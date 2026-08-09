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

    $stmt = db()->prepare(
        'SELECT slot_id, item_id, inst_charges, inst_color, inst_no_drop,
                augment_1, augment_2, augment_3, augment_4, augment_5, augment_6
         FROM bot_inventories WHERE bot_id = :bid ORDER BY slot_id ASC'
    );
    $stmt->execute([':bid' => $bot_id]);
    $rows = $stmt->fetchAll();

    // Look up items so the UI can display names/stats without a second call.
    $item_ids = [];
    foreach ($rows as $r) {
        if ((int) $r['item_id'] > 0) $item_ids[(int) $r['item_id']] = true;
    }
    $items_by_id = [];
    if ($item_ids) {
        $ids = array_keys($item_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT id, Name, icon, itemtype, classes, races, slots, ac, hp, mana,
                    damage, delay, nodrop, magic, loregroup
             FROM items WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $it) $items_by_id[(int) $it['id']] = $it;
    }

    $out = [];
    foreach ($rows as $r) {
        $slot = (int) $r['slot_id'];
        $out[] = [
            'slot'      => $slot,
            'slot_name' => EQUIPMENT_SLOT_NAMES[$slot] ?? "Slot $slot",
            'item_id'   => (int) $r['item_id'],
            'charges'   => (int) $r['inst_charges'],
            'color'     => (int) $r['inst_color'],
            'no_drop'   => (int) $r['inst_no_drop'],
            'augments'  => array_values(array_filter([
                (int) $r['augment_1'], (int) $r['augment_2'], (int) $r['augment_3'],
                (int) $r['augment_4'], (int) $r['augment_5'], (int) $r['augment_6'],
            ])),
        ];
    }

    json_out([
        'bot'         => $bot,
        'bot_class_name' => class_name((int) $bot['class']),
        'bot_race_name'  => race_name((int) $bot['race']),
        'equipment'   => $out,
        'items_by_id' => $items_by_id,
    ]);
}

// ------------------------------------------------------------------ POST ----
require_method('POST');
$body   = read_json();
$bot_id = (int) ($body['bot_id'] ?? 0);
$action = (string) ($body['action'] ?? '');
if ($bot_id <= 0 || $action === '') fail(400, 'missing_fields', 'bot_id + action are required.');

$bot = assert_bot_owned($s['account_id'], $bot_id);
$cid = (int) ($s['character_id'] ?? 0);
if ($cid <= 0 || (int) $bot['owner_id'] !== $cid) {
    fail(403, 'wrong_character', 'This bot belongs to a different character. Select that character first.');
}

$pdo = db();

