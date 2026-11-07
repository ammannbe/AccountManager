<?php

namespace App\Http\Controllers;

use App\Enums\HouseholdRole;
use App\Households\Households;
use App\Households\LastHouseholdAdmin;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Households page: create, rename and delete households, invite by email, change roles, remove
 * members and leave. Admins manage the household, members may leave it.
 */
class HouseholdController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('households', [
            'households' => $user->households()
                ->with([
                    'users' => fn ($query) => $query->orderBy('name')->orderBy('email'),
                    'invitations' => fn ($query) => $query->pending()->orderBy('email'),
                    'shares' => fn ($query) => $query->with('service')->orderBy('label'),
                ])
                ->orderBy('name')
                ->get(),
            'invitations' => HouseholdInvitation::query()->pending()->sentTo($user)->with(['household', 'inviter'])->get(),
            'verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function store(Request $request, Households $households): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $households->create($user, $this->validateName($request));

        return $this->back(__('Household created.'));
    }

    public function update(Request $request, Household $household, Households $households): RedirectResponse
    {
        $this->ensureAdmin($request, $household);
        $households->rename($household, $this->validateName($request));

        return $this->back(__('Saved.'));
    }

    public function destroy(Request $request, Household $household, Households $households): RedirectResponse
    {
        $this->ensureAdmin($request, $household);
        $households->delete($household);

        return $this->back(__('Household deleted.'));
    }

    public function invite(Request $request, Household $household, Households $households): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureAdmin($request, $household);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(HouseholdRole::class)],
        ]);
        $email = mb_strtolower(trim($data['email']));

        if ($household->members()->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))->exists()) {
            return $this->back(error: __(':email is already a member.', ['email' => $email]));
        }

        $households->invite($household, $email, HouseholdRole::from($data['role']), $user);

        return $this->back(__('Invitation sent to :email.', ['email' => $email]));
    }

    public function revoke(Request $request, Household $household, HouseholdInvitation $invitation, Households $households): RedirectResponse
    {
        $this->ensureAdmin($request, $household);
        abort_unless($invitation->household_id === $household->id, 404);

        $households->revoke($invitation);

        return $this->back(__('Invitation revoked.'));
    }

    public function updateMember(Request $request, Household $household, User $member, Households $households): RedirectResponse
    {
        $this->ensureAdmin($request, $household);
        abort_unless($household->hasMember($member), 404);

        $role = $request->validate(['role' => ['required', Rule::enum(HouseholdRole::class)]])['role'];

        try {
            $households->changeRole($household, $member, HouseholdRole::from($role));
        } catch (LastHouseholdAdmin $exception) {
            return $this->back(error: $exception->getMessage());
        }

        return $this->back(__('Saved.'));
    }

    /**
     * Admins remove members, members leave.
     */
    public function destroyMember(Request $request, Household $household, User $member, Households $households): RedirectResponse
    {
        $self = $member->is($request->user());

        if (! $self) {
            $this->ensureAdmin($request, $household);
        }

        abort_unless($household->hasMember($member), 404);

        try {
            $households->removeMember($household, $member);
        } catch (LastHouseholdAdmin $exception) {
            return $this->back(error: $exception->getMessage());
        }

        return $this->back($self ? __('You left the household.') : __('Member removed.'));
    }

    private function validateName(Request $request): string
    {
        return trim($request->validate(['name' => ['required', 'string', 'max:100']])['name']);
    }

    private function ensureAdmin(Request $request, Household $household): void
    {
        /** @var User $user */
        $user = $request->user();

        // Not found for non-members, so nobody learns which households exist.
        abort_unless($household->hasMember($user), 404);
        abort_unless($household->isAdministeredBy($user), 403);
    }

    private function back(?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('households.index');

        return $error !== null ? $redirect->withErrors(['household' => $error]) : $redirect->with('status', $status);
    }
}
