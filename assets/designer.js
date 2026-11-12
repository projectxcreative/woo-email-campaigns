/* Shop Email Campaigns: email designer. */
(function ($) {
	'use strict';

	var D = window.WHM;
	if (!D) { return; }

	var state = {
		id: D.id || 0,
		title: D.title || '',
		subject: D.subject || '',
		preheader: D.preheader || '',
		segment: D.segment || 0,
		blocks: D.blocks || []
	};
	var dirty = false;
	var isCampaign = D.mode === 'campaign';
	var ro = !!D.readonly;

	var BLOCKS = {
		heading: { label: 'Heading', make: function () { return { type: 'heading', text: 'New heading', size: 'medium', align: 'left' }; } },
		text: { label: 'Text', make: function () { return { type: 'text', html: '<p>Write something lovely.</p>', align: 'left' }; } },
		button: { label: 'Button', make: function () { return { type: 'button', label: 'Shop now', url: '{shop_url}', align: 'center', style: 'brand' }; } },
		image: { label: 'Image', make: function () { return { type: 'image', id: 0, url: '', alt: '', link: '', width: 100, align: 'center' }; } },
		product: { label: 'Product', make: function () { return { type: 'product', product_id: 0, button: 'Shop now', show_price: true }; } },
		coupon: { label: 'Discount code', make: function () { return { type: 'coupon', title: 'Use code', code: 'WELCOME10', note: '' }; } },
		divider: { label: 'Divider', make: function () { return { type: 'divider' }; } },
		spacer: { label: 'Spacer', make: function () { return { type: 'spacer', height: 24 }; } },
		signoff: { label: 'Sign-off', make: function () { return { type: 'signoff', text: D.signoff }; } }
	};

	var TAGS = [
		['{first_name}', 'First name ("there" if unknown)'],
		['{shop_url}', 'Shop page link'],
		['{site_url}', 'Home page link'],
		['{account_url}', 'My Account link']
	];

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function uid() { return 'b' + Math.random().toString(36).slice(2, 9); }

	function markDirty() {
		dirty = true;
		$('#whm-save-state').text('Unsaved changes');
		schedulePreview();
	}

	/* ---------------------------------------------------------------- Layout */

	function shell() {
		var badge = isCampaign ? '<span class="whm-badge whm-badge-' + esc(D.status) + '">' + esc(D.status.charAt(0).toUpperCase() + D.status.slice(1)) + '</span>' : '<span class="whm-badge">Template</span>';
		var html = '' +
			'<div class="whm-d-top">' +
			'  <label class="screen-reader-text" for="whm-title">Name</label>' +
			'  <input id="whm-title" type="text" class="whm-d-title" placeholder="' + (isCampaign ? 'Campaign name (only you see this)' : 'Template name') + '" value="' + esc(state.title) + '">' +
			'  ' + badge +
			'  <span id="whm-save-state" class="whm-save-state" aria-live="polite"></span>' +
			'  <span class="whm-d-top-actions">' +
			(ro ? '' : '<button type="button" class="button button-primary" id="whm-save">' + (isCampaign ? 'Save draft' : 'Save template') + '</button> ') +
			(isCampaign ? '<button type="button" class="button" id="whm-save-tpl">Save as template</button> ' : '') +
			'<a class="button-link" href="' + esc(isCampaign ? D.urls.campaigns : D.urls.templates) + '">Back</a>' +
			'  </span>' +
			'</div>' +
			(ro ? '<div class="notice notice-info inline"><p>This campaign has been sent, so it can\'t be edited. Duplicate it from the Campaigns page to reuse it.</p></div>' : '') +
			'<div class="whm-d-main">' +
			'  <div class="whm-d-left">' +
			'    <section class="whm-panel"><h2>Email details</h2>' +
			'      <p><label for="whm-subject"><strong>Subject line</strong></label><input id="whm-subject" type="text" class="widefat" maxlength="200" value="' + esc(state.subject) + '"><span class="whm-hint"><span id="whm-subject-len"></span> You can use {first_name}.</span></p>' +
			'      <p><label for="whm-preheader"><strong>Preview text</strong></label><input id="whm-preheader" type="text" class="widefat" maxlength="200" value="' + esc(state.preheader) + '"><span class="whm-hint">Shown after the subject in most inboxes.</span></p>' +
			'    </section>' +
			(isCampaign ? audiencePanel() : '') +
			'    <section class="whm-panel"><h2>Content</h2><div id="whm-blocks"></div>' + (ro ? '' : addMenu('end')) + '</section>' +
			(isCampaign ? sendPanel() : testPanel()) +
			'  </div>' +
			'  <div class="whm-d-right"><div class="whm-preview-wrap">' +
			'    <div class="whm-preview-bar"><div class="whm-preview-subject"><strong id="whm-pv-subject"></strong><span id="whm-pv-pre"></span></div>' +
			'      <div class="whm-seg-toggle" role="group" aria-label="Preview size"><button type="button" class="button whm-pv-size is-active" data-w="100%">Desktop</button><button type="button" class="button whm-pv-size" data-w="390px">Mobile</button></div></div>' +
			'    <div class="whm-preview-frame"><iframe id="whm-preview" title="Email preview" sandbox="allow-same-origin allow-popups"></iframe></div>' +
			'    <p class="whm-hint">Preview uses the sample name "Alex". Fonts show in Apple Mail; Gmail and Outlook use Helvetica/Arial.</p>' +
			'  </div></div>' +
			'</div>';
		$('#whm-designer').html(html);
		if (ro) {
			$('#whm-designer').find('input, select, textarea').not('.whm-pv-size').prop('disabled', true);
		}
		renderBlocks();
		updateSubjectLen();
		if (isCampaign) { loadAudience(); }
		preview();
	}

	function audiencePanel() {
		var opts = '<option value="0">Choose a segment…</option>';
		D.segments.forEach(function (s) {
			opts += '<option value="' + s.id + '"' + (s.id === state.segment ? ' selected' : '') + '>' + esc(s.title) + '</option>';
		});
		return '<section class="whm-panel"><h2>Who gets it</h2>' +
			'<p><label for="whm-segment" class="screen-reader-text">Segment</label><select id="whm-segment" class="widefat">' + opts + '</select></p>' +
			'<div id="whm-audience" class="whm-audience" aria-live="polite"></div>' +
			'<p class="whm-hint"><a href="' + esc(D.urls.segments) + '" target="_blank">Create or edit segments</a> (combine filters like "bought in the last 3 months" and "2+ orders").</p></section>';
	}

	function testPanel() {
		return '<section class="whm-panel"><h2>Send a test</h2>' +
			'<p><label for="whm-test-to"><strong>Send a test to</strong></label><input id="whm-test-to" type="text" class="widefat" value="' + esc(D.testEmail) + '"><span class="whm-hint">Separate up to 5 addresses with commas.</span></p>' +
			'<p><button type="button" class="button" id="whm-test">Send test email</button></p><div id="whm-test-result" aria-live="polite"></div></section>';
	}

	function sendPanel() {
		var html = testPanel();
		if (ro) { return html; }
		html += '<section class="whm-panel whm-send-panel"><h2>Send</h2>';
		if (!D.liveSending) {
			html += '<p class="whm-warn">Live sending is switched off, so this can\'t go to customers yet. Turn it on in <a href="' + esc(D.urls.settings) + '">Settings</a> when you\'re ready.</p>';
		}
		html += '<fieldset><legend class="screen-reader-text">When</legend>' +
			'<label><input type="radio" name="whm-when" value="now"' + (D.sendAt ? '' : ' checked') + '> Send now</label><br>' +
			'<label><input type="radio" name="whm-when" value="later"' + (D.sendAt ? ' checked' : '') + '> Schedule for</label> ' +
			'<label for="whm-send-at" class="screen-reader-text">Send date and time</label><input type="datetime-local" id="whm-send-at" value="' + esc(D.sendAt) + '"></fieldset>' +
			'<p><button type="button" class="button button-primary button-hero" id="whm-send"' + (D.liveSending ? '' : ' disabled') + '>Review and send</button></p>' +
			'<div id="whm-send-result" aria-live="polite"></div></section>';
		return html;
	}

	function addMenu(pos) {
		var html = '<div class="whm-add" data-pos="' + pos + '"><span>Add:</span>';
		Object.keys(BLOCKS).forEach(function (k) {
			html += '<button type="button" class="button button-small whm-add-btn" data-type="' + k + '">' + BLOCKS[k].label + '</button>';
		});
		return html + '</div>';
	}

	/* ---------------------------------------------------------------- Blocks */

	function alignSelect(b, def) {
		var v = b.align || def || 'left';
		return '<label>Align <select data-f="align">' +
			['left', 'center', 'right'].map(function (a) { return '<option value="' + a + '"' + (v === a ? ' selected' : '') + '>' + a.charAt(0).toUpperCase() + a.slice(1) + '</option>'; }).join('') +
			'</select></label>';
	}

	function blockBody(b) {
		switch (b.type) {
			case 'heading':
				return '<input type="text" class="widefat" data-f="text" value="' + esc(b.text) + '" aria-label="Heading text">' +
					'<div class="whm-row"><label>Size <select data-f="size">' +
					[['large', 'Large'], ['medium', 'Medium'], ['small', 'Small']].map(function (o) { return '<option value="' + o[0] + '"' + (b.size === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
					'</select></label>' + alignSelect(b) + '</div>';
			case 'text':
				return '<div class="whm-rte-bar" role="toolbar" aria-label="Text formatting">' +
					'<button type="button" class="button button-small" data-cmd="bold" title="Bold"><strong>B</strong></button>' +
					'<button type="button" class="button button-small" data-cmd="italic" title="Italic"><em>I</em></button>' +
					'<button type="button" class="button button-small" data-cmd="link" title="Link">Link</button>' +
					'<button type="button" class="button button-small" data-cmd="unlink" title="Remove link">Unlink</button>' +
					'<button type="button" class="button button-small" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>' +
					'<button type="button" class="button button-small" data-cmd="insertOrderedList" title="Numbered list">1. List</button>' +
					'<select class="whm-tag-insert" aria-label="Insert personalisation"><option value="">Insert…</option>' +
					TAGS.map(function (t) { return '<option value="' + t[0] + '">' + esc(t[1]) + '</option>'; }).join('') + '</select>' +
					'</div><div class="whm-rte" contenteditable="' + (ro ? 'false' : 'true') + '" role="textbox" aria-multiline="true" aria-label="Text">' + b.html + '</div>' +
					'<div class="whm-row">' + alignSelect(b) + '</div>';
			case 'button':
				return '<div class="whm-row"><label>Label <input type="text" data-f="label" value="' + esc(b.label) + '"></label>' +
					'<label class="whm-grow">Link <input type="text" data-f="url" value="' + esc(b.url) + '" placeholder="https://… or {shop_url}"></label></div>' +
					'<div class="whm-row">' + alignSelect(b, 'center') + '<label>Style <select data-f="style">' +
					[['brand', 'Brand default'], ['outline', 'Outlined'], ['filled', 'Filled']].map(function (o) { return '<option value="' + o[0] + '"' + (b.style === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
					'</select></label></div>';
			case 'image':
				return '<div class="whm-row"><button type="button" class="button whm-pick-image">' + (b.url ? 'Change image' : 'Choose image') + '</button>' +
					(b.url ? '<img class="whm-thumb" src="' + esc(b.url) + '" alt="">' : '') + '</div>' +
					'<div class="whm-row"><label class="whm-grow">Alt text <input type="text" data-f="alt" value="' + esc(b.alt) + '" placeholder="Describe the image"></label></div>' +
					'<div class="whm-row"><label class="whm-grow">Link (optional) <input type="text" data-f="link" value="' + esc(b.link) + '"></label></div>' +
					'<div class="whm-row"><label>Width <input type="range" min="20" max="100" step="5" data-f="width" value="' + (b.width || 100) + '"> <span class="whm-w">' + (b.width || 100) + '%</span></label>' + alignSelect(b, 'center') + '</div>';
			case 'product':
				return '<div class="whm-row"><label class="whm-grow">Find a product <input type="search" class="whm-product-search" placeholder="Type to search…"></label></div>' +
					'<div class="whm-product-results"></div>' +
					'<p class="whm-product-chosen">' + (b.product_id ? 'Chosen: <span class="whm-pname" data-id="' + b.product_id + '">#' + b.product_id + '</span>' : 'No product chosen yet.') + '</p>' +
					'<div class="whm-row"><label>Button <input type="text" data-f="button" value="' + esc(b.button) + '"></label>' +
					'<label><input type="checkbox" data-f="show_price"' + (b.show_price ? ' checked' : '') + '> Show price</label></div>';
			case 'coupon':
				return '<div class="whm-row"><label>Title <input type="text" data-f="title" value="' + esc(b.title) + '"></label>' +
					'<label>Code <input type="text" data-f="code" value="' + esc(b.code) + '" class="whm-code"></label></div>' +
					'<div class="whm-row"><label class="whm-grow">Small print <input type="text" data-f="note" value="' + esc(b.note) + '" placeholder="e.g. Valid until 30 November"></label></div>' +
					'<p class="whm-hint">Create the code in WooCommerce → Marketing → Coupons first.</p>';
			case 'spacer':
				return '<label>Height <input type="number" min="4" max="120" data-f="height" value="' + esc(b.height) + '"> px</label>';
			case 'signoff':
				return '<textarea class="widefat" rows="2" data-f="text" aria-label="Sign-off">' + esc(b.text) + '</textarea>';
			case 'divider':
				return '<p class="whm-hint">A thin line to separate sections.</p>';
		}
		return '';
	}

	function renderBlocks() {
		var $wrap = $('#whm-blocks').empty();
		state.blocks.forEach(function (b, i) {
			b._k = b._k || uid();
			var tools = ro ? '' :
				'<span class="whm-b-tools">' +
				'<button type="button" class="button-link whm-b-up" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
				'<button type="button" class="button-link whm-b-down" aria-label="Move down"' + (i === state.blocks.length - 1 ? ' disabled' : '') + '>↓</button>' +
				'<button type="button" class="button-link whm-b-dup" aria-label="Duplicate">Copy</button>' +
				'<button type="button" class="button-link whm-b-del whm-danger" aria-label="Remove">Remove</button></span>';
			var $b = $('<div class="whm-block" data-i="' + i + '"><div class="whm-b-head"><span class="whm-b-type">' + BLOCKS[b.type].label + '</span>' + tools + '</div><div class="whm-b-body">' + blockBody(b) + '</div></div>');
			$wrap.append($b);
			if (!ro && i < state.blocks.length - 1) {
				$wrap.append('<div class="whm-insert"><button type="button" class="button-link whm-insert-toggle" aria-expanded="false">+ Insert block here</button>' + addMenu(i + 1).replace('whm-add"', 'whm-add whm-add-inline"') + '</div>');
			}
		});
		if (ro) { $wrap.find('input, select, textarea, button').prop('disabled', true); }
		// Resolve product names.
		var ids = [];
		$wrap.find('.whm-pname').each(function () { ids.push($(this).data('id')); });
		if (ids.length) {
			$.get(D.ajax, { action: 'whm_products', nonce: D.nonce, ids: ids.join(',') }, function (r) {
				if (r && r.success) {
					r.data.forEach(function (p) { $wrap.find('.whm-pname[data-id="' + p.id + '"]').text(p.title); });
				}
			});
		}
	}

	function blockIndex(el) { return parseInt($(el).closest('.whm-block').data('i'), 10); }

	/* ---------------------------------------------------------------- Preview */

	var pvTimer = null;
	function schedulePreview() {
		clearTimeout(pvTimer);
		pvTimer = setTimeout(preview, 450);
	}

	function payload(extra) {
		var blocks = state.blocks.map(function (b) {
			var c = $.extend({}, b);
			delete c._k;
			return c;
		});
		return $.extend({
			nonce: D.nonce,
			mode: D.mode,
			id: state.id,
			title: state.title,
			subject: state.subject,
			preheader: state.preheader,
			segment: state.segment,
			blocks: JSON.stringify(blocks)
		}, extra || {});
	}

	function preview() {
		$.post(D.ajax, payload({ action: 'whm_preview' }), function (r) {
			if (!r || !r.success) { return; }
			var frame = document.getElementById('whm-preview');
			if (frame) {
				frame.srcdoc = r.data.html.replace('<head>', '<head><base target="_blank">');
			}
			$('#whm-pv-subject').text(r.data.subject || '(no subject yet)');
			$('#whm-pv-pre').text(state.preheader ? ' – ' + state.preheader : '');
		});
	}

	/* ---------------------------------------------------------------- Audience */

	function loadAudience() {
		var $a = $('#whm-audience');
		if (!state.segment) { $a.html('<p class="whm-hint">Pick who this campaign goes to.</p>'); return; }
		$a.html('<p class="whm-hint">Counting…</p>');
		$.post(D.ajax, { action: 'whm_audience', nonce: D.nonce, segment: state.segment }, function (r) {
			if (!r || !r.success) { $a.html(''); return; }
			var d = r.data;
			var html = '<p class="whm-count"><strong>' + d.count + '</strong> ' + (d.count === 1 ? 'person' : 'people') + ' will get this email.</p>' +
				'<p class="whm-hint">' + esc(d.describe) + '</p>';
			if (d.held) {
				html += '<p class="whm-hint">' + d.held + ' more match but haven\'t agreed to marketing emails, so they\'re left out.</p>';
			}
			$a.html(html);
			$a.data('count', d.count);
		});
	}

	/* ---------------------------------------------------------------- Actions */

	function save(extra, done) {
		var $btn = $('#whm-save').prop('disabled', true);
		$('#whm-save-state').text('Saving…');
		$.post(D.ajax, payload($.extend({ action: 'whm_save' }, extra || {})), function (r) {
			$btn.prop('disabled', false);
			if (!r || !r.success) {
				$('#whm-save-state').text((r && r.data && r.data.message) || 'Could not save.');
				return;
			}
			if (r.data.id) {
				var wasNew = !state.id;
				state.id = r.data.id;
				dirty = false;
				$('#whm-save-state').text('Saved');
				if (wasNew && window.history && r.data.editUrl) { window.history.replaceState(null, '', r.data.editUrl); }
			} else {
				$('#whm-save-state').text(r.data.message);
			}
			if (done) { done(r.data); }
		}).fail(function () {
			$btn.prop('disabled', false);
			$('#whm-save-state').text('Could not save. Check your connection.');
		});
	}

	function bind() {
		var $d = $('#whm-designer');

		$d.on('input', '#whm-title', function () { state.title = this.value; dirty = true; });
		$d.on('input', '#whm-subject', function () { state.subject = this.value; updateSubjectLen(); markDirty(); });
		$d.on('input', '#whm-preheader', function () { state.preheader = this.value; markDirty(); });
		$d.on('change', '#whm-segment', function () { state.segment = parseInt(this.value, 10) || 0; dirty = true; loadAudience(); });

		// Block field edits.
		$d.on('input change', '.whm-block [data-f]', function () {
			var i = blockIndex(this), f = $(this).data('f'), b = state.blocks[i];
			if (this.type === 'checkbox') { b[f] = this.checked; }
			else if (this.type === 'range' || this.type === 'number') { b[f] = parseInt(this.value, 10) || 0; }
			else { b[f] = this.value; }
			if (f === 'width') { $(this).siblings('.whm-w').text(this.value + '%'); }
			markDirty();
		});

		// Rich text.
		$d.on('input', '.whm-rte', function () {
			state.blocks[blockIndex(this)].html = this.innerHTML;
			markDirty();
		});
		$d.on('mousedown', '.whm-rte-bar button', function (e) { e.preventDefault(); });
		$d.on('click', '.whm-rte-bar button', function () {
			var cmd = $(this).data('cmd');
			var $rte = $(this).closest('.whm-b-body').find('.whm-rte');
			$rte.trigger('focus');
			if (cmd === 'link') {
				var url = window.prompt('Link address (or {shop_url}):', 'https://');
				if (!url) { return; }
				document.execCommand('createLink', false, url);
			} else {
				document.execCommand(cmd, false, null);
			}
			$rte.trigger('input');
		});
		$d.on('change', '.whm-tag-insert', function () {
			var tag = this.value;
			this.value = '';
			if (!tag) { return; }
			var $rte = $(this).closest('.whm-b-body').find('.whm-rte');
			$rte.trigger('focus');
			document.execCommand('insertText', false, tag);
			$rte.trigger('input');
		});
		// Paste as plain text so Word/Docs styles don't come along.
		$d.on('paste', '.whm-rte', function (e) {
			e.preventDefault();
			var text = (e.originalEvent.clipboardData || window.clipboardData).getData('text/plain');
			document.execCommand('insertText', false, text);
		});

		$d.on('click', '.whm-insert-toggle', function () {
			var $w = $(this).closest('.whm-insert').toggleClass('is-open');
			$(this).attr('aria-expanded', $w.hasClass('is-open') ? 'true' : 'false');
		});

		// Add / move / duplicate / delete blocks.
		$d.on('click', '.whm-add-btn', function () {
			var pos = $(this).closest('.whm-add').data('pos');
			var b = BLOCKS[$(this).data('type')].make();
			if (pos === 'end') { state.blocks.push(b); } else { state.blocks.splice(parseInt(pos, 10), 0, b); }
			renderBlocks();
			markDirty();
		});
		$d.on('click', '.whm-b-up, .whm-b-down', function () {
			var i = blockIndex(this), j = $(this).hasClass('whm-b-up') ? i - 1 : i + 1;
			if (j < 0 || j >= state.blocks.length) { return; }
			var t = state.blocks[i]; state.blocks[i] = state.blocks[j]; state.blocks[j] = t;
			renderBlocks();
			markDirty();
		});
		$d.on('click', '.whm-b-dup', function () {
			var i = blockIndex(this), c = $.extend({}, state.blocks[i]);
			delete c._k;
			state.blocks.splice(i + 1, 0, c);
			renderBlocks();
			markDirty();
		});
		$d.on('click', '.whm-b-del', function () {
			state.blocks.splice(blockIndex(this), 1);
			renderBlocks();
			markDirty();
		});

		// Image picker.
		$d.on('click', '.whm-pick-image', function () {
			var i = blockIndex(this);
			var frame = wp.media({ title: 'Choose an image', library: { type: 'image' }, multiple: false, button: { text: 'Use this image' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				var size = (a.sizes && (a.sizes.large || a.sizes.full)) || a;
				state.blocks[i].id = a.id;
				state.blocks[i].url = size.url;
				if (!state.blocks[i].alt) { state.blocks[i].alt = a.alt || ''; }
				renderBlocks();
				markDirty();
			});
			frame.open();
		});

		// Product search.
		var searchTimer = null;
		$d.on('input', '.whm-product-search', function () {
			var el = this, $res = $(this).closest('.whm-b-body').find('.whm-product-results');
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				if (el.value.length < 2) { $res.empty(); return; }
				$.get(D.ajax, { action: 'whm_products', nonce: D.nonce, q: el.value }, function (r) {
					if (!r || !r.success) { return; }
					$res.html(r.data.length ? r.data.map(function (p) {
						return '<button type="button" class="button button-small whm-product-pick" data-id="' + p.id + '">' + esc(p.title) + '</button>';
					}).join(' ') : '<span class="whm-hint">No products found.</span>');
				});
			}, 300);
		});
		$d.on('click', '.whm-product-pick', function () {
			state.blocks[blockIndex(this)].product_id = $(this).data('id');
			renderBlocks();
			markDirty();
		});

		// Preview size.
		$d.on('click', '.whm-pv-size', function () {
			$('.whm-pv-size').removeClass('is-active');
			$(this).addClass('is-active');
			$('#whm-preview').css('width', $(this).data('w'));
		});

		// Save.
		$d.on('click', '#whm-save', function () { save(); });
		$d.on('click', '#whm-save-tpl', function () {
			var name = window.prompt('Name for the new template:', state.title || state.subject || 'My template');
			if (!name) { return; }
			save({ as_template: 1, template_name: name }, function (d) {
				$('#whm-save-state').text(d.message || 'Saved as a template.');
			});
		});

		// Test.
		$d.on('click', '#whm-test', function () {
			var $r = $('#whm-test-result').html('<p class="whm-hint">Sending…</p>');
			var $btn = $(this).prop('disabled', true);
			$.post(D.ajax, payload({ action: 'whm_test', emails: $('#whm-test-to').val() }), function (r) {
				$btn.prop('disabled', false);
				var ok = r && r.success;
				$r.html('<p class="' + (ok ? 'whm-ok' : 'whm-error') + '">' + esc((r && r.data && r.data.message) || 'Something went wrong.') + '</p>');
			}).fail(function () { $btn.prop('disabled', false); $r.html('<p class="whm-error">Could not send the test.</p>'); });
		});

		// Send / schedule.
		$d.on('click', '#whm-send', function () {
			var later = $('input[name="whm-when"]:checked').val() === 'later';
			var when = later ? $('#whm-send-at').val() : '';
			var count = $('#whm-audience').data('count');
			var $r = $('#whm-send-result');
			if (!state.subject.trim()) { $r.html('<p class="whm-error">Add a subject line first.</p>'); return; }
			if (!state.segment) { $r.html('<p class="whm-error">Choose who to send to.</p>'); return; }
			if (later && !when) { $r.html('<p class="whm-error">Pick a date and time.</p>'); return; }
			var msg = 'Send "' + state.subject + '" to ' + (count == null ? 'this segment' : count + ' ' + (count === 1 ? 'person' : 'people')) + (later ? ' at ' + when.replace('T', ' ') : ' now') + '?\n\nThis can\'t be undone once emails have gone out.';
			if (!window.confirm(msg)) { return; }
			var $btn = $(this).prop('disabled', true);
			$r.html('<p class="whm-hint">Starting…</p>');
			$.post(D.ajax, payload({ action: 'whm_send', send_at: when }), function (r) {
				if (r && r.success) {
					dirty = false;
					window.location.href = r.data.redirect;
					return;
				}
				$btn.prop('disabled', false);
				if (r && r.data && r.data.id) { state.id = r.data.id; }
				$r.html('<p class="whm-error">' + esc((r && r.data && r.data.message) || 'Could not start sending.') + '</p>');
			}).fail(function () { $btn.prop('disabled', false); $r.html('<p class="whm-error">Could not start sending.</p>'); });
		});

		window.addEventListener('beforeunload', function (e) {
			if (dirty && !ro) { e.preventDefault(); e.returnValue = ''; }
		});
	}

	function updateSubjectLen() {
		var n = state.subject.length;
		$('#whm-subject-len').text(n + ' characters' + (n > 60 ? ' (may be cut off on phones).' : '.'));
	}

	$(function () {
		shell();
		bind();
	});
})(jQuery);
