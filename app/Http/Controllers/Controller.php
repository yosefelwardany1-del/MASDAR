<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Enables $this->authorize() / policy checks in controllers (Laravel 12 no
    // longer provides this on the base controller by default).
    use AuthorizesRequests;
}
