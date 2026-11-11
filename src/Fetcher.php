<?php

declare(strict_types=1);

namespace NcAppPurge;

/**
 * Temporarily downloads the code of removed apps from the app store, so their
 * migrations can be replayed and their tables attributed exactly. Uses
 * Nextcloud's own installer (certificate and signature checks included) and
 * removes the code again afterwards. The apps are never enabled.
 */
final class Fetcher {
	/** @var list<string> */
	public array $fetched = [];

	public function __construct(private Nextcloud $nc) {
	}

	/**
	 * @param array<string, string> $sources apps that already have code from --source
	 * @param callable(string): void $note
	 * @param list<string>|null $only restrict to these apps
	 * @return array<string, string> app => path of the downloaded code
	 */
	public function fetchRemoved(array $sources, callable $note, ?array $only = null): array {
		$candidates = array_keys($this->nc->installedVersions());
		$qb = $this->nc->db->getQueryBuilder();
		$res = $qb->selectDistinct('app')->from('migrations')->executeQuery();
		while ($row = $res->fetch()) {
			$candidates[] = (string)$row['app'];
		}
		$res->closeCursor();
		$candidates = array_unique($candidates);
		if ($only !== null) {
			$candidates = array_intersect($candidates, $only);
		}
		$installer = \OCP\Server::get(\OC\Installer::class);
		$out = [];
		foreach ($candidates as $app) {
			if ($app === 'core' || isset($sources[$app]) || $this->nc->appPath($app) !== null || $this->nc->isShipped($app)) {
				continue;
			}
			try {
				$installer->downloadApp($app);
				$path = $this->nc->appPath($app);
				if ($path === null) {
					throw new \RuntimeException('download finished but the app folder is missing');
				}
				$this->fetched[] = $app;
				$out[$app] = $path;
				$note("fetched $app from the app store into $path (temporary, not enabled)");
			} catch (\Throwable $e) {
				$note("could not fetch $app from the app store ({$e->getMessage()}); its tables can only be matched by name");
			}
		}
		return $out;
	}

	/** @param callable(string): void $note */
	public function cleanup(callable $note): void {
		if ($this->fetched === []) {
			return;
		}
		$installer = \OCP\Server::get(\OC\Installer::class);
		foreach ($this->fetched as $app) {
			$path = $this->nc->appPath($app);
			if ($installer->removeApp($app)) {
				$note("removed temporary code of $app");
			} else {
				$note("warning: could not remove the temporary code of $app in $path; delete it by hand");
			}
		}
		$this->fetched = [];
	}
}
