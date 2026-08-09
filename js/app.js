/* ============================================================
   Bot Roster - vanilla JS SPA.
   Talks to /api/*.php endpoints. State kept in module-level vars.
   ============================================================ */

'use strict';

// --- App state ---------------------------------------------------------
const state = {
    account_id: null,
    character: null,
    bots: [],
    selected_bot: null,
    bot_equipment: [],
    bot_items: {},        // item_id -> item row (for currently-viewed bot)
    player_items: [],     // full inventory rows
    player_items_by_id: {},
    equipment_selected_source: null, // slot in player inventory
    active_tab: 'equipment',
    active_inv_tab: 'worn',
    spells: [],
    spell_filter: '',
    spell_sort: 'level_asc',
    only_overrides: false,
};

// --- EQ visual helpers ------------------------------------------------
// charbrowser packs every 40x40 icon into 10x10 spritesheets, one file per
// 100 icons starting at icon id 500. We reference them directly from
// /charbrowser/images/ rather than duplicating the ~18 MB of PNGs into
// botroster's repo.
const CHARBROWSER_ROOT = '/charbrowser/images';

function itemIconStyle(iconId) {
    const n = +iconId;
    if (!n || n < 500) return '';
    const sheet  = Math.floor((n - 500) / 100) + 1;
    const offset = (n - 500) % 100;
    const col    = Math.floor(offset / 10);
    const row    = offset % 10;
    return `background-image: url('${CHARBROWSER_ROOT}/itemsheets/${sheet}.png'); ` +
           `background-position: ${-col * 40}px ${-row * 40}px;`;
}

function itemIconHtml(item, extraClass = '') {
    if (!item || !+item.icon) return '';
    return `<span class="eq-icon ${extraClass}" style="${itemIconStyle(item.icon)}"></span>`;
}

// race+gender+face -> PNG in charbrowser's faces dir.
// Falls back to face 1 if the requested face is out of range.
function facePath(race, gender, face) {
    const r = +race || 1, g = +gender || 0, f = +face || 1;
    return `${CHARBROWSER_ROOT}/faces/race_${r}_gender_${g}_face_${f}.png`;
}
function avatarHtml({ race, gender, face }, { self = false, klass = '' } = {}) {
    const src = facePath(race, gender, face);
    // onerror falls back to a generic Human face if the specific PNG is missing
    // (e.g. bots with face 0 or Drakkin variants not shipped by charbrowser).
    return `
        <span class="eq-avatar-stack ${self ? 'self' : ''} ${klass}">
            <span class="avatar-face" style="background-image: url('${src}');"
                  onerror="this.style.backgroundImage='url(${CHARBROWSER_ROOT}/faces/race_1_gender_0_face_1.png)'"></span>
            <span class="avatar-frame"></span>
        </span>`;
}

// --- API layer ---------------------------------------------------------
async function api(url, opts = {}) {
    const init = {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    };
    if (opts.body) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(opts.body);
    }
    const res = await fetch('api/' + url, init);
    let data;
    try { data = await res.json(); }
    catch (e) { throw new Error('Bad response from server (not JSON). Status ' + res.status); }
    if (!res.ok) {
        const msg = data.message || data.error || ('HTTP ' + res.status);
        const err = new Error(msg);
        err.data = data;
        err.status = res.status;
        throw err;
    }
    return data;
}

// --- Toast helpers -----------------------------------------------------
function toast(msg, kind = '') {
    const host = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'toast ' + kind;
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; }, 3200);
    setTimeout(() => { el.remove(); }, 3600);
}

// --- View switching ----------------------------------------------------
function showView(id) {
    document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
}

// --- Boot --------------------------------------------------------------
async function boot() {
    try {
        const me = await api('me.php');
        if (!me.authenticated) return showLogin();
        state.account_id = me.account_id;
        if (me.character) {
            state.character = me.character;
            await enterMain();
        } else {
            await showCharacterSelect();
        }
    } catch (e) {
        console.error(e);
        showLogin();
    }
}

