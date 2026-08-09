<?php
/**
 * Ownership checks. Every endpoint that touches a character or bot must run
 * these against the session's account_id -- otherwise a logged-in player
 * could poke another player's data by supplying a foreign id.
 */

function assert_character_owned(int $account_id, int $character_id): array {
    $stmt = db()->prepare(
        'SELECT id, account_id, name, level, class, race
         FROM character_data
         WHERE id = :id AND account_id = :aid AND deleted_at IS NULL LIMIT 1'
    );
    // deleted_at may not exist on older schemas; fall back to no-filter.
    try {
        $stmt->execute([':id' => $character_id, ':aid' => $account_id]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $stmt = db()->prepare(
            'SELECT id, account_id, name, level, class, race
             FROM character_data WHERE id = :id AND account_id = :aid LIMIT 1'
        );
        $stmt->execute([':id' => $character_id, ':aid' => $account_id]);
        $row = $stmt->fetch();
    }
    if (!$row) {
        fail(403, 'forbidden', 'That character does not belong to you.');
    }
    return $row;
}

function assert_bot_owned(int $account_id, int $bot_id): array {
    $stmt = db()->prepare(
        'SELECT b.bot_id AS id, b.owner_id, b.name, b.race, b.class, b.level, b.gender
         FROM bot_data b
         JOIN character_data c ON c.id = b.owner_id
         WHERE b.bot_id = :bid AND c.account_id = :aid LIMIT 1'
    );
    $stmt->execute([':bid' => $bot_id, ':aid' => $account_id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail(403, 'forbidden', 'That bot does not belong to any of your characters.');
    }
    return $row;
}
