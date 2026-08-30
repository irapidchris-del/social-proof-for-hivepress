=== Social Proof for HivePress ===
Contributors: chrisb
Tags: hivepress, social proof, notifications, popups, marketplace
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live, highly customisable social-proof toast popups for HivePress marketplaces: recent sign-ups, listings, bookings, reviews, sales and more.

== Description ==

Social Proof for HivePress makes your marketplace feel alive. It listens for real activity happening on your site and shows small pill-shaped toast popups such as:

* "Anna K. just joined Your Site"
* "James T. just posted a new listing: Sunny Loft Apartment"
* "Maria S. just booked City Bike Tour"
* "Daniel R. just left a 5-star review on Harbour View Studio"
* "Someone just purchased Vintage Camera Kit"

Names are shortened to "First L." wherever a member has a first and last name saved, and message contents (for enquiries) are never shown. Where a member has given no name, popups show exactly the name HivePress shows elsewhere on your site, which you control under HivePress Settings > Users > Display Name and which falls back to the member's username. The "Anonymise members" setting replaces every name with "Someone".

= Tracked events =

* New user sign-ups (WordPress core)
* New listings going live (HivePress)
* Confirmed bookings (HivePress Bookings)
* Approved reviews, with star rating tokens (HivePress Reviews)
* Paid orders (HivePress Marketplace / WooCommerce)
* Listings added to favourites (HivePress Favorites)
* New vendor profiles (HivePress)
* Listing enquiries (HivePress Messages)

Every event type can be switched on or off individually and has its own message template with dynamic tokens like %username%, %listing_title_link%, %location%, %rating_stars%, %booking_start% and more, written between percent signs the same way as HivePress email templates. Templates support basic HTML, and leaving a template box empty puts the built-in wording back.

= Customisation =

* Six positions on desktop plus a separate position for mobile, or hide popups on mobile entirely
* Slide, fade and pop animations with adjustable speed (visitors with reduced-motion preferences always get a gentle fade)
* Colours for background, text, links and border; corner radius (pill to square), border width, shadow presets, font size, max width
* Round, rounded or square popup image (user avatar or listing photo, with automatic fallbacks)
* Custom fallback avatar for members without a profile photo
* Icon tiles with a large Font Awesome 6/7 icon choice (brand icons included), plus icon size, weight, colour and tile background controls
* Timing controls: initial delay, display duration, gap between popups, popups visible at once, max per page view
* Rotation controls: newest-first or random order, no-repeats-per-session, optional looping, and an event lifetime so the feed never goes stale
* Visitor-friendly: closing a popup can snooze all popups for a configurable time, and logged-in users can hide popups permanently from HivePress Account → Settings
* Anonymise mode: show "Someone" instead of member names, sitewide
* Corner offsets, plus automatic room-making when Notifications for HivePress shows a pop-up in the same corner
* Exclude popups on specific pages with URL patterns
* Live design preview, test popup button and queue tools in the admin

= Privacy =

The plugin stores only minimal event references (user, listing and object IDs) in the WordPress options table, renders names in shortened form where a member has supplied one and otherwise defers to the name HivePress already displays, and never exposes email addresses or message contents. Logged-in users can opt out of seeing popups. Deleting the plugin keeps your settings and preferences so you can reinstall and carry on; tick "Delete all data" on the General tab first if you want everything removed instead.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New.
2. Activate the plugin.
3. Go to Settings → Social Proof to configure events, design and timing.
4. Use "Send test popup" to preview it on your site.

HivePress is recommended but not strictly required. Without it the plugin still shows new user sign-ups.

== Frequently Asked Questions ==

= Why don't popups appear? =

Check that the plugin is enabled in Settings → Social Proof, that at least one event type is enabled, and that events exist (use "Send test popup"). If you use a full-page cache, the popup feed is fetched via the REST API and is not affected, but make sure `/wp-json/` requests are not cached for long periods.

= Can visitors turn popups off? =

Yes. Closing a popup snoozes all popups for a configurable time, and logged-in users get a "Hide activity popups" checkbox in HivePress Account → Settings.

= Are old events shown forever? =

No. Events expire after the configured lifetime (48 hours by default) and the queue keeps only the most recent events.

