<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

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
}
