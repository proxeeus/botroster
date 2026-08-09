<?php
/**
 * eqemu login-server password verification.
 *
 * Mirrors Server/loginserver/encryption.cpp `eqcrypt_verify_hash`.
 * Modes 1-12 are the plain hash variants; mode 13 (Argon2) and mode 14
 * (SCrypt) route through libsodium.
 */

require_once __DIR__ . '/bootstrap.php';

function eqcrypt_hash(string $username, string $password, int $mode): string {
    switch ($mode) {
        case 1:  return md5($password);
        case 2:  return md5($password . ':' . $username);
        case 3:  return md5($username . ':' . $password);
        case 4:  return md5(md5($username) . md5($password));

        case 5:  return sha1($password);
        case 6:  return sha1($password . ':' . $username);
        case 7:  return sha1($username . ':' . $password);
        case 8:  return sha1(sha1($username) . sha1($password));

        case 9:  return hash('sha512', $password);
        case 10: return hash('sha512', $password . ':' . $username);
        case 11: return hash('sha512', $username . ':' . $password);
        case 12: return hash('sha512', hash('sha512', $username) . hash('sha512', $password));
    }
    return '';
}

function eqcrypt_verify(string $username, string $password, string $stored_hash, int $mode): bool {
    if ($mode >= 1 && $mode <= 12) {
        $computed = eqcrypt_hash($username, $password, $mode);
        return $computed !== '' && hash_equals($stored_hash, $computed);
    }
    if ($mode === 13) {
        // Argon2 -- sodium's PHC-string verifier handles both argon2i/id.
        if (function_exists('sodium_crypto_pwhash_str_verify')) {
            return sodium_crypto_pwhash_str_verify($stored_hash, $password);
        }
        return function_exists('password_verify') && password_verify($password, $stored_hash);
    }
    if ($mode === 14) {
        // SCrypt (libsodium) -- requires the sodium extension.
        if (function_exists('sodium_crypto_pwhash_scryptsalsa208sha256_str_verify')) {
            return sodium_crypto_pwhash_scryptsalsa208sha256_str_verify($stored_hash, $password);
        }
        return false;
    }
    return false;
}

/**
 * Look up a login_accounts row for (name, [source_loginserver]) and verify
 * the password. Returns ['ls_account_id' => int, 'account_id' => int] on
 * success or null on failure. `account_id` is the zone-server account row
 * linked via lsaccount_id.
 */
function auth_login(string $account_name, string $password): ?array {
    $source = cfg('source_loginserver'); // may be null
    $mode   = (int) cfg('encryption_mode');

    if ($source !== null) {
        $stmt = db()->prepare(
            'SELECT id, account_password FROM login_accounts
             WHERE source_loginserver = :src AND account_name = :name LIMIT 1'
        );
        $stmt->execute([':src' => $source, ':name' => $account_name]);
    } else {
        $stmt = db()->prepare(
            'SELECT id, account_password FROM login_accounts
             WHERE account_name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $account_name]);
    }
    $row = $stmt->fetch();
    if (!$row) return null;

    if (!eqcrypt_verify($account_name, $password, (string) $row['account_password'], $mode)) {
        return null;
    }
    $ls_id = (int) $row['id'];

    // Find the zone-server account row. Prefer lsaccount_id match; fall back
    // to name match for older installs that don't set lsaccount_id.
    $stmt = db()->prepare(
        'SELECT id FROM account WHERE lsaccount_id = :ls LIMIT 1'
    );
    $stmt->execute([':ls' => $ls_id]);
    $acct = $stmt->fetch();
    if (!$acct) {
        $stmt = db()->prepare('SELECT id FROM account WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $account_name]);
        $acct = $stmt->fetch();
    }
    if (!$acct) return null;

    return [
        'ls_account_id' => $ls_id,
        'account_id'    => (int) $acct['id'],
    ];
}
