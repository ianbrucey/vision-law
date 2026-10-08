<?php

namespace Tests\Helpers;

/**
 * Spec 008: a deterministic software WebAuthn authenticator for tests.
 *
 * Generates one EC P-256 keypair (openssl) and speaks just enough of the
 * WebAuthn ceremony protocol to drive Laragear's real validators:
 * attestation with format "none" (008-D04) and ECDSA assertions with a
 * strictly increasing signature counter. User-verification and
 * user-presence flags are always set (008-D07).
 *
 * Hermetic by construction (the 007 CI lesson): no device, no browser, no
 * daemon — ceremonies are computed in-process against the test RP ID and
 * origin pinned in phpunit.xml (008-D03).
 */
class VirtualAuthenticator
{
    private string $privateKeyPem;

    private string $publicKeyX;

    private string $publicKeyY;

    private string $credentialId;

    private int $counter = 0;

    public function __construct(
        private readonly string $rpId = 'localhost',
        private readonly string $origin = 'http://localhost',
    ) {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        assert($key !== false);
        openssl_pkey_export($key, $privateKeyPem);
        assert(is_string($privateKeyPem));
        $this->privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($key);
        assert($details !== false);

        $this->publicKeyX = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->publicKeyY = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->credentialId = random_bytes(32);
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * The credential id as the server stores it (base64url) — the row key
     * of webauthn_credentials.
     */
    public function credentialId(): string
    {
        return self::base64UrlEncode($this->credentialId);
    }

    /**
     * Build the attestation (registration) response for the given options
     * JSON (Laragear's flat shape: challenge is a base64url string).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> POST body for passkeys.register
     */
    public function attest(array $options, ?string $originOverride = null): array
    {
        $clientData = $this->clientData('webauthn.create', (string) $options['challenge'], $originOverride);

        // authData: rpIdHash || flags (UP|UV|AT) || signCount(0) ||
        // aaguid(16 zero bytes) || credIdLen || credId || COSE public key.
        $authData = hash('sha256', $this->rpId, true)
            .chr(0x01 | 0x04 | 0x40)
            .pack('N', 0)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->cosePublicKey();

        $attestationObject = self::cborMap([
            [self::cborText('fmt'), self::cborText('none')],
            [self::cborText('attStmt'), self::cborMap([])],
            [self::cborText('authData'), self::cborBytes($authData)],
        ]);

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientData),
                'attestationObject' => self::base64UrlEncode($attestationObject),
            ],
        ];
    }

    /**
     * Build the assertion (login) response for the given options JSON.
     * Each call increments the signature counter, as a real authenticator
     * does. $tamper flips one signature byte (invalid-proof case);
     * $originOverride forges the clientData origin (C-08).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> POST body for two-factor.passkey.store
     */
    public function assert(array $options, ?string $originOverride = null, bool $tamper = false): array
    {
        $this->counter++;

        $clientData = $this->clientData('webauthn.get', (string) $options['challenge'], $originOverride);

        // authData: rpIdHash || flags (UP|UV) || signCount.
        $authData = hash('sha256', $this->rpId, true)
            .chr(0x01 | 0x04)
            .pack('N', $this->counter);

        $signature = '';
        openssl_sign(
            $authData.hash('sha256', $clientData, true),
            $signature,
            $this->privateKeyPem,
            OPENSSL_ALGO_SHA256
        );
        assert(is_string($signature) && $signature !== '');

        if ($tamper) {
            $signature[10] = $signature[10] === "\0" ? "\1" : "\0";
        }

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientData),
                'authenticatorData' => self::base64UrlEncode($authData),
                'signature' => self::base64UrlEncode($signature),
                'userHandle' => null,
            ],
        ];
    }

    private function clientData(string $type, string $challenge, ?string $originOverride): string
    {
        return (string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $originOverride ?? $this->origin,
            'crossOrigin' => false,
        ]);
    }

    /**
     * COSE_Key for ES256: {1: EC2, 3: ES256, -1: P-256, -2: x, -3: y}.
     */
    private function cosePublicKey(): string
    {
        return self::cborMap([
            [self::cborInt(1), self::cborInt(2)],
            [self::cborInt(3), self::cborInt(-7)],
            [self::cborInt(-1), self::cborInt(1)],
            [self::cborInt(-2), self::cborBytes($this->publicKeyX)],
            [self::cborInt(-3), self::cborBytes($this->publicKeyY)],
        ]);
    }

    // ── Minimal CBOR encoder (only the shapes above) ──────────────────

    private static function cborHead(int $major, int $length): string
    {
        $type = $major << 5;

        return match (true) {
            $length < 24 => chr($type | $length),
            $length < 256 => chr($type | 24).chr($length),
            $length < 65536 => chr($type | 25).pack('n', $length),
            default => chr($type | 26).pack('N', $length),
        };
    }

    private static function cborInt(int $value): string
    {
        return $value >= 0
            ? self::cborHead(0, $value)
            : self::cborHead(1, -1 - $value);
    }

    private static function cborBytes(string $bytes): string
    {
        return self::cborHead(2, strlen($bytes)).$bytes;
    }

    private static function cborText(string $text): string
    {
        return self::cborHead(3, strlen($text)).$text;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  pre-encoded key/value pairs
     */
    private static function cborMap(array $pairs): string
    {
        $out = self::cborHead(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $out .= $key.$value;
        }

        return $out;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
