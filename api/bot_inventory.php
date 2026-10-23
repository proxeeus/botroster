<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/slots.php';
require_once __DIR__ . '/bot_vault.php';
require_once __DIR__ . '/items.php';

$s = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $bot_id = (int) ($_GET['bot_id'] ?? 0);
    if ($bot_id <= 0) fail(400, 'missing_fields', 'bot_id is required.');
    $bot = assert_bot_owned($s['account_id'], $bot_id);
    $owner_cid = (int) $bot['owner_id'];

    $stmt = db()->prepare(
        'SELECT slot_id, item_id, inst_charges, inst_color, inst_no_drop, inst_custom_data,
                augment_1, augment_2, augment_3, augment_4, augment_5, augment_6
         FROM bot_inventories WHERE bot_id = :bid ORDER BY slot_id ASC'
    );
    $stmt->execute([':bid' => $bot_id]);
    $rows = $stmt->fetchAll();

    $vault_rows = [];
    if (vault_available()) {
        $stmt = db()->prepare(
            'SELECT vault_id, item_id, charges, color, attuned, vaulted_at
             FROM bot_vault WHERE bot_id = :bid ORDER BY vault_id ASC'
        );
        $stmt->execute([':bid' => $bot_id]);
        $vault_rows = $stmt->fetchAll();
    }

    // Look up items so the UI can display names/stats without a second call.
    $item_ids = [];
    foreach ($rows as $r) {
        if ((int) $r['item_id'] > 0) $item_ids[(int) $r['item_id']] = true;
    }
    foreach ($vault_rows as $r) {
        if ((int) $r['item_id'] > 0) $item_ids[(int) $r['item_id']] = true;
    }
    $items_by_id = fetch_items_by_id(array_keys($item_ids));

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
            // true: the owner handed it over and gets it back; false: bot-bound.
            'gift'      => is_owner_gift($r['inst_custom_data'], $owner_cid),
            'augments'  => array_values(array_filter([
                (int) $r['augment_1'], (int) $r['augment_2'], (int) $r['augment_3'],
                (int) $r['augment_4'], (int) $r['augment_5'], (int) $r['augment_6'],
            ])),
        ];
    }

    $vault = [];
    foreach ($vault_rows as $r) {
        $vault[] = [
            'vault_id'   => (int) $r['vault_id'],
            'item_id'    => (int) $r['item_id'],
            'charges'    => (int) $r['charges'],
            'no_drop'    => (int) $r['attuned'],
            'vaulted_at' => $r['vaulted_at'],
        ];
    }

    json_out([
        'bot'             => $bot,
        'bot_class_name'  => class_name((int) $bot['class']),
        'bot_race_name'   => race_name((int) $bot['race']),
        'equipment'       => $out,
        'vault'           => $vault,
        'vault_available' => vault_available(),
        'items_by_id'     => $items_by_id,
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
    // Move an item from the player's inventory into a bot equipment slot. It
    // is tagged as an owner gift, so it comes back to this character later.
    // Body: { source_slot: int, target_slot: 0-22 }
    $src = (int) ($body['source_slot'] ?? -1);
    $tgt = (int) ($body['target_slot'] ?? -1);
    if ($tgt < 0 || $tgt > 22) fail(400, 'bad_slot', 'Target slot must be an equipment slot 0-22.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT itemid, charges, color, instnodrop, custom_data,
                    augslot1,augslot2,augslot3,augslot4,augslot5,augslot6
             FROM inventory WHERE charid = :cid AND slotid = :s FOR UPDATE'
        );
        $stmt->execute([':cid' => $cid, ':s' => $src]);
        $src_row = $stmt->fetch();
        if (!$src_row || (int) $src_row['itemid'] <= 0) {
            $pdo->rollBack();
            fail(400, 'empty_source', 'The source slot is empty.');
        }

        $item = load_item((int) $src_row['itemid']);
        if (!$item) { $pdo->rollBack(); fail(400, 'unknown_item', 'Item not found in items table.'); }

        assert_bot_can_equip($pdo, $bot, $item, $tgt);

        $worn    = lock_bot_equipment($pdo, $bot_id);
        $release = slots_released($pdo, $worn, $item, $tgt);
        assert_no_bot_lore($pdo, $bot, $worn, $release, $item);

        // Returning gifts take the freed source slot first -- unless it is a
        // worn slot of yours, which the returning item may not fit.
        $returns = [];
        if ($src < 0 || $src > 22) $returns[] = [$src, true];
        foreach (free_general_slots($pdo, $cid) as $free) $returns[] = [$free, false];

        $moves = release_bot_slots($pdo, $bot, $cid, $worn, $release, $returns, $src);

        $src_reused = false;
        foreach ($moves as $m) {
            if (($m['player_slot'] ?? null) === $src) $src_reused = true;
        }
        if (!$src_reused) {
            $pdo->prepare('DELETE FROM inventory WHERE charid = :cid AND slotid = :s')
                ->execute([':cid' => $cid, ':s' => $src]);
        }

        $inst = inst_from_player_row($src_row);
        $inst['custom_data'] = mark_owner_gift($inst['custom_data'], $cid);
        put_bot_item($pdo, $bot_id, $tgt, $inst, false);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_out(['ok' => true, 'action' => 'equip', 'source_slot' => $src, 'target_slot' => $tgt, 'moves' => $moves]);
}

if ($action === 'unequip') {
    // Take an item off the bot: an owner gift comes back to your first free
    // general slot, bot-bound gear goes into the bot's vault.
    $tgt = (int) ($body['bot_slot'] ?? -1);
    if ($tgt < 0 || $tgt > 22) fail(400, 'bad_slot', 'bot_slot must be 0-22.');

    $pdo->beginTransaction();
    try {
        $worn = lock_bot_equipment($pdo, $bot_id);
        if (!isset($worn[$tgt])) {
            $pdo->rollBack();
            fail(400, 'empty_slot', 'That equipment slot is empty.');
        }

        $returns = array_map(fn($free) => [$free, false], free_general_slots($pdo, $cid));
        $moves = release_bot_slots($pdo, $bot, $cid, $worn, [$tgt], $returns);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $m = $moves[0];
    json_out([
        'ok' => true, 'action' => 'unequip', 'bot_slot' => $tgt,
        'to' => $m['to'], 'moved_to_slot' => $m['player_slot'] ?? null,
    ]);
}

if ($action === 'vault_equip') {
    // ^vaultequip: put a bot-bound item from the bot's vault back on.
    // Body: { vault_id: int, target_slot: 0-22 }
    $vid = (int) ($body['vault_id'] ?? 0);
    $tgt = (int) ($body['target_slot'] ?? -1);
    if ($vid <= 0) fail(400, 'missing_fields', 'vault_id is required.');
    if ($tgt < 0 || $tgt > 22) fail(400, 'bad_slot', 'Target slot must be an equipment slot 0-22.');
    require_vault();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM bot_vault WHERE vault_id = :vid AND bot_id = :bid FOR UPDATE'
        );
        $stmt->execute([':vid' => $vid, ':bid' => $bot_id]);
        $vrow = $stmt->fetch();
        if (!$vrow) {
            $pdo->rollBack();
            fail(404, 'not_in_vault', "{$bot['name']} has no item #$vid in the vault.");
        }

        $item = load_item((int) $vrow['item_id']);
        if (!$item) { $pdo->rollBack(); fail(400, 'unknown_item', 'Item not found in items table.'); }

        assert_bot_can_equip($pdo, $bot, $item, $tgt);

        $worn    = lock_bot_equipment($pdo, $bot_id);
        $release = slots_released($pdo, $worn, $item, $tgt);
        assert_no_bot_lore($pdo, $bot, $worn, $release, $item);

        $returns = array_map(fn($free) => [$free, false], free_general_slots($pdo, $cid));
        $moves = release_bot_slots($pdo, $bot, $cid, $worn, $release, $returns);

        $pdo->prepare('DELETE FROM bot_vault WHERE vault_id = :vid AND bot_id = :bid')
            ->execute([':vid' => $vid, ':bid' => $bot_id]);
        put_bot_item($pdo, $bot_id, $tgt, inst_from_vault_row($vrow), false);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_out(['ok' => true, 'action' => 'vault_equip', 'vault_id' => $vid, 'target_slot' => $tgt, 'moves' => $moves]);
}

fail(400, 'unknown_action', "Unknown action '$action'. Use 'equip', 'unequip' or 'vault_equip'.");
