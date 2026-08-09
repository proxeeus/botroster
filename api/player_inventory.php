<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/slots.php';

$s = require_auth();
$cid = (int) ($s['character_id'] ?? 0);
if ($cid <= 0) fail(400, 'no_character_selected', 'Select a character first.');
assert_character_owned($s['account_id'], $cid);

// Pull every inventory row the character owns. We include shared_bank if
// eqemu keeps it as separate rows -- some setups store shared bank on the
// account row, in which case we just get empty and move on.
$stmt = db()->prepare(
    'SELECT slotid, itemid, charges, color, instnodrop,
            augslot1, augslot2, augslot3, augslot4, augslot5, augslot6
     FROM inventory WHERE charid = :cid ORDER BY slotid ASC'
);
$stmt->execute([':cid' => $cid]);
$rows = $stmt->fetchAll();

// Collect item ids for a single batch lookup.
$item_ids = [];
foreach ($rows as $r) {
    if ((int) $r['itemid'] > 0) $item_ids[(int) $r['itemid']] = true;
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
    foreach ($stmt->fetchAll() as $it) {
        $items_by_id[(int) $it['id']] = $it;
    }
}

// Shape the response: keep raw slotid + computed location + parent_slot; the
// UI groups by these to render worn / general / bags / bank sections.
$out = [];
foreach ($rows as $r) {
    $slot = (int) $r['slotid'];
    $out[] = [
        'slot'        => $slot,
        'location'    => slot_location($slot),
        'parent_slot' => parent_slot($slot),
        'item_id'     => (int) $r['itemid'],
        'charges'     => (int) $r['charges'],
        'color'       => (int) $r['color'],
        'no_drop'     => (int) $r['instnodrop'],
        'augments'    => array_values(array_filter([
            (int) $r['augslot1'], (int) $r['augslot2'], (int) $r['augslot3'],
            (int) $r['augslot4'], (int) $r['augslot5'], (int) $r['augslot6'],
        ])),
    ];
}

json_out(['items' => $out, 'items_by_id' => $items_by_id]);
