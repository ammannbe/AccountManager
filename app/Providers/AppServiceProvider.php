<?php

namespace App\Providers;

use App\Auth\OidcProvider;
use App\Identity\IdentityProvider;
use App\Identity\ManualIdentityProvider;
use App\Identity\PocketIdProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityProvider::class, fn (): IdentityProvider => match (config('identity.driver')) {
            'pocket-id' => new PocketIdProvider(
                url: (string) config('identity.pocket_id.url'),
                apiKey: (string) config('identity.pocket_id.api_key'),
                loginCodeTtl: (string) config('identity.pocket_id.login_code_ttl'),
                timeout: (int) config('identity.pocket_id.timeout'),
            ),
            default => new ManualIdentityProvider,
        });
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('oidc', OidcProvider::class);
        });
    }
}
