<?php

declare(strict_types=1);

// Test helper: boots Nextcloud and creates or inspects test data.
// Usage: php fixture.php NC_ROOT COMMAND [ARGS]

require_once __DIR__ . '/../../src/Util.php';
require_once __DIR__ . '/../../src/Nextcloud.php';

use Doctrine\DBAL\Schema\Schema;

[$_, $root, $cmd] = $argv;
$args = array_slice($argv, 3);
$nc = NcAppPurge\Nextcloud::boot($root);
$db = $nc->db;

// Nextcloud's own handler would only log uncaught exceptions to nextcloud.log
set_exception_handler(function (\Throwable $e): void {
	fwrite(STDERR, 'fixture: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
	exit(1);
});

switch ($cmd) {
	case 'appdata': // write appdata_<id>/<app>/test/hello.txt
		$appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get($args[0]);
		try {
			$folder = $appData->getFolder('test');
		} catch (\OCP\Files\NotFoundException) {
			$folder = $appData->newFolder('test');
		}
		$folder->newFile('hello.txt', "hello from the purge test\n");
		echo "ok\n";
		break;
	case 'ghost': // an app that no app store knows: table, config, migration record
		$schema = new Schema();
		$t = $schema->createTable($nc->prefix . 'ghostapp_items');
		$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('label', 'string', ['length' => 64, 'notnull' => true]);
		$t->addColumn('payload', 'blob', ['notnull' => false]);
		$t->setPrimaryKey(['id']);
		foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
			$db->executeStatement($sql);
		}
		$db->insert($nc->prefix . 'ghostapp_items', ['label' => 'one', 'payload' => "\x00\x01\xff"], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::LARGE_OBJECT]);
		\OCP\Server::get(\OCP\IConfig::class)->setAppValue('ghostapp', 'installed_version', '1.2.3');
		$qb = $db->getQueryBuilder();
		$qb->insert('migrations')->values(['app' => $qb->createNamedParameter('ghostapp'), 'version' => $qb->createNamedParameter('010203Date20200101000000')])->executeStatement();
		echo "ok\n";
		break;
	case 'tables': // tables whose name starts with prefix + ARG
		foreach ($nc->liveTables() as $t) {
			if (str_starts_with($t, $nc->prefix . $args[0])) {
				echo $t, "\n";
			}
		}
		break;
	case 'count': // rows in table (full name)
		echo $nc->countRows($args[0]), "\n";
		break;
	case 'blob': // hex of the payload of ghostapp_items row 1
		$v = $db->executeQuery('SELECT payload FROM ' . $nc->prefix . 'ghostapp_items')->fetchOne();
		echo bin2hex(is_resource($v) ? stream_get_contents($v) : (string)$v), "\n";
		break;
	case 'rows': // rows of TABLE where COLUMN = VALUE
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($args[0])->where($qb->expr()->eq($args[1], $qb->createNamedParameter($args[2])));
		echo (int)$qb->executeQuery()->fetchOne(), "\n";
		break;
	case 'jobs': // jobs whose class starts with ARG
		$n = 0;
		$res = $db->getQueryBuilder()->select('class')->from('jobs')->executeQuery();
		while ($row = $res->fetch()) {
			if (str_starts_with($row['class'], $args[0])) {
				$n++;
			}
		}
		echo $n, "\n";
		break;
	case 'appdata-exists':
		try {
			$nc->rootFolder()->get($nc->appDataRoot() . '/' . $args[0] . '/test/hello.txt');
			echo "yes\n";
		} catch (\OCP\Files\NotFoundException) {
			echo "no\n";
		}
		break;
	default:
		fwrite(STDERR, "unknown fixture command $cmd\n");
		exit(2);
}
