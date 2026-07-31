Exponential Site Data Media
===================

General description
-------------------

Exponential Site Data Media (extension name: `expsite_data_media`) is the
media demo content data pack for Exponential CMS (eZ Publish Legacy),
feature-ripped from `netgen/media-site-data`. It ships the demo site's
database rows as a hand-maintained SQLite delta plus the matching binary
storage payload, and an installer class that applies both on top of an
existing installation. It provides the following capabilities:

- Demo content import - Apply the media demo site's content rows to an
  already-installed base site as a non-destructive INSERT OR IGNORE delta.
- Binary storage merge - Merge the matching `images/` and `original/`
  storage payload into the live storage tree.
- Replace-mode import - An opt-in REPLACE variant of the delta for
  deliberate, conflict-overwriting installs.
- CLI and programmatic installation - A dedicated CLI script with
  post-import merge/cleanup passes, plus a plain PHP installer class for
  scripted use.
- Maintained data workflow - Dedicated sync and audit tooling for keeping
  the SQL delta consistent with the live database.

The pack is a fork of `netgen/media-site-data`, stripped of templates and
refactored into a plain data pack (SQL delta + storage payload) for the
Exponential simple site installation.

Critical facts (read before touching anything)

- **`data/data.sql` is a fragile, hand-maintained delta layer.** It has
  been carefully patched row by row; never regenerate it blindly. Use the
  maintained tools: `ai/bin/one/sync_data_sql_from_live_db.py` to sync it
  from the live database and `ai/bin/one/audit_data_sql_vs_live_db.py` to
  audit it (see [doc/USAGE.md](doc/USAGE.md)).
- **`site.ini` `[FileSettings]` `VarDir=var/site` must match the storage
  payload.** The pack's `StoragePath` default is `var/site/storage`; a
  different `VarDir` leaves every imported image and file broken.
- **ID offsets:** content imported to this (alpha) installation is shifted
  relative to the Nexus reference site - node IDs = Nexus node IDs + 554,
  object IDs = Nexus object IDs + 776. Block and collection IDs map 1:1.

Features
--------

Exponential Site Data Media provides the following features in detail:

What is included

- `data/data.sql` - INSERT OR IGNORE delta of the demo content (default
  import; never overwrites existing rows).
- `data/data-replace.sql` - REPLACE variant of the same delta
  (conflict-overwriting import; opt-in).
- `data/storage/` - binary payload (`images/`, `original/`) merged into the
  site's storage directory. It mirrors a site storage tree (`images/`,
  `original/audio`, `original/image`, `original/text`, `original/video`).
- `classes/expsitedatamediainstaller.php` - `expSiteDataMediaInstaller`,
  applies the SQL via the `sqlite3` CLI and merges the binaries.
- `bin/php/install_data.php` - CLI entry point that runs the import plus
  post-import merge/cleanup passes.
- `settings/expsite_data_media.ini.append.php` - `[DataMediaSettings]`
  `DataPath` / `StoragePath` defaults read by the installer constructor.

Key classes

| Class | File | Purpose |
| --- | --- | --- |
| `expSiteDataMediaInstaller` | `classes/expsitedatamediainstaller.php` | Extends `expLayoutsSiteInstaller`; `installDataPack()` imports the SQL delta via the `sqlite3` CLI and merges `data/storage/` into the live storage tree |

Installer behaviour

- `installDataPack()` closes the kernel DB handle, applies the SQL file
  with the external `sqlite3` client against `var/storage/sqlite3/sqlite.db`
  (so the SQLite file is not locked during import), reopens the connection
  and calls `importBinaries()`, which merges `data/storage/` into the
  storage path. Unlike the base class, it does not refuse a non-empty
  destination; existing files are left in place, new files are added.
- The public `replaceConflicts` property switches between `data.sql`
  (INSERT OR IGNORE, the default - kickstarter-created rows like the Home
  node are preserved) and `data-replace.sql` (overwrites existing rows;
  only enable deliberately).
- The CLI script additionally performs the post-import passes: media-site
  root merge, URL-alias action fixes, merging imported sites into Home,
  media/core merges, media root node setup, URL-alias cleanup, orphan
  draft-version cleanup, content-class and attribute deduplication, media
  section policy fixes, the Fit & Healthy layout fix, media theme
  activation and a full cache clear.

Version
-------

- The current version of Exponential Site Data Media is 1.0.0
- Last Major update: July 30, 2026

Copyright
---------

- Exponential Site Data Media is copyright 1998 - 2026 7x
- See: [LICENSE.md](LICENSE.md) for more information on the terms of the copyright and license

License
-------

Exponential Site Data Media is licensed under the GNU General Public License.

The complete license agreement is included in the LICENSE.md file.

Exponential Site Data Media is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License or at your
option a later version.

Exponential Site Data Media is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

The GNU GPL gives you the right to use, modify and redistribute
Exponential Site Data Media under certain conditions. The GNU GPL license
is distributed with the software, see the file LICENSE.md.

It is also available at https://www.gnu.org/licenses/gpl.txt

You should have received a copy of the GNU General Public License
along with Exponential Site Data Media in LICENSE.md.  If not, see https://www.gnu.org/licenses/.

Using Exponential Site Data Media under the terms of the GNU GPL is free (as in freedom).

For more information or questions please contact info@se7enx.com

Requirements
------------

The following requirements exists for using Exponential Site Data Media extension:

