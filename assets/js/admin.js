/**
 * Social Proof for HivePress — admin settings screen.
 *
 * Tab switching, colour pickers and the live design preview.
 */
(function ($) {
	'use strict';

	var shadows = (window.HPSPAdmin && window.HPSPAdmin.shadows) || {};

	/* ======================================================================
	 * SHARED SETTINGS CHROME
	 *
	 * Three pieces of furniture for a long settings tab: the quick-links
	 * anchor nav, a floating Save control and a back-to-top button. Written
	 * to be copied verbatim into the other plugins, so everything below is
	 * self-contained and the only plugin-specific values are the two
	 * constants in CHROME.
	 *
	 * THE HOUSE RULE THIS IMPLEMENTS (resources/hivepress-settings.md, "The
	 * settings anchor nav: one shared marker class", 2026-08-30). Several of
	 * these plugins can decorate one settings screen, so each piece carries
	 * TWO classes: a shared marker that is never styled and exists only so
	 * siblings can find it (`hp-settings-nav`, `hp-settings-save`,
	 * `hp-settings-top`), plus the plugin's own prefixed class carrying all
	 * the CSS. Before rendering a piece, test for its marker with an EXACT
	 * class selector and stand down if a sibling got there first, so the
	 * owner sees one of each however many extensions are active.
	 *
	 * The exact test is the point. The old convention was the substring
	 * `nav[class*="settings-nav"]`, which was blind to three of the plugins
	 * it was meant to see - Notifications' `hpnf-anchors`, Action Bar's
	 * `hpab-anchor-nav` and Account Menu Enhancer's own `amehp-section-nav` -
	 * and it failed silently.
	 * ================================================================== */

	var CHROME = {
		// This plugin's own class prefix and the field prefix that says the
		// rendered tab belongs to it. The only two lines to change on a copy.
		prefix: 'hpsp',
		fieldPrefix: 'hpsp_settings',
	};

	/*
	 * The wording, from the localised data this plugin prints on the page.
	 *
	 * Every string falls back to its English source, so the chrome still
	 * renders if the enqueue ever stops localising - a nav labelled in
	 * English is a smaller failure than a nav labelled "undefined".
	 * Keep this helper self-contained: the whole block is copied between
	 * plugins, and anything it reaches out to would have to be copied with
	 * it or land as a missing function on somebody's settings screen.
	 */
	function chromeLabels() {
		return ( window.HPSPAdmin && window.HPSPAdmin.labels ) || {};
	}

	/**
	 * The settings form, but only when this plugin's tab is the one rendered.
	 *
	 * Gating on our own fields rather than on heading count, because a count
	 * is true of every HivePress tab: Geolocation Plus 1.1.0 gated that way
	 * and decorated other plugins' tabs until 1.1.1.
	 *
	 * ADAPTED, one selector. This plugin does not add a HivePress settings
	 * tab; it registers a page of its own under Settings, so the form to
	 * find is that page's. The field test below is the reference's, working
	 * unchanged: every input on this screen is named hpsp_settings[...].
	 *
	 * @return {Element|null}
	 */
	function chromeForm() {
		var form = document.querySelector( '.hpsp-wrap form.hpsp-main' );

		if ( ! form || ! form.querySelector( '[name^="' + CHROME.fieldPrefix + '"]' ) ) {
			return null;
		}

		return form;
	}

	/**
	 * The quick-links anchor nav.
	 *
	 * WordPress renders settings sections as bare <h2>s through
	 * do_settings_sections(), with no hook to add anchors, so the ids and the
	 * nav have to be added here.
	 *
	 * @param {Element} form Settings form.
	 */
	function addSectionNav( form ) {
		if ( document.querySelector( 'nav.hp-settings-nav' ) ) {
			return;
		}

		// Direct children only: a panel inside the form can carry an h2 of its
		// own, and that one is neither a section nor a target.
		var headings = form.querySelectorAll( ':scope > h2' );

		if ( headings.length < 2 ) {
			return;
		}

		var nav = document.createElement( 'nav' ),
			navLabel = chromeLabels().jumpTo || 'Jump to a section:';

		nav.className = 'hp-settings-nav ' + CHROME.prefix + '-settings-nav';

		/*
		 * The bar opens with its own wording, not just an aria-label.
		 *
		 * A row of pills with nothing in front of it reads as decoration, and
		 * the one audience that was told what it is - a screen reader, through
		 * the aria-label - is the one audience that could not see the pills
		 * anyway. The visible text is part of the house chrome spec
		 * (resources/hivepress-settings.md, "The settings anchor nav"), so it
		 * carries its own class for the sibling plugins to copy, and the
		 * aria-label is dropped: the text now names the nav for everybody, and
		 * leaving both would have a screen reader announce the name twice.
		 */
		var label = document.createElement( 'span' );

		label.className = CHROME.prefix + '-settings-nav__label';
		label.textContent = navLabel;

		nav.appendChild( label );

		headings.forEach( function ( heading, index ) {

			/*
			 * Reuse the id WordPress already put on the heading and mint one
			 * only where there is none. Overwriting it breaks every link,
			 * bookmark and sibling script pointing at the real
			 * `wp-settings-section-{name}` id.
			 */
			if ( ! heading.id ) {
				heading.id = CHROME.prefix + '-section-' + index;
			}

			heading.classList.add( CHROME.prefix + '-section-heading' );

			if ( 0 === index ) {
				heading.classList.add( CHROME.prefix + '-section-heading--first' );
			}

			var link = document.createElement( 'a' );

			link.href = '#' + heading.id;

			// textContent on both ends, so heading markup can never become
			// link markup.
			link.textContent = heading.textContent;

			nav.appendChild( link );
		} );

		form.insertBefore( nav, headings[ 0 ] );
	}

	/**
	 * The floating Save control.
	 *
	 * It submits the real form rather than carrying any save logic of its
	 * own: requestSubmit() runs the same validation and the same submit
	 * handlers as pressing the button at the bottom of the page, so there is
	 * only ever one way to save. The real button stays exactly where it was.
	 *
	 * @param {Element} form Settings form.
	 */
	function addFloatingSave( form ) {
		if ( document.querySelector( '.hp-settings-save' ) ) {
			return;
		}

		var submit = form.querySelector( 'input[type="submit"], button[type="submit"]' );

		if ( ! submit ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			text = document.createElement( 'span' ),
			label = chromeLabels().save || 'Save Changes';

		button.type = 'button';

		/*
		 * Core's own button classes, so WordPress paints it.
		 *
		 * This control IS the form's Save button, moved somewhere reachable,
		 * so it has to look like it - and "looks like it" is not one colour.
		 * Every user can pick an Admin Colour Scheme under Users > Profile,
		 * and each scheme repaints .wp-core-ui .button-primary. Painting our
		 * own #2271b1 matched the default scheme and nothing else: measured on
		 * 2026-08-30 under Modern, the real button was rgb(56,88,233) and this
		 * tab rgb(34,113,177), side by side on the same screen. The prefixed
		 * class is kept for layout only.
		 */
		button.className = 'hp-settings-save ' + CHROME.prefix + '-settings-save button button-primary';
		button.setAttribute( 'aria-label', label );

		icon.className = 'dashicons dashicons-saved';
		icon.setAttribute( 'aria-hidden', 'true' );

		text.className = CHROME.prefix + '-settings-save__text';
		text.textContent = label;

		button.appendChild( icon );
		button.appendChild( text );

		button.addEventListener( 'click', function () {

			// requestSubmit() fires the submit event and the browser's own
			// validation; form.submit() would skip both. Older browsers
			// without it get the real button pressed instead, which is the
			// same thing by a longer route.
			if ( form.requestSubmit ) {
				form.requestSubmit( submit );
			} else {
				submit.click();
			}
		} );

		document.body.appendChild( button );
	}

	/**
	 * The back-to-top button.
	 *
	 * Hidden until the page has actually scrolled, so it never covers
	 * anything on a tab short enough not to need it.
	 */
	function addBackToTop() {
		if ( document.querySelector( '.hp-settings-top' ) ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			label = chromeLabels().backToTop || 'Back to top';

		button.type = 'button';

		// Core's secondary button, for the same reason as the Save tab above:
		// its blue is the scheme's blue, not a hex of ours.
		button.className = 'hp-settings-top ' + CHROME.prefix + '-settings-top button';
		button.setAttribute( 'aria-label', label );
		button.title = label;
		button.hidden = true;

		icon.className = 'dashicons dashicons-arrow-up-alt2';
		icon.setAttribute( 'aria-hidden', 'true' );

		button.appendChild( icon );

		button.addEventListener( 'click', function () {

			// A reader who has asked for reduced motion is asking not to be
			// moved through a long page; "auto" jumps instead of animating.
			var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( {
				top: 0,
				behavior: reduced ? 'auto' : 'smooth',
			} );

			// Focus follows the scroll, so a keyboard user carries on from the
			// top of the page rather than from a button that is now off screen.
			var heading = document.querySelector( '.hp-page__title' );

			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus( { preventScroll: true } );
			}
		} );

		document.body.appendChild( button );

		/*
		 * The show/hide runs straight off the scroll event.
		 *
		 * It used to be deferred into requestAnimationFrame, which is the
		 * usual advice for scroll handlers - and it meant the button never
		 * appeared at all whenever the page was not being painted, because a
		 * browser pauses rAF on a hidden page and the callback simply never
		 * ran. Caught by measurement on 2026-08-30: document.hidden was true,
		 * the page was scrolled to 1500px, and the button stayed hidden.
		 * Nobody is looking at a page in that state, so the symptom was
		 * invisible rather than harmless - it would equally have hidden a
		 * genuine failure. The work here is two property reads and a boolean
		 * write, which is cheap enough to do on the event itself, so the
		 * optimisation bought nothing and cost correctness.
		 */
		function update() {
			button.hidden = ( window.pageYOffset || document.documentElement.scrollTop ) < 300;
		}

		window.addEventListener( 'scroll', update, { passive: true } );

		update();
	}

	/**
	 * Adds every piece of chrome, one tick after ready.
	 *
	 * The delay is deliberate: load order between plugins is not something
	 * any of them controls, so a sibling whose hook registered first may
	 * still be placing its own nav when this runs. One tick lets it finish,
	 * and the stand-down guards then see it.
	 */
	function addSettingsChrome() {
		window.setTimeout( function () {
			var form = chromeForm();

			if ( ! form ) {
				return;
			}

			addSectionNav( form );
			addFloatingSave( form );
			addBackToTop();
		}, 0 );
	}

	$(function () {
		var $tabs = $('.hpsp-tabs .nav-tab');
		var $panels = $('.hpsp-tab');
		var $toast = $('#hpsp-preview-toast');
		var $stage = $('#hpsp-preview-stage');

		// --------------------------------------------------------------
		// Tabs.
		// --------------------------------------------------------------

		function activateTab(id) {
			$tabs.removeClass('nav-tab-active').filter('[data-tab="' + id + '"]').addClass('nav-tab-active');
			$panels.removeClass('is-active').filter('#hpsp-tab-' + id).addClass('is-active');

			try {
				window.localStorage.setItem('hpspAdminTab', id);
			} catch (e) {
				// Ignore.
			}
		}

		$tabs.on('click', function (event) {
			event.preventDefault();
			activateTab($(this).data('tab'));
		});

		var savedTab = null;

		try {
			savedTab = window.localStorage.getItem('hpspAdminTab');
		} catch (e) {
			// Ignore.
		}

		if (savedTab && $tabs.filter('[data-tab="' + savedTab + '"]').length) {
			activateTab(savedTab);
		}

		// --------------------------------------------------------------
		// Quick links: activate the section's tab, then jump to it.
		// --------------------------------------------------------------

		$(document).on('click', '.hpsp-settings-nav a', function (event) {
			event.preventDefault();

			activateTab($(this).data('tab'));

			var target = document.getElementById('hpsp-sec-' + $(this).data('hpspJump'));

			if (target && target.scrollIntoView) {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});

		// --------------------------------------------------------------
		// Live preview.
		// --------------------------------------------------------------

		function val(key) {
			var $field = $('[data-hpsp="' + key + '"]');

			if (!$field.length) {
				return null;
			}

			if ($field.is(':checkbox')) {
				return $field.is(':checked');
			}

			return $field.val();
		}

		function updatePreview() {
			if (!$toast.length) {
				return;
			}

			var shadowKey = val('shadow');

			$toast.css({
				background: val('bg_color') || '#111827',
				color: val('text_color') || '#f9fafb',
				borderStyle: 'solid',
				borderColor: val('border_color') || 'transparent',
				borderWidth: parseInt(val('border_width'), 10) || 0,
				borderRadius: (parseInt(val('border_radius'), 10) || 0) + 'px',
				boxShadow: shadows[shadowKey] || 'none',
				fontSize: (parseInt(val('font_size'), 10) || 14) + 'px',
				maxWidth: Math.min(parseInt(val('max_width'), 10) || 380, 290) + 'px'
			});

			$toast.find('a').css('color', val('link_color') || '#93c5fd');
			$toast.find('button').toggle(!!val('show_close'));
			$toast.find('small').toggle(!!val('show_time'));

			// Image shape. Non-circular images take the deeper inset and the
			// capped toast radius, exactly as the front end does.
			var imageStyle = val('image_style');
			var radius = imageStyle === 'circle' ? '50%' : (imageStyle === 'rounded' ? '8px' : '2px');

			$toast.find('img, .hpsp-preview-initial').css('border-radius', radius);

			// Mirror the front end: a pill gets a uniform 16px inset and a
			// vertically centred close button, flat corners get 12px with a
			// 16px left inset and a capped radius.
			if (imageStyle === 'circle') {
				$toast.css('padding', '16px');
			} else {
				$toast.css('padding', '12px 12px 12px 16px');
				$toast.css('border-radius', Math.min(parseInt(val('border_radius'), 10) || 0, 16) + 'px');
			}

			// Approximate the selected position inside the stage.
			var position = String(val('position') || 'bottom-left');
			var vertical = position.indexOf('top') === 0 ? 'top' : 'bottom';
			var horizontal = position.indexOf('right') !== -1 ? 'right' : (position.indexOf('center') !== -1 ? 'center' : 'left');

			$toast.css({
				marginTop: vertical === 'top' ? 0 : 'auto',
				marginBottom: vertical === 'top' ? 'auto' : 0,
				marginLeft: horizontal === 'left' ? 0 : 'auto',
				marginRight: horizontal === 'right' ? 0 : 'auto'
			});

			if (horizontal === 'center') {
				$toast.css({ marginLeft: 'auto', marginRight: 'auto' });
			}
		}

		function replayAnimation() {
			if (!$toast.length) {
				return;
			}

			var animation = String(val('animation') || 'slide');
			var speed = parseInt(val('animation_speed'), 10) || 300;

			$toast.css('transition-duration', speed + 'ms');
			$toast.removeClass('anim-slide anim-pop');

			if (animation !== 'fade') {
				$toast.addClass('anim-' + animation);
			}

			$toast.addClass('hpsp-preview-hidden');

			window.setTimeout(function () {
				$toast.removeClass('hpsp-preview-hidden');
			}, Math.max(speed, 150) + 50);
		}

		// --------------------------------------------------------------
		// Per-event icon choice: only shown while "Icon on a coloured
		// tile" is the selected image source.
		// --------------------------------------------------------------

		$(document).on('change', 'select[data-hpsp-image]', function () {
			var type = $(this).attr('data-hpsp-image');

			$('[data-hpsp-icon-for="' + type + '"]').prop('hidden', $(this).val() !== 'icon');
		});

		// --------------------------------------------------------------
		// Icon picker: the toggle button opens a grid of radio options,
		// choosing one mirrors its glyph and name onto the button.
		// --------------------------------------------------------------

		function closeIconPickers(except) {
			$('.hpsp-icon-picker').each(function () {
				if (this !== except) {
					$(this).find('.hpsp-icon-grid').prop('hidden', true);
					$(this).find('.hpsp-icon-toggle').attr('aria-expanded', 'false');
				}
			});
		}

		$(document).on('click', '.hpsp-icon-toggle', function (event) {
			event.preventDefault();

			var $picker = $(this).closest('.hpsp-icon-picker');
			var $grid = $picker.find('.hpsp-icon-grid');
			var open = $grid.prop('hidden');

			closeIconPickers($picker.get(0));
			$grid.prop('hidden', !open);
			$(this).attr('aria-expanded', open ? 'true' : 'false');
		});

		$(document).on('change', '.hpsp-icon-option input', function () {
			var $option = $(this).closest('.hpsp-icon-option');
			var $picker = $(this).closest('.hpsp-icon-picker');
			var glyphClass = $option.find('.hpsp-glyph').attr('class');

			$picker.find('.hpsp-icon-toggle .hpsp-glyph').attr('class', glyphClass);
			$picker.find('.hpsp-icon-toggle__name').text($option.find('.hpsp-icon-option__name').text());
		});

		// Close on mouse selection only, so keyboard arrow browsing (which
		// also fires change) keeps the grid open until Escape or Tab away.
		$(document).on('click', '.hpsp-icon-option', function () {
			var $picker = $(this).closest('.hpsp-icon-picker');

			$picker.find('.hpsp-icon-grid').prop('hidden', true);
			$picker.find('.hpsp-icon-toggle').attr('aria-expanded', 'false');
		});

		$(document).on('click', function (event) {
			if (!$(event.target).closest('.hpsp-icon-picker').length) {
				closeIconPickers(null);
			}
		});

		$(document).on('keydown', function (event) {
			if (event.key === 'Escape') {
				closeIconPickers(null);
			}
		});

		// --------------------------------------------------------------
		// Media pickers (fallback avatar, anonymous image): one WordPress
		// library frame per field, shared handlers keyed by data attribute.
		// --------------------------------------------------------------

		var mediaFrames = {};
		var i18n = (window.HPSPAdmin && window.HPSPAdmin.i18n) || {};

		$(document).on('click', '.hpsp-media-choose', function (event) {
			event.preventDefault();

			if (!window.wp || !wp.media) {
				return;
			}

			var key = $(this).attr('data-hpsp-media');
			// The modal title comes from the field itself: a shared string
			// headed the Anonymous image picker "Choose fallback avatar",
			// which is the worst possible place to confuse the two.
			var title = $(this).attr('data-hpsp-media-title') || i18n.chooseImage || '';

			if (!mediaFrames[key]) {
				mediaFrames[key] = wp.media({
					title: title,
					library: { type: 'image' },
					multiple: false,
					button: { text: i18n.useImage || '' }
				});

				mediaFrames[key].on('select', function () {
					var attachment = mediaFrames[key].state().get('selection').first().toJSON();
					var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;

					$('#hpsp-' + key).val(attachment.id);
					$('.hpsp-media-preview[data-hpsp-media="' + key + '"]').attr('src', url).show();
					$('.hpsp-media-remove[data-hpsp-media="' + key + '"]').show();
				});
			}

			mediaFrames[key].open();
		});

		$(document).on('click', '.hpsp-media-remove', function (event) {
			event.preventDefault();

			var key = $(this).attr('data-hpsp-media');

			$('#hpsp-' + key).val(0);
			$('.hpsp-media-preview[data-hpsp-media="' + key + '"]').attr('src', '').hide();
			$(this).hide();
		});

		// The preview avatar is a remote Gravatar: if it cannot load, fall back
		// to the initial badge exactly as the front-end toast does, rather
		// than leaving an empty circle (spotted in the live preview).
		$('#hpsp-preview-img').on('error', function () {
			var $img = $(this);
			var $badge = $('<span class="hpsp-preview-initial"></span>').text($img.attr('data-initial') || '');

			$img.replaceWith($badge);
			updatePreview();
		});

		// Colour pickers.
		$('.hpsp-color').wpColorPicker({
			change: function () {
				window.setTimeout(updatePreview, 0);
			},
			clear: function () {
				window.setTimeout(updatePreview, 0);
			}
		});

		// Any design field change refreshes the preview.
		$(document).on('change input', '[data-hpsp]', updatePreview);

		$('#hpsp-preview-replay').on('click', replayAnimation);

		// Changing animation settings replays automatically.
		$('[data-hpsp="animation"], [data-hpsp="animation_speed"]').on('change', replayAnimation);

		if ($stage.length) {
			updatePreview();
		}

		// The floating Save control and the back-to-top button. The nav is
		// server-rendered on this screen, so the injected one stands down on
		// its own marker guard, which is the convention working as intended
		// rather than a special case.
		addSettingsChrome();
	});
})(jQuery);
