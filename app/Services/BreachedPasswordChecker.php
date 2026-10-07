<?php

namespace App\Services;

use App\Exceptions\BreachedPasswordCheckUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Have-I-Been-Pwned k-anonymity breached-password check (C-02).
 *
 * Only the first 5 characters of the SHA-1 hash ever leave the server. FAILS
 * CLOSED per 001-D07: any transport or API failure throws
 * BreachedPasswordCheckUnavailableException and the password attempt is
 * rejected with a retryable error — never let through silently.
 */
class BreachedPasswordChecker
{
    private const API_BASE = 'https://api.pwnedpasswords.com/range/';

    public function isBreached(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::withHeaders(['User-Agent' => 'VisionLaw/1.0'])
                ->timeout(5)
                ->get(self::API_BASE.$prefix);
        } catch (ConnectionException $e) {
            throw new BreachedPasswordCheckUnavailableException(
                'Breached-password check is unreachable.', 0, $e
            );
        }

        if (! $response->successful()) {
            throw new BreachedPasswordCheckUnavailableException(
                'Breached-password check returned HTTP '.$response->status().'.'
            );
        }

        foreach (explode("\n", $response->body()) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$hashSuffix, $count] = array_pad(explode(':', $line, 2), 2, '0');

            if (strcasecmp(trim($hashSuffix), $suffix) === 0 && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