Exponential CMS / eZ Publish Legacy version
- Make sure you use Exponential 6 (eZ Publish Legacy) or higher, with a
  **SQLite** database at `var/storage/sqlite3/sqlite.db` (the import path is
  fixed in `installDataPack()`).

PHP version
- Make sure you have PHP 8.1 or higher.

sqlite3 command-line client
- Make sure the `sqlite3` CLI binary is installed and on the PATH - the SQL
  delta is applied through it, not through the kernel DB layer.

Sibling extensions
- `extension/explayouts` present - `expSiteDataMediaInstaller` extends
  `expLayoutsSiteInstaller`.

Base site
- An already-installed base site (kickstarted); the pack is a delta on top,
  not a full database.

VarDir
- The siteaccess **must** use `site.ini` `[FileSettings]` `VarDir=var/site`
  (matching the `StoragePath` default `var/site/storage`). With any other
  `VarDir`, imported images and files will be missing.

Installation
------------

Installation is the standard legacy extension procedure: place the extension
in `extension/expsite_data_media`, activate it in
`settings/override/site.ini.append.php`, set `[FileSettings]`
`VarDir=var/site`, regenerate autoloads and clear caches, then run the data
import.

See the complete step-by-step instructions, including the VarDir
requirement and both `installDataPack()` entry points, in
[INSTALL.md](INSTALL.md).

Usage
-----

Installing the data pack - two supported entry points, both from the
installation root:

Via the CLI script (recommended - runs the import plus all post-import
merge/cleanup passes):

```bash
php extension/expsite_data_media/bin/php/install_data.php
```

The script constructs
`expSiteDataMediaInstaller( 'extension/expsite_data_media/data', 'var/site/storage' )`
with `replaceConflicts = false` (INSERT OR IGNORE, so kickstarter-created
rows like the Home node are preserved) and runs `installDataPack()` followed
by the post-import passes.

Via the site installer (activation, INI reset, import, autoloads, caches -
but not the post-import merge/cleanup passes; for a complete demo install
prefer the CLI script):

```php
$results = expSiteInstaller::runMediaInstall();
```

Programmatic use:

```php
require_once 'extension/explayouts/classes/explayoutssiteinstaller.php';
require_once 'extension/expsite_data_media/classes/expsitedatamediainstaller.php';

$installer = new expSiteDataMediaInstaller();          // paths from expsite_data_media.ini
// or: new expSiteDataMediaInstaller( 'extension/expsite_data_media/data', 'var/site/storage' );

$installer->replaceConflicts = false;                  // data.sql (INSERT OR IGNORE) — default
// $installer->replaceConflicts = true;                // data-replace.sql — overwrites conflicts!

$output = $installer->installDataPack();               // array of log lines
```

After the import, run a final cache clear:

```bash
php bin/php/ezcache.php --clear-all --purge --allow-root-user
```

Maintaining data.sql - the only supported workflow

`data/data.sql` is a **fragile, hand-maintained INSERT OR IGNORE delta
layer**. It has been corrupted by naive regeneration before. Never dump the
database over it and never rebuild it by hand-editing large sections. The
maintained workflow is:

1. Sync changes from the live database into the delta:

   ```bash
   python3 ai/bin/one/sync_data_sql_from_live_db.py
   ```

2. Audit the delta against the live database before committing:

   ```bash
   python3 ai/bin/one/audit_data_sql_vs_live_db.py
   ```

3. Review the diff of `data/data.sql` line by line; only commit intended
   row changes.

For one-off corrections, patch the specific INSERT rows surgically. Keep
`data-replace.sql` in step with `data.sql` when a change must also apply to
replace-mode installs.

ID mapping against the Nexus reference site

Content in this pack originates from the Nexus (v5) reference installation.
When cross-referencing rows:

- alpha node ID = Nexus node ID + 554
- alpha object ID = Nexus object ID + 776
- Layouts block and collection IDs map 1:1

Use these offsets when porting a row from the reference database into
`data.sql`.

See [doc/USAGE.md](doc/USAGE.md) for the full installer reference, the
data.sql maintenance workflow and the customization guide covering all
three layers: the settings layer (`[DataMediaSettings]` `DataPath` /
`StoragePath` and their INI cascade - remember that changing `StoragePath`
only makes sense together with a matching `VarDir`), the template layer
(the pack ships no templates; rendering is owned by the theme extensions)
and the PHP layer (subclassing `installDataPack()` / `importBinaries()` and
the `replaceConflicts` switch).

Documentation
-------------

| Document | Description |
| --- | --- |
| [INSTALL.md](INSTALL.md) | How the pack is applied, prerequisites and the VarDir requirement |
| [doc/USAGE.md](doc/USAGE.md) | Installer invocation, the data.sql maintenance workflow and the settings / template / PHP customization layers |
| [doc/FAQ.md](doc/FAQ.md) | Answers to the most common questions |
| [doc/TODO.md](doc/TODO.md) | Known gaps and planned improvements |
| [doc/SUPPORT.md](doc/SUPPORT.md) | How and where to get help |
| [LICENSE.md](LICENSE.md) | The complete GNU General Public License agreement |

Troubleshooting
---------------

Read the FAQ
- Some problems are more common than others. The most common ones are listed in the [doc/FAQ.md](doc/FAQ.md).

Use our support systems
- If you have any questions not handled by this document or the FAQ you can reach us through [se7enx.com](https://se7enx.com)
- If you find a bug or defect, please report it to the [Exponential Site Data Media: Issue Tracker](https://github.com/se7enxweb/expsite_data_media/issues)
