<?php

namespace App\Providers;

use App\Auth\OidcProvider;
use App\Identity\IdentityProvider;
use App\Identity\ManualIdentityProvider;
use App\Identity\PocketIdProvider;
use Illuminate\Foundation\Events\LocaleUpdated;
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

        $this->useBaseLanguage($this->app->getLocale());
        Event::listen(fn (LocaleUpdated $event) => $this->useBaseLanguage($event->locale));
    }

    /**
     * Regional locales without own translations (e.g. APP_LOCALE=de_CH) use the
     * base language: JSON keys never fall back, so lang/de.json and Filament's de
     * would be skipped and everything shown in English.
     */
    private function useBaseLanguage(string $locale): void
    {
        $base = strtok(str_replace('-', '_', $locale), '_');

        if ($base !== false && $base !== $locale && ! is_file(lang_path("{$locale}.json")) && is_file(lang_path("{$base}.json"))) {
            $this->app->setLocale($base);
        }
    }
}
