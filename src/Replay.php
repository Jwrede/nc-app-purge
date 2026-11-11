<?php

declare(strict_types=1);

namespace NcAppPurge;

use Doctrine\DBAL\Schema\Schema;

/**
 * Rebuilds the schema Nextcloud expects by replaying migrations into an
 * in-memory Doctrine schema (the same approach as occ db:schema:check in
 * Nextcloud 35), and records which app created which table.
 *
 * Only changeSchema() is called; pre/postSchemaChange (data changes) never run,
 * and nothing is written to the database.
 */
final class Replay {
	public Schema $schema;
	/** @var array<string, string> table (with prefix) => owning app */
	public array $owner = [];
	/** @var array<string, string> app => error message */
	public array $errors = [];

	public function __construct(private Nextcloud $nc) {
		$this->schema = new Schema();
	}

	/**
	 * Replays core and every app whose code is present (enabled or not).
	 * $skip: apps to leave out (the ones being inspected separately).
	 *
	 * @param list<string> $skip
	 */
	public function replayInstalled(array $skip = []): void {
		$this->replayCore();
		$apps = array_unique(array_merge(
			$this->nc->enabledApps(),
			array_keys($this->nc->installedVersions()),
		));
		sort($apps);
		foreach ($apps as $app) {
			if ($app === 'core' || in_array($app, $skip, true)) {
				continue;
			}
			$path = $this->nc->appPath($app);
			if ($path !== null) {
				$this->replayApp($app, $path);
			}
		}
	}

	public function replayCore(): void {
		$before = $this->tableNames();
		try {
			$ms = new \OC\DB\MigrationService('core', $this->nc->db, $this->output('core'));
			foreach ($ms->getAvailableVersions() as $version) {
				$step = $ms->createInstance($version);
				$step->changeSchema($this->output('core'), fn () => $this->wrapper(), []);
			}
		} catch (\Throwable $e) {
			throw new \RuntimeException('Replaying core migrations failed, refusing to continue: ' . $e->getMessage(), 0, $e);
		}
		$this->claim('core', $before);
		// created by MigrationService itself, not by a migration step
		$this->owner[$this->nc->prefix . 'migrations'] = 'core';
	}

	/**
	 * Replays one app's migrations from $path and returns the tables it created.
	 *
	 * @return list<string>
	 */
	public function replayApp(string $app, string $path): array {
		$before = $this->tableNames();
		try {
			$this->nc->registerAutoloading($app, $path);
			$ns = $this->nc->appNamespace($app, $path) . '\\Migration';
			$files = $this->migrationFiles($path . '/lib/Migration');
			foreach ($files as $file) {
				$class = $ns . '\\' . basename($file, '.php');
				if (!class_exists($class)) {
					require_once $file;
				}
				if (!class_exists($class)) {
					continue;
				}
				try {
					$step = \OCP\Server::get($class);
				} catch (\Throwable) {
					$step = new $class();
				}
				if (!$step instanceof \OCP\Migration\IMigrationStep) {
					continue;
				}
				$step->changeSchema($this->output($app), fn () => $this->wrapper(), []);
			}
		} catch (\Throwable $e) {
			$this->errors[$app] = $e->getMessage();
		}
		return $this->claim($app, $before);
	}

	/** @return list<string> */
	private function migrationFiles(string $dir): array {
		if (!is_dir($dir)) {
			return [];
		}
		$it = new \RegexIterator(
			new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
			'#^.+/Version[^/]{1,255}\.php$#i',
			\RegexIterator::GET_MATCH,
		);
		$files = array_keys(iterator_to_array($it));
		usort($files, [Util::class, 'compareMigrations']);
		return $files;
	}

	/**
	 * @param array<string, true> $before
	 * @return list<string>
	 */
	private function claim(string $app, array $before): array {
		$new = [];
		foreach (array_keys($this->tableNames()) as $t) {
			if (!isset($before[$t])) {
				$this->owner[$t] = $app;
				$new[] = $t;
			}
		}
		return $new;
	}

	/** @return array<string, true> */
	private function tableNames(): array {
		$out = [];
		foreach ($this->schema->getTables() as $t) {
			$out[$t->getName()] = true;
		}
		return $out;
	}

	private function wrapper(): \OC\DB\SchemaWrapper {
		return new \OC\DB\SchemaWrapper($this->nc->db, $this->schema);
	}

	private function output(string $app): \OCP\Migration\IOutput {
		if (class_exists(\OC\Migration\NullOutput::class)) {
			return new \OC\Migration\NullOutput();
		}
		return new \OC\Migration\SimpleOutput(\OCP\Server::get(\Psr\Log\LoggerInterface::class), $app);
	}
}
