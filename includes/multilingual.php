<?php
/** Opt-in native module dispatcher. Exactly one implementation is parsed per request. */
defined( 'ABSPATH' ) || exit;

function erankly_multilingual_boot(): void {
	if ( ! erankly_multilingual_enabled() ) {
		return;
	}

	if ( 'multisite' === erankly_multilingual_mode() ) {
		require_once __DIR__ . '/multilingual/multisite/bootstrap.php';
		erankly_mlms_boot();
	} else {
		require_once __DIR__ . '/multilingual/singlesite/bootstrap.php';
		erankly_mlss_boot();
	}
}
