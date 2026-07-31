# expsite_data_media

Media demo content data pack for Exponential CMS (legacy), feature-ripped from `netgen/media-site-data`. It ships the demo site's database rows as a hand-maintained SQLite delta plus the matching binary storage payload, and an installer class that applies both on top of an existing installation.

## What is included

- `data/data.sql` — INSERT OR IGNORE delta of the demo content (default import; never overwrites existing rows).
- `data/data-replace.sql` — REPLACE variant of the same delta (conflict-overwriting import; opt-in).
- `data/storage/` — binary payload (`images/`, `original/`) merged into the site's storage directory.
- `classes/expsitedatamediainstaller.php` — `expSiteDataMediaInstaller`, applies the SQL via the `sqlite3` CLI and merges the binaries.
- `bin/php/install_data.php` — CLI entry point that runs the import plus post-import merge/cleanup passes.
- `settings/expsite_data_media.ini.append.php` — `[DataMediaSettings]` `DataPath` / `StoragePath` defaults read by the installer constructor.

## Key classes

| Class | File | Purpose |
| --- | --- | --- |
| `expSiteDataMediaInstaller` | `classes/expsitedatamediainstaller.php` | Extends `expLayoutsSiteInstaller`; `installDataPack()` imports the SQL delta via the `sqlite3` CLI and merges `data/storage/` into the live storage tree |

## Critical facts

- **`data/data.sql` is a fragile, hand-maintained delta layer.** It has been carefully patched row by row; never regenerate it blindly. Use the maintained tools: `ai/bin/one/sync_data_sql_from_live_db.py` to sync it from the live database and `ai/bin/one/audit_data_sql_vs_live_db.py` to audit it (see `doc/USAGE.md`).
- **`site.ini` `[FileSettings]` `VarDir=var/site` must match the storage payload.** The pack's `StoragePath` default is `var/site/storage`; a different `VarDir` leaves every imported image and file broken.
- **ID offsets:** content imported to this (alpha) installation is shifted relative to the Nexus reference site — node IDs = Nexus node IDs + 554, object IDs = Nexus object IDs + 776. Block and collection IDs map 1:1.

## Provenance

Fork of `netgen/media-site-data`, stripped of templates and refactored into a plain data pack (SQL delta + storage payload) for the Exponential simple site installation.

## Documentation

- `INSTALL.md` — how the pack is applied and the VarDir requirement
- `doc/USAGE.md` — installer invocation and the data.sql maintenance workflow
- `doc/FAQ.md` — common questions
- `doc/TODO.md` — known gaps
- `doc/SUPPORT.md` — how to get help
