@php use App\Enums\HouseholdRole; @endphp
<x-layout :title="__('Households')">
    <h1 class="mb-1 text-xl font-semibold">{{ __('Households') }}</h1>
    <p class="mb-6 text-sm text-stone-600">{{ __('A household shares contents across the apps, e.g. a cookbook or shopping lists. Whoever joins sees everything shared with it; whoever leaves loses access everywhere.') }}</p>

    @if ($invitations->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Invitations') }}</h2>
            <ul class="space-y-3">
                @foreach ($invitations as $invitation)
                    <li class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                        <p class="mb-3">
                            @if ($invitation->inviter)
                                {{ __(':name invites you to the household ":household".', ['name' => $invitation->inviter->name, 'household' => $invitation->household->name]) }}
                            @else
                                {{ __('You are invited to the household ":household".', ['household' => $invitation->household->name]) }}
                            @endif
                        </p>
                        @if ($verified)
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('household-invitations.accept', $invitation) }}" class="flex-1">
                                    @csrf
                                    <x-button>{{ __('Join household') }}</x-button>
                                </form>
                                <form method="POST" action="{{ route('household-invitations.decline', $invitation) }}">
                                    @csrf
                                    <button type="submit" class="button-secondary">{{ __('Decline') }}</button>
                                </form>
                            </div>
                        @else
                            <p class="text-stone-600">{{ __('Confirm your email address in your profile to join.') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @forelse ($households as $household)
        @php
            $role = $household->membership->role;
            $admin = $role === HouseholdRole::Admin;
        @endphp
        <section class="mb-10 space-y-4" id="household-{{ $household->uuid }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="min-w-0 truncate text-lg font-semibold">🏠 {{ $household->name }}</h2>
                <span class="badge bg-stone-100 text-stone-700">{{ $role->getLabel() }}</span>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Members') }}</h3>
                <ul class="rows text-sm">
                    @foreach ($household->users as $member)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate">
                                    {{ $member->name }}
                                    @if ($member->is(auth()->user()))
                                        <span class="text-stone-500">({{ __('you') }})</span>
                                    @endif
                                </span>
                                <span class="block truncate text-xs text-stone-500">{{ $member->email }}</span>
                            </span>
                            @if ($admin && ! $member->is(auth()->user()))
                                <span class="flex items-center gap-3">
                                    <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                        @csrf
                                        @method('PUT')
                                        <select name="role" onchange="this.form.submit()" aria-label="{{ __('Role') }}" class="field py-1 text-sm">
                                            @foreach (HouseholdRole::cases() as $option)
                                                <option value="{{ $option->value }}" @selected($member->membership->role === $option)>{{ $option->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                        <noscript><button type="submit" class="underline">{{ __('Save') }}</button></noscript>
                                    </form>
                                    <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}" onsubmit="return confirm(@js(__('Remove :name from the household? They lose access to everything shared with the household in all connected apps, and their own contents (e.g. cookbooks, lists) are no longer shared with it; the contents stay with them.', ['name' => $member->name])))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-700 underline">{{ __('Remove') }}</button>
                                    </form>
                                </span>
                            @else
                                <span class="text-xs text-stone-500">{{ $member->membership->role->getLabel() }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($household->shares->isNotEmpty())
                <div>
                    <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Shared') }}</h3>
                    <ul class="rows text-sm">
                        @foreach ($household->shares->groupBy('service_id') as $shares)
                            <li class="px-3 py-2"><span class="font-medium">{{ $shares->first()->service->name }}:</span> {{ $shares->pluck('label')->join(', ') }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($admin)
                @if ($household->invitations->isNotEmpty())
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Open invitations') }}</h3>
                        <ul class="rows text-sm">
                            @foreach ($household->invitations as $invitation)
                                <li class="flex items-center justify-between gap-2 px-3 py-2">
                                    <span class="min-w-0 truncate">{{ $invitation->email }} <span class="text-stone-500">({{ $invitation->role->getLabel() }})</span></span>
                                    <form method="POST" action="{{ route('households.invitations.destroy', [$household, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-700 underline">{{ __('Revoke') }}</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <details class="rounded-lg border border-stone-200 bg-white text-sm">
                    <summary class="cursor-pointer px-3 py-2 font-medium">{{ __('Invite someone') }}</summary>
                    <form method="POST" action="{{ route('households.invitations.store', $household) }}" class="space-y-2 border-t border-stone-200 p-3">
                        @csrf
                        <div class="flex gap-2">
                            <input name="email" type="email" required maxlength="255" autocomplete="email" placeholder="{{ __('Email') }}" aria-label="{{ __('Email') }}" class="field min-w-0 flex-1">
                            <select name="role" aria-label="{{ __('Role') }}" class="field">
                                @foreach (HouseholdRole::cases() as $option)
                                    <option value="{{ $option->value }}" @selected($option === HouseholdRole::Member)>{{ $option->getLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-button>{{ __('Send invitation') }}</x-button>
                    </form>
                </details>

                <details class="rounded-lg border border-stone-200 bg-white text-sm">
                    <summary class="cursor-pointer px-3 py-2 font-medium">{{ __('Rename') }}</summary>
                    <form method="POST" action="{{ route('households.update', $household) }}" class="flex gap-2 border-t border-stone-200 p-3">
                        @csrf
                        @method('PUT')
                        <input name="name" value="{{ $household->name }}" required maxlength="100" aria-label="{{ __('Name') }}" class="field min-w-0 flex-1">
                        <button type="submit" class="button-secondary">{{ __('Save') }}</button>
                    </form>
                </details>
            @endif

            <div class="flex flex-wrap gap-4 text-sm">
                <form method="POST" action="{{ route('households.members.destroy', [$household, auth()->user()]) }}" onsubmit="return confirm(@js(__('Leave the household? You lose access to everything shared with it in all connected apps, and your own contents (e.g. cookbooks, lists) are no longer shared with it; the contents stay with you.')))">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-700 underline">{{ __('Leave household') }}</button>
                </form>
                @if ($admin)
                    <form method="POST" action="{{ route('households.destroy', $household) }}" onsubmit="return confirm(@js(__('Delete the household? The members lose access to everything shared with it; the contents stay with their owners.')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-700 underline">{{ __('Delete household') }}</button>
                    </form>
                @endif
            </div>
        </section>
    @empty
        <p class="mb-10 py-8 text-center text-stone-500">{{ __('You are not in a household yet.') }}</p>
    @endforelse

    <section>
        <h2 class="mb-2 text-lg font-semibold">{{ __('New household') }}</h2>
        <form method="POST" action="{{ route('households.store') }}" class="space-y-3">
            @csrf
            <input name="name" required maxlength="100" placeholder="{{ __('e.g. Family Muster') }}" aria-label="{{ __('Name') }}" class="field w-full">
            <x-button>{{ __('Create household') }}</x-button>
        </form>
    </section>
</x-layout>
