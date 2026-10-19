<?php

namespace App\Services\Calendaring\Channels;

use App\Exceptions\ChannelUnconfiguredException;
use App\Mail\ReminderMail;
use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements ReminderChannel
{
    public function send(ReminderDelivery $delivery): string
    {
        // 009-D08: gated off by default. Throw BEFORE any send so the
        // ledger can record failed_unconfigured — loud, never fake-sent.
        if (! (bool) config('calendaring.email_enabled', false)) {
            throw new ChannelUnconfiguredException('Email channel is not configured (calendaring.email_enabled is false).');
        }

        $recipient = User::query()->whereKey($delivery->recipient_user_id)->firstOrFail();

        $email = $delivery->recipient_email ?? $recipient->email;
        if ($delivery->recipient_email === null) {
            $delivery->forceFill(['recipient_email' => $email])->save();
        }

        Mail::to($email)->send(new ReminderMail($delivery));

        return 'reminder-mail-'.(string) $delivery->getKey();
    }
}
