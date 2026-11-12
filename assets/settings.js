/* Shop Email Campaigns: settings page. */
(function ($) {
	'use strict';
	$(function () {
		$('.whm-color').wpColorPicker();
		var frame = null;
		$('#whm-logo-pick').on('click', function () {
			if (!frame) {
				frame = wp.media({ title: 'Choose a logo', library: { type: ['image/png', 'image/jpeg', 'image/gif'] }, multiple: false, button: { text: 'Use this logo' } });
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					$('#whm-logo_id').val(a.id);
					$('.whm-logo-preview').html('<img src="' + a.url + '" alt="">');
				});
			}
			frame.open();
		});
		$('#whm-logo-clear').on('click', function () {
			$('#whm-logo_id').val('0');
			$('.whm-logo-preview').empty();
		});
	});
})(jQuery);
