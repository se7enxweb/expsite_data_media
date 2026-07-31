# Using expsite_data_media

All examples use real class, script and tool names. Run everything from the installation root.

## Installing the data pack

### Via the CLI script (recommended)

```bash
php extension/expsite_data_media/bin/php/install_data.php
```

The script constructs `expSiteDataMediaInstaller( 'extension/expsite_data_media/data', 'var/site/storage' )` with `replaceConflicts = false` (INSERT OR IGNORE, so kickstarter-created rows like the Home node are preserved), runs `installDataPack()`, and then performs the post-import passes: media-site root merge, URL-alias action fixes, merging imported sites into Home, media/core merges, media root node setup, URL-alias cleanup, orphan draft-version cleanup, content-class and attribute deduplication, media section policy fixes, the Fit & Healthy layout fix, media theme activation and a full cache clear.

### Via the site installer

```php
$results = expSiteInstaller::runMediaInstall();
```

This performs extension activation and INI reset around `installDataPack()` but not the post-import merge/cleanup passes — for a complete demo install prefer the CLI script.

### Programmatic use

```php
require_once 'extension/explayouts/classes/explayoutssiteinstaller.php';
require_once 'extension/expsite_data_media/classes/expsitedatamediainstaller.php';

$installer = new expSiteDataMediaInstaller();          // paths from expsite_data_media.ini
// or: new expSiteDataMediaInstaller( 'extension/expsite_data_media/data', 'var/site/storage' );

$installer->replaceConflicts = false;                  // data.sql (INSERT OR IGNORE) — default
// $installer->replaceConflicts = true;                // data-replace.sql — overwrites conflicts!

$output = $installer->installDataPack();               // array of log lines
```

`installDataPack()` closes the kernel DB handle, applies the SQL file with the external `sqlite3` client against `var/storage/sqlite3/sqlite.db` (so the SQLite file is not locked during import), reopens the connection, and calls `importBinaries()` which merges `data/storage/` into the storage path (unlike the base class, it does not refuse a non-empty destination).

## Maintaining data.sql — the only supported workflow

`data/data.sql` is a **fragile, hand-maintained INSERT OR IGNORE delta layer**. It has been corrupted by naive regeneration before. Never dump the database over it and never rebuild it by hand-editing large sections. The maintained workflow is:

1. **Sync** changes from the live database into the delta:

   ```bash
   python3 ai/bin/one/sync_data_sql_from_live_db.py
   ```

2. **Audit** the delta against the live database before committing:

   ```bash
   python3 ai/bin/one/audit_data_sql_vs_live_db.py
   ```

3. Review the diff of `data/data.sql` line by line; only commit intended row changes.

For one-off corrections, patch the specific INSERT rows surgically. Keep `data-replace.sql` in step with `data.sql` when a change must also apply to replace-mode installs.

## ID mapping against the Nexus reference site

Content in this pack originates from the Nexus (v5) reference installation. When cross-referencing rows:

- alpha node ID = Nexus node ID + 554
- alpha object ID = Nexus object ID + 776
- Layouts block and collection IDs map 1:1

Use these offsets when porting a row from the reference database into `data.sql`.

## Storage payload

`data/storage/` mirrors a site storage tree (`images/`, `original/audio`, `original/image`, `original/text`, `original/video`). It is merged (recursive copy) into `StoragePath` — existing files are left in place, new files are added. The siteaccess must run with `site.ini` `[FileSettings]` `VarDir=var/site` so the merged files are actually served.

## Customization

### Settings layer

The installer constructor reads its defaults from `expsite_data_media.ini` `[DataMediaSettings]`:

```ini
[DataMediaSettings]
DataPath=extension/expsite_data_media/data
StoragePath=var/site/storage
```

Override them from outside the extension through the INI cascade (later wins): extension defaults → `settings/siteaccess/<siteaccess>/expsite_data_media.ini.append.php` → your extension's `settings/siteaccess/<siteaccess>/` → `settings/override/expsite_data_media.ini.append.php`. Constructor arguments trump INI values. Remember: changing `StoragePath` only makes sense together with a matching `VarDir`.

### Template layer

The pack ships no templates (they were stripped in the fork); rendering of the imported content is owned by the theme extensions. Nothing here participates in the design cascade.

### PHP layer

`expSiteDataMediaInstaller` extends `expLayoutsSiteInstaller` and is itself the reference example of customizing that base class. Safe extension points:

- Subclass and override `installDataPack()` to change the database path or import strategy (the SQLite path `var/storage/sqlite3/sqlite.db` is currently fixed inside the method).
- Override `importBinaries( $source, $destination )` to change the merge behaviour (the override here already relaxes the base class's empty-destination requirement).
- Set the public `replaceConflicts` property before calling `installDataPack()` to switch between `data.sql` and `data-replace.sql`.
