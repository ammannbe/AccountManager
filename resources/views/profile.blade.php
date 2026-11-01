<x-layout :title="__('Profile')">
    <h1 class="mb-6 text-xl font-semibold">{{ __('Profile') }}</h1>

    <form method="POST" action="{{ route('profile.update') }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name', $user->name) }}" required maxlength="100" autocomplete="name" class="rounded-lg border border-stone-300 px-3 py-2">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('Email') }}</span>
            <input name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email" class="rounded-lg border border-stone-300 px-3 py-2">
            <span class="text-sm text-stone-600">{{ __('A new address is used as soon as you confirm it by email.') }}</span>
        </label>
        <x-button>{{ __('Save') }}</x-button>
    </form>

    @if (config('identity.account_url'))
        <section class="mt-10">
            <h2 class="mb-2 font-semibold">{{ __('Passkeys') }}</h2>
            <p class="mb-4 text-sm text-stone-600">{{ __('You log in with a passkey. Add one for another device or remove old ones at the login provider.') }}</p>
            <x-button :href="config('identity.account_url')" secondary>{{ __('Manage passkeys') }}</x-button>
        </section>
    @endif
</x-layout>
