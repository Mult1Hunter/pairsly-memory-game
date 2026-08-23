<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signed, server-issued tokens - the core of the anti-cheat design.
 *
 *  - "session" tokens: issued once the visitor has passed bot protection
 *    (or immediately, when protection is off). Lets a visitor start any
 *    number of games without re-solving a challenge every time.
 *  - "run" tokens: issued per game start, embed tier + pair count + a
 *    server-side timestamp. Finishing a run derives elapsed time from that
 *    timestamp and the server clock - never from anything the client
 *    reports - and each run token can be redeemed exactly once.
 *
 * Tokens are HMAC-signed opaque strings, not stored server-side except for
 * the single-use marker (a short-lived transient).
 */
class PairsMG_Token {

    const SECRET_OPTION    = 'pairsmg_hmac_secret';
    const IP_SECRET_OPTION = 'pairsmg_ip_secret';

    /**
     * A random, plugin-owned secret persisted in the options table. The
     * plugin deliberately does not touch WordPress's AUTH/NONCE keys and
     * salts (wp_salt() and friends): those exist only to sign login cookies
     * and must not be reused as keys for anything else.
     */
    private static function stored_secret($option) {
        $secret = get_option($option);
        if (!$secret) {
            $secret = wp_generate_password(64, true, true);
            update_option($option, $secret, false);
        }
        return $secret;
    }

    /** Key for signing session/run tokens. */
    private static function secret() {
        return self::stored_secret(self::SECRET_OPTION);
    }

    /** Separate key for hashing visitor IPs (rate limiting, ip_hash column). */
    public static function ip_secret() {
        return self::stored_secret(self::IP_SECRET_OPTION);
    }

    private static function sign($payload) {
        $json = wp_json_encode($payload);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, self::secret());
        return $b64 . '.' . $sig;
    }

    public static function issue_session($ttl = null) {
        $ttl = $ttl !== null ? $ttl : (2 * HOUR_IN_SECONDS);
        return self::sign(array(
            'type' => 'session',
            'exp'  => time() + $ttl,
            'n'    => wp_generate_password(12, false),
        ));
    }

    public static function issue_run($tier, $pairs, $ttl = null) {
        $ttl = $ttl !== null ? $ttl : (30 * MINUTE_IN_SECONDS);
        return self::sign(array(
            'type'  => 'run',
            'tier'  => (string) $tier,
            'pairs' => (int) $pairs,
            'iat'   => time(),
            'exp'   => time() + $ttl,
            'n'     => wp_generate_password(16, false),
        ));
    }

    /**
     * @return array|WP_Error Decoded payload on success.
     */
    public static function verify($token, $expected_type) {
        if (!is_string($token) || strpos($token, '.') === false) {
            return new WP_Error('pairsmg_bad_token', __('Invalid token.', 'pairsly-memory-game'));
        }
        list($b64, $sig) = explode('.', $token, 2);
        $expected_sig = hash_hmac('sha256', $b64, self::secret());
        if (!hash_equals($expected_sig, (string) $sig)) {
            return new WP_Error('pairsmg_bad_signature', __('Invalid token signature.', 'pairsly-memory-game'));
        }
        $b64u = strtr($b64, '-_', '+/');
        $remainder = strlen($b64u) % 4;
        if ($remainder) {
            $b64u .= str_repeat('=', 4 - $remainder);
        }
        $payload = json_decode(base64_decode($b64u), true);
        if (!is_array($payload) || !isset($payload['type']) || $payload['type'] !== $expected_type) {
            return new WP_Error('pairsmg_bad_payload', __('Invalid token payload.', 'pairsly-memory-game'));
        }
        if (!isset($payload['exp']) || time() > (int) $payload['exp']) {
            return new WP_Error('pairsmg_token_expired', __('Token has expired.', 'pairsly-memory-game'));
        }
        return $payload;
    }

    /**
     * Marks a run token's nonce as spent. Returns false if it was already
     * used (replay attempt) - callers must reject the request in that case.
     */
    public static function consume_once($nonce) {
        $key = 'pairsmg_used_' . md5((string) $nonce);
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, 1, HOUR_IN_SECONDS);
        return true;
    }
}
