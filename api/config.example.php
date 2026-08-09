<?php
// Copy this file to api/config.php and edit the values for your server.
// api/config.php is gitignored so your creds don't get committed.

return [
    // ---- Database ----------------------------------------------------------
    // A single connection that has read access to login_accounts and
    // read/write access to account, character_data, character_inventory,
    // items, bot_data, bot_inventories, bot_spells_entries, bot_spell_settings.
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'user'     => 'eqemu',
        'password' => 'eqemupass',
        'name'     => 'peq',
        'charset'  => 'utf8mb4',
    ],

    // ---- Login server ------------------------------------------------------
    // Encryption mode used by your login server. Match the value in
    // login.json ("account:{encryption_mode}"). Botroster supports modes 1-13.
    //   1  MD5
    //   2  MD5PassUser        md5(password:username)
    //   3  MD5UserPass        md5(username:password)
    //   4  MD5Triple          md5(md5(username) . md5(password))
    //   5  SHA1
    //   6  SHAPassUser
    //   7  SHAUserPass
    //   8  SHATriple
    //   9  SHA512
    //   10 SHA512PassUser
    //   11 SHA512UserPass
    //   12 SHA512Triple
    //   13 Argon2             (requires PHP built with libargon2 support)
    'encryption_mode' => 5,

    // source_loginserver value used in login_accounts. "local" for a private
    // server, "eqemu" if you also authenticate against the public login server.
    // Leave null to auto-detect the row for the submitted account name.
    'source_loginserver' => null,

    // ---- Session -----------------------------------------------------------
    // Secret used to sign session cookies. Change this to any long random
    // string. Rotating this value logs everyone out.
    'session_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
    // Session lifetime in seconds. 30 days by default.
    'session_lifetime' => 60 * 60 * 24 * 30,
];
