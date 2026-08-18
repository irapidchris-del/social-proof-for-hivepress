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

		// filemtime() in the version guarantees fresh assets after every update.
		wp_enqueue_style( 'hpsp-admin', HPSP_URL . 'assets/css/admin.css', [], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'hpsp-admin', HPSP_URL . 'assets/js/admin.js', [ 'jquery', 'wp-color-picker' ], HPSP_VERSION . '.' . (int) filemtime( HPSP_DIR . 'assets/js/admin.js' ), true );

		wp_add_inline_script(
			'hpsp-admin',
			'window.HPSPAdmin = ' . wp_json_encode(
				[
					'shadows' => Hpsp_Settings::shadow_presets(),
					'i18n'    => [
						'chooseImage' => __( 'Choose fallback avatar', 'social-proof-for-hivepress' ),
						'useImage'    => __( 'Use this image', 'social-proof-for-hivepress' ),
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
		?>
		<div class="wrap hpsp-wrap">
			<h1><?php esc_html_e( 'Social Proof for HivePress', 'social-proof-for-hivepress' ); ?></h1>

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
		?>
		<h2 class="title"><?php esc_html_e( 'General', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Master switch, event capture rules and pages where popups should stay hidden.', 'social-proof-for-hivepress' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			self::field_checkbox( 'enabled', __( 'Enable popups', 'social-proof-for-hivepress' ), __( 'Master switch for all social-proof popups.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'exclude_admins', __( 'Ignore administrators', 'social-proof-for-hivepress' ), __( 'Don\'t create popups for actions performed by administrators.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'anonymise', __( 'Anonymise members', 'social-proof-for-hivepress' ), __( 'Show "Someone" instead of member names, and your anonymous image instead of any member photo. Listing images, icons and locations still show.', 'social-proof-for-hivepress' ), $settings );
			self::field_media_picker( 'anonymous_avatar', __( 'Anonymous image', 'social-proof-for-hivepress' ), __( 'Shown on anonymised popups instead of any member photo. Pick something neutral, such as your logo or a silhouette, because a recognisable person next to "Someone" implies they did it. Leave empty for a plain initial badge.', 'social-proof-for-hivepress' ), $settings );
			self::field_user_location_attribute( $settings );
			self::field_number( 'event_lifetime', __( 'Event lifetime (hours)', 'social-proof-for-hivepress' ), __( 'How long an event keeps appearing in popups. Older events are discarded so the feed never feels stale.', 'social-proof-for-hivepress' ), $settings, 1, 720 );
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

		<h2 class="title"><?php esc_html_e( 'Removing the plugin', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Your settings and your members\' preferences are kept if you delete this plugin, so you can reinstall it and carry on. WordPress shows its own warning on the delete screen saying the data goes too, but that warning is the same for every plugin and does not apply here unless you tick the box below. Switching the plugin off never removes anything.', 'social-proof-for-hivepress' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			self::field_checkbox( 'delete_data', __( 'Delete all data', 'social-proof-for-hivepress' ), __( 'Leave this unticked unless you are certain. With it ticked, deleting the plugin also removes every setting on this page and each member\'s "hide activity popups" choice. It cannot be undone and nothing asks you to confirm at the time. The popup queue and caches are always cleared on deletion either way.', 'social-proof-for-hivepress' ), $settings );
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
		?>
		<h2 class="title"><?php esc_html_e( 'Tracked events', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description hpsp-tokens-help">
			<?php esc_html_e( 'Switch each event type on or off and customise its popup message. Templates support basic HTML (links, bold, italics) and dynamic tokens. Tokens without a value for a given event are left blank.', 'social-proof-for-hivepress' ); ?>
		</p>
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
						<label for="hpsp-icon-<?php echo esc_attr( $type ); ?>" class="screen-reader-text"><?php esc_html_e( 'Icon', 'social-proof-for-hivepress' ); ?></label>
						<select id="hpsp-icon-<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $base ); ?>[icon]">
							<?php foreach ( Hpsp_Settings::allowed_icons() as $icon_name ) : ?>
								<option value="<?php echo esc_attr( $icon_name ); ?>" <?php selected( $icon_value, $icon_name ); ?>><?php echo esc_html( $icon_name ); ?></option>
							<?php endforeach; ?>
						</select>
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
		?>
		<h2 class="title"><?php esc_html_e( 'Position', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Choose where popups appear on desktop and mobile screens.', 'social-proof-for-hivepress' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			self::field_select( 'position', __( 'Desktop position', 'social-proof-for-hivepress' ), __( 'Corner of the screen where popups appear on desktop.', 'social-proof-for-hivepress' ), $settings, $positions );
			self::field_select( 'position_mobile', __( 'Mobile position', 'social-proof-for-hivepress' ), __( 'Used on screens narrower than 640px.', 'social-proof-for-hivepress' ), $settings, $positions );
			self::field_number( 'offset_x', __( 'Offset from the side (px)', 'social-proof-for-hivepress' ), __( 'Distance from the left or right screen edge on desktop. Raise it if popups sit on top of a floating button or bar in that corner.', 'social-proof-for-hivepress' ), $settings, 0, 400 );
			self::field_number( 'offset_y', __( 'Offset from the top or bottom (px)', 'social-proof-for-hivepress' ), __( 'Distance from the top or bottom screen edge on desktop. Popups also move up automatically while a Notifications for HivePress pop-up is using the same corner.', 'social-proof-for-hivepress' ), $settings, 0, 400 );
			self::field_checkbox( 'show_on_mobile', __( 'Show on mobile', 'social-proof-for-hivepress' ), __( 'Untick to hide popups on small screens entirely.', 'social-proof-for-hivepress' ), $settings );
			self::field_number( 'z_index', __( 'Z-index', 'social-proof-for-hivepress' ), __( 'Raise this if popups appear behind other elements.', 'social-proof-for-hivepress' ), $settings, 1, 2147483647 );
			?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Animation', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'How popups enter and leave the screen.', 'social-proof-for-hivepress' ); ?></p>
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

		<h2 class="title"><?php esc_html_e( 'Colours & shape', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Match the popups to your site design. The live preview updates as you change these.', 'social-proof-for-hivepress' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			self::field_color( 'bg_color', __( 'Background', 'social-proof-for-hivepress' ), __( 'Popup background colour.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'text_color', __( 'Text', 'social-proof-for-hivepress' ), __( 'Popup text colour.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'link_color', __( 'Links', 'social-proof-for-hivepress' ), __( 'Colour of links inside popups, such as listing titles.', 'social-proof-for-hivepress' ), $settings );
			self::field_color( 'border_color', __( 'Border', 'social-proof-for-hivepress' ), __( 'Border colour, visible when the border width is above zero.', 'social-proof-for-hivepress' ), $settings );
			self::field_number( 'border_width', __( 'Border width (px)', 'social-proof-for-hivepress' ), __( 'Set to 0 for no border.', 'social-proof-for-hivepress' ), $settings, 0, 10 );
			self::field_number( 'border_radius', __( 'Corner radius (px)', 'social-proof-for-hivepress' ), __( 'Use a high value like 999 for the classic pill shape.', 'social-proof-for-hivepress' ), $settings, 0, 999 );
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
			self::field_media_picker( 'fallback_avatar', __( 'Fallback avatar', 'social-proof-for-hivepress' ), __( 'Shown instead of the grey silhouette when a member has no profile photo (Gravatar). Members who have set a photo still see their own. Leave empty to use the default WordPress avatar.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_close', __( 'Show close button', 'social-proof-for-hivepress' ), __( 'Adds a small cross so visitors can dismiss popups.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_time', __( 'Show relative time', 'social-proof-for-hivepress' ), __( 'Adds a subtle "5 minutes ago" line to each popup.', 'social-proof-for-hivepress' ), $settings );
			self::field_checkbox( 'show_progress', __( 'Show countdown bar', 'social-proof-for-hivepress' ), __( 'A thin bar along the bottom of each popup empties as its time runs down, and pauses while the pointer is over it.', 'social-proof-for-hivepress' ), $settings );
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
		?>
		<h2 class="title"><?php esc_html_e( 'Timing & rotation', 'social-proof-for-hivepress' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Control how often popups appear and how the event feed rotates.', 'social-proof-for-hivepress' ); ?></p>
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
		?>
		<div class="hpsp-box hpsp-preview-box">
			<h2><?php esc_html_e( 'Live preview', 'social-proof-for-hivepress' ); ?></h2>
			<div id="hpsp-preview-stage" class="hpsp-preview-stage">
				<div id="hpsp-preview-toast" class="hpsp-preview-toast">
					<?php if ( $avatar ) : ?>
						<img id="hpsp-preview-img" src="<?php echo esc_url( $avatar ); ?>" alt="">
					<?php else : ?>
						<span class="hpsp-preview-initial"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 ) ); ?></span>
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
		$count = Hpsp_Events::count();
		?>
		<div class="hpsp-box">
			<h2><?php esc_html_e( 'Tools', 'social-proof-for-hivepress' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: number of queued events. */
					esc_html( _n( '%d event currently in the queue.', '%d events currently in the queue.', $count, 'social-proof-for-hivepress' ) ),
					(int) $count
				);
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
			__( 'Which user profile attribute supplies a member\'s location for sign-up popups and their %location% and %in_location% tokens. Only attributes on the user model are listed. Collecting real locations on profiles usually needs a Location attribute on the user model, which the Geolocation Plus extension makes available.', 'social-proof-for-hivepress' ),
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
				<button type="button" class="button hpsp-media-choose" data-hpsp-media="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Choose image', 'social-proof-for-hivepress' ); ?></button>
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
