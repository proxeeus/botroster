<?php
/**
 * bot_raid_roster CRUD -- the persistent list of bots the `^raidcreate`
 * command spawns/adds first. Table: bot_raid_roster (owner_id, bot_id).
 *
 * GET  → { bot_ids: int[] }  for the selected character
 * POST → { bot_id: int, action: 'add' | 'remove' }
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';

$s = require_auth();
$cid = (int) ($s['character_id'] ?? 0);
if ($cid <= 0) fail(400, 'no_character_selected', 'Select a character first.');
assert_character_owned($s['account_id'], $cid);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = db()->prepare(
        'SELECT bot_id FROM bot_raid_roster WHERE owner_id = :cid'
    );
    $stmt->execute([':cid' => $cid]);
    $ids = [];
    foreach ($stmt->fetchAll() as $r) $ids[] = (int) $r['bot_id'];
    json_out(['bot_ids' => $ids]);
}

require_method('POST');
$body   = read_json();
$bot_id = (int) ($body['bot_id'] ?? 0);
$action = (string) ($body['action'] ?? '');
if ($bot_id <= 0) fail(400, 'missing_fields', 'bot_id is required.');

// Confirm the bot really belongs to this account (and to this character too --
// bot_raid_roster.owner_id is a character_id).
$bot = assert_bot_owned($s['account_id'], $bot_id);
if ((int) $bot['owner_id'] !== $cid) {
    fail(403, 'wrong_character', 'This bot belongs to a different character. Select that character first.');
}

if ($action === 'add') {
    $stmt = db()->prepare(
        'REPLACE INTO bot_raid_roster (owner_id, bot_id) VALUES (:cid, :bid)'
    );
    $stmt->execute([':cid' => $cid, ':bid' => $bot_id]);
    json_out(['ok' => true, 'action' => 'add', 'in_roster' => true]);
}
if ($action === 'remove') {
    $stmt = db()->prepare(
        'DELETE FROM bot_raid_roster WHERE owner_id = :cid AND bot_id = :bid'
    );
    $stmt->execute([':cid' => $cid, ':bid' => $bot_id]);
    json_out(['ok' => true, 'action' => 'remove', 'in_roster' => false]);
}
fail(400, 'unknown_action', "Unknown action '$action'. Use 'add' or 'remove'.");
