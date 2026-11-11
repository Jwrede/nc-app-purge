<?php

declare(strict_types=1);

namespace NcAppPurge;

final class Cli {
	public const VERSION = '0.1.0';

	private const USAGE = <<<'TXT'
nc-app-purge 0.1.0: find and remove what uninstalled Nextcloud apps leave behind

Usage:
  nc-app-purge [--nextcloud DIR] scan [--all] [--fetch] [--source APP=DIR] [--json] [-v]
  nc-app-purge [--nextcloud DIR] purge APP... [options]
  nc-app-purge [--nextcloud DIR] purge --all-removed [options]
  nc-app-purge [--nextcloud DIR] restore BACKUP_DIR [--skip-existing]

Run it as the user that owns config/config.php (sudo -u www-data php nc-app-purge ...).

scan                 list apps whose code is gone (status "removed") or that left data
                     without ever being installed ("unclaimed"), with their tables, app
                     config, user preferences, migration records, background jobs,
                     notifications and appdata folder, plus tables no app accounts for
  --all              also list enabled and disabled apps
  --fetch            download removed apps from the app store (signature checked by
                     Nextcloud) to attribute their tables exactly; the code is removed
                     again afterwards

purge                delete the leftovers of the named apps (never enabled or shipped apps)
  --dry-run          show what would be deleted and stop
  --backup-dir DIR   where to write the backup (default ./nc-app-purge-backup-<time>)
  --no-backup        do not write a backup
  --no-fetch         do not download removed apps from the app store
  --source APP=DIR   use the app's code from DIR to attribute its tables
  --by-name          also drop unattributed tables whose name matches the app id
  --table NAME       also drop this table (repeatable, full name with prefix)
  --yes              do not ask for confirmation

restore              re-create tables, rows and appdata from a backup
  --skip-existing    keep tables that exist again instead of refusing

Common: --json (scan only, machine-readable output), -v / --verbose, --help, --version
TXT;

	private bool $json = false;
	private bool $verbose = false;

	/** @param list<string> $argv */
	public function run(array $argv): int {
		try {
			$args = array_map(fn ($a) => $a === '-v' ? '--verbose' : $a, array_slice($argv, 1));
			[$pos, $opts] = Util::parseArgs($args, ['nextcloud', 'backup-dir', 'source', 'table']);
			if (isset($opts['version'])) {
				echo 'nc-app-purge ', self::VERSION, "\n";
				return 0;
			}
			if (isset($opts['help']) || $pos === []) {
				echo self::USAGE, "\n";
				return $pos === [] && !isset($opts['help']) ? 2 : 0;
			}
			$this->json = isset($opts['json']);
			$this->verbose = isset($opts['verbose']);
			$cwd = (string)getcwd();
			$abs = fn (string $p) => str_starts_with($p, '/') ? $p : $cwd . '/' . $p;
			$sources = [];
			foreach (Util::listOpt($opts, 'source') as $s) {
				if (!str_contains($s, '=')) {
					throw new \InvalidArgumentException("--source expects APP=DIR, got $s");
				}
				[$app, $dir] = explode('=', $s, 2);
				$sources[$app] = rtrim($abs($dir), '/');
			}
			$backupDir = isset($opts['backup-dir']) ? $abs((string)$opts['backup-dir']) : $cwd . '/nc-app-purge-backup-' . gmdate('Ymd-His');
			$restoreDir = ($pos[0] === 'restore' && isset($pos[1])) ? $abs($pos[1]) : null;

			$nc = Nextcloud::boot($this->findRoot($opts['nextcloud'] ?? null, $cwd));
			return match ($pos[0]) {
				'scan' => $this->scan($nc, $opts, $sources),
				'purge' => $this->purge($nc, array_slice($pos, 1), $opts, $sources, isset($opts['no-backup']) ? null : $backupDir),
				'restore' => $this->restore($nc, $restoreDir, isset($opts['skip-existing'])),
				default => throw new \InvalidArgumentException("unknown command {$pos[0]}"),
			};
		} catch (\Throwable $e) {
			fwrite(STDERR, 'nc-app-purge: ' . $e->getMessage() . "\n");
			if ($this->verbose) {
				fwrite(STDERR, $e->getTraceAsString() . "\n");
			}
			return 1;
		}
	}

	private function findRoot(?string $given, string $cwd): string {
		$candidates = array_filter([$given, getenv('NEXTCLOUD_ROOT') ?: null, $cwd, '/var/www/nextcloud', '/var/www/html']);
		foreach ($candidates as $c) {
			if (is_file(rtrim($c, '/') . '/occ')) {
				return $c;
			}
		}
		throw new \RuntimeException('Nextcloud not found; pass --nextcloud DIR or set NEXTCLOUD_ROOT');
	}

