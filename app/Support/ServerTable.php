<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One page of a DataTable, worked out on the server.
 *
 * Every list in both portals used to be rendered whole and paged in the
 * browser, so a page cost grew with the entire table - every row, and every
 * dialog every row could open - rather than with the ten rows on screen. This
 * reads the request public/js/serverTable.js sends (paging, the search box, the
 * clicked column) and answers it with that page alone.
 *
 * Two ways in, because not every list can be filtered in SQL:
 *
 *   - paginate() narrows, sorts and slices an Eloquent query. This is the
 *     default, and what every list should use when its columns are columns.
 *   - paginateCollection() does the same to a collection, for the one list
 *     whose tabs are decided by model methods with no SQL twin - see
 *     ProjectController::table(). Only light rows go in; the relations that
 *     make a row expensive to draw are loaded for the page that comes out.
 *
 * Search follows DataTables' own: the box is split into words and every word
 * has to match somewhere in the row, so "ongoing santos" finds Ongoing
 * projects of the Santos family rather than anything mentioning either.
 */
final class ServerTable
{
    /**
     * Rows per page when the request does not say, matching the tables on
     * the Configuration and Reports pages.
     */
    public const DEFAULT_LENGTH = 10;

    /**
     * The most rows one request may ask for - the top of every table's
     * "Show N" menu. Anything above is read as this, so a hand-edited URL
     * cannot ask for the whole table again.
     */
    public const MAX_LENGTH = 100;

    /**
     * @param  array<int, array{column: int, dir: string}>  $order
     */
    private function __construct(
        public readonly int $draw,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly array $order,
    ) {}

