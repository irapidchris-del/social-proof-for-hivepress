<?php
/**
 * GitHub-powered updates via the native WordPress update API.
 *
 * The plugin is distributed through GitHub Releases rather than wp.org, so
 * update checks go through the native `update_plugins_{$hostname}` filter
 * introduced in WordPress 5.8, keyed off the plugin's `Update URI` header.
 * No third-party libraries are used.
 *
 * The update package is the release asset whose name ends in `.zip`, which
 * must contain a single top-level `social-proof-for-hivepress` directory.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub-powered updates via the native WordPress update API.
 */
class Hpsp_Updater {

	/**
	 * GitHub repository in "owner/repo" form.
	 */
	const REPO = 'irapidchris-del/social-proof-for-hivepress';

	/**
	 * Plugin slug = plugin folder name = text domain.
	 */
	const SLUG = 'social-proof-for-hivepress';

	/**
	 * Site-transient cache key for the latest release lookup.
	 */
	const CACHE_KEY = 'hpsp_github_release';

	/**
	 * Register the update hooks.
	 */
	public static function init(): void {
		// The Update URI hostname is github.com, so this is the matching filter.
		add_filter( 'update_plugins_github.com', [ __CLASS__, 'check_for_update' ], 10, 3 );

		// Populate the "View version details" popup.
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_information' ], 10, 3 );

		// Keep updates installing into the current plugin directory.
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_update_directory' ], 10, 4 );

		// Manual "Check for updates" action link + handler + notice.
		$basename = plugin_basename( HPSP_FILE );

		add_filter( 'plugin_action_links_' . $basename, [ __CLASS__, 'add_update_check_link' ] );
		add_filter( 'network_admin_plugin_action_links_' . $basename, [ __CLASS__, 'add_update_check_link' ] );

		add_action( 'admin_init', [ __CLASS__, 'handle_update_check' ] );
		add_action( 'admin_notices', [ __CLASS__, 'show_update_check_notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'show_update_check_notice' ] );
	}

	/**
	 * Get the installed plugin version.
	 */
	public static function get_version(): string {
		return HPSP_VERSION;
	}

	/**
	 * Get the latest GitHub release details, cached for 6 hours.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string, string>|null
	 */
	public static function get_latest_release( bool $force = false ): ?array {
		$release = $force ? false : get_site_transient( self::CACHE_KEY );

		if ( ! is_array( $release ) ) {
			$release = self::fetch_latest_release();

			// Failures are cached briefly so the API is not queried repeatedly.
			set_site_transient( self::CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		}

		return $release ? $release : null;
	}

	/**
	 * Fetch the latest release details from the GitHub API.
	 *
	 * Draft and pre-release entries are excluded by the endpoint itself, so
	 * publishing a pre-release never triggers an update notice.
	 *
	 * @return array<string, string>
	 */
	protected static function fetch_latest_release(): array {
		// The explicit user-agent matters: without one WordPress sends
		// "WordPress/{version}; {site url}", leaking the site address and its
		// WordPress version to GitHub on every update check.
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout'    => 10,
				'user-agent' => self::SLUG . '/' . HPSP_VERSION,
				'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		// The version is read from the release tag, with or without a "v" prefix.
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );

		if ( ! $version ) {
			return [];
		}

		// The update package is the first release asset named `*.zip`.
		$package = '';

		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			$name = strtolower( (string) ( $asset['name'] ?? '' ) );

			if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];

				break;
			}
		}

		if ( ! $package ) {
			return [];
		}

