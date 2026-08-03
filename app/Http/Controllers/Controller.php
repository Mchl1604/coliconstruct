<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Lets any controller reach for $this->authorize(), which is how the
     * technician portal defers every permission decision to its policies.
     */
    use AuthorizesRequests;
}
