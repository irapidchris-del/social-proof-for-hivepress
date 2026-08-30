<?php
/**
 * Event registry, capture hooks, queue storage and message rendering.
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event registry, capture hooks, queue storage and message rendering.
 */
class Hpsp_Events {

	const QUEUE_OPTION    = 'hpsp_event_queue';
	const PAYLOAD_CACHE   = 'hpsp_events_payload';
	const TEST_EVENT_TTL  = 300; // Test popups expire after 5 minutes regardless of the lifetime setting.
	const MAX_SENT_EVENTS = 20;  // Events sent to the browser per request.

	/**
	 * Register capture hooks.
	 */
	public static function init(): void {
		// New user accounts.
		add_action( 'user_register', [ __CLASS__, 'capture_user_registered' ], 20, 1 );

		// Listings, bookings and vendors are HivePress custom post types.
		add_action( 'transition_post_status', [ __CLASS__, 'capture_post_transition' ], 20, 3 );

		// Reviews, favourites and messages are HivePress comment types.
		add_action( 'wp_insert_comment', [ __CLASS__, 'capture_comment' ], 20, 2 );
		add_action( 'transition_comment_status', [ __CLASS__, 'capture_comment_transition' ], 20, 3 );

		// Marketplace orders run on WooCommerce.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'capture_order' ], 20, 3 );

		// Scheduled queue cleanup.
		add_action( 'hpsp_cleanup', [ __CLASS__, 'cleanup' ] );
	}

	/**
	 * Event type registry.
	 *
	 * Each type defines a label, default template, default image source and
	 * the placeholder tokens available for its templates.
	 */
	public static function types(): array {
		/*
		 * Tokens use the %token% form to match HivePress email templates.
		 * phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText, WordPress.WP.I18n.MissingTranslatorsComment -- these
		 * are HivePress-style named tokens, not printf placeholders; the sniff's
		 * auto-fix rewrites %site_name% into %1$site_name%, which can never
		 * match a token name again (it mangled five strings in a sibling
		 * plugin twice, invisibly to every check except rendering).
		 */
		$types = [
			'user_registered'   => [
				'label'       => __( 'New user sign-up', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when someone creates an account.', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just joined %site_name%', 'social-proof-for-hivepress' ),
				'image'       => 'avatar',
				'icon'        => 'user-plus',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'location', 'in_location', 'site_name' ],
			],
			'listing_published' => [
				'label'       => __( 'New listing published', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when a listing goes live.', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just posted a new listing: %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'listing',
				'icon'        => 'home',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'location', 'location_link', 'in_location', 'site_name' ],
			],
			'booking_confirmed' => [
				'label'       => __( 'New booking', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when a booking is confirmed (requires the Bookings extension).', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just booked %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'listing',
				'icon'        => 'calendar-check',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'location', 'location_link', 'in_location', 'booking_start', 'booking_end', 'site_name' ],
			],
			'review_submitted'  => [
				'label'       => __( 'New review', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when an approved review is posted (requires the Reviews extension).', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just left a %rating%-star review on %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'avatar',
				'icon'        => 'star',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'rating', 'rating_stars', 'site_name' ],
			],
			'order_paid'        => [
				'label'       => __( 'New purchase', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when an order is paid (requires the Marketplace extension / WooCommerce).', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just purchased %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'listing',
				'icon'        => 'shopping-cart',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'site_name' ],
			],
			'favorite_added'    => [
				'label'       => __( 'Listing favourited', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when someone adds a listing to their favourites (requires the Favorites extension).', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just favourited %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'listing',
				'icon'        => 'heart',
				'enabled'     => true,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'site_name' ],
			],
			'vendor_registered' => [
				'label'       => __( 'New vendor', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when a new vendor profile goes live.', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just became a vendor on %site_name%', 'social-proof-for-hivepress' ),
				'image'       => 'avatar',
				'icon'        => 'store',
				'enabled'     => false,
				'tokens'      => [ 'username', 'first_name', 'vendor_name', 'location', 'in_location', 'site_name' ],
			],
			'message_sent'      => [
				'label'       => __( 'New enquiry', 'social-proof-for-hivepress' ),
				'description' => __( 'Shown when someone sends a message about a listing (requires the Messages extension). Message contents are never shown.', 'social-proof-for-hivepress' ),
				'template'    => __( '<strong>%username%</strong> just enquired about %listing_title_link%', 'social-proof-for-hivepress' ),
				'image'       => 'avatar',
				'icon'        => 'envelope',
				'enabled'     => false,
				'tokens'      => [ 'username', 'first_name', 'listing_title', 'listing_title_link', 'listing_url', 'vendor_name', 'site_name' ],
			],
		];
		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText, WordPress.WP.I18n.MissingTranslatorsComment

