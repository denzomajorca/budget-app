<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        // Accounts, budgets and transactions arrive in later steps; the
        // dashboard shows empty states until then.
        return Inertia::render('Dashboard', [
            'accounts' => [],
            'recentTransactions' => [],
        ]);
    }
}