    /**
     * Read a table request, forgivingly.
     *
     * Nothing here is refused: a pager given nonsense falls back to page one
     * of ten rows rather than answering 422, because the only thing that
     * sends these parameters is the table itself.
     */
    public static function fromRequest(Request $request, int $defaultLength = self::DEFAULT_LENGTH): self
    {
        $length = (int) $request->query('length', (string) $defaultLength);

        $order = collect((array) $request->query('order', []))
            ->filter(fn (mixed $entry): bool => is_array($entry) && is_numeric($entry['column'] ?? null))
            ->map(fn (array $entry): array => [
                'column' => (int) $entry['column'],
                'dir' => strtolower((string) ($entry['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            ])
            ->values()
            ->all();

        return new self(
            draw: max(0, (int) $request->query('draw', '0')),
            start: max(0, (int) $request->query('start', '0')),
            length: $length < 1 ? $defaultLength : min($length, self::MAX_LENGTH),
            search: trim((string) $request->query('search', '')),
            order: $order,
        );
    }

    /**
     * The search box as words, each of which has to match.
     *
     * @return array<int, string>
     */
    public function searchWords(): array
    {
        return preg_split('/\s+/', $this->search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Narrow, sort and slice a query.
     *
     * `$sortable` maps a column's position in the table to how it sorts:
     * a column name, or a closure given the query and the direction. A
     * column left out of it does not sort - its header should say so with
     * `orderable: false`.
     *
     * `$search` is handed the query, one word of the search box and that word
     * wrapped for LIKE, and adds the places that word may match as `orWhere`s.
     * It is called once per word, inside its own group, so the words AND
     * together.
     *
     * The rows always end ordered by their key as well. A sort on a column
     * with ties - a status, a date with no time - otherwise comes back in
     * whatever order the database likes, and an order that differs between
     * two requests is how a row turns up on page one and page two and another
     * never appears at all.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string|Closure(Builder<TModel>, string): mixed>  $sortable
     * @param  (Closure(Builder<TModel>, string, string): mixed)|null  $search
     * @param  array<int, array{0: int, 1: string}>  $defaultOrder  [[column, dir], ...] when none is asked for
     * @return ServerTablePage<TModel>
     */
    public function paginate(Builder $query, array $sortable, ?Closure $search = null, array $defaultOrder = []): ServerTablePage
    {
        $total = (clone $query)->count();

        $words = $this->searchWords();

        if ($search !== null && $words !== []) {
            foreach ($words as $word) {
                $query->where(function (Builder $group) use ($search, $word): void {
                    $search($group, $word, '%'.$word.'%');
                });
            }

            $filtered = (clone $query)->count();
        } else {
            $filtered = $total;
        }

        foreach ($this->ordersFor($sortable, $defaultOrder) as [$column, $dir]) {
            $sorter = $sortable[$column];

            if ($sorter instanceof Closure) {
                $sorter($query, $dir);
            } else {
                $query->orderBy($sorter, $dir);
            }
        }

        $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');

        return new ServerTablePage(
            items: $query->skip($this->start)->take($this->length)->get(),
            total: $total,
            filtered: $filtered,
        );
    }

    /**
     * Narrow, sort and slice a collection.
     *
     * For a list whose filters are model methods rather than columns. The
     * collection should hold only what those methods read: every relation a
     * row needs merely to be DRAWN is better loaded on the page that comes
     * back, which is ten rows rather than all of them.
     *
     * `$sortable` maps a column's position to the value it sorts by. `$search`
     * answers whether one word of the search box matches an item - see
     * matchesAny() - and the words AND together, as they do in SQL.
     *
     * The collection's own order is the tiebreaker, so hand it over in the
     * order the table should fall back to.
     *
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  array<int, Closure(TItem): mixed>  $sortable
     * @param  (Closure(TItem, string): bool)|null  $search
     * @param  array<int, array{0: int, 1: string}>  $defaultOrder
     * @return ServerTablePage<TItem>
     */
    public function paginateCollection(Collection $items, array $sortable, ?Closure $search = null, array $defaultOrder = []): ServerTablePage
    {
        $items = $items->values();
        $total = $items->count();
        $words = $this->searchWords();

        if ($search !== null && $words !== []) {
            $items = $items
                ->filter(function (mixed $item) use ($search, $words): bool {
                    foreach ($words as $word) {
                        if (! $search($item, $word)) {
                            return false;
                        }
                    }

                    return true;
                })
                ->values();
        }

        $orders = $this->ordersFor($sortable, $defaultOrder);

        if ($orders !== []) {
            // Stable: PHP's sort keeps the incoming order between equals,
            // which is what makes that order the tiebreaker.
            $items = $items
                ->sort(function (mixed $a, mixed $b) use ($orders, $sortable): int {
                    foreach ($orders as [$column, $dir]) {
                        $result = self::compare($sortable[$column]($a), $sortable[$column]($b));

                        if ($result !== 0) {
                            return $dir === 'desc' ? -$result : $result;
                        }
                    }

                    return 0;
                })
                ->values();
        }

        return new ServerTablePage(
            items: $items->slice($this->start, $this->length)->values(),
            total: $total,
            filtered: $items->count(),
        );
    }

    /**
     * Whether a search word appears in any of the given values, ignoring
     * case - the collection twin of a row of `orWhere ... LIKE`s.
     */
    public static function matchesAny(string $word, mixed ...$values): bool
    {
        $needle = mb_strtolower($word);

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer serverTable.js reads: DataTables' own four fields, the rows
     * as finished markup, and whatever the page of rows needs beside it.
     *
     * `$row` renders one item as its `<tr>`. `$extras` is markup that goes
     * outside the table - the dialogs the rows' buttons open, which cannot
     * live inside a `<tbody>`. `$meta` is anything else the page's own script
     * wants for these rows.
     *
     * @template TItem
     *
     * @param  ServerTablePage<TItem>  $page
     * @param  Closure(TItem): string  $row
     * @param  array<string, mixed>  $meta
     */
    public function respond(ServerTablePage $page, Closure $row, ?string $extras = null, array $meta = []): JsonResponse
    {
        return response()->json([
            'draw' => $this->draw,
            'recordsTotal' => $page->total,
            'recordsFiltered' => $page->filtered,
            'rows' => $page->items->map(fn (mixed $item): string => $row($item))->values()->all(),
            'extras' => $extras,
            'meta' => (object) $meta,
        ]);
    }

    /**
     * The orders asked for that this table can honour, or the table's own
     * default when none of them are.
     *
     * @param  array<int, mixed>  $sortable
     * @param  array<int, array{0: int, 1: string}>  $defaultOrder
     * @return array<int, array{0: int, 1: string}>
     */
    private function ordersFor(array $sortable, array $defaultOrder): array
    {
        $orders = collect($this->order)
            ->filter(fn (array $order): bool => array_key_exists($order['column'], $sortable))
            ->map(fn (array $order): array => [$order['column'], $order['dir']])
            ->values()
            ->all();

        if ($orders !== []) {
            return $orders;
        }

        return collect($defaultOrder)
            ->filter(fn (array $order): bool => array_key_exists($order[0], $sortable))
            ->map(fn (array $order): array => [$order[0], strtolower($order[1] ?? 'asc') === 'desc' ? 'desc' : 'asc'])
            ->values()
            ->all();
    }

    /**
     * Compare two sort values: numbers as numbers, text naturally and without
     * regard to case, and an empty value before any other.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strnatcasecmp((string) $a, (string) $b);
    }
}
