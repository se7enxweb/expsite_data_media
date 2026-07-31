# Installing expsite_data_media

## Requirements

- Exponential CMS (legacy) installation with a **SQLite** database at `var/storage/sqlite3/sqlite.db` (the import path is fixed in `installDataPack()`).
- The `sqlite3` command-line client on the PATH (the SQL delta is applied through it, not through the kernel DB layer).
- `extension/explayouts` present — `expSiteDataMediaInstaller` extends `expLayoutsSiteInstaller`.
- An already-installed base site (kickstarted); the pack is a delta on top, not a full database.

## VarDir requirement

The storage payload is merged to `var/site/storage` (the `[DataMediaSettings]` `StoragePath` default). The siteaccess **must** use the matching var directory:

```ini
# settings/override/site.ini.append.php
[FileSettings]
VarDir=var/site
```

With any other `VarDir`, imported images and files will be missing.

## Steps

1. Place the extension in `extension/expsite_data_media`.

2. Activate it in `settings/override/site.ini.append.php`:

   ```ini
   [ExtensionSettings]
   ActiveExtensions[]=expsite_data_media
   ```

3. Regenerate autoloads and clear caches:

   ```bash
   php bin/php/ezpgenerateautoloads.php -e
   php bin/php/ezcache.php --clear-all --purge --allow-root-user
   ```

## How installDataPack() is invoked

Two supported entry points, both from the installation root:

- **Dedicated CLI script** (import plus post-import merge/cleanup passes — the normal way):

  ```bash
  php extension/expsite_data_media/bin/php/install_data.php
  ```

- **Via the site installer** (activation, INI reset, import, autoloads, caches):

  ```php
  expSiteInstaller::runMediaInstall();
  ```

`installDataPack()` closes the kernel DB connection, shells out to `sqlite3 var/storage/sqlite3/sqlite.db < data/data.sql`, reopens the connection and then merges `data/storage/` into the storage path. With `$installer->replaceConflicts = true` it uses `data-replace.sql` instead of `data.sql` — only do this deliberately, as it overwrites existing rows.

After the import, run a final cache clear:

```bash
php bin/php/ezcache.php --clear-all --purge --allow-root-user
```
