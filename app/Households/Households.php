<?php

namespace App\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Household management with the same rules as the local households of the apps: every household
 * keeps an admin, invitations bind only to the invited, verified address and need consent, and
 * leaving or deleting only removes access (contents stay in the apps with their owners).
 *
 * Every change increments the household's version and sends an event to the apps.
 */
class Households
{
    public function __construct(private readonly HouseholdEvents $events) {}

    public function create(User $user, string $name): Household
    {
        return DB::transaction(function () use ($user, $name): Household {
            $household = Household::query()->create(['name' => trim($name), 'created_by' => $user->getKey()]);

            $household->members()->create(['user_id' => $user->getKey(), 'role' => HouseholdRole::Admin, 'joined_at' => now()]);

            $this->events->dispatch(HouseholdEvents::CREATED, $household->snapshot(), [(int) $user->getKey()]);

            return $household;
        });
    }

    public function rename(Household $household, string $name): void
    {
        $name = trim($name);

        if ($name === $household->name) {
            return;
        }

        DB::transaction(function () use ($household, $name): void {
            $this->lock($household);
            $household->name = $name;
            $household->version++;
            $household->save();

            $this->events->dispatch(HouseholdEvents::UPDATED, $household->snapshot(), HouseholdEvents::memberIds($household));
        });
    }

    /**
     * Members, invitations and reported shares go with it; the apps drop their shares.
     */
    public function delete(Household $household): void
    {
        DB::transaction(function () use ($household): void {
            $this->lock($household);
            $memberIds = HouseholdEvents::memberIds($household);
            $snapshot = [...$household->snapshot(), 'version' => $household->version + 1, 'members' => [], 'deleted' => true];

            $household->delete();

            $this->events->dispatch(HouseholdEvents::DELETED, $snapshot, $memberIds);
        });
    }

    /**
     * Sends an invitation, also to existing users: joining needs their consent. A new invitation
     * to the same address replaces the old one (new token, new expiry).
     */
    public function invite(Household $household, string $email, HouseholdRole $role, User $invitedBy): HouseholdInvitation
    {
        $email = mb_strtolower(trim($email));
        $token = HouseholdInvitation::newToken();

        $invitation = $household->invitations()->updateOrCreate(['email' => $email], [
            'role' => $role,
            'invited_by' => $invitedBy->getKey(),
            'token_hash' => HouseholdInvitation::hash($token),
            'expires_at' => now()->addDays(HouseholdInvitation::LIFETIME_DAYS),
            'accepted_at' => null,
        ]);

        Notification::route('mail', $email)->notify(new HouseholdInvited($invitation, $token, app()->getLocale()));

        return $invitation;
    }

    public function revoke(HouseholdInvitation $invitation): void
    {
        $invitation->delete();
    }

    /**
     * Adds the user to the household of a pending invitation sent to their verified address.
     */
    public function accept(HouseholdInvitation $invitation, User $user): bool
    {
        if (! $invitation->isPending() || ! $invitation->isFor($user)) {
            return false;
        }

        DB::transaction(function () use ($invitation, $user): void {
            $household = $this->lock($invitation->household);

            if (! $household->hasMember($user)) {
                $household->members()->create(['user_id' => $user->getKey(), 'role' => $invitation->role, 'joined_at' => now()]);
                $this->memberChanged(HouseholdEvents::MEMBER_ADDED, $household, $user, $invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return true;
    }

    public function decline(HouseholdInvitation $invitation, User $user): bool
    {
        if (! $invitation->isPending() || ! $invitation->isFor($user)) {
            return false;
        }

        $invitation->delete();

        return true;
    }

    /**
     * @throws LastHouseholdAdmin
     */
    public function changeRole(Household $household, User $member, HouseholdRole $role): void
    {
        DB::transaction(function () use ($household, $member, $role): void {
            $this->lock($household);
            $membership = $this->membership($household, $member);

            if ($membership->role === $role) {
                return;
            }

            if ($role === HouseholdRole::Member) {
                $this->ensureAnotherAdmin($household, $member);
            }

            $membership->update(['role' => $role]);
            $this->memberChanged(HouseholdEvents::MEMBER_UPDATED, $household, $member, $role);
        });
    }

    /**
     * Removes the member's access to everything shared with the household.
     *
     * @throws LastHouseholdAdmin
     */
    public function removeMember(Household $household, User $member): void
    {
        DB::transaction(function () use ($household, $member): void {
            $this->lock($household);
            $membership = $this->membership($household, $member);
            $this->ensureAnotherAdmin($household, $member);

            $membership->delete();
            $this->memberChanged(HouseholdEvents::MEMBER_REMOVED, $household, $member, $membership->role);
        });
    }

    /**
     * @throws LastHouseholdAdmin
     */
    public function leave(Household $household, User $user): void
    {
        $this->removeMember($household, $user);
    }

    /**
     * The user is deleted: drops their memberships. A household left without members is deleted,
     * one left without admin gets its longest-standing member as admin.
     */
    public function forget(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = $user->householdMemberships()->with('household')->get();

            foreach ($memberships as $membership) {
                $household = $this->lock($membership->household);
                $membership->delete();

                $remaining = $household->members()->orderBy('joined_at')->orderBy('id')->get();

                if ($remaining->isEmpty()) {
                    $this->events->dispatch(HouseholdEvents::DELETED, [...$household->snapshot(), 'version' => $household->version + 1, 'members' => [], 'deleted' => true], [(int) $user->getKey()]);
                    $household->delete();

                    continue;
                }

                if (! $remaining->contains(fn (HouseholdMember $member): bool => $member->role === HouseholdRole::Admin)) {
                    $remaining->firstOrFail()->update(['role' => HouseholdRole::Admin]);
                }

                $this->memberChanged(HouseholdEvents::MEMBER_REMOVED, $household, $user, $membership->role);
            }
        });
    }

    /**
     * Increments the version and tells the apps. Members without an identity are not part of the
     * snapshot, so for them the apps only get the new version as an update.
     */
    private function memberChanged(string $type, Household $household, User $member, HouseholdRole $role): void
    {
        $household->version++;
        $household->save();

        $userIds = array_values(array_unique([...HouseholdEvents::memberIds($household), (int) $member->getKey()]));

        if ($member->oidc_sub === null) {
            $this->events->dispatch(HouseholdEvents::UPDATED, $household->snapshot(), $userIds);

            return;
        }

        $this->events->dispatch($type, $household->snapshot(), $userIds, [
            'member' => ['sub' => $member->oidc_sub, 'role' => $role->value],
        ]);
    }

    /**
     * Locks the household row and reloads it before a change: changes of the same household run
     * one after the other, so every version is used once and its snapshot is complete.
     */
    private function lock(Household $household): Household
    {
        $current = Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();
        $household->setRawAttributes($current->getAttributes(), true);

        return $household;
    }

    private function membership(Household $household, User $member): HouseholdMember
    {
        return $household->members()->where('user_id', $member->getKey())->firstOrFail();
    }

    /**
     * Throws when the user is the household's only admin. Locks the admin rows, so two admins
     * demoting each other at the same time cannot both succeed.
     */
    private function ensureAnotherAdmin(Household $household, User $member): void
    {
        $admins = $household->members()
            ->where('role', HouseholdRole::Admin)
            ->lockForUpdate()
            ->pluck('user_id');

        if ($admins->count() === 1 && (int) $admins->first() === (int) $member->getKey()) {
            throw new LastHouseholdAdmin;
        }
    }
}
