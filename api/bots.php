<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/slots.php';

$s = require_auth();
$cid = (int) ($s['character_id'] ?? 0);
if ($cid <= 0) fail(400, 'no_character_selected', 'Select a character first.');
assert_character_owned($s['account_id'], $cid);

$stmt = db()->prepare(
    'SELECT bot_id AS id, name, race, class, gender, level,
            face, hair_color, hair_style, beard, beard_color,
            eye_color_1, eye_color_2, drakkin_heritage, drakkin_tattoo, drakkin_details,
            hp, mana, ac, atk
     FROM bot_data
     WHERE owner_id = :cid
     ORDER BY level DESC, name ASC'
);
$stmt->execute([':cid' => $cid]);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['class_name'] = class_name((int) $r['class']);
    $r['race_name']  = race_name((int) $r['race']);
}
unset($r);

json_out(['bots' => $rows]);
