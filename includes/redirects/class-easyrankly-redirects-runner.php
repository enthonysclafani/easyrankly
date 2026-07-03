<?php
/**
 * Frontend redirect runner.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs frontend redirect matching.
 */
final class EasyRankly_Redirects_Runner {
	/**
	 * Redirect repository.
	 *
	 * @var EasyRankly_Redirects_Repository
	 */
	private EasyRankly_Redirects_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param EasyRankly_Redirects_Repository $repository Redirect repository.
	 */
	public function __construct( EasyRankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register frontend hook.
	 */
	public function register_hooks(): void {
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Try to redirect the current frontend request.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $request_uri ) {
			return;
		}

		$current_path    = EasyRankly_Redirects_Normalizer::normalize_path( $request_uri );
			$source_hash = EasyRankly_Redirects_Normalizer::source_hash( $current_path );
			$redirect    = $this->repository->get_exact_rule_cached( $source_hash );
			$target_url  = '';

		if ( $redirect ) {
			$target_url = (string) $redirect['target_url'];
		} else {
			$patterns = $this->repository->get_pattern_rules();
			$redirect = $this->find_regex_match( $current_path, $patterns );

			if ( $redirect ) {
				if ( ! empty( $redirect['is_wildcard'] ) ) {
					$target_url = EasyRankly_Redirects_Normalizer::apply_wildcard_target(
						(string) $redirect['source_path'],
						$current_path,
						(string) $redirect['target_url']
					);
				} else {
					$target_url = EasyRankly_Redirects_Normalizer::apply_regex_target(
						(string) $redirect['source_path'],
						$current_path,
						(string) $redirect['target_url']
					);
				}
			}
		}

		if ( ! $redirect ) {
			return;
		}

		$status_code = (int) $redirect['status_code'];

		if ( ! EasyRankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			return;
		}

		$target_url = EasyRankly_Redirects_Normalizer::normalize_target_url( $target_url );

		if ( '' === $target_url || $this->is_loop( $current_path, $target_url ) ) {
			return;
		}

		// Global setting: never redirect administrators.
		if ( easyrankly_get_setting( 'redirect_exclude_admins', 0 ) && current_user_can( 'manage_options' ) ) {
			return;
		}

		// Per-redirect visibility condition.
		if ( ! $this->passes_visibility( $redirect ) ) {
			return;
		}

		$this->allow_safe_external_host_for_target( $target_url );
		$this->repository->increment_hit( (int) $redirect['id'] );

		wp_safe_redirect( $target_url, $status_code, 'EasyRankly' );
		exit;
	}

	/**
	 * Check whether a redirect should apply to the current visitor.
	 *
	 * @param array<string,mixed> $redirect Redirect row.
	 * @return bool
	 */
	private function passes_visibility( array $redirect ): bool {
		$visibility = isset( $redirect['visibility'] ) ? (string) $redirect['visibility'] : 'all';

		if ( 'all' === $visibility ) {
			return true;
		}

		if ( 'logged_out' === $visibility ) {
			return ! is_user_logged_in();
		}

		if ( 'logged_in' === $visibility ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			$required_role = isset( $redirect['required_role'] ) ? (string) $redirect['required_role'] : '';

			if ( '' === $required_role ) {
				return true;
			}

			$user = wp_get_current_user();

			return in_array( $required_role, (array) $user->roles, true );
		}

		return true;
	}

	/**
	 * Find a regex fallback match.
	 *
	 * @param string                         $current_path Normalized current path.
	 * @param array<int,array<string,mixed>> $redirects    Regex and wildcard redirects.
	 * @return array<string,mixed>|null
	 */
	private function find_regex_match( string $current_path, array $redirects ): ?array {
		// Realistic paths are short; a multi-kilobyte path is only ever a vector for
		// driving up pattern-matching cost, so refuse to run regexes against it.
		if ( strlen( $current_path ) > 4096 ) {
			return null;
		}

		// Lower the PCRE limits so a catastrophic (backtracking-heavy) stored pattern
		// bails out in microseconds instead of stalling the request. When a limit is
		// hit preg_match() returns false, which the strict 1 === check below treats as
		// "no match" — the matching stays fail-safe.
		// phpcs:disable WordPress.PHP.IniSet.Risky -- Deliberately *tightening* the PCRE limits as a ReDoS safeguard; both are restored to their previous values below.
		$prev_backtrack = ini_set( 'pcre.backtrack_limit', '100000' );
		$prev_recursion = ini_set( 'pcre.recursion_limit', '100000' );
		// phpcs:enable WordPress.PHP.IniSet.Risky

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporarily suppresses invalid stored regex warnings during matching.
			static function (): bool {
				return true;
			}
		);

		$match = null;
		foreach ( $redirects as $redirect ) {
			if ( ! empty( $redirect['is_wildcard'] ) ) {
				$pattern = EasyRankly_Redirects_Normalizer::build_wildcard_pattern( (string) $redirect['source_path'] );
			} else {
				$pattern = EasyRankly_Redirects_Normalizer::build_regex_pattern( (string) $redirect['source_path'] );
			}

			if ( 1 === preg_match( $pattern, $current_path ) ) {
				$match = $redirect;
				break;
			}
		}

		restore_error_handler();

		// phpcs:disable WordPress.PHP.IniSet.Risky -- Restoring the caller's previous PCRE limits.
		if ( false !== $prev_backtrack ) {
			ini_set( 'pcre.backtrack_limit', $prev_backtrack );
		}
		if ( false !== $prev_recursion ) {
			ini_set( 'pcre.recursion_limit', $prev_recursion );
		}
		// phpcs:enable WordPress.PHP.IniSet.Risky

		return $match;
	}

	/**
	 * Prevent redirects that resolve to the same local path.
	 *
	 * @param string $current_path Current normalized path.
	 * @param string $target_url Target URL.
	 * @return bool
	 */
	private function is_loop( string $current_path, string $target_url ): bool {
		$target_path = EasyRankly_Redirects_Normalizer::target_to_local_path( $target_url );

		return null !== $target_path && $target_path === $current_path;
	}

	/**
	 * Allow wp_safe_redirect() to redirect to a validated external target host.
	 *
	 * @param string $target_url Target URL.
	 */
	private function allow_safe_external_host_for_target( string $target_url ): void {
		if ( EasyRankly_Redirects_Normalizer::is_internal_url( $target_url ) ) {
			return;
		}

		if ( ! EasyRankly_Redirects_Normalizer::is_safe_absolute_url( $target_url ) ) {
			return;
		}

		$host = wp_parse_url( $target_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = strtolower( $host );

				return array_values( array_unique( $hosts ) );
			}
		);
	}
}
