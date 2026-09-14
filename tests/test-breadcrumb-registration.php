<?php
/**
 * Registration-time availability when core/breadcrumbs is deregistered on init.
 *
 * Default PHPUnit runs skip this unless ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS=1
 * is set before bootstrap (so the native block is unregistered at init priority 10,
 * before EasyRankly registers the legacy block at priority 11).
 */

final class ERankly_Breadcrumb_Registration_Test extends WP_UnitTestCase {

	public function test_legacy_inserter_reflects_actual_core_absence_at_init(): void {
		if ( ! getenv( 'ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS' ) ) {
			$this->markTestSkipped( 'Requires ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS=1 so core/breadcrumbs is deregistered during init.' );
		}

		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertFalse( $registry->is_registered( 'core/breadcrumbs' ) );
		$this->assertFalse( erankly_core_breadcrumbs_block_available() );

		$legacy = $registry->get_registered( 'easyrankly/breadcrumbs' );
		$this->assertNotNull( $legacy );
		$this->assertTrue( (bool) ( $legacy->supports['inserter'] ?? false ) );
		$this->assertFalse( $legacy->supports['html'] );
		$this->assertSame( array( 'wide', 'full' ), $legacy->supports['align'] );
	}
}
