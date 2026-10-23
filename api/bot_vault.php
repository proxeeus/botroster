<?php
/**
 * Owner gifts vs bot-bound gear -- the web mirror of the server's bot vault
 * (Server: utils/sql/git/bots/optional/2026_10_20_bots_bot_vault.sql).
 *
 * Every item a bot holds is one of two kinds, told apart by the item's
 * custom data (bot_inventories.inst_custom_data):
 *
 *   owner gift   tagged bot_gift^<owner character id>: the owner handed it
 *                over. It goes back to the owner when unequipped or replaced.
 *   bot-bound    anything else: gear a PlayerBot was generated with, or a
 *                gift tagged for another character. It never goes to a
 *                player; it moves into bot_vault instead.
 *
 * Fail closed: without the bot_vault table, every action that would vault an
 * item is refused and the item stays on the bot.
 */

require_once __DIR__ . '/slots.php';

const OWNER_GIFT_KEY = 'bot_gift';
const SLOT_RANGE     = 11; // SLOT_AMMO, SLOT_PRIMARY, SLOT_SECONDARY: slots.php

// items.itemtype values (common/item_data.h).
const ITEM_TYPE_1H_SLASH  = 0;
const ITEM_TYPE_2H_SLASH  = 1;
const ITEM_TYPE_1H_PIERCE = 2;
const ITEM_TYPE_1H_BLUNT  = 3;
const ITEM_TYPE_2H_BLUNT  = 4;
const ITEM_TYPE_SHIELD    = 8;
const ITEM_TYPE_2H_PIERCE = 35;
const ITEM_TYPE_H2H       = 45;
const ITEM_TYPE_AUGMENT   = 54;

const SKILL_DUAL_WIELD = 22;

/**
 * Custom data string <-> map, same format as EQ::ItemInstance:
 * "a^1^^b^2^" (each entry "key^value^", entries joined by "^", keys sorted
 * like the std::map that holds them).
 */
function custom_data_parse(?string $s): array {
    $parts = array_values(array_filter(explode('^', (string) $s), fn($p) => $p !== ''));
    $out = [];
    for ($i = 0; $i + 1 < count($parts); $i += 2) $out[$parts[$i]] = $parts[$i + 1];
    return $out;
}

function custom_data_build(array $map): string {
    ksort($map, SORT_STRING);
    $out = '';
    foreach ($map as $k => $v) {
        if ($out !== '') $out .= '^';
        $out .= $k . '^' . $v . '^';
    }
    return $out;
}

function is_owner_gift(?string $custom_data, int $owner_cid): bool {
    $map = custom_data_parse($custom_data);
    return $owner_cid > 0 && ($map[OWNER_GIFT_KEY] ?? '') === (string) $owner_cid;
}

function mark_owner_gift(?string $custom_data, int $owner_cid): string {
    $map = custom_data_parse($custom_data);
    $map[OWNER_GIFT_KEY] = (string) $owner_cid;
    return custom_data_build($map);
}

function clear_owner_gift(?string $custom_data): string {
    $map = custom_data_parse($custom_data);
    unset($map[OWNER_GIFT_KEY]);
    return custom_data_build($map);
}

function vault_available(): bool {
    static $ok = null;
    if ($ok === null) {
        $stmt = db()->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'bot_vault'"
        );
        $ok = (int) $stmt->fetchColumn() > 0;
    }
    return $ok;
}

function require_vault(): void {
    if (!vault_available()) {
        fail(409, 'vault_missing',
            'The bot vault is not set up on this server (2026_10_20_bots_bot_vault.sql), ' .
            'so bot-bound gear has nowhere to go. Nothing was changed.');
    }
}

/** Item metadata needed by the equip rules. */
function load_item(int $item_id): ?array {
    $stmt = db()->prepare(
        'SELECT id, Name, classes, races, slots, nodrop, itemtype, itemclass,
                damage, delay, reqlevel, loregroup
         FROM items WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $item_id]);
    return $stmt->fetch() ?: null;
}

function item_is_2h(array $item): bool {
    return in_array((int) $item['itemtype'], [ITEM_TYPE_2H_SLASH, ITEM_TYPE_2H_BLUNT, ITEM_TYPE_2H_PIERCE], true);
}

function item_is_1h_weapon(array $item): bool {
    return in_array((int) $item['itemtype'], [ITEM_TYPE_1H_SLASH, ITEM_TYPE_1H_PIERCE, ITEM_TYPE_1H_BLUNT, ITEM_TYPE_H2H], true);
}

function item_is_weapon(array $item): bool {
    return (int) $item['damage'] !== 0 && (int) $item['delay'] !== 0;
}

