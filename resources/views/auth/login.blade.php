{{--
    auth/login.blade.php — Sign in (spec 003, ticket T-03).

    Pure guest view: no auth session data is rendered here (C-04).

    Backend contract (001 / Fortify): the form posts to the existing Fortify
    endpoint named `login.store` (POST /login). Routes are T-05's; this ticket
    creates the view only. Password errors stay generic (non-enumerating) via
    the 001 backend; the password field never repopulates from old() input.
--}}
<x-layouts.public title="Sign in · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        <x-ui.card class="max-w-[420px] mx-auto">
            <div class="flex items-center justify-center w-16 h-16 rounded-2xl bg-vl-dark-0 mx-auto mb-[22px] overflow-hidden shadow-[0_0_24px_rgba(53,162,255,0.25)]">
                <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="w-[52px] h-auto block">
            </div>

            <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Welcome back</h2>
            <p class="text-center text-vl-mut text-sm mb-7">Sign in to Vision Law</p>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <x-ui.field
                    name="email"
                    label="Email"
                    type="email"
                    required
                    autocomplete="username"
                    placeholder="you@firm.com"
                />
                <x-ui.field
                    name="password"
                    label="Password"
                    type="password"
                    required
                    autocomplete="current-password"
                    value=""
                    placeholder="••••••••••"
                />

                <div class="-mt-2 mb-4 text-right">
                    <a href="#" class="text-[13px] text-vl-info no-underline">Forgot password?</a>
                </div>

                <x-ui.button variant="primary" type="submit" class="w-full mt-2">Sign in</x-ui.button>
            </form>

            <p class="text-center text-vl-mut text-sm mt-[22px]">
                New to Vision Law?
                <a href="/register" class="text-vl-info no-underline font-semibold">Create an account</a>
            </p>
        </x-ui.card>
    </div>
</x-layouts.public>
