# AGENTS.md

AccountManager («Konto»): central account portal for own services. Users log in through an OpenID Connect provider (Pocket ID), see their services, register per service, and admins manage services and access. Concept, decisions and phases: `docs/KONZEPT.md` (German). Phase 4 (MVP) is implemented; subscriptions, invoices, webhooks and households follow.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`accountmanager.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Filament 5 admin panel at `/admin` (`AdminPanelProvider`, only `users.is_admin`). The user facing part is plain Blade + Tailwind 4 (`resources/views`, layout `<x-layout>`).
- Laravel Socialite + `kovah/laravel-socialite-oidc` for the login, Larastan level 7.
- Generic for other hosters: domains, provider, texts only through configuration. Default locale `de` (`lang/de.json`, keys are the English texts, Swiss spelling "ss", informal "du").

## Concepts

- `services`: catalog (`key` = registration slug, `type` oidc|external, `access_group` = group in the identity provider, `self_signup`, `requires_approval`, `visible`, `position`).
- `service_accounts`: access of a user to a service, status `requested` | `active` | `suspended` (`App\Enums\AccountStatus`). `App\Accounts\Access`: `claim()` (portal and registration: active right away with self-registration and without approval, otherwise requested and the admins get `AccessRequested`), `approve()` (mails `AccessGranted`), `suspend()`. Suspended accounts stay suspended for the user.
- The AccountManager is the source of truth for access: `ServiceAccountObserver` queues `SyncUserGroups` on status changes; it sets the user's groups in the provider to the `access_group`s of their active accounts (`User::desiredGroups()`) and keeps all groups no service manages (`Service::managedGroups()`). The OIDC clients in Pocket ID are restricted to their group, so a missing group blocks the login at the provider.
- Self-registration (`RegistrationController`, `App\Accounts\Registrar`): `/register/{service}` (honeypot `website` + 3 s time trap with the encrypted render time, throttled, same answer for known and unknown addresses, no new mail to the same address within 5 minutes) → `registrations` row with the SHA-256 of the token, `ConfirmRegistration` mail (valid `Registration::LIFETIME_DAYS`) → `GET /confirm/{token}` only shows a button (mail scanners must not confirm) → `POST` creates or finds the local user and the provider identity (`oidc_sub` = Pocket ID user id), claims the service, syncs the groups synchronously and lets Pocket ID mail a login code to new identities. Failures at the provider roll back (the link can be used again). `registrations:prune` (daily).
- Login: `/login` (button) → `/auth/oidc/redirect|callback` (`OidcController`, `App\Auth\OidcLogin`: by `oidc_sub`, otherwise by an email the provider marks as verified; unknown users get a local account with `AUTH_OIDC_REGISTRATION`). `App\Auth\OidcProvider` checks `iss`, `aud`/`azp`, `exp`. Admins may also log in with a password at `/admin/login` (emergency access only; `php artisan accounts:make-admin <email> --password`). Same structure as RecipeManager, Shoppinglist and Chorelist.

## Identity provider

- `App\Identity\IdentityProvider` (find, list and create users, sync groups, send a login code), bound by `IDENTITY_DRIVER` (`config/identity.php`): `pocket-id` (`PocketIdProvider`, REST API with `X-API-KEY`: `POST /api/users`, `GET /api/users/{id}/groups`, `PUT /api/users/{id}/user-groups`, `POST /api/user-groups` for missing groups, `POST /api/users/{id}/one-time-access-email`) or `manual` (reduced mode: nothing is created or synced, the admins get `ManualSetupNeeded`).
- Pocket ID needs SMTP and "Email login code from admin" for the login codes, and an API key of an admin (`POCKET_ID_API_KEY`).
- Commands: `accounts:import [--dry-run]` (links provider users by id/email and creates active accounts for the groups of services), `accounts:sync-groups`, `accounts:make-admin`.

## Deployment

- KeyHelp (PHP-FPM + cron), like the other apps. `.forgejo/workflows/deploy.yml` (same as RecipeManager): push to `main` → GitHub → Forgejo pull mirror → builds the assets in CI, uploads `public/build`, then `git pull` + `composer install --no-dev` + `migrate` + `optimize` on the server via SSH. Forgejo variables `SSH_HOST`, `SSH_USER`, `SSH_FINGERPRINT`, `DEPLOY_PATH`, `PRIMARY_SITE_URL`, secret `SSH_KEY`.
- Cron every minute: `schedule:run` and `queue:work --max-time=50` (mails and the group sync are queued).

## Local development

- Pocket ID runs in the RecipeManager DDEV project (`https://recipemanager.ddev.site:14111`, API key `local-development-only-api-key`, see RecipeManager `AGENTS.md`); reach it at `http://ddev-RecipeManager-pocket-id:1411`. Put `AUTH_OIDC_*`, `IDENTITY_DRIVER=pocket-id`, `POCKET_ID_URL`, `POCKET_ID_API_KEY` into the git-ignored `.ddev/config.local.yaml` (`web_environment`). Mails of the AccountManager: its Mailpit (`ddev mailpit`); login codes are mailed by Pocket ID (RecipeManager's Mailpit).
- Mails and the group sync are queued: `ddev artisan queue:work --stop-when-empty` (production: cron `schedule:run` + `queue:work --max-time=50` like the other apps).

## Commands

- `ddev composer test` (Feature tests, SQLite in memory; `tests/Fakes/FakeIdentityProvider` via `$this->fakeIdentityProvider()`, Pocket ID API with `Http::fake()`), `ddev composer pint`, `ddev composer phpstan`. Run all three after changes.
- `ddev npm run build` for the Blade styles.

## Rules

- Never store credentials in the repository; `.env` and `.ddev/config.local.yaml` are git-ignored.
- Only change groups the AccountManager manages (the `access_group`s of services); others belong to the admin.
- Registration and confirmation must not reveal whether an address is known.
