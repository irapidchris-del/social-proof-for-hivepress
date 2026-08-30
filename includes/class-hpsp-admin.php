<?php
/**
 * Admin settings screen, live preview and maintenance actions.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings screen, live preview and maintenance actions.
 */
class Hpsp_Admin {

	const PAGE_SLUG      = 'hpsp';
	const SETTINGS_GROUP = 'hpsp';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );

		add_action( 'admin_post_hpsp_test_event', [ __CLASS__, 'handle_test_event' ] );
		add_action( 'admin_post_hpsp_clear_queue', [ __CLASS__, 'handle_clear_queue' ] );

		add_filter( 'plugin_action_links_' . plugin_basename( HPSP_FILE ), [ __CLASS__, 'action_links' ] );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'Social Proof for HivePress', 'social-proof-for-hivepress' ),
			__( 'Social Proof', 'social-proof-for-hivepress' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Register the settings option.
	 */
	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Hpsp_Settings::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ 'Hpsp_Settings', 'sanitize' ],
				'default'           => Hpsp_Settings::defaults(),
			]
		);
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );

		// Media library modal for the fallback avatar picker.
		wp_enqueue_media();

		// Font Awesome 7, for the live glyph previews in the icon picker.
		// HivePress's own FA5 bundle has no brand glyphs and none of the
		// FA6/7 names, so the picker loads the shared stylesheet itself.
		Hpsp_Frontend::enqueue_fontawesome();

		// filemtime() in the version guarantees fresh assets after every update.
		wp_enqueue_style( 'hpsp-admin', HPSP_URL . 'assets/css/admin.css', [], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'hpsp-admin', HPSP_URL . 'assets/js/admin.js', [ 'jquery', 'wp-color-picker' ], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/js/admin.js' ), true );

		wp_add_inline_script(
			'hpsp-admin',
			'window.HPSPAdmin = ' . wp_json_encode(
				[
					'shadows' => Hpsp_Settings::shadow_presets(),
					'i18n'    => [
						'chooseImage' => __( 'Choose image', 'social-proof-for-hivepress' ),
						'useImage'    => __( 'Use this image', 'social-proof-for-hivepress' ),
					],

					// Read by the shared settings-screen chrome, which expects
					// its wording under "labels". The nav's own label is
					// printed by render_page() rather than passed here,
					// because that nav is server-rendered on this screen.
					'labels'  => [
						'save'      => __( 'Save Changes', 'social-proof-for-hivepress' ),
						'backToTop' => __( 'Back to top', 'social-proof-for-hivepress' ),
					],
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Admin notices: HivePress detection and action feedback.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_ours = $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id;

		if ( $is_ours && ! function_exists( 'hivepress' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'HivePress does not appear to be active. Social Proof will still track core WordPress sign-ups, but HivePress events (listings, bookings, reviews, favourites, orders) require HivePress and its extensions.', 'social-proof-for-hivepress' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirects; nothing is processed or saved.
		if ( ! $is_ours || empty( $_GET['hpsp_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag; sanitised and matched against a fixed list below.
		$notice = sanitize_key( wp_unslash( $_GET['hpsp_notice'] ) );

		if ( 'test' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test popup queued! Open your site in a new tab to see it. Test popups expire automatically after 5 minutes.', 'social-proof-for-hivepress' ) . '</p></div>';
		} elseif ( 'cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The popup queue has been cleared.', 'social-proof-for-hivepress' ) . '</p></div>';
		}
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 */
	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : [];

		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'social-proof-for-hivepress' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Queue a test popup.
	 */
	public static function handle_test_event(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'social-proof-for-hivepress' ) );
		}

		check_admin_referer( 'hpsp_test_event' );

		Hpsp_Events::push(
			[
				'type'    => 'test',
				'user_id' => get_current_user_id(),
			]
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&hpsp_notice=test' ) );
		exit;
	}

	/**
	 * Clear the event queue.
	 */
	public static function handle_clear_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'social-proof-for-hivepress' ) );
		}

		check_admin_referer( 'hpsp_clear_queue' );

		Hpsp_Events::clear();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&hpsp_notice=cleared' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Page rendering.
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		$settings = Hpsp_Settings::get();

		$tabs = [
			'general' => __( 'General', 'social-proof-for-hivepress' ),
			'events'  => __( 'Events', 'social-proof-for-hivepress' ),
			'design'  => __( 'Design', 'social-proof-for-hivepress' ),
			'timing'  => __( 'Timing', 'social-proof-for-hivepress' ),
		];

		// Every section across the tabs, as id => [ tab, label ]. Drives the
		// quick-links bar below; the ids match the section headings rendered
		// by section_heading().
		$sections = [
			'general'   => [ 'general', __( 'General', 'social-proof-for-hivepress' ) ],
			'uninstall' => [ 'general', __( 'Removing the plugin', 'social-proof-for-hivepress' ) ],
			'events'    => [ 'events', __( 'Tracked events', 'social-proof-for-hivepress' ) ],
			'position'  => [ 'design', __( 'Position', 'social-proof-for-hivepress' ) ],
			'animation' => [ 'design', __( 'Animation', 'social-proof-for-hivepress' ) ],
			'colours'   => [ 'design', __( 'Colours & shape', 'social-proof-for-hivepress' ) ],
			'icons'     => [ 'design', __( 'Icon tiles', 'social-proof-for-hivepress' ) ],
			'timing'    => [ 'timing', __( 'Timing & rotation', 'social-proof-for-hivepress' ) ],
		];
		?>
		<div class="wrap hpsp-wrap">
			<h1><?php esc_html_e( 'Social Proof for HivePress', 'social-proof-for-hivepress' ); ?></h1>

			<?php
			/*
			 * The quick-links nav, carrying the shared marker class.
			 *
			 * hp-settings-nav is never styled: it exists so that every extension in this
			 * family can find a nav another one has already placed and stand down rather
			 * than draw a second (resources/hivepress-settings.md, "The settings anchor
			 * nav: one shared marker class"). It is on this one too even though nothing
			 * else can reach this page, because the marker is what the convention is,
			 * and a copy that carries it only where it is strictly needed is a copy that
			 * teaches the next reader the wrong rule.
			 *
			 * This nav is rendered here rather than injected by the script, which is the
			 * one place this screen genuinely differs from a HivePress settings tab: a
			 * link here has to switch to the section's tab before scrolling to it, so it
			 * needs the tab each section belongs to, which is known here and nowhere
			 * else. The label and the styling are the family's.
			 *
			 * The aria-label is deliberately absent. The visible label below names the
			 * nav for everybody; adding both would have a screen reader announce it twice.
			 */
			?>
			<nav class="hp-settings-nav hpsp-settings-nav">
				<?php // The colon is part of the wording: it reads as a lead-in to the links that follow it, not as a heading over them. ?>
				<span class="hpsp-settings-nav__label"><?php esc_html_e( 'Jump to a section:', 'social-proof-for-hivepress' ); ?></span>
				<?php foreach ( $sections as $section_id => $section ) : ?>
					<a href="#hpsp-sec-<?php echo esc_attr( $section_id ); ?>" data-hpsp-jump="<?php echo esc_attr( $section_id ); ?>" data-tab="<?php echo esc_attr( $section[0] ); ?>"><?php echo esc_html( $section[1] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="hpsp-layout">
				<form method="post" action="options.php" class="hpsp-main">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>

					<nav class="nav-tab-wrapper hpsp-tabs">
						<?php $first = true; ?>
						<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
							<a href="#hpsp-tab-<?php echo esc_attr( $tab_id ); ?>" class="nav-tab<?php echo $first ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $tab_label ); ?></a>
							<?php $first = false; ?>
						<?php endforeach; ?>
					</nav>

					<div id="hpsp-tab-general" class="hpsp-tab is-active">
						<?php self::render_general_tab( $settings ); ?>
					</div>

					<div id="hpsp-tab-events" class="hpsp-tab">
						<?php self::render_events_tab( $settings ); ?>
					</div>

					<div id="hpsp-tab-design" class="hpsp-tab">
						<?php self::render_design_tab( $settings ); ?>
					</div>

					<div id="hpsp-tab-timing" class="hpsp-tab">
						<?php self::render_timing_tab( $settings ); ?>
					</div>

					<?php submit_button(); ?>
				</form>

				<div class="hpsp-side">
					<?php self::render_preview_box(); ?>
					<?php self::render_tools_box(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * General tab.
	 *
	 * @param array $settings Current settings.
	 */
	protected static function render_general_tab( array $settings ): void {
		self::section_heading( 'general', __( 'General', 'social-proof-for-hivepress' ), __( 'Master switch, event capture rules and pages where popups should stay hidden.', 'social-proof-for-hivepress' ), false );
		?>
		<table class="form-table" role="presentation">
			<?php
			self::field_checkbox( 'enabled', __( 'Enable popups', 'social-proof-for-hivepress' ), __( 'Master switch for all social-proof popups.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'exclude_admins', __( 'Ignore administrators', 'social-proof-for-hivepress' ), __( 'Don\'t create popups for actions performed by administrators.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'anonymise', __( 'Anonymise members', 'social-proof-for-hivepress' ), __( 'Show "Someone" instead of member names and photos. Listing images, icons and locations still show.', 'social-proof-for-hivepress' ), $settings );
			self::field_media_picker( 'anonymous_avatar', __( 'Anonymous image', 'social-proof-for-hivepress' ), __( 'Shown on anonymised popups instead of any member photo. Pick something neutral, such as a logo. Leave empty for a plain initial badge.', 'social-proof-for-hivepress' ), $settings );
			self::field_user_location_attribute( $settings );
			self::field_number( 'event_lifetime', __( 'Event lifetime (hours)', 'social-proof-for-hivepress' ), __( 'How long an event keeps appearing in popups before it is discarded.', 'social-proof-for-hivepress' ), $settings, 1, 720 );
			self::field_number( 'queue_size', __( 'Queue size', 'social-proof-for-hivepress' ), __( 'Maximum number of recent events kept in the rotation.', 'social-proof-for-hivepress' ), $settings, 10, 200 );
			?>
			<tr>
				<th scope="row"><label for="hpsp-exclude_paths"><?php esc_html_e( 'Exclude pages', 'social-proof-for-hivepress' ); ?></label></th>
				<td>
					<textarea id="hpsp-exclude_paths" name="<?php echo esc_attr( self::name( 'exclude_paths' ) ); ?>" rows="4" class="large-text code" placeholder="/checkout&#10;/account/*"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL path per line where popups should not appear. Use * as a wildcard, e.g. /account/*.', 'social-proof-for-hivepress' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		self::section_heading( 'uninstall', __( 'Removing the plugin', 'social-proof-for-hivepress' ), __( 'Deleting this plugin keeps your settings and your members\' preferences unless you tick the box below, so a reinstall carries on where you left off. WordPress\'s generic delete-screen warning does not apply here. Switching the plugin off never removes anything.', 'social-proof-for-hivepress' ) );
		?>
		<table class="form-table" role="presentation">
			<?php
			self::field_checkbox( 'delete_data', __( 'Delete all data', 'social-proof-for-hivepress' ), __( 'With this ticked, deleting the plugin also removes every setting here and each member\'s "hide activity popups" choice, with no extra confirmation. The popup queue and caches are always cleared either way.', 'social-proof-for-hivepress' ), $settings );
			?>
		</table>
		<?php
	}

	/**
	 * Events tab: one card per event type.
	 *
	 * @param array $settings Current settings.
	 */
	protected static function render_events_tab( array $settings ): void {
		self::section_heading( 'events', __( 'Tracked events', 'social-proof-for-hivepress' ), __( 'Switch each event type on or off and customise its popup message. Templates support basic HTML and dynamic tokens; tokens without a value are left blank.', 'social-proof-for-hivepress' ), false );
		?>
		<?php
		foreach ( Hpsp_Events::types() as $type => $config ) :
			$event = $settings['events'][ $type ];
			$base  = Hpsp_Settings::OPTION_KEY . '[events][' . $type . ']';

			// An empty stored template means "use the built-in wording"; show
			// that wording so the box is editable, not mysteriously blank.
			$template_value = '' !== (string) $event['template'] ? $event['template'] : $config['template'];
			$icon_value     = ! empty( $event['icon'] ) ? $event['icon'] : $config['icon'];
			?>
			<div class="hpsp-event-card">
				<div class="hpsp-event-card__header">
					<label class="hpsp-switch">
						<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( ! empty( $event['enabled'] ) ); ?>>
						<strong><?php echo esc_html( $config['label'] ); ?></strong>
					</label>
					<span class="description"><?php echo esc_html( $config['description'] ); ?></span>
				</div>

				<p>
					<label for="hpsp-template-<?php echo esc_attr( $type ); ?>" class="hpsp-field-label"><?php esc_html_e( 'Message template', 'social-proof-for-hivepress' ); ?></label>
					<input type="text" id="hpsp-template-<?php echo esc_attr( $type ); ?>" class="large-text" name="<?php echo esc_attr( $base ); ?>[template]" value="<?php echo esc_attr( $template_value ); ?>">
				</p>

				<p>
					<label for="hpsp-image-<?php echo esc_attr( $type ); ?>" class="hpsp-field-label"><?php esc_html_e( 'Popup image', 'social-proof-for-hivepress' ); ?></label>
					<select id="hpsp-image-<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $base ); ?>[image]" data-hpsp-image="<?php echo esc_attr( $type ); ?>">
						<option value="avatar" <?php selected( $event['image'], 'avatar' ); ?>><?php esc_html_e( 'User avatar', 'social-proof-for-hivepress' ); ?></option>
						<option value="listing" <?php selected( $event['image'], 'listing' ); ?>><?php esc_html_e( 'Listing image (falls back to avatar)', 'social-proof-for-hivepress' ); ?></option>
						<option value="icon" <?php selected( $event['image'], 'icon' ); ?>><?php esc_html_e( 'Icon on a coloured tile', 'social-proof-for-hivepress' ); ?></option>
						<option value="none" <?php selected( $event['image'], 'none' ); ?>><?php esc_html_e( 'No image', 'social-proof-for-hivepress' ); ?></option>
					</select>

					<span class="hpsp-icon-choice" data-hpsp-icon-for="<?php echo esc_attr( $type ); ?>" <?php echo 'icon' === $event['image'] ? '' : 'hidden'; ?>>
						<?php self::icon_picker( $base . '[icon]', $type, $icon_value ); ?>
					</span>
				</p>

				<p class="description hpsp-tokens">
					<?php esc_html_e( 'Tokens:', 'social-proof-for-hivepress' ); ?>
					<?php foreach ( $config['tokens'] as $token ) : ?>
						<code>%<?php echo esc_html( $token ); ?>%</code>
					<?php endforeach; ?>
				</p>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Design tab.
	 *
	 * @param array $settings Current settings.
	 */
	protected static function render_design_tab( array $settings ): void {
		$positions = [
			'bottom-left'   => __( 'Bottom left', 'social-proof-for-hivepress' ),
			'bottom-center' => __( 'Bottom centre', 'social-proof-for-hivepress' ),
			'bottom-right'  => __( 'Bottom right', 'social-proof-for-hivepress' ),
			'top-left'      => __( 'Top left', 'social-proof-for-hivepress' ),
			'top-center'    => __( 'Top centre', 'social-proof-for-hivepress' ),
			'top-right'     => __( 'Top right', 'social-proof-for-hivepress' ),
		];
		self::section_heading( 'position', __( 'Position', 'social-proof-for-hivepress' ), __( 'Choose where popups appear on desktop and mobile screens.', 'social-proof-for-hivepress' ), false );
		?>
		<table class="form-table" role="presentation">
			<?php
			self::field_select( 'position', __( 'Desktop position', 'social-proof-for-hivepress' ), __( 'Corner of the screen where popups appear on desktop.', 'social-proof-for-hivepress' ), $settings, $positions );
			self::field_select( 'position_mobile', __( 'Mobile position', 'social-proof-for-hivepress' ), __( 'Used on screens narrower than 640px.', 'social-proof-for-hivepress' ), $settings, $positions );
			self::field_number( 'offset_x', __( 'Offset from the side (px)', 'social-proof-for-hivepress' ), __( 'Distance from the left or right screen edge on desktop. Raise it if popups cover a floating button in that corner.', 'social-proof-for-hivepress' ), $settings, 0, 400 );
			self::field_number( 'offset_y', __( 'Offset from the top or bottom (px)', 'social-proof-for-hivepress' ), __( 'Distance from the top or bottom screen edge on desktop. Popups move clear of Notifications for HivePress pop-ups automatically.', 'social-proof-for-hivepress' ), $settings, 0, 400 );
			self::field_checkbox( 'show_on_mobile', __( 'Show on mobile', 'social-proof-for-hivepress' ), __( 'Untick to hide popups on small screens entirely.', 'social-proof-for-hivepress' ), $settings );
			self::field_number( 'z_index', __( 'Z-index', 'social-proof-for-hivepress' ), __( 'Raise this if popups appear behind other elements.', 'social-proof-for-hivepress' ), $settings, 1, 2147483647 );
			?>
		</table>

		<?php self::section_heading( 'animation', __( 'Animation', 'social-proof-for-hivepress' ), __( 'How popups enter and leave the screen.', 'social-proof-for-hivepress' ) ); ?>
		<table class="form-table" role="presentation">
			<?php
			self::field_select(
				'animation',
				__( 'Style', 'social-proof-for-hivepress' ),
				__( 'Visitors with reduced-motion preferences always get a simple fade.', 'social-proof-for-hivepress' ),
				$settings,
				[
					'slide' => __( 'Slide', 'social-proof-for-hivepress' ),
					'fade'  => __( 'Fade', 'social-proof-for-hivepress' ),
					'pop'   => __( 'Pop', 'social-proof-for-hivepress' ),
				]
			);
			self::field_number( 'animation_speed', __( 'Speed (ms)', 'social-proof-for-hivepress' ), __( 'How long the show and hide animation takes, in milliseconds.', 'social-proof-for-hivepress' ), $settings, 100, 2000, 50 );
			?>
		</table>

		<?php self::section_heading( 'colours', __( 'Colours & shape', 'social-proof-for-hivepress' ), __( 'Match the popups to your site design. The live preview updates as you change these.', 'social-proof-for-hivepress' ) ); ?>
		<table class="form-table" role="presentation">
			<?php
			self::field_color( 'bg_color', __( 'Background', 'social-proof-for-hivepress' ), __( 'Popup background colour.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'text_color', __( 'Text', 'social-proof-for-hivepress' ), __( 'Popup text colour.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'link_color', __( 'Links', 'social-proof-for-hivepress' ), __( 'Colour of links inside popups, such as listing titles.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'border_color', __( 'Border', 'social-proof-for-hivepress' ), __( 'Border colour, visible when the border width is above zero.', 'social-proof-for-hivepress' ), $settings );
			self::field_number( 'border_width', __( 'Border width (px)', 'social-proof-for-hivepress' ), __( 'Set to 0 for no border.', 'social-proof-for-hivepress' ), $settings, 0, 10 );
			self::field_number( 'border_radius', __( 'Corner radius (px)', 'social-proof-for-hivepress' ), __( 'Use a high value like 999 for the classic pill shape. Capped at 16px when the image shape is square or rounded square.', 'social-proof-for-hivepress' ), $settings, 0, 999 );
			self::field_select(
				'shadow',
				__( 'Shadow', 'social-proof-for-hivepress' ),
				__( 'Drop shadow behind each popup.', 'social-proof-for-hivepress' ),
				$settings,
				[
					'none'   => __( 'None', 'social-proof-for-hivepress' ),
					'soft'   => __( 'Soft', 'social-proof-for-hivepress' ),
					'medium' => __( 'Medium', 'social-proof-for-hivepress' ),
					'strong' => __( 'Strong', 'social-proof-for-hivepress' ),
				]
			);
			self::field_number( 'font_size', __( 'Font size (px)', 'social-proof-for-hivepress' ), __( 'Size of the popup message text.', 'social-proof-for-hivepress' ), $settings, 10, 24 );
			self::field_number( 'max_width', __( 'Max width (px)', 'social-proof-for-hivepress' ), __( 'Widest a popup can grow on desktop screens.', 'social-proof-for-hivepress' ), $settings, 240, 640 );
			self::field_select(
				'image_style',
				__( 'Image shape', 'social-proof-for-hivepress' ),
				__( 'Shape of the avatar or listing image in each popup.', 'social-proof-for-hivepress' ),
				$settings,
				[
					'circle'  => __( 'Circle', 'social-proof-for-hivepress' ),
					'rounded' => __( 'Rounded square', 'social-proof-for-hivepress' ),
					'square'  => __( 'Square', 'social-proof-for-hivepress' ),
				]
			);
			self::field_media_picker( 'fallback_avatar', __( 'Fallback avatar', 'social-proof-for-hivepress' ), __( 'Shown when a member has no profile photo. Leave empty to use the default WordPress avatar.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_close', __( 'Show close button', 'social-proof-for-hivepress' ), __( 'Adds a small cross so visitors can dismiss popups.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_time', __( 'Show relative time', 'social-proof-for-hivepress' ), __( 'Adds a subtle "5 minutes ago" line to each popup.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_progress', __( 'Show countdown bar', 'social-proof-for-hivepress' ), __( 'A thin bar along the bottom of each popup empties as its time runs down, and pauses while the pointer is over it.', 'social-proof-for-hivepress' ), $settings );
			?>
		</table>

		<?php self::section_heading( 'icons', __( 'Icon tiles', 'social-proof-for-hivepress' ), __( 'Applies to events whose popup image is set to "Icon on a coloured tile" on the Events tab. The icon itself is chosen per event there.', 'social-proof-for-hivepress' ) ); ?>
		<table class="form-table" role="presentation">
			<?php
			self::field_number( 'icon_size', __( 'Icon size (px)', 'social-proof-for-hivepress' ), __( 'Size of the icon glyph. 0 = automatic, scaling with the popup text.', 'social-proof-for-hivepress' ), $settings, 0, 32 );
			self::field_select(
				'icon_weight',
				__( 'Icon weight', 'social-proof-for-hivepress' ),
				__( 'Thickens the icon strokes for a heavier look.', 'social-proof-for-hivepress' ),
				$settings,
				[
					'normal'   => __( 'Normal', 'social-proof-for-hivepress' ),
					'semibold' => __( 'Semi-bold', 'social-proof-for-hivepress' ),
					'bold'     => __( 'Bold', 'social-proof-for-hivepress' ),
				]
			);
			self::field_color( 'icon_color', __( 'Icon colour', 'social-proof-for-hivepress' ), __( 'Colour of the icon glyph on the tile.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'icon_bg_color', __( 'Tile background', 'social-proof-for-hivepress' ), __( 'Background of the icon tile. Leave empty to follow the link colour.', 'social-proof-for-hivepress' ), $settings );
			?>
		</table>
		<?php
	}

	/**
	 * Timing tab.
	 *
	 * @param array $settings Current settings.
	 */
	protected static function render_timing_tab( array $settings ): void {
		self::section_heading( 'timing', __( 'Timing & rotation', 'social-proof-for-hivepress' ), __( 'Control how often popups appear and how the event feed rotates.', 'social-proof-for-hivepress' ), false );
		?>
		<table class="form-table" role="presentation">
			<?php
			self::field_number( 'initial_delay', __( 'Initial delay (seconds)', 'social-proof-for-hivepress' ), __( 'Wait before showing the first popup after the page loads.', 'social-proof-for-hivepress' ), $settings, 0, 120 );
			self::field_number( 'display_duration', __( 'Display duration (seconds)', 'social-proof-for-hivepress' ), __( 'How long each popup stays on screen. Hovering pauses the timer.', 'social-proof-for-hivepress' ), $settings, 2, 60 );
			self::field_number( 'gap', __( 'Gap between popups (seconds)', 'social-proof-for-hivepress' ), __( 'Quiet time between one popup hiding and the next appearing.', 'social-proof-for-hivepress' ), $settings, 1, 120 );
			self::field_number( 'max_visible', __( 'Popups visible at once', 'social-proof-for-hivepress' ), __( 'How many popups can be on screen at the same time.', 'social-proof-for-hivepress' ), $settings, 1, 5 );
			self::field_number( 'max_per_page', __( 'Max popups per page view', 'social-proof-for-hivepress' ), __( '0 means unlimited.', 'social-proof-for-hivepress' ), $settings, 0, 100 );
			self::field_select(
				'order',
				__( 'Order', 'social-proof-for-hivepress' ),
				__( 'Show the newest events first, or shuffle them for variety.', 'social-proof-for-hivepress' ),
				$settings,
				[
					'newest' => __( 'Newest first', 'social-proof-for-hivepress' ),
					'random' => __( 'Random', 'social-proof-for-hivepress' ),
				]
			);
			self::field_checkbox( 'no_repeat', __( 'No repeats per session', 'social-proof-for-hivepress' ), __( 'Don\'t show a visitor the same event twice in one browsing session.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'loop', __( 'Loop events', 'social-proof-for-hivepress' ), __( 'Start over from the beginning after every event has been shown (ignored when "No repeats" is on).', 'social-proof-for-hivepress' ), $settings );
			self::field_number( 'snooze_duration', __( 'Snooze after close (minutes)', 'social-proof-for-hivepress' ), __( 'When a visitor closes a popup, hide all popups for this long. 0 closes only that popup.', 'social-proof-for-hivepress' ), $settings, 0, 10080 );
			?>
		</table>
		<?php
	}

	/**
	 * Live preview sidebar box.
	 */
	protected static function render_preview_box(): void {
		$user_id = get_current_user_id();
		$avatar  = get_avatar_url( $user_id, [ 'size' => 96 ] );
		$name    = Hpsp_Events::format_username( $user_id );
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		?>
		<div class="hpsp-box hpsp-preview-box">
			<h2><?php esc_html_e( 'Live preview', 'social-proof-for-hivepress' ); ?></h2>
			<div id="hpsp-preview-stage" class="hpsp-preview-stage">
				<div id="hpsp-preview-toast" class="hpsp-preview-toast">
					<?php if ( $avatar ) : ?>
						<?php // data-initial feeds the script's fallback when the avatar fails to load, which otherwise left an empty circle here. ?>
						<img id="hpsp-preview-img" src="<?php echo esc_url( $avatar ); ?>" alt="" data-initial="<?php echo esc_attr( $initial ); ?>">
					<?php else : ?>
						<span class="hpsp-preview-initial"><?php echo esc_html( $initial ); ?></span>
					<?php endif; ?>
					<div class="hpsp-preview-content">
						<span id="hpsp-preview-text"><strong><?php echo esc_html( $name ); ?></strong> <?php esc_html_e( 'just booked', 'social-proof-for-hivepress' ); ?> <a href="#" onclick="return false;"><?php esc_html_e( 'Sunny Loft Apartment', 'social-proof-for-hivepress' ); ?></a></span>
						<small id="hpsp-preview-time"><?php esc_html_e( '2 minutes ago', 'social-proof-for-hivepress' ); ?></small>
					</div>
					<button type="button" id="hpsp-preview-close" aria-hidden="true">&times;</button>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'Updates as you change the design settings. Use "Replay" to preview the animation.', 'social-proof-for-hivepress' ); ?></p>
			<p><button type="button" class="button" id="hpsp-preview-replay"><?php esc_html_e( 'Replay animation', 'social-proof-for-hivepress' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Tools sidebar box: test popup, clear queue, queue status.
	 */
	protected static function render_tools_box(): void {
		$stored = Hpsp_Events::count();
		$shown  = Hpsp_Events::display_count();
		?>
		<div class="hpsp-box">
			<h2><?php esc_html_e( 'Tools', 'social-proof-for-hivepress' ); ?></h2>
			<p>
				<?php
				if ( $shown === $stored ) {
					printf(
						/* translators: %d: number of queued events. */
						esc_html( _n( '%d event currently in the queue.', '%d events currently in the queue.', $stored, 'social-proof-for-hivepress' ) ),
						(int) $stored
					);
				} else {
					printf(
						/* translators: 1: number of events stored in the queue, 2: number of events ready to show. */
						esc_html( _n( '%1$d event in the queue, %2$d ready to show.', '%1$d events in the queue, %2$d ready to show.', $stored, 'social-proof-for-hivepress' ) ),
						(int) $stored,
						(int) $shown
					);
					?>
					<br>
					<span class="description"><?php esc_html_e( 'Events are held back when what they point at has since been cancelled or removed, or when their event type is switched off. They are cleared out automatically once they expire.', 'social-proof-for-hivepress' ); ?></span>
					<?php
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpsp-inline-form">
				<input type="hidden" name="action" value="hpsp_test_event">
				<?php wp_nonce_field( 'hpsp_test_event' ); ?>
				<?php submit_button( __( 'Send test popup', 'social-proof-for-hivepress' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpsp-inline-form">
				<input type="hidden" name="action" value="hpsp_clear_queue">
				<?php wp_nonce_field( 'hpsp_clear_queue' ); ?>
				<?php submit_button( __( 'Clear queue', 'social-proof-for-hivepress' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field helpers.
	// -------------------------------------------------------------------------

	/**
	 * Input name attribute for a top-level setting.
	 *
	 * @param string $key Setting key.
	 */
	protected static function name( string $key ): string {
		return Hpsp_Settings::OPTION_KEY . '[' . $key . ']';
	}

	/**
	 * Anchored section heading with an optional divider above it.
	 *
	 * The id must match an entry in the quick-links map in render_page(), so
	 * the bar at the top of the screen can jump straight to the section.
	 *
	 * @param string $id          Section id (without the hpsp-sec- prefix).
	 * @param string $title       Section title.
	 * @param string $description Section description.
	 * @param bool   $divider     Whether to draw a divider above the heading.
	 */
	protected static function section_heading( string $id, string $title, string $description = '', bool $divider = true ): void {
		if ( $divider ) {
			echo '<hr class="hpsp-divider">';
		}
		?>
		<h2 class="title hpsp-section-title" id="hpsp-sec-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></h2>
		<?php if ( $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
			<?php
		endif;
	}

	/**
	 * Icon picker: a toggle button showing the current glyph, opening a grid
	 * of radio options that each render their actual Font Awesome glyph.
	 *
	 * Previews render as `fa-solid fa-{slug}` / `fa-brands fa-{slug}` against
	 * the shared Font Awesome 7 stylesheet enqueued in enqueue() - HivePress's
	 * own FA5 bundle has no brand glyphs and none of the FA6/7 names (see
	 * Hpsp_Settings::icons()). The `fa-solid`/`fa-brands` class on the
	 * option carries the style to admin.js so the toggle button can mirror
	 * the chosen glyph.
	 *
	 * @param string $input_name Full input name attribute for the icon value.
	 * @param string $type       Event type, used for unique ids.
	 * @param string $current    Currently selected icon slug.
	 */
	protected static function icon_picker( string $input_name, string $type, string $current ): void {
		$icons         = Hpsp_Settings::icons();
		$current       = isset( $icons[ $current ] ) ? $current : (string) key( $icons );
		$current_class = ( 'brands' === $icons[ $current ] ? 'fa-brands' : 'fa-solid' ) . ' fa-' . $current;
		?>
		<span class="hpsp-icon-picker" data-hpsp-icon-picker="<?php echo esc_attr( $type ); ?>">
			<button type="button" class="button hpsp-icon-toggle" aria-expanded="false">
				<i class="hpsp-glyph <?php echo esc_attr( $current_class ); ?>" aria-hidden="true"></i>
				<span class="hpsp-icon-toggle__name"><?php echo esc_html( $current ); ?></span>
			</button>
			<span class="hpsp-icon-grid" hidden>
				<?php foreach ( $icons as $icon_name => $icon_group ) : ?>
					<label class="hpsp-icon-option">
						<input type="radio" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $icon_name ); ?>" <?php checked( $current, $icon_name ); ?>>
						<i class="hpsp-glyph <?php echo esc_attr( ( 'brands' === $icon_group ? 'fa-brands' : 'fa-solid' ) . ' fa-' . $icon_name ); ?>" aria-hidden="true"></i>
						<span class="hpsp-icon-option__name"><?php echo esc_html( $icon_name ); ?></span>
					</label>
				<?php endforeach; ?>
			</span>
		</span>
		<?php
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param array  $settings    Current settings.
	 */
	protected static function field_checkbox( string $key, string $label, string $description, array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> data-hpsp="<?php echo esc_attr( $key ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Number row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param array  $settings    Current settings.
	 * @param int    $min         Minimum value.
	 * @param int    $max         Maximum value.
	 * @param int    $step        Input step.
	 */
	protected static function field_number( string $key, string $label, string $description, array $settings, int $min, int $max, int $step = 1 ): void {
		?>
		<tr>
			<th scope="row"><label for="hpsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" id="hpsp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="<?php echo esc_attr( (string) $step ); ?>" class="small-text" data-hpsp="<?php echo esc_attr( $key ); ?>">
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Select row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param array  $settings    Current settings.
	 * @param array  $choices     Choice value => label map.
	 */
	protected static function field_select( string $key, string $label, string $description, array $settings, array $choices ): void {
		?>
		<tr>
			<th scope="row"><label for="hpsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="hpsp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" data-hpsp="<?php echo esc_attr( $key ); ?>">
					<?php foreach ( $choices as $value => $choice_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings[ $key ], $value ); ?>><?php echo esc_html( $choice_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * User location attribute row: maps a user profile attribute to the
	 * location tokens on sign-up popups.
	 *
	 * @param array $settings Current settings.
	 */
	protected static function field_user_location_attribute( array $settings ): void {
		$current = (string) $settings['user_location_attribute'];
		$options = [
			'' => __( 'None', 'social-proof-for-hivepress' ),
		];

		// User attributes registered on this site, admin-defined or programmatic.
		if ( function_exists( 'hivepress' ) ) {
			foreach ( hivepress()->attribute->get_attributes( 'user' ) as $name => $attribute ) {
				$label = '';

				if ( ! empty( $attribute['label'] ) ) {
					$label = (string) $attribute['label'];
				} elseif ( ! empty( $attribute['edit_field']['label'] ) ) {
					$label = (string) $attribute['edit_field']['label'];
				}

				// Geolocation's latitude/longitude companions are internal
				// coordinate fields; picking one rendered "in 55.9533"
				// (found on staging), so they are not offered.
				if ( preg_match( '/_(latitude|longitude)$/', $name ) ) {
					continue;
				}

				$options[ $name ] = '' !== $label ? $label . ' (' . $name . ')' : $name;
			}
		}

		// Keep a stored choice visible even while its attribute is missing,
		// so deactivating an extension never silently rewrites the setting.
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			/* translators: %s: the stored attribute name. */
			$options[ $current ] = sprintf( __( '%s (not currently registered)', 'social-proof-for-hivepress' ), $current );
		}

		self::field_select(
			'user_location_attribute',
			__( 'Member location attribute', 'social-proof-for-hivepress' ),
			// phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText, WordPress.WP.I18n.MissingTranslatorsComment -- %location% and %in_location% are literal token names shown to the admin, not printf placeholders.
			__( 'User profile attribute that fills the %location% and %in_location% tokens on sign-up popups. Collecting locations usually needs a Location attribute on the user model (Geolocation Plus).', 'social-proof-for-hivepress' ),
			$settings,
			$options
		);
	}

	/**
	 * Media-picker row: an attachment setting with a WordPress library
	 * picker (which also allows uploading), a preview and a remove button.
	 *
	 * @param string $key         Setting key holding the attachment ID.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param array  $settings    Current settings.
	 */
	protected static function field_media_picker( string $key, string $label, string $description, array $settings ): void {
		$attachment_id = absint( $settings[ $key ] );
		$preview_url   = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		?>
		<tr>
			<th scope="row"><label for="hpsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="hidden" id="hpsp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" data-hpsp="<?php echo esc_attr( $key ); ?>">
				<img class="hpsp-media-preview" data-hpsp-media="<?php echo esc_attr( $key ); ?>" src="<?php echo esc_url( $preview_url ); ?>" alt="" style="<?php echo $preview_url ? '' : 'display:none;'; ?>width:48px;height:48px;object-fit:cover;border-radius:50%;vertical-align:middle;margin-right:8px;">
				<button type="button" class="button hpsp-media-choose" data-hpsp-media="<?php echo esc_attr( $key ); ?>" data-hpsp-media-title="<?php echo esc_attr( $label ); ?>"><?php esc_html_e( 'Choose image', 'social-proof-for-hivepress' ); ?></button>
				<button type="button" class="button hpsp-media-remove" data-hpsp-media="<?php echo esc_attr( $key ); ?>" style="<?php echo $preview_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'social-proof-for-hivepress' ); ?></button>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Colour picker row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param array  $settings    Current settings.
	 */
	protected static function field_color( string $key, string $label, string $description, array $settings ): void {
		?>
		<tr>
			<th scope="row"><label for="hpsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="hpsp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( $settings[ $key ] ); ?>" class="hpsp-color" data-hpsp="<?php echo esc_attr( $key ); ?>" data-default-color="<?php echo esc_attr( Hpsp_Settings::defaults()[ $key ] ); ?>">
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