function bot_can_dual_wield(array $bot): bool {
    $stmt = db()->prepare(
        'SELECT 1 FROM skill_caps
         WHERE skill_id = :sk AND class_id = :c AND level <= :l AND cap > 0 LIMIT 1'
    );
    $stmt->execute([':sk' => SKILL_DUAL_WIELD, ':c' => (int) $bot['class'], ':l' => (int) $bot['level']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * The checks the game makes before a bot puts an item on (Bot::EquipFromVault):
 * slot, class, race, level, and the secondary hand. Fails the request on the
 * first one that doesn't pass.
 */
function assert_bot_can_equip(PDO $pdo, array $bot, array $item, int $slot): void {
    $name = $item['Name'];
    $slot_name = EQUIPMENT_SLOT_NAMES[$slot] ?? "slot $slot";
    $abort = function (string $key, string $msg) use ($pdo) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail(400, $key, $msg);
    };

    if ((int) $item['itemclass'] !== 0 || (int) $item['itemtype'] === ITEM_TYPE_AUGMENT) {
        $abort('not_equipable', "$name can't be equipped.");
    }
    if (!item_fits_slot($item, $slot)) {
        $abort('slot_forbidden', "$name cannot go into $slot_name.");
    }
    if (!item_allows_class($item, (int) $bot['class'])) {
        $abort('class_forbidden', "$name isn't usable by a " . class_name((int) $bot['class']) . '.');
    }
    if (!item_allows_race($item, (int) $bot['race'])) {
        $abort('race_forbidden', "$name isn't usable by a " . race_name((int) $bot['race']) . '.');
    }
    if ((int) $bot['level'] < (int) $item['reqlevel']) {
        $abort('level_forbidden', "$name needs level {$item['reqlevel']}; {$bot['name']} is {$bot['level']}.");
    }
    if ($slot === SLOT_SECONDARY) {
        $ok = (bot_can_dual_wield($bot) && item_is_1h_weapon($item))
            || (int) $item['itemtype'] === ITEM_TYPE_SHIELD
            || !item_is_weapon($item);
        if (!$ok) {
            $abort('hand_forbidden',
                "{$bot['name']} can't hold $name in the secondary hand: that needs Dual Wield " .
                'for a one-handed weapon, or a shield or a non-weapon.');
        }
    }
}

/** The bot's worn rows, keyed by slot, locked for the transaction. */
function lock_bot_equipment(PDO $pdo, int $bot_id): array {
    $stmt = $pdo->prepare(
        'SELECT slot_id, item_id, inst_charges, inst_color, inst_no_drop, inst_custom_data,
                augment_1, augment_2, augment_3, augment_4, augment_5, augment_6
         FROM bot_inventories WHERE bot_id = :bid AND slot_id BETWEEN 0 AND 22 FOR UPDATE'
    );
    $stmt->execute([':bid' => $bot_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        if ((int) $r['item_id'] > 0) $out[(int) $r['slot_id']] = $r;
    }
    return $out;
}

/**
 * Which worn slots leave the bot when $item goes into $slot: the slot itself,
 * the secondary for a two-hander, the primary's two-hander for a secondary.
 */
function slots_released(PDO $pdo, array $worn, array $item, int $slot): array {
    $release = [];
    if (isset($worn[$slot])) $release[] = $slot;
    if ($slot === SLOT_PRIMARY && item_is_2h($item) && isset($worn[SLOT_SECONDARY])) {
        $release[] = SLOT_SECONDARY;
    }
    if ($slot === SLOT_SECONDARY && isset($worn[SLOT_PRIMARY])) {
        $primary = load_item((int) $worn[SLOT_PRIMARY]['item_id']);
        if ($primary && item_is_2h($primary)) $release[] = SLOT_PRIMARY;
    }
    return $release;
}

/** Lore against what the bot keeps wearing (the released slots are leaving). */
function assert_no_bot_lore(PDO $pdo, array $bot, array $worn, array $release, array $item): void {
    $lore = (int) $item['loregroup'];
    if ($lore === 0) return;
    foreach ($worn as $s => $r) {
        if (in_array($s, $release, true)) continue;
        $w = load_item((int) $r['item_id']);
        if (!$w) continue;
        if ((int) $w['id'] === (int) $item['id'] || ($lore > 0 && (int) $w['loregroup'] === $lore)) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail(400, 'lore_conflict', "{$bot['name']} already has {$w['Name']}, which is lore.");
        }
    }
}

/**
 * Client::CheckLoreConflict for an item coming back to the player: lore by id
 * (loregroup -1) or by lore group (> 0). $leaving_slot is a player slot whose
 * item is leaving in the same move.
 */
function player_lore_conflict(PDO $pdo, int $cid, array $item, ?int $leaving_slot = null): bool {
    $lore = (int) $item['loregroup'];
    if ($lore === 0) return false;
    $sql = 'SELECT 1 FROM inventory i JOIN items it ON it.id = i.itemid
            WHERE i.charid = :cid AND ' .
           ($lore > 0 ? 'it.loregroup = :lore' : 'i.itemid = :lore') .
           ($leaving_slot !== null ? ' AND i.slotid <> :leave' : '') . ' LIMIT 1';
    $params = [':cid' => $cid, ':lore' => $lore > 0 ? $lore : (int) $item['id']];
    if ($leaving_slot !== null) $params[':leave'] = $leaving_slot;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/** Free general slots 23-32 of the character, in order, locked. */
function free_general_slots(PDO $pdo, int $cid): array {
    $stmt = $pdo->prepare(
        'SELECT slotid FROM inventory WHERE charid = :cid AND slotid BETWEEN 23 AND 32 FOR UPDATE'
    );
    $stmt->execute([':cid' => $cid]);
    $taken = [];
    foreach ($stmt->fetchAll() as $r) $taken[(int) $r['slotid']] = true;
    $free = [];
    for ($s = 23; $s <= 32; $s++) if (!isset($taken[$s])) $free[] = $s;
    return $free;
}

/** Writes a bot_inventories row (worn slot) as an INSERT or UPDATE. */
function put_bot_item(PDO $pdo, int $bot_id, int $slot, array $v, bool $exists): void {
    $sql = $exists
        ? 'UPDATE bot_inventories
           SET item_id = :iid, inst_charges = :ch, inst_color = :co, inst_no_drop = :nd,
               inst_custom_data = :cd,
               augment_1 = :a1, augment_2 = :a2, augment_3 = :a3,
               augment_4 = :a4, augment_5 = :a5, augment_6 = :a6
           WHERE bot_id = :bid AND slot_id = :s'
        : 'INSERT INTO bot_inventories
              (bot_id, slot_id, item_id, inst_charges, inst_color, inst_no_drop, inst_custom_data,
               augment_1, augment_2, augment_3, augment_4, augment_5, augment_6)
           VALUES (:bid, :s, :iid, :ch, :co, :nd, :cd, :a1, :a2, :a3, :a4, :a5, :a6)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':bid' => $bot_id, ':s' => $slot,
        ':iid' => $v['item_id'], ':ch' => $v['charges'], ':co' => $v['color'],
        ':nd'  => $v['no_drop'], ':cd' => $v['custom_data'],
        ':a1'  => $v['aug'][0], ':a2' => $v['aug'][1], ':a3' => $v['aug'][2],
        ':a4'  => $v['aug'][3], ':a5' => $v['aug'][4], ':a6' => $v['aug'][5],
    ]);
}

/** Writes a player inventory row as an INSERT or UPDATE. */
function put_player_item(PDO $pdo, int $cid, int $slot, array $v, bool $exists): void {
    $sql = $exists
        ? 'UPDATE inventory
           SET itemid = :iid, charges = :ch, color = :co, instnodrop = :nd, custom_data = :cd,
               augslot1 = :a1, augslot2 = :a2, augslot3 = :a3,
               augslot4 = :a4, augslot5 = :a5, augslot6 = :a6
           WHERE charid = :cid AND slotid = :s'
        : 'INSERT INTO inventory
              (charid, slotid, itemid, charges, color, instnodrop, custom_data,
               augslot1, augslot2, augslot3, augslot4, augslot5, augslot6)
           VALUES (:cid, :s, :iid, :ch, :co, :nd, :cd, :a1, :a2, :a3, :a4, :a5, :a6)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cid' => $cid, ':s' => $slot,
        ':iid' => $v['item_id'], ':ch' => $v['charges'], ':co' => $v['color'],
        ':nd'  => $v['no_drop'], ':cd' => $v['custom_data'],
        ':a1'  => $v['aug'][0], ':a2' => $v['aug'][1], ':a3' => $v['aug'][2],
        ':a4'  => $v['aug'][3], ':a5' => $v['aug'][4], ':a6' => $v['aug'][5],
    ]);
}

