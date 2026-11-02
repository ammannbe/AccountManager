@if (config('login.oidc.enabled'))
    <div class="flex flex-col gap-y-4">
        <div class="flex items-center gap-x-3 text-sm text-gray-500 dark:text-gray-400">
            <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
            {{ __('or') }}
            <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
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
