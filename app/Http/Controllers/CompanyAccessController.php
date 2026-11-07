<?php

namespace App\Http\Controllers;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyAccessController extends Controller
{
    public function index(CompanyAccessService $access)
    {
        abort_if(session('impersonator_id') || session('platform_impersonator_id'), 403);

        return Inertia::render('Auth/Companies', ['companies' => $access->companies()]);
    }

    public function select(Request $request, CompanyAccessService $access)
    {
        abort_if(session('impersonator_id') || session('platform_impersonator_id'), 403);
        $data = $request->validate(['tenant_id' => ['required', 'string'], 'identity_id' => ['sometimes', 'integer']]);
        $identityId = $data['identity_id'] ?? session('central_identity_id');
        abort_unless(collect($access->companies())->contains(fn ($choice) => $choice['id'] === $data['tenant_id'] && (int) $choice['identity_id'] === (int) $identityId), 403);
        if ((int) $identityId !== (int) session('central_identity_id')) {
            $identity = CentralUser::findOrFail($identityId);
            abort_unless($access->accountAvailable($identity), 403);
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            tenancy()->initialize(Tenant::findOrFail($identity->tenant_id));
            $user = User::findOrFail($identity->tenant_user_id);
            session(['home_tenant_id' => $identity->tenant_id, 'central_identity_id' => $identity->id]);
            Auth::guard('web')->login($user);
        }
        $access->event('visit', session('central_identity_id'), $data['tenant_id']);
        session()->forget(['current_project_id', 'company_selection_required']);
        session(['tenant_id' => $data['tenant_id']]);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }
}
