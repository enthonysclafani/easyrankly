<?php
/**
 * Enforces the architectural invariants listed in CLAUDE.md.
 *
 * Usage: php tools/check-architecture.php
 *
 * Development-only file: excluded from the distributable (see .gitattributes).
 *
 * @package EasyRankly
 */

declare( strict_types=1 );

namespace EasyRankly\Tools;

/**
 * Scans the plugin source for constructs that CLAUDE.md forbids.
 *
 * The checks work on PHP tokens, not on raw text, so names inside comments
 * never trigger a violation and method calls are never mistaken for functions.
 */
final class ArchitectureChecker {

	/**
	 * Directories scanned for PHP and block.json files, relative to the root.
	 *
	 * @var list<string>
	 */
	private const SCANNED_PATHS = array( 'easyrankly.php', 'uninstall.php', 'src', 'blocks' );

	/**
	 * Files allowed to use `eval`. Each entry needs a section in CLAUDE.md.
	 *
	 * @var list<string>
	 */
	private const EVAL_ALLOWLIST = array( 'src/CustomCode/PhpRunner.php' );

	/**
	 * Files allowed to touch `$wpdb`. Each entry needs approval and a comment explaining why the APIs fall short.
	 *
	 * @var list<string>
	 */
	private const WPDB_ALLOWLIST = array();

	/**
	 * Functions forbidden everywhere, grouped by the rule they break.
	 *
	 * @var array<string, list<string>>
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		'no-custom-tables' => array( 'dbdelta', 'maybe_create_table', 'maybe_add_column' ),
		'no-cron'          => array(
			'wp_schedule_event',
			'wp_schedule_single_event',
			'wp_reschedule_event',
			'wp_next_scheduled',
			'as_schedule_single_action',
			'as_schedule_recurring_action',
			'as_schedule_cron_action',
			'as_enqueue_async_action',
		),
		'no-disk-writes'   => array(
			'file_put_contents',
			'fopen',
			'fwrite',
			'fputs',
			'mkdir',
			'wp_mkdir_p',
			'rmdir',
			'unlink',
			'rename',
			'copy',
			'touch',
			'move_uploaded_file',
		),
		'no-raw-http'      => array( 'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen' ),
		'forbidden-php'    => array( 'create_function', 'extract', 'unserialize', 'assert' ),
	);

	/**
	 * Asset functions allowed only in admin code (a path segment named `Admin`).
	 *
	 * @var list<string>
	 */
	private const ADMIN_ONLY_FUNCTIONS = array(
		'wp_enqueue_script',
		'wp_enqueue_style',
		'wp_enqueue_script_module',
		'wp_add_inline_script',
		'wp_add_inline_style',
	);

	/**
	 * Hooks that load assets on the frontend.
	 *
	 * @var list<string>
	 */
	private const FRONTEND_ASSET_HOOKS = array( 'wp_enqueue_scripts', 'enqueue_block_assets', 'wp_print_scripts', 'wp_print_styles' );

	/**
	 * Hook registration functions that must run from a method, never at file level or in a constructor.
	 *
	 * @var list<string>
	 */
	private const HOOK_FUNCTIONS = array( 'add_action', 'add_filter' );

	/**
	 * Global functions of the WordPress AI Client, Abilities API and Connectors API: the free plugin has no AI
	 * (the agent lives in EasyRankly Pro).
	 *
	 * @var string
	 */
	private const AI_FUNCTION_PATTERN = '/^wp_(ai_|supports_ai$|(register|unregister|get|has)_abilit|\w*connector)/';

	/**
	 * Hook names of the Abilities API and Connectors API, forbidden for the same reason.
	 *
	 * @var string
	 */
	private const AI_HOOK_PATTERN = '/^wp_(abilities_api_|connectors_)\w*init$/';

	/**
	 * Names of the agent and of EasyRankly Pro (post types, options, hooks, slug): nothing in the free plugin
	 * is written for the Pro.
	 *
	 * @var string
	 */
	private const PRO_NAME_PATTERN = '/(?<![a-z])e(asy)?rankly[_-](proposal|memory|agent|pro)(?![a-z])/i';

