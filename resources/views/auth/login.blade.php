<x-layout :title="__('Log in')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Log in') }}</h1>
    <p class="mb-6 text-stone-600">{{ __('Manage your access to all services in one place.') }}</p>

    @if (config('login.oidc.enabled'))
        <x-button :href="route('oidc.redirect')">{{ __('Log in with :provider', ['provider' => config('login.oidc.label')]) }}</x-button>
    @else
        <p class="rounded-lg bg-amber-100 px-4 py-3 text-sm text-amber-900">{{ __('The login is not configured yet.') }}</p>
    @endif

    <p class="mt-8 text-center text-sm text-stone-500">
        <a class="underline" href="{{ route('filament.admin.auth.login') }}">{{ __('Admin login') }}</a>
    </p>
</x-layout>
