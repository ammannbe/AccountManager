@php use App\Enums\AccountStatus; @endphp
<x-layout :title="__('My services')">
    <h1 class="mb-1 text-xl font-semibold">{{ __('My services') }}</h1>
    <p class="mb-6 text-sm text-stone-600">{{ __('Logged in as :email', ['email' => auth()->user()->email]) }}</p>

    @if ($services->isEmpty())
        <p class="py-12 text-center text-stone-500">{{ __('There are no services yet.') }}</p>
    @else
        <ul class="rows">
            @foreach ($services as $service)
                @php $account = $accounts->get($service->id); @endphp
                <li class="p-4">
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="font-semibold">{{ $service->name }}</h2>
                            @if ($service->description)
                                <p class="mt-0.5 text-sm text-stone-600">{{ $service->description }}</p>
                            @endif
                        </div>
                        @if ($account)
                            <span @class([
                                'badge',
                                'bg-green-100 text-green-800' => $account->status === AccountStatus::Active,
                                'bg-amber-100 text-amber-900' => $account->status === AccountStatus::Requested,
                                'bg-red-100 text-red-800' => $account->status === AccountStatus::Suspended,
                            ])>{{ $account->status->getLabel() }}</span>
                        @endif
                    </div>

                    @if ($account?->status === AccountStatus::Active)
                        @if ($service->openUrl())
                            <x-button :href="$service->openUrl()" secondary>{{ __('Open :service', ['service' => $service->name]) }}</x-button>
                        @endif
                        @if ($account->external_username)
                            <p class="mt-2 text-sm text-stone-600">{{ __('Username: :name', ['name' => $account->external_username]) }}</p>
                        @endif
                        @php $unlocked = $entitlements[$service->id] ?? null; @endphp
                        @if ($unlocked && ($unlocked['subscriptions']->isNotEmpty() || $unlocked['features']->isNotEmpty()))
                            <dl class="mt-3 space-y-1 text-sm">
                                @if ($unlocked['subscriptions']->isNotEmpty())
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="text-stone-500">{{ __('Plans') }}:</dt>
                                        <dd>
                                            @foreach ($unlocked['subscriptions'] as $subscription)
                                                {{ $subscription->plan->name }}@if ($subscription->ends_at) <span class="text-stone-500">({{ __('until :date', ['date' => $subscription->ends_at->isoFormat('LL')]) }})</span>@endif{{ $loop->last ? '' : ',' }}
                                            @endforeach
                                        </dd>
                                    </div>
                                @endif
                                @if ($unlocked['features']->isNotEmpty())
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="text-stone-500">{{ __('Unlocked') }}:</dt>
                                        <dd>{{ $unlocked['features']->pluck('name')->join(', ') }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif
                    @elseif ($account?->status === AccountStatus::Requested)
                        <p class="text-sm text-stone-600">{{ __('Your request is waiting for an approval.') }}</p>
                    @elseif ($account?->status === AccountStatus::Suspended)
                        <p class="text-sm text-stone-600">{{ __('Your access is suspended.') }}</p>
                    @else
                        <form method="POST" action="{{ route('services.claim', $service) }}">
                            @csrf
                            <x-button>
                                {{ $service->self_signup && ! $service->requires_approval ? __('Activate access') : __('Request access') }}
                            </x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-6 text-sm text-stone-500">{{ __('Already have an account in one of the services? Open it here and log in: your account there is linked through your email address.') }}</p>
</x-layout>
