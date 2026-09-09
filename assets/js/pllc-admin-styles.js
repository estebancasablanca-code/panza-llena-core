(function ($) {
	'use strict';

	function paintSwatch($control, color) {
		var $swatch = $control.find('.pllc-color-swatch');
		if (color) {
			$swatch.css('background', color);
		} else {
			$swatch.css('background', '');
		}
	}

	function setStatus($control, text, color) {
		$control.find('.pllc-color-status-text').text(text);
		$control.find('.pllc-color-value').attr('data-resolved', color || '');
		paintSwatch($control, color);
	}

	function syncSource($control) {
		var $source = $control.find('.pllc-color-source');
		var $hidden = $control.find('.pllc-color-value');
		var $customWrap = $control.find('.pllc-color-custom-wrap');
		var $custom = $control.find('.pllc-color');
		var selected = $source.val();
		var $option = $source.find(':selected');
		var color = $option.attr('data-color') || '';

		if ('custom' === selected) {
			$customWrap.show();
			color = $custom.val() || $hidden.attr('data-fallback') || '';
			$hidden.val(color);
			setStatus($control, color ? 'Personalizado · ' + color.toUpperCase() : 'Personalizado', color);
			return;
		}

		$customWrap.hide();
		if ('inherit' === selected) {
			$hidden.val('');
			setStatus($control, 'Heredado de Elementor o del tema', color);
			return;
		}

		$hidden.val(selected);
		setStatus($control, $option.text(), color);
	}

	function previewValue(key) {
		var $field = $('.pllc-color-value[data-key="' + key + '"]');
		return $field.attr('data-resolved') || $field.attr('data-fallback') || '';
	}

	function updateButtonPreview() {
		function value(key) {
			return $('[name="styles[' + key + ']"]').val();
		}

		var common = {
			borderWidth: value('button_border_width') + 'px',
			borderStyle: 'solid',
			borderRadius: value('button_radius') + 'px',
			minHeight: value('button_height') + 'px',
			fontSize: value('button_font_size') + 'px',
			fontWeight: value('button_weight')
		};
		$('.pllc-preview button').css(common);
		$('.pllc-preview-primary').css({backgroundColor: previewValue('primary_bg'), color: previewValue('primary_text'), borderColor: previewValue('primary_border')});
		$('.pllc-preview-action').css({backgroundColor: previewValue('action_bg'), color: previewValue('action_text'), borderColor: previewValue('action_border')});
		$('.pllc-preview-disabled').css({backgroundColor: previewValue('disabled_bg'), color: previewValue('disabled_text'), borderColor: previewValue('disabled_border')});
	}

	$(function () {
		$('.pllc-color-control').each(function () {
			var $control = $(this);
			var $custom = $control.find('.pllc-color');
			$custom.wpColorPicker({
				change: function (event, ui) {
					if ('custom' === $control.find('.pllc-color-source').val()) {
						$control.find('.pllc-color-value').val(ui.color.toString());
						setStatus($control, 'Personalizado · ' + ui.color.toString().toUpperCase(), ui.color.toString());
						updateButtonPreview();
					}
				},
				clear: function () {
					if ('custom' === $control.find('.pllc-color-source').val()) {
						$control.find('.pllc-color-value').val('');
						setStatus($control, 'Personalizado', '');
					}
				}
			});
			syncSource($control);
		});

		$(document).on('change', '.pllc-color-source', function () {
			syncSource($(this).closest('.pllc-color-control'));
			updateButtonPreview();
		});
		$(document).on('input change', '.pllc-style-form input[type="number"]', updateButtonPreview);
		updateButtonPreview();
	});
})(jQuery);
