<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /** The home page. Needs sign-in only: a user with no roles still lands here. */
    public function __invoke(): View
    {
        return view('dashboard');
    }
}
