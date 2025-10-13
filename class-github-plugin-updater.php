<?php
namespace Soderlind\RedisQueue\Update;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Generic WordPress Plugin GitHub Updater
 * 
 * Moved from includes/ to plugin root in version 1.0.0.
 * 
 * @package Soderlind\WordPress
 * @version 1.0.0
 */
class GitHub_Plugin_Updater {
	private $github_url;
	private $branch;
	private $name_regex;
	private $plugin_slug;
	private $plugin_file;
	private $enable_release_assets;

	public function __construct( $config = array() ) {
		$required = array( 'github_url', 'plugin_file', 'plugin_slug' );
		foreach ( $required as $key ) {
			if ( empty( $config[ $key ] ) ) {
				throw new \InvalidArgumentException( "Required parameter '{$key}' is missing or empty." );
			}
		}

		$this->github_url            = $config[ 'github_url' ];
		$this->plugin_file           = $config[ 'plugin_file' ];
		$this->plugin_slug           = $config[ 'plugin_slug' ];
		$this->branch                = isset( $config[ 'branch' ] ) ? $config[ 'branch' ] : 'main';
		$this->name_regex            = isset( $config[ 'name_regex' ] ) ? $config[ 'name_regex' ] : '';
		$this->enable_release_assets = isset( $config[ 'enable_release_assets' ] )
			? $config[ 'enable_release_assets' ]
			: ! empty( $this->name_regex );

		add_action( 'init', array( $this, 'setup_updater' ) );
	}

	public function setup_updater() {
		try {
			$update_checker = PucFactory::buildUpdateChecker(
				$this->github_url,
				$this->plugin_file,
				$this->plugin_slug
			);

			$update_checker->setBranch( $this->branch );

			if ( $this->enable_release_assets && ! empty( $this->name_regex ) ) {
				$update_checker->getVcsApi()->enableReleaseAssets( $this->name_regex );
			}
		} catch (\Exception $e) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GitHub Plugin Updater Error: ' . $e->getMessage() );
			}
		}
	}

	public static function create( $github_url, $plugin_file, $plugin_slug, $branch = 'main' ) {
		return new self( array(
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
		) );
	}

	public static function create_with_assets( $github_url, $plugin_file, $plugin_slug, $name_regex, $branch = 'main' ) {
		return new self( array(
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
			'name_regex'  => $name_regex,
		) );
	}
}
