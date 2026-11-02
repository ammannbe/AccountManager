{{-- Inline styles: the panel uses Filament's prebuilt CSS, which lacks most Tailwind utilities. --}}
@if (config('login.oidc.enabled'))
    <div style="display: flex; flex-direction: column; row-gap: 1rem;">
        <div style="display: flex; align-items: center; column-gap: 0.75rem; font-size: 0.875rem; color: var(--gray-500);">
            <span style="height: 1px; flex: 1; background: currentColor; opacity: 0.3;"></span>
            {{ __('or') }}
            <span style="height: 1px; flex: 1; background: currentColor; opacity: 0.3;"></span>
        </div>

        <x-filament::button
            tag="a"
            :href="route('oidc.redirect')"
            color="gray"
            icon="heroicon-o-key"
            size="lg"
        >
            {{ __("Log in with :provider", ["provider" => App\Auth\OidcLabel::get()]) }}
        </x-filament::button>
    </div>
@endif