// --- Login view --------------------------------------------------------
function showLogin() {
    showView('view-login');
    document.getElementById('login-error').textContent = '';
    const form = document.getElementById('login-form');
    form.onsubmit = async (ev) => {
        ev.preventDefault();
        const acct = form.account.value.trim();
        const pass = form.password.value;
        form.querySelector('button').disabled = true;
        try {
            const r = await api('login.php', { method: 'POST', body: { account: acct, password: pass } });
            state.account_id = r.account_id;
            form.password.value = '';
            await showCharacterSelect();
        } catch (e) {
            document.getElementById('login-error').textContent = e.message;
        } finally {
            form.querySelector('button').disabled = false;
        }
    };
}

// --- Character select --------------------------------------------------
async function showCharacterSelect() {
    showView('view-characters');
    const list = document.getElementById('character-list');
    list.innerHTML = '<li class="dim">Consulting the ledger…</li>';
    try {
        const { characters } = await api('characters.php');
        if (!characters.length) {
            list.innerHTML = '<li class="dim">No characters found on this account.</li>';
            return;
        }
        list.innerHTML = '';
        for (const c of characters) {
            const li = document.createElement('li');
            li.innerHTML = `
                ${avatarHtml(c, { self: true })}
                <div class="char-info">
                    <div class="char-title">${escapeHtml(c.name)}</div>
                    <div class="char-sub">Level ${c.level} ${escapeHtml(c.race_name)} ${escapeHtml(c.class_name)}</div>
                </div>
                <div class="dim">›</div>
            `;
            li.addEventListener('click', () => selectCharacter(c));
            list.appendChild(li);
        }
    } catch (e) {
        list.innerHTML = `<li class="error">${escapeHtml(e.message)}</li>`;
    }
}

async function selectCharacter(c) {
    await api('select_character.php', { method: 'POST', body: { character_id: c.id } });
    state.character = c;
    await enterMain();
}

// --- Main view ---------------------------------------------------------
async function enterMain() {
    showView('view-main');
    document.getElementById('char-name').textContent = state.character.name;
    document.getElementById('char-title').textContent =
        `Level ${state.character.level} ${state.character.race_name || raceName(state.character.race)} ${state.character.class_name || className(state.character.class)}`;
    document.getElementById('char-avatar-host').innerHTML =
        avatarHtml(state.character, { self: true });

    await loadBots();
}

