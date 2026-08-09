<?php
/**
 * Signed-cookie sessions -- no server-side session files, no PHP `session`.
 *
 * Cookie layout:  base64url(payload) . "." . base64url(hmac_sha256(payload))
 * Payload is a JSON blob: { "ls_account_id": int, "account_id": int, "exp": unix_ts, "character_id": int|null }
 *
 * The signature is computed with cfg('session_secret'). Rotate the secret to
 * invalidate every session on the server. The cookie is HttpOnly + SameSite=Lax.
 */

const BOTROSTER_COOKIE = 'botroster_session';

function _b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function _b64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

function session_write(array $payload): void {
    $lifetime = (int) (cfg('session_lifetime') ?? 60 * 60 * 24 * 30);
    $payload['exp'] = time() + $lifetime;
    $json = json_encode($payload);
    $body = _b64url_encode($json);
    $sig  = _b64url_encode(hash_hmac('sha256', $body, cfg('session_secret'), true));
    $cookie = $body . '.' . $sig;
    setcookie(BOTROSTER_COOKIE, $cookie, [
        'expires'  => time() + $lifetime,
        'path'     => '/botroster/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function session_destroy_cookie(): void {
    setcookie(BOTROSTER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/botroster/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Reads and validates the session cookie. Returns payload array or null. */
function session_read(): ?array {
    if (empty($_COOKIE[BOTROSTER_COOKIE])) return null;
    $raw = $_COOKIE[BOTROSTER_COOKIE];
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) return null;
    [$body, $sig] = $parts;
    $expected = _b64url_encode(hash_hmac('sha256', $body, cfg('session_secret'), true));
    if (!hash_equals($expected, $sig)) return null;
    $json = _b64url_decode($body);
    $data = json_decode($json, true);
    if (!is_array($data)) return null;
    if (!isset($data['exp']) || $data['exp'] < time()) return null;
    return $data;
}

/** Updates a single field in the session and rewrites the cookie. */
function session_set(string $key, $value): void {
    $s = session_read() ?? [];
    $s[$key] = $value;
    session_write($s);
}
