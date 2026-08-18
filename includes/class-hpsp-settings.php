<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage, defaults and sanitisation.
 */
class Hpsp_Settings {

	const OPTION_KEY = 'hpsp_settings';

	/**
	 * Cached, merged settings for the current request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Full default settings.
	 */
	public static function defaults(): array {
		$events = [];

		foreach ( Hpsp_Events::types() as $type => $config ) {
			$events[ $type ] = [
				'enabled'  => ! empty( $config['enabled'] ),

				// An empty stored template means "use the built-in wording",
				// which keeps the default translatable through Loco Translate
				// instead of freezing the English text into the database.
				'template' => '',
				'image'    => $config['image'],
				'icon'     => $config['icon'],
			];
		}

		return [
			// General.
			'enabled'          => true,
			'exclude_admins'   => true,
			'anonymise'        => false,  // Show "Someone" instead of member names.
			'show_on_mobile'   => true,
			'event_lifetime'   => 48,   // Hours an event stays eligible for display.
			'queue_size'       => 50,   // Max events kept in the queue.
			'exclude_paths'    => '',   // One URL path per line, * wildcards allowed.

			// Timing & behaviour.
			'initial_delay'    => 4,  // Seconds before the first popup.
			'display_duration' => 6,  // Seconds each popup stays visible.
			'gap'              => 8,  // Seconds between popups.
			'max_visible'      => 1,  // Popups shown at the same time.
			'max_per_page'     => 0,  // 0 = unlimited per page view.
			'order'            => 'newest', // newest|random.
			'no_repeat'        => true,     // Skip events already seen this browser session.
			'loop'             => false,    // Start over once every event has been shown.
			'snooze_duration'  => 60,       // Minutes popups stay hidden after a visitor closes one. 0 = close only that popup.

			// Position & animation.
			'position'         => 'bottom-left',
			'position_mobile'  => 'bottom-center',
			'offset_x'         => 20,        // Distance from the side edge on desktop, px.
			'offset_y'         => 20,        // Distance from the top/bottom edge on desktop, px.
			'animation'        => 'slide',   // slide|fade|pop.
			'animation_speed'  => 300,       // Milliseconds.

			// Appearance.
			'bg_color'         => '#111827',
			'text_color'       => '#f9fafb',
			'link_color'       => '#93c5fd',
			'border_color'     => '#374151',
			'border_width'     => 0,
			'border_radius'    => 999,
			'shadow'           => 'medium',    // none|soft|medium|strong.
			'font_size'        => 14,
			'max_width'        => 380,
			'image_style'      => 'circle',  // circle|rounded|square.
			'fallback_avatar'  => 0,         // Attachment ID; 0 = default WordPress avatar.
			'show_close'       => true,
			'show_time'        => true,
			'show_progress'    => true,      // Countdown bar along the bottom of each popup.
			'z_index'          => 99999,

			// Uninstall behaviour. Retain by default; see uninstall.php.
			'delete_data'      => false,

			// Per-event settings.
			'events'           => $events,
		];
	}

	/**
	 * Get the merged settings array.
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$saved    = get_option( self::OPTION_KEY, [] );
			$saved    = is_array( $saved ) ? $saved : [];
			$defaults = self::defaults();

			$settings           = array_merge( $defaults, $saved );
			$settings['events'] = [];

			foreach ( $defaults['events'] as $type => $event_defaults ) {
				$saved_event = isset( $saved['events'][ $type ] ) && is_array( $saved['events'][ $type ] ) ? $saved['events'][ $type ] : [];

				$settings['events'][ $type ] = array_merge( $event_defaults, $saved_event );
			}

			self::$cache = $settings;
		}

		return self::$cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback when the key is unknown.
	 */
	public static function get_value( string $key, $fallback = null ) {
		$settings = self::get();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Reset the per-request cache (used after saving).
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * HTML allowed inside popup message templates.
	 */
	public static function allowed_template_tags(): array {
		return [
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			],
			'strong' => [],
			'em'     => [],
			'b'      => [],
			'i'      => [],
			'u'      => [],
			'br'     => [],
			'span'   => [ 'class' => true ],
		];
	}

	/**
	 * One-off upgrade pass, run when the stored version is behind the code.
	 *
	 * Earlier builds froze the default English template text into the stored
	 * option on first save. Blanking any template that still equals one of
	 * those old defaults restores "empty = built-in wording", which keeps the
	 * text translatable and moves it to the new %token% form automatically.
	 */
	public static function upgrade(): void {
		$saved = get_option( self::OPTION_KEY );

		if ( ! is_array( $saved ) || empty( $saved['events'] ) || ! is_array( $saved['events'] ) ) {
			return;
		}

		$old_defaults = [
			'user_registered'   => '<strong>[username]</strong> just joined [site_name]',
			'listing_published' => '<strong>[username]</strong> just posted a new listing: [listing_title_link]',
			'booking_confirmed' => '<strong>[username]</strong> just booked [listing_title_link]',
			'review_submitted'  => '<strong>[username]</strong> just left a [rating]-star review on [listing_title_link]',
			'order_paid'        => '<strong>[username]</strong> just purchased [listing_title_link]',
			'favorite_added'    => '<strong>[username]</strong> just favourited [listing_title_link]',
			'vendor_registered' => '<strong>[username]</strong> just became a vendor on [site_name]',
			'message_sent'      => '<strong>[username]</strong> just enquired about [listing_title_link]',
		];

		$changed = false;

		foreach ( $old_defaults as $type => $old_template ) {
			if ( isset( $saved['events'][ $type ]['template'] ) && $saved['events'][ $type ]['template'] === $old_template ) {
				$saved['events'][ $type ]['template'] = '';

				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $saved, false );
			self::flush_cache();
		}
	}

