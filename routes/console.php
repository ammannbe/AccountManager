<?php

use App\Billing\Billing;
use App\Billing\BillingException;
use App\Entitlements\Entitlements;
use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Identity\IdentityProvider;
use App\Jobs\SyncUserGroups;
use App\Models\HouseholdInvitation;
use App\Models\Registration;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

Artisan::command('accounts:make-admin {email} {--password : Also set a password for the local emergency login}', function (string $email) {
    $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first()
        ?? new User(['email' => mb_strtolower($email), 'name' => Str::before($email, '@')]);

    $user->is_admin = true;
    $user->email_verified_at ??= now();

    if ($this->option('password')) {
        $user->password = password(label: 'Password', required: true, validate: ['password' => 'min:12']);
    }

    $user->save();

    $this->info("{$user->email} is an admin now.");
})->purpose('Make a user an admin (created if unknown), optionally with a local emergency password');

Artisan::command('accounts:import {--dry-run : Only show what would change}', function (IdentityProvider $provider) {
    if (! $provider->manages()) {
        $this->error('The identity provider is managed by hand (IDENTITY_DRIVER=manual).');

        return 1;
    }

    $services = Service::query()->whereNotNull('access_group')->get()->keyBy('access_group');
    $dryRun = (bool) $this->option('dry-run');

    foreach ($provider->users() as $identity) {
        if ($identity->email === null) {
            $this->warn("Skipped {$identity->name}: no email address.");

            continue;
        }

        $user = User::query()->where('oidc_sub', $identity->id)->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($identity->email)])->first();

        if ($user && $user->oidc_sub !== null && $user->oidc_sub !== $identity->id) {
            $this->warn("Skipped {$identity->email}: linked to another identity.");

            continue;
        }

        $groups = array_values(array_filter($identity->groups, fn (string $group): bool => $services->has($group)));
        $this->line(sprintf('%s %s (%s)', $user ? 'link' : 'create', $identity->email, $groups === [] ? 'no services' : implode(', ', $groups)));

        if ($dryRun) {
            continue;
        }

        $user ??= new User(['email' => mb_strtolower($identity->email), 'name' => $identity->name ?: Str::before($identity->email, '@')]);
        $user->oidc_sub = $identity->id;
        $user->email_verified_at ??= now();
        $user->save();

        foreach ($groups as $group) {
            ServiceAccount::query()->firstOrCreate(
                ['user_id' => $user->id, 'service_id' => $services[$group]->id],
                ['status' => AccountStatus::Active, 'approved_at' => now()],
            );
        }
    }

    return 0;
})->purpose('Import the users of the identity provider and their service groups');

Artisan::command('accounts:sync-groups', function () {
    $users = User::query()->whereNotNull('oidc_sub')->get();

    $users->each(fn (User $user) => SyncUserGroups::dispatchSync($user));

    $this->info("Synced the groups of {$users->count()} user(s).");
})->purpose('Set the groups of all linked users in the identity provider');

Artisan::command('registrations:prune', function () {
    $pruned = Registration::query()
        ->where(fn ($query) => $query
            ->where('expires_at', '<', now())
            ->orWhere('confirmed_at', '<', now()->subDays(30)))
        ->delete();

    $this->info("Pruned {$pruned} registration(s).");
})->purpose('Delete expired and old confirmed registrations');

Artisan::command('households:prune', function () {
    $invitations = HouseholdInvitation::query()
        ->where(fn ($query) => $query
            ->where('expires_at', '<', now()->subDays(30))
            ->orWhere('accepted_at', '<', now()->subDays(30)))
        ->delete();

    $deliveries = WebhookDelivery::query()->where('created_at', '<', now()->subDays(30))->delete();

    $this->info("Pruned {$invitations} invitation(s) and {$deliveries} webhook delivery(ies).");
})->purpose('Delete old household invitations and webhook deliveries (older than 30 days)');

Artisan::command('entitlements:refresh {--all : Recompute the entitlements of every linked user}', function (Entitlements $entitlements) {
    // Subscriptions past their end are ended (the observer sends the new entitlements).
    $expired = Subscription::query()->where('status', SubscriptionStatus::Active)->where('ends_at', '<=', now())->get();
    $expired->each(fn (Subscription $subscription) => $subscription->update(['status' => SubscriptionStatus::Ended]));

    // Subscriptions that started since the last runs (a day back, in case runs were missed); unchanged snapshots send nothing.
    $userIds = $this->option('all')
        ? User::query()->whereNotNull('oidc_sub')->pluck('id')
        : Subscription::query()->where('status', SubscriptionStatus::Active)->whereBetween('starts_at', [now()->subDay(), now()])->pluck('user_id');

    $entitlements->refreshUsers(array_map('intval', $userIds->all()));

    $this->info("Ended {$expired->count()} subscription(s), checked {$userIds->unique()->count()} user(s).");
})->purpose('End expired subscriptions and send the entitlements of subscriptions that started');

Artisan::command('billing:run', function (Billing $billing) {
    if (! $billing->enabled()) {
        $this->warn('Billing is disabled (BILLING_CREDITOR_IBAN is not set): no invoices are created.');
    }

    try {
        $run = $billing->run();
    } catch (BillingException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info("Created {$run->created} invoice(s), sent {$run->issued}, {$run->overdue} became overdue, sent {$run->reminded} reminder(s).");

    return 0;
})->purpose('Create the invoices of due subscription periods, send them, mark overdue invoices and send reminders');

Schedule::command('entitlements:refresh')->everyFifteenMinutes();
Schedule::command('billing:run')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('registrations:prune')->daily();
Schedule::command('households:prune')->daily();
