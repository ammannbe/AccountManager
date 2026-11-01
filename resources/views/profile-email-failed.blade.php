<x-layout :title="__('Address not changed')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Address not changed') }}</h1>
    <p class="mb-6 text-stone-600">{{ __('This address cannot be used for your account, or the link is outdated. Change the address in your profile again or try again in a few minutes.') }}</p>
    <x-button :href="route('profile')" secondary>{{ __('Profile') }}</x-button>
</x-layout>
