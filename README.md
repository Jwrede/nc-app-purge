# nc-app-purge

Find and remove what uninstalled Nextcloud apps leave behind: database tables,
app config, user preferences, migration records, background jobs,
notifications and the app's folder in `appdata_<instanceid>`. Every purge
writes a backup that `nc-app-purge restore` puts back.

`occ app:remove` deletes only an app's code. Its tables and settings stay in
the database. Nextcloud 35 can list such tables (`occ db:schema:check -v`
shows them under "(unknown app)"), but there is no command that removes them,
and the listing cannot say which app a table came from once the code is gone.

## Why

- [nextcloud/server#5539](https://github.com/nextcloud/server/issues/5539)
  "Detect / Delete App Data": 250 reactions, 49 comments, open since June 2017.
  Users describe databases "filled with crap that can't be removed without
  manually messing with it" and dropping tables by hand. In October 2026 a user
  ran `occ db:schema:check -v`, got a list of tables "all apparently from
  (unknown app)", and asked for "a program to delete tables and related data
  for all (unknown app) tables", optionally from cron.
- The same thread names the second use case: resetting an app whose settings
  break it. Removing and reinstalling does not help because the old config
  survives (OnlyOffice, Assistant, GpodderSync and Polls are mentioned).
  Polls added its own `occ polls:db:purge` for this reason. It covers one app
  and only works while that app is installed.
- Nextcloud's own `db:schema:check` (added in 35, `lib/private/DB/SchemaChecker.php`)
  says in its help text: "occ app:remove only deletes its code, not its tables
  or config". No GitHub project for this was found (searched for
  "nextcloud orphan tables", "nextcloud app purge", "nextcloud database cleanup"
  and similar on 2026-11-11).

## How it works

`nc-app-purge` is a PHP script that boots your Nextcloud in-process, the way
`occ` does, and uses Nextcloud's own database connection. It therefore works
with SQLite, MySQL/MariaDB and PostgreSQL and honours the table prefix.

1. **Expected schema.** It replays the migrations of core and of every app
   whose code is present into an in-memory schema and records which app
   created which table (the same approach as `occ db:schema:check`; only
   `changeSchema()` runs, nothing is written). Tables of installed apps are
   never touched.
2. **Removed apps.** An app counts as removed when its code is gone but
   `installed_version` or migration records remain. For such an app,
   `purge` (and `scan --fetch`) downloads its code from the app store with
   Nextcloud's installer, which checks the app certificate and signature,
   replays the app's migrations to get its exact table list, and deletes the
   code again. The app is never enabled. Apps that are not in the app store
   are matched by table name (`<appid>_*` plus a few known exceptions such as
   `spreed` -> `talk_*`); those tables are only dropped with `--by-name`.
3. **Other leftovers.** Rows in `appconfig`, `preferences`, `migrations` and
   `notifications` with the app id, background jobs whose class lives in the
   app's namespace, and `appdata_<instanceid>/<appid>`.
4. **Backup, then delete.** The backup directory holds the `CREATE TABLE`
   statements for your database, all rows as JSON lines (binary values base64
   encoded), the other rows and a copy of the appdata folder. Config and
   preferences are deleted through Nextcloud's APIs, so its caches stay
   consistent.

Enabled apps and apps shipped with Nextcloud are refused. A disabled app
(code still present) can be purged; enabling it again then installs it from
scratch, which is the "reset a broken app" case from the issue.

## Install

Requirements: Nextcloud 33, 34 or 35 (tested in CI), the PHP CLI that runs
your Nextcloud, shell access as the web server user.

```sh
git clone https://github.com/Jwrede/nc-app-purge /opt/nc-app-purge
# or download a tarball of a tag and unpack it anywhere
```

No Composer dependencies. Run it as the owner of `config/config.php`, like occ:

```sh
sudo -u www-data php /opt/nc-app-purge/bin/nc-app-purge --nextcloud /var/www/nextcloud scan
```

Docker (official image, Nextcloud in `/var/www/html`; not covered by the CI):

```sh
docker cp nc-app-purge nextcloud:/tmp/nc-app-purge
docker exec -u www-data nextcloud php /tmp/nc-app-purge/bin/nc-app-purge scan
```

The Nextcloud root can also come from `NEXTCLOUD_ROOT` or the current directory.

## Example

Output from the CI run against Nextcloud 35.0.2 on PostgreSQL (CI paths
replaced by typical ones, purge log shortened), after `occ app:remove deck`
and a test app `ghostapp` that is not in the app store. The backup directory
must be writable by the web server user.

