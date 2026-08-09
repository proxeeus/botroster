<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';

require_method('POST');
$s = require_auth();

$body = read_json();
$cid = (int) ($body['character_id'] ?? 0);
if ($cid <= 0) fail(400, 'missing_fields', 'character_id is required.');

$char = assert_character_owned($s['account_id'], $cid);
session_set('character_id', $cid);

json_out(['ok' => true, 'character' => $char]);
