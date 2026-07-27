<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

class HPSP_Settings {

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
	public static function defaults() : array {
		$events = [];

		foreach ( HPSP_Events::types() as $type => $config ) {
			$events[ $type ] = [
				'enabled'  => ! empty( $config['enabled'] ),
				'template' => $config['template'],
				'image'    => $config['image'],
			];
		}

		return [
			// General.
			'enabled'        => true,
			'exclude_admins' => true,
			'show_on_mobile' => true,
			'event_lifetime' => 48,   // Hours an event stays eligible for display.
			'queue_size'     => 50,   // Max events kept in the queue.
			'exclude_paths'  => '',   // One URL path per line, * wildcards allowed.

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
			'position'        => 'bottom-left',
			'position_mobile' => 'bottom-center',
			'animation'       => 'slide',   // slide|fade|pop.
			'animation_speed' => 300,       // Milliseconds.

			// Appearance.
			'bg_color'      => '#111827',
			'text_color'    => '#f9fafb',
			'link_color'    => '#93c5fd',
			'border_color'  => '#374151',
			'border_width'  => 0,
			'border_radius' => 999,
			'shadow'        => 'medium',    // none|soft|medium|strong.
			'font_size'     => 14,
			'max_width'     => 380,
			'image_style'   => 'circle',    // circle|rounded|square.
			'show_close'    => true,
			'show_time'     => true,
			'z_index'       => 99999,

			// Per-event settings.
			'events' => $events,
		];
	}

	/**
	 * Get the merged settings array.
	 */
	public static function get() : array {
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
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 */
	public static function get_value( string $key, $default = null ) {
		$settings = self::get();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Reset the per-request cache (used after saving).
	 */
	public static function flush_cache() : void {
		self::$cache = null;
	}

	/**
	 * HTML allowed inside popup message templates.
	 */
	public static function allowed_template_tags() : array {
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
	 * Shadow presets shared by the frontend and the admin preview.
	 */
	public static function shadow_presets() : array {
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
	public static function sanitize( $input ) : array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();
		$output   = [];

		// Booleans. Unchecked checkboxes are simply absent from the request.
		foreach ( [ 'enabled', 'exclude_admins', 'show_on_mobile', 'no_repeat', 'loop', 'show_close', 'show_time' ] as $key ) {
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

		// Colours.
		foreach ( [ 'bg_color', 'text_color', 'link_color', 'border_color' ] as $key ) {
			$color          = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : '';
			$output[ $key ] = $color ? $color : $defaults[ $key ];
		}

		// Excluded paths, one per line.
		$paths = isset( $input['exclude_paths'] ) ? sanitize_textarea_field( wp_unslash( (string) $input['exclude_paths'] ) ) : '';
		$lines = array_filter( array_map( 'trim', explode( "\n", $paths ) ) );

		$output['exclude_paths'] = implode( "\n", $lines );

		// Per-event settings.
		$output['events'] = [];

		foreach ( HPSP_Events::types() as $type => $config ) {
			$event = isset( $input['events'][ $type ] ) && is_array( $input['events'][ $type ] ) ? $input['events'][ $type ] : [];

			// Settings API passes slashed $_POST values to sanitize callbacks.
			$template = isset( $event['template'] ) ? trim( wp_kses( wp_unslash( (string) $event['template'] ), self::allowed_template_tags() ) ) : '';
			$image    = isset( $event['image'] ) ? sanitize_key( $event['image'] ) : '';

			$output['events'][ $type ] = [
				'enabled'  => ! empty( $event['enabled'] ),
				'template' => '' !== $template ? $template : $config['template'],
				'image'    => in_array( $image, [ 'avatar', 'listing', 'none' ], true ) ? $image : $config['image'],
			];
		}

		// Fresh payloads should reflect the new settings immediately.
		delete_transient( 'hpsp_events_payload' );
		self::flush_cache();

		return $output;
	}
}