```text
$ sudo -u www-data php bin/nc-app-purge scan
Nextcloud 35.0.2.2 (pgsql, table prefix oc_)

APP       STATUS   TABLES  ROWS  CONFIG  PREFS  MIGR  JOBS  NOTIF  APPDATA
deck      removed  0+10?   30    4       2      16    4     0      26 B
ghostapp  removed  0+1?    1     1       0      1     0     0      -

TABLES: attributed by replayed migrations, "+n?" = n more matched only by name (purge --by-name).
```

`TABLES` counts tables attributed through the app's migrations; `+n?` are
tables that only match by name. Without `--fetch` the Deck tables are only
name matches, because the Deck code is gone.

```text
$ sudo -u www-data php bin/nc-app-purge purge deck ghostapp --by-name --backup-dir /srv/nc-purge --yes
fetched deck from the app store into /var/www/nextcloud/apps/deck (temporary, not enabled)
could not fetch ghostapp from the app store (Could not download app ghostapp, it was not found on the appstore); its tables can only be matched by name
removed temporary code of deck
deck (removed, version 1.19.2):
  drop table oc_deck_boards
  drop table oc_deck_stacks
  drop table oc_deck_cards
  drop table oc_deck_attachment
  drop table oc_deck_labels
  drop table oc_deck_assigned_labels
  drop table oc_deck_assigned_users
  drop table oc_deck_board_acl
  drop table oc_deck_sessions
  drop table oc_deck_dependent_cards
  delete 4 app config value(s)
  delete 2 user preference(s)
  delete 16 migration record(s)
  delete 4 background job(s)
  delete appdata folder /appdata_oc2x3jx1ouy1/deck (26 B)
ghostapp (removed, version 1.2.3):
  drop table oc_ghostapp_items
  delete 1 app config value(s)
  delete 1 migration record(s)
  backup written to /srv/nc-purge
  dropped table oc_deck_boards
...
Backup: /srv/nc-purge (restore with: nc-app-purge restore /srv/nc-purge)
```

```sh
# what would happen
sudo -u www-data php bin/nc-app-purge purge deck --dry-run
# every removed app at once, keeping a backup in /srv/nc-purge
sudo -u www-data php bin/nc-app-purge purge --all-removed --backup-dir /srv/nc-purge --yes
# undo
sudo -u www-data php bin/nc-app-purge restore /srv/nc-purge
```

`scan --json` gives machine-readable output; `scan -v` lists every table,
job and folder.

## Tests

- `php tests/unit/run.php`: name matching, job attribution, migration order,
  value encoding, argument parsing.
- `tests/integration/run.sh` runs in CI against real Nextcloud 35 (SQLite,
  MariaDB 11.4, PostgreSQL 17), 34 (SQLite, MariaDB) and 33 (PostgreSQL),
  weekly on Mondays as well. It installs Deck from the app store, creates
  boards through the Deck API, adds app config, a user preference, an appdata
  file and a fake app with a binary column, runs `occ app:remove`, and then
  checks: scan finds everything, also when called as `php bin/nc-app-purge`
  from another directory; `--fetch` attributes every Deck table; a dry
  run changes nothing; enabled apps are refused; the purge drops all tables
  and rows, deletes the appdata folder and background jobs; `occ
  db:schema:check -v` no longer reports the tables (35); `occ status` and
  `status.php` still work; restore brings back tables, boards, config,
  preference, migration records, appdata and binary data; a second restore
  refuses; `purge --all-removed` finds both apps again; after it
  `occ app:install deck` gives a fresh, empty app.

## Limits

- Tested with Nextcloud 33 to 35 only. Older versions lack some APIs it uses.
- Attribution by replaying migrations needs the app's code: present on disk,
  given with `--source APP=DIR`, or downloadable from the app store for this
  Nextcloud version. Otherwise only the name match is available, and tables
  of apps that existed in very old versions (dropped by later migrations)
  may stay unattributed. `--table` drops a named table explicitly.
- Fetching runs the app's migration classes (`changeSchema()` only) inside
  your Nextcloud process, with the same trust as installing the app.
- Rows other apps store about the removed app are not touched (activity
  stream entries, shares created by the app, files the app put into user
  folders).
- Object storage as primary storage is untested; appdata is read and written
  through Nextcloud's file API, not the local disk.
- Restore expects the same database type and table prefix and refuses when
  the tables exist again. Oracle is untested.
- It changes the database of a production system. Take a regular backup first.

## License

MIT
