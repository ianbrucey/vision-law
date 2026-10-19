<?php

namespace App\Services\Calendaring;

use App\Exceptions\ChannelUnconfiguredException;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\Calendaring\Channels\ReminderChannel;

/**
 * Delivery pipeline (009-D16, T-07).
 *
 * deliver() claims a pending ledger row atomically, re-resolves the
 * recipient and re-checks matter authorization at send time, then
 * dispatches to the bound channel. The retry state machine lives
 * here: failures requeue with the backoff schedule [60, 300, 900,
 * 3600, 14400] seconds, and the 5th failed attempt is dead. (T-09
 * adds the admin ops view + replay on top of this machine; replay
 * is a status reset to pending, the machine itself is complete.)
 */
class DeliveryService
{
    /** @var list<int> */
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 14400];

    public const MAX_ATTEMPTS = 5;

    public function deliver(ReminderDelivery $delivery): ReminderDelivery
    {
        // (1) Atomic claim: pending -> claimed, bail if 0 rows moved.
        $claimed = ReminderDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'claimed']);

        if ($claimed === 0) {
            return $delivery->fresh() ?? $delivery;
        }

        $delivery = $delivery->fresh() ?? $delivery;
        $attempt = (int) $delivery->attempts + 1;

        // (2) Re-resolve the recipient at send time.
        $recipient = User::query()->whereKey($delivery->recipient_user_id)->first();
        $matter = $delivery->matter;

        if ($recipient === null
            || $recipient->deactivated_at !== null
            || $matter === null
            || AccessControl::effectiveMatterRole($recipient, $matter) === null
        ) {
            $delivery->forceFill(['status' => 'suppressed_no_access'])->save();

            return $delivery;
        }

        // (3) Dispatch to the bound channel.
        try {
            $channel = app('calendaring.channel.'.$delivery->channel);
            if (! $channel instanceof ReminderChannel) {
                throw new \RuntimeException("No reminder channel bound for {$delivery->channel}.");
            }

            $providerRef = $channel->send($delivery);

            $delivery->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'provider_ref' => $providerRef,
                'attempts' => $attempt,
                'last_error' => null,
                'next_attempt_at' => null,
                // Snapshot the resolved address at send time.
                'recipient_email' => $delivery->recipient_email ?? $recipient->email,
            ])->save();
        } catch (ChannelUnconfiguredException $e) {
            $delivery->forceFill([
                'status' => 'failed_unconfigured',
                'attempts' => $attempt,
                'last_error' => $e->getMessage(),
                'next_attempt_at' => null,
            ])->save();
        } catch (\Throwable $e) {
            if ($attempt >= self::MAX_ATTEMPTS) {
                $delivery->forceFill([
                    'status' => 'dead',
                    'attempts' => $attempt,
                    'last_error' => $e->getMessage(),
                    'next_attempt_at' => null,
                ])->save();
            } else {
                $delay = self::BACKOFF_SECONDS[$attempt - 1] ?? 14400;
                $delivery->forceFill([
                    'status' => 'pending',
                    'attempts' => $attempt,
                    'last_error' => $e->getMessage(),
                    'next_attempt_at' => now()->addSeconds((int) $delay),
                ])->save();
            }
        }

        return $delivery->fresh() ?? $delivery;
    }
}
