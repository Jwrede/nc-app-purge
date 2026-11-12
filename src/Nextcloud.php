<?php

declare(strict_types=1);

namespace NcAppPurge;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\Server;

/**
 * Boots a Nextcloud installation in-process (like occ does) and exposes the
 * few services this tool needs, papering over API differences between
 * Nextcloud 33, 34 and 35.
 */
final class Nextcloud {
	public \OC\DB\Connection $db;
	public IAppManager $apps;
	public IConfig $config;
	public string $prefix;

	public static function boot(string $root): self {
		$root = rtrim($root, '/');
		if (!is_file($root . '/lib/base.php') || !is_file($root . '/occ')) {
			throw new \RuntimeException("$root does not look like a Nextcloud installation (no lib/base.php / occ)");
		}
		$configFile = $root . '/config/config.php';
		if (is_file($configFile) && function_exists('posix_getuid') && posix_getuid() !== fileowner($configFile)) {
			throw new \RuntimeException('Run this as the user that owns config/config.php (uid ' . fileowner($configFile) . '), e.g. sudo -u www-data');
		}
		if (!defined('OC_CONSOLE')) {
			define('OC_CONSOLE', 1);
		}
		// OC::initPaths() resolves SCRIPT_FILENAME after our chdir(); a relative
		// path (php bin/nc-app-purge) would then no longer resolve (issue #1).
		$script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
		if ($script !== false) {
			$_SERVER['SCRIPT_FILENAME'] = $script;
		}
		chdir($root);
		require_once $root . '/lib/base.php';
		if (!\OC::$CLI) {
			throw new \RuntimeException('must run from the command line');
		}
		$self = new self();
		$self->config = Server::get(IConfig::class);
		if (!$self->config->getSystemValueBool('installed', false)) {
			throw new \RuntimeException('Nextcloud is not installed');
		}
		$self->db = Server::get(\OC\DB\Connection::class);
		$self->apps = Server::get(IAppManager::class);
		$self->prefix = (string)$self->config->getSystemValueString('dbtableprefix', 'oc_');
		return $self;
	}

	public function version(): string {
		return implode('.', \OCP\Util::getVersion());
	}

	public function dbType(): string {
		return (string)$this->config->getSystemValueString('dbtype', 'sqlite');
	}

	/** Path to the app's code, or null if the code is gone. */
	public function appPath(string $app): ?string {
		try {
			return $this->apps->getAppPath($app, true);
		} catch (\Throwable) {
			return null;
		}
	}

	/** Full PHP namespace of the app, e.g. "OCA\Polls". */
	public function appNamespace(string $app, ?string $path = null): string {
		if ($path !== null && is_file("$path/appinfo/info.xml")) {
			$xml = @simplexml_load_file("$path/appinfo/info.xml");
			if ($xml !== false && isset($xml->namespace) && trim((string)$xml->namespace) !== '') {
				return 'OCA\\' . trim((string)$xml->namespace);
			}
		}
		if ($path === null) {
			return 'OCA\\' . ucfirst($app);
		}
		if (method_exists($this->apps, 'getAppNamespace')) {
			return $this->apps->getAppNamespace($app);
		}
		return \OC\AppFramework\App::buildAppNamespace($app);
	}

	public function registerAutoloading(string $app, string $path): void {
		if (method_exists($this->apps, 'registerAutoloading')) {
			$this->apps->registerAutoloading($app, $path, true);
			return;
		}
		if (method_exists(\OC_App::class, 'registerAutoloading')) {
			\OC_App::registerAutoloading($app, $path, true);
			return;
		}
		$ns = $this->appNamespace($app, $path);
		if (is_file($path . '/composer/autoload.php')) {
			require_once $path . '/composer/autoload.php';
		} else {
			\OC::$composerAutoloader->addPsr4($ns . '\\', $path . '/lib/', true);
		}
	}

	public function isShipped(string $app): bool {
		return $this->apps->isShipped($app);
	}

	/** @return list<string> */
	public function enabledApps(): array {
		return array_values($this->apps->getEnabledApps());
	}

	/** @return array<string, string> app => installed_version, read straight from appconfig */
	public function installedVersions(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('appid', 'configvalue')->from('appconfig')
			->where($qb->expr()->eq('configkey', $qb->createNamedParameter('installed_version')));
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(string)$row['appid']] = (string)$row['configvalue'];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<string> all tables of this instance (with prefix) */
	public function liveTables(): array {
		$names = $this->db->createSchemaManager()->listTableNames();
		$names = array_values(array_filter($names, fn ($n) => str_starts_with($n, $this->prefix)));
		sort($names);
		return $names;
	}

	public function tableExists(string $fullName): bool {
		return in_array($fullName, $this->db->createSchemaManager()->listTableNames(), true);
	}

	public function countRows(string $fullName): int {
		$q = $this->db->getDatabasePlatform()->quoteIdentifier($fullName);
		return (int)$this->db->executeQuery("SELECT COUNT(*) FROM $q")->fetchOne();
	}

	public function dataDir(): string {
		return rtrim((string)$this->config->getSystemValueString('datadirectory', \OC::$SERVERROOT . '/data'), '/');
	}

	public function appDataRoot(): string {
		return 'appdata_' . $this->config->getSystemValueString('instanceid', '');
	}

	public function rootFolder(): \OCP\Files\IRootFolder {
		return Server::get(\OCP\Files\IRootFolder::class);
	}

	public function clearAppConfigCache(): void {
		$ac = Server::get(\OCP\IAppConfig::class);
		if (method_exists($ac, 'clearCache')) {
			$ac->clearCache(true);
		} elseif (method_exists($ac, 'clearCachedConfig')) {
			$ac->clearCachedConfig();
		}
	}
}
