@props(['label', 'name', 'type' => 'text', 'hint' => null])

<label class="block">
    <span class="label">{{ $label }}</span>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $attributes->get('value')) }}"
        {{ $attributes->except('value')->merge(['class' => 'field w-full']) }}>
    @if ($hint)
        <span class="mt-1 block text-sm text-stone-500">{{ $hint }}</span>
    @endif
</label>
