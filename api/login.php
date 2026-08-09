<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

require_method('POST');
$body = read_json();
$name = trim((string) ($body['account'] ?? ''));
$pass = (string) ($body['password'] ?? '');
if ($name === '' || $pass === '') {
    fail(400, 'missing_fields', 'Account and password are required.');
}

$ids = auth_login($name, $pass);
if (!$ids) {
    fail(401, 'invalid_credentials', 'Account or password incorrect.');
}

session_write([
    'ls_account_id' => $ids['ls_account_id'],
    'account_id'    => $ids['account_id'],
    'character_id'  => null,
]);

json_out([
    'ok'          => true,
    'account_id'  => $ids['account_id'],
]);
