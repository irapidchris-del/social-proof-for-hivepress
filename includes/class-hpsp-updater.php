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
	 * Why the last release check came back empty, so the notice can say which.
	 */
	const REASON_KEY = 'hpsp_github_release_reason';

	/**
	 * When GitHub's hourly allowance for this server is expected back. While this is set the
	 * API is not called at all, so a site that has run out does not spend the rest of the
	 * window making requests that can only fail.
	 */
	const RATE_LIMIT_KEY = 'hpsp_github_release_rate_limit';

	/**
	 * How many times restore_tokens() will sweep a line before giving up.
	 *
	 * Tokens nest one level in practice (a code span inside link text), so two passes is the real
	 * requirement; the rest is headroom for a shape nobody has written yet. It is a bound rather
	 * than a while-true because the token store is built from remote content.
	 */
	const RESTORE_PASS_LIMIT = 10;

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

		add_action( self::CACHE_KEY . '_refresh', [ __CLASS__, 'refresh_release' ] );
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
	 * Queues a background refresh of the release cache.
	 *
	 * Prefers HivePress's scheduler, which is Action Scheduler and already refuses a duplicate of a job
	 * with the same hook and arguments, so repeated admin requests coalesce into one fetch. WP-Cron is
	 * the fallback for the same reason it exists: it also runs the work outside this request.
	 *
	 * Neither is blocking, so where cron itself is starved the cache simply stays cold and no update is
	 * offered until somebody presses Check for updates, which always fetches at once.
	 *
	 * @return void
	 */
	public static function schedule_release_refresh() {
		$hook = self::CACHE_KEY . '_refresh';

		// Assigned and then tested: Core defines no __isset(), so isset( hivepress()->x ) is always
		// false even for a component that is present and working.
		$scheduler = function_exists( 'hivepress' ) ? hivepress()->scheduler : null;

		if ( $scheduler ) {
			$scheduler->add_action( $hook );

			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time(), $hook );
		}
	}

	/**
	 * Fills the release cache. Runs from the scheduler, never from a page render.
	 *
	 * @return void
	 */
	public static function refresh_release() {
		self::get_latest_release( true );
	}

	/**
	 * Get the latest GitHub release details, cached for 6 hours.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string, string>|null
	 */
	public static function get_latest_release( bool $force = false ): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( ! $force && is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		/*
		 * A cold cache must not be filled from somebody's page load. WordPress asks every plugin for its
		 * update details while rendering an admin request, so with several of these installed one such
		 * request made one blocking call to GitHub after another, in series: a site with nine of them
		 * measured 18.6 seconds on a settings screen, once, and then behaved perfectly for six hours
		 * because the answers were cached again. That is the same shape as the listing-save incident, on
		 * the admin side rather than the public one.
		 *
		 * So the fetch moves to a background job and this answers with what is already known. The manual
		 * Check for updates link still fetches immediately, because there a person is waiting for it.
		 */
		if ( ! $force ) {
			self::schedule_release_refresh();

			return null;
		}

		$release = self::fetch_latest_release();

		// A failed check must not erase what the last good one found. Overwriting the cache with an
		// empty result took a genuinely pending update off the Plugins screen for an hour with nothing
		// to say why, which is worse than showing a result that is at most a few hours old. The short
		// lifetime means the next check still tries again promptly.

		if ( ! $release && $cached ) {
			set_site_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );

			return $cached;
		}

		// Failures are cached briefly so the lookup is not repeated on every admin page load.
		set_site_transient( self::CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

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
		$data = self::fetch_release_data();

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
	 * Gets the latest release, from github.com in preference to the GitHub API.
	 *
	 * WHY THIS DOES NOT SIMPLY CALL THE API
	 *
	 * Without a token `api.github.com` allows **60 requests an hour per IP address**, and that
	 * allowance is shared by every plugin on the site, by every other site on the same server, and by
	 * anything else calling the API from that address. A site running several of these extensions,
	 * plus a few clicks of "Check for updates" - which deliberately bypasses the cache - spends it
	 * easily; on shared hosting a neighbouring site can spend it alone. GitHub then answers 403, and
	 * reporting that as "could not reach GitHub" sends the owner hunting a network fault that does not
	 * exist. That is the same family of bug as reporting a 404 as unreachable: a refusal is an answer,
	 * not a failure to get one.
	 *
	 * Everything this lookup needs is also published on github.com itself, which carries no such
	 * allowance:
	 *
	 *   - `/releases/latest` answers 302, and the Location header names the release GitHub considers
	 *     latest, with drafts and pre-releases excluded exactly as the API excludes them;
	 *   - `/releases/expanded_assets/{tag}` is the fragment the release page uses to list its own
	 *     downloads, so it names the asset;
	 *   - `/releases.atom` carries the release notes.
	 *
	 * Measured against GitHub's own rate-limit counter on 2026-08-19, thirteen full update checks
	 * through this route moved it by zero. The API is kept as a fallback so that a change at github.com
	 * cannot leave the plugin with no way to check at all.
	 *
	 * @return array<string, mixed>|null Release data in the API's own shape, or null.
	 */
	protected static function fetch_release_data() {
		$site = self::fetch_release_from_site();

		if ( isset( $site['release'] ) ) {
			delete_site_transient( self::REASON_KEY );

			return $site['release'];
		}

		// github.com has given a definite answer that nothing is published. Asking the API would only
		// repeat it, at the cost of one of the sixty.
		if ( isset( $site['reason'] ) && 'no_release' === $site['reason'] ) {
			set_site_transient( self::REASON_KEY, 'no_release', HOUR_IN_SECONDS );

			return null;
		}

		return self::fetch_release_from_api();
	}

	/**
	 * Reads the latest release from github.com, without touching the API allowance.
	 *
	 * @return array<string, mixed> Either a `release` in the API's shape, a `reason`, or empty to fall
	 *                              back to the API.
	 */
	protected static function fetch_release_from_site() {
		$base = 'https://github.com/' . self::REPO;

		$response = self::request(
			$base . '/releases/latest',
			[
				// Do not follow it. The redirect target is the answer.
				'redirection' => 0,
			]
		);

		if ( ! $response ) {
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// A repository with nothing published answers 404 here, which is the normal state of a new
		// repository rather than a fault.
		if ( 404 === $code ) {
			return [ 'reason' => 'no_release' ];
		}

		if ( 301 !== $code && 302 !== $code ) {
			return [];
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		// WordPress hands back an array when a header repeats.
		if ( is_array( $location ) ) {
			$location = end( $location );
		}

		if ( ! preg_match( '#/releases/tag/(.+)$#', (string) $location, $matches ) ) {
			return [];
		}

		$tag = rawurldecode( trim( $matches[1] ) );

		$asset = self::fetch_release_asset( $base, $tag );

		// No downloadable asset means there is nothing the updater could install, so let the API have
		// its say rather than reporting a release that cannot be applied.
		if ( ! $asset ) {
			return [];
		}

		$notes = self::fetch_release_notes( $base, $tag );

		// Shaped exactly like the API's own answer, so everything downstream is identical either way.
		return [
			'release' => [
				'tag_name'     => $tag,
				'html_url'     => $base . '/releases/tag/' . rawurlencode( $tag ),
				'body'         => $notes['body'],
				'published_at' => $notes['published'],
				'assets'       => [
					[
						'name'                 => $asset['name'],
						'browser_download_url' => $asset['url'],
					],
				],
			],
		];
	}

	/**
	 * Reads a release's asset from the fragment the release page uses to list its own downloads.
	 *
	 * @param string $base Repository URL.
	 * @param string $tag Release tag.
	 * @return array<string, string>|null
	 */
	protected static function fetch_release_asset( $base, $tag ) {
		$response = self::request( $base . '/releases/expanded_assets/' . rawurlencode( $tag ) );

		if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		if ( ! preg_match_all( '#href="(/[^"]*/releases/download/[^"]+\.zip)"#i', wp_remote_retrieve_body( $response ), $matches ) ) {
			return null;
		}

		// Take the first zip, matching what the API branch does with the assets list.
		$path = html_entity_decode( $matches[1][0], ENT_QUOTES, 'UTF-8' );

		return [
			'name' => rawurldecode( basename( $path ) ),
			'url'  => 'https://github.com' . $path,
		];
	}

	/**
	 * Reads a release's notes and publication date from the releases feed.
	 *
	 * Only the changelog in the plugin details popup depends on this, so a failure here is not fatal.
	 *
	 * @param string $base Repository URL.
	 * @param string $tag Release tag.
	 * @return array<string, string>
	 */
	protected static function fetch_release_notes( $base, $tag ) {
		$empty = [
			'body'      => '',
			'published' => '',
		];

		$response = self::request( $base . '/releases.atom' );

		if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $empty;
		}

		if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', wp_remote_retrieve_body( $response ), $entries ) ) {
			return $empty;
		}

		foreach ( $entries[1] as $entry ) {

			// Match the tag rather than taking the newest entry: the feed also carries pre-releases,
			// which the latest-release redirect deliberately skips.
			if ( false === strpos( $entry, '/releases/tag/' . $tag ) ) {
				continue;
			}

			$notes = '';

			if ( preg_match( '#<content[^>]*>(.*?)</content>#s', $entry, $content ) ) {
				$notes = self::release_notes_to_text( $content[1] );
			}

			$published = '';

			if ( preg_match( '#<updated>(.*?)</updated>#s', $entry, $updated ) ) {
				$published = trim( $updated[1] );
			}

			return [
				'body'      => $notes,
				'published' => $published,
			];
		}

		return $empty;
	}

	/**
	 * Turns the rendered notes in the feed back into the plain text the API would have returned.
	 *
	 * The API hands back the release body as it was written, in Markdown, and the details popup prints
	 * that as text. The feed carries the rendered HTML instead, so headings, bold runs and list items
	 * are put back into their Markdown spelling to keep the popup reading the same either way.
	 *
	 * @param string $html Rendered notes.
	 * @return string
	 */
	protected static function release_notes_to_text( $html ) {
		$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

		$text = preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', "\n**$1**\n", $text );
		$text = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $text );
		$text = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $text );
		$text = preg_replace( '#<li[^>]*>#i', "\n- ", $text );
		$text = preg_replace( '#</(p|div|ul|ol|li|pre|blockquote)>#i', "\n", $text );
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );

		$text = wp_strip_all_tags( (string) $text );

		// Collapse the blank lines the substitutions leave behind.
		$text = preg_replace( '#\n{3,}#', "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Reads the latest release from the GitHub API.
	 *
	 * Kept as a fallback only. See `fetch_release_data()` for why it is not the first choice.
	 *
	 * @return array<string, mixed>|null
	 */
	protected static function fetch_release_from_api() {

		// GitHub has already said the allowance is spent, so sit the window out rather than spending it
		// on requests that can only be refused.
		if ( get_site_transient( self::RATE_LIMIT_KEY ) ) {
			set_site_transient( self::REASON_KEY, 'rate_limited', HOUR_IN_SECONDS );

			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout'    => 10,
				'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

				// Our own User-Agent, because WordPress's default is "WordPress/{version}; {site url}"
				// (wp-includes/class-wp-http.php:211) and that puts the site's address and its exact
				// WordPress version into every release check. GitHub only requires that the header
				// identifies something, so this satisfies it while telling them nothing about the site.
				'user-agent' => self::SLUG . '/' . HPSP_VERSION,
			]
		);

		if ( is_wp_error( $response ) ) {
			set_site_transient( self::REASON_KEY, 'unreachable', HOUR_IN_SECONDS );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$reason = 404 === $code ? 'no_release' : 'unreachable';

			// A 403 or 429 with nothing left on the counter means this server's hourly allowance is
			// spent. Nothing is wrong with the site, the plugin or the repository, so it must not be
			// reported as though something were.
			if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
				$reason = 'rate_limited';
				$reset  = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
				$wait   = $reset > time() ? min( $reset - time(), HOUR_IN_SECONDS ) : 5 * MINUTE_IN_SECONDS;

				set_site_transient( self::RATE_LIMIT_KEY, $reset ? $reset : time() + $wait, $wait );
			}

			set_site_transient( self::REASON_KEY, $reason, HOUR_IN_SECONDS );

			return null;
		}

		delete_site_transient( self::RATE_LIMIT_KEY );
		delete_site_transient( self::REASON_KEY );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Makes a request to github.com.
	 *
	 * The User-Agent is set for the same reason as in the API branch: WordPress's default would put the
	 * site's address and its exact WordPress version into every check.
	 *
	 * @param string               $url Request URL.
	 * @param array<string, mixed> $args Extra request arguments.
	 * @return array<string, mixed>|null
	 */
	protected static function request( $url, $args = [] ) {
		$response = wp_remote_get(
			$url,
			array_merge(
				[
					'timeout'    => 10,
					'headers'    => [ 'Accept' => 'text/html, application/xml;q=0.9, */*;q=0.8' ],
					'user-agent' => self::SLUG . '/' . HPSP_VERSION,
				],
				$args
			)
		);

		return is_wp_error( $response ) ? null : $response;
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

		// With no reachable release, still file a self-describing entry so the
		// update transient carries our slug: without one WordPress has nothing
		// under response OR no_update, and the Plugins row silently degrades
		// from "View details" to a bare "Visit plugin site" link (found on
		// staging while the repository was still private).
		if ( ! $release ) {
			return [
				'id'      => 'https://github.com/' . self::REPO,
				'slug'    => self::SLUG,
				'plugin'  => $plugin_file,
				'version' => HPSP_VERSION,
				'url'     => 'https://github.com/' . self::REPO,
				'package' => '',
			];
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

		$information = (object) [
			'name'         => $plugin_data['Name'],
			'slug'         => self::SLUG,
			'version'      => HPSP_VERSION,
			'author'       => $author,
			'homepage'     => 'https://github.com/' . self::REPO,
			'requires'     => $plugin_data['RequiresWP'],
			'requires_php' => $plugin_data['RequiresPHP'],
			'donate_link'  => 'https://ko-fi.com/chrisbathivepresscommunity',
			'sections'     => [
				'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
				'changelog'   => '<p>' . esc_html__( 'The changelog could not be fetched from GitHub just now. See the GitHub releases page instead.', 'social-proof-for-hivepress' ) . '</p>',
			],
		];

		// With no reachable release the popup still renders from the local
		// headers above; a fetched release upgrades it with the real details.
		if ( $release ) {
			// Never show a version below the installed one: between pushing a
			// build and publishing its release, the latest published number is
			// older, and the popup read like a downgrade (found on staging).
			if ( version_compare( $release['version'], HPSP_VERSION, '>' ) ) {
				$information->version = $release['version'];
			}

			$information->last_updated  = $release['published'];
			$information->download_link = $release['package'];

			if ( $release['notes'] ) {
				// Release notes are Markdown; render them rather than showing
				// literal asterisks (found on staging).
				$information->sections['changelog'] = self::format_release_notes( $release['notes'] );
			} else {
				$information->sections['changelog'] = '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'social-proof-for-hivepress' ) . '</p>';
			}
		}

		return $information;
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

		// Read why the lookup ended as it did rather than inferring it from the result. Since a failed
		// check now keeps the last good answer, the presence of a release no longer proves the check
		// itself succeeded, and reporting a stale answer as a fresh one would be a lie.
		$reason = get_site_transient( self::REASON_KEY );

		if ( 'no_release' === $reason ) {
			$status = 'empty';
		} elseif ( 'rate_limited' === $reason ) {
			$status = 'limited';
		} elseif ( 'unreachable' === $reason ) {
			$status = 'error';
		} elseif ( $release && version_compare( $release['version'], self::get_version(), '>' ) ) {
			$status = 'available';
		} else {
			$status = 'none';
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
		} elseif ( 'empty' === $status ) {
			$message = __( 'No releases have been published for Social Proof for HivePress yet, so there is nothing to update to. This is normal for a brand new copy and does not mean anything is wrong.', 'social-proof-for-hivepress' );
			$class   = 'notice-info';
		} elseif ( 'limited' === $status ) {
			$message = __( 'GitHub limits how many update checks one server may make each hour, and this server has reached that limit. Nothing is wrong with the plugin or your site, and checking will work again within the hour.', 'social-proof-for-hivepress' );
			$class   = 'notice-warning';
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
	/**
	 * Renders the GitHub release body as HTML for the details popup.
	 *
	 * Release notes are written in Markdown, and WordPress prints the
	 * changelog tab as HTML, so passing the body straight through shows
	 * literal `**bold**` asterisks and runs bullet lists together.
	 *
	 * The body is remote content, so it is escaped FIRST and only then given
	 * the small set of tags below; the result goes through `wp_kses()` as a
	 * second belt. Only the constructs release notes actually use are
	 * handled: headings, bullet and numbered lists, fenced and inline code,
	 * bold (`**` and `__`), italics (guarded `*` and `_`) and http(s) links.
	 * Code spans and URLs are tokenised out before the emphasis rules run
	 * and restored afterwards - see the comment at the tokenising step.
	 *
	 * @param string $notes Release body in Markdown.
	 * @return string
	 */
	private static function format_release_notes( $notes ) {
		$text = esc_html( trim( (string) $notes ) );

		/*
		 * The `/u` flag is not cosmetic here. Without it `\R` also matches the
		 * single byte 0x85 (NEL), which occurs INSIDE ordinary UTF-8 emoji -
		 * U+2705 "white heavy check mark" encodes as E2 9C 85 - so a tick in
		 * the release notes was split mid-character, corrupting the glyph and
		 * breaking the line in two. Every pattern below is therefore UTF-8
		 * aware, and a failed match falls back rather than returning null.
		 */
		$lines = preg_split( '/\R/u', $text );

		if ( ! is_array( $lines ) ) {

			// Invalid UTF-8, or a PCRE limit on a very large body. Show
			// something readable rather than an empty changelog.
			return wpautop( $text );
		}

		$output   = '';
		$list_tag = '';
		$in_fence = false;

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			// Fenced code blocks are passed through verbatim, with no inline
			// formatting applied, so a snippet containing asterisks or
			// underscores survives intact.
			if ( preg_match( '/^\s*```/u', $line ) ) {
				if ( $in_fence ) {
					$output .= '</code></pre>';
				} else {
					$output  .= self::close_list( $list_tag ) . '<pre><code>';
					$list_tag = '';
				}

				$in_fence = ! $in_fence;

				continue;
			}

			if ( $in_fence ) {
				$output .= $line . "\n";

				continue;
			}

			/*
			 * Tokenise BEFORE transforming, transform, then restore. The
			 * emphasis patterns must never see the inside of a code span, a
			 * link URL or a bare URL: an adversarial review proved (by
			 * execution) that running them over the whole line first turned
			 * `/docs/_v2_/` into `/docs/emv2/em/` inside an href - esc_url()
			 * strips only the angle brackets of an injected tag and keeps its
			 * letters as path text - and chewed `__FILE__` inside backticks
			 * into straddled, unclosed tags that wp_kses does not rebalance
			 * (neither pass calls force_balance_tags). Running the link pass
			 * FIRST is not a fix either: the emphasis rules then eat the
			 * emitted href markup itself. Placeholders sidestep both orders.
			 * The token delimiter is a control character that esc_html leaves
			 * alone and legitimate notes never contain; any real occurrence
			 * is stripped first so remote content cannot address the token
			 * table.
			 */
			$tokens = [];
			$line   = str_replace( "\x1a", '', $line );

			// Inline code spans first, held verbatim so their asterisks and
			// underscores survive.
			$line = self::tokenise(
				'/`([^`]+)`/u',
				$line,
				$tokens,
				function ( $matches ) {
					return '<code>' . $matches[1] . '</code>';
				}
			);

			// Markdown links next. Only http(s) targets are matched at all;
			// the text was escaped above, so the URL is decoded before
			// esc_url() sees it. The URL part refuses the token delimiter, so
			// a backtick pair inside a URL (already lifted out as a code
			// span, which is also CommonMark's precedence) stops the link
			// forming rather than producing an anchor with a corrupted
			// target. Link text is kept verbatim, and MAY contain a code
			// token - a code span inside link text is legal Markdown.
			$line = self::tokenise(
				'/\[(.+?)\]\((https?:\/\/[^\s)\x1a]+)\)/u',
				$line,
				$tokens,
				function ( $matches ) {
					return '<a href="' . esc_url( html_entity_decode( $matches[2], ENT_QUOTES ) ) . '">' . $matches[1] . '</a>';
				}
			);

			// Bare URLs, kept as plain text but shielded from the emphasis
			// rules, so an underscore in a pasted URL is never eaten. This
			// pattern must also refuse the token delimiter: restoration is
			// single-pass, so a token swallowed into another token would
			// come back as raw control bytes instead of its content.
			$line = self::tokenise(
				'/https?:\/\/[^\s\x1a]+/u',
				$line,
				$tokens,
				function ( $matches ) {
					return $matches[0];
				}
			);

			// Emphasis, on prose only. Both double markers run before the
			// single ones so `__FILE__` reads as GitHub renders it (bold
			// FILE) rather than shedding stray underscores, and the single
			// rules require non-space, non-marker characters at BOTH ends -
			// the closing guard is what keeps "*.php and *.js" or "5 * 3"
			// from italicising half the sentence. The `<>` exclusions stop a
			// match crossing an already-emitted tag.
			$line = self::replace_safely( '/\*\*\*(.+?)\*\*\*/u', '<strong><em>$1</em></strong>', $line );
			$line = self::replace_safely( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line );
			$line = self::replace_safely( '/__(.+?)__/u', '<strong>$1</strong>', $line );
			$line = self::replace_safely( '/\*([^\s*<>](?:[^*<>]*[^\s*<>])?)\*/u', '<em>$1</em>', $line );
			$line = self::replace_safely( '/(?<![a-z0-9_])_([^_<>]+?)_(?![a-z0-9_])/iu', '<em>$1</em>', $line );

			// Restore the held-out spans.
			$line = self::restore_tokens( $line, $tokens );

			// Bullet and numbered lists, closing the other kind if it changes.
			$tag  = '';
			$item = [];

			if ( preg_match( '/^\s*[-*]\s+(.*)$/u', $line, $item ) ) {
				$tag = 'ul';
			} elseif ( preg_match( '/^\s*\d+\.\s+(.*)$/u', $line, $item ) ) {
				$tag = 'ol';
			}

			if ( $tag !== $list_tag ) {
				$output  .= self::close_list( $list_tag ) . ( $tag ? '<' . $tag . '>' : '' );
				$list_tag = $tag;
			}

			if ( $tag ) {
				$output .= '<li>' . $item[1] . '</li>';
			} elseif ( preg_match( '/^\s*#{1,6}\s+(.*)$/u', $line, $heading ) ) {
				$output .= '<h4>' . $heading[1] . '</h4>';
			} elseif ( '' !== trim( $line ) ) {
				$output .= '<p>' . $line . '</p>';
			}
		}

		$output .= self::close_list( $list_tag );

		if ( $in_fence ) {
			$output .= '</code></pre>';
		}

		return wp_kses(
			$output,
			[
				'p'      => [],
				'h4'     => [],
				'ul'     => [],
				'ol'     => [],
				'li'     => [],
				'pre'    => [],
				'strong' => [],
				'em'     => [],
				'code'   => [],
				'a'      => [ 'href' => [] ],
			]
		);
	}

	/**
	 * Closes an open list, if there is one.
	 *
	 * @param string $tag Currently open list tag, or an empty string.
	 * @return string
	 */
	private static function close_list( $tag ) {
		return $tag ? '</' . $tag . '>' : '';
	}

	/**
	 * Runs a replacement, keeping the original when the pattern fails.
	 *
	 * `preg_replace()` returns null on a PCRE error, such as the backtrack
	 * limit on a very long line or malformed UTF-8 under the `/u` flag.
	 * Assigning that straight back would silently blank the line.
	 *
	 * @param string $pattern Regular expression.
	 * @param string $replacement Replacement string.
	 * @param string $subject Subject string.
	 * @return string
	 */
	private static function replace_safely( $pattern, $replacement, $subject ) {
		$result = preg_replace( $pattern, $replacement, $subject );

		return null === $result ? $subject : $result;
	}

	/**
	 * Replaces every match with a placeholder, storing the rendered HTML.
	 *
	 * The placeholder is `\x1A{index}\x1A`; the caller strips any literal
	 * `\x1A` from the line first, so remote content can never collide with
	 * or address the token table.
	 *
	 * @param string   $pattern Regular expression.
	 * @param string   $line Subject line.
	 * @param array    $tokens Token store, passed by reference.
	 * @param callable $render Renders a match into final HTML.
	 * @return string
	 */
	private static function tokenise( $pattern, $line, &$tokens, $render ) {
		$result = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( &$tokens, $render ) {
				$tokens[] = call_user_func( $render, $matches );

				return "\x1a" . ( count( $tokens ) - 1 ) . "\x1a";
			},
			$line
		);

		return null === $result ? $line : $result;
	}

	/**
	 * Restores tokenised spans into the transformed line.
	 *
	 * SWEEPS IN A LOOP, BECAUSE TOKENS NEST. The link tokeniser above keeps its link text
	 * verbatim, and a code span inside link text is legal Markdown, so a link token routinely
	 * CONTAINS a code token. One pass replaces the outer token with text that still holds the
	 * inner placeholder, and the reader gets the index digit instead of the content.
	 *
	 * Reproduced by execution against this file on 2026-08-24, with the exact input from the
	 * report:
	 *
	 *   IN  : [see `wp_kses` docs](https://example.com/)
	 *   OUT : <p><a href="https://example.com/">see 0 docs</a></p>
	 *
	 * The 0x1A bytes themselves never reach the screen - wp_kses_no_null() drops \x0E-\x1F on
	 * the way out (wp-includes/kses.php:2025) - which is exactly why the symptom read as a stray
	 * digit rather than as something obviously broken.
	 *
	 * A sweep that returns null has hit a PCRE limit and a sweep that changes nothing has no more
	 * to do, so both end the loop and fall through to the strip below. That fall-through is the
	 * second half of the fix: returning the line untouched on a PCRE failure sent every held-out
	 * span in that line to the popup as a bare index digit.
	 *
	 * Anything still holding a placeholder when the sweeps end is STRIPPED rather than printed. A
	 * missing code span reads as an omission; half a placeholder reads as a broken plugin.
	 *
	 * The sweep pattern is deliberately not `/u`, unlike the patterns that ran before it. It
	 * matches one control byte and ASCII digits, so UTF-8 mode would not change what it matches,
	 * and it would add a way to fail: `/u` makes PCRE reject a subject it reads as invalid UTF-8
	 * outright, and a restoration that fails is the bug being fixed here.
	 *
	 * This is Persistent Account Menu 1.6.6's fix, ported. If a third plugin ever grows a copy of
	 * this token scheme, it needs this version, not the single-pass one.
	 *
	 * @param string $line Transformed line.
	 * @param array  $tokens Token store.
	 * @return string
	 */
	private static function restore_tokens( $line, $tokens ) {
		$passes = 0;

		while ( false !== strpos( $line, "\x1a" ) && $passes < self::RESTORE_PASS_LIMIT ) {
			++$passes;

			$result = preg_replace_callback(
				"/\x1a(\\d+)\x1a/",
				function ( $matches ) use ( $tokens ) {
					return isset( $tokens[ (int) $matches[1] ] ) ? $tokens[ (int) $matches[1] ] : '';
				},
				$line
			);

			if ( null === $result || $result === $line ) {
				break;
			}

			$line = $result;
		}

		if ( false === strpos( $line, "\x1a" ) ) {
			return $line;
		}

		/*
		 * Strip the survivors with plain string functions and no pattern at all. This branch is
		 * only ever reached because PCRE has just failed, so a strip that itself depends on PCRE
		 * succeeding is no belt at all: with the backtrack limit exhausted for both calls, a
		 * pattern strip left the index digit standing and the reader saw "the 0 docs" all over
		 * again, which is the very symptom being fixed. A delimiter with no closing partner keeps
		 * whatever followed it: only a whole placeholder is a placeholder.
		 */
		$stripped = '';
		$rest     = $line;

		while ( true ) {
			$start = strpos( $rest, "\x1a" );

			if ( false === $start ) {
				$stripped .= $rest;

				break;
			}

			$stripped .= substr( $rest, 0, $start );
			$rest      = substr( $rest, $start + 1 );

			// Drop the index digits with it, but only when a closing delimiter proves they were
			// an index and not the note's own text.
			$digits = strspn( $rest, '0123456789' );

			if ( $digits && isset( $rest[ $digits ] ) && "\x1a" === $rest[ $digits ] ) {
				$rest = substr( $rest, $digits + 1 );
			}
		}

		return $stripped;
	}
}
