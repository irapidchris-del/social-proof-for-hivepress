# Social Proof for HivePress

Live, highly customisable social-proof toast popups for [HivePress](https://hivepress.io) marketplaces. Real activity — sign-ups, new listings, bookings, reviews, sales, favourites — appears as small pill-shaped notifications that make your site feel alive and nudge visitors to join in.

> "**Anna K.** just booked *Sunny Loft Apartment* — 2 minutes ago"

## Features

- **8 tracked event types**, each individually toggleable with its own message template:
  - user sign-ups, published listings, confirmed bookings, approved reviews, paid orders (Marketplace/WooCommerce), favourites, new vendors, listing enquiries
- **Dynamic tokens** per event: `[username]`, `[first_name]`, `[listing_title]`, `[listing_title_link]`, `[listing_url]`, `[vendor_name]`, `[location]`, `[location_link]`, `[booking_start]`, `[booking_end]`, `[rating]`, `[rating_stars]`, `[site_name]` — plus basic HTML in templates
- **Deep design control**: 6 desktop positions + separate mobile position, slide/fade/pop animations with speed control, colours (background/text/links/border), corner radius, border width, shadow presets, font size, max width, circle/rounded/square images, optional close button and relative-time line
- **Timing & rotation**: initial delay, display duration, gap, popups visible at once, max per page view, newest-first or random order, no-repeat-per-session, optional looping, configurable event lifetime so nothing goes stale
- **Respectful by design**: names shown as "First L.", visitors' own actions filtered out, closing a popup snoozes everything for a configurable period, logged-in users can opt out from HivePress Account → Settings, reduced-motion preference honoured, `aria-live` announcements
- **Admin niceties**: tabbed settings with live preview, test popup button, queue counter and one-click queue clearing
- **Lightweight**: no external services, one cached REST call per page view, dependency-free frontend JS (~4 KB), events stored as small ID references and rendered fresh so template changes apply retroactively

## How it works

1. Capture hooks (core WordPress + WooCommerce, resilient to HivePress internals) record events as minimal ID-based records in a bounded queue.
2. A public REST endpoint (`/wp-json/hpsp/v1/events`) renders the enabled events against the current templates, with a short server-side cache shared by all visitors.
3. The frontend script fetches the feed once per page view and rotates the toasts client-side, applying per-visitor rules (snooze, session no-repeat, own-activity filtering) in the browser so the feed stays cacheable.

## Requirements

- WordPress 6.0+, PHP 7.4+
- [HivePress](https://hivepress.io) for marketplace events (sign-up popups work without it)
- Optional HivePress extensions for their respective events: Bookings, Reviews, Marketplace (WooCommerce), Favorites, Messages

## Installation

1. Copy this folder to `wp-content/plugins/social-proof-for-hivepress/` (or upload a ZIP of it via **Plugins → Add New**).
2. Activate **Social Proof for HivePress**.
3. Configure via **Settings → Social Proof**, then hit **Send test popup**.

## Updates (GitHub-powered)

The plugin checks this repository's **GitHub Releases** for new versions and
surfaces them on the WordPress **Plugins** page — check-for-updates, the
"View details" changelog, and one-click update all work, powered by the
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library bundled in `lib/`.

### Cutting a release

1. Bump the `Version:` header in `social-proof-for-hivepress.php` (and the
   `Stable tag` in `readme.txt`). Commit.
2. Build the distributable ZIP:
   ```bash
   ./build.sh
   ```
   This writes to `dist/`:
   - `social-proof-for-hivepress.zip` — **attach this to the release**
   - `social-proof-for-hivepress-<version>.zip` — same contents, version-tagged
     filename for your own tracking
3. Create a **GitHub Release** tagged with the version (e.g. `v1.1.0` — a
   leading `v` is fine) and attach `social-proof-for-hivepress.zip` as a
   release asset.

   > Or let CI do step 2–3's upload: the included workflow
   > (`.github/workflows/release.yml`) builds and attaches the asset
   > automatically whenever you publish a release.

WordPress sites running the plugin will detect the new version within a day
(or immediately via **Dashboard → Updates → Check again**) and can update in
one click. The update installs from the attached `.zip` asset, so it always
lands in the correct `social-proof-for-hivepress` folder with no
folder-mismatch warnings.

> **Note:** the main plugin file (`social-proof-for-hivepress.php`) and the
> folder name never change between versions — WordPress identifies the plugin
> by `folder/main-file.php`, so only the *ZIP filename* ever carries a version
> tag, never the plugin file itself.

### Always-latest download link (for the forum)

This URL always redirects to the newest release's asset, so you can post it
once and never update it:

```
https://github.com/irapidchris-del/social-proof-for-hivepress/releases/latest/download/social-proof-for-hivepress.zip
```

Because the asset is named identically on every release, the link ends in
`.zip` and downloads the latest version instantly.

### Private repository?

If you make the repo private, add an access token from an integration:

```php
add_action( 'hpsp_update_checker', function ( $checker ) {
    $checker->setAuthentication( 'your-github-token' );
} );
```

## Developer hooks

| Hook | Type | Description |
| --- | --- | --- |
| `hpsp_event_types` | filter | Add or modify registered event types (label, template, tokens, image source). |
| `hpsp_push_event` | filter | Inspect/modify an event before it is queued; return empty to discard. |
| `hpsp_event_tokens` | filter | Add custom template tokens for an event. |
| `hpsp_display` | filter | Force-show or force-hide popups for the current request. |
| `hpsp_update_checker` | action | Runs with the Plugin Update Checker instance; use it to set authentication or tweak the check period. |

## License

GPL-2.0-or-later. Author: Chris B.
