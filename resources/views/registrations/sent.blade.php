<x-layout :title="__('Check your email')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Check your email') }}</h1>
    <p class="text-stone-600">{{ __('If the address is valid, you will receive an email with a link to confirm your registration for :service.', ['service' => $service->name]) }}</p>
</x-layout>
