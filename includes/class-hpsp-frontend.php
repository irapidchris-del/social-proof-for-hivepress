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
	 * Enqueue frontend assets and inline configuration.
	 */
	public static function enqueue(): void {
		if ( ! self::should_display() ) {
			return;
		}

		$settings = Hpsp_Settings::get();

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
			'--hpsp-bg'        => $settings['bg_color'],
			'--hpsp-fg'        => $settings['text_color'],
			'--hpsp-link'      => $settings['link_color'],
			'--hpsp-bd-color'  => $settings['border_color'],
			'--hpsp-bd-width'  => absint( $settings['border_width'] ) . 'px',
			'--hpsp-off-x'     => absint( $settings['offset_x'] ) . 'px',
			'--hpsp-off-y'     => absint( $settings['offset_y'] ) . 'px',
			'--hpsp-radius'    => absint( $settings['border_radius'] ) . 'px',
			'--hpsp-shadow'    => isset( $shadows[ $settings['shadow'] ] ) ? $shadows[ $settings['shadow'] ] : $shadows['medium'],
			'--hpsp-font-size' => absint( $settings['font_size'] ) . 'px',
			'--hpsp-max-width' => absint( $settings['max_width'] ) . 'px',
			'--hpsp-anim-ms'   => absint( $settings['animation_speed'] ) . 'ms',
			'--hpsp-z'         => absint( $settings['z_index'] ),
		];

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