		/**
		 * Filter the registered social-proof event types.
		 *
		 * @param array $types Event type definitions.
		 */
		return apply_filters( 'hpsp_event_types', $types );
	}

	// -------------------------------------------------------------------------
	// Capture hooks.
	// -------------------------------------------------------------------------

	/**
	 * New user account.
	 *
	 * @param int $user_id User ID.
	 */
	public static function capture_user_registered( $user_id ): void {
		self::push(
			[
				'type'    => 'user_registered',
				'user_id' => absint( $user_id ),
			]
		);
	}

	/**
	 * Listings, bookings and vendors going live.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function capture_post_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		switch ( $post->post_type ) {
			case 'hp_listing':
				self::push(
					[
						'type'       => 'listing_published',
						'user_id'    => (int) $post->post_author,
						'listing_id' => (int) $post->ID,
					]
				);
				break;

			case 'hp_booking':
				// A booking's parent post is the booked listing.
				self::push(
					[
						'type'       => 'booking_confirmed',
						'user_id'    => (int) $post->post_author,
						'listing_id' => (int) $post->post_parent,
						'object_id'  => (int) $post->ID,
					]
				);
				break;

			case 'hp_vendor':
				self::push(
					[
						'type'      => 'vendor_registered',
						'user_id'   => (int) $post->post_author,
						'object_id' => (int) $post->ID,
					]
				);
				break;
		}
	}

	/**
	 * Reviews, favourites and messages (HivePress comment types).
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 */
	public static function capture_comment( $comment_id, $comment ): void {
		if ( ! $comment instanceof WP_Comment ) {
			$comment = get_comment( $comment_id );
		}

		if ( ! $comment ) {
			return;
		}

		switch ( $comment->comment_type ) {
			case 'hp_review':
				// Only surface approved reviews; pending ones are captured on approval.
				if ( 1 === (int) $comment->comment_approved ) {
					self::push_review( $comment );
				}
				break;

			case 'hp_favorite':
				self::push(
					[
						'type'       => 'favorite_added',
						'user_id'    => (int) $comment->user_id,
						'listing_id' => (int) $comment->comment_post_ID,
						'object_id'  => (int) $comment->comment_ID,
					]
				);
				break;

			case 'hp_message':
				$listing_id = (int) $comment->comment_post_ID;

				// Only enquiries about a listing make a meaningful popup;
				// direct messages and thread replies have no listing context.
				if ( ! $listing_id || 'hp_listing' !== get_post_type( $listing_id ) ) {
					break;
				}

				self::push(
					[
						'type'       => 'message_sent',
						'user_id'    => (int) $comment->user_id,
						'listing_id' => $listing_id,
						'object_id'  => (int) $comment->comment_ID,
					]
				);
				break;
		}
	}

	/**
	 * Reviews approved after moderation.
	 *
	 * @param string     $new_status New comment status.
	 * @param string     $old_status Old comment status.
	 * @param WP_Comment $comment    Comment object.
	 */
	public static function capture_comment_transition( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status && 'approved' !== $old_status && $comment instanceof WP_Comment && 'hp_review' === $comment->comment_type ) {
			self::push_review( $comment );
		}
	}

	/**
	 * Push a review event.
	 *
	 * @param WP_Comment $comment Review comment.
	 */
	protected static function push_review( WP_Comment $comment ): void {
		// Review replies are hp_review comments with a parent; they are not new reviews.
		if ( (int) $comment->comment_parent ) {
			return;
		}

		self::push(
			[
				'type'       => 'review_submitted',
				'user_id'    => (int) $comment->user_id,
				'listing_id' => (int) $comment->comment_post_ID,
				'object_id'  => (int) $comment->comment_ID,
			]
		);
	}

	/**
	 * Paid Marketplace orders (WooCommerce).
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old status slug.
	 * @param string $new_status New status slug.
	 */
	public static function capture_order( $order_id, $old_status, $new_status ): void {
		if ( ! in_array( $new_status, [ 'processing', 'completed' ], true ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Map the first purchased product back to its HivePress listing
		// (Marketplace stores the listing as the product's parent post).
		$listing_id = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( [ $item, 'get_product_id' ] ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$parent_id  = $product_id ? (int) wp_get_post_parent_id( $product_id ) : 0;

			if ( $parent_id && 'hp_listing' === get_post_type( $parent_id ) ) {
				$listing_id = $parent_id;
				break;
			}
		}

		// Skip orders unrelated to HivePress listings.
		if ( ! $listing_id ) {
			return;
		}

		// Use the moment the order was actually paid, not the moment this
		// status change fired: completing a backlog of old orders otherwise
		// announced every one of them as a brand new sale.
		$paid = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();

		self::push(
			[
				'type'       => 'order_paid',
				'user_id'    => (int) $order->get_customer_id(),
				'listing_id' => $listing_id,
				'object_id'  => (int) $order_id,
				'time'       => $paid ? $paid->getTimestamp() : time(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Queue storage.
	// -------------------------------------------------------------------------

	/**
	 * Push an event onto the queue.
	 *
	 * Events are stored as small ID-based records; messages are rendered
	 * fresh when requested so template changes apply retroactively.
	 *
	 * @param array $event Event data (type, user_id, listing_id, object_id, extra).
	 */
	public static function push( array $event ): void {
		// Stand down during bulk imports: HivePress Import defines HP_IMPORT
		// (its model hooks do the same), and WordPress importers define
		// WP_IMPORTING. Without this, an import queues a popup per record.
		if ( ( defined( 'HP_IMPORT' ) && HP_IMPORT ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return;
		}

		$types = self::types();

		if ( empty( $event['type'] ) || ( ! isset( $types[ $event['type'] ] ) && 'test' !== $event['type'] ) ) {
			return;
		}

		$settings = Hpsp_Settings::get();

		// Optionally ignore actions performed by administrators.
		if ( ! empty( $settings['exclude_admins'] ) && ! empty( $event['user_id'] ) && 'test' !== $event['type'] && user_can( (int) $event['user_id'], 'manage_options' ) ) {
			return;
		}

		$event = wp_parse_args(
			$event,
			[
				'user_id'    => 0,
				'listing_id' => 0,
				'object_id'  => 0,
				'extra'      => [],
			]
		);

		// A capture site may state WHEN the thing actually happened (an order
		// knows when it was paid); everything else happened just now.
		$event['time'] = ! empty( $event['time'] ) ? (int) $event['time'] : time();
		$event['key']  = implode( ':', [ $event['type'], $event['object_id'], $event['user_id'], $event['listing_id'] ] );

		// The id is DERIVED from the key and time rather than randomised, so a
		// capture that fires twice for one thing (an order moving processing ->
		// completed) produces the same id both times without having to look the
		// old record up. That lookup was the bug: it read the raw, unpruned
		// queue, so an expired row could donate its expired timestamp to a
		// genuinely new event, which prune() then deleted on the spot.
		$event['id'] = substr( md5( $event['key'] . '|' . $event['time'] ), 0, 12 );

		/**
		 * Filter a social-proof event before it is queued. Return an empty
		 * value to discard the event.
		 *
		 * @param array $event Event record.
		 */
		$event = apply_filters( 'hpsp_push_event', $event );

		if ( empty( $event ) || ! is_array( $event ) ) {
			return;
		}

		$queue = self::get_queue();

		// Deduplicate: the same action reported twice keeps one record. The
		// time and id come from the event itself (see above), so a re-fired
		// capture cannot re-stamp a purchase as "just now".
		$queue = array_filter(
			$queue,
			function ( $existing ) use ( $event ) {
				return empty( $existing['key'] ) || $existing['key'] !== $event['key'];
			}
		);

		$queue[] = $event;

		// Prune expired events and cap the queue size. Sort first: a re-fired
		// capture keeps its original (older) time, so appending it at the tail
		// would otherwise make the size cap evict a NEWER event in its place.
		$queue = self::prune( $queue );

		usort(
			$queue,
			function ( $a, $b ) {
				return ( isset( $a['time'] ) ? (int) $a['time'] : 0 ) <=> ( isset( $b['time'] ) ? (int) $b['time'] : 0 );
			}
		);

		$size = max( 10, absint( $settings['queue_size'] ) );

		if ( count( $queue ) > $size ) {
			$queue = array_slice( $queue, -$size );
		}

		update_option( self::QUEUE_OPTION, array_values( $queue ), false );
		delete_transient( self::PAYLOAD_CACHE );
	}

	/**
	 * Get the raw event queue.
	 */
	public static function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, [] );

		return is_array( $queue ) ? array_values( array_filter( $queue, 'is_array' ) ) : [];
	}

	/**
	 * Number of events currently queued and unexpired.
	 */
	public static function count(): int {
		return count( self::prune( self::get_queue() ) );
	}

	/**
	 * Number of events a visitor would actually be shown right now.
	 *
	 * A cancelled booking or refunded order stays in the queue and is filtered
	 * when the feed is built, rather than being purged, so the stored count can
	 * legitimately run ahead of what reaches the screen. The Tools box reports
	 * both numbers so that gap never reads as a fault.
	 */
	public static function display_count(): int {
		return count( self::get_display_events( 0 ) );
	}

	/**
	 * Empty the queue.
	 */
	public static function clear(): void {
		delete_option( self::QUEUE_OPTION );
		delete_transient( self::PAYLOAD_CACHE );
	}

	/**
	 * Remove expired events from a queue array.
	 *
	 * @param array $queue Queue records.
	 */
	protected static function prune( array $queue ): array {
		$lifetime = max( 1, absint( Hpsp_Settings::get_value( 'event_lifetime', 48 ) ) ) * HOUR_IN_SECONDS;
		$now      = time();

		return array_values(
			array_filter(
				$queue,
				function ( $event ) use ( $lifetime, $now ) {
					if ( empty( $event['time'] ) ) {
						return false;
					}

					$ttl = ( isset( $event['type'] ) && 'test' === $event['type'] ) ? self::TEST_EVENT_TTL : $lifetime;

					return ( $now - (int) $event['time'] ) < $ttl;
				}
			)
		);
	}

	/**
	 * Scheduled cleanup: persist the pruned queue.
	 */
	public static function cleanup(): void {
		$queue  = self::get_queue();
		$pruned = self::prune( $queue );

		if ( count( $pruned ) !== count( $queue ) ) {
			update_option( self::QUEUE_OPTION, $pruned, false );
			delete_transient( self::PAYLOAD_CACHE );
		}
	}

	// -------------------------------------------------------------------------
	// Rendering.
	// -------------------------------------------------------------------------

	/**
	 * Build the display payload for the browser: enabled, unexpired events
	 * rendered against the current templates, newest first.
	 *
	 * @param int $limit Maximum events to return, or 0 for every renderable
	 *                   event. The default caps what a single request carries;
	 *                   the admin counter passes 0 because a capped total would
	 *                   report events as "held back" when they are merely
	 *                   waiting for the next request.
	 */
	public static function get_display_events( int $limit = self::MAX_SENT_EVENTS ): array {
		$settings = Hpsp_Settings::get();
		$queue    = self::prune( self::get_queue() );

		// Newest first.
		usort(
			$queue,
			function ( $a, $b ) {
				return (int) $b['time'] <=> (int) $a['time'];
			}
		);

		$events = [];

		foreach ( $queue as $event ) {
			$rendered = self::render_event( $event, $settings );

			if ( $rendered ) {
				$events[] = $rendered;
			}

			if ( $limit > 0 && count( $events ) >= $limit ) {
				break;
			}
		}

		return $events;
	}

	/**
	 * Render a single queued event, or null when it should not be displayed.
	 *
	 * @param array $event    Queued event record.
	 * @param array $settings Plugin settings.
	 */
	public static function render_event( array $event, array $settings ): ?array {
		$type       = isset( $event['type'] ) ? (string) $event['type'] : '';
		$user_id    = isset( $event['user_id'] ) ? (int) $event['user_id'] : 0;
		$listing_id = isset( $event['listing_id'] ) ? (int) $event['listing_id'] : 0;

		if ( 'test' === $type ) {
			$config = [
				// phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText, WordPress.WP.I18n.MissingTranslatorsComment -- HivePress-style named token, not a printf placeholder.
				'template' => __( '<strong>%username%</strong> just sent a test popup, looking good! 🎉', 'social-proof-for-hivepress' ),
				'image'    => 'avatar',
				'icon'     => '',
			];
		} else {
			$types = self::types();

			if ( ! isset( $types[ $type ] ) || empty( $settings['events'][ $type ]['enabled'] ) ) {
				return null;
			}

			$config = $settings['events'][ $type ];

			// An empty stored template means "use the built-in wording", which
			// keeps the default translatable rather than frozen at save time.
			if ( '' === (string) $config['template'] ) {
				$config['template'] = $types[ $type ]['template'];
			}

			if ( empty( $config['icon'] ) ) {
				$config['icon'] = $types[ $type ]['icon'];
			}
		}

		// Re-check the administrator exclusion at render time; some plugins
		// assign roles only after the user_register capture has already run.
		if ( 'test' !== $type && ! empty( $settings['exclude_admins'] ) && $user_id && user_can( $user_id, 'manage_options' ) ) {
			return null;
		}

		// Skip events whose listing has been removed or unpublished.
		if ( $listing_id && 'publish' !== get_post_status( $listing_id ) ) {
			return null;
		}

		// Skip events whose source object was removed or reverted after being queued.
		$object_id = isset( $event['object_id'] ) ? (int) $event['object_id'] : 0;

		switch ( $type ) {
			// Reviews that were deleted or unapproved.
			case 'review_submitted':
				$comment = $object_id ? get_comment( $object_id ) : null;

				if ( ! $comment || 1 !== (int) $comment->comment_approved ) {
					return null;
				}
				break;

			// Favourites that were removed and enquiries that were deleted.
			case 'favorite_added':
			case 'message_sent':
				if ( ! $object_id || ! get_comment( $object_id ) ) {
					return null;
				}
				break;

			// Vendors that were unpublished.
			case 'vendor_registered':
				if ( ! $object_id || 'publish' !== get_post_status( $object_id ) ) {
					return null;
				}
				break;

			// Bookings that were cancelled. The status check alone is not
			// enough: HivePress's own cancel action sets a "canceled" flag
			// (hp_canceled meta) and can leave the post published, so a
			// cancelled booking kept advertising itself for the whole event
			// lifetime (found on staging).
			case 'booking_confirmed':
				if ( ! $object_id || 'publish' !== get_post_status( $object_id ) ) {
					return null;
				}

				if ( get_post_meta( $object_id, 'hp_canceled', true ) ) {
					return null;
				}
				break;

			// Orders that were cancelled, refunded or failed after payment.
			// Without this a refunded sale kept promoting itself, which is
			// exactly the claim a social-proof plugin must not make.
			case 'order_paid':
				if ( ! $object_id || ! function_exists( 'wc_get_order' ) ) {
					break;
				}

				$order = wc_get_order( $object_id );

				if ( ! $order || ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
					return null;
				}
				break;
		}

		$anonymise = ! empty( $settings['anonymise'] );

		$tokens  = self::build_tokens( $event, $settings );
		$message = strtr( (string) $config['template'], $tokens );
		$message = wp_kses( $message, Hpsp_Settings::allowed_template_tags() );
		$message = trim( preg_replace( '/[ \t]{2,}/', ' ', $message ) );

		if ( '' === wp_strip_all_tags( $message ) ) {
			return null;
		}

		// Icon tiles use the Font Awesome 7 stylesheet the plugin enqueues
		// itself whenever an enabled event uses them (HivePress only bundles
		// the FA5 solid font, which has no brand glyphs and none of the FA6/7
		// names - see Hpsp_Frontend::enqueue_fontawesome()). The style
		// travels with the slug so the browser emits the right class pair:
		// `fa-solid fa-{slug}` or `fa-brands fa-{slug}`.
		$icon       = '';
		$icon_style = '';

		if ( 'icon' === $config['image'] && in_array( (string) $config['icon'], Hpsp_Settings::allowed_icons(), true ) ) {
			$icon       = (string) $config['icon'];
			$icon_style = Hpsp_Settings::icon_style( $icon );
		}

		return [
			'id'        => isset( $event['id'] ) ? (string) $event['id'] : substr( md5( wp_json_encode( $event ) ), 0, 12 ),
			'type'      => $type,
			'html'      => $message,
			'img'       => self::event_image( $config['image'], $user_id, $listing_id, $settings ),
			'icon'      => $icon,
			'iconStyle' => $icon_style,
			'initial'   => $anonymise ? self::initial( __( 'Someone', 'social-proof-for-hivepress' ) ) : self::actor_initial( $user_id ),

			// Test popups report no actor: the browser filters out a viewer's
			// own activity, which otherwise made "Send test popup" invisible
			// to the very admin who pressed it (found on staging). Anonymised
			// feeds report none either, so user IDs never leave the site.
			'actor'     => ( 'test' === $type || $anonymise ) ? 0 : $user_id,
			'time'      => isset( $event['time'] ) ? (int) $event['time'] : time(),
		];
	}

	/**
	 * Build the token replacement map for an event.
	 *
	 * @param array $event    Queued event record.
	 * @param array $settings Plugin settings, as passed to render_event().
	 */
	protected static function build_tokens( array $event, array $settings = [] ): array {
		$anonymise  = ! empty( $settings['anonymise'] );
		$user_id    = isset( $event['user_id'] ) ? (int) $event['user_id'] : 0;
		$listing_id = isset( $event['listing_id'] ) ? (int) $event['listing_id'] : 0;
		$object_id  = isset( $event['object_id'] ) ? (int) $event['object_id'] : 0;
		$type       = isset( $event['type'] ) ? (string) $event['type'] : '';

		$username = $anonymise ? __( 'Someone', 'social-proof-for-hivepress' ) : self::format_username( $user_id );

		$tokens = [
			'username'   => esc_html( $username ),
			'first_name' => esc_html( $anonymise ? $username : self::first_name( $user_id, $username ) ),
			'site_name'  => esc_html( get_bloginfo( 'name' ) ),
		];

		// Listing tokens.
		$listing_title = $listing_id ? get_the_title( $listing_id ) : '';
		$listing_url   = $listing_id ? get_permalink( $listing_id ) : '';

		$tokens['listing_title'] = esc_html( $listing_title );
		$tokens['listing_url']   = $listing_url ? esc_url( $listing_url ) : '';

		$tokens['listing_title_link'] = ( $listing_title && $listing_url )
			? '<a href="' . esc_url( $listing_url ) . '">' . esc_html( $listing_title ) . '</a>'
			: esc_html( $listing_title );

		// Location tokens (Geolocation extension). Listing events use the
		// listing's location; vendor events fall back to the vendor post's
		// hp_location meta, and sign-ups to the user's hp_location meta (a
		// user Location attribute, where the site defines one).
		$location = '';

		if ( $listing_id ) {
			$location = self::listing_location( $listing_id );
		} elseif ( 'vendor_registered' === $type && $object_id ) {
			$location = (string) get_post_meta( $object_id, 'hp_location', true );
		} elseif ( 'user_registered' === $type && $user_id ) {
			// The source attribute is admin-configurable: user attributes store
			// as hp_{name} user meta. A Location attribute on user profiles
			// normally comes from the Geolocation Plus extension.
			$attribute = isset( $settings['user_location_attribute'] ) ? sanitize_key( (string) $settings['user_location_attribute'] ) : '';

			// A coordinate companion saved before those were filtered out of
			// the picker would still render "in 55.9533"; ignore them here too.
			if ( preg_match( '/_(latitude|longitude)$/', $attribute ) ) {
				$attribute = '';
			}

			if ( '' !== $attribute ) {
				$value = get_user_meta( $user_id, 'hp_' . $attribute, true );

				if ( is_scalar( $value ) ) {
					$location = (string) $value;
				}
			}
		}

		$location_link = '';

		if ( $location ) {
			$location_link = '<a href="https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location ) . '" target="_blank" rel="noopener">' . esc_html( $location ) . '</a>';
		}

		$tokens['location']      = esc_html( $location );
		$tokens['location_link'] = $location_link ? $location_link : esc_html( $location );

		// Grammar-safe location: renders "in Edinburgh" only when a location
		// exists, so templates like "%username% %in_location% just booked"
		// never show a dangling "in" for events without one.
		$tokens['in_location'] = '';

		if ( '' !== $location ) {
			/* translators: %s: a place name, e.g. Edinburgh. */
			$tokens['in_location'] = sprintf( __( 'in %s', 'social-proof-for-hivepress' ), esc_html( $location ) );
		}

		// Vendor tokens.
		$tokens['vendor_name'] = esc_html( self::vendor_name( $listing_id, $type, $object_id ) );

		// Booking tokens.
		$tokens['booking_start'] = '';
		$tokens['booking_end']   = '';

		if ( 'booking_confirmed' === $type && $object_id ) {
			$start = (int) get_post_meta( $object_id, 'hp_start_time', true );
			$end   = (int) get_post_meta( $object_id, 'hp_end_time', true );

			$tokens['booking_start'] = $start ? esc_html( date_i18n( get_option( 'date_format' ), $start ) ) : '';
			$tokens['booking_end']   = $end ? esc_html( date_i18n( get_option( 'date_format' ), $end ) ) : '';
		}

		// Review tokens. HivePress Reviews stores the rating in comment_karma.
		$tokens['rating']       = '';
		$tokens['rating_stars'] = '';

		if ( 'review_submitted' === $type && $object_id ) {
			$comment = get_comment( $object_id );
			$rating  = $comment ? (int) $comment->comment_karma : 0;

			if ( $rating > 0 ) {
				$rating = min( $rating, 5 );

				$tokens['rating']       = esc_html( (string) $rating );
				$tokens['rating_stars'] = esc_html( str_repeat( '★', $rating ) );
			}
		}

		/**
		 * Filter the token replacement map for an event.
		 *
		 * @param array $tokens Replacement map ( 'token' => escaped HTML, no delimiters ).
		 * @param array $event  Queued event record.
		 */
		$tokens = apply_filters( 'hpsp_event_tokens', $tokens, $event );

		// Templates accept the HivePress-style %token% form and, for backwards
		// compatibility with earlier builds, the legacy [token] form.
		$replacements = [];

		foreach ( $tokens as $name => $value ) {
			$replacements[ '%' . $name . '%' ] = (string) $value;
			$replacements[ '[' . $name . ']' ] = (string) $value;
		}

		return $replacements;
	}

	/**
	 * Shorten an actor's public name to "First L." for privacy where the
	 * member has supplied a name, otherwise showing the same name HivePress
	 * itself displays for them.
	 *
	 * @param int $user_id User ID.
	 */
	public static function format_username( int $user_id ): string {
		if ( ! $user_id ) {
			return __( 'Someone', 'social-proof-for-hivepress' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return __( 'Someone', 'social-proof-for-hivepress' );
		}

		$first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
		$last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );

		if ( '' !== $first ) {
			return '' !== $last ? $first . ' ' . self::initial( $last ) . '.' : $first;
		}

		// Fall back to splitting the display name.
		$display = trim( $user->display_name ? $user->display_name : $user->user_login );
		$parts   = preg_split( '/\s+/', $display );

		if ( is_array( $parts ) && count( $parts ) > 1 ) {
			$last = array_pop( $parts );

			return $parts[0] . ' ' . self::initial( $last ) . '.';
		}

		// Anything else is left exactly as HivePress renders it. HivePress owns
		// display_name and rewrites it on every save from its own Settings >
		// Users > Display Name option, falling back to the username when the
		// chosen name parts are empty (reference/hivepress/includes/components/
		// class-user.php:166-219). Reformatting it here would show a different
		// name in popups than the rest of the site shows, so the site's own
		// setting stays the single source of truth.

		return $display ? $display : __( 'Someone', 'social-proof-for-hivepress' );
	}

	/**
	 * Actor's first name (falls back to the formatted username).
	 *
	 * @param int    $user_id  User ID.
	 * @param string $fallback Fallback name.
	 */
	protected static function first_name( int $user_id, string $fallback ): string {
		$first = $user_id ? trim( (string) get_user_meta( $user_id, 'first_name', true ) ) : '';

		return '' !== $first ? $first : $fallback;
	}

	/**
	 * Uppercase first letter of a name (multibyte-safe).
	 *
	 * @param string $name Name.
	 */
	protected static function initial( string $name ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : mb_substr( $name, 0, 1 );
		}

		return strtoupper( substr( $name, 0, 1 ) );
	}

	/**
	 * Single-letter avatar fallback shown when no image is available.
	 *
	 * @param int $user_id User ID.
	 */
	protected static function actor_initial( int $user_id ): string {
		$name = self::format_username( $user_id );

		return self::initial( $name );
	}

	/**
	 * Listing location string via the HivePress model, with a meta fallback.
	 *
	 * @param int $listing_id Listing ID.
	 */
	protected static function listing_location( int $listing_id ): string {
		if ( class_exists( '\HivePress\Models\Listing' ) ) {
			try {
				$listing = \HivePress\Models\Listing::query()->get_by_id( $listing_id );

				// Magic getters make is_callable() always true on models, so the
				// only meaningful guard is the instance check; a missing field
				// (Geolocation inactive) just returns null through __call.
				if ( $listing instanceof \HivePress\Models\Listing ) {
					$location = (string) $listing->get_location();

					if ( '' !== $location ) {
						return $location;
					}
				}
			} catch ( \Throwable $e ) {
				// The meta lookup below is the fallback for any model failure.
				unset( $e );
			}
		}

		return (string) get_post_meta( $listing_id, 'hp_location', true );
	}

	/**
	 * Vendor display name for a listing (the vendor is the listing's parent post).
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $type       Event type.
	 * @param int    $object_id  Related object ID.
	 */
	protected static function vendor_name( int $listing_id, string $type, int $object_id ): string {
		$vendor_id = 0;

		if ( 'vendor_registered' === $type && $object_id ) {
			$vendor_id = $object_id;
		} elseif ( $listing_id ) {
			$parent_id = (int) wp_get_post_parent_id( $listing_id );

			if ( $parent_id && 'hp_vendor' === get_post_type( $parent_id ) ) {
				$vendor_id = $parent_id;
			}
		}

		if ( ! $vendor_id ) {
			return '';
		}

		$title = get_the_title( $vendor_id );

		if ( '' !== $title ) {
			return $title;
		}

		$author_id = (int) get_post_field( 'post_author', $vendor_id );

		return $author_id ? self::format_username( $author_id ) : '';
	}

	/**
	 * Resolve a configured attachment setting to a thumbnail URL.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $key      Setting key holding an attachment ID.
	 */
	protected static function attachment_url( array $settings, string $key ): string {
		$attachment_id = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

		if ( ! $attachment_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		return $url ? $url : '';
	}

	/**
	 * Resolve the popup image URL for an event.
	 *
	 * @param string $source     Image source: avatar|listing|icon|none.
	 * @param int    $user_id    Actor user ID.
	 * @param int    $listing_id Listing ID.
	 * @param array  $settings   Plugin settings, as passed to render_event().
	 */
	protected static function event_image( string $source, int $user_id, int $listing_id, array $settings = [] ): string {
		// Icon tiles are rendered client-side from the payload's icon name.
		if ( 'none' === $source || 'icon' === $source ) {
			return '';
		}

		// Anonymised popups never show a member's own picture or the fallback
		// avatar: a recognisable photo beside the word "Someone" implies that
		// person did it (found on staging). The admin-chosen anonymous image
		// shows instead, or the neutral initial badge when none is set.
		if ( ! empty( $settings['anonymise'] ) ) {
			$anonymous = self::attachment_url( $settings, 'anonymous_avatar' );

			return $anonymous ? esc_url_raw( $anonymous ) : '';
		}

		if ( 'listing' === $source && $listing_id ) {
			$thumbnail = get_the_post_thumbnail_url( $listing_id, 'thumbnail' );

			if ( $thumbnail ) {
				return esc_url_raw( $thumbnail );
			}
		}

		// Custom fallback avatar, shown for users without their own Gravatar.
		$fallback = self::attachment_url( $settings, 'fallback_avatar' );

		// Avatar, or fallback when the listing has no image.
		if ( $user_id ) {
			$args = [ 'size' => 96 ];

			// Gravatar serves the "default" URL for users without an avatar,
			// so members who set one still see their own picture.
			if ( $fallback ) {
				$args['default'] = $fallback;
			}

			$avatar = get_avatar_url( $user_id, $args );

			if ( $avatar ) {
				return esc_url_raw( $avatar );
			}
		}

		return $fallback ? esc_url_raw( $fallback ) : '';
	}
}
