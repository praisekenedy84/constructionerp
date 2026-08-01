<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $listing = ListingQuery::for(
            Notification::query()->where('user_id', $request->user()->id),
            $request,
        )
            ->search(['type'])
            ->dateRange('created_at')
            ->sort(['created_at', 'type', 'read_at']);

        return Inertia::render('Notifications/Index', [
            'notifications' => $listing->paginate(ListingQuery::PER_PAGE),
            'filters' => $listing->filters(),
        ]);
    }

    public function unreadCount(Request $request): \Illuminate\Http\JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): RedirectResponse
    {
        $notification = Notification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->markAsRead();

        return back();
    }
}
