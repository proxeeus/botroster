<?php
/**
 * Item rows for the UI (paperdoll, bags, vault, tooltip), keyed by item id.
 * Effects are spell ids; their names come from spells_new.
 *   worneffect    while worn (Ultravision, See Invisible...); haste is its own column
 *   clickeffect   clicktype 1/5 any slot, 3 expendable, 4 must be equipped
 *   proceffect    combat proc on a weapon (procrate is a modifier, 0 = normal)
 *   focuseffect   spell focus
 */

function fetch_items_by_id(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT i.id, i.Name, i.icon, i.itemtype, i.classes, i.races, i.slots, i.ac, i.hp, i.mana,
                i.damage, i.delay, i.nodrop, i.magic, i.loregroup, i.reqlevel, i.haste,
                i.worneffect, sw.name AS worn_name,
                i.clickeffect, i.clicktype, i.clicklevel, i.maxcharges, i.casttime, sc.name AS click_name,
                i.proceffect, i.procrate, sp.name AS proc_name,
                i.focuseffect, sf.name AS focus_name
         FROM items i
         LEFT JOIN spells_new sw ON sw.id = i.worneffect  AND i.worneffect  > 0
         LEFT JOIN spells_new sc ON sc.id = i.clickeffect AND i.clickeffect > 0
         LEFT JOIN spells_new sp ON sp.id = i.proceffect  AND i.proceffect  > 0
         LEFT JOIN spells_new sf ON sf.id = i.focuseffect AND i.focuseffect > 0
         WHERE i.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $it) $out[(int) $it['id']] = $it;
    return $out;
}