== Changelog ==

= 1.4.4 =
* Changed - internal formatting only. Some explanatory comments in the settings screen's script were
  reworded to match the wording used across the rest of these extensions, so the file is easier to
  compare with its siblings. Nothing about the settings screen, or anything else the plugin does,
  has changed.

= 1.4.3 =
* Changed - the settings screen now carries the same furniture as every other extension in this
  family. The jump links are labelled "Jump to a section:" and stay with you as you scroll, a Save
  Changes tab sits on the right edge of the screen wherever you are on the page, and a button in
  the bottom corner takes you back to the top. Nothing about what the settings do has changed.

= 1.4.2 =
* Changed - outline icon styles now render as outlines. An icon set to an outline style
  previously appeared filled in, because only the solid style was included with the plugin and
  your browser quietly used that instead.

= 1.4.1 =
* Changed - the icon library is now included with the plugin instead of being loaded from a
  third-party server, which is faster and keeps every request on your own site. Your chosen
  icons and settings are unaffected.

= 1.4.0 =
* Added - icon tiles are now fully customisable: icon size, weight (normal, semi-bold or bold),
  icon colour and tile background colour, under Design > Icon tiles. Defaults match the previous
  appearance, so nothing changes until you adjust them.
* Added - a much larger icon choice, picked from a visual grid that shows each icon, including
  Font Awesome 6/7 icons and brand icons such as Facebook, Instagram and WhatsApp. The Font
  Awesome 7 stylesheet is loaded only when icon tiles are actually in use.
* Added - quick links at the top of the settings screen jump straight to any section, and
  sections are now visually divided.
* Changed - settings descriptions are shorter and wrap at a readable line length.

= 1.3.9 =
* Fixed - pop-ups no longer sit on top of the Action Bar. With Action Bar for HivePress active, a
  pop-up covered most of the bar for the whole time it was on screen, so tapping Home, Browse or
  Account hit the pop-up instead, and tapping the right hand item hit the pop-up's close button,
  which also stopped pop-ups for that visitor.
* Fixed - "Hide activity popups" no longer appears in the middle of submitting a listing or
  registering as a vendor. It belongs on the account settings page and now only appears there.
* Fixed - pop-ups no longer overlap the Notifications for HivePress pop-ups. The check that moves
  them clear read the wrong screen size, so a site using a top position on phones and a bottom
  position on desktops got both stacks in the same corner.
* Fixed - "Exclude pages" now works on a WordPress installed in a subdirectory. Paths were
  compared against the full web address rather than the site relative one the field asks for, so
  nothing ever matched. Paths written the old way still work.
* Fixed - the update details popup now shows code and links inside release notes correctly,
  instead of replacing them with a number.
* Fixed - the settings are now loaded with the rest of the site's options rather than fetched
  separately on every page.
* Fixed - deleting the plugin now also clears the update check's own leftovers and cancels its
  background update check.

= 1.3.8 =
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.

= 1.3.7 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.3.6 =
* Changed: the plugin now declares HivePress as a required plugin, so WordPress warns you before activating it on a site without HivePress rather than leaving it doing nothing.

= 1.3.5 =
* Fixed: the documentation claimed member names are always shortened to "First L.". Popups shorten a name where the member has saved one, and otherwise show exactly the name HivePress itself displays, which you control under HivePress Settings > Users > Display Name.
* Fixed: the queue box in Tools counted every stored event, including ones deliberately held back from visitors, so it could report more popups than your site was actually showing. It now reports both numbers whenever they differ, and says why the rest are held back.

= 1.3.4 =
* Fixed: cancelled bookings and cancelled or refunded orders no longer keep appearing in popups. Previously they carried on advertising themselves for the rest of the event lifetime, which meant your site could promote a sale that no longer existed.
* Fixed: completing an order no longer resets its popup to "just now". Purchase popups are now dated from when the order was actually paid, so clearing a backlog of orders cannot push old sales back to the top of the feed.
* Fixed: the Anonymous image picker no longer opens a window titled "Choose fallback avatar". Each picker now names its own field.
* Fixed: the member location list no longer offers the hidden latitude and longitude fields, which produced popups reading "in 55.9533".
* Changed: popup spacing now follows one consistent scale and the close button sits in line with the message. Square and rounded square images get more room, and with those shapes the corner radius is capped at 16px so a pill's curved ends cannot crowd a straight-edged image.
* Changed: the member location attribute now starts as "None" rather than assuming an attribute called "location" exists, and latitude or longitude fields are refused if one was saved before.

