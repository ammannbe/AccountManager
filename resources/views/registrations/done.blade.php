@php use App\Enums\AccountStatus; @endphp
<x-layout :title="__('Registration complete')">
    <h1 class="mb-4 text-xl font-semibold">{{ __('Registration complete') }}</h1>

    @if (! $result->automatic)
        <p class="text-stone-600">{{ __('Thank you. An admin will set up your login and let you know.') }}</p>
    @elseif ($result->loginCodeSent)
        <p class="mb-4 text-stone-600">{{ __('You will receive an email with a login code. Use it to log in once and set up a passkey; from then on you log in with the passkey.') }}</p>
    @else
        <p class="mb-4 text-stone-600">{{ __('You already have an account: log in as usual.') }}</p>
    @endif

    @if ($result->account->status === AccountStatus::Requested)
        <p class="mt-4 text-stone-600">{{ __('Your access to :service needs an approval. You will get an email as soon as it is granted.', ['service' => $service->name]) }}</p>
    @elseif ($service->url && $result->automatic)
        <div class="mt-6"><x-button :href="$service->url">{{ __('Open :service', ['service' => $service->name]) }}</x-button></div>
    @endif
</x-layout>
