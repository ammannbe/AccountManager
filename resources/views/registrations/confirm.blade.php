<x-layout :title="__('Confirm registration')">
    @if ($registration)
        <h1 class="mb-4 text-xl font-semibold">{{ __('Confirm registration') }}</h1>
        <p class="mb-6 text-stone-600">{{ __('Complete the registration of :email for :service.', ['email' => $registration->email, 'service' => $registration->service->name]) }}</p>
        <form method="POST" action="{{ route('registrations.confirm', ['token' => $token]) }}">
            @csrf
            <x-button>{{ __('Confirm registration') }}</x-button>
        </form>
    @else
        <h1 class="mb-4 text-xl font-semibold">{{ __('Link not valid') }}</h1>
        <p class="text-stone-600">{{ __('The link has expired or was already used. Register again or log in.') }}</p>
        <div class="mt-6"><x-button :href="route('login')" secondary>{{ __('Log in') }}</x-button></div>
    @endif
</x-layout>
