@php
    use App\Enums\HouseholdRole;
    $input = 'rounded-lg border border-stone-300 px-3 py-2';
@endphp
<x-layout :title="__('Households')">
    <h1 class="mb-1 text-xl font-semibold">{{ __('Households') }}</h1>
    <p class="mb-8 text-sm text-stone-600">{{ __('A household shares contents across the apps, e.g. a cookbook or shopping lists. Whoever joins sees everything shared with it; whoever leaves loses access everywhere.') }}</p>

    @if ($invitations->isNotEmpty())
        <section class="mb-8">
            <h2 class="mb-3 font-semibold">{{ __('Invitations') }}</h2>
            <ul class="flex flex-col gap-3">
                @foreach ($invitations as $invitation)
                    <li class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                        <p class="mb-3 text-sm">
                            @if ($invitation->inviter)
                                {{ __(':name invites you to the household ":household".', ['name' => $invitation->inviter->name, 'household' => $invitation->household->name]) }}
                            @else
                                {{ __('You are invited to the household ":household".', ['household' => $invitation->household->name]) }}
                            @endif
                        </p>
                        @if ($verified)
                            <div class="flex gap-3">
                                <form method="POST" action="{{ route('household-invitations.accept', $invitation) }}" class="flex-1">
                                    @csrf
                                    <x-button>{{ __('Join household') }}</x-button>
                                </form>
                                <form method="POST" action="{{ route('household-invitations.decline', $invitation) }}" class="flex-1">
                                    @csrf
                                    <x-button secondary>{{ __('Decline') }}</x-button>
                                </form>
                            </div>
                        @else
                            <p class="text-sm text-stone-600">{{ __('Confirm your email address in your profile to join.') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <ul class="flex flex-col gap-6">
        @forelse ($households as $household)
            @php
                $role = $household->membership->role;
                $admin = $role === HouseholdRole::Admin;
            @endphp
            <li class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <h2 class="font-semibold">{{ $household->name }}</h2>
                    <span class="shrink-0 rounded-full bg-stone-100 px-3 py-1 text-xs font-medium">{{ $role->getLabel() }}</span>
                </div>

                <h3 class="mb-2 text-sm font-medium">{{ __('Members') }}</h3>
                <ul class="mb-4 flex flex-col gap-2 text-sm">
                    @foreach ($household->users as $member)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {{ $member->name }} <span class="text-stone-500">{{ $member->email }}</span>
                                @if ($member->is(auth()->user()))
                                    <span class="text-stone-500">({{ __('you') }})</span>
                                @endif
                            </span>
                            @if ($admin && ! $member->is(auth()->user()))
                                <span class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                        @csrf
                                        @method('PUT')
                                        <select name="role" onchange="this.form.submit()" aria-label="{{ __('Role') }}" class="rounded-lg border border-stone-300 px-2 py-1 text-sm">
                                            @foreach (HouseholdRole::cases() as $option)
                                                <option value="{{ $option->value }}" @selected($member->membership->role === $option)>{{ $option->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                        <noscript><button type="submit" class="underline">{{ __('Save') }}</button></noscript>
                                    </form>
                                    <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}" onsubmit="return confirm(@js(__('Remove :name from the household?', ['name' => $member->name])))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-700 underline">{{ __('Remove') }}</button>
                                    </form>
                                </span>
                            @else
                                <span class="text-stone-500">{{ $member->membership->role->getLabel() }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($household->shares->isNotEmpty())
                    <h3 class="mb-2 text-sm font-medium">{{ __('Shared') }}</h3>
                    <ul class="mb-4 flex flex-col gap-1 text-sm text-stone-700">
                        @foreach ($household->shares->groupBy('service_id') as $shares)
                            <li><span class="font-medium">{{ $shares->first()->service->name }}:</span> {{ $shares->pluck('label')->join(', ') }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($admin)
                    @if ($household->invitations->isNotEmpty())
                        <h3 class="mb-2 text-sm font-medium">{{ __('Open invitations') }}</h3>
                        <ul class="mb-4 flex flex-col gap-2 text-sm">
                            @foreach ($household->invitations as $invitation)
                                <li class="flex items-center justify-between gap-2">
                                    <span>{{ $invitation->email }} <span class="text-stone-500">({{ $invitation->role->getLabel() }})</span></span>
                                    <form method="POST" action="{{ route('households.invitations.destroy', [$household, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="underline">{{ __('Revoke') }}</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <details class="mb-2 text-sm">
                        <summary class="cursor-pointer font-medium">{{ __('Invite someone') }}</summary>
                        <form method="POST" action="{{ route('households.invitations.store', $household) }}" class="mt-3 flex flex-col gap-3">
                            @csrf
                            <input name="email" type="email" required maxlength="255" placeholder="{{ __('Email') }}" aria-label="{{ __('Email') }}" class="{{ $input }}">
                            <select name="role" aria-label="{{ __('Role') }}" class="{{ $input }}">
                                @foreach (HouseholdRole::cases() as $option)
                                    <option value="{{ $option->value }}" @selected($option === HouseholdRole::Member)>{{ $option->getLabel() }}</option>
                                @endforeach
                            </select>
                            <x-button secondary>{{ __('Send invitation') }}</x-button>
                        </form>
                    </details>

                    <details class="mb-2 text-sm">
                        <summary class="cursor-pointer font-medium">{{ __('Rename') }}</summary>
                        <form method="POST" action="{{ route('households.update', $household) }}" class="mt-3 flex flex-col gap-3">
                            @csrf
                            @method('PUT')
                            <input name="name" value="{{ $household->name }}" required maxlength="100" aria-label="{{ __('Name') }}" class="{{ $input }}">
                            <x-button secondary>{{ __('Save') }}</x-button>
                        </form>
                    </details>
                @endif

                <div class="mt-4 flex flex-wrap gap-4 text-sm">
                    <form method="POST" action="{{ route('households.members.destroy', [$household, auth()->user()]) }}" onsubmit="return confirm(@js(__('Leave the household? You lose access to everything shared with it.')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="underline">{{ __('Leave household') }}</button>
                    </form>
                    @if ($admin)
                        <form method="POST" action="{{ route('households.destroy', $household) }}" onsubmit="return confirm(@js(__('Delete the household? The members lose access to everything shared with it; the contents stay with their owners.')))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-700 underline">{{ __('Delete household') }}</button>
                        </form>
                    @endif
                </div>
            </li>
        @empty
            <li class="text-stone-600">{{ __('You are not in a household yet.') }}</li>
        @endforelse
    </ul>

    <section class="mt-10">
        <h2 class="mb-3 font-semibold">{{ __('New household') }}</h2>
        <form method="POST" action="{{ route('households.store') }}" class="flex flex-col gap-3">
            @csrf
            <input name="name" required maxlength="100" placeholder="{{ __('e.g. Family Muster') }}" aria-label="{{ __('Name') }}" class="{{ $input }}">
            <x-button>{{ __('Create household') }}</x-button>
        </form>
    </section>
</x-layout>
