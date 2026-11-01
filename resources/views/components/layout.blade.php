@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' – ' : '' }}{{ __('Account') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased">
    <main class="mx-auto flex min-h-dvh max-w-xl flex-col px-6 py-12">
        <header class="mb-10 flex items-center justify-between">
            <a href="{{ auth()->check() ? route('portal') : route('login') }}" class="text-2xl font-semibold">{{ __('Account') }}</a>
            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm underline">{{ __('Log out') }}</button>
                </form>
            @endauth
        </header>

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
