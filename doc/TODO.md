# expsite_data_media TODO

Code-observed gaps; no promises attached.

- `installDataPack()` hardcodes the database path `var/storage/sqlite3/sqlite.db` and the `sqlite3` client; there is no MySQL path and no INI setting for the database location.
- `replaceConflicts` can only be toggled in code (public property); no CLI flag on `bin/php/install_data.php` exposes it.
- `bin/php/install_data.php` still describes itself as "Install the sevenx site data media pack." — a pre-rename leftover in the script's `eZScript` description (code file, left untouched by the documentation pass).
- Backup artifacts (`data/data.sql.backup.pre-sync-20260730-190503`, `data/data.sql.backup.v2`, `data/data.sql.full_before`) live inside `data/` and would be shipped with the pack; they belong outside the extension.
- The post-import merge/cleanup passes live only in `bin/php/install_data.php`, so `expSiteInstaller::runMediaInstall()` applies the raw delta without them; the passes could move into the installer class for parity.
- `data-replace.sql` must be maintained manually in step with `data.sql`; no tool checks the two variants against each other.
- `importBinaries()` merges with a plain recursive copy; there is no manifest or verification of the copied files.
