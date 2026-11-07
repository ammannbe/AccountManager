<x-layout :title="__('Piracy. It’s a crime.')">
    <h1 class="mb-4 text-center text-xl font-semibold">{{ __('Piracy. It’s a crime.') }}</h1>

    <video controls playsinline preload="metadata" poster="{{ asset('easter-egg/poster.jpg') }}" class="aspect-video w-full rounded-lg bg-black">
        <source src="{{ asset('easter-egg/narren-easter-egg.mp4') }}" type="video/mp4">
    </video>

    <p class="mt-6 text-center">
        <a href="{{ route('portal') }}" class="underline">{{ __('Back') }}</a>
    </p>
</x-layout>
