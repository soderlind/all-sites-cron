<?php
/**
 * PHPUnit bootstrap for All Sites Cron tests (Brain Monkey).
 *
 * No WordPress installation required — WP classes are stubbed and
 * WP functions are mocked via Brain Monkey.
 *
 * @package All_Sites_Cron
 */

// 1. Composer autoloader — loads Brain Monkey, Mockery, and PSR-4 src/ classes.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// 2. WordPress class stubs & helper functions.
require_once __DIR__ . '/stubs/wordpress.php';

// 3. Define WordPress constants the plugin expects at load time.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// 4. Temporarily set up Brain Monkey so WP function stubs exist
//    while we include the plugin file (it calls add_action, etc.).
Brain\Monkey\setUp();

Brain\Monkey\Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__ ) . '/' );
Brain\Monkey\Functions\when( 'register_activation_hook' )->justReturn( null );
Brain\Monkey\Functions\when( 'register_deactivation_hook' )->justReturn( null );

// 5. Load the plugin — defines constants and namespace functions.
require_once dirname( __DIR__ ) . '/all-sites-cron.php';

// 6. Tear down the bootstrap-phase Brain Monkey state.
//    Each test will call Monkey\setUp() / tearDown() independently.
Brain\Monkey\tearDown();
