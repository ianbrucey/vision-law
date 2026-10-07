{{--
    auth/register.blade.php — Create account (spec 003, ticket T-04).

    Pure guest view: no auth session data is rendered here (C-04).

    003-O1 resolution (recorded in specs/003-public-site-auth/decisions.md):
    the mockup's "Organization name" field and "creates your organization's
    workspace / first administrator" copy were DROPPED — the 001 backend
    (CreateNewUser) never creates an org; it joins the invitation org or the
    oldest open-registration org with the least-privileged role. The subcopy
    below reflects that backend reality.

    Backend contract (001 CreateNewUser): accepts name, email, password,
    invitation_token (optional). The form posts to the existing Fortify
    endpoint named `register.store` (POST /register); routes are T-05's.
--}}
<x-layouts.public title="Create account · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        <x-ui.card class="max-w-[420px] mx-auto">
            <div class="flex items-center justify-center w-16 h-16 rounded-2xl bg-vl-dark-0 mx-auto mb-[22px] overflow-hidden shadow-[0_0_24px_rgba(53,162,255,0.25)]">
                <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="w-[52px] h-auto block">
            </div>

            <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Create your account</h2>
            <p class="text-center text-vl-mut text-sm mb-7">
                Your account joins your organization&rsquo;s workspace &mdash;
                through your invitation or open registration.
            </p>

            <form method="POST" action="{{ route('register.store') }}">
                @csrf

                @if (request()->query('invitation_token'))
                    <input type="hidden" name="invitation_token" value="{{ request()->query('invitation_token') }}">
                @endif

                <x-ui.field name="name" label="Full name" required autocomplete="name" placeholder="Jordan Ellis" />
                <x-ui.field name="email" label="Email" type="email" required autocomplete="email" placeholder="you@firm.com" />
                <x-ui.field
                    name="password"
                    label="Password"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                    help="Minimum 12 characters. You'll be asked to verify your email."
                />
                <x-ui.field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" />

                <x-ui.button variant="primary" type="submit" class="w-full mt-2">Create account</x-ui.button>
            </form>

            <p class="text-center text-vl-mut text-[12.5px] leading-relaxed mt-5">
                By creating an account you agree to the
                <a href="#" class="text-vl-info no-underline">Terms of Service</a>
                and
                <a href="#" class="text-vl-info no-underline">Privacy Policy</a>.
            </p>

            <p class="text-center text-vl-mut text-sm mt-[22px]">
                Already have an account?
                <a href="/login" class="text-vl-info no-underline font-semibold">Sign in</a>
            </p>
        </x-ui.card>
    </div>
</x-layouts.public>
