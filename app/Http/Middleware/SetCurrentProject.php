<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $projectId = $request->route('id') ?? $request->route('projectId');

        if ($projectId && is_numeric($projectId) && Project::whereKey((int) $projectId)->exists()) {
            session(['current_project_id' => (int) $projectId]);
        }

        return $next($request);
    }
}
