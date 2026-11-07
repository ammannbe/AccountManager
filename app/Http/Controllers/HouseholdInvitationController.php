<?php

namespace App\Http\Controllers;

use App\Households\Households;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Only the user with the invited, verified address joins, after confirming: by the link in the
 * mail or on the households page. Guests log in or create an account first and come back here.
 */
class HouseholdInvitationController extends Controller
{
    /**
     * The link only shows the invitation: mail scanners that open links must not accept.
     */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = HouseholdInvitation::findByToken($token);

        if ($invitation === null) {
            return view('household-invitation', ['invitation' => null, 'token' => $token]);
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user && $invitation->household->hasMember($user)) {
            return redirect()->route('households.index');
        }

        if (! $user) {
            // Logging in (also after creating an account in this browser) returns here.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('household-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'signup' => RegistrationController::signupOpen(),
        ]);
    }

    public function acceptToken(Request $request, string $token, Households $households): RedirectResponse
    {
        return $this->accept($request, HouseholdInvitation::findByToken($token), $households);
    }

    public function declineToken(Request $request, string $token, Households $households): RedirectResponse
    {
        return $this->decline($request, HouseholdInvitation::findByToken($token), $households);
    }

    /**
     * From the households page (pending invitations for the user's address).
     */
    public function acceptOpen(Request $request, HouseholdInvitation $invitation, Households $households): RedirectResponse
    {
        return $this->accept($request, $invitation, $households);
    }

    public function declineOpen(Request $request, HouseholdInvitation $invitation, Households $households): RedirectResponse
    {
        return $this->decline($request, $invitation, $households);
    }

    private function accept(Request $request, ?HouseholdInvitation $invitation, Households $households): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->invalid();
        }

        if (! $households->accept($invitation, $user)) {
            return $this->wrongAddress($invitation);
        }

        return redirect()->route('households.index')->with('status', __('You joined the household ":household".', ['household' => $invitation->household->name]));
    }

    private function decline(Request $request, ?HouseholdInvitation $invitation, Households $households): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->invalid();
        }

        if (! $households->decline($invitation, $user)) {
            return $this->wrongAddress($invitation);
        }

        return redirect()->route('households.index')->with('status', __('Invitation declined.'));
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('households.index')->withErrors(['household' => __('This invitation is invalid or has expired.')]);
    }

    private function wrongAddress(HouseholdInvitation $invitation): RedirectResponse
    {
        return redirect()->route('households.index')->withErrors(['household' => __('This invitation is for :email. Log in with that address and confirm it in your profile.', ['email' => $invitation->email])]);
    }
}
