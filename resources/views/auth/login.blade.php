<x-layout :title="__('Log in')">
    <p class="mb-6 text-center text-stone-600">{{ __('Manage your access to all services in one place.') }}</p>

    @if (config('login.oidc.enabled'))
        <x-button :href="route('oidc.redirect')">{{ __('Log in with :provider', ['provider' => App\Auth\OidcLabel::get()]) }}</x-button>
    @else
        <p class="notice">{{ __('The login is not configured yet.') }}</p>
    @endif

    @if ($signup)
        <p class="my-6 flex items-center gap-3 text-sm text-stone-500">
            <span class="h-px flex-1 bg-stone-200"></span>
            {{ __('or') }}
            <span class="h-px flex-1 bg-stone-200"></span>
        </p>
        <x-button :href="route('registrations.account')" secondary>{{ __('Create account') }}</x-button>
    @endif

    <p class="mt-10 text-center text-sm text-stone-500">
        <a class="underline" href="{{ route('filament.admin.auth.login') }}">{{ __('Admin login') }}</a>
    </p>
</x-layout>
