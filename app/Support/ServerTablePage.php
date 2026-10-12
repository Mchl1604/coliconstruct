<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The rows of one page of a server-paged table, and the two counts DataTables
 * needs to draw its pager: how many rows the table holds, and how many are
 * left once the search box has had its say.
 *
 * @template TItem
 */
final class ServerTablePage
{
    /**
     * @param  Collection<int, TItem>  $items
     */
    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly int $filtered,
    ) {}
}
