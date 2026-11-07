# AccountManager

Central account portal («Konto») for self-hosted services: one login through an OpenID Connect provider ([Pocket ID](https://pocket-id.org)), a catalog of services, self-registration per service with email confirmation, access requests and approvals, and the sync of the provider's groups.

Households shared across apps (members, roles, invitations, signed webhooks and an API for the apps, see [docs/HOUSEHOLDS-API.md](docs/HOUSEHOLDS-API.md)). Planned: subscriptions with Swiss QR-bill invoices, entitlements for the apps. See [docs/KONZEPT.md](docs/KONZEPT.md) (German).

Built with Laravel and Filament. See [AGENTS.md](AGENTS.md) for the architecture.

## Development

Requires [DDEV](https://ddev.com).

```bash
ddev start
ddev composer install
ddev npm install && ddev npm run build
ddev artisan key:generate
ddev artisan migrate
ddev artisan accounts:make-admin you@example.com --password
```

## License

See [LICENSE](LICENSE).