function vault_insert(PDO $pdo, int $bot_id, array $v): void {
    $stmt = $pdo->prepare(
        'INSERT INTO bot_vault
            (bot_id, item_id, charges, color, attuned, custom_data,
             augment_1, augment_2, augment_3, augment_4, augment_5, augment_6)
         VALUES (:bid, :iid, :ch, :co, :at, :cd, :a1, :a2, :a3, :a4, :a5, :a6)'
    );
    $stmt->execute([
        ':bid' => $bot_id, ':iid' => $v['item_id'], ':ch' => $v['charges'], ':co' => $v['color'],
        ':at'  => $v['no_drop'], ':cd' => $v['custom_data'],
        ':a1'  => $v['aug'][0], ':a2' => $v['aug'][1], ':a3' => $v['aug'][2],
        ':a4'  => $v['aug'][3], ':a5' => $v['aug'][4], ':a6' => $v['aug'][5],
    ]);
}

/** Row shapes -> one item-instance shape. */
function inst_from_bot_row(array $r): array {
    return [
        'item_id' => (int) $r['item_id'], 'charges' => (int) $r['inst_charges'],
        'color' => (int) $r['inst_color'], 'no_drop' => (int) $r['inst_no_drop'],
        'custom_data' => (string) ($r['inst_custom_data'] ?? ''),
        'aug' => [(int) $r['augment_1'], (int) $r['augment_2'], (int) $r['augment_3'],
                  (int) $r['augment_4'], (int) $r['augment_5'], (int) $r['augment_6']],
    ];
}

