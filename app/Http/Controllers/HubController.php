<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class HubController extends Controller
{
    public function finance(): RedirectResponse
    {
        return redirect()->route('finance.approvals');
    }

    public function payroll(): RedirectResponse
    {
        return redirect()->route('payroll.employees.index');
    }

    public function procurement(): RedirectResponse
    {
        return redirect()->route('procurement.suppliers.index');
    }

    public function inventory(): RedirectResponse
    {
        return redirect()->route('inventory.items');
    }
}