if ($action === 'equip') {
    // Move an item from the player's inventory into a bot equipment slot.
    // Body: { source_slot: int, target_slot: 0-22 }
    $src = (int) ($body['source_slot'] ?? -1);
    $tgt = (int) ($body['target_slot'] ?? -1);
    if ($tgt < 0 || $tgt > 22) fail(400, 'bad_slot', 'Target slot must be an equipment slot 0-22.');

    $pdo->beginTransaction();
    try {
        // Lock rows: player source, bot target.
        $stmt = $pdo->prepare(
            'SELECT itemid, charges, color, instnodrop,
                    augslot1,augslot2,augslot3,augslot4,augslot5,augslot6
             FROM inventory WHERE charid = :cid AND slotid = :s FOR UPDATE'
        );
        $stmt->execute([':cid' => $cid, ':s' => $src]);
        $src_row = $stmt->fetch();
        if (!$src_row || (int) $src_row['itemid'] <= 0) {
            $pdo->rollBack();
            fail(400, 'empty_source', 'The source slot is empty.');
        }

        // Item metadata for restriction checks.
        $stmt = $pdo->prepare(
            'SELECT id, Name, classes, races, slots, nodrop, itemtype
             FROM items WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int) $src_row['itemid']]);
        $item = $stmt->fetch();
        if (!$item) { $pdo->rollBack(); fail(400, 'unknown_item', 'Item not found in items table.'); }

        $bot_class = (int) $bot['class'];
        $bot_race  = (int) $bot['race'];
        if (!item_fits_slot($item, $tgt)) {
            $pdo->rollBack();
            fail(400, 'slot_forbidden', $item['Name'] . " cannot go into " . (EQUIPMENT_SLOT_NAMES[$tgt] ?? "slot $tgt") . ".");
        }
        if (!item_allows_class($item, $bot_class)) {
            $pdo->rollBack();
            fail(400, 'class_forbidden', $item['Name'] . " isn't usable by a " . class_name($bot_class) . ".");
        }
        if (!item_allows_race($item, $bot_race)) {
            $pdo->rollBack();
            fail(400, 'race_forbidden', $item['Name'] . " isn't usable by a " . race_name($bot_race) . ".");
        }

        // Currently equipped item in target slot (if any).
        $stmt = $pdo->prepare(
            'SELECT item_id, inst_charges, inst_color, inst_no_drop,
                    augment_1,augment_2,augment_3,augment_4,augment_5,augment_6
             FROM bot_inventories WHERE bot_id = :bid AND slot_id = :s FOR UPDATE'
        );
        $stmt->execute([':bid' => $bot_id, ':s' => $tgt]);
        $tgt_row = $stmt->fetch();

        // Write bot slot with the source item.
        if ($tgt_row) {
            $stmt = $pdo->prepare(
                'UPDATE bot_inventories
                 SET item_id = :iid, inst_charges = :ch, inst_color = :co, inst_no_drop = :nd,
                     augment_1=:a1, augment_2=:a2, augment_3=:a3,
                     augment_4=:a4, augment_5=:a5, augment_6=:a6
                 WHERE bot_id = :bid AND slot_id = :s'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO bot_inventories
                    (bot_id, slot_id, item_id, inst_charges, inst_color, inst_no_drop,
                     augment_1, augment_2, augment_3, augment_4, augment_5, augment_6)
                 VALUES
                    (:bid, :s, :iid, :ch, :co, :nd, :a1, :a2, :a3, :a4, :a5, :a6)'
            );
        }
        $stmt->execute([
            ':bid' => $bot_id, ':s' => $tgt,
            ':iid' => (int) $src_row['itemid'],
            ':ch'  => (int) $src_row['charges'],
            ':co'  => (int) $src_row['color'],
            ':nd'  => (int) $src_row['instnodrop'],
            ':a1'  => (int) $src_row['augslot1'], ':a2' => (int) $src_row['augslot2'],
            ':a3'  => (int) $src_row['augslot3'], ':a4' => (int) $src_row['augslot4'],
            ':a5'  => (int) $src_row['augslot5'], ':a6' => (int) $src_row['augslot6'],
        ]);

        // Player source: if swap, put displaced item there; otherwise delete row.
        if ($tgt_row && (int) $tgt_row['item_id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE inventory
                 SET itemid=:iid, charges=:ch, color=:co, instnodrop=:nd,
                     augslot1=:a1,augslot2=:a2,augslot3=:a3,augslot4=:a4,augslot5=:a5,augslot6=:a6
                 WHERE charid=:cid AND slotid=:s'
            );
            $stmt->execute([
                ':iid' => (int) $tgt_row['item_id'],
                ':ch'  => (int) $tgt_row['inst_charges'],
                ':co'  => (int) $tgt_row['inst_color'],
                ':nd'  => (int) $tgt_row['inst_no_drop'],
                ':a1'  => (int) $tgt_row['augment_1'], ':a2' => (int) $tgt_row['augment_2'],
                ':a3'  => (int) $tgt_row['augment_3'], ':a4' => (int) $tgt_row['augment_4'],
                ':a5'  => (int) $tgt_row['augment_5'], ':a6' => (int) $tgt_row['augment_6'],
                ':cid' => $cid, ':s' => $src,
            ]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM inventory WHERE charid=:cid AND slotid=:s');
            $stmt->execute([':cid' => $cid, ':s' => $src]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_out(['ok' => true, 'action' => 'equip', 'source_slot' => $src, 'target_slot' => $tgt]);
}

if ($action === 'unequip') {
    // Move bot equipment back to the player, into the first free general slot.
    $tgt = (int) ($body['bot_slot'] ?? -1);
    if ($tgt < 0 || $tgt > 22) fail(400, 'bad_slot', 'bot_slot must be 0-22.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT item_id, inst_charges, inst_color, inst_no_drop,
                    augment_1,augment_2,augment_3,augment_4,augment_5,augment_6
             FROM bot_inventories WHERE bot_id = :bid AND slot_id = :s FOR UPDATE'
        );
        $stmt->execute([':bid' => $bot_id, ':s' => $tgt]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['item_id'] <= 0) {
            $pdo->rollBack();
            fail(400, 'empty_slot', 'That equipment slot is empty.');
        }

        // Find the first free general slot 23-32 for this character.
        $stmt = $pdo->prepare(
            'SELECT slotid FROM inventory
             WHERE charid = :cid AND slotid BETWEEN 23 AND 32 FOR UPDATE'
        );
        $stmt->execute([':cid' => $cid]);
        $taken = [];
        foreach ($stmt->fetchAll() as $r) $taken[(int) $r['slotid']] = true;
        $free = null;
        for ($s2 = 23; $s2 <= 32; $s2++) if (!isset($taken[$s2])) { $free = $s2; break; }
        if ($free === null) {
            $pdo->rollBack();
            fail(400, 'inventory_full', 'Your general inventory (slots 23-32) is full.');
        }

        // Insert into player inventory, clear bot slot.
        $stmt = $pdo->prepare(
            'INSERT INTO inventory
                (charid, slotid, itemid, charges, color, instnodrop,
                 augslot1, augslot2, augslot3, augslot4, augslot5, augslot6)
             VALUES
                (:cid, :s, :iid, :ch, :co, :nd, :a1, :a2, :a3, :a4, :a5, :a6)'
        );
        $stmt->execute([
            ':cid' => $cid, ':s' => $free,
            ':iid' => (int) $row['item_id'],
            ':ch'  => (int) $row['inst_charges'],
            ':co'  => (int) $row['inst_color'],
            ':nd'  => (int) $row['inst_no_drop'],
            ':a1'  => (int) $row['augment_1'], ':a2' => (int) $row['augment_2'],
            ':a3'  => (int) $row['augment_3'], ':a4' => (int) $row['augment_4'],
            ':a5'  => (int) $row['augment_5'], ':a6' => (int) $row['augment_6'],
        ]);
        $stmt = $pdo->prepare('DELETE FROM bot_inventories WHERE bot_id = :bid AND slot_id = :s');
        $stmt->execute([':bid' => $bot_id, ':s' => $tgt]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_out(['ok' => true, 'action' => 'unequip', 'bot_slot' => $tgt, 'moved_to_slot' => $free]);
}

fail(400, 'unknown_action', "Unknown action '$action'. Use 'equip' or 'unequip'.");
