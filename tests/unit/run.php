<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Util.php';

use NcAppPurge\Util;

$failures = 0;
$count = 0;
function check(string $name, mixed $got, mixed $want): void {
	global $failures, $count;
	$count++;
	if ($got !== $want) {
		$failures++;
		fwrite(STDERR, "FAIL $name: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n");
	}
}

// table name matching
check('exact prefix', Util::tableMatchesApp('polls_polls', 'polls'), true);
check('table equals app', Util::tableMatchesApp('bookmarks', 'bookmarks'), true);
check('other app', Util::tableMatchesApp('pollsx_items', 'polls'), false);
check('longer app id is not a prefix match', Util::tableMatchesApp('deck_boards', 'deckx'), false);
check('alias spreed', Util::tableMatchesApp('talk_rooms', 'spreed'), true);
check('alias groupfolders', Util::tableMatchesApp('group_folders_acl', 'groupfolders'), true);
check('alias forms', Util::tableMatchesApp('forms_v2_forms', 'forms'), true);
check('underscore-free table', Util::tableMatchesApp('filesrightclick_x', 'files_rightclick'), true);
check('core never', Util::tableMatchesApp('core_x', 'core'), false);
check('short ids need exact prefix', Util::tableMatchesApp('nx_items', 'n_x'), false);
check('case insensitive', Util::tableMatchesApp('Deck_Boards', 'deck'), true);

// background job classes
check('job by guess', Util::classMatchesApp('OCA\\Polls\\Cron\\NotificationCron', 'polls'), true);
check('job leading backslash', Util::classMatchesApp('\\OCA\\Deck\\Cron\\ScheduledNotifications', 'deck'), true);
check('job other app', Util::classMatchesApp('OCA\\Deck\\Cron\\X', 'polls'), false);
check('job underscore app', Util::classMatchesApp('OCA\\FilesRightClick\\Job', 'files_rightclick'), true);
check('job core class', Util::classMatchesApp('OC\\Core\\BackgroundJobs\\X', 'core'), false);
check('job by namespace', Util::classMatchesApp('OCA\\Talk\\BackgroundJob\\X', 'spreed', 'OCA\\Talk'), true);
check('job namespace mismatch', Util::classMatchesApp('OCA\\Spreed\\X\\Y', 'spreed', 'Talk'), false);
check('job too short', Util::classMatchesApp('OCA\\Polls', 'polls'), false);

// migration order (same as MigrationService)
$files = ['Version010200Date20200101.php', 'Version000900Date20190101.php', 'Version010200Date20191231.php', 'Version2000.php'];
usort($files, [Util::class, 'compareMigrations']);
check('migration order', $files, ['Version000900Date20190101.php', 'Version010200Date20191231.php', 'Version010200Date20200101.php', 'Version2000.php']);

// value encoding round trip
$bin = "\x00\xff\xfe binary";
check('binary encodes', Util::encodeValue($bin), ['$b64' => base64_encode($bin)]);
check('binary round trip', Util::decodeValue(json_decode(json_encode(Util::encodeValue($bin)), true)), $bin);
check('utf8 stays', Util::encodeValue('grüße'), 'grüße');
check('int stays', Util::encodeValue(42), 42);
check('null stays', Util::encodeValue(null), null);
$fh = fopen('php://memory', 'r+');
fwrite($fh, "pg\x00bytea");
rewind($fh);
check('stream encodes', Util::encodeValue($fh), ['$b64' => base64_encode("pg\x00bytea")]);

// sizes
check('bytes', Util::humanBytes(512), '512 B');
check('kib', Util::humanBytes(1536), '1.5 KiB');
check('mib', Util::humanBytes(5 * 1024 * 1024), '5.0 MiB');

// argument parsing
[$pos, $opts] = Util::parseArgs(['purge', 'deck', '--dry-run', '--backup-dir', '/tmp/b', '--table=oc_a', '--table', 'oc_b,oc_c', 'polls'], ['backup-dir', 'table']);
check('positional', $pos, ['purge', 'deck', 'polls']);
check('flag', $opts['dry-run'], true);
check('value', $opts['backup-dir'], '/tmp/b');
check('repeated list', Util::listOpt($opts, 'table'), ['oc_a', 'oc_b', 'oc_c']);
[$pos] = Util::parseArgs(['restore', '--', '--weird-dir'], []);
check('double dash', $pos, ['restore', '--weird-dir']);
$threw = false;
try {
	Util::parseArgs(['--backup-dir'], ['backup-dir']);
} catch (InvalidArgumentException) {
	$threw = true;
}
check('missing value', $threw, true);

echo "$count checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
