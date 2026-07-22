/**
 * Social Proof for HivePress — admin settings screen.
 *
 * Tab switching, colour pickers and the live design preview.
 */
(function ($) {
	'use strict';

	var shadows = (window.HPSPAdmin && window.HPSPAdmin.shadows) || {};

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

			// Image shape.
			var imageStyle = val('image_style');
			var radius = imageStyle === 'circle' ? '50%' : (imageStyle === 'rounded' ? '8px' : '2px');

			$toast.find('img, .hpsp-preview-initial').css('border-radius', radius);

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
	});
})(jQuery);
