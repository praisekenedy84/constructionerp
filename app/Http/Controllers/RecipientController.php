<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecipientRequest;
use App\Http\Requests\UpdateRecipientRequest;
use App\Models\Recipient;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecipientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = Recipient::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['name', 'phone', 'email', 'address', 'national_id'])
            ->dateRange('created_at')
            ->sort(['name', 'phone', 'email', 'status', 'created_at'], 'name');

        return Inertia::render('Recipients/Index', [
            'recipients' => $listing->paginate(25),
            'filters' => $listing->filters([
                'status' => $request->input('status', ''),
            ]),
        ]);
    }

    public function store(StoreRecipientRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        Recipient::create($request->validated());

        return back()->with('success', 'Recipient registered.');
    }

    public function update(UpdateRecipientRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $recipient = Recipient::findOrFail($id);
        $recipient->update($request->validated());

        return back()->with('success', 'Recipient updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $recipient = Recipient::findOrFail($id);

        $inUse = $recipient->requisitionItems()->exists()
            || $recipient->requisitionRecipientRows()->exists()
            || $recipient->requisitions()->exists()
            || $recipient->attendances()->exists()
            || $recipient->projects()->exists();

        if ($inUse) {
            $recipient->update(['status' => 'inactive']);

            return back()->with(
                'success',
                'Recipient is in use, so it was deactivated instead of deleted.',
            );
        }

        $recipient->delete();

        return back()->with('success', 'Recipient archived.');
    }
}