async function loadBots() {
    const list = document.getElementById('bot-list');
    const empty = document.getElementById('bot-empty');
    list.innerHTML = '<li class="dim">Summoning roster…</li>';
    try {
        const { bots } = await api('bots.php');
        state.bots = bots;
        list.innerHTML = '';
        if (!bots.length) {
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        for (const b of bots) {
            const li = document.createElement('li');
            li.dataset.botId = b.id;
            li.innerHTML = `
                ${avatarHtml(b)}
                <div class="bot-info">
                    <div class="bot-name">${escapeHtml(b.name)}</div>
                    <div class="bot-meta">Lv ${b.level} ${escapeHtml(b.race_name)} ${escapeHtml(b.class_name)}</div>
                </div>
            `;
            li.addEventListener('click', () => selectBot(b));
            list.appendChild(li);
        }
    } catch (e) {
        list.innerHTML = `<li class="error">${escapeHtml(e.message)}</li>`;
    }
}

async function selectBot(b) {
    state.selected_bot = b;
    document.querySelectorAll('.bot-list li').forEach(li => li.classList.toggle('active', +li.dataset.botId === b.id));
    document.getElementById('detail-placeholder').classList.add('hidden');
    document.getElementById('detail-content').classList.remove('hidden');
    // Rebuild the header so the avatar slot is refreshed with this bot's face.
    const header = document.querySelector('.bot-header > div:first-child');
    header.innerHTML = `
        ${avatarHtml(b)}
        <div>
            <h2 id="bot-name">${escapeHtml(b.name)}</h2>
            <p class="dim" id="bot-subtitle">Level ${b.level} ${escapeHtml(b.race_name)} ${escapeHtml(b.class_name)}</p>
        </div>`;
    setTab(state.active_tab);
}

function setTab(tab) {
    state.active_tab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.getElementById('tab-equipment').classList.toggle('hidden', tab !== 'equipment');
    document.getElementById('tab-spells').classList.toggle('hidden', tab !== 'spells');
    if (tab === 'equipment') loadEquipmentTab();
    else if (tab === 'spells') loadSpellsTab();
}

// --- Equipment tab -----------------------------------------------------
async function loadEquipmentTab() {
    if (!state.selected_bot) return;
    await Promise.all([loadBotEquipment(), loadPlayerInventory()]);
    renderPaperdoll();
    renderPlayerInventory();
}

async function loadBotEquipment() {
    const { equipment, items_by_id } = await api(`bot_inventory.php?bot_id=${state.selected_bot.id}`);
    state.bot_equipment = equipment;
    state.bot_items = items_by_id;
}

async function loadPlayerInventory() {
    const { items, items_by_id } = await api('player_inventory.php');
    state.player_items = items;
    state.player_items_by_id = items_by_id;
    // Merge into one big items pool for tooltip / paperdoll lookups.
    Object.assign(state.bot_items, items_by_id);
}

function renderPaperdoll() {
    const pd = document.getElementById('bot-paperdoll');
    const equippedBySlot = {};
    for (const e of state.bot_equipment) equippedBySlot[e.slot] = e;

    // ALL 23 equipment slots, in slotid order. Grid areas from CSS take care
    // of visual placement -- we just have to emit one div per slot plus the
    // central avatar cell.
    pd.innerHTML = '';
    for (let slotId = 0; slotId <= 22; slotId++) {
        const eq = equippedBySlot[slotId];
        const item = eq ? state.bot_items[eq.item_id] : null;
        const div = document.createElement('div');
        div.className = `slot eq-slot-${slotId}` + (eq ? '' : ' empty');
        div.dataset.slotId = slotId;
        const label = EQUIP_LABEL[slotId] || '';
        const innerCls = 'slot-inner' + (item ? '' : ` slot-silhouette slot-silhouette-${slotId}`);
        const iconStyle = item ? itemIconStyle(item.icon) : '';
        div.innerHTML = `
            <div class="${innerCls}"
                 title="${item ? escapeHtml(item.Name) : label}"
                 ${iconStyle ? `style="${iconStyle}"` : ''}>
            </div>
            <span class="slot-label">${label}</span>
            ${eq && eq.no_drop ? '<span class="nodrop-badge">nd</span>' : ''}`;
        if (item) {
            div.addEventListener('mouseenter', (ev) => showTooltip(ev, item));
            div.addEventListener('mouseleave', hideTooltip);
        }
        div.addEventListener('click', () => onPaperdollClick(slotId));
        pd.appendChild(div);
    }
    // Central avatar cell -- lives inside the grid area "av".
    const av = document.createElement('div');
    av.className = 'eq-avatar';
    av.innerHTML = state.selected_bot ? avatarHtml(state.selected_bot) : '';
    pd.appendChild(av);
}

function renderPlayerInventory() {
    const host = document.getElementById('player-inventory');
    const filter = state.active_inv_tab;

    // "Worn" gets the paperdoll treatment so it mirrors the bot column and
    // reads as a character-sheet rather than a pile of bag rows.
    if (filter === 'worn') {
        const worn = state.player_items.filter(i => i.location === 'worn');
        document.getElementById('inv-count').textContent = `(${worn.length})`;
        renderPlayerPaperdoll(host, worn);
        return;
    }

    // Bags / bank keep the icon-grid.
    host.className = 'inventory-grid';
    let filtered;
    if (filter === 'general') filtered = state.player_items.filter(i => i.location === 'general' || i.location === 'bag' || i.location === 'cursor');
    else if (filter === 'bank') filtered = state.player_items.filter(i => i.location === 'bank' || i.location === 'bank_bag' || i.location === 'shared_bank');
    else filtered = state.player_items;

    document.getElementById('inv-count').textContent = `(${filtered.length})`;

    host.innerHTML = '';
    if (!filtered.length) {
        host.innerHTML = '<div class="dim" style="grid-column: 1/-1; text-align:center; padding:20px;">Nothing here.</div>';
        return;
    }
    for (const inv of filtered) {
        const item = state.player_items_by_id[inv.item_id];
        if (!item) continue;
        const div = document.createElement('div');
        div.className = 'inv-item' + (inv.no_drop ? ' nodrop' : '');
        if (state.equipment_selected_source === inv.slot) div.classList.add('selected');
        const slotLabel = inv.location === 'worn' ? (equipSlotLabel(inv.slot) || 'worn') :
                          inv.location === 'bag' ? `bag → ${inv.parent_slot}` :
                          inv.location === 'general' ? `bag ${inv.slot - 22}` :
                          inv.location === 'bank' ? `bank ${inv.slot - 1999}` :
                          inv.location;
        div.innerHTML = `
            <div class="eq-icon-wrap">${itemIconHtml(item)}</div>
            <div class="item-info">
                <div class="item-name">${escapeHtml(item.Name)}</div>
                <div class="item-meta">${slotLabel}</div>
            </div>
        `;
        div.addEventListener('mouseenter', (ev) => showTooltip(ev, item, state.selected_bot));
        div.addEventListener('mouseleave', hideTooltip);
        div.addEventListener('click', () => onInventoryClick(inv, item));
        host.appendChild(div);
    }
}

// Renders the player's worn equipment as a paperdoll matching the bot side.
// Click on an occupied slot = same as clicking that inv-item row (source
// select for equip). Click on an empty slot is a no-op.
function renderPlayerPaperdoll(host, wornItems) {
    host.className = 'eq-paperdoll player-paperdoll';
    host.innerHTML = '';
    const bySlot = {};
    for (const w of wornItems) bySlot[w.slot] = w;

    for (let slotId = 0; slotId <= 22; slotId++) {
        const inv = bySlot[slotId];
        const item = inv ? state.player_items_by_id[inv.item_id] : null;
        const div = document.createElement('div');
        div.className = `slot eq-slot-${slotId}` + (inv ? '' : ' empty');
        if (inv && state.equipment_selected_source === inv.slot) div.classList.add('selected');
        const label = EQUIP_LABEL[slotId] || '';
        const innerCls = 'slot-inner' + (item ? '' : ` slot-silhouette slot-silhouette-${slotId}`);
        const iconStyle = item ? itemIconStyle(item.icon) : '';
        div.innerHTML = `
            <div class="${innerCls}"
                 title="${item ? escapeHtml(item.Name) : label}"
                 ${iconStyle ? `style="${iconStyle}"` : ''}>
            </div>
            <span class="slot-label">${label}</span>
            ${inv && inv.no_drop ? '<span class="nodrop-badge">nd</span>' : ''}`;
        if (item) {
            div.addEventListener('mouseenter', (ev) => showTooltip(ev, item, state.selected_bot));
            div.addEventListener('mouseleave', hideTooltip);
            div.addEventListener('click', () => onInventoryClick(inv, item));
        }
        host.appendChild(div);
    }
    // Character avatar in the middle -- mirrors the bot paperdoll layout.
    const av = document.createElement('div');
    av.className = 'eq-avatar';
    av.innerHTML = state.character ? avatarHtml(state.character, { self: true }) : '';
    host.appendChild(av);
}

function onInventoryClick(inv, item) {
    if (!state.selected_bot) return;
    if (inv.no_drop) {
        toast('That item is no-drop; it cannot be handed to a bot.', 'error');
        return;
    }
    // Two-click flow: first click selects the source, second click on a bot
    // slot equips. Second click on the same item cancels.
    if (state.equipment_selected_source === inv.slot) {
        state.equipment_selected_source = null;
    } else {
        state.equipment_selected_source = inv.slot;
        const suggested = suggestSlot(item);
        if (suggested !== null) {
            toast(`Selected ${item.Name}. Click a highlighted slot on the paperdoll to equip it.`, 'success');
        } else {
            toast(`${item.Name} cannot be equipped by ${state.selected_bot.name}.`, 'error');
            state.equipment_selected_source = null;
        }
    }
    renderPlayerInventory();
    highlightPaperdollForItem();
}

function suggestSlot(item) {
    const slots = +item.slots;
    for (let i = 0; i <= 22; i++) {
        if (slots & (1 << i)) {
            if (!itemAllowsBot(item)) return null;
            return i;
        }
    }
    return null;
}

function itemAllowsBot(item) {
    if (!state.selected_bot) return false;
    const cls = +state.selected_bot.class;
    if (cls >= 1 && cls <= 16 && !(item.classes & (1 << (cls - 1)))) return false;
    const raceBit = baseRaceBit(+state.selected_bot.race);
    if (raceBit === null || !(item.races & (1 << raceBit))) return false;
    return true;
}

function highlightPaperdollForItem() {
    const source = state.equipment_selected_source;
    document.querySelectorAll('.paperdoll .slot').forEach(el => {
        el.style.boxShadow = '';
        el.style.borderColor = '';
    });
    if (source === null) return;
    const inv = state.player_items.find(i => i.slot === source);
    if (!inv) return;
    const item = state.player_items_by_id[inv.item_id];
    if (!item) return;
    for (let i = 0; i <= 22; i++) {
        if (item.slots & (1 << i)) {
            const el = document.querySelector(`.paperdoll .slot[data-slot-id="${i}"]`);
            if (el) {
                el.style.boxShadow = '0 0 10px rgba(184,147,90,0.9)';
                el.style.borderColor = 'var(--seal-red)';
            }
        }
    }
}

async function onPaperdollClick(slotId) {
    if (state.equipment_selected_source === null) {
        // Unequip flow: click occupied slot to send item back to your bags.
        const eq = state.bot_equipment.find(e => e.slot === slotId);
        if (!eq || !eq.item_id) return;
        const item = state.bot_items[eq.item_id];
        if (!confirm(`Return ${item ? item.Name : 'this item'} to your bags?`)) return;
        try {
            await api('bot_inventory.php', {
                method: 'POST',
                body: { bot_id: state.selected_bot.id, action: 'unequip', bot_slot: slotId }
            });
            toast('Returned to your bags.', 'success');
            await loadEquipmentTab();
        } catch (e) { toast(e.message, 'error'); }
        return;
    }
    // Equip flow.
    try {
        await api('bot_inventory.php', {
            method: 'POST',
            body: {
                bot_id: state.selected_bot.id,
                action: 'equip',
                source_slot: state.equipment_selected_source,
                target_slot: slotId,
            }
        });
        toast('Equipped.', 'success');
        state.equipment_selected_source = null;
        await loadEquipmentTab();
    } catch (e) {
        toast(e.message, 'error');
    }
}

// --- Spells tab --------------------------------------------------------
async function loadSpellsTab() {
    if (!state.selected_bot) return;
    const list = document.getElementById('spell-list');
    list.innerHTML = '<div class="dim" style="text-align:center; padding:20px;">Turning the pages…</div>';
    try {
        const { spells } = await api(`bot_spells.php?bot_id=${state.selected_bot.id}`);
        state.spells = spells;
        renderSpells();
    } catch (e) {
        list.innerHTML = `<div class="error">${escapeHtml(e.message)}</div>`;
    }
}

function renderSpells() {
    const list = document.getElementById('spell-list');
    const q = state.spell_filter.trim().toLowerCase();
    const shown = state.spells.filter(s => {
        if (state.only_overrides && !s.override) return false;
        if (q && !s.spell_name.toLowerCase().includes(q)) return false;
        return true;
    });
    shown.sort(spellComparator(state.spell_sort));

    if (!shown.length) {
        list.innerHTML = '<div class="dim" style="text-align:center; padding:20px;">No spells match.</div>';
        return;
    }

    list.innerHTML = '';
    for (const s of shown) {
        const row = document.createElement('div');
        row.className = 'spell-row' + (s.is_enabled ? '' : ' disabled') + (s.override ? ' override' : '');
        const levelLabel = levelRangeLabel(s.min_level, s.max_level);
        const typeLabel = spellTypeLabel(s.type);
        row.innerHTML = `
            <div class="spell-level" title="Levels ${s.min_level}–${s.max_level || '?'}">${levelLabel}</div>
            <div>
                <div class="spell-name">${escapeHtml(s.spell_name)}</div>
                <div class="spell-meta">
                    <span class="spell-type-tag">${escapeHtml(typeLabel)}</span>
                    <span>mana ${s.manacost}</span>
                    <span>recast ${(s.recast_delay/1000).toFixed(1)}s</span>
                </div>
            </div>
            <label class="toggle" title="Enable / disable">
                <input type="checkbox" ${s.is_enabled ? 'checked' : ''} data-field="is_enabled">
                on
            </label>
            <div>
                <span class="field-label">prio</span>
                <input type="number" value="${s.priority}" step="1" data-field="priority">
            </div>
            <div>
                <span class="field-label">HP%</span>
                <input type="number" value="${s.min_hp}" min="0" max="100" data-field="min_hp">
                –
                <input type="number" value="${s.max_hp}" min="0" max="100" data-field="max_hp">
            </div>
            <button class="reset-btn" ${s.override ? '' : 'disabled'} title="Remove custom setting">reset</button>
        `;

        // Debounced save-on-change.
        const inputs = row.querySelectorAll('input');
        let pending = null;
        const commit = () => {
            const payload = {
                bot_id: state.selected_bot.id,
                spell_id: s.spell_id,
                action: 'update',
                is_enabled: row.querySelector('[data-field="is_enabled"]').checked ? 1 : 0,
                priority:   +row.querySelector('[data-field="priority"]').value,
                min_hp:     +row.querySelector('[data-field="min_hp"]').value,
                max_hp:     +row.querySelector('[data-field="max_hp"]').value,
            };
            api('bot_spells.php', { method: 'POST', body: payload })
                .then(() => {
                    // Refresh internal state so override/reset reflect immediately.
                    s.override = { priority: payload.priority, min_hp: payload.min_hp,
                                   max_hp: payload.max_hp, is_enabled: payload.is_enabled };
                    Object.assign(s, payload);
                    row.classList.add('override');
                    row.classList.toggle('disabled', !payload.is_enabled);
                    row.querySelector('.reset-btn').disabled = false;
                })
                .catch(e => toast(e.message, 'error'));
        };
        inputs.forEach(inp => {
            inp.addEventListener('change', () => {
                clearTimeout(pending);
                pending = setTimeout(commit, 250);
            });
        });

        row.querySelector('.reset-btn').addEventListener('click', async () => {
            try {
                await api('bot_spells.php', {
                    method: 'POST',
                    body: { bot_id: state.selected_bot.id, spell_id: s.spell_id, action: 'reset' }
                });
                toast(`${s.spell_name} restored to default.`, 'success');
                loadSpellsTab();
            } catch (e) { toast(e.message, 'error'); }
        });

        list.appendChild(row);
    }
}

// Bit-position -> label. Mirrors SpellType_* in Server/common/spdat.h.
// bot_spells_entries.type is stored as a bitmask; usually one bit is set,
// but decoder handles combinations too.
const SPELL_TYPE_LABELS = [
    'Nuke','Heal','Root','Buff','Escape','Pet','Lifetap','Snare','DOT',
    'Dispel','InCombat Buff','Mez','Charm','Slow','Debuff','Cure',
    'Resurrect','Hate Redux','InCombat Song','OOC Song','PreCombat Buff',
    'PreCombat Song','Twitch',
];
function spellTypeLabel(mask) {
    const m = +mask;
    if (!m) return 'Untyped';
    const parts = [];
    for (let i = 0; i < SPELL_TYPE_LABELS.length; i++) {
        if (m & (1 << i)) parts.push(SPELL_TYPE_LABELS[i]);
    }
    // Unknown bits (future engine additions): show as trailing hex flag.
    const knownMask = (1 << SPELL_TYPE_LABELS.length) - 1;
    const extra = m & ~knownMask;
    if (extra) parts.push('0x' + extra.toString(16));
    return parts.join(' / ') || 'Untyped';
}
function levelRangeLabel(min, max) {
    if (!max || max >= 255 || max === min) return `L${min}`;
    return `L${min}–${max}`; // en-dash
}

function spellComparator(mode) {
    const byName = (a, b) => a.spell_name.localeCompare(b.spell_name);
    switch (mode) {
        case 'level_desc':
            return (a, b) => (b.min_level - a.min_level) || byName(a, b);
        case 'name_asc':
            return byName;
        case 'type_priority':
            return (a, b) => (a.type - b.type) || (a.priority - b.priority) || byName(a, b);
        case 'level_asc':
        default:
            return (a, b) => (a.min_level - b.min_level) || byName(a, b);
    }
}

// --- Tooltip -----------------------------------------------------------
const tooltipEl = document.getElementById('tooltip');
function showTooltip(ev, item, bot = null) {
    const iconHtml = itemIconHtml(item);
    const lines = [];
    if (item.itemtype != null) lines.push(`Type ${item.itemtype}`);
    if (+item.ac)     lines.push(`AC ${item.ac}`);
    if (+item.hp)     lines.push(`HP ${item.hp}`);
    if (+item.mana)   lines.push(`Mana ${item.mana}`);
    if (+item.damage) lines.push(`Dmg ${item.damage} / Delay ${item.delay || 0}`);
    if (+item.nodrop === 0) lines.push('NO DROP');

    // Slot summary from bitmask.
    const slotBits = +item.slots;
    const slotNames = [];
    for (let i = 0; i <= 22; i++) {
        if (slotBits & (1 << i)) slotNames.push(EQUIP_LABEL[i] || ('slot' + i));
    }

    // Class + race restriction summary.
    const cls = classesFromMask(+item.classes);
    const races = racesFromMask(+item.races);

    let botWarning = '';
    if (bot) {
        if (!itemAllowsBot(item)) {
            botWarning = `Cannot be used by ${bot.name} (${bot.race_name} ${bot.class_name}).`;
        }
    }

    tooltipEl.innerHTML = `
        ${iconHtml ? `<div class="t-icon-row">${iconHtml}<span class="t-name">${escapeHtml(item.Name)}</span></div>`
                   : `<span class="t-name">${escapeHtml(item.Name)}</span>`}
        ${lines.length ? `<div class="t-line">${lines.join(' · ')}</div>` : ''}
        ${slotNames.length ? `<div class="t-line">Slots: ${slotNames.join(', ')}</div>` : ''}
        ${cls.length ? `<div class="t-restrict">Classes: ${cls.join(', ')}</div>` : ''}
        ${races.length ? `<div class="t-restrict">Races: ${races.join(', ')}</div>` : ''}
        ${botWarning ? `<div class="t-warn">${botWarning}</div>` : ''}
    `;
    tooltipEl.classList.remove('hidden');
    positionTooltip(ev);
    document.addEventListener('mousemove', positionTooltip);
}
function hideTooltip() {
    tooltipEl.classList.add('hidden');
    document.removeEventListener('mousemove', positionTooltip);
}
function positionTooltip(ev) {
    const pad = 14;
    let x = ev.clientX + pad;
    let y = ev.clientY + pad;
    const rect = tooltipEl.getBoundingClientRect();
    if (x + rect.width > window.innerWidth)  x = ev.clientX - rect.width - pad;
    if (y + rect.height > window.innerHeight) y = ev.clientY - rect.height - pad;
    tooltipEl.style.left = x + 'px';
    tooltipEl.style.top  = y + 'px';
}

// --- Constants for display ---------------------------------------------
const EQUIP_LABEL = {
    0:'Charm', 1:'L Ear', 2:'Head', 3:'Face', 4:'R Ear', 5:'Neck',
    6:'Shoulders', 7:'Arms', 8:'Back', 9:'L Wrist', 10:'R Wrist',
    11:'Range', 12:'Hands', 13:'Primary', 14:'Secondary', 15:'L Finger',
    16:'R Finger', 17:'Chest', 18:'Legs', 19:'Feet', 20:'Waist',
    21:'Power', 22:'Ammo',
};
function equipSlotLabel(s) { return EQUIP_LABEL[s]; }

const CLASS_NAMES = {
    1:'War', 2:'Cle', 3:'Pal', 4:'Rng', 5:'Shd', 6:'Dru', 7:'Mnk', 8:'Brd',
    9:'Rog', 10:'Sha', 11:'Nec', 12:'Wiz', 13:'Mag', 14:'Enc', 15:'Bst', 16:'Ber',
};
function className(c) { return CLASS_NAMES[c] || ('Class' + c); }
function classesFromMask(m) {
    const out = [];
    for (let i = 0; i < 16; i++) if (m & (1 << i)) out.push(CLASS_NAMES[i + 1]);
    return out.length === 16 ? ['All'] : out;
}

const RACE_ORDER = [
    [1,'Hum'], [2,'Bar'], [3,'Eru'], [4,'WEl'], [5,'HEl'], [6,'DEl'],
    [7,'HfE'], [8,'Dwf'], [9,'Trl'], [10,'Ogr'], [11,'Hlf'], [12,'Gnm'],
    [128,'Iks'], [130,'Vah'], [330,'Frg'], [522,'Drk'],
];
function raceName(r) { const row = RACE_ORDER.find(x => x[0] === r); return row ? row[1] : ('Race' + r); }
function baseRaceBit(race) {
    const idx = RACE_ORDER.findIndex(x => x[0] === race);
    return idx >= 0 ? idx : null;
}
function racesFromMask(m) {
    const out = [];
    for (let i = 0; i < RACE_ORDER.length; i++) if (m & (1 << i)) out.push(RACE_ORDER[i][1]);
    return out.length === RACE_ORDER.length ? ['All'] : out;
}

// --- Small utils -------------------------------------------------------
function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}

