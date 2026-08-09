<?php
require_once __DIR__ . '/bootstrap.php';

$s = session_read();
if (!$s) {
    json_out(['authenticated' => false]);
}

$char = null;
if (!empty($s['character_id'])) {
    $stmt = db()->prepare(
        'SELECT id, name, level, class, race FROM character_data
         WHERE id = :id AND account_id = :aid LIMIT 1'
    );
    $stmt->execute([':id' => $s['character_id'], ':aid' => $s['account_id']]);
    $char = $stmt->fetch() ?: null;
}

json_out([
    'authenticated' => true,
    'account_id'    => $s['account_id'],
    'character'     => $char,
]);
