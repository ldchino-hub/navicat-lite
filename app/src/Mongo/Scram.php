<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/**
 * SCRAM authentication (RFC 5802 / 7677) over the MongoDB wire protocol.
 * Supports SCRAM-SHA-1 (DocumentDB default) and SCRAM-SHA-256 (Mongo 4.0+).
 *
 * The caller provides a runCommand callable that ships a command document and
 * returns the decoded reply, so this class stays transport-agnostic.
 */
final class Scram
{
    /**
     * @param callable(array<string,mixed>):array<string,mixed> $runCommand
     */
    public static function authenticate(
        callable $runCommand,
        string $mechanism,
        string $username,
        string $password,
        string $authDb
    ): void {
        $hash = $mechanism === 'SCRAM-SHA-256' ? 'sha256' : 'sha1';
        $hashLen = $mechanism === 'SCRAM-SHA-256' ? 32 : 20;

        $clientNonce = base64_encode(random_bytes(24));
        $gs2Header = 'n,,';
        $clientFirstBare = 'n=' . self::saslPrepName($username) . ',r=' . $clientNonce;

        $reply = $runCommand([
            'saslStart' => 1,
            'mechanism' => $mechanism,
            'payload'   => new Binary($gs2Header . $clientFirstBare, 0),
            'options'   => ['skipEmptyExchange' => true],
        ]);
        self::assertOk($reply, 'saslStart');

        $convId = $reply['conversationId'];
        $serverFirst = self::payloadString($reply['payload']);
        $attrs = self::parse($serverFirst);

        $serverNonce = $attrs['r'] ?? '';
        $salt = base64_decode($attrs['s'] ?? '', true);
        $iterations = (int)($attrs['i'] ?? 0);

        if (strncmp($serverNonce, $clientNonce, strlen($clientNonce)) !== 0) {
            throw new \RuntimeException('SCRAM: server nonce does not extend client nonce');
        }
        if ($iterations < 4096) {
            throw new \RuntimeException('SCRAM: iteration count below 4096 (possible downgrade attack)');
        }

        if ($mechanism === 'SCRAM-SHA-256') {
            $saltedPassword = hash_pbkdf2('sha256', self::saslPrepPassword($password), $salt, $iterations, $hashLen, true);
        } else {
            // SCRAM-SHA-1 in Mongo hashes the password as MD5(user:mongo:pass) hex first.
            $digest = md5($username . ':mongo:' . $password);
            $saltedPassword = hash_pbkdf2('sha1', $digest, $salt, $iterations, $hashLen, true);
        }

        $clientKey = hash_hmac($hash, 'Client Key', $saltedPassword, true);
        $storedKey = hash($hash, $clientKey, true);

        $channelBinding = 'c=' . base64_encode($gs2Header);
        $clientFinalNoProof = $channelBinding . ',r=' . $serverNonce;
        $authMessage = $clientFirstBare . ',' . $serverFirst . ',' . $clientFinalNoProof;

        $clientSignature = hash_hmac($hash, $authMessage, $storedKey, true);
        $clientProof = $clientKey ^ $clientSignature;
        $clientFinal = $clientFinalNoProof . ',p=' . base64_encode($clientProof);

        $reply2 = $runCommand([
            'saslContinue'   => 1,
            'conversationId' => $convId,
            'payload'        => new Binary($clientFinal, 0),
        ]);
        self::assertOk($reply2, 'saslContinue');

        // Verify the server signature (mutual auth).
        $serverFinal = self::parse(self::payloadString($reply2['payload']));
        if (isset($serverFinal['v'])) {
            $serverKey = hash_hmac($hash, 'Server Key', $saltedPassword, true);
            $expected = base64_encode(hash_hmac($hash, $authMessage, $serverKey, true));
            if (!hash_equals($expected, $serverFinal['v'])) {
                throw new \RuntimeException('SCRAM: server signature mismatch');
            }
        }

        // Drain any remaining empty exchanges until done.
        $guard = 0;
        while (empty($reply2['done']) && $guard++ < 3) {
            $reply2 = $runCommand([
                'saslContinue'   => 1,
                'conversationId' => $convId,
                'payload'        => new Binary('', 0),
            ]);
            self::assertOk($reply2, 'saslContinue (drain)');
        }
    }

    private static function payloadString(mixed $payload): string
    {
        if ($payload instanceof Binary) {
            return $payload->data;
        }
        if (is_string($payload)) {
            return $payload;
        }
        throw new \RuntimeException('SCRAM: unexpected payload type ' . get_debug_type($payload));
    }

    /** @return array<string,string> */
    private static function parse(string $s): array
    {
        $out = [];
        foreach (explode(',', $s) as $part) {
            $eq = strpos($part, '=');
            if ($eq !== false) {
                $out[substr($part, 0, $eq)] = substr($part, $eq + 1);
            }
        }
        return $out;
    }

    private static function saslPrepName(string $u): string
    {
        // Usernames: escape '=' and ',' per RFC 5802; no SASLprep required.
        return str_replace(['=', ','], ['=3D', '=2C'], $u);
    }

    private static function saslPrepPassword(string $p): string
    {
        // Full SASLprep (RFC 4013) is rarely needed for ASCII passwords; pass through.
        return $p;
    }

    private static function assertOk(array $reply, string $ctx): void
    {
        if (($reply['ok'] ?? 0) != 1) {
            $msg = $reply['errmsg'] ?? json_encode($reply);
            throw new \RuntimeException("SCRAM {$ctx} failed: {$msg}");
        }
    }
}
