<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HubController extends Controller
{
    public function finance(Request $request): Response
    {
        $project = $this->resolveOptionalProject();

        return Inertia::render('Finance/Hub', [
            'project' => $project,
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name', 'status']),
        ]);
    }

    public function payroll(Request $request): Response
    {
        $project = $this->resolveOptionalProject();

        return Inertia::render('Payroll/Hub', [
            'project' => $project,
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name', 'status']),
        ]);
    }

    public function procurement(): Response
    {
        return Inertia::render('Procurement/Index');
    }

    public function inventory(): Response
    {
        return Inertia::render('Inventory/Index');
    }

    private function resolveOptionalProject(): ?Project
    {
        $id = session('current_project_id');

        if ($id) {
            $project = Project::find($id);

            if ($project) {
                return $project;
            }

            session()->forget('current_project_id');
        }

        $project = Project::query()->orderByDesc('created_at')->first();

        if ($project) {
            session(['current_project_id' => $project->id]);
        }

        return $project;
    }
}
