<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/slots.php';

$s = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $bot_id = (int) ($_GET['bot_id'] ?? 0);
    if ($bot_id <= 0) fail(400, 'missing_fields', 'bot_id is required.');
    $bot = assert_bot_owned($s['account_id'], $bot_id);

    // Class default spell list for this bot (bot_data.spells_id -> bot_spells_entries.npc_spells_id)
    $stmt = db()->prepare('SELECT spells_id FROM bot_data WHERE bot_id = :bid LIMIT 1');
    $stmt->execute([':bid' => $bot_id]);
    $sid_row = $stmt->fetch();
    $spells_id = (int) ($sid_row['spells_id'] ?? 0);

    $entries = [];
    if ($spells_id > 0) {
        $stmt = db()->prepare(
            'SELECT e.spell_id, e.type, e.minlevel, e.maxlevel, e.priority, e.recast_delay,
                    e.min_hp, e.max_hp, e.manacost,
                    s.name AS spell_name, s.mana AS spell_mana, s.new_icon AS icon
             FROM bot_spells_entries e
             LEFT JOIN spells_new s ON s.id = e.spell_id
             WHERE e.npc_spells_id = :sid
               AND e.minlevel <= :lvl
             ORDER BY e.type ASC, e.priority ASC, e.spell_id ASC'
        );
        $stmt->execute([':sid' => $spells_id, ':lvl' => (int) $bot['level']]);
        $entries = $stmt->fetchAll();
    }

    // Per-bot overrides from bot_spell_settings.
    $stmt = db()->prepare(
        'SELECT id, spell_id, priority, min_hp, max_hp, is_enabled
         FROM bot_spell_settings WHERE bot_id = :bid'
    );
    $stmt->execute([':bid' => $bot_id]);
    $overrides = [];
    foreach ($stmt->fetchAll() as $r) {
        $overrides[(int) $r['spell_id']] = [
            'id'         => (int) $r['id'],
            'priority'   => (int) $r['priority'],
            'min_hp'     => (int) $r['min_hp'],
            'max_hp'     => (int) $r['max_hp'],
            'is_enabled' => (int) $r['is_enabled'],
        ];
    }

    // Merge defaults + overrides so the UI has one row per spell.
    $out = [];
    foreach ($entries as $e) {
        $sid = (int) $e['spell_id'];
        $o   = $overrides[$sid] ?? null;
        $out[] = [
            'spell_id'         => $sid,
            'spell_name'       => $e['spell_name'] ?: "Spell #$sid",
            'icon'             => (int) ($e['icon'] ?? 0),
            'type'             => (int) $e['type'],
            'min_level'        => (int) $e['minlevel'],
            'max_level'        => (int) $e['maxlevel'],
            'default_priority' => (int) $e['priority'],
            'default_min_hp'   => (int) $e['min_hp'],
            'default_max_hp'   => (int) $e['max_hp'],
            'manacost'         => (int) $e['manacost'],
            'recast_delay'     => (int) $e['recast_delay'],
            'override'         => $o,
            'priority'         => $o ? $o['priority']   : (int) $e['priority'],
            'min_hp'           => $o ? $o['min_hp']     : (int) $e['min_hp'],
            'max_hp'           => $o ? $o['max_hp']     : (int) $e['max_hp'],
            'is_enabled'       => $o ? $o['is_enabled'] : 1,
        ];
    }

    json_out([
        'bot'    => $bot,
        'spells' => $out,
    ]);
}

// ------------------------------------------------------------------ POST ----
require_method('POST');
$body   = read_json();
$bot_id = (int) ($body['bot_id'] ?? 0);
if ($bot_id <= 0) fail(400, 'missing_fields', 'bot_id is required.');
$bot = assert_bot_owned($s['account_id'], $bot_id);

$action = (string) ($body['action'] ?? 'update');
$sid    = (int) ($body['spell_id'] ?? 0);
if ($sid <= 0) fail(400, 'missing_fields', 'spell_id is required.');

$pdo = db();

if ($action === 'reset') {
    // Delete the per-bot override for this spell -> falls back to defaults.
    $stmt = $pdo->prepare('DELETE FROM bot_spell_settings WHERE bot_id = :bid AND spell_id = :sid');
    $stmt->execute([':bid' => $bot_id, ':sid' => $sid]);
    json_out(['ok' => true, 'action' => 'reset']);
}

// update: upsert bot_spell_settings row for (bot_id, spell_id).
$priority   = (int) ($body['priority']   ?? 0);
$min_hp     = (int) ($body['min_hp']     ?? 0);
$max_hp     = (int) ($body['max_hp']     ?? 100);
$is_enabled = (int) (!!($body['is_enabled'] ?? 1));

// Clamp to sensible ranges.
if ($min_hp < 0)   $min_hp = 0;
if ($max_hp > 100) $max_hp = 100;
if ($min_hp > $max_hp) { $tmp = $min_hp; $min_hp = $max_hp; $max_hp = $tmp; }

$stmt = $pdo->prepare(
    'SELECT id FROM bot_spell_settings WHERE bot_id = :bid AND spell_id = :sid LIMIT 1'
);
$stmt->execute([':bid' => $bot_id, ':sid' => $sid]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $pdo->prepare(
        'UPDATE bot_spell_settings
         SET priority = :p, min_hp = :mn, max_hp = :mx, is_enabled = :e
         WHERE id = :id'
    );
    $stmt->execute([
        ':p' => $priority, ':mn' => $min_hp, ':mx' => $max_hp,
        ':e' => $is_enabled, ':id' => (int) $existing['id'],
    ]);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO bot_spell_settings
            (bot_id, spell_id, priority, min_hp, max_hp, is_enabled)
         VALUES (:bid, :sid, :p, :mn, :mx, :e)'
    );
    $stmt->execute([
        ':bid' => $bot_id, ':sid' => $sid, ':p' => $priority,
        ':mn' => $min_hp, ':mx' => $max_hp, ':e' => $is_enabled,
    ]);
}

json_out(['ok' => true, 'action' => 'update']);
