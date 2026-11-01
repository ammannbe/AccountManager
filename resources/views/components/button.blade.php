@props(['href' => null, 'secondary' => false])
@php
    $classes = $secondary
        ? 'inline-flex w-full items-center justify-center rounded-lg border border-stone-300 bg-white px-4 py-2 font-medium hover:bg-stone-100'
        : 'inline-flex w-full items-center justify-center rounded-lg bg-stone-900 px-4 py-2 font-medium text-white hover:bg-stone-700';
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
