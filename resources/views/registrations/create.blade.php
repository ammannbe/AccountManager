@php $title = $service ? __('Register for :service', ['service' => $service->name]) : __('Create account'); @endphp
<x-layout :title="$title">
    <h1 class="mb-2 text-xl font-semibold">{{ $title }}</h1>
    @if ($service?->description)
        <p class="mb-6 text-stone-600">{{ $service->description }}</p>
    @elseif (! $service)
        <p class="mb-6 text-stone-600">{{ __('One account for all services: afterwards you choose the services you want to use.') }}</p>
    @endif

    <form method="POST" action="{{ $service ? route('registrations.store', $service) : route('registrations.account.store') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="rendered_at" value="{{ $renderedAt }}">
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name" class="rounded-lg border border-stone-300 px-3 py-2">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('Email') }}</span>
            <input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="rounded-lg border border-stone-300 px-3 py-2">
        </label>
        {{-- Honeypot: humans never see or reach this field. --}}
        <div aria-hidden="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;">
            <label>Website <input name="website" tabindex="-1" autocomplete="off"></label>
        </div>
        <x-button>{{ __('Register') }}</x-button>
    </form>

    <p class="mt-6 text-sm text-stone-600">{{ __('You will receive an email to confirm your address. Afterwards you log in with a passkey (Face ID, Touch ID, Windows Hello or a security key) instead of a password.') }}</p>
    <p class="mt-4 text-center text-sm"><a class="underline" href="{{ route('login') }}">{{ __('I already have an account') }}</a></p>
</x-layout>
