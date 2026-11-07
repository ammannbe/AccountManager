# AGENTS.md

AccountManager («Konto»): central account portal for own services. Users log in through an OpenID Connect provider (Pocket ID), see their services, register per service, and admins manage services and access. Concept, decisions and phases: `docs/KONZEPT.md` (German). Phase 4 (MVP) and the households (phase 8, AccountManager side) are implemented; subscriptions, invoices and entitlement webhooks follow.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`accountmanager.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Filament 5 admin panel at `/admin` (`AdminPanelProvider`, only `users.is_admin`). The user facing part is plain Blade + Tailwind 4 (`resources/views`, layout `<x-layout>`).
- Laravel Socialite + `kovah/laravel-socialite-oidc` for the login, Larastan level 7.
- Icon: the Narrenhaus logo (both jesters, traced from the original PNG): `src/logo.svg` (also `public/narrenhaus.svg`), rendered to `src/favicon.png` (1024 px, transparent) → `ddev npm run favicon -- --source src/favicon.png --theme '#b91c1c' --bg '#fafaf9' --padding-bg '#fff4cc' --clean` (`partials/favicon.blade.php`, also in the panel via `HEAD_END`). Highlight color red (buttons, panel `Color::Red`). Link previews: `partials/meta.blade.php` + `public/og-image.png`.
- Generic for other hosters: domains, provider, texts only through configuration. Default locale `de` (`lang/de.json`; regional locales like `de_CH` fall back to it in `AppServiceProvider`, keys are the English texts, Swiss spelling "ss", informal "du").

## Concepts

- `services`: catalog (`key` = registration slug, `type` oidc|external, `login_url` = SSO start of the app, `access_group` = group in the identity provider, `self_signup`, `requires_approval`, `visible`, `position`).
- `service_accounts`: access of a user to a service, status `requested` | `active` | `suspended` (`App\Enums\AccountStatus`). `App\Accounts\Access`: `claim()` (portal and registration: active right away with self-registration and without approval, otherwise requested and the admins get `AccessRequested`), `approve()` (mails `AccessGranted`), `suspend()`. Suspended accounts stay suspended for the user.
- The AccountManager is the source of truth for access: `ServiceAccountObserver` queues `SyncUserGroups` on status changes; it sets the user's groups in the provider to the `access_group`s of their active accounts (`User::desiredGroups()`) and keeps all groups no service manages (`Service::managedGroups()`). The OIDC clients in Pocket ID are restricted to their group, so a missing group blocks the login at the provider.
- Self-registration (`RegistrationController`, `App\Accounts\Registrar`): `/register` (account only, the services follow in the portal; needs `AUTH_SIGNUP_ENABLED` and a managed provider, linked on the login page) or `/register/{service}` (a service key must not be `sent`) (honeypot `website` + 3 s time trap with the encrypted render time, throttled, same answer for known and unknown addresses, no new mail to the same address within 5 minutes) → `registrations` row with the SHA-256 of the token, `ConfirmRegistration` mail (valid `Registration::LIFETIME_DAYS`) → `GET /confirm/{token}` only shows a button (mail scanners must not confirm) → `POST` creates or finds the local user and the provider identity (`oidc_sub` = Pocket ID user id), claims the service (if any), syncs the groups synchronously and lets Pocket ID mail a login code to new identities. An existing identity is linked and its address set as verified (the apps link accounts only by verified addresses). Failures at the provider roll back (the link can be used again). `registrations:prune` (daily).
- Portal (`/`): services with their status, claim access, "Open" leads to `services.login_url` (the SSO start of the app, so logging in links an existing account there by email) or `url`.
- Profile (`ProfileController`, `/profile`): name is saved in the provider right away; a new email address gets a signed link (`profile.email`, 24 h, bound to the old address via `from`, GET only shows a button) and is set in the provider as verified on confirmation. Links to the passkey page of the provider (`identity.account_url`, default `<issuer>/settings/account` with pocket-id). Users never need the provider's UI except for the login and the passkeys (Pocket ID: `ALLOW_OWN_ACCOUNT_EDIT=false`, `HOME_PAGE_URL` = the AccountManager).
- Login: `/login` (button) → `/auth/oidc/redirect|callback` (`OidcController`, `App\Auth\OidcLogin`: by `oidc_sub`, otherwise by an email the provider marks as verified; unknown users get a local account with `AUTH_OIDC_REGISTRATION`). `App\Auth\OidcProvider` checks `iss`, `aud`/`azp`, `exp`. Admins may also log in with a password at `/admin/login` (emergency access only; `php artisan accounts:make-admin <email> --password`). Same structure as RecipeManager, Shoppinglist and Chorelist. Button label `App\Auth\OidcLabel::get()` (same class in all apps): `AUTH_OIDC_LABEL`, otherwise the `appName` Pocket ID publishes at `<issuer>/api/application-configuration` (cached), otherwise "SSO".

- Households (`App\Households\Households`, same rules and names as the local households of RecipeManager/Chorelist): `households` (`uuid` route key, `version` incremented on every change), `household_members` (role `admin`|`member`, `App\Enums\HouseholdRole`), `household_invitations` (SHA-256 of the token, 14 days, bound to the invited verified address, always needs consent; the link `/invitations/{token}` only shows a button, guests get `url.intended` and log in or register). Last-admin protection (`LastHouseholdAdmin`, rows locked). Portal `/households` (`HouseholdController`, `HouseholdInvitationController`); the panel only has read-only overviews. Deleting a user (`User::deleting`) runs `Households::forget()`: longest member becomes admin, empty households are deleted.
- Webhooks to the apps (`App\Households\HouseholdEvents` → `webhook_deliveries` → `App\Jobs\DeliverWebhook`, 8 tries, signed by `App\Webhooks\WebhookSignature`): only to services with `webhook_url` and `secret` where an affected user has an account; payload = full household snapshot (uuid, name, version, members' `sub` + role), nothing else. Each change locks the household row first so versions and snapshots stay consistent.
- API for the apps (`routes/api.php`, `AuthenticateService`: HTTP Basic service key + service secret; `Service::rotateSecret()` in the panel, stored encrypted, shown once): households of a sub, one household, shares, import proposals (`household_imports`, resolved by an admin in the panel, `App\Households\HouseholdImports`). Contract: `docs/HOUSEHOLDS-API.md`; keep it in sync with any change.
- `households:prune` (daily): old invitations and webhook deliveries (30 days).

## Identity provider

- `App\Identity\IdentityProvider` (find, list, create and update users, sync groups, send a login code), bound by `IDENTITY_DRIVER` (`config/identity.php`): `pocket-id` (`PocketIdProvider`, REST API with `X-API-KEY`: `POST /api/users`, `GET|PUT /api/users/{id}` (the PUT replaces the whole user, so unchanged fields are sent back), `GET /api/users/{id}/groups`, `PUT /api/users/{id}/user-groups`, `POST /api/user-groups` for missing groups, `POST /api/users/{id}/one-time-access-email`) or `manual` (reduced mode: nothing is created or synced, the admins get `ManualSetupNeeded`).
- Pocket ID needs SMTP and "Email login code from admin" for the login codes, and an API key of an admin (`POCKET_ID_API_KEY`).
- Commands: `accounts:import [--dry-run]` (links provider users by id/email and creates active accounts for the groups of services), `accounts:sync-groups`, `accounts:make-admin`.

## Deployment

- KeyHelp (PHP-FPM + cron), like the other apps. `.forgejo/workflows/deploy.yml` (same as RecipeManager): push to `main` → GitHub → Forgejo pull mirror → builds the assets in CI, uploads `public/build`, then `git pull` + `composer install --no-dev` + `migrate` + `optimize` on the server via SSH. Forgejo variables `SSH_HOST`, `SSH_USER`, `SSH_FINGERPRINT`, `DEPLOY_PATH`, `PRIMARY_SITE_URL`, secret `SSH_KEY`.
- Cron every minute: `schedule:run` and `queue:work --max-time=50` (mails, the group sync and the webhooks are queued).

## Local development

- Local Pocket ID: `.ddev/docker-compose.pocket-id.yaml` (`https://accountmanager.ddev.site:14111`, first start `/setup`, dev-only keys incl. API key `local-development-only-api-key`, Application Configuration via env with `UI_CONFIG_DISABLED=true`, mails to this project's Mailpit). Reach it at `http://ddev-AccountManager-pocket-id:1411`; the apps (RecipeManager, Shoppinglist, Chorelist) use the same URL as `AUTH_OIDC_ISSUER` through the shared `ddev_default` network. If the container exited (e.g. after sleep): `docker start ddev-AccountManager-pocket-id`. Put `AUTH_OIDC_*`, `IDENTITY_DRIVER=pocket-id`, `POCKET_ID_URL`, `POCKET_ID_API_KEY` into the git-ignored `.ddev/config.local.yaml` (`web_environment`). Mails of the AccountManager: its Mailpit (`ddev mailpit`); login codes are mailed by Pocket ID (also this Mailpit).
- Mails and the group sync are queued: `ddev artisan queue:work --stop-when-empty` (production: cron `schedule:run` + `queue:work --max-time=50` like the other apps).

## Commands

- `ddev composer test` (Feature tests, SQLite in memory; `tests/Fakes/FakeIdentityProvider` via `$this->fakeIdentityProvider()`, Pocket ID API with `Http::fake()`), `ddev composer pint`, `ddev composer phpstan`. Run all three after changes.
- `ddev npm run build` for the Blade styles.

## Rules

- Never store credentials in the repository; `.env` and `.ddev/config.local.yaml` are git-ignored.
- Only change groups the AccountManager manages (the `access_group`s of services); others belong to the admin.
- Registration and confirmation must not reveal whether an address is known.
- Apps get only household uuid, name and members' `oidc_sub` + role; never send other profile data in webhooks or API responses.
