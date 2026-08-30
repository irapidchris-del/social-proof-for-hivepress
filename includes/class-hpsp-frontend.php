<?php
/**
 * Frontend output: assets, inline configuration, the toast container, and
 * the per-user opt-out in HivePress account settings.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Frontend output and the per-user opt-out.
 */
class Hpsp_Frontend {

	const OPTOUT_FIELD = 'spf_hide_popups';

	// HivePress stores "_external" model fields as meta prefixed with "hp_".
	const OPTOUT_META = 'hp_spf_hide_popups';

	// Shared across this family of plugins on purpose: every plugin that
	// needs Font Awesome 6/7 registers this same handle behind a
	// wp_style_is() guard, so one copy loads however many are active.
	const FA_STYLE_HANDLE = 'freestylr-fontawesome';

	// Relative to the plugin root, and BUNDLED - see enqueue_fontawesome() below
	// for why a CDN URL must never go here.
	const FA_STYLE_PATH    = 'assets/vendor/fontawesome/css/all.min.css';
	const FA_STYLE_VERSION = '7.1.0';

	/**
	 * Cached display decision for the current request.
	 *
	 * @var bool|null
	 */
	protected static $should_display = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_root' ] );

		// Per-user opt-out, surfaced in HivePress Account → Settings.
		add_filter( 'hivepress/v1/models/user', [ __CLASS__, 'add_user_model_field' ] );
		add_filter( 'hivepress/v1/forms/user_update', [ __CLASS__, 'add_user_form_field' ], 10, 2 );
	}

	/**
	 * Decide whether popups should render on this request.
	 */
	public static function should_display(): bool {
		if ( null !== self::$should_display ) {
			return self::$should_display;
		}

		$settings = Hpsp_Settings::get();
		$display  = ! empty( $settings['enabled'] );

		// Respect the per-user opt-out.
		if ( $display && is_user_logged_in() && get_user_meta( get_current_user_id(), self::OPTOUT_META, true ) ) {
			$display = false;
		}

		// Respect excluded URL paths.
		if ( $display && ! empty( $settings['exclude_paths'] ) ) {
			$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';

			/*
			 * Two forms of the path are tested, because the setting could only ever have worked
			 * against one of them.
			 *
			 * REQUEST_URI is the path from the domain, so on a WordPress installed in a
			 * subdirectory the checkout page arrives as "/shop/checkout" while the field's own
			 * placeholder teaches "/checkout". The pattern never matched, the pop-ups carried on
			 * appearing on the pages the owner had excluded, and nothing anywhere said why. The
			 * site-relative form below is the one the UI teaches, so it is the one that has to
			 * work.
			 *
			 * The full path is still tested as well, so anybody who found the old behaviour and
			 * typed "/shop/checkout" to work around it does not have their setting quietly
			 * stop working on update.
			 */
			$candidates = [ $request_path ];

			$base = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

			if ( '' !== $base ) {
				if ( 0 === strcasecmp( $request_path, $base ) ) {
					$candidates[] = '/';
				} elseif ( 0 === stripos( $request_path, $base . '/' ) ) {
					$candidates[] = substr( $request_path, strlen( $base ) );
				}
			}

			foreach ( explode( "\n", $settings['exclude_paths'] ) as $pattern ) {
				$pattern = trim( $pattern );

				if ( '' === $pattern ) {
					continue;
				}

				$regex = '#^' . str_replace( '\*', '.*', preg_quote( untrailingslashit( $pattern ), '#' ) ) . '/?$#i';

				foreach ( $candidates as $candidate ) {
					if ( preg_match( $regex, untrailingslashit( $candidate ) ? untrailingslashit( $candidate ) : '/' ) ) {
						$display = false;
						break 2;
					}
				}
			}
		}

		/**
		 * Filter whether social-proof popups display on the current request.
		 *
		 * @param bool $display Display decision.
		 */
		self::$should_display = (bool) apply_filters( 'hpsp_display', $display );

		return self::$should_display;
	}

	/**
	 * Register (if no sibling already has) and enqueue the shared Font
	 * Awesome 7 stylesheet.
	 *
	 * HivePress core only bundles the Font Awesome 5 SOLID font, so FA6/7
	 * icon names and every brand icon would render as blank squares without
	 * this. Loaded under the shared `freestylr-fontawesome` handle (see the
	 * constants above), on the front end only when icon tiles are actually in
	 * use and on this plugin's own settings screen for the picker previews.
	 */
	public static function enqueue_fontawesome(): void {
		if ( ! wp_style_is( self::FA_STYLE_HANDLE, 'registered' ) ) {
			/*
			 * Font Awesome 7.1.0 Free is BUNDLED, in assets/vendor/fontawesome/. Never
			 * point this at cdnjs or any other CDN. A convenience CDN copy of a library
			 * is the exact case the offloaded-assets rule exists to catch
			 * (resources/security-standards.md, "Offloaded assets" - a remote asset is
			 * only acceptable when it is a service's own required SDK from that
			 * service's own domain), Plugin Check reported EnqueuedResourceOffloading on
			 * every plugin that did it, and Chris ruled on 2026-08-30 that the files
			 * ship with the plugin. It is also faster: cache partitioning (Chrome 86+,
			 * Firefox, Safari) means a CDN copy is a cold download for every site
			 * anyway, plus a DNS lookup and TLS handshake to a third origin.
			 *
			 * Layout matters. assets/vendor/fontawesome/css/all.min.css sits beside
			 * assets/vendor/fontawesome/webfonts/, so the stock "../webfonts/" paths
			 * inside the upstream CSS resolve unchanged. Three faces ship -
			 * fa-solid-900.woff2, fa-brands-400.woff2 and fa-regular-400.woff2 - and
			 * only the v4-compatibility @font-face block was removed from the CSS, so
			 * nothing can request a file that is not there. The regular face is NOT
			 * optional, and it costs ~19 KB: with no weight-400 face declared the
			 * browser silently substitutes the weight-900 solid one, so a far /
			 * fa-regular name draws a FILLED glyph instead of an outline. That shipped
			 * between 2026-08-29 and 2026-08-30 and read as somebody picking the wrong
			 * icon rather than as a missing font, which is why it survived a whole day.
			 *
			 * Pinned to 7.1.0, and every plugin sharing this handle must pin the
			 * identical version, because only the first registration of a shared handle
			 * wins. Full rule: resources/hivepress-ui.md, "FA6/7 and brand icons: bundle
			 * them, never load a CDN copy (2026-08-30)".
			 */
			wp_register_style(
				self::FA_STYLE_HANDLE,
				HPSP_URL . self::FA_STYLE_PATH,
				[],
				self::FA_STYLE_VERSION
			);
		}

		wp_enqueue_style( self::FA_STYLE_HANDLE );
	}

	/**
	 * Whether any enabled event renders an icon tile, which is the only
	 * front-end use of Font Awesome - avatars, listing images and initial
	 * badges need no icon font.
	 *
	 * @param array $settings Plugin settings.
	 */
	protected static function uses_icon_tiles( array $settings ): bool {
		foreach ( $settings['events'] as $event ) {
			if ( ! empty( $event['enabled'] ) && isset( $event['image'] ) && 'icon' === $event['image'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue frontend assets and inline configuration.
	 */
	public static function enqueue(): void {
		if ( ! self::should_display() ) {
			return;
		}

		$settings = Hpsp_Settings::get();

		if ( self::uses_icon_tiles( $settings ) ) {
			self::enqueue_fontawesome();
		}

		// filemtime() in the version guarantees fresh assets after every update.
		wp_enqueue_style( 'hpsp-frontend', HPSP_URL . 'assets/css/social-proof.css', [], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/css/social-proof.css' ) );
		wp_enqueue_script( 'hpsp-frontend', HPSP_URL . 'assets/js/social-proof.js', [], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/js/social-proof.js' ), true );

		$config = [
			'endpoint'        => esc_url_raw( rest_url( Hpsp_Rest::API_NAMESPACE . '/events' ) ),
			'viewer'          => get_current_user_id(),
			'mobile'          => ! empty( $settings['show_on_mobile'] ),
			'initialDelay'    => (int) $settings['initial_delay'] * 1000,
			'displayDuration' => (int) $settings['display_duration'] * 1000,
			'gap'             => (int) $settings['gap'] * 1000,
			'maxVisible'      => (int) $settings['max_visible'],
			'maxPerPage'      => (int) $settings['max_per_page'],
			'order'           => (string) $settings['order'],
			'noRepeat'        => ! empty( $settings['no_repeat'] ),
			'loop'            => ! empty( $settings['loop'] ),
			'snooze'          => (int) $settings['snooze_duration'] * 60 * 1000,
			'animation'       => (string) $settings['animation'],
			'animationSpeed'  => (int) $settings['animation_speed'],
			'showClose'       => ! empty( $settings['show_close'] ),
			'showTime'        => ! empty( $settings['show_time'] ),
			'showProgress'    => ! empty( $settings['show_progress'] ),
			'i18n'            => [
				'close'    => __( 'Close notification', 'social-proof-for-hivepress' ),
				'justNow'  => __( 'just now', 'social-proof-for-hivepress' ),
				/* translators: %d: number of minutes. */
				'minsAgo'  => __( '%d minutes ago', 'social-proof-for-hivepress' ),
				'minAgo'   => __( '1 minute ago', 'social-proof-for-hivepress' ),
				/* translators: %d: number of hours. */
				'hoursAgo' => __( '%d hours ago', 'social-proof-for-hivepress' ),
				'hourAgo'  => __( '1 hour ago', 'social-proof-for-hivepress' ),
				/* translators: %d: number of days. */
				'daysAgo'  => __( '%d days ago', 'social-proof-for-hivepress' ),
				'dayAgo'   => __( '1 day ago', 'social-proof-for-hivepress' ),
			],
		];

		wp_add_inline_script( 'hpsp-frontend', 'window.HPSPConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Print the toast container with its position classes and style variables.
	 */
	public static function render_root(): void {
		if ( ! self::should_display() ) {
			return;
		}

		$settings = Hpsp_Settings::get();
		$shadows  = Hpsp_Settings::shadow_presets();

		// Icon weight is drawn as a text stroke in the glyph's own colour, so
		// it thickens the strokes without needing extra font files.
		$icon_strokes = [
			'normal'   => '0',
			'semibold' => '0.3px',
			'bold'     => '0.5px',
		];

		$classes = [
			'hpsp-root',
			'hpsp-pos-' . $settings['position'],
			'hpsp-posm-' . $settings['position_mobile'],
			'hpsp-anim-' . $settings['animation'],
			'hpsp-img-' . $settings['image_style'],
		];

		if ( empty( $settings['show_on_mobile'] ) ) {
			$classes[] = 'hpsp-no-mobile';
		}

		// The variable names deliberately avoid the substrings "border-width"
		// and "border-color": WordPress core applies
		// `html :where([style*=border-width]) { border-style: solid; }` (and the
		// border-color twin) to ANY element whose style attribute contains those
		// substrings (wp-includes/css/dist/block-library/common.css:281), which
		// painted a stray 3px currentColor border around the popup container.
		$vars = [
			'--hpsp-bg'          => $settings['bg_color'],
			'--hpsp-fg'          => $settings['text_color'],
			'--hpsp-link'        => $settings['link_color'],
			'--hpsp-bd-color'    => $settings['border_color'],
			'--hpsp-bd-width'    => absint( $settings['border_width'] ) . 'px',
			'--hpsp-off-x'       => absint( $settings['offset_x'] ) . 'px',
			'--hpsp-off-y'       => absint( $settings['offset_y'] ) . 'px',
			'--hpsp-radius'      => absint( $settings['border_radius'] ) . 'px',
			'--hpsp-shadow'      => isset( $shadows[ $settings['shadow'] ] ) ? $shadows[ $settings['shadow'] ] : $shadows['medium'],
			'--hpsp-font-size'   => absint( $settings['font_size'] ) . 'px',
			// 0 means automatic: 1.1x the popup text, the size icon tiles have
			// always used, so existing sites look identical until they change it.
			'--hpsp-icon-size'   => absint( $settings['icon_size'] ) > 0 ? absint( $settings['icon_size'] ) . 'px' : '1.1em',
			'--hpsp-icon-fg'     => sanitize_hex_color( (string) $settings['icon_color'] ) ? $settings['icon_color'] : '#ffffff',
			'--hpsp-icon-stroke' => isset( $icon_strokes[ $settings['icon_weight'] ] ) ? $icon_strokes[ $settings['icon_weight'] ] : '0',
			'--hpsp-max-width'   => absint( $settings['max_width'] ) . 'px',
			'--hpsp-anim-ms'     => absint( $settings['animation_speed'] ) . 'ms',
			'--hpsp-z'           => absint( $settings['z_index'] ),
		];

		// Only set when chosen: with the variable absent, the stylesheet falls
		// back to the link colour, which is what icon tiles have always used.
		if ( '' !== (string) $settings['icon_bg_color'] && sanitize_hex_color( (string) $settings['icon_bg_color'] ) ) {
			$vars['--hpsp-icon-bg'] = $settings['icon_bg_color'];
		}

		$style = '';

		foreach ( $vars as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}

		printf(
			'<div id="hpsp-root" class="%s" style="%s" aria-live="polite" aria-atomic="false"></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style )
		);
	}

	/**
	 * Register the opt-out as an external user model field so HivePress
	 * persists it to user meta automatically.
	 *
	 * @param array $model User model args.
	 */
	public static function add_user_model_field( $model ): array {
		$model = is_array( $model ) ? $model : [];

		$model['fields'][ self::OPTOUT_FIELD ] = [
			'type'      => 'checkbox',
			'_external' => true,
		];

		return $model;
	}

	/**
	 * Surface the opt-out checkbox on the HivePress account settings form.
	 *
	 * Core applies a parent form's filter to its children as well: Form::__construct loops
	 * hp\get_class_parents() and fires the filter once per class in the chain
	 * (hivepress/includes/forms/class-form.php:159). User_Update_Profile extends User_Update, and
	 * that child form is the profile STEP of listing submission and vendor registration, so without
	 * this guard somebody part-way through submitting their first listing was asked whether they
	 * would like to hide activity pop-ups, at _order 1000 and therefore as the last thing above the
	 * submit button. Match one exact class. Vendor Analytics shipped this same bug in 1.8.0 and
	 * Holiday Mode guards against it; this was the last unguarded sibling.
	 *
	 * @param array  $form Form args.
	 * @param object $form_object Form object.
	 */
	public static function add_user_form_field( $form, $form_object = null ): array {
		$form = is_array( $form ) ? $form : [];

		// Fail closed: only the exact User_Update instance gets the field.
		if ( ! is_object( $form_object ) || 'HivePress\Forms\User_Update' !== get_class( $form_object ) ) {
			return $form;
		}

		$form['fields'][ self::OPTOUT_FIELD ] = [
			'caption' => __( 'Hide activity popups', 'social-proof-for-hivepress' ),
			'type'    => 'checkbox',
			'_order'  => 1000,
		];

		return $form;
	}
}