// --- Session reset -----------------------------------------------------
// Wipe every per-session bit of state AND scrub the DOM so nothing from the
// previous character bleeds into the next view. Called on logout and on
// change-character.
function resetSessionUi({ keepAccount = false } = {}) {
    if (!keepAccount) state.account_id = null;
    state.character = null;
    state.bots = [];
    state.selected_bot = null;
    state.bot_equipment = [];
    state.bot_items = {};
    state.player_items = [];
    state.player_items_by_id = {};
    state.equipment_selected_source = null;
    state.spells = [];
    state.spell_filter = '';
    state.spell_sort = 'level_asc';
    state.only_overrides = false;
    // Force the detail panel back to its "pick a bot" state.
    document.getElementById('detail-content').classList.add('hidden');
    document.getElementById('detail-placeholder').classList.remove('hidden');
    document.getElementById('bot-list').innerHTML = '';
    document.getElementById('bot-empty').classList.add('hidden');
    document.getElementById('bot-paperdoll').innerHTML = '';
    document.getElementById('player-inventory').innerHTML = '';
    document.getElementById('spell-list').innerHTML = '';
    document.getElementById('bot-name').textContent = '--';
    document.getElementById('bot-subtitle').textContent = '--';
    document.getElementById('char-name').textContent = '--';
    document.getElementById('char-title').textContent = '--';
    const charAv = document.getElementById('char-avatar-host');
    if (charAv) charAv.innerHTML = '';
    document.getElementById('inv-count').textContent = '';
    const filterInput = document.getElementById('spell-filter');
    if (filterInput) filterInput.value = '';
    const onlyOverridesInput = document.getElementById('spell-only-overrides');
    if (onlyOverridesInput) onlyOverridesInput.checked = false;
    const sortInput = document.getElementById('spell-sort');
    if (sortInput) sortInput.value = 'level_asc';
    hideTooltip();
}

// --- Global event wiring ----------------------------------------------
document.addEventListener('click', async (ev) => {
    const t = ev.target;
    if (t.matches('[data-action="logout"]')) {
        await api('logout.php', { method: 'POST' }).catch(() => {});
        resetSessionUi();
        showLogin();
    }
    if (t.matches('[data-action="change-character"]')) {
        resetSessionUi({ keepAccount: true });
        showCharacterSelect();
    }
    if (t.matches('.tab')) setTab(t.dataset.tab);
    if (t.matches('.inv-tab')) {
        document.querySelectorAll('.inv-tab').forEach(x => x.classList.toggle('active', x === t));
        state.active_inv_tab = t.dataset.inv;
        renderPlayerInventory();
    }
});

document.addEventListener('input', (ev) => {
    if (ev.target.id === 'spell-filter') {
        state.spell_filter = ev.target.value;
        renderSpells();
    }
});
document.addEventListener('change', (ev) => {
    if (ev.target.id === 'spell-only-overrides') {
        state.only_overrides = ev.target.checked;
        renderSpells();
    }
    if (ev.target.id === 'spell-sort') {
        state.spell_sort = ev.target.value;
        renderSpells();
    }
});

boot();
