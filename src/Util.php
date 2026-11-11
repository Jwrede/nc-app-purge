<?php

declare(strict_types=1);

namespace NcAppPurge;

/**
 * Pure helpers without Nextcloud dependencies (covered by tests/unit).
 */
final class Util {
	/**
	 * App ids whose table names do not start with the app id.
	 * Only used for the name-based fallback; replayed migrations take precedence.
	 */
	public const TABLE_ALIASES = [
		'spreed' => ['talk_'],
		'groupfolders' => ['group_folders'],
		'forms' => ['forms_v2_'],
		'gpoddersync' => ['gpodder_'],
		'announcementcenter' => ['announcements'],
		'files_antivirus' => ['files_avir_', 'files_antivirus'],
		'user_saml' => ['user_saml_'],
		'twofactor_totp' => ['twofactor_totp_'],
	];

	public static function normalize(string $s): string {
		return strtolower(str_replace(['_', '-'], '', $s));
	}

	/**
	 * Does a table name (without the database prefix) look like it belongs to $appId?
	 * Matches "<appid>", "<appid>_*" and the aliases above.
	 */
	public static function tableMatchesApp(string $table, string $appId): bool {
		$table = strtolower($table);
		$appId = strtolower($appId);
		if ($appId === '' || $appId === 'core') {
			return false;
		}
		$prefixes = array_merge([$appId], self::TABLE_ALIASES[$appId] ?? []);
		foreach ($prefixes as $p) {
			if ($table === rtrim($p, '_') || str_starts_with($table, rtrim($p, '_') . '_')) {
				return true;
			}
		}
		// "files_rightclick" vs. table "filesrightclick_x" and the reverse
		$n = self::normalize($appId);
		$first = explode('_', $table)[0];
		return strlen($n) >= 4 && self::normalize($first) === $n;
	}

	/**
	 * Does a background job class belong to the app? Uses the namespace from
	 * info.xml when known, otherwise compares the second namespace segment
	 * ("OCA\Polls\Cron\X") with the app id without underscores.
	 */
	public static function classMatchesApp(string $class, string $appId, ?string $namespace = null): bool {
		$class = ltrim($class, '\\');
		$parts = explode('\\', $class);
		if (count($parts) < 3 || $parts[0] !== 'OCA') {
			return false;
		}
		if ($namespace !== null && $namespace !== '') {
			$ns = str_starts_with($namespace, 'OCA\\') ? substr($namespace, 4) : $namespace;
			return strcasecmp($parts[1], $ns) === 0;
		}
		return self::normalize($parts[1]) === self::normalize($appId);
	}

	/** Same ordering as OC\DB\MigrationService::sortMigrations(). */
	public static function compareMigrations(string $a, string $b): int {
		preg_match('/(\d+)Date(\d+)/', basename($a), $ma);
		preg_match('/(\d+)Date(\d+)/', basename($b), $mb);
		if ($ma && $mb) {
			if ((int)$ma[1] !== (int)$mb[1]) {
				return (int)$ma[1] < (int)$mb[1] ? -1 : 1;
			}
			return strnatcmp($ma[2], $mb[2]);
		}
		return strnatcmp(basename($a), basename($b));
	}

	/**
	 * Encode a database value for a JSON backup. Binary data and streams
	 * (PostgreSQL bytea) become {"$b64": "..."}.
	 */
	public static function encodeValue(mixed $v): mixed {
		if (is_resource($v)) {
			$v = stream_get_contents($v);
			return ['$b64' => base64_encode((string)$v)];
		}
		if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
			return ['$b64' => base64_encode($v)];
		}
		return $v;
	}

	public static function decodeValue(mixed $v): mixed {
		if (is_array($v) && array_key_exists('$b64', $v)) {
			return base64_decode($v['$b64'], true);
		}
		return $v;
	}

	public static function humanBytes(int $bytes): string {
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$i = 0;
		$v = (float)$bytes;
		while ($v >= 1024 && $i < count($units) - 1) {
			$v /= 1024;
			$i++;
		}
		return $i === 0 ? "$bytes B" : sprintf('%.1f %s', $v, $units[$i]);
	}

	/**
	 * Minimal option parser: returns [positional args, options].
	 * Supports --flag, --key=value, --key value (for keys in $withValue), repeated keys become lists.
	 *
	 * @param list<string> $argv
	 * @param list<string> $withValue
	 * @return array{0: list<string>, 1: array<string, mixed>}
	 */
	public static function parseArgs(array $argv, array $withValue): array {
		$pos = [];
		$opts = [];
		$add = function (string $k, mixed $v) use (&$opts): void {
			if (!array_key_exists($k, $opts)) {
				$opts[$k] = $v;
			} elseif (is_array($opts[$k])) {
				$opts[$k][] = $v;
			} else {
				$opts[$k] = [$opts[$k], $v];
			}
		};
		for ($i = 0; $i < count($argv); $i++) {
			$a = $argv[$i];
			if ($a === '--') {
				array_push($pos, ...array_slice($argv, $i + 1));
				break;
			}
			if (str_starts_with($a, '--')) {
				$a = substr($a, 2);
				if (str_contains($a, '=')) {
					[$k, $v] = explode('=', $a, 2);
					$add($k, $v);
				} elseif (in_array($a, $withValue, true)) {
					if (!isset($argv[$i + 1])) {
						throw new \InvalidArgumentException("--$a needs a value");
					}
					$add($a, $argv[++$i]);
				} else {
					$add($a, true);
				}
			} else {
				$pos[] = $a;
			}
		}
		return [$pos, $opts];
	}

	/** @return list<string> */
	public static function listOpt(array $opts, string $key): array {
		if (!isset($opts[$key])) {
			return [];
		}
		$v = $opts[$key];
		$v = is_array($v) ? $v : [$v];
		$out = [];
		foreach ($v as $item) {
			foreach (explode(',', (string)$item) as $part) {
				if (trim($part) !== '') {
					$out[] = trim($part);
				}
			}
		}
		return $out;
	}
}
