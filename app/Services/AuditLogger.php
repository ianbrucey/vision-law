<?php

namespace App\Services;

use App\Exceptions\InvalidPayloadException;
use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    /**
     * Privileged-key blocklist (03-contract.md): payload keys matching any of
     * these substrings — case-insensitive, at any nesting depth — are rejected
     * so privileged data never reaches the audit table.
     *
     * @var list<string>
     */
    private const PRIVILEGED_KEY_PATTERNS = ['password', 'secret', 'token', 'hash', 'recovery'];

    /**
     * The SOLE writer of audit_events (a future architecture test enforces
     * this). Validates the payload against the privileged-key blocklist, then
     * inserts inside a transaction under a per-org Postgres advisory lock so
     * concurrent writers cannot fork the hash chain.
     *
     * prev_hash is the previous row_hash for the org, or 64 zeros for the
     * genesis row. row_hash = sha256 over a canonical encoding of
     * (prev_hash + event + payload), bound to actor and timestamp per
     * 02-schema-delta.md.
     *
     * @param  array<mixed>  $payload
     *
     * @throws InvalidPayloadException when the payload contains privileged keys
     * @throws \InvalidArgumentException when neither actor nor matter resolves a tenant
     */
    public static function log(string $event, ?User $actor, array $payload, ?Matter $matter = null): AuditEvent
    {
        self::assertNoPrivilegedKeys($payload);

        // ?? already treats a null $actor as "not set" — no nullsafe needed.
        $orgId = $actor->org_id ?? $matter?->org_id;

        if ($orgId === null) {
            throw new \InvalidArgumentException(
                'AuditLogger::log() requires an actor or a matter to resolve the tenant org_id.'
            );
        }

        return DB::transaction(function () use ($event, $actor, $payload, $matter, $orgId): AuditEvent {
            // Serialize per-org writers: the lock is held until the
            // transaction commits, so no two writers can compute the same
            // prev_hash.
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$orgId]);

            $prevHash = AuditEvent::query()
                ->where('org_id', $orgId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('row_hash');

            $prevHash = is_string($prevHash) ? $prevHash : str_repeat('0', 64);

            $createdAt = now();
            $actorId = $actor?->getKey();

            $rowHash = hash('sha256', implode('|', [
                $prevHash,
                $event,
                (string) ($actorId ?? ''),
                $createdAt->toIso8601String(),
                self::canonicalJson($payload),
            ]));

            $auditEvent = new AuditEvent([
                'org_id' => $orgId,
                'actor_id' => $actorId,
                'event' => $event,
                'matter_id' => $matter?->getKey(),
                'ip' => $payload['ip'] ?? null,
                'user_agent' => $payload['user_agent'] ?? null,
                'payload' => $payload,
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
                'created_at' => $createdAt,
            ]);
            $auditEvent->save();

            return $auditEvent;
        });
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidPayloadException
     */
    private static function assertNoPrivilegedKeys(array $payload, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (self::PRIVILEGED_KEY_PATTERNS as $pattern) {
                    if (str_contains($lower, $pattern)) {
                        // The offending VALUE is never included: it may itself
                        // be privileged (a secret, a token, a hash).
                        throw new InvalidPayloadException(
                            "Audit payload rejected: privileged key '{$keyPath}' must never be written to the audit log."
                        );
                    }
                }
            }

            if (is_array($value)) {
                self::assertNoPrivilegedKeys($value, $keyPath);
            }
        }
    }

    /**
     * Canonical JSON encoding: keys sorted recursively, no whitespace, no
     * escaping — identical payloads always hash identically.
     *
     * @param  array<mixed>  $payload
     *
     * @throws \JsonException
     */
    private static function canonicalJson(array $payload): string
    {
        return json_encode(
            self::sortKeysRecursive($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sortKeysRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursive($value);
            }
        }

        if (array_is_list($data)) {
            return $data;
        }

        ksort($data);

        return $data;
    }
}
