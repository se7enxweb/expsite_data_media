# expsite_data_media FAQ

## Can I regenerate data.sql from the database?

Not blindly — ever. `data.sql` is a hand-maintained INSERT OR IGNORE delta that has been patched row by row; naive regeneration has corrupted it before. Use `python3 ai/bin/one/sync_data_sql_from_live_db.py` to sync changes in and `python3 ai/bin/one/audit_data_sql_vs_live_db.py` to verify, then review the diff before committing. See `USAGE.md`.

## Why INSERT OR IGNORE instead of plain INSERT or REPLACE?

The pack is applied on top of an already-kickstarted site. INSERT OR IGNORE guarantees existing rows — for example the Home node created by the kickstarter — are never overwritten. `data-replace.sql` exists for the deliberate, conflict-overwriting case and is only used when `replaceConflicts = true`.

## Images and files are broken after the import — why?

Almost always a `VarDir` mismatch. The payload is merged to `var/site/storage`, so the siteaccess must run with `site.ini` `[FileSettings]` `VarDir=var/site`. Check that first, then clear caches.

## Why does the installer shell out to the sqlite3 CLI?

Two reasons visible in the code: the multi-megabyte dump imports far faster through the native client, and `installDataPack()` closes the kernel DB connection first so the SQLite file is not locked while the external client writes it.

## Why don't the IDs match the Nexus reference site?

The content was ported into an installation that already had objects, so everything was offset: alpha node IDs = Nexus node IDs + 554, alpha object IDs = Nexus object IDs + 776. Layouts block and collection IDs map 1:1.

## Does this work on MySQL?

Not currently. `installDataPack()` hardcodes the SQLite database path `var/storage/sqlite3/sqlite.db` and the `sqlite3` client. See `TODO.md`.
