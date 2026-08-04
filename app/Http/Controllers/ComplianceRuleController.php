<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComplianceRuleRequest;
use App\Http\Requests\UpdateComplianceRuleRequest;
use App\Models\ComplianceRule;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'read');

        $listing = ListingQuery::for(ComplianceRule::query(), $request)
            ->search(['name', 'description'])
            ->dateRange('created_at')
            ->sort(['name', 'created_at', 'is_active'], 'name');

        return Inertia::render('ComplianceRules/Index', [
            'rules' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'rule_types' => [
                ['value' => 'other', 'label' => 'Other'],
                ['value' => 'retention', 'label' => 'Retention'],
                ['value' => 'advance_recovery', 'label' => 'Advance recovery'],
                ['value' => 'wht', 'label' => 'Withholding tax'],
                ['value' => 'defect_liability', 'label' => 'Defect liability'],
                ['value' => 'material_test', 'label' => 'Material test'],
                ['value' => 'hiv_report', 'label' => 'HIV report'],
            ],
        ]);
    }

    public function store(StoreComplianceRuleRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'create');

        ComplianceRule::create($request->validated());

        return back()->with('success', 'Compliance rule created.');
    }

    public function update(UpdateComplianceRuleRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $rule = ComplianceRule::findOrFail($id);
        $rule->update($request->validated());

        return back()->with('success', 'Compliance rule updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'delete-soft');

        $rule = ComplianceRule::findOrFail($id);
        $rule->delete();

        return back()->with('success', 'Compliance rule archived.');
    }
}
