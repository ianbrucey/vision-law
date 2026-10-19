<?php

namespace App\Services\Calendaring;

use App\Exceptions\ChannelUnconfiguredException;
use App\Models\ReminderDelivery;
use App\Models\ReminderPolicy;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\Calendaring\Channels\ReminderChannel;
use Illuminate\Support\Carbon;

/**
 * Delivery pipeline (009-D16, T-07; behaviors T-08).
 *
 * deliver() claims a pending ledger row atomically, re-resolves the
 * recipient and re-checks matter authorization at send time, applies
 * the recipient's digest / quiet-hours preferences for non-mandatory
 * traffic, then dispatches to the bound channel. The retry state
 * machine lives here: failures requeue with the backoff schedule
 * [60, 300, 900, 3600, 14400] seconds, and the 5th failed attempt is
 * dead. (T-09 adds the admin ops view + replay on top of this
 * machine; replay is a status reset to pending.)
 *
 * Criticality resolution (T-08): the payload snapshot written by
 * ReminderPlanner at materialization already carries `criticality`
 * (and `rule_index`); that value wins. Only when the payload lacks
 * it do we fall back to resolving policy rules[rule_index].
 */
class DeliveryService
{
    /** @var list<int> */
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 14400];

    public const MAX_ATTEMPTS = 5;

    public function resolveCriticality(ReminderDelivery $delivery): string
    {
        $payload = $delivery->payload;
        $criticality = $payload['criticality'] ?? null;
        if (is_string($criticality) && $criticality !== '') {
            return $criticality;
        }

        $ruleIndex = $payload['rule_index'] ?? null;
        if ($delivery->policy_id !== null && is_int($ruleIndex)) {
            $policy = ReminderPolicy::query()->whereKey($delivery->policy_id)->first();
            if ($policy !== null) {
                $rules = $policy->rules;
                $rule = $rules[$ruleIndex] ?? null;
                if (is_array($rule)) {
                    $fromRule = $rule['criticality'] ?? null;
                    if (is_string($fromRule) && $fromRule !== '') {
                        return $fromRule;
                    }
                }
            }
        }

        return 'standard';
    }

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

        // (2b) Recipient preferences at send time (009-D17/D06).
        // Quiet hours and digest govern the recipient, not the
        // channel: they apply to in_app and email alike. Mandatory
        // traffic bypasses both, always — no configuration may
        // change that floor.
        $criticality = $this->resolveCriticality($delivery);
        if ($criticality !== 'mandatory' && ! in_array($delivery->kind, ['digest', 'escalation'], true)) {
            $pref = ReminderPreference::query()->where('user_id', $recipient->getKey())->first();

            if ($pref !== null) {
                if ($pref->digest_enabled && $delivery->kind === 'reminder') {
                    return $this->holdForDigest($delivery, $pref);
                }

                $quietEnd = $this->quietWindowEnd($pref);
                if ($quietEnd !== null) {
                    $delivery->forceFill([
                        'status' => 'pending',
                        'scheduled_for' => $quietEnd,
                        'next_attempt_at' => null,
                    ])->save();

                    return $delivery->fresh() ?? $delivery;
                }
            }
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

    /**
     * If now (in the preference timezone) is inside the quiet window,
     * return the window's end as a UTC instant; otherwise null. The
     * window may wrap midnight (e.g. 21:00–07:00).
     */
    private function quietWindowEnd(ReminderPreference $pref): ?Carbon
    {
        if ($pref->quiet_start === null || $pref->quiet_end === null) {
            return null;
        }

        $start = substr((string) $pref->quiet_start, 0, 5);
        $end = substr((string) $pref->quiet_end, 0, 5);
        if ($start === $end) {
            return null;
        }

        $tz = $pref->timezone !== '' ? $pref->timezone : 'UTC';
        $nowLocal = Carbon::now($tz);
        $time = $nowLocal->format('H:i');

        $wraps = $start > $end;
        $inside = $wraps
            ? ($time >= $start || $time < $end)
            : ($time >= $start && $time < $end);

        if (! $inside) {
            return null;
        }

        $endLocal = Carbon::parse($nowLocal->toDateString().' '.$end, $tz);
        if ($wraps && $time >= $start) {
            $endLocal->addDay();
        }

        return $endLocal->copy()->setTimezone('UTC');
    }

    /**
     * The recipient's next digest instant in their timezone: today
     * at digest_time when that is still ahead, otherwise tomorrow.
     */
    private function nextDigestAt(ReminderPreference $pref): Carbon
    {
        $tz = $pref->timezone !== '' ? $pref->timezone : 'UTC';
        $time = $pref->digest_time !== null ? substr((string) $pref->digest_time, 0, 5) : '08:00';

        $candidate = Carbon::parse(Carbon::now($tz)->toDateString().' '.$time, $tz);
        if ($candidate->lessThanOrEqualTo(Carbon::now($tz))) {
            $candidate->addDay();
        }

        return $candidate->copy()->setTimezone('UTC');
    }

    /**
     * Hold a non-mandatory reminder for the digest: this row is
     * superseded with a payload note, and its items are appended to
     * the recipient's ONE pending digest delivery.
     */
    private function holdForDigest(ReminderDelivery $delivery, ReminderPreference $pref): ReminderDelivery
    {
        $digestAt = $this->nextDigestAt($pref);

        $payload = $delivery->payload;
        $entry = [
            'item_id' => (string) $delivery->item_id,
            'item_title' => isset($payload['item_title']) && is_string($payload['item_title']) ? $payload['item_title'] : null,
            'item_instant' => isset($payload['item_instant']) && is_string($payload['item_instant']) ? $payload['item_instant'] : null,
            'scheduled_for' => $delivery->scheduled_for->toIso8601String(),
        ];

        $payload['digested_into'] = 'pending digest';
        $payload['digest_scheduled_for'] = $digestAt->toIso8601String();
        $delivery->forceFill([
            'status' => 'superseded',
            'payload' => $payload,
        ])->save();

        $existing = ReminderDelivery::query()
            ->where('recipient_user_id', $delivery->recipient_user_id)
            ->where('kind', 'digest')
            ->where('channel', 'in_app')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->first();

        if ($existing !== null) {
            $existingPayload = $existing->payload;
            /** @var list<array<string, mixed>> $items */
            $items = isset($existingPayload['digest_items']) && is_array($existingPayload['digest_items'])
                ? array_values($existingPayload['digest_items'])
                : [];
            $already = false;
            foreach ($items as $existingEntry) {
                if (($existingEntry['item_id'] ?? null) === $entry['item_id']) {
                    $already = true;
                    break;
                }
            }
            if (! $already) {
                $items[] = $entry;
            }
            $existingPayload['digest_items'] = $items;
            $titles = [];
            foreach ($items as $entryRow) {
                $title = $entryRow['item_title'] ?? null;
                if (is_string($title)) {
                    $titles[] = $title;
                }
            }
            $existingPayload['item_titles'] = $titles;
            $existing->forceFill(['payload' => $existingPayload])->save();

            return $delivery->fresh() ?? $delivery;
        }

        ReminderDelivery::create([
            'org_id' => (string) $delivery->org_id,
            'matter_id' => (string) $delivery->matter_id,
            'item_id' => (string) $delivery->item_id,
            'policy_id' => null,
            'kind' => 'digest',
            'recipient_user_id' => (string) $delivery->recipient_user_id,
            'recipient_email' => $delivery->recipient_email,
            'channel' => 'in_app',
            'idempotency_key' => hash('sha256', implode('|', [
                'digest',
                (string) $delivery->recipient_user_id,
                $digestAt->toIso8601String(),
            ])),
            'scheduled_for' => $digestAt,
            'status' => 'pending',
            'attempts' => 0,
            'payload' => [
                'item_title' => $entry['item_title'],
                'matter_title' => $payload['matter_title'] ?? null,
                'criticality' => 'standard',
                'rule_label' => 'Digest',
                'digest_items' => [$entry],
                'item_titles' => $entry['item_title'] !== null ? [$entry['item_title']] : [],
                'item_instant' => $entry['item_instant'],
            ],
        ]);

        return $delivery->fresh() ?? $delivery;
    }
}