		return [
			'version'   => $version,
			'package'   => $package,
			'url'       => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		];
	}

	/**
	 * Provide the update details to the WordPress update system.
	 *
	 * WordPress matches the plugin to this filter via the Update URI header
	 * hostname and compares the versions itself, filing the result under
	 * either the available updates or the up-to-date list.
	 *
	 * @param array<string, mixed>|false $update      Update data.
	 * @param array<string, string>      $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public static function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( HPSP_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::get_latest_release();

		if ( ! $release ) {
			return $update;
		}

		return [
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => self::SLUG,
			'plugin'  => $plugin_file,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		];
	}

	/**
	 * Provide the plugin details for the update information popup.
	 *
	 * Without this the "View version x.x.x details" link on the Plugins
	 * screen would open an empty modal, since the plugin is not on wp.org.
	 *
	 * @param object|array|false $result Result object.
	 * @param string             $action API action.
	 * @param object             $args   API arguments.
	 * @return object|array|false
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		$plugin_data = get_file_data(
			HPSP_FILE,
			[
				'Name'        => 'Plugin Name',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			]
		);

		$author = $plugin_data['AuthorURI']
			? '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>'
			: esc_html( $plugin_data['Author'] );

		return (object) [
			'name'          => $plugin_data['Name'],
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => $author,
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $plugin_data['RequiresWP'],
			'requires_php'  => $plugin_data['RequiresPHP'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'donate_link'   => 'https://ko-fi.com/chrisbathivepresscommunity',
			'sections'      => [
				'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
				'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'social-proof-for-hivepress' ) . '</p>',
			],
		];
	}

	/**
	 * Add the manual update-check link to the plugin row.
	 *
	 * @param array<string> $links Plugin action links.
	 * @return array<string>
	 */
	public static function add_update_check_link( $links ): array {
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hpsp_check_updates=1' ), 'hpsp_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'social-proof-for-hivepress' ) . '</a>';
		}

		return $links;
	}

	/**
	 * Handle the manual update check.
	 *
	 * Refreshes the cached release, re-runs the update check and redirects
	 * back to the Plugins screen with the result.
	 */
	public static function handle_update_check(): void {
		if ( ! isset( $_GET['hpsp_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'hpsp_check_updates' );

		$release = self::get_latest_release( true );

		wp_clean_plugins_cache();
		wp_update_plugins();

		$status = 'none';

		if ( ! $release ) {
			$status = 'error';
		} elseif ( version_compare( $release['version'], self::get_version(), '>' ) ) {
			$status = 'available';
		}

		wp_safe_redirect( add_query_arg( 'hpsp_checked', $status, self_admin_url( 'plugins.php' ) ) );

		exit;
	}

	/**
	 * Show the manual update-check result.
	 */
	public static function show_update_check_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only result flag set by our own nonce-checked redirect; nothing is processed or saved.
		if ( ! isset( $_GET['hpsp_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag; sanitised and matched against a fixed list below.
		$status = sanitize_key( wp_unslash( $_GET['hpsp_checked'] ) );

		if ( 'available' === $status ) {
			$release = self::get_latest_release();

			/* translators: %s: new version number. */
			$message = sprintf( __( 'A new version of Social Proof for HivePress (%s) is available.', 'social-proof-for-hivepress' ), $release ? $release['version'] : '' );
			$class   = 'notice-success';
		} elseif ( 'none' === $status ) {
			$message = __( 'Social Proof for HivePress is up to date.', 'social-proof-for-hivepress' );
			$class   = 'notice-success';
		} elseif ( 'error' === $status ) {
			$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'social-proof-for-hivepress' );
			$class   = 'notice-error';
		} else {
			return;
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Keep updates installing into the current plugin directory.
	 *
	 * The extracted release folder is renamed to match the directory the
	 * plugin is installed in, so an update can never end up in a differently
	 * named folder even if the release zip is packaged unexpectedly.
	 *
	 * @param string               $source        Extracted update source.
	 * @param string               $remote_source Remote source directory.
	 * @param object               $upgrader      Upgrader instance.
	 * @param array<string, mixed> $hook_extra    Extra hook arguments.
	 * @return string|\WP_Error
	 */
	public static function fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( plugin_basename( HPSP_FILE ) !== ( $hook_extra['plugin'] ?? '' ) || ! $wp_filesystem ) {
			return $source;
		}

		$directory = dirname( plugin_basename( HPSP_FILE ) );

		if ( '.' === $directory ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . $directory . '/';

		if ( trailingslashit( $source ) === $target ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
			return new \WP_Error( 'hpsp_rename_failed', __( 'Could not rename the update directory.', 'social-proof-for-hivepress' ) );
		}

		return $target;
	}
}
