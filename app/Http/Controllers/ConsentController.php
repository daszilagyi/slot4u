<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Legal\RecordConsent;
use App\Enums\ConsentContext;
use App\Models\LegalDocument;
use App\Services\Legal\LegalDocumentRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The blocking screen a signed-in user meets when a document they already
 * accepted has been superseded (SLO-161).
 *
 * Deliberately not a dismissible banner. A banner would leave the product usable
 * by someone who has not accepted the terms it is being used under, which is the
 * exact state this feature exists to make impossible.
 */
class ConsentController extends Controller
{
    public function show(Request $request, LegalDocumentRegistry $registry): Response|RedirectResponse
    {
        $outstanding = $registry->outstandingFor($request->user());

        // Nothing to accept — usually a second tab that submitted first. Sending
        // them on beats showing an empty form with a button that does nothing.
        if ($outstanding->isEmpty()) {
            return redirect('/');
        }

        return Inertia::render('Legal/Consent', [
            'documents' => $outstanding->values()->map(fn (LegalDocument $document): array => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'version' => $document->version,
                'title' => $document->title,
                'href' => '/legal/'.$document->getKey(),
            ])->all(),
        ]);
    }

    public function store(Request $request, LegalDocumentRegistry $registry, RecordConsent $recordConsent): RedirectResponse
    {
        $request->validate([
            'accepted_legal' => ['required', 'accepted'],
        ]);

        $user = $request->user();
        $outstanding = $registry->outstandingFor($user);

        if ($outstanding->isEmpty()) {
            return redirect('/');
        }

        $tenant = $user->tenant;

        // A user with no tenant reaching here would be a super-admin, whom the
        // registry never asks in the first place. Refusing loudly beats writing
        // a consent row with no owner.
        if ($tenant === null) {
            throw ValidationException::withMessages(['accepted_legal' => __('app.legal.stale')]);
        }

        // Re-read at submit time, not from the form: the acceptance must attach
        // to the versions in force now, and those are the ones the screen was
        // just rendered from. A version published in between re-renders the
        // screen on the next request rather than being silently skipped.
        $recordConsent->many(
            $outstanding,
            $tenant,
            ConsentContext::Reconsent,
            user: $user,
            ipAddress: $request->ip(),
        );

        return redirect('/');
    }
}
