<x-layout :title="__('Confirm email address')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Confirm email address') }}</h1>
    <p class="mb-6 text-stone-600">{{ __('Use :email as the new address of your account.', ['email' => $email]) }}</p>
    <form method="POST" action="{{ $url }}">
        @csrf
        <x-button>{{ __('Confirm email address') }}</x-button>
    </form>
</x-layout>