	/** @param array<string, string> $sources */
	private function scan(Nextcloud $nc, array $opts, array $sources): int {
		$fetcher = new Fetcher($nc);
		try {
			if (isset($opts['fetch'])) {
				$sources += $fetcher->fetchRemoved($sources, $this->note(...));
			}
			$inv = new Inventory($nc);
			$inv->scan($sources, $fetcher->fetched);
		} finally {
			$fetcher->cleanup($this->note(...));
		}
		$apps = array_filter($inv->apps, fn ($a) => Inventory::hasLeftovers($a)
			&& (isset($opts['all']) || in_array($a['status'], ['removed', 'unclaimed'], true) || isset($sources[$a['app']])));
		if ($this->json) {
			echo json_encode(['nextcloud' => $nc->version(), 'dbtype' => $nc->dbType(), 'prefix' => $nc->prefix,
				'apps' => array_values($apps), 'unattributed_tables' => $inv->unattributed, 'replay_errors' => $inv->replay->errors],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
			return 0;
		}
		echo "Nextcloud {$nc->version()} ({$nc->dbType()}, table prefix {$nc->prefix})\n\n";
		if ($apps === []) {
			echo "No leftovers of removed apps found.\n";
		} else {
			$rows = [['APP', 'STATUS', 'TABLES', 'ROWS', 'CONFIG', 'PREFS', 'MIGR', 'JOBS', 'NOTIF', 'APPDATA']];
			foreach ($apps as $a) {
				$rows[] = [$a['app'], $a['status'] . ($a['shipped'] ? '*' : ''), $this->tableCell($a), (string)array_sum(array_column($a['tables'], 'rows')),
					(string)$a['appconfig'], (string)$a['preferences'], (string)$a['migrations'], (string)count($a['jobs']),
					(string)$a['notifications'], $a['appdata'] === null ? '-' : Util::humanBytes($a['appdata']['bytes'])];
			}
			$this->table($rows);
			echo "\nTABLES: attributed by replayed migrations, \"+n?\" = n more matched only by name (purge --by-name).\n";
			if (array_filter($apps, fn ($a) => $a['shipped'])) {
				echo "* shipped with Nextcloud, never purged.\n";
			}
			if ($this->verbose) {
				foreach ($apps as $a) {
					echo "\n{$a['app']}:\n";
					foreach ($a['tables'] as $t) {
						echo "  table {$t['table']} ({$t['rows']} rows, by {$t['by']})\n";
					}
					foreach ($a['jobs'] as $j) {
						echo "  job #{$j['id']} {$j['class']}\n";
					}
					if ($a['appdata'] !== null) {
						echo "  appdata {$a['appdata']['path']}\n";
					}
				}
			}
		}
		if ($inv->unattributed !== []) {
			echo "\nTables no installed or listed app accounts for:\n";
			foreach ($inv->unattributed as $t) {
				echo "  {$t['table']} ({$t['rows']} rows)\n";
			}
		}
		foreach ($inv->replay->errors as $app => $err) {
			echo "\nwarning: could not replay migrations of $app: $err\n";
		}
		return 0;
	}

	/**
	 * @param list<string> $names
	 * @param array<string, string> $sources
	 */
	private function purge(Nextcloud $nc, array $names, array $opts, array $sources, ?string $backupDir): int {
		$allRemoved = isset($opts['all-removed']);
		if ($names === [] && !$allRemoved) {
			throw new \InvalidArgumentException('name the apps to purge, or pass --all-removed');
		}
		$fetcher = new Fetcher($nc);
		try {
			if (!isset($opts['no-fetch'])) {
				$sources += $fetcher->fetchRemoved($sources, $this->note(...), $allRemoved ? null : $names);
			}
			$inv = new Inventory($nc);
			$inv->scan($sources, $fetcher->fetched);
		} finally {
			$fetcher->cleanup($this->note(...));
		}
		if ($allRemoved) {
			$names = array_keys(array_filter($inv->apps, fn ($a) => $a['status'] === 'removed' && !$a['shipped'] && Inventory::hasLeftovers($a)));
		}
		$extraTables = Util::listOpt($opts, 'table');
		$plan = [];
		foreach ($names as $app) {
			$a = $inv->apps[$app] ?? null;
			if ($a === null) {
				throw new \RuntimeException("no data of app '$app' found");
			}
			if ($a['status'] === 'enabled') {
				throw new \RuntimeException("$app is enabled; disable it first (occ app:disable $app)");
			}
			if ($a['shipped']) {
				throw new \RuntimeException("$app ships with Nextcloud; refusing to purge it");
			}
			$tables = [];
			foreach ($a['tables'] as $t) {
				if ($t['by'] === 'migrations' || isset($opts['by-name'])) {
					$tables[] = $t['table'];
				}
			}
			$plan[$app] = ['entry' => $a, 'tables' => $tables];
		}
		if ($extraTables !== []) {
			if (count($plan) !== 1) {
				throw new \InvalidArgumentException('--table needs exactly one app to attach the tables to');
			}
			$app = array_key_first($plan);
			$owned = $inv->replay->owner;
			foreach ($extraTables as $t) {
				if (isset($owned[$t]) && $owned[$t] !== $app) {
					throw new \RuntimeException("table $t belongs to {$owned[$t]}; refusing");
				}
				if (!$nc->tableExists($t)) {
					throw new \RuntimeException("table $t does not exist");
				}
				$plan[$app]['tables'][] = $t;
			}
			$plan[$app]['tables'] = array_values(array_unique($plan[$app]['tables']));
		}
		if ($plan === []) {
			echo "Nothing to purge.\n";
			return 0;
		}

		foreach ($plan as $app => $p) {
			$a = $p['entry'];
			echo "$app ({$a['status']}" . ($a['installed_version'] ? ", version {$a['installed_version']}" : '') . "):\n";
			foreach ($p['tables'] as $t) {
				echo "  drop table $t\n";
			}
			$skipped = array_diff(array_column($a['tables'], 'table'), $p['tables']);
			foreach ($skipped as $t) {
				echo "  keep table $t (matched by name only; add --by-name)\n";
			}
			foreach (['appconfig' => 'app config value(s)', 'preferences' => 'user preference(s)', 'migrations' => 'migration record(s)', 'notifications' => 'notification(s)'] as $k => $label) {
				if ($a[$k] > 0) {
					echo "  delete {$a[$k]} $label\n";
				}
			}
			if ($a['jobs'] !== []) {
				echo '  delete ' . count($a['jobs']) . " background job(s)\n";
			}
			if ($a['appdata'] !== null) {
				echo "  delete appdata folder {$a['appdata']['path']} (" . Util::humanBytes($a['appdata']['bytes']) . ")\n";
			}
			if ($a['status'] === 'disabled') {
				echo "  (code stays in {$a['code']}; enabling the app again installs it fresh)\n";
			}
		}
		if (isset($opts['dry-run'])) {
			echo "\nDry run, nothing changed.\n";
			return 0;
		}
		if (!isset($opts['yes'])) {
			if (!stream_isatty(STDIN)) {
				throw new \RuntimeException('not a terminal; pass --yes to confirm');
			}
			echo "\nProceed? [y/N] ";
			if (strtolower(trim((string)fgets(STDIN))) !== 'y') {
				echo "Aborted.\n";
				return 1;
			}
		}
		$purge = new Purge($nc);
		foreach ($plan as $app => $p) {
			$purge->purge($p['entry'], $p['tables'], $backupDir);
		}
		foreach ($purge->log as $l) {
			echo "  $l\n";
		}
		if ($backupDir !== null) {
			echo "\nBackup: $backupDir (restore with: nc-app-purge restore $backupDir)\n";
		}
		return 0;
	}

	private function restore(Nextcloud $nc, ?string $dir, bool $skipExisting): int {
		if ($dir === null) {
			throw new \InvalidArgumentException('restore needs the backup directory');
		}
		$purge = new Purge($nc);
		$purge->restore($dir, $skipExisting);
		foreach ($purge->log as $l) {
			echo "  $l\n";
		}
		return 0;
	}

	private function tableCell(array $a): string {
		$exact = count(array_filter($a['tables'], fn ($t) => $t['by'] === 'migrations'));
		$byName = count($a['tables']) - $exact;
		return $exact . ($byName > 0 ? "+$byName?" : '');
	}

	/** @param list<list<string>> $rows */
	private function table(array $rows): void {
		$w = [];
		foreach ($rows as $r) {
			foreach ($r as $i => $c) {
				$w[$i] = max($w[$i] ?? 0, strlen($c));
			}
		}
		foreach ($rows as $r) {
			$line = '';
			foreach ($r as $i => $c) {
				$line .= str_pad($c, $w[$i] + 2);
			}
			echo rtrim($line), "\n";
		}
	}

	private function note(string $msg): void {
		if (!$this->json) {
			fwrite(STDERR, $msg . "\n");
		}
	}
}
