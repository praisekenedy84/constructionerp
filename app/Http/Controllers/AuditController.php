<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\ListingQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'audit', 'read');

        $query = AuditLog::query()->with('performer');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', 'like', '%'.$request->string('entity_type').'%');
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action').'%');
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['entity_type', 'action', 'ip_address', 'performer.name'])
            ->dateRange('created_at')
            ->sort(['created_at', 'entity_type', 'action', 'entity_id']);

        return Inertia::render('Audit/Index', [
            'logs' => $listing->paginate(ListingQuery::PER_PAGE),
            'filters' => $listing->filters([
                'entity_type' => $request->input('entity_type'),
                'entity_id' => $request->input('entity_id'),
                'action' => $request->input('action'),
            ]),
        ]);
    }
}
