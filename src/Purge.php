<?php

declare(strict_types=1);

namespace NcAppPurge;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Backs up and deletes an app's leftovers, and restores such a backup.
 *
 * Backup layout (one directory):
 *   manifest.json                  what was purged, Nextcloud version, db type, prefix
 *   tables/<table>.json            CREATE statements for this db platform, column types
 *   tables/<table>.jsonl           one row per line
 *   rows/<kind>.jsonl              appconfig, preferences, migrations, jobs, notifications rows
 *   appdata/<app>/...              copy of appdata_<instanceid>/<app>
 */
final class Purge {
	/** Row kinds outside the app's own tables: kind => [table, column that holds the app id, autoincrement column] */
	private const ROWS = [
		'appconfig' => ['appconfig', 'appid', null],
		'preferences' => ['preferences', 'appid', null],
		'migrations' => ['migrations', 'app', null],
		'notifications' => ['notifications', 'app', 'notification_id'],
	];

	/** @var list<string> */
	public array $log = [];

	public function __construct(private Nextcloud $nc) {
	}

	/**
	 * @param array<string, mixed> $entry one Inventory app entry
	 * @param list<string> $tables tables to drop (full names)
	 */
	public function purge(array $entry, array $tables, ?string $backupDir): void {
		$app = $entry['app'];
		if ($backupDir !== null) {
			$this->backup($entry, $tables, $backupDir);
		}
		$sm = $this->nc->db->createSchemaManager();
		foreach ($tables as $t) {
			$sm->dropTable($t);
			$this->log[] = "dropped table $t";
		}
		foreach ($entry['jobs'] as $job) {
			$qb = $this->nc->db->getQueryBuilder();
			$qb->delete('jobs')->where($qb->expr()->eq('id', $qb->createNamedParameter($job['id'], ParameterType::INTEGER)))->executeStatement();
		}
		if ($entry['jobs'] !== []) {
			$this->log[] = 'deleted ' . count($entry['jobs']) . ' background job(s)';
		}
		foreach (['notifications', 'migrations'] as $kind) {
			[$table, $col] = self::ROWS[$kind];
			if ($entry[$kind] > 0) {
				$qb = $this->nc->db->getQueryBuilder();
				$n = $qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($app)))->executeStatement();
				$this->log[] = "deleted $n $kind row(s)";
			}
		}
		if ($entry['preferences'] > 0) {
			\OCP\Server::get(\OCP\IConfig::class)->deleteAppFromAllUsers($app);
			$this->log[] = "deleted {$entry['preferences']} user preference(s)";
		}
		if ($entry['appconfig'] > 0) {
			$ac = \OCP\Server::get(\OCP\IAppConfig::class);
			if (method_exists($ac, 'deleteApp')) {
				$ac->deleteApp($app);
			} else {
				\OCP\Server::get(\OCP\IConfig::class)->deleteAppValues($app);
			}
			$this->log[] = "deleted {$entry['appconfig']} app config value(s)";
		}
		if ($entry['appdata'] !== null) {
			$this->nc->rootFolder()->get($this->nc->appDataRoot() . '/' . $app)->delete();
			$this->log[] = 'deleted appdata folder ' . $entry['appdata']['path'];
		}
	}

	/** @param list<string> $tables */
	private function backup(array $entry, array $tables, string $dir): void {
		$app = $entry['app'];
		foreach (['', '/tables', '/rows'] as $sub) {
			if (!is_dir($dir . $sub) && !mkdir($dir . $sub, 0700, true)) {
				throw new \RuntimeException("cannot create backup directory $dir$sub");
			}
		}
		$platform = $this->nc->db->getDatabasePlatform();
		$sm = $this->nc->db->createSchemaManager();
		foreach ($tables as $t) {
			$table = $sm->introspectTable($t);
			$columns = [];
			foreach ($table->getColumns() as $c) {
				$columns[$c->getName()] = \Doctrine\DBAL\Types\Type::lookupName($c->getType());
			}
			$this->writeJson("$dir/tables/$t.json", ['app' => $app, 'create' => $platform->getCreateTableSQL($table), 'columns' => $columns]);
			$q = $platform->quoteIdentifier($t);
			$this->writeRows("$dir/tables/$t.jsonl", $this->nc->db->executeQuery("SELECT * FROM $q"), null);
		}
		foreach (self::ROWS as $kind => [$table, $col]) {
			if ($entry[$kind] > 0) {
				$qb = $this->nc->db->getQueryBuilder();
				$qb->select('*')->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($app)));
				$this->writeRows("$dir/rows/$kind.jsonl", $qb->executeQuery(), $app);
			}
		}
		if ($entry['jobs'] !== []) {
			$ids = array_column($entry['jobs'], 'id');
			$qb = $this->nc->db->getQueryBuilder();
			$qb->select('*')->from('jobs')->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$this->writeRows("$dir/rows/jobs.jsonl", $qb->executeQuery(), $app);
		}
		if ($entry['appdata'] !== null) {
			$folder = $this->nc->rootFolder()->get($this->nc->appDataRoot() . '/' . $app);
			$this->copyOut($folder, "$dir/appdata/$app");
		}
		$manifestFile = "$dir/manifest.json";
		$manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : [
			'tool' => 'nc-app-purge',
			'created' => gmdate('c'),
			'nextcloud' => $this->nc->version(),
			'dbtype' => $this->nc->dbType(),
			'prefix' => $this->nc->prefix,
			'apps' => [],
		];
		$manifest['apps'][$app] = ['tables' => $tables] + array_intersect_key($entry, array_flip(['status', 'installed_version', 'appconfig', 'preferences', 'migrations', 'notifications', 'appdata']));
		$this->writeJson($manifestFile, $manifest);
		$this->log[] = "backup written to $dir";
	}

	public function restore(string $dir, bool $skipExisting): void {
		$manifest = json_decode((string)@file_get_contents("$dir/manifest.json"), true);
		if (!is_array($manifest) || ($manifest['tool'] ?? '') !== 'nc-app-purge') {
			throw new \RuntimeException("$dir/manifest.json is missing or not an nc-app-purge backup");
		}
		if ($manifest['dbtype'] !== $this->nc->dbType() || $manifest['prefix'] !== $this->nc->prefix) {
			throw new \RuntimeException("backup was made on {$manifest['dbtype']} with prefix {$manifest['prefix']}, this instance uses {$this->nc->dbType()} with prefix {$this->nc->prefix}");
		}
		$existing = [];
		foreach ($manifest['apps'] as $app => $m) {
			foreach ($m['tables'] as $t) {
				if ($this->nc->tableExists($t)) {
					$existing[] = $t;
				}
			}
		}
		if ($existing !== [] && !$skipExisting) {
			throw new \RuntimeException('tables already exist (app reinstalled?): ' . implode(', ', $existing) . '. Remove them or pass --skip-existing.');
		}
		foreach ($manifest['apps'] as $app => $m) {
			foreach ($m['tables'] as $t) {
				if (in_array($t, $existing, true)) {
					$this->log[] = "skipped existing table $t";
					continue;
				}
				$def = json_decode((string)file_get_contents("$dir/tables/$t.json"), true);
				foreach ($def['create'] as $sql) {
					$this->nc->db->executeStatement($sql);
				}
				$n = $this->insertRows($t, "$dir/tables/$t.jsonl", $def['columns'], null);
				$this->fixSequences($t, $def['columns']);
				$this->log[] = "restored table $t ($n rows)";
			}
			foreach (self::ROWS + ['jobs' => ['jobs', 'class', 'id']] as $kind => [$table, , $autoinc]) {
				$file = "$dir/rows/$kind.jsonl";
				if (!is_file($file)) {
					continue;
				}
				$n = $this->insertRows($this->nc->prefix . $table, $file, null, $app, $autoinc);
				$this->log[] = "restored $n $kind row(s) for $app";
			}
			$src = "$dir/appdata/$app";
			if (is_dir($src)) {
				$root = $this->nc->rootFolder()->get($this->nc->appDataRoot());
				$target = $root->nodeExists($app) ? $root->get($app) : $root->newFolder($app);
				$this->copyIn($src, $target);
				$this->log[] = "restored appdata for $app";
			}
		}
		$this->nc->clearAppConfigCache();
	}

	/**
	 * @param array<string, string>|null $columns column => Doctrine type name
	 */
	private function insertRows(string $table, string $file, ?array $columns, ?string $app, ?string $dropColumn = null): int {
		$fh = fopen($file, 'r');
		$n = 0;
		while (($line = fgets($fh)) !== false) {
			$rec = json_decode($line, true);
			if ($app !== null) {
				if (($rec['app'] ?? null) !== $app) {
					continue;
				}
				$rec = $rec['row'];
			}
			$row = [];
			$types = [];
			foreach ($rec as $k => $v) {
				if ($k === $dropColumn) {
					continue;
				}
				$row[$this->nc->db->getDatabasePlatform()->quoteIdentifier($k)] = Util::decodeValue($v);
				$type = $columns[$k] ?? null;
				$types[] = in_array($type, [Types::BLOB, Types::BINARY], true) ? ParameterType::LARGE_OBJECT : ParameterType::STRING;
			}
			try {
				$this->nc->db->insert($table, $row, $types);
				$n++;
			} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
				$this->log[] = "skipped duplicate row in $table";
			}
		}
		fclose($fh);
		return $n;
	}

	/** PostgreSQL: move serial sequences past the restored ids. */
	private function fixSequences(string $table, array $columns): void {
		if ($this->nc->dbType() !== 'pgsql') {
			return;
		}
		foreach ($columns as $col => $type) {
			if (!in_array($type, [Types::INTEGER, Types::BIGINT, Types::SMALLINT], true)) {
				continue;
			}
			$seq = $this->nc->db->executeQuery('SELECT pg_get_serial_sequence(?, ?)', [$table, $col])->fetchOne();
			if ($seq) {
				$this->nc->db->executeQuery("SELECT setval(?, COALESCE((SELECT MAX(\"$col\") FROM \"$table\"), 0) + 1, false)", [$seq]);
			}
		}
	}

	private function writeRows(string $file, \OCP\DB\IResult|\Doctrine\DBAL\Result $res, ?string $app): void {
		$fh = fopen($file, 'a');
		while ($row = ($res instanceof \Doctrine\DBAL\Result ? $res->fetchAssociative() : $res->fetch())) {
			$row = array_map([Util::class, 'encodeValue'], $row);
			fwrite($fh, json_encode($app === null ? $row : ['app' => $app, 'row' => $row], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}
		fclose($fh);
		if ($res instanceof \OCP\DB\IResult) {
			$res->closeCursor();
		}
	}

	private function writeJson(string $file, array $data): void {
		file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}

	private function copyOut(\OCP\Files\Folder $folder, string $target): void {
		if (!is_dir($target)) {
			mkdir($target, 0700, true);
		}
		foreach ($folder->getDirectoryListing() as $node) {
			$dest = $target . '/' . $node->getName();
			if ($node instanceof \OCP\Files\Folder) {
				$this->copyOut($node, $dest);
			} elseif ($node instanceof \OCP\Files\File) {
				$in = $node->fopen('r');
				$out = fopen($dest, 'w');
				stream_copy_to_stream($in, $out);
				fclose($in);
				fclose($out);
			}
		}
	}

	private function copyIn(string $src, \OCP\Files\Folder $target): void {
		foreach (scandir($src) as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$path = "$src/$name";
			if (is_dir($path)) {
				$sub = $target->nodeExists($name) ? $target->get($name) : $target->newFolder($name);
				$this->copyIn($path, $sub);
			} elseif (!$target->nodeExists($name)) {
				$fh = fopen($path, 'r');
				$target->newFile($name, $fh);
				if (is_resource($fh)) {
					fclose($fh);
				}
			}
		}
	}
}
