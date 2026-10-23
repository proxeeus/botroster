# Bot Roster

A player-facing companion journal for managing your EQEmu bots — inventory
handoffs and spell settings, from any browser, without needing to be in-game.

Not an admin tool. It only touches bots and characters owned by the
authenticated login-server account.

## Requirements

- XAMPP (or any Apache + PHP 7.4+ + MySQL/MariaDB stack)
- The **sodium** PHP extension (only if your loginserver uses Argon2 or SCrypt
  — see [Loginserver encryption modes](#loginserver-encryption-modes) below)
- Read/write access to the EQEmu database

## Install

1. Clone (or copy) this folder into your Apache document root, e.g.
   `C:\xampp\htdocs\botroster\`.
2. Copy `api/config.example.php` → `api/config.php` and edit it:
   - `db.*` — your MySQL host/port/user/password/name.
   - `encryption_mode` — must match `security.mode` in your loginserver's
     `login.json`. Common values: `5` (SHA1), `9` (SHA512), `13` (Argon2),
     `14` (SCrypt).
   - `source_loginserver` — either `null` (auto-detect) or the exact string
     stored in `login_accounts.source_loginserver` (`"local"` for a private
     server, `"eqemu"` for the public EQEmu login server).
   - `session_secret` — any long random string. Rotate to invalidate every
     logged-in session.
3. Browse to `http://localhost/botroster/` and log in with your loginserver
   account credentials.

## Loginserver encryption modes

Modes 1–12 (MD5, SHA1, SHA512 variants) work out of the box. Modes 13
(Argon2) and 14 (SCrypt) need the `sodium` PHP extension:

1. Edit `C:\xampp\php\php.ini` and uncomment (or add) `extension=sodium`.
2. On Windows, copy `C:\xampp\php\libsodium.dll` into `C:\xampp\apache\bin\`
   so Apache can find it at load time. (CLI PHP finds it automatically; the
   Apache module doesn't share the same DLL search path.)
3. Restart Apache from the XAMPP control panel.

To confirm it worked, look for `PHP Startup: Unable to load dynamic library
'sodium'` in `C:\xampp\apache\logs\error.log` — if the line is gone, sodium
is loaded.

## What it does

- **Login** using your loginserver account (`login_accounts` table).
- **Character picker** — every character on that account.
- **Bot roster** — every bot owned by the selected character.
- **Equipment** — click an item in your bags to select it, then click a slot
  on the bot's paperdoll. Slot, class, race, level, secondary-hand (Dual
  Wield, shield) and lore rules are enforced server-side, the same checks as
  the game's `^vaultequip`. A two-hander in the primary also takes the
  secondary off.
- **Bot vault** — follows the server's anti-exploit rule (`bot_vault`, Server
  PR#92). An item you hand over is tagged as *your gift*
  (`bot_gift^<character id>` in its custom data) and comes back to your bags
  when taken off or replaced. Everything else on a bot (gear a PlayerBot was
  generated with, quest rewards) is *bot-bound*: it goes into that bot's
  vault, never to a player. The paperdoll marks each item `yours` or
  `bound`; the Vault panel lists the vault, and clicking a vault item then
  a slot puts it back on. Without the `bot_vault` table
  (`2026_10_20_bots_bot_vault.sql`) every move that would vault an item is
  refused.
- **Spellbook** — every spell entry for the bot's class up to its level (from
  `bot_spells_entries`), with per-bot overrides for enabled/priority/HP
  thresholds (upserted into `bot_spell_settings`). `reset` on a row deletes
  the override so defaults take over again. Entries the server never loads
  are hidden unless you tick *Show spells never cast*: ids past 2010 (the
  v29c client has no such spell) and ranks the bot has outgrown.
- **Tactics line** — the character's saved `^tactics` overrides (main tank,
  CH chain, auto-pull), read from the `bot_tactics_<character id>` data
  bucket. Read-only: zones cache the bucket, so change it in game.

## What it does NOT do

- Notify a live bot in-game. Changes take effect the next time the bot
  spawns (log the character out or `^botspawn` again to see them). Move
  gear while the character is camped: a spawned bot still holds its old
  gear in memory, and the game would hand a returned gift out a second time.
- Move items into or out of bags-inside-bags. The equip flow only handles
  worn ↔ top-level bag slots. Move things around in-game first if needed.
- Touch coin, shared bank, augment sockets, or ornament graphics.
- Anything for characters or bots that aren't owned by the logged-in account.
  Ownership is checked on every write.

## Endpoints (for curious debuggers)

All under `/api/`. Every non-login endpoint requires the `botroster_session`
cookie set by `login.php`.

| Endpoint | Methods | Purpose |
|---|---|---|
| `login.php` | POST | `{account, password}` → sets session cookie |
| `logout.php` | POST | clears cookie |
| `me.php` | GET | current auth + selected character |
| `characters.php` | GET | list characters for this account |
| `select_character.php` | POST | `{character_id}` → pin to session |
| `bots.php` | GET | list bots owned by selected character |
| `player_inventory.php` | GET | selected character's `inventory` rows |
| `bot_inventory.php` | GET, POST | GET: bot equipment + vault. POST: `{action: "equip"\|"unequip"\|"vault_equip", ...}` |
| `roster.php` | GET, POST | `bot_raid_roster` (the `^raidcreate` list): `{bot_id, action: "add"\|"remove"}` |
| `bot_spells.php` | GET, POST | GET: spell entries + overrides. POST: `{action: "update"\|"reset", ...}` |

## Files

```
botroster/
├── index.html              SPA shell
├── css/style.css           RP-themed styling (parchment + wax seal)
├── js/app.js               vanilla JS SPA (no framework)
├── api/
│   ├── bootstrap.php       common: config, db, json helpers
│   ├── errors.php          error → JSON handler
│   ├── session.php         signed-cookie sessions (HMAC-SHA256)
│   ├── auth.php            eqcrypt_verify_hash port (modes 1-14)
│   ├── slots.php           EQ slot constants + class/race/slot validation
│   ├── ownership.php       "does this belong to my account?" gate
│   ├── bot_vault.php       owner gift vs bot-bound gear, equip rules
│   ├── config.example.php  template
│   ├── config.php          real config (gitignored)
│   └── *.php               one file per endpoint
├── .htaccess               blocks web access to config.php + docs
└── README.md
```

## Security notes

- Sessions are signed cookies — no server-side session files.
  Rotating `session_secret` in `config.php` logs everyone out immediately.
- The `session_secret` is loaded from a plain PHP file. Anyone with
  filesystem read access to your server can forge sessions; keep the file
  outside the docroot or lock down its permissions if that matters.
- `.htaccess` denies web access to `config.php`, `.gitignore`, and `*.md`.
- Every write endpoint calls `assert_bot_owned()` / `assert_character_owned()`
  against the session's `account_id`. A logged-in player cannot poke
  another player's data by supplying a foreign id.
- HTTPS is strongly recommended. When behind HTTPS the session cookie is
  automatically marked `Secure`.

## License

Do whatever you want with it. Not affiliated with Daybreak or Verant.
