{{-- Layout of Laravel's error pages (404, 419, 500 …) in the look of the portal; no session or $errors here. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') – {{ __('Account') }}</title>
    <meta name="theme-color" content="#b91c1c" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1c1917" media="(prefers-color-scheme: dark)">
    @include('partials.favicon')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased">
    <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6 py-12 text-center">
        <a href="/" class="mb-8 flex flex-col items-center gap-3">
            <img src="/web-app-manifest-192x192.png" alt="" class="size-16 rounded-2xl">
            <span class="text-2xl font-semibold">{{ __('Account') }}</span>
        </a>
        <p class="text-sm font-medium text-stone-500">@yield('code')</p>
        <h1 class="mb-8 text-xl font-semibold">@yield('message')</h1>
        <a href="/" class="button w-full">{{ __('Continue') }}</a>
    </main>
</body>
</html>
