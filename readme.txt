=== Social Proof for HivePress ===
Contributors: chrisb
Tags: hivepress, social proof, notifications, popups, marketplace
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live, highly customisable social-proof toast popups for HivePress marketplaces — recent sign-ups, listings, bookings, reviews, sales and more.

== Description ==

Social Proof for HivePress makes your marketplace feel alive. It listens for real activity happening on your site and shows small pill-shaped toast popups such as:

* "Anna K. just joined Your Site"
* "James T. just posted a new listing: Sunny Loft Apartment"
* "Maria S. just booked City Bike Tour"
* "Daniel R. just left a 5-star review on Harbour View Studio"
* "Someone just purchased Vintage Camera Kit"

Names are always shortened to "First L." for privacy, and message contents (for enquiries) are never shown.

= Tracked events =

* New user sign-ups (WordPress core)
* New listings going live (HivePress)
* Confirmed bookings (HivePress Bookings)
* Approved reviews, with star rating tokens (HivePress Reviews)
* Paid orders (HivePress Marketplace / WooCommerce)
* Listings added to favourites (HivePress Favorites)
* New vendor profiles (HivePress)
* Listing enquiries (HivePress Messages)

Every event type can be switched on or off individually and has its own message template with dynamic tokens like [username], [listing_title_link], [location], [rating_stars], [booking_start] and more. Templates support basic HTML.

= Customisation =

* Six positions on desktop plus a separate position for mobile — or hide popups on mobile entirely
* Slide, fade and pop animations with adjustable speed (visitors with reduced-motion preferences always get a gentle fade)
* Colours for background, text, links and border; corner radius (pill to square), border width, shadow presets, font size, max width
* Round, rounded or square popup image — user avatar or listing photo, with automatic fallbacks
* Timing controls: initial delay, display duration, gap between popups, popups visible at once, max per page view
* Rotation controls: newest-first or random order, no-repeats-per-session, optional looping, and an event lifetime so the feed never goes stale
* Visitor-friendly: closing a popup can snooze all popups for a configurable time, and logged-in users can hide popups permanently from HivePress Account → Settings
* Exclude popups on specific pages with URL patterns
* Live design preview, test popup button and queue tools in the admin

= Privacy =

The plugin stores only minimal event references (user, listing and object IDs) in the WordPress options table, renders names in shortened form, and never exposes email addresses or message contents. Logged-in users can opt out of seeing popups. All data is removed on uninstall.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New.
2. Activate the plugin.
3. Go to Settings → Social Proof to configure events, design and timing.
4. Use "Send test popup" to preview it on your site.

HivePress is recommended but not strictly required — without it the plugin still shows new user sign-ups.

== Frequently Asked Questions ==

= Popups don't appear — why? =

Check that the plugin is enabled in Settings → Social Proof, that at least one event type is enabled, and that events exist (use "Send test popup"). If you use a full-page cache, the popup feed is fetched via the REST API and is not affected, but make sure `/wp-json/` requests are not cached for long periods.

= Can visitors turn popups off? =

Yes. Closing a popup snoozes all popups for a configurable time, and logged-in users get a "Hide activity popups" checkbox in HivePress Account → Settings.

= Are old events shown forever? =

No. Events expire after the configured lifetime (48 hours by default) and the queue keeps only the most recent events.

== Changelog ==

= 1.1.0 =
* Added GitHub-powered updates using WordPress's native update API (no third-party libraries): new versions published as GitHub Releases are detected and installable from the Plugins page, with a "View details" changelog and a manual "Check for updates" link.
* Settings sanitiser now clamps out-of-range negative numbers to the field minimum.

= 1.0.0 =
* Complete rebuild: modular architecture, event registry, render-time templating, REST feed with server-side caching, live admin preview, mobile positions, animations, snooze and per-user opt-out.
