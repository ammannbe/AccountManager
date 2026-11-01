<?php

namespace App\Accounts;

use App\Identity\IdentityProvider;
use App\Jobs\SyncUserGroups;
use App\Models\Registration;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\ConfirmRegistration;
use App\Notifications\ManualSetupNeeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Self-registration for an account, optionally with a service: double opt-in by e-mail, then the
 * user is created in the identity provider (Pocket ID), gets the service's group and a login code.
 */
class Registrar
{
    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly Access $access,
    ) {}

    /**
     * Starts a registration. Known and unknown addresses are treated the same (no account enumeration).
     */
    public function start(?Service $service, string $name, string $email, ?string $locale): void
    {
        $email = mb_strtolower(trim($email));

        // Do not mail the same address over and over.
        $recent = Registration::query()
            ->where('service_id', $service?->id)
            ->where('email', $email)
            ->whereNull('confirmed_at')
            ->where('created_at', '>', now()->subMinutes(5))
            ->exists();

        if ($recent) {
            return;
        }

        $token = Registration::newToken();

        Registration::query()->create([
            'service_id' => $service?->id,
            'email' => $email,
            'name' => trim($name),
            'locale' => $locale,
            'token_hash' => Registration::hash($token),
            'expires_at' => now()->addDays(Registration::LIFETIME_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new ConfirmRegistration($service, $token, $locale));
    }

    /**
     * Confirms the e-mail address: user, identity and (with a service) the service account are created.
     */
    public function confirm(Registration $registration): RegistrationResult
    {
        [$user, $account, $identityCreated] = DB::transaction(function () use ($registration): array {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$registration->email])->first()
                ?? new User(['email' => $registration->email, 'name' => $registration->name]);

            $user->email_verified_at ??= now();

            $identityCreated = false;

            if ($user->oidc_sub === null && $this->provider->manages()) {
                $identity = $this->provider->findUserByEmail($registration->email);

                if (! $identity) {
                    $identity = $this->provider->createUser($registration->email, $registration->name, $registration->locale);
                    $identityCreated = true;
                } else {
                    // The address is verified now; the apps only link accounts by verified addresses.
                    $this->provider->updateUser($identity->id, $identity->name, $registration->email);
                }

                $user->oidc_sub = $identity->id;
            }

            $user->save();

            $account = $registration->service ? $this->access->claim($user, $registration->service) : null;

            $registration->update(['confirmed_at' => now()]);

            return [$user, $account, $identityCreated];
        });

        /** @var User $user */
        /** @var ServiceAccount|null $account */
        // Set the groups right away (the queued sync follows anyway), so the first login works.
        if ($account) {
            SyncUserGroups::dispatchSync($user);
        }

        if ($identityCreated && $user->oidc_sub) {
            $this->provider->sendLoginCode($user->oidc_sub);
        }

        // Registrations without a service only exist when the provider is managed (see RegistrationController).
        if ($account && ! $this->provider->manages()) {
            Notification::send(User::query()->where('is_admin', true)->get(), new ManualSetupNeeded($account));
        }

        return new RegistrationResult($user, $account, $identityCreated, $this->provider->manages());
    }

    public function findPending(string $token): ?Registration
    {
        return Registration::query()->pending()->where('token_hash', Registration::hash($token))->first();
    }
}
