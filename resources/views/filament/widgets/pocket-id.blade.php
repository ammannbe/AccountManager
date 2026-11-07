@php use Filament\Support\Icons\Heroicon; @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
            <div>
                <h2 style="font-weight: 600;">Pocket ID</h2>
                <p style="font-size: 0.875rem; opacity: 0.7;">{{ __('Users, groups and OIDC clients of the login provider.') }}</p>
            </div>
            <x-filament::button tag="a" :href="$url" target="_blank" rel="noopener" color="gray" :icon="Heroicon::ArrowTopRightOnSquare" icon-position="after">
                {{ __('Manage Pocket ID') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