function inst_from_player_row(array $r): array {
    return [
        'item_id' => (int) $r['itemid'], 'charges' => (int) $r['charges'],
        'color' => (int) $r['color'], 'no_drop' => (int) $r['instnodrop'],
        'custom_data' => (string) ($r['custom_data'] ?? ''),
        'aug' => [(int) $r['augslot1'], (int) $r['augslot2'], (int) $r['augslot3'],
                  (int) $r['augslot4'], (int) $r['augslot5'], (int) $r['augslot6']],
    ];
}

function inst_from_vault_row(array $r): array {
    return [
        'item_id' => (int) $r['item_id'], 'charges' => (int) $r['charges'],
        'color' => (int) $r['color'], 'no_drop' => (int) $r['attuned'],
        'custom_data' => (string) ($r['custom_data'] ?? ''),
        'aug' => [(int) $r['augment_1'], (int) $r['augment_2'], (int) $r['augment_3'],
                  (int) $r['augment_4'], (int) $r['augment_5'], (int) $r['augment_6']],
    ];
}

/**
 * Takes the released worn items off the bot: owner gifts go back to the
 * owner (tag stripped) into $return_slots in order, bot-bound gear goes into
 * the vault. Returns [ ['slot', 'item_id', 'to' => 'owner'|'vault', 'player_slot'] ].
 * $return_slots: player slots to fill, each [slot, row_exists]; $leaving_slot:
 * a player slot whose item goes onto the bot in the same move.
 */
function release_bot_slots(PDO $pdo, array $bot, int $cid, array $worn, array $release, array $return_slots, ?int $leaving_slot = null): array {
    $moves = [];
    $bot_id = (int) $bot['id'];
    $gifts = array_values(array_filter($release, fn($s) => is_owner_gift($worn[$s]['inst_custom_data'] ?? '', $cid)));
    $bound = array_values(array_diff($release, $gifts));

    if ($bound) require_vault();
    if (count($gifts) > count($return_slots)) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail(400, 'inventory_full', 'Your general inventory (slots 23-32) has no room for what comes back.');
    }

    foreach ($release as $s) {
        $inst = inst_from_bot_row($worn[$s]);
        if (in_array($s, $gifts, true)) {
            $item = load_item($inst['item_id']);
            if ($item && player_lore_conflict($pdo, $cid, $item, $leaving_slot)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                fail(400, 'lore_conflict', "You already have {$item['Name']}, which is lore; it can't come back to you.");
            }
            [$ps, $exists] = array_shift($return_slots);
            $inst['custom_data'] = clear_owner_gift($inst['custom_data']);
            put_player_item($pdo, $cid, $ps, $inst, $exists);
            $moves[] = ['slot' => $s, 'item_id' => $inst['item_id'], 'to' => 'owner', 'player_slot' => $ps];
        } else {
            vault_insert($pdo, $bot_id, $inst);
            $moves[] = ['slot' => $s, 'item_id' => $inst['item_id'], 'to' => 'vault'];
        }
        $pdo->prepare('DELETE FROM bot_inventories WHERE bot_id = :bid AND slot_id = :s')
            ->execute([':bid' => $bot_id, ':s' => $s]);
        if ($s === SLOT_RANGE || $s === SLOT_AMMO) {
            // As SetBotArcherySetting(false, true) in game.
            $pdo->prepare('UPDATE bot_data SET archery_setting = 0 WHERE bot_id = :bid')
                ->execute([':bid' => $bot_id]);
        }
    }
    return $moves;
}
