{{--
    auth/confirm-password.blade.php — password confirmation prompt (spec 005 T-03).

    Surfaces Fortify's existing confirmPassword behavior — no new auth logic.
    Fortify's ConfirmablePasswordController@store does the verification and sets
    auth.password_confirmed_at; this view is only the form Fortify redirects to
    when a password.confirm-gated action (2FA disable/regenerate, session
    revocation, audit export, …) needs a fresh password proof. On success
    Fortify's PasswordConfirmedResponse sends the user back to the page that
    triggered the gate (url.intended).

    A wrong password returns to this page with the error in the default bag,
    which x-ui.field renders under the field (no enumeration beyond the
    single generic message).
--}}
<x-layouts.app title="Confirm your password · Vision Law">
    <x-ui.page-header
        title="Confirm your password"
        subtitle="This is a sensitive action. Re-enter your password to continue."
    />

    <x-ui.card class="max-w-[480px] mx-auto">
        <form method="POST" action="{{ route('password.confirm.store') }}">
            @csrf

            <x-ui.field
                name="password"
                label="Password"
                type="password"
                required
                value=""
                autocomplete="current-password"
                placeholder="••••••••••"
                help="Your current Vision Law password."
            />

            <x-ui.button variant="primary" type="submit" class="w-full">
                Confirm
            </x-ui.button>
        </form>

        <p class="text-[13px] text-vl-mut mt-3.5">
            For your security, sensitive actions ask for your password again —
            even though you're already signed in.
        </p>
    </x-ui.card>
</x-layouts.app>
