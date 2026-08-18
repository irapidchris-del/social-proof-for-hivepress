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
	 * @param string $line Transformed line.
	 * @param array  $tokens Token store.
	 * @return string
	 */
	private static function restore_tokens( $line, $tokens ) {
		$result = preg_replace_callback(
			"/\x1a(\\d+)\x1a/",
			function ( $matches ) use ( $tokens ) {
				return isset( $tokens[ (int) $matches[1] ] ) ? $tokens[ (int) $matches[1] ] : '';
			},
			$line
		);

		return null === $result ? $line : $result;
	}

	/**
	 * Provides the plugin details for the update information popup.
	 *
	 * Without this the "View version x.x.x details" link on the Plugins
	 * screen would open an empty modal, since the plugin is not on wp.org.
	 *
	 * @param object|array|false $result Result object.
	 * @param string             $action API action.
	 * @param object             $args API arguments.
	 * @return object|array|false
	 */

}