= 1.3.3 =
* New: a "Member location attribute" setting maps any user profile attribute to the sign-up popup location tokens, instead of assuming one named "location". Pairs naturally with a Location attribute from the Geolocation Plus extension.
* New: an "Anonymous image" picker chooses what anonymised popups show instead of member photos, straight from the media library. Empty means a plain initial badge, and the fallback avatar is never used there, so a recognisable face can no longer appear beside "Someone".
* Fixed: the version details popup now renders its changelog as formatted text instead of raw Markdown asterisks.
* Fixed: the version details popup no longer shows an older published version below the one installed.

= 1.3.1 =
* New: an "Anonymise members" setting shows "Someone" instead of member names, with a generic picture instead of their profile photo, and keeps member IDs out of the public popup feed.
* New: location tokens for sign-up and vendor popups, and a %in_location% token that renders "in Edinburgh" only when a location exists, so wording like "Someone in Edinburgh just booked" never shows a dangling "in". Works with the HivePress Geolocation location attribute.
* New: offset settings for the popup corner, so popups can clear floating buttons and bars that share it.
* New: popups now move out of the way automatically while a Notifications for HivePress pop-up is using the same corner, and move back when it clears.
* Fixed: "Send test popup" popups are now visible to the admin who sent them.
* Fixed: the Plugins row kept its "View details" link even when GitHub is unreachable, instead of degrading to a plain website link.
* Fixed: expired test popups can no longer linger in the popup feed past their five-minute life.
* Fixed: an event is no longer marked as "seen" for the no-repeats setting unless the visitor's tab could actually show it.
* Changed: the popup close button now meets the recommended minimum tap size.

= 1.3.0 =
* New: per-event icon popups. Each event can now show a Font Awesome icon on a coloured tile instead of a photo, with a curated icon list that matches HivePress's own set.
* New: a countdown bar along the bottom of each popup that empties as its time runs down and pauses on hover (Design tab, can be switched off).
* New: deleting the plugin now keeps your settings and preferences by default; a "Delete all data" setting on the General tab controls the full clean-up.
* New: a Donate link on the Plugins screen row and in the version details popup.
* Changed: template tokens now use the %token% form, matching HivePress email templates. Old-style [token] templates carry on working.
* Changed: leaving a message template empty now restores the built-in wording, which also keeps it translatable.
* Fixed: update checks no longer send your site address to GitHub through the default WordPress user agent.
* Fixed: backslashes typed into templates or excluded paths are no longer stripped on save.
* Fixed: the colour pickers' reset buttons now return to the plugin's defaults.

= 1.2.0 =
* Fixed a stray grey box around every popup, caused by WordPress core forcing a border onto elements whose inline styles mention border widths.
* Fixed review popups always showing an empty star rating (the rating is stored in the comment karma column, not comment meta).
* Fixed vendor replies to reviews wrongly appearing as new review popups.
* Fixed popups for cancelled bookings, removed favourites, deleted enquiries and unpublished vendors continuing to show until they expired.
* New: choose a custom fallback avatar shown for members without a profile photo (Design tab). Members with their own photo still see it.
* Event capture now stands down during bulk imports, so an import no longer floods the popup queue.
* The administrator exclusion is re-checked when popups render, catching plugins that assign roles after registration.
* Fresh stylesheets and scripts are now guaranteed after every update.

= 1.1.0 =
* Added GitHub-powered updates using WordPress's native update API (no third-party libraries): new versions published as GitHub Releases are detected and installable from the Plugins page, with a "View details" changelog and a manual "Check for updates" link.
* Settings sanitiser now clamps out-of-range negative numbers to the field minimum.

= 1.0.0 =
* Complete rebuild: modular architecture, event registry, render-time templating, REST feed with server-side caching, live admin preview, mobile positions, animations, snooze and per-user opt-out.
