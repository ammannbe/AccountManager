<x-layout :title="__('Profile')">
    <h1 class="mb-6 text-xl font-semibold">{{ __('Profile') }}</h1>

    <form method="POST" action="{{ route('profile.update') }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')
        <x-input :label="__('Name')" name="name" :value="$user->name" required maxlength="100" autocomplete="name" />
        <x-input :label="__('Email')" name="email" type="email" :value="$user->email" required maxlength="255" autocomplete="email"
            :hint="__('A new address is used as soon as you confirm it by email.')" />
        <x-button>{{ __('Save') }}</x-button>
    </form>

    @if ($billing)
        <section class="mt-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Billing address') }}</h2>
            <p class="mb-4 text-sm text-stone-600">{{ __('For invoices of paid plans and the payment slip. Without an address the payer field of the slip stays empty.') }}</p>
            <form method="POST" action="{{ route('profile.billing') }}" class="flex flex-col gap-4">
                @csrf
                @method('PUT')
                <x-input :label="__('Name or company')" name="billing_name" :value="$user->billing_name" maxlength="70" autocomplete="organization"
                    :hint="__('Empty: your name (:name).', ['name' => $user->name])" />
                <x-input :label="__('Street and number')" name="billing_street" :value="$user->billing_street" maxlength="70" autocomplete="street-address" />
                <div class="grid grid-cols-[8rem_1fr] gap-3">
                    <x-input :label="__('Postal code')" name="billing_postal_code" :value="$user->billing_postal_code" maxlength="16" autocomplete="postal-code" />
                    <x-input :label="__('City')" name="billing_city" :value="$user->billing_city" maxlength="35" autocomplete="address-level2" />
                </div>
                <label class="block">
                    <span class="label">{{ __('Country') }}</span>
                    @php $country = old('billing_country', $user->billing_country ?? 'CH'); @endphp
                    <select name="billing_country" class="field w-full" autocomplete="country">
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected($country === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <x-button>{{ __('Save billing address') }}</x-button>
            </form>
        </section>
    @endif

    @if (config('identity.account_url'))
        <section class="mt-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Passkeys') }}</h2>
            <p class="mb-4 text-sm text-stone-600">{{ __('You log in with a passkey. Add one for another device or remove old ones at the login provider.') }}</p>
            <x-button :href="config('identity.account_url')" secondary>
                <x-passkey-icon />
                {{ __('Manage passkeys') }}
            </x-button>
        </section>
    @endif
</x-layout>
