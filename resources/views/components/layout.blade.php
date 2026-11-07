@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' – ' : '' }}{{ __('Account') }}</title>
    @include('partials.favicon')
    @include('partials.meta')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased">
    <main class="mx-auto flex min-h-dvh max-w-xl flex-col px-6 py-12">
        <header class="mb-10 flex items-center justify-between">
            <a href="{{ auth()->check() ? route('portal') : route('login') }}" class="inline-flex items-center gap-3 text-2xl font-semibold"><img src="/favicon-96x96.png" alt="" class="size-9 rounded-lg">{{ __('Account') }}</a>
            @auth
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('households.index') }}" class="underline">{{ __('Households') }}</a>
                    <a href="{{ route('profile') }}" class="underline">{{ __('Profile') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="underline">{{ __('Log out') }}</button>
                    </form>
                </nav>
            @endauth
        </header>

        @if (session('status'))
            <p class="mb-6 rounded-lg bg-green-100 px-4 py-3 text-sm text-green-800">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <ul class="mb-6 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
