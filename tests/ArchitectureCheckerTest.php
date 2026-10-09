<?php
/**
 * Tests for tools/check-architecture.php.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests;

use EasyRankly\Tools\ArchitectureChecker;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The architecture checker catches every forbidden construct and nothing else.
 */
final class ArchitectureCheckerTest extends TestCase {

	/**
	 * The real plugin source respects every invariant.
	 */
	public function test_plugin_source_respects_every_invariant(): void {
		$this->assertSame( array(), $this->violations( dirname( __DIR__ ) ) );
	}

	/**
	 * Allowed patterns (admin assets, hooks from callbacks, interpolated strings, names that only look like the
	 * agent's) are not reported.
	 */
	public function test_clean_fixture_has_no_violations(): void {
		$this->assertSame( array(), $this->violations( __DIR__ . '/fixtures/architecture/clean' ) );
	}

	/**
	 * Each forbidden construct is reported once, on its line, with its rule.
	 */
	public function test_each_forbidden_construct_is_reported(): void {
		$expected = array(
			'blocks/switcher/block.json:1:no-frontend-assets',
			'composer.json:1:no-runtime-deps',
			'src/Bad.php:1:direct-access-guard',
			'src/Bad.php:4:hooks-in-register',
			'src/Bad.php:8:hooks-in-register',
			'src/Bad.php:12:no-frontend-assets',
			'src/Bad.php:13:no-cron',
			'src/Bad.php:14:no-custom-tables',
			'src/Bad.php:14:no-custom-tables',
			'src/Bad.php:15:no-direct-db',
			'src/Bad.php:16:forbidden-php',
			'src/Bad.php:17:no-disk-writes',
			'src/Bad.php:18:no-frontend-assets',
			'src/Bad.php:19:no-ai',
			'src/Bad.php:20:no-ai',
			'src/Bad.php:21:no-pro-code',
			'src/Bad.php:22:no-pro-code',
		);

		$this->assertEqualsCanonicalizing( $expected, $this->violations( __DIR__ . '/fixtures/architecture/violations' ) );
	}

	/**
	 * Runs the checker and flattens violations to "file:line:rule".
	 *
	 * @param string $root Plugin root to scan.
	 * @return list<string>
	 */
	private function violations( string $root ): array {
		return array_map(
			static fn( array $violation ): string => $violation['file'] . ':' . $violation['line'] . ':' . $violation['rule'],
			( new ArchitectureChecker( $root ) )->check()
		);
	}
}
