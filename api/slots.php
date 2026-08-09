<?php
/**
 * EQEmu inventory slot constants and item validation helpers.
 *
 * Slot layout (RoF2 numbering, matches character_inventory.slotid):
 *   0-22    Worn equipment  (0=Charm ... 22=Ammo)
 *   23-32   Main inventory / general slots (10 slots)
 *   33      Cursor
 *   251-340 Bag contents. For general slot N, contents live at 251+10*(N-23) .. +9.
 *   2000-2023  Main bank slots (24 slots)
 *   2031-2270  Bank bag contents (for bank slot 2000..2023 -> 2031+10*(N-2000))
 *   2500-2501  Shared bank main
 *   2531-2550  Shared bank bag contents
 */

const SLOT_CHARM = 0;
const SLOT_HEAD = 2;
const SLOT_PRIMARY = 13;
const SLOT_SECONDARY = 14;
const SLOT_AMMO = 22;

const SLOT_GENERAL_FIRST = 23;
const SLOT_GENERAL_LAST  = 32;
const SLOT_CURSOR        = 33;

const SLOT_BAG_BASE_INV   = 251;   // 23 -> 251..260, 24 -> 261..270, ...
const SLOT_BANK_FIRST     = 2000;
const SLOT_BANK_LAST      = 2023;
const SLOT_BANK_BAG_BASE  = 2031;
const SLOT_SHARED_BANK_FIRST = 2500;
const SLOT_SHARED_BANK_LAST  = 2501;

const EQUIPMENT_SLOT_NAMES = [
    0  => 'Charm',
    1  => 'Left Ear',
    2  => 'Head',
    3  => 'Face',
    4  => 'Right Ear',
    5  => 'Neck',
    6  => 'Shoulders',
    7  => 'Arms',
    8  => 'Back',
    9  => 'Left Wrist',
    10 => 'Right Wrist',
    11 => 'Range',
    12 => 'Hands',
    13 => 'Primary',
    14 => 'Secondary',
    15 => 'Left Finger',
    16 => 'Right Finger',
    17 => 'Chest',
    18 => 'Legs',
    19 => 'Feet',
    20 => 'Waist',
    21 => 'Power Source',
    22 => 'Ammo',
];

function slot_location(int $slot): string {
    if ($slot >= 0 && $slot <= 22) return 'worn';
    if ($slot >= SLOT_GENERAL_FIRST && $slot <= SLOT_GENERAL_LAST) return 'general';
    if ($slot === SLOT_CURSOR) return 'cursor';
    if ($slot >= SLOT_BAG_BASE_INV && $slot < SLOT_BAG_BASE_INV + 100) return 'bag';
    if ($slot >= SLOT_BANK_FIRST && $slot <= SLOT_BANK_LAST) return 'bank';
    if ($slot >= SLOT_BANK_BAG_BASE && $slot < SLOT_BANK_BAG_BASE + 250) return 'bank_bag';
    if ($slot >= SLOT_SHARED_BANK_FIRST && $slot <= SLOT_SHARED_BANK_LAST) return 'shared_bank';
    return 'other';
}

/** For a bag-content slot, return the parent general slot (or null). */
function parent_slot(int $slot): ?int {
    if ($slot >= SLOT_BAG_BASE_INV && $slot < SLOT_BAG_BASE_INV + 100) {
        return SLOT_GENERAL_FIRST + intdiv($slot - SLOT_BAG_BASE_INV, 10);
    }
    if ($slot >= SLOT_BANK_BAG_BASE && $slot < SLOT_BANK_BAG_BASE + 250) {
        return SLOT_BANK_FIRST + intdiv($slot - SLOT_BANK_BAG_BASE, 10);
    }
    return null;
}

/** Returns true if the item can be equipped in $slot (per items.slots bitmask). */
function item_fits_slot(array $item, int $slot): bool {
    if ($slot < 0 || $slot > 22) return false;
    $bit = 1 << $slot;
    return ((int) ($item['slots'] ?? 0) & $bit) !== 0;
}

/** Returns true if the item is usable by the given class (1-16). */
function item_allows_class(array $item, int $class): bool {
    if ($class < 1 || $class > 16) return false;
    $bit = 1 << ($class - 1);
    return ((int) ($item['classes'] ?? 0) & $bit) !== 0;
}

/** Returns true if the item is usable by the given race. Race id is a
 *  1..N ordinal used by the items.races bitmask (Human=1, Barbarian=2 etc.). */
function item_allows_race(array $item, int $race): bool {
    // The items.races bitmask uses the compact base-race ordering that
    // matches Mob::GetPlayerRaceBit(). Fully expanded races (drakkin, froglok
    // etc.) fall back to their base race for the bit test.
    $bit_index = base_race_bit_index($race);
    if ($bit_index === null) return false;
    return ((int) ($item['races'] ?? 0) & (1 << $bit_index)) !== 0;
}

/** Maps player_race -> bitmask index (0-based) used by items.races.
 *  Mirrors Mob::GetPlayerRaceBit in Server/zone/mob.cpp. */
function base_race_bit_index(int $race): ?int {
    switch ($race) {
        case 1:   return 0;   // Human
        case 2:   return 1;   // Barbarian
        case 3:   return 2;   // Erudite
        case 4:   return 3;   // Wood Elf
        case 5:   return 4;   // High Elf
        case 6:   return 5;   // Dark Elf
        case 7:   return 6;   // Half Elf
        case 8:   return 7;   // Dwarf
        case 9:   return 8;   // Troll
        case 10:  return 9;   // Ogre
        case 11:  return 10;  // Halfling
        case 12:  return 11;  // Gnome
        case 128: return 12;  // Iksar
        case 130: return 13;  // Vah Shir
        case 330: return 14;  // Froglok
        case 522: return 15;  // Drakkin
    }
    return null;
}

const CLASS_NAMES = [
    1 => 'Warrior', 2 => 'Cleric', 3 => 'Paladin', 4 => 'Ranger',
    5 => 'Shadow Knight', 6 => 'Druid', 7 => 'Monk', 8 => 'Bard',
    9 => 'Rogue', 10 => 'Shaman', 11 => 'Necromancer', 12 => 'Wizard',
    13 => 'Magician', 14 => 'Enchanter', 15 => 'Beastlord', 16 => 'Berserker',
];

const RACE_NAMES = [
    1 => 'Human', 2 => 'Barbarian', 3 => 'Erudite', 4 => 'Wood Elf',
    5 => 'High Elf', 6 => 'Dark Elf', 7 => 'Half Elf', 8 => 'Dwarf',
    9 => 'Troll', 10 => 'Ogre', 11 => 'Halfling', 12 => 'Gnome',
    128 => 'Iksar', 130 => 'Vah Shir', 330 => 'Froglok', 522 => 'Drakkin',
];

function class_name(int $c): string { return CLASS_NAMES[$c] ?? "Class#$c"; }
function race_name(int $r): string  { return RACE_NAMES[$r]  ?? "Race#$r"; }
