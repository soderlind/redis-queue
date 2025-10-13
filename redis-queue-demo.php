<?php

/**
 * Plugin Name:       Redis Queue (Legacy Loader)
 * Plugin URI:        https://github.com/soderlind/redis-queue
 * Description:       Temporary loader file. New main file is redis-queue.php. Will be removed after folder rename.
 * Version:           2.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redis-queue
 * Domain Path:       /languages
 * Update URI:        false
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This file remains only to avoid immediate fatal if directory not yet renamed.
require_once __DIR__ . '/redis-queue.php';

// Load Composer autoload if present.
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// All logic handled by redis-queue.php.
