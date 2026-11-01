@php use App\Enums\AccountStatus; @endphp
<x-layout :title="__('My services')">
    <h1 class="mb-1 text-xl font-semibold">{{ __('My services') }}</h1>
    <p class="mb-8 text-sm text-stone-600">{{ __('Logged in as :email', ['email' => auth()->user()->email]) }}</p>

    <ul class="flex flex-col gap-4">
        @forelse ($services as $service)
            @php $account = $accounts->get($service->id); @endphp
            <li class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="mb-3 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">{{ $service->name }}</h2>
                        @if ($service->description)
                            <p class="mt-1 text-sm text-stone-600">{{ $service->description }}</p>
                        @endif
                    </div>
                    @if ($account)
                        <span @class([
                            'shrink-0 rounded-full px-3 py-1 text-xs font-medium',
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
                @elseif ($account?->status === AccountStatus::Requested)
                    <p class="text-sm text-stone-600">{{ __('Your request is waiting for an approval.') }}</p>
                @elseif ($account?->status === AccountStatus::Suspended)
                    <p class="text-sm text-stone-600">{{ __('Your access is suspended.') }}</p>
                @else
                    <form method="POST" action="{{ route('services.claim', $service) }}">
                        @csrf
                        <x-button secondary>
                            {{ $service->self_signup && ! $service->requires_approval ? __('Activate access') : __('Request access') }}
                        </x-button>
                    </form>
                @endif
            </li>
        @empty
            <li class="text-stone-600">{{ __('There are no services yet.') }}</li>
        @endforelse
    </ul>

    <p class="mt-8 text-sm text-stone-600">{{ __('Already have an account in one of the services? Open it here and log in: your account there is linked through your email address.') }}</p>
</x-layout>