	/**
	 * Icons offered for popup tiles.
	 *
	 * A curated subset of the Font Awesome 5 solid set that HivePress bundles;
	 * every name is verified against core's icons config and stylesheet, so a
	 * choice here can never render as a blank square while HivePress is active.
	 */
	public static function allowed_icons(): array {
		return [
			'user-plus',
			'home',
			'calendar-check',
			'star',
			'shopping-cart',
			'heart',
			'store',
			'envelope',
			'bell',
			'bullhorn',
			'check-circle',
			'fire',
			'gift',
			'thumbs-up',
			'bolt',
			'trophy',
			'comment',
			'map-marker-alt',
			'tag',
			'users',
		];
	}

	/**
	 * Shadow presets shared by the frontend and the admin preview.
	 */
	public static function shadow_presets(): array {
		return [
			'none'   => 'none',
			'soft'   => '0 2px 10px rgba(0, 0, 0, 0.12)',
			'medium' => '0 8px 24px rgba(0, 0, 0, 0.18)',
			'strong' => '0 12px 40px rgba(0, 0, 0, 0.3)',
		];
	}

	/**
	 * Sanitise the raw settings array submitted from the admin form.
	 *
	 * @param mixed $input Raw input.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();
		$output   = [];

		// Booleans. Unchecked checkboxes are simply absent from the request.
		foreach ( [ 'enabled', 'exclude_admins', 'anonymise', 'show_on_mobile', 'no_repeat', 'loop', 'show_close', 'show_time', 'show_progress', 'delete_data' ] as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] );
		}

		// Bounded integers: key => [ min, max ].
		$int_bounds = [
			'event_lifetime'   => [ 1, 720 ],
			'queue_size'       => [ 10, 200 ],
			'initial_delay'    => [ 0, 120 ],
			'display_duration' => [ 2, 60 ],
			'gap'              => [ 1, 120 ],
			'max_visible'      => [ 1, 5 ],
			'max_per_page'     => [ 0, 100 ],
			'snooze_duration'  => [ 0, 10080 ],
			'offset_x'         => [ 0, 400 ],
			'offset_y'         => [ 0, 400 ],
			'animation_speed'  => [ 100, 2000 ],
			'border_width'     => [ 0, 10 ],
			'border_radius'    => [ 0, 999 ],
			'font_size'        => [ 10, 24 ],
			'max_width'        => [ 240, 640 ],
			'z_index'          => [ 1, 2147483647 ],
		];

		foreach ( $int_bounds as $key => $bounds ) {
			// Cast (not absint) so out-of-range negatives clamp to the minimum
			// instead of having their sign flipped.
			$value          = isset( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
			$output[ $key ] = min( max( $value, $bounds[0] ), $bounds[1] );
		}

		// Enumerated choices: key => allowed values.
		$positions = [ 'bottom-left', 'bottom-center', 'bottom-right', 'top-left', 'top-center', 'top-right' ];

		$enums = [
			'position'        => $positions,
			'position_mobile' => $positions,
			'animation'       => [ 'slide', 'fade', 'pop' ],
			'order'           => [ 'newest', 'random' ],
			'shadow'          => array_keys( self::shadow_presets() ),
			'image_style'     => [ 'circle', 'rounded', 'square' ],
		];

		foreach ( $enums as $key => $allowed ) {
			$value          = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
			$output[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
		}

		// Fallback avatar attachment.
		$output['fallback_avatar'] = isset( $input['fallback_avatar'] ) ? absint( $input['fallback_avatar'] ) : 0;

		// Colours.
		foreach ( [ 'bg_color', 'text_color', 'link_color', 'border_color' ] as $key ) {
			$color          = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : '';
			$output[ $key ] = $color ? $color : $defaults[ $key ];
		}

		// Excluded paths, one per line. No wp_unslash here: options.php has
		// already unslashed the POST once, and a second pass eats literal
		// backslashes (measured on the real save path, see resources).
		$paths = isset( $input['exclude_paths'] ) ? sanitize_textarea_field( (string) $input['exclude_paths'] ) : '';
		$lines = array_filter( array_map( 'trim', explode( "\n", $paths ) ) );

		$output['exclude_paths'] = implode( "\n", $lines );

		// Per-event settings.
		$output['events'] = [];

		foreach ( Hpsp_Events::types() as $type => $config ) {
			$event = isset( $input['events'][ $type ] ) && is_array( $input['events'][ $type ] ) ? $input['events'][ $type ] : [];

			$template = isset( $event['template'] ) ? trim( wp_kses( (string) $event['template'], self::allowed_template_tags() ) ) : '';
			$image    = isset( $event['image'] ) ? sanitize_key( $event['image'] ) : '';
			$icon     = isset( $event['icon'] ) ? sanitize_key( $event['icon'] ) : '';

			// A template identical to the built-in default stores as '' so the
			// wording stays translatable and "blank the box" means "reset".
			if ( $template === $config['template'] ) {
				$template = '';
			}

			$output['events'][ $type ] = [
				'enabled'  => ! empty( $event['enabled'] ),
				'template' => $template,
				'image'    => in_array( $image, [ 'avatar', 'listing', 'icon', 'none' ], true ) ? $image : $config['image'],
				'icon'     => in_array( $icon, self::allowed_icons(), true ) ? $icon : $config['icon'],
			];
		}

		// Fresh payloads should reflect the new settings immediately.
		delete_transient( 'hpsp_events_payload' );
		self::flush_cache();

		return $output;
	}
}
