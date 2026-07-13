<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Models\Supplier;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Procurement Officer', 'Storekeeper']);

        $listing = ListingQuery::for(Supplier::query(), $request)
            ->search(['name', 'contact_info'])
            ->dateRange('created_at')
            ->sort(['name', 'created_at']);

        return Inertia::render('Procurement/Suppliers', [
            'suppliers' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Procurement Officer']);

        Supplier::create($request->validated());

        return back()->with('success', 'Supplier created.');
    }
}