	/**
	 * Keys of block.json that load assets on the frontend.
	 *
	 * @var list<string>
	 */
	private const FRONTEND_BLOCK_KEYS = array( 'viewScript', 'viewScriptModule', 'viewStyle', 'script', 'style' );

	/**
	 * Tokens that, right before a name, mean the name is not a global function call.
	 *
	 * @var list<int>
	 */
	private const NOT_A_FUNCTION_CALL = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );

	/**
	 * Absolute path of the plugin root, without trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Violations found so far.
	 *
	 * @var list<array{file: string, line: int, rule: string, message: string}>
	 */
	private array $violations = array();

	/**
	 * Sets the plugin root to scan.
	 *
	 * @param string $root Absolute path of the plugin root.
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Runs every check and returns the violations.
	 *
	 * @return list<array{file: string, line: int, rule: string, message: string}>
	 */
	public function check(): array {
		$this->violations = array();

		foreach ( $this->collect_files() as $relative ) {
			if ( str_ends_with( $relative, '.php' ) ) {
				$this->check_php_file( $relative );
			} elseif ( str_ends_with( $relative, 'block.json' ) ) {
				$this->check_block_json( $relative );
			}
		}

		$this->check_composer_json();

		return $this->violations;
	}

	/**
	 * Lists scanned files relative to the root, sorted for stable output.
	 *
	 * @return list<string>
	 */
	private function collect_files(): array {
		$files = array();

		foreach ( self::SCANNED_PATHS as $path ) {
			$absolute = $this->root . '/' . $path;

			if ( is_file( $absolute ) ) {
				$files[] = $path;
				continue;
			}

			if ( ! is_dir( $absolute ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}

				$name = $file->getFilename();
				if ( 'php' === $file->getExtension() || 'block.json' === $name ) {
					$files[] = substr( $file->getPathname(), strlen( $this->root ) + 1 );
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Checks one PHP file token by token.
	 *
	 * @param string $relative Path relative to the root.
	 */
	private function check_php_file( string $relative ): void {
		$code = (string) file_get_contents( $this->root . '/' . $relative );

		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			$this->add( $relative, $error->getLine(), 'syntax', $error->getMessage() );
			return;
		}

		$this->check_direct_access_guard( $relative, $code );

		$is_admin_path = (bool) preg_match( '#(^|/)Admin/#', $relative );
		$depth         = 0;
		// Stack of functions whose body is open: name and the brace depth at which the body started.
		$function_stack = array();
		$pending_name   = null;
		$count          = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_string( $token ) ) {
				if ( '{' === $token ) {
					++$depth;
					if ( null !== $pending_name ) {
						$function_stack[] = array(
							'name'  => $pending_name,
							'depth' => $depth,
						);
						$pending_name     = null;
					}
				} elseif ( '}' === $token ) {
					$innermost = end( $function_stack );
					if ( false !== $innermost && $innermost['depth'] === $depth ) {
						array_pop( $function_stack );
					}
					--$depth;
				} elseif ( ';' === $token ) {
					// Abstract and interface methods have no body.
					$pending_name = null;
				}
				continue;
			}

			list( $id, $text, $line ) = $token;

			if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
				continue;
			}

			if ( T_FUNCTION === $id ) {
				$next         = $this->next_significant( $tokens, $i );
				$pending_name = ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] )
					? strtolower( $tokens[ $next ][1] )
					: '{closure}';
				continue;
			}

			if ( T_EVAL === $id && ! $this->is_allowlisted( $relative, self::EVAL_ALLOWLIST ) ) {
				$this->add( $relative, $line, 'forbidden-php', '`eval` is allowed only in ' . implode( ', ', self::EVAL_ALLOWLIST ) . '.' );
				continue;
			}

			if ( T_VARIABLE === $id && '$wpdb' === $text && ! $this->is_allowlisted( $relative, self::WPDB_ALLOWLIST ) ) {
				$this->add( $relative, $line, 'no-direct-db', '`$wpdb` is forbidden: use WP_Query, the meta APIs or the Options API.' );
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) {
				$this->check_string( $relative, $line, $text );
				continue;
			}

			if ( T_STRING !== $id && T_NAME_FULLY_QUALIFIED !== $id ) {
				continue;
			}

			$next = $this->next_significant( $tokens, $i );
			if ( null === $next || '(' !== $tokens[ $next ] ) {
				continue;
			}

			$previous = $this->previous_significant( $tokens, $i );
			if ( null !== $previous && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], self::NOT_A_FUNCTION_CALL, true ) ) {
				continue;
			}

			$function_name = strtolower( ltrim( $text, '\\' ) );
			$this->check_function_call( $relative, $line, $function_name, $is_admin_path, $function_stack );
		}
	}

	/**
	 * Checks a call to a global function.
	 *
	 * @param string                                $relative       Path relative to the root.
	 * @param int                                   $line           Line of the call.
	 * @param string                                $function_name       Lowercase function name.
	 * @param bool                                  $is_admin_path  Whether the file lives under an `Admin` directory.
	 * @param list<array{name: string, depth: int}> $function_stack Functions whose body encloses the call.
	 */
	private function check_function_call( string $relative, int $line, string $function_name, bool $is_admin_path, array $function_stack ): void {
		foreach ( self::FORBIDDEN_FUNCTIONS as $rule => $functions ) {
			if ( in_array( $function_name, $functions, true ) ) {
				$this->add( $relative, $line, $rule, "`{$function_name}()` is forbidden (see CLAUDE.md)." );
				return;
			}
		}

		if ( preg_match( self::AI_FUNCTION_PATTERN, $function_name ) ) {
			$this->add( $relative, $line, 'no-ai', "`{$function_name}()` is forbidden: the free plugin has no AI (see CLAUDE.md)." );
			return;
		}

		if ( in_array( $function_name, self::ADMIN_ONLY_FUNCTIONS, true ) && ! $is_admin_path ) {
			$this->add( $relative, $line, 'no-frontend-assets', "`{$function_name}()` is allowed only in admin code (a path under `Admin/`)." );
			return;
		}

		if ( in_array( $function_name, self::HOOK_FUNCTIONS, true ) && str_starts_with( $relative, 'src/' ) ) {
			$named = array_values( array_filter( array_column( $function_stack, 'name' ), static fn( string $name ): bool => '{closure}' !== $name ) );

			if ( array() === $named ) {
				$this->add( $relative, $line, 'hooks-in-register', "`{$function_name}()` at file level: hook from `register()` or from a callback it registers." );
			} elseif ( '__construct' === end( $named ) ) {
				$this->add( $relative, $line, 'hooks-in-register', "`{$function_name}()` in a constructor: hook from `register()` instead." );
			}
		}
	}

	/**
	 * Checks a string literal for SQL schema changes, frontend asset hooks, AI hooks and names of the Pro.
	 *
	 * @param string $relative Path relative to the root.
	 * @param int    $line     Line of the string.
	 * @param string $text     Raw token text, quotes included.
	 */
	private function check_string( string $relative, int $line, string $text ): void {
		if ( preg_match( '/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $text ) ) {
			$this->add( $relative, $line, 'no-custom-tables', 'SQL schema changes are forbidden: store data with the native APIs.' );
			return;
		}

		$value = trim( $text, '\'"' );
		if ( in_array( $value, self::FRONTEND_ASSET_HOOKS, true ) ) {
			$this->add( $relative, $line, 'no-frontend-assets', "The `{$value}` hook loads frontend assets and is forbidden." );
			return;
		}

		if ( preg_match( self::AI_HOOK_PATTERN, $value ) ) {
			$this->add( $relative, $line, 'no-ai', "The `{$value}` hook is forbidden: the free plugin has no AI (see CLAUDE.md)." );
			return;
		}

		if ( preg_match( self::PRO_NAME_PATTERN, $value, $match ) ) {
			$this->add( $relative, $line, 'no-pro-code', "`{$match[0]}` belongs to the agent or to EasyRankly Pro: nothing in the free plugin is written for the Pro." );
		}
	}

	/**
	 * Requires the direct-access guard at the top of every runtime PHP file.
	 *
	 * @param string $relative Path relative to the root.
	 * @param string $code     File contents.
	 */
	private function check_direct_access_guard( string $relative, string $code ): void {
		$constant = 'uninstall.php' === $relative ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH';

		if ( ! preg_match( '/defined\(\s*\'' . $constant . '\'\s*\)\s*\|\|\s*exit\s*;/', $code ) ) {
			$this->add( $relative, 1, 'direct-access-guard', "Missing `defined( '{$constant}' ) || exit;`." );
		}
	}

	/**
	 * Forbids block.json keys that load frontend assets.
	 *
	 * @param string $relative Path relative to the root.
	 */
	private function check_block_json( string $relative ): void {
		$data = json_decode( (string) file_get_contents( $this->root . '/' . $relative ), true );

		if ( ! is_array( $data ) ) {
			$this->add( $relative, 1, 'syntax', 'Invalid JSON.' );
			return;
		}

		foreach ( self::FRONTEND_BLOCK_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$this->add( $relative, 1, 'no-frontend-assets', "block.json key `{$key}` loads frontend assets and is forbidden." );
			}
		}
	}

	/**
	 * Forbids runtime Composer dependencies: only PHP and extensions may be required.
	 */
	private function check_composer_json(): void {
		$path = $this->root . '/composer.json';
		if ( ! is_file( $path ) ) {
			return;
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			$this->add( 'composer.json', 1, 'syntax', 'Invalid JSON.' );
			return;
		}

		$require = isset( $data['require'] ) && is_array( $data['require'] ) ? $data['require'] : array();

		foreach ( array_keys( $require ) as $package ) {
			if ( 'php' !== $package && ! str_starts_with( (string) $package, 'ext-' ) ) {
				$this->add( 'composer.json', 1, 'no-runtime-deps', "Runtime dependency `{$package}` is forbidden: move it to require-dev or remove it." );
			}
		}
	}

	/**
	 * Whether a file is in an allowlist. Allowlists may be empty until an exception is approved.
	 *
	 * @param string   $relative  Path relative to the root.
	 * @param string[] $allowlist Allowed paths.
	 */
	private function is_allowlisted( string $relative, array $allowlist ): bool {
		return in_array( $relative, $allowlist, true );
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Starting index.
	 */
	private function next_significant( array $tokens, int $index ): ?int {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( ! $this->is_insignificant( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Starting index.
	 */
	private function previous_significant( array $tokens, int $index ): ?int {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( ! $this->is_insignificant( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Whether a token is whitespace or a comment.
	 *
	 * @param mixed $token Token.
	 */
	private function is_insignificant( $token ): bool {
		return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	}

	/**
	 * Records a violation.
	 *
	 * @param string $file    Path relative to the root.
	 * @param int    $line    Line number.
	 * @param string $rule    Rule identifier.
	 * @param string $message Explanation.
	 */
	private function add( string $file, int $line, string $rule, string $message ): void {
		$this->violations[] = array(
			'file'    => $file,
			'line'    => $line,
			'rule'    => $rule,
			'message' => $message,
		);
	}
}

// Run only when executed directly, so tests can load the class without side effects.
if ( 'cli' === PHP_SAPI && realpath( get_included_files()[0] ) === __FILE__ ) {
	$easyrankly_violations = ( new ArchitectureChecker( dirname( __DIR__ ) ) )->check();

	foreach ( $easyrankly_violations as $easyrankly_violation ) {
		fwrite( STDERR, sprintf( "%s:%d  [%s] %s\n", $easyrankly_violation['file'], $easyrankly_violation['line'], $easyrankly_violation['rule'], $easyrankly_violation['message'] ) );
	}

	if ( array() !== $easyrankly_violations ) {
		fwrite( STDERR, sprintf( "\n%d architecture violation(s). See CLAUDE.md, section \"Invarianti architetturali\".\n", count( $easyrankly_violations ) ) );
		exit( 1 );
	}

	fwrite( STDOUT, "Architecture invariants: OK\n" );
}
