<x-layout :title="__('Invitation')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Invitation') }}</h1>

    @if (! $invitation)
        <p class="mb-6 text-stone-600">{{ __('This invitation is invalid or has expired.') }}</p>
        <x-button :href="auth()->check() ? route('households.index') : route('login')" secondary>{{ __('Continue') }}</x-button>
    @else
        <p class="mb-2">
            @if ($invitation->inviter)
                {{ __(':name invites you to the household ":household".', ['name' => $invitation->inviter->name, 'household' => $invitation->household->name]) }}
            @else
                {{ __('You are invited to the household ":household".', ['household' => $invitation->household->name]) }}
            @endif
        </p>
        <p class="mb-6 text-sm text-stone-500">{{ __('You see what the members share with the household in the connected apps, and they see your name and email address.') }}</p>

        @guest
            <p class="mb-4 text-sm text-stone-600">{{ __('Log in with :email to join. You come back here afterwards.', ['email' => $invitation->email]) }}</p>
            <div class="flex flex-col gap-3">
                <x-button :href="route('login')">{{ __('Log in') }}</x-button>
                @if ($signup)
                    <x-button :href="route('registrations.account')" secondary>{{ __('Create account') }}</x-button>
                @endif
            </div>
        @else
            @if (! $invitation->isFor(auth()->user()))
                <p class="notice">{{ __('This invitation is for :email. Log in with that address and confirm it in your profile.', ['email' => $invitation->email]) }}</p>
            @else
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('household-invitations.accept-token', $token) }}" class="flex-1">
                        @csrf
                        <x-button>{{ __('Join household') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('household-invitations.decline-token', $token) }}">
                        @csrf
                        <button type="submit" class="button-secondary">{{ __('Decline') }}</button>
                    </form>
                </div>
            @endif
        @endguest
    @endif
</x-layout>
