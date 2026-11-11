<?php

declare(strict_types=1);

namespace NcAppPurge;

/**
 * Finds what apps left behind: tables, app config, user preferences,
 * migration records, background jobs, notifications and appdata folders.
 */
final class Inventory {
	/** Folders in appdata_<instanceid> that belong to core, not to an app. */
	private const CORE_APPDATA = ['preview', 'appstore', 'css', 'js', 'avatar', 'identityproof', 'theming', 'dav-photocache', 'core', 'text', 'files_trashbin', 'files_versions', 'admin', 'settings', 'provisioning_api', 'previews'];

	/** App ids core uses in appconfig/preferences without being an app. */
	private const CORE_IDS = ['core', 'login', 'avatar', 'backgroundjob'];

	public Replay $replay;
	/** @var array<string, array<string, mixed>> */
	public array $apps = [];
	/** @var list<array{table: string, rows: int}> */
	public array $unattributed = [];
	/** @var array<string, string> app => where its code came from for attribution */
	public array $sources = [];

	public function __construct(private Nextcloud $nc) {
	}

	/**
	 * @param array<string, string> $extraSources app => path with the app's code (from --source or the app store)
	 * @param list<string> $fetched apps whose code was only downloaded temporarily (status stays "removed")
	 */
	public function scan(array $extraSources = [], array $fetched = []): void {
		$this->replay = new Replay($this->nc);
		$this->replay->replayInstalled(array_keys($extraSources));
		foreach ($extraSources as $app => $path) {
			$this->replay->replayApp($app, $path);
			$this->sources[$app] = $path;
		}

		$installed = $this->nc->installedVersions();
		$enabled = array_flip($this->nc->enabledApps());
		$ids = array_merge(
			array_keys($installed),
			$this->distinct('appconfig', 'appid'),
			$this->distinct('preferences', 'appid'),
			$this->distinct('migrations', 'app'),
			$this->appDataFolders(),
			array_keys($extraSources),
		);
		$ids = array_values(array_unique(array_filter($ids, fn ($a) => $a !== '' && !in_array($a, self::CORE_IDS, true))));
		sort($ids);

		$live = $this->nc->liveTables();
		$unexpected = array_values(array_filter($live, fn ($t) => !isset($this->replay->owner[$t])));

		foreach ($ids as $app) {
			$path = in_array($app, $fetched, true) ? null : $this->nc->appPath($app);
			$status = isset($enabled[$app]) ? 'enabled' : ($path !== null ? 'disabled' : (isset($installed[$app]) || $this->count('migrations', 'app', $app) > 0 ? 'removed' : 'unclaimed'));
			$codePath = $extraSources[$app] ?? $path;
			$ns = $codePath !== null ? $this->nc->appNamespace($app, $codePath) : null;
			$tables = [];
			foreach ($this->replay->owner as $t => $owner) {
				if ($owner === $app && in_array($t, $live, true)) {
					$tables[$t] = ['table' => $t, 'rows' => $this->nc->countRows($t), 'by' => 'migrations'];
				}
			}
			foreach ($unexpected as $t) {
				if (!isset($tables[$t]) && Util::tableMatchesApp(substr($t, strlen($this->nc->prefix)), $app)) {
					$tables[$t] = ['table' => $t, 'rows' => $this->nc->countRows($t), 'by' => 'name'];
				}
			}
			$this->apps[$app] = [
				'app' => $app,
				'status' => $status,
				'shipped' => $this->nc->isShipped($app),
				'installed_version' => $installed[$app] ?? null,
				'code' => $codePath,
				'namespace' => $ns,
				'replay_error' => $this->replay->errors[$app] ?? null,
				'tables' => array_values($tables),
				'appconfig' => $this->count('appconfig', 'appid', $app),
				'preferences' => $this->count('preferences', 'appid', $app),
				'migrations' => $this->count('migrations', 'app', $app),
				'jobs' => $this->jobs($app, $ns),
				'notifications' => $this->tableExistsShort('notifications') ? $this->count('notifications', 'app', $app) : 0,
				'appdata' => $this->appData($app),
			];
		}

		$claimed = [];
		foreach ($this->apps as $a) {
			foreach ($a['tables'] as $t) {
				$claimed[$t['table']] = true;
			}
		}
		foreach ($unexpected as $t) {
			if (!isset($claimed[$t])) {
				$this->unattributed[] = ['table' => $t, 'rows' => $this->nc->countRows($t)];
			}
		}
	}

	/** Leftovers worth reporting: apps whose code is gone, or explicitly inspected ones. */
	public static function hasLeftovers(array $a): bool {
		return $a['tables'] !== [] || $a['appconfig'] > 0 || $a['preferences'] > 0 || $a['migrations'] > 0
			|| $a['jobs'] !== [] || $a['notifications'] > 0 || $a['appdata'] !== null;
	}

	/** @return list<string> */
	private function distinct(string $table, string $col): array {
		$qb = $this->nc->db->getQueryBuilder();
		$qb->selectDistinct($col)->from($table);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = (string)$row[$col];
		}
		$res->closeCursor();
		return $out;
	}

	private function count(string $table, string $col, string $value): int {
		$qb = $this->nc->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($value)));
		$res = $qb->executeQuery();
		$n = (int)$res->fetchOne();
		$res->closeCursor();
		return $n;
	}

	private function tableExistsShort(string $short): bool {
		return $this->nc->tableExists($this->nc->prefix . $short);
	}

	/** @return list<array{id: int, class: string}> */
	private function jobs(string $app, ?string $ns): array {
		$qb = $this->nc->db->getQueryBuilder();
		$qb->select('id', 'class')->from('jobs');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			if (Util::classMatchesApp((string)$row['class'], $app, $ns)) {
				$out[] = ['id' => (int)$row['id'], 'class' => (string)$row['class']];
			}
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<string> */
	private function appDataFolders(): array {
		try {
			$root = $this->nc->rootFolder()->get($this->nc->appDataRoot());
		} catch (\Throwable) {
			return [];
		}
		$out = [];
		foreach ($root->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\Folder && !in_array($node->getName(), self::CORE_APPDATA, true)) {
				$out[] = $node->getName();
			}
		}
		return $out;
	}

	/** @return array{path: string, files: int, bytes: int}|null */
	private function appData(string $app): ?array {
		if (in_array($app, self::CORE_APPDATA, true)) {
			return null;
		}
		try {
			$node = $this->nc->rootFolder()->get($this->nc->appDataRoot() . '/' . $app);
		} catch (\Throwable) {
			return null;
		}
		$files = 0;
		$bytes = 0;
		$walk = function (\OCP\Files\Folder $f) use (&$walk, &$files, &$bytes): void {
			foreach ($f->getDirectoryListing() as $n) {
				if ($n instanceof \OCP\Files\Folder) {
					$walk($n);
				} else {
					$files++;
					$bytes += max(0, (int)$n->getSize());
				}
			}
		};
		if ($node instanceof \OCP\Files\Folder) {
			$walk($node);
		}
		return ['path' => $node->getPath(), 'files' => $files, 'bytes' => $bytes];
	}
}
