<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/slots.php';

$s = require_auth();
$cid = (int) ($s['character_id'] ?? 0);
if ($cid <= 0) fail(400, 'no_character_selected', 'Select a character first.');
assert_character_owned($s['account_id'], $cid);

$stmt = db()->prepare(
    'SELECT b.bot_id AS id, b.name, b.race, b.class, b.gender, b.level,
            b.face, b.hair_color, b.hair_style, b.beard, b.beard_color,
            b.eye_color_1, b.eye_color_2, b.drakkin_heritage, b.drakkin_tattoo, b.drakkin_details,
            b.hp, b.mana, b.ac, b.atk,
            (r.bot_id IS NOT NULL) AS in_raid_roster
     FROM bot_data b
     LEFT JOIN bot_raid_roster r ON r.owner_id = b.owner_id AND r.bot_id = b.bot_id
     WHERE b.owner_id = :cid
     ORDER BY b.level DESC, b.name ASC'
);
$stmt->execute([':cid' => $cid]);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['class_name']     = class_name((int) $r['class']);
    $r['race_name']      = race_name((int) $r['race']);
    $r['in_raid_roster'] = (bool) $r['in_raid_roster'];
}
unset($r);

json_out(['bots' => $rows]);
