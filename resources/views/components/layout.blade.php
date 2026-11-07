@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' – ' : '' }}{{ __('Account') }}</title>
    <meta name="theme-color" content="#b91c1c" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1c1917" media="(prefers-color-scheme: dark)">
    @include('partials.favicon')
    @include('partials.meta')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased">
    @auth
        {{-- App bar like in Chorelist and Shoppinglist: icon and name, the sections as tabs below. --}}
        <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
            <div class="mx-auto flex h-14 max-w-xl items-center gap-2 px-3">
                <a href="{{ route('portal') }}" class="flex min-w-0 flex-1 items-center gap-2 text-lg font-semibold">
                    <img src="/favicon-96x96.png" alt="" class="size-7 rounded-md">
                    <span class="truncate">{{ __('Account') }}</span>
                </a>
                {{-- Admins: the panel in a new tab (same check as the panel itself). --}}
                @php $panel = \Filament\Facades\Filament::getPanel('admin'); @endphp
                @if (auth()->user()->canAccessPanel($panel))
                    <a href="{{ $panel->getUrl() }}" target="_blank" rel="noopener" class="inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100">
                        {{ __('Admin') }}
                        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-button" title="{{ __('Log out') }}" aria-label="{{ __('Log out') }}">
                        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                    </button>
                </form>
            </div>
            <nav class="mx-auto flex max-w-xl gap-1 px-3 pb-2" aria-label="{{ __('Account') }}">
                @php
                    $tabs = ['portal' => __('My services'), 'households.index' => __('Households')];
                    // Invoices only where billing is on or the user has some.
                    if (app(\App\Billing\BillingConfig::class)->enabled() || auth()->user()->invoices()->issued()->exists()) {
                        $tabs['invoices.index'] = __('Invoices');
                    }
                    $tabs['profile'] = __('Profile');
                @endphp
                @foreach ($tabs as $route => $label)
                    @php $active = request()->routeIs($route); @endphp
                    <a href="{{ route($route) }}" @class(['tab', 'tab-active' => $active]) @if ($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="mx-auto max-w-xl px-4 pt-6 pb-12">
            @include('partials.flash')
            {{ $slot }}
        </main>
    @else
        {{-- Guests: centred column with the icon, like the login pages of the apps. --}}
        <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6 py-12">
            <a href="{{ route('login') }}" class="mb-8 flex flex-col items-center gap-3">
                <img src="/web-app-manifest-192x192.png" alt="" class="size-16 rounded-2xl">
                <span class="text-2xl font-semibold">{{ __('Account') }}</span>
            </a>
            @include('partials.flash')
            {{ $slot }}
        </main>
    @endauth
</body>
</html>
