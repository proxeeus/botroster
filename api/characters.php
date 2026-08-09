<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/slots.php';

$s = require_auth();

$sql = 'SELECT id, name, level, class, race, last_login
        FROM character_data
        WHERE account_id = :aid ';
$sql .= 'AND (deleted_at IS NULL OR deleted_at = 0) ';
$sql .= 'ORDER BY last_login DESC, name ASC';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute([':aid' => $s['account_id']]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback for schemas that lack deleted_at / last_login.
    $stmt = db()->prepare(
        'SELECT id, name, level, class, race
         FROM character_data WHERE account_id = :aid ORDER BY name ASC'
    );
    $stmt->execute([':aid' => $s['account_id']]);
    $rows = $stmt->fetchAll();
}

foreach ($rows as &$r) {
    $r['class_name'] = class_name((int) $r['class']);
    $r['race_name']  = race_name((int) $r['race']);
}
unset($r);

json_out(['characters' => $rows]);
