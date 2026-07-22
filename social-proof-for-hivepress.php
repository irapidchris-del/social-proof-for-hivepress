<?php
/**
 * Plugin Name: Social Proof for HivePress (by Chris Bruce)
 * Description: Live, highly customisable social-proof popups for HivePress events (reviews, listings, bookings, users, orders, etc.).
 * Author: Chris Bruce
 * Version: 0.3.3
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0+
 * Text Domain: hp-social-proof
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------

define( 'HP_SPF_VER', '0.3.3' );
define( 'HP_SPF_FILE', __FILE__ );
define( 'HP_SPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'HP_SPF_URL', plugin_dir_url( __FILE__ ) );

// -----------------------------------------------------------------------------
// Admin
// -----------------------------------------------------------------------------

class HP_SPF_Admin {
	const OPTION_KEY = 'hp_spf_settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Shortcode fallback for opt-out if form integration is missing
		add_shortcode( 'hp_spf_optout', [ $this, 'optout_shortcode' ] );
		add_action( 'wp_ajax_hp_spf_optout', [ $this, 'ajax_save_optout' ] );
		add_action( 'wp_ajax_nopriv_hp_spf_optout', '__return_false' );

		// Clear queue handler
		add_action( 'admin_post_hp_spf_clear_queue', [ $this, 'handle_clear_queue' ] );
	}

	public static function defaults() : array {
		return [
			'enabled' => true,
			'queue_size' => 50,
			'event_lifetime_minutes' => 360,
			'rotation_interval_ms' => 7000,
			'display_duration_ms' => 5000,
			'max_concurrent' => 1,
			'position' => 'bottom-left',
			'animation' => 'slide',
			'appearance' => [
				'radius' => 9999,
				'bg' => '#111827',
				'fg' => '#F9FAFB',
				'link' => '#93C5FD',
				'border' => '1px solid rgba(255,255,255,0.12)',
				'shadow' => '0 10px 25px rgba(0,0,0,0.25)'
			],
			'enabled_events' => [
				'user_register' => true,
				'listing_published' => true,
				'booking_created' => true,
				'review_posted' => true,
				'order_paid' => true,
				'order_refund' => false
			],
			'templates' => [
				'user_register' => '[username] just signed up!',
				'listing_published' => '[username] just posted a new listing — [listing_title_link]',
				'booking_created' => '[username] just booked [listing_title_link] in [location]',
				'review_posted' => '[username] left a review for [listing_title_link]',
				'order_paid' => 'Order paid for [listing_title_link] by [username]',
				'order_refund' => 'Order refunded for [listing_title_link]'
			]
		];
	}

	public static function get_settings() : array {
		$settings = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( $settings, self::defaults() );
	}

	public function register_settings_page() {
		add_options_page(
			__( 'Social Proof (HivePress)', 'hp-social-proof' ),
			__( 'Social Proof (HivePress)', 'hp-social-proof' ),
			'manage_options',
			'hp-spf',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'hp_spf', self::OPTION_KEY, [
			'type' => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
			'default' => self::defaults(),
		] );
	}

	public function sanitize_settings( $value ) {
		$defaults = self::defaults();
		$value = is_array( $value ) ? $value : [];
		$value = wp_parse_args( $value, $defaults );
		$value['enabled'] = ! empty( $value['enabled'] );
		$value['queue_size'] = max( 5, absint( $value['queue_size'] ) );
		$value['event_lifetime_minutes'] = max( 5, absint( $value['event_lifetime_minutes'] ) );
		$value['rotation_interval_ms'] = max( 1000, absint( $value['rotation_interval_ms'] ) );
		$value['display_duration_ms'] = max( 1000, absint( $value['display_duration_ms'] ) );
		$value['max_concurrent'] = max( 1, absint( $value['max_concurrent'] ) );
		$value['position'] = in_array( $value['position'], [ 'bottom-left','bottom-right','top-left','top-right' ], true ) ? $value['position'] : 'bottom-left';
		$value['animation'] = in_array( $value['animation'], [ 'slide','fade' ], true ) ? $value['animation'] : 'slide';
		if ( ! is_array( $value['appearance'] ) ) { $value['appearance'] = $defaults['appearance']; }
		$value['appearance'] = array_merge( $defaults['appearance'], $value['appearance'] );
		return $value;
	}

	public function render_settings_page() {
		$settings = self::get_settings();
		$nonce = wp_create_nonce( 'hp_spf_clear_queue' );
		$clear_url = admin_url( 'admin-post.php?action=hp_spf_clear_queue&_wpnonce=' . $nonce );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Social Proof for HivePress — Settings', 'hp-social-proof' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'hp_spf' ); ?>
				<?php $opt = self::OPTION_KEY; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable popups', 'hp-social-proof' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'hp-social-proof' ); ?></label></td>
					</tr>
					<tr><th><?php esc_html_e( 'Position', 'hp-social-proof' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[position]">
								<?php foreach ( [ 'bottom-left','bottom-right','top-left','top-right' ] as $pos ) : ?>
									<option value="<?php echo esc_attr( $pos ); ?>" <?php selected( $settings['position'], $pos ); ?>><?php echo esc_html( ucfirst( str_replace( '-', ' ', $pos ) ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Animation', 'hp-social-proof' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[animation]">
								<?php foreach ( [ 'slide' => 'Slide', 'fade' => 'Fade' ] as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['animation'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Timing', 'hp-social-proof' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Rotation interval (ms)', 'hp-social-proof' ); ?> <input type="number" min="1000" name="<?php echo esc_attr( $opt ); ?>[rotation_interval_ms]" value="<?php echo esc_attr( $settings['rotation_interval_ms'] ); ?>"></label>
							<br>
							<label><?php esc_html_e( 'Display duration (ms)', 'hp-social-proof' ); ?> <input type="number" min="1000" name="<?php echo esc_attr( $opt ); ?>[display_duration_ms]" value="<?php echo esc_attr( $settings['display_duration_ms'] ); ?>"></label>
							<br>
							<label><?php esc_html_e( 'Event lifetime (minutes)', 'hp-social-proof' ); ?> <input type="number" min="5" name="<?php echo esc_attr( $opt ); ?>[event_lifetime_minutes]" value="<?php echo esc_attr( $settings['event_lifetime_minutes'] ); ?>"></label>
							<br>
							<label><?php esc_html_e( 'Max concurrent', 'hp-social-proof' ); ?> <input type="number" min="1" name="<?php echo esc_attr( $opt ); ?>[max_concurrent]" value="<?php echo esc_attr( $settings['max_concurrent'] ); ?>"></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Appearance', 'hp-social-proof' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Border radius (px)', 'hp-social-proof' ); ?> <input type="number" min="0" name="<?php echo esc_attr( $opt ); ?>[appearance][radius]" value="<?php echo esc_attr( $settings['appearance']['radius'] ); ?>"></label><br>
							<label><?php esc_html_e( 'Background', 'hp-social-proof' ); ?> <input type="text" name="<?php echo esc_attr( $opt ); ?>[appearance][bg]" value="<?php echo esc_attr( $settings['appearance']['bg'] ); ?>" class="regular-text"></label><br>
							<label><?php esc_html_e( 'Text colour', 'hp-social-proof' ); ?> <input type="text" name="<?php echo esc_attr( $opt ); ?>[appearance][fg]" value="<?php echo esc_attr( $settings['appearance']['fg'] ); ?>" class="regular-text"></label><br>
							<label><?php esc_html_e( 'Link colour', 'hp-social-proof' ); ?> <input type="text" name="<?php echo esc_attr( $opt ); ?>[appearance][link]" value="<?php echo esc_attr( $settings['appearance']['link'] ); ?>" class="regular-text"></label><br>
							<label><?php esc_html_e( 'Border', 'hp-social-proof' ); ?> <input type="text" name="<?php echo esc_attr( $opt ); ?>[appearance][border]" value="<?php echo esc_attr( $settings['appearance']['border'] ); ?>" class="regular-text"></label><br>
							<label><?php esc_html_e( 'Shadow', 'hp-social-proof' ); ?> <input type="text" name="<?php echo esc_attr( $opt ); ?>[appearance][shadow]" value="<?php echo esc_attr( $settings['appearance']['shadow'] ); ?>" class="regular-text"></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Enable / disable events', 'hp-social-proof' ); ?></th>
						<td>
							<?php foreach ( [
								'user_register' => 'User registered',
								'listing_published' => 'Listing published',
								'booking_created' => 'Booking created',
								'review_posted' => 'Review posted',
								'order_paid' => 'Order paid',
								'order_refund' => 'Order refunded',
							] as $key => $label ) : ?>
								<label style="display:block;margin:4px 0;"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled_events][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['enabled_events'][ $key ] ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Event Templates', 'hp-social-proof' ); ?></h2>
				<p><?php esc_html_e( 'Placeholders: [username], [listing_title], [listing_title_link], [vendor_name], [booking_start], [booking_end], [location], [location_link].', 'hp-social-proof' ); ?></p>
				<?php foreach ( $settings['templates'] as $key => $template ) : ?>
					<p><label><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></strong><br>
						<input type="text" class="regular-text" style="width: 100%;" name="<?php echo esc_attr( $opt ); ?>[templates][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $template ); ?>"></label></p>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
			<hr>
			<form method="post" action="<?php echo esc_url( $clear_url ); ?>">
				<?php submit_button( __( 'Clear Pop-up Queue Now', 'hp-social-proof' ), 'delete' ); ?>
			</form>
			<p><em><?php esc_html_e( 'If you do not see the opt-out checkbox in Account → Settings, you can place this shortcode on that page:', 'hp-social-proof' ); ?></em>
			<code>[hp_spf_optout]</code></p>
		</div>
		<?php
	}

	public function handle_clear_queue() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Unauthorized.', 'hp-social-proof' ) ); }
		check_admin_referer( 'hp_spf_clear_queue' );
		delete_option( HP_SPF_Events::QUEUE_OPTION );
		wp_safe_redirect( admin_url( 'options-general.php?page=hp-spf&hp_spf_cleared=1' ) );
		exit;
	}

	// Shortcode fallback renderer.
	public function optout_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) { return ''; }
		$checked = get_user_meta( get_current_user_id(), 'hp_spf_optout', true ) ? 'checked' : '';
		$nonce = wp_create_nonce( 'hp_spf_optout' );
		ob_start();
		?>
		<div class="hp-spf-optout">
			<label style="display:flex;align-items:center;gap:6px;">
				<input type="checkbox" id="hp-spf-optout" <?php echo $checked; ?>>
				<span><?php esc_html_e( 'Disable social proof popups', 'hp-social-proof' ); ?></span>
			</label>
		</div>
		<script>
		(function(){
			var cb = document.getElementById('hp-spf-optout');
			if(!cb) return;
			cb.addEventListener('change', function(){
				var xhr=new XMLHttpRequest();
				xhr.open('POST','<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
				xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
				xhr.send('action=hp_spf_optout&_ajax_nonce=<?php echo esc_js( $nonce ); ?>&value='+(cb.checked?1:0));
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function ajax_save_optout() {
		check_ajax_referer( 'hp_spf_optout' );
		if ( ! is_user_logged_in() ) { wp_send_json_error(); }
		update_user_meta( get_current_user_id(), 'hp_spf_optout', (int) ( ! empty( $_POST['value'] ) ) );
		wp_send_json_success();
	}
}

// -----------------------------------------------------------------------------
// Events
// -----------------------------------------------------------------------------

class HP_SPF_Events {
	const QUEUE_OPTION = 'hp_spf_event_queue';

	public static function init() : void {
		// Push immediately removed to avoid duplicates; only schedule deferred
		add_action( 'user_register', [ __CLASS__, 'on_user_register_schedule' ], 10, 1 );
		add_action( 'hp_spf_deferred_user_register', [ __CLASS__, 'on_user_register_deferred' ], 10, 1 );

		add_action( 'transition_post_status', [ __CLASS__, 'on_post_transition' ], 10, 3 );
		add_action( 'hivepress/v1/emails/review_notify/send', [ __CLASS__, 'on_review_notify' ], 10, 1 );
		add_action( 'hivepress/v1/emails/order_paid/send', [ __CLASS__, 'on_order_paid' ], 10, 1 );
		add_action( 'hivepress/v1/emails/order_refund/send', [ __CLASS__, 'on_order_refund' ], 10, 1 );

		add_action( 'hivepress/v1/models/booking/create', [ __CLASS__, 'on_booking_model' ], 10, 2 );
		add_action( 'hivepress/v1/models/booking/update_status', [ __CLASS__, 'on_booking_status' ], 10, 2 );

		add_action( 'init', [ __CLASS__, 'gc' ] );

		// Also catch WP profile updates for avatar/name changes
		add_action( 'profile_update', [ __CLASS__, 'on_profile_update' ], 10, 2 );
	}

	public static function on_profile_update( $user_id, $old_user_data ) {
		update_user_meta( (int) $user_id, 'hp_spf_avatar_ver', time() );
	}

	// Queue helpers ------------------------------------------------------------
	protected static function get_queue() {
		$q = get_option( self::QUEUE_OPTION, [] );
		return is_array( $q ) ? $q : [];
	}

	protected static function set_queue( $q ) {
		update_option( self::QUEUE_OPTION, array_values( $q ), false );
	}

	protected static function push( array $event ) : void {
		$settings = HP_SPF_Admin::get_settings();
		$queue = self::get_queue();
		$queue[] = $event + [ 'ts' => time() ];
		$ttl = max( 60, absint( $settings['event_lifetime_minutes'] ) * 60 );
		$cut = time() - $ttl;
		$queue = array_values( array_filter( $queue, function( $e ) use ( $cut ) {
			return isset( $e['ts'] ) && $e['ts'] >= $cut;
		} ) );
		$size = max( 10, absint( $settings['queue_size'] ) );
		if ( count( $queue ) > $size ) {
			$queue = array_slice( $queue, -1 * $size );
		}
		self::set_queue( $queue );
	}

	// Remove duplicate user_register events for same user within last 5 min
	protected static function dedupe_user_register( $user_id ) : void {
		$window = time() - 300;
		$queue = self::get_queue();
		$queue = array_filter( $queue, function( $e ) use ( $user_id, $window ) {
			if ( empty( $e['key'] ) || 'user_register' !== $e['key'] ) { return true; }
			if ( empty( $e['user_id'] ) || (int) $e['user_id'] !== (int) $user_id ) { return true; }
			if ( empty( $e['ts'] ) || (int) $e['ts'] < $window ) { return true; }
			// Drop it (we will re-add a fresh one)
			return false;
		} );
		self::set_queue( $queue );
	}

	protected static function username_compact( $user_id ) : string {
		$u = get_userdata( $user_id );
		if ( ! $u ) { return __( 'Someone', 'hp-social-proof' ); }
		$first = get_user_meta( $user_id, 'first_name', true );
		$last  = get_user_meta( $user_id, 'last_name', true );
		if ( $first || $last ) {
			$last_initial = $last ? substr( $last, 0, 1 ) . '.' : '';
			return trim( sprintf( '%s %s', $first ?: '', $last_initial ) );
		}
		$dn = $u->display_name ?: $u->user_login;
		$parts = preg_split( '/\s+/', trim( $dn ) );
		if ( count( $parts ) > 1 ) {
			return sprintf( '%s %s.', $parts[0], substr( end( $parts ), 0, 1 ) );
		}
		return $dn;
	}

	protected static function listing_payload( $listing_id ) : array {
		$link  = get_permalink( $listing_id );
		$title = get_the_title( $listing_id );
		$location = '';
		$location_link = '';

		if ( class_exists( '\HivePress\Models\Listing' ) ) {
			try {
				$listing = \HivePress\Models\Listing::query()->get_by_id( $listing_id );
				if ( $listing ) {
					if ( method_exists( $listing, 'get_location' ) ) { $location = (string) $listing->get_location(); }
					$lat = method_exists( $listing, 'get_latitude' ) ? $listing->get_latitude() : null;
					$lng = method_exists( $listing, 'get_longitude' ) ? $listing->get_longitude() : null;
					if ( $lat && $lng ) {
						if ( function_exists( 'hivepress' ) && isset( hivepress()->router ) && method_exists( hivepress()->router, 'get_url' ) ) {
							$location_link = esc_url( hivepress()->router->get_url( 'location_view_page', [ 'latitude' => $lat, 'longitude' => $lng ] ) );
						} else {
							$location_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $lat . ',' . $lng );
						}
					}
				}
			} catch ( \Throwable $e ) {}
		}

		if ( ! $location ) {
			foreach ( [ 'hp_location', 'location', 'address', '_hp_location' ] as $k ) {
				$val = get_post_meta( $listing_id, $k, true );
				if ( is_string( $val ) && $val !== '' ) { $location = $val; break; }
			}
		}
		if ( ! $location_link && $location ) {
			$location_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location );
		}

		return [
			'listing_id' => $listing_id,
			'listing_title' => $title,
			'listing_link' => $link,
			'location' => $location,
			'location_link' => $location_link,
		];
	}

	protected static function render_template( string $template, array $vars ) : string {
		$replacements = [
			'[username]' => $vars['username'] ?? '',
			'[listing_title]' => $vars['listing_title'] ?? '',
			'[listing_title_link]' => ! empty( $vars['listing_link'] ) ? '<a href="' . esc_url( $vars['listing_link'] ) . '">' . esc_html( $vars['listing_title'] ?? '' ) . '</a>' : ( $vars['listing_title'] ?? '' ),
			'[vendor_name]' => $vars['vendor_name'] ?? '',
			'[booking_start]' => $vars['booking_start'] ?? '',
			'[booking_end]' => $vars['booking_end'] ?? '',
			'[location]' => esc_html( $vars['location'] ?? '' ),
			'[location_link]' => ! empty( $vars['location_link'] ) ? '<a href="' . esc_url( $vars['location_link'] ) . '" target="_blank" rel="noopener">' . esc_html( $vars['location'] ?? '' ) . '</a>' : esc_html( $vars['location'] ?? '' ),
		];
		return strtr( $template, $replacements );
	}

	// ----- User register (deferred only, deduping) ----------------------------
	public static function on_user_register_schedule( $user_id ) : void {
		if ( ! wp_next_scheduled( 'hp_spf_deferred_user_register', [ $user_id ] ) ) {
			wp_schedule_single_event( time() + 20, 'hp_spf_deferred_user_register', [ $user_id ] );
		}
	}

	public static function on_user_register_deferred( $user_id ) : void {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled_events']['user_register'] ) ) { return; }
		self::dedupe_user_register( $user_id );
		$username = self::username_compact( $user_id );
		$msg = self::render_template( $settings['templates']['user_register'] ?? '[username] just signed up!', [ 'username' => $username ] );
		self::push( [
			'key' => 'user_register',
			'message' => wp_kses_post( $msg ),
			'user_id' => absint( $user_id ),
		] );
	}

	// ----- Listing published ---------------------------------------------------
	public static function on_post_transition( $new, $old, $post ) : void {
		if ( 'publish' === $new && 'publish' !== $old && $post && 'hp_listing' === $post->post_type ) {
			$settings = HP_SPF_Admin::get_settings();
			if ( empty( $settings['enabled_events']['listing_published'] ) ) { return; }
			$author_id = (int) $post->post_author;
			$username = self::username_compact( $author_id );
			$p = self::listing_payload( $post->ID );
			$msg = self::render_template( $settings['templates']['listing_published'] ?? '[username] just posted a new listing — [listing_title_link]', [
				'username' => $username,
				'listing_title' => $p['listing_title'],
				'listing_link' => $p['listing_link'],
				'location' => $p['location'],
				'location_link' => $p['location_link'],
			] );
			self::push( [
				'key' => 'listing_published',
				'message' => wp_kses_post( $msg ),
				'user_id' => $author_id,
				'listing_id' => (int) $post->ID,
			] );
		}
	}

	// ----- Booking events ------------------------------------------------------
	public static function on_booking_model( $booking, $args ) : void {
		$booking_id = is_object( $booking ) && method_exists( $booking, 'get_id' ) ? (int) $booking->get_id() : 0;
		$listing_id = is_object( $booking ) && method_exists( $booking, 'get_listing_id' ) ? (int) $booking->get_listing_id() : 0;
		$user_obj = is_object( $booking ) && method_exists( $booking, 'get_user' ) ? $booking->get_user() : null;
		$user_id = is_object( $user_obj ) && method_exists( $user_obj, 'get_id' ) ? (int) $user_obj->get_id() : 0;
		self::emit_booking_event( $booking_id, $listing_id, $user_id );
	}

	public static function on_booking_status( $booking, $new_status ) : void {
		$booking_id = is_object( $booking ) && method_exists( $booking, 'get_id' ) ? (int) $booking->get_id() : 0;
		$listing_id = is_object( $booking ) && method_exists( $booking, 'get_listing_id' ) ? (int) $booking->get_listing_id() : 0;
		$user_obj = is_object( $booking ) && method_exists( $booking, 'get_user' ) ? $booking->get_user() : null;
		$user_id = is_object( $user_obj ) && method_exists( $user_obj, 'get_id' ) ? (int) $user_obj->get_id() : 0;
		self::emit_booking_event( $booking_id, $listing_id, $user_id );
	}

	protected static function emit_booking_event( $booking_id, $listing_id, $user_id ) : void {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled_events']['booking_created'] ) ) { return; }

		$start = ''; $end = '';
		if ( $booking_id ) {
			$start_ts = (int) get_post_meta( $booking_id, 'hp_start_time', true );
			$end_ts   = (int) get_post_meta( $booking_id, 'hp_end_time', true );
			$start = $start_ts ? date_i18n( get_option( 'date_format' ), $start_ts ) : '';
			$end   = $end_ts ? date_i18n( get_option( 'date_format' ), $end_ts ) : '';
		}
		$p = $listing_id ? self::listing_payload( $listing_id ) : [ 'listing_title' => '', 'listing_link' => '', 'location' => '', 'location_link' => '' ];
		$username = $user_id ? self::username_compact( $user_id ) : __( 'Someone', 'hp-social-proof' );
		$msg = self::render_template( $settings['templates']['booking_created'] ?? '[username] just booked [listing_title_link] in [location]', [
			'username' => $username,
			'listing_title' => $p['listing_title'],
			'listing_link' => $p['listing_link'],
			'booking_start' => $start,
			'booking_end' => $end,
			'location' => $p['location'],
			'location_link' => $p['location_link'],
		] );
		self::push( [ 'key' => 'booking_created', 'message' => wp_kses_post( $msg ), 'user_id' => (int) $user_id, 'listing_id' => (int) $listing_id, 'booking_id' => (int) $booking_id ] );
	}

	// ----- Reviews & Orders ----------------------------------------------------
	public static function on_review_notify( $email ) : void {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled_events']['review_posted'] ) ) { return; }
		$comment_id = 0;
		$listing_id = 0;
		$user_id = 0;
		if ( is_object( $email ) && method_exists( $email, 'get_context' ) ) {
			$ctx = $email->get_context();
			$comment_id = (int) ( $ctx['review_id'] ?? 0 );
			$listing_id = (int) ( $ctx['listing_id'] ?? 0 );
			$user_id = (int) ( $ctx['user_id'] ?? 0 );
		}
		$p = $listing_id ? self::listing_payload( $listing_id ) : [ 'listing_title' => '', 'listing_link' => '', 'location' => '', 'location_link' => '' ];
		$username = $user_id ? self::username_compact( $user_id ) : __( 'Someone', 'hp-social-proof' );
		$msg = self::render_template( $settings['templates']['review_posted'] ?? '[username] left a review for [listing_title_link]', [
			'username' => $username,
			'listing_title' => $p['listing_title'],
			'listing_link' => $p['listing_link'],
			'location' => $p['location'],
			'location_link' => $p['location_link'],
		] );
		self::push( [ 'key' => 'review_posted', 'message' => wp_kses_post( $msg ), 'user_id' => (int) $user_id, 'listing_id' => (int) $listing_id, 'comment_id' => (int) $comment_id ] );
	}

	public static function on_order_paid( $email ) : void {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled_events']['order_paid'] ) ) { return; }
		$listing_id = 0; $user_id = 0;
		if ( is_object( $email ) && method_exists( $email, 'get_context' ) ) {
			$ctx = $email->get_context();
			$listing_id = (int) ( $ctx['listing_id'] ?? 0 );
			$user_id = (int) ( $ctx['user_id'] ?? 0 );
		}
		$p = $listing_id ? self::listing_payload( $listing_id ) : [ 'listing_title' => '', 'listing_link' => '', 'location' => '', 'location_link' => '' ];
		$username = $user_id ? self::username_compact( $user_id ) : __( 'Someone', 'hp-social-proof' );
		$msg = self::render_template( $settings['templates']['order_paid'] ?? 'Order paid for [listing_title_link] by [username]', [
			'username' => $username,
			'listing_title' => $p['listing_title'],
			'listing_link' => $p['listing_link'],
			'location' => $p['location'],
			'location_link' => $p['location_link'],
		] );
		self::push( [ 'key' => 'order_paid', 'message' => wp_kses_post( $msg ), 'user_id' => (int) $user_id, 'listing_id' => (int) $listing_id ] );
	}

	public static function on_order_refund( $email ) : void {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled_events']['order_refund'] ) ) { return; }
		$listing_id = 0;
		if ( is_object( $email ) && method_exists( $email, 'get_context' ) ) {
			$ctx = $email->get_context();
			$listing_id = (int) ( $ctx['listing_id'] ?? 0 );
		}
		$p = $listing_id ? self::listing_payload( $listing_id ) : [ 'listing_title' => '', 'listing_link' => '', 'location' => '', 'location_link' => '' ];
		$msg = self::render_template( $settings['templates']['order_refund'] ?? 'Order refunded for [listing_title_link]', [
			'listing_title' => $p['listing_title'],
			'listing_link' => $p['listing_link'],
			'location' => $p['location'],
			'location_link' => $p['location_link'],
		] );
		self::push( [ 'key' => 'order_refund', 'message' => wp_kses_post( $msg ), 'user_id' => 0, 'listing_id' => (int) $listing_id ] );
	}

	public static function gc() : void {
		$settings = HP_SPF_Admin::get_settings();
		$ttl = max( 60, absint( $settings['event_lifetime_minutes'] ) * 60 );
		$cut = time() - $ttl;
		$queue = get_option( self::QUEUE_OPTION, [] );
		$queue = array_values( array_filter( $queue, function( $e ) use ( $cut ) { return isset( $e['ts'] ) && $e['ts'] >= $cut; } ) );
		update_option( self::QUEUE_OPTION, $queue, false );
	}
}

// -----------------------------------------------------------------------------
// REST
// -----------------------------------------------------------------------------

class HP_SPF_REST {
	public static function init() : void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		// Set no-cache headers for our namespace
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'nocache_headers' ], 10, 4 );
	}

	public static function register_routes() : void {
		register_rest_route( 'hp-spf/v1', '/events', [
			'methods' => 'GET',
			'callback' => [ __CLASS__, 'get_events' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function nocache_headers( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( is_string( $route ) && false !== strpos( $route, '/hp-spf/v1/events' ) ) {
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );
				header( 'Expires: 0' );
				header( 'Vary: Cookie' );
			}
		}
		return $served;
	}

	public static function get_events( WP_REST_Request $req ) : WP_REST_Response {
		$settings = HP_SPF_Admin::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_REST_Response( [ 'enabled' => false, 'events' => [] ], 200 );
		}

		// Respect per-user opt-out
		$viewer_id = get_current_user_id();
		$user_opted_out = $viewer_id ? (bool) get_user_meta( $viewer_id, 'hp_spf_optout', true ) : false;
		if ( $user_opted_out ) {
			return new WP_REST_Response( [ 'enabled' => false, 'events' => [] ], 200 );
		}

		$queue = get_option( HP_SPF_Events::QUEUE_OPTION, [] );
		$ttl = max( 60, absint( $settings['event_lifetime_minutes'] ) * 60 );
		$cut = time() - $ttl;
		$events = array_values( array_filter( $queue, function( $e ) use ( $cut ) { return isset( $e['ts'] ) && $e['ts'] >= $cut; } ) );

		// Derive fresh avatars for actors; add version & timestamp cache-busting
		$now = time();
		$events = array_map( function( $e ) use ( $now ){
			$img = '';
			$uid = isset( $e['user_id'] ) ? (int) $e['user_id'] : 0;
			if ( $uid ) {
				$raw = get_avatar_url( $uid, [ 'size' => 96 ] );
				$ver = (int) get_user_meta( $uid, 'hp_spf_avatar_ver', true );
				if ( $raw ) {
					$raw = add_query_arg( 't', $now, $raw );
					if ( $ver ) { $raw = add_query_arg( 'v', $ver, $raw ); }
					$img = $raw;
				}
			}
			return [
				'key' => (string) ( $e['key'] ?? '' ),
				'message' => wp_kses_post( $e['message'] ?? '' ),
				'img' => esc_url_raw( $img ),
				'ts' => (int) ( $e['ts'] ?? $now ),
			];
		}, $events );

		return new WP_REST_Response( [
			'enabled' => (bool) $settings['enabled'],
			'rotation_interval_ms' => (int) $settings['rotation_interval_ms'],
			'display_duration_ms' => (int) $settings['display_duration_ms'],
			'max_concurrent' => (int) $settings['max_concurrent'],
			'position' => (string) $settings['position'],
			'animation' => (string) $settings['animation'],
			'appearance' => (array) $settings['appearance'],
			'events' => $events,
		], 200 );
	}
}

// -----------------------------------------------------------------------------
// Frontend
// -----------------------------------------------------------------------------

class HP_SPF_Frontend {
	public static function init() : void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_root' ] );

		// Try multiple HivePress form slugs to ensure visibility across versions
		add_filter( 'hivepress/v1/forms/user_settings', [ __CLASS__, 'extend_user_settings_form' ], 10, 2 );
		add_filter( 'hivepress/v1/forms/user_update', [ __CLASS__, 'extend_user_settings_form' ], 10, 2 );
		add_filter( 'hivepress/v1/forms/user', [ __CLASS__, 'extend_user_settings_form' ], 10, 2 );

		add_action( 'hivepress/v1/models/user/update', [ __CLASS__, 'save_user_settings' ], 10, 2 );
		// Fallback for other profile edits
		add_action( 'profile_update', [ __CLASS__, 'save_user_settings_profile' ], 10, 2 );
	}

	public static function enqueue() : void {
		wp_register_style( 'hp-spf', HP_SPF_URL . 'assets/css/hp-spf.css', [], HP_SPF_VER );
		wp_register_script( 'hp-spf', HP_SPF_URL . 'assets/js/hp-spf.js', [], HP_SPF_VER, true );
		wp_enqueue_style( 'hp-spf' );
		wp_enqueue_script( 'hp-spf' );
		wp_localize_script( 'hp-spf', 'HP_SPF', [
			'endpoint' => esc_url_raw( rest_url( 'hp-spf/v1/events' ) ),
			'version' => HP_SPF_VER,
		] );
	}

	public static function render_root() : void {
		echo '<div id="hp-spf-root" aria-live="polite" aria-atomic="true"></div>';
	}

	public static function extend_user_settings_form( $form, $request ) {
		$uid = get_current_user_id();
		$checked = (bool) get_user_meta( $uid, 'hp_spf_optout', true );
		$form['fields']['hp_spf_optout'] = [
			'name'    => 'hp_spf_optout',
			'label'   => __( 'Disable social proof popups', 'hp-social-proof' ),
			'desc'    => __( 'If enabled, you won\'t see live activity popups.', 'hp-social-proof' ),
			'type'    => 'checkbox',
			'value'   => $checked,
			'default' => $checked,
			'_order'  => 9999,
		];
		return $form;
	}

	public static function save_user_settings( $model, $args ) : void {
		$uid = ( is_object( $model ) && method_exists( $model, 'get_id' ) ) ? (int) $model->get_id() : get_current_user_id();
		$value = (int) ( ! empty( $args['hp_spf_optout'] ) );
		if ( ! array_key_exists( 'hp_spf_optout', (array) $args ) ) {
			$value = 0;
		}
		update_user_meta( $uid, 'hp_spf_optout', $value );
	}

	// Fallback for WP profile screens
	public static function save_user_settings_profile( $user_id, $old_user_data ) : void {
		if ( isset( $_POST['hp_spf_optout'] ) ) {
			update_user_meta( (int) $user_id, 'hp_spf_optout', (int) ( ! empty( $_POST['hp_spf_optout'] ) ) );
		} else {
			update_user_meta( (int) $user_id, 'hp_spf_optout', 0 );
		}
	}
}

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------

add_action( 'plugins_loaded', function () {
	new HP_SPF_Admin();
	HP_SPF_Events::init();
	HP_SPF_REST::init();
	HP_SPF_Frontend::init();
} );
