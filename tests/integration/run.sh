#!/usr/bin/env bash
# End-to-end test against a real Nextcloud: install an app from the app store,
# give it data, remove it with occ app:remove, then scan, purge, restore and
# reinstall. Needs: NC (Nextcloud root, installed), APP (default deck).
set -euo pipefail

NC=${NC:?set NC to the Nextcloud root}
APP=${APP:-deck}
HERE=$(cd "$(dirname "$0")" && pwd)
TOOL="php $HERE/../../bin/nc-app-purge --nextcloud $NC"
FIX="php $HERE/fixture.php $NC"
OCC="php $NC/occ"
WORK=$(mktemp -d)
PASS=0

ok() { PASS=$((PASS + 1)); echo "ok $PASS - $*"; }
fail() { echo "FAIL - $*" >&2; exit 1; }
eq() { [ "$1" = "$2" ] || fail "$3: got '$1', want '$2'"; ok "$3"; }

major=$($OCC status --output=json | php -r 'echo explode(".", json_decode(stream_get_contents(STDIN), true)["version"])[0];')
echo "# Nextcloud major $major, app $APP"

# 1. the app with real data ------------------------------------------------
$OCC app:install "$APP"
$OCC config:system:set trusted_domains 1 --value=127.0.0.1
php -S 127.0.0.1:8080 -t "$NC" >"$WORK/php-server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null http://127.0.0.1:8080/status.php && break; sleep 1; done
if [ "$APP" = deck ]; then
	for title in "Purge test" "Second board"; do
		curl -sf -u "admin:$ADMIN_PASS" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
			-X POST http://127.0.0.1:8080/index.php/apps/deck/api/v1.0/boards \
			-d "{\"title\":\"$title\",\"color\":\"0082c9\"}" >/dev/null || { cat "$WORK/php-server.log"; fail "create deck board"; }
	done
	boards=$($FIX count oc_deck_boards)
	[ "$boards" -ge 2 ] || fail "deck boards: got $boards, want at least 2"
	ok "$boards deck boards exist (two created through the Deck API)"
fi
$OCC config:app:set "$APP" purge_test --value=kept
$OCC user:setting admin "$APP" purge_pref yes
eq "$($FIX appdata "$APP")" ok "appdata file written"
eq "$($FIX ghost)" ok "ghost app with table, config and migration record"
tables_before=$($FIX tables "${APP}_" | wc -l)
[ "$tables_before" -gt 0 ] || fail "app has no tables"
ok "$APP has $tables_before tables"
migr_before=$($FIX rows migrations app "$APP")

# 2. remove the app the usual way: code goes, data stays ------------------
$OCC app:remove "$APP"
[ ! -d "$NC/apps/$APP" ] || fail "app code still there"
eq "$($FIX tables "${APP}_" | wc -l)" "$tables_before" "occ app:remove leaves the tables"
eq "$($OCC config:app:get "$APP" purge_test)" kept "occ app:remove leaves the app config"
if [ "$major" -ge 35 ]; then
	$OCC db:schema:check -v >"$WORK/check-before.txt" || true
	grep -q "unexpected table 'oc_${APP}_" "$WORK/check-before.txt" || { cat "$WORK/check-before.txt"; fail "db:schema:check does not see the leftovers"; }
	ok "occ db:schema:check reports the leftover tables as unexpected"
fi

# 3. scan --------------------------------------------------------------------
$TOOL scan >"$WORK/scan.txt"
cat "$WORK/scan.txt"
grep -Eq "^$APP +removed" "$WORK/scan.txt" || fail "scan does not list $APP as removed"
ok "scan lists $APP as removed"
grep -Eq "^ghostapp +removed" "$WORK/scan.txt" || fail "scan does not list ghostapp"
ok "scan lists ghostapp as removed"

$TOOL scan --fetch --json >"$WORK/scan.json"
php -r '
$s = json_decode(file_get_contents($argv[1]), true);
$a = array_column($s["apps"], null, "app");
$app = $a[$argv[2]] ?? null;
if (!$app) { fwrite(STDERR, "app missing\n"); exit(1); }
$exact = count(array_filter($app["tables"], fn ($t) => $t["by"] === "migrations"));
echo "$exact ", $app["appconfig"] > 0 ? 1 : 0, " ", $app["preferences"] > 0 ? 1 : 0, " ", $app["appdata"]["files"] ?? 0, " ", $a["ghostapp"]["tables"][0]["by"] ?? "none", "\n";
' "$WORK/scan.json" "$APP" >"$WORK/scan.sum"
read -r exact hascfg prefs hasappdata ghostby <"$WORK/scan.sum"
eq "$exact" "$tables_before" "scan --fetch attributes all $tables_before tables through the app's migrations"
eq "$hascfg $prefs $hasappdata" "1 1 1" "scan finds app config, user preferences and the appdata file"
eq "$ghostby" name "ghostapp table is matched by name only"
[ ! -d "$NC/apps/$APP" ] || fail "fetched code was not removed"
ok "temporarily fetched code removed again"

