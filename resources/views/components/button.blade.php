@props(['href' => null, 'secondary' => false])
@php $classes = ($secondary ? 'button-secondary' : 'button').' w-full'; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
