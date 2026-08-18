<?php
/**
 * Public REST endpoint serving the popup event feed.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoint serving the popup event feed.
 */
class Hpsp_Rest {

	const API_NAMESPACE = 'hpsp/v1';
	const CACHE_TTL     = 60; // Seconds the rendered payload is cached server-side.

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the events route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/events',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_events' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Serve the rendered event feed.
	 *
	 * The feed is identical for every visitor (per-visitor rules run in the
	 * browser), so it can be cached briefly server-side. The cache is
	 * invalidated whenever an event is queued or settings are saved.
	 */
	public static function get_events(): WP_REST_Response {
		$settings = Hpsp_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return self::respond( [ 'events' => [] ] );
		}

		$events = get_transient( Hpsp_Events::PAYLOAD_CACHE );

		if ( ! is_array( $events ) ) {
			$events = Hpsp_Events::get_display_events();

			set_transient( Hpsp_Events::PAYLOAD_CACHE, $events, self::CACHE_TTL );
		}

		// Belt and braces at serve time: cached payloads (or a cache layer
		// that outlives the intended TTL) must never surface expired events.
		// A stale test popup was seen on staging long past its 5-minute life.
		$lifetime = max( 1, absint( $settings['event_lifetime'] ) ) * HOUR_IN_SECONDS;
		$now      = time();

		$events = array_values(
			array_filter(
				$events,
				function ( $event ) use ( $lifetime, $now ) {
					$age = $now - ( isset( $event['time'] ) ? (int) $event['time'] : 0 );
					$ttl = ( isset( $event['type'] ) && 'test' === $event['type'] ) ? Hpsp_Events::TEST_EVENT_TTL : $lifetime;

					return $age < $ttl;
				}
			)
		);

		return self::respond( [ 'events' => $events ] );
	}

	/**
	 * Build a non-cacheable (browser-side) response.
	 *
	 * @param array $data Response body.
	 */
	protected static function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );

		// No max-age here: WordPress and hosting proxies append their own, and
		// a duplicated max-age=0 was observed on staging. no-store covers it.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		return $response;
	}
}