# 4. dry run changes nothing ----------------------------------------------
$TOOL purge "$APP" ghostapp --by-name --dry-run >"$WORK/dry.txt"
grep -q "Dry run" "$WORK/dry.txt" || fail "no dry-run marker"
eq "$($FIX tables "${APP}_" | wc -l)" "$tables_before" "dry run keeps the tables"

# without --by-name the ghost table is kept
$TOOL purge ghostapp --dry-run >"$WORK/dry2.txt"
grep -q "keep table oc_ghostapp_items" "$WORK/dry2.txt" || { cat "$WORK/dry2.txt"; fail "name-only table not kept without --by-name"; }
ok "name-only tables need --by-name"

# refuses shipped and enabled apps
if $TOOL purge files --yes --no-backup >/dev/null 2>&1; then fail "purged an enabled shipped app"; fi
ok "refuses to purge an enabled app"

# 5. purge with backup -----------------------------------------------------
BACKUP="$WORK/backup"
$TOOL purge "$APP" ghostapp --by-name --yes --backup-dir "$BACKUP" | tee "$WORK/purge.txt"
eq "$($FIX tables "${APP}_" | wc -l)" 0 "all $APP tables dropped"
eq "$($FIX tables ghostapp_ | wc -l)" 0 "ghost table dropped"
eq "$($FIX rows appconfig appid "$APP")" 0 "app config deleted"
eq "$($FIX rows preferences appid "$APP")" 0 "user preferences deleted"
eq "$($FIX rows migrations app "$APP")" 0 "migration records deleted"
eq "$($FIX rows appconfig appid ghostapp)" 0 "ghost config deleted"
eq "$($FIX appdata-exists "$APP")" no "appdata deleted"
ns=$(php -r 'echo "OCA\\".ucfirst($argv[1])."\\";' "$APP")
eq "$($FIX jobs "$ns")" 0 "background jobs deleted"
[ ! -d "$NC/apps/$APP" ] || fail "code left behind"
if [ "$major" -ge 35 ]; then
	$OCC db:schema:check -v >"$WORK/check-after.txt" || true
	if grep -Eq "unexpected table 'oc_(${APP}|ghostapp)_" "$WORK/check-after.txt"; then cat "$WORK/check-after.txt"; fail "db:schema:check still sees leftovers"; fi
	ok "occ db:schema:check no longer reports them"
fi
$OCC status >/dev/null && ok "occ status works after the purge"
curl -sf http://127.0.0.1:8080/status.php >/dev/null && ok "status.php answers after the purge"

# 6. restore ---------------------------------------------------------------
$TOOL restore "$BACKUP"
eq "$($FIX tables "${APP}_" | wc -l)" "$tables_before" "restore re-creates all $APP tables"
[ "$APP" != deck ] || eq "$($FIX count oc_deck_boards)" "$boards" "restore brings back all $boards deck boards"
eq "$($OCC config:app:get "$APP" purge_test)" kept "restore brings back app config"
eq "$($OCC user:setting admin "$APP" purge_pref)" yes "restore brings back the user preference"
eq "$($FIX rows migrations app "$APP")" "$migr_before" "restore brings back the migration records"
eq "$($FIX appdata-exists "$APP")" yes "restore brings back appdata"
eq "$($FIX blob)" 0001ff "restore keeps binary data"
if $TOOL restore "$BACKUP" >/dev/null 2>&1; then fail "restore over existing tables did not refuse"; fi
ok "second restore refuses because the tables exist"

# 7. purge again, then a clean reinstall works ----------------------------
$TOOL purge --all-removed --by-name --yes --no-backup >"$WORK/purge-all.txt"
grep -q "dropped table oc_ghostapp_items" "$WORK/purge-all.txt" || fail "--all-removed did not purge ghostapp"
eq "$($FIX tables "${APP}_" | wc -l)" 0 "purge --all-removed drops the restored tables again"
$OCC app:install "$APP"
[ "$APP" != deck ] || eq "$($FIX count oc_deck_boards)" 0 "reinstalled app starts empty"
eq "$($OCC config:app:get "$APP" purge_test || true)" "" "reinstalled app has no old config"
$OCC app:list --output=json | php -r 'exit(isset(json_decode(stream_get_contents(STDIN), true)["enabled"][$argv[1]]) ? 0 : 1);' "$APP" || fail "app not enabled after reinstall"
ok "fresh reinstall of $APP is enabled"

echo "# $PASS checks passed"
