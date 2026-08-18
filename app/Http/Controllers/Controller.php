<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use RuntimeException;
use Throwable;

abstract class Controller
{
    /**
     * Lets any controller reach for $this->authorize(), which is how the
     * technician portal defers every permission decision to its policies.
     */
    use AuthorizesRequests;

    /**
     * The paging figures a JSON-driven table needs, in the one shape every
     * such table's script already reads.
     *
     * Stated here rather than per controller so Configuration and Reports
     * cannot drift into disagreeing about what a page of rows is called.
     *
     * @return array<string, mixed>
     */
    protected function paginationMeta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * What a failed action is allowed to tell the person who attempted it.
     *
     * A RuntimeException is something this application raised deliberately and
     * its message is written for somebody to read - "Kevin is unavailable on
     * August 6", "This project is on hold". Anything else is a fault, and its
     * message belongs in the log: a raw SQL error tells an administrator
     * nothing they can act on and describes the shape of the database to
     * whoever is watching. `No query results for model [App\Models\Client]` is
     * the one this replaced.
     *
     * The test is the exact class rather than `instanceof`, and that is the
     * whole point of it. Several framework exceptions *extend*
     * RuntimeException - PDOException does, and so does
     * ModelNotFoundException, which is what printed
     * "No query results for model [App\Models\Client]" on screen. An
     * `instanceof` check reads every one of those as deliberate, and ruling
     * them out one class at a time only lasts until the next one. Every
     * message this application raises for a person to read is thrown as a
     * plain `new RuntimeException(...)` - all sixty-odd of them - so that is
     * what is asked for.
     *
     * Lifted out of ProjectController::creationFailureMessage(), which had
     * the idea right, and given to every controller so the dozen other catch
     * blocks stop each deciding for themselves.
     *
     * @param  string  $fallback  what to say when the detail cannot be shown
     */
    protected function safeErrorMessage(Throwable $exception, string $fallback): string
    {
        if ($exception::class === RuntimeException::class) {
            return $exception->getMessage();
        }

        report($exception);

        // In debug mode the detail is appended regardless, because that is
        // what debug mode is for.
        return config('app.debug')
            ? $fallback.' ('.$exception->getMessage().')'
            : $fallback;
    }
}
