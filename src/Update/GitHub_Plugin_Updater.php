<?php
namespace Soderlind\RedisQueueDemo\Update;

use \YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Generic WordPress Plugin GitHub Updater (namespaced copy)
 */
class GitHub_Plugin_Updater {
	private string $github_url;
	private string $branch;
	private string $name_regex;
	private string $plugin_slug;
	private string $plugin_file;
	private bool $enable_release_assets;

	public function __construct( array $config = [] ) {
		foreach ( [ 'github_url', 'plugin_file', 'plugin_slug' ] as $key ) {
			if ( empty( $config[ $key ] ) ) {
				throw new \InvalidArgumentException( "Required parameter '{$key}' is missing or empty." );
			}
		}
		$this->github_url            = $config[ 'github_url' ];
		$this->plugin_file           = $config[ 'plugin_file' ];
		$this->plugin_slug           = $config[ 'plugin_slug' ];
		$this->branch                = $config[ 'branch' ] ?? 'main';
		$this->name_regex            = $config[ 'name_regex' ] ?? '';
		$this->enable_release_assets = $config[ 'enable_release_assets' ] ?? ! empty( $this->name_regex );
		// add_action( 'init', [ $this, 'setup_updater' ] );
	}

	public function setup_updater(): void {
		try {
			$update_checker = PucFactory::buildUpdateChecker(
				$this->github_url,
				$this->plugin_file,
				$this->plugin_slug
			);
			$update_checker->setBranch( $this->branch );
			// if ( $this->enable_release_assets && ! empty( $this->name_regex ) ) {
			// 	$update_checker->getVcsApi()->enableReleaseAssets( $this->name_regex );
			// }
		} catch (\Exception $e) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GitHub Plugin Updater Error: ' . $e->getMessage() );
			}
		}
	}

	public static function create( string $github_url, string $plugin_file, string $plugin_slug, string $branch = 'main' ): self {
		return new self( [
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
		] );
	}

	public static function create_with_assets( string $github_url, string $plugin_file, string $plugin_slug, string $name_regex, string $branch = 'main' ): self {
		return new self( [
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
			'name_regex'  => $name_regex,
		] );
	}
}
