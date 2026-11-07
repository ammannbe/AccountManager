<x-layout :title="__('Profile')">
    <h1 class="mb-6 text-xl font-semibold">{{ __('Profile') }}</h1>

    <form method="POST" action="{{ route('profile.update') }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')
        <x-input :label="__('Name')" name="name" :value="$user->name" required maxlength="100" autocomplete="name" />
        <x-input :label="__('Email')" name="email" type="email" :value="$user->email" required maxlength="255" autocomplete="email"
            :hint="__('A new address is used as soon as you confirm it by email.')" />
        <x-button>{{ __('Save') }}</x-button>
    </form>

    @if (config('identity.account_url'))
        <section class="mt-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Passkeys') }}</h2>
            <p class="mb-4 text-sm text-stone-600">{{ __('You log in with a passkey. Add one for another device or remove old ones at the login provider.') }}</p>
            <x-button :href="config('identity.account_url')" secondary>
                <x-passkey-icon />
                {{ __('Manage passkeys') }}
            </x-button>
        </section>
    @endif
</x-layout>
