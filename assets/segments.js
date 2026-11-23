/* Shop Email Campaigns: segment builder. */
(function ($) {
	'use strict';
	var S = window.WHMSEG;
	if (!S) { return; }
	var def = S.def || { match: 'all', rules: [] };
	var productNames = {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function typeOptions(sel) {
		return Object.keys(S.types).map(function (k) {
			return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(S.types[k].label) + '</option>';
		}).join('');
	}

	function valueInput(r) {
		var t = S.types[r.type].input;
		switch (t) {
			case 'period':
				return '<input type="number" min="1" class="small-text" data-f="value" value="' + esc(r.value || 3) + '" aria-label="Amount"> ' +
					'<select data-f="unit" aria-label="Unit">' + ['days', 'weeks', 'months', 'years'].map(function (u) {
						return '<option value="' + u + '"' + ((r.unit || 'months') === u ? ' selected' : '') + '>' + u + '</option>';
					}).join('') + '</select>';
			case 'number':
				return '<input type="number" min="0" class="small-text" data-f="value" value="' + esc(r.value == null ? 2 : r.value) + '" aria-label="Number of orders">';
			case 'money':
				return esc(S.currency) + ' <input type="number" min="0" step="0.01" class="small-text" data-f="value" value="' + esc(r.value || 50) + '" aria-label="Amount">';
			case 'status':
				return '<select data-f="value" aria-label="Status">' + [['subscribed', 'Subscribed'], ['customer', 'Customer (soft opt-in)']].map(function (o) {
					return '<option value="' + o[0] + '"' + (r.value === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
				}).join('') + '</select>';
			case 'categories':
				return '<select multiple data-f="value" class="whm-multi" aria-label="Categories">' + S.cats.map(function (c) {
					return '<option value="' + c.id + '"' + ((r.value || []).indexOf(c.id) > -1 ? ' selected' : '') + '>' + esc(c.title) + '</option>';
				}).join('') + '</select>';
			case 'campaigns':
				var camps = S.campaigns || [];
				if (!camps.length) { return '<em>No campaigns have been sent yet.</em>'; }
				return '<select multiple data-f="value" class="whm-multi" aria-label="Campaigns">' + camps.map(function (c) {
					return '<option value="' + c.id + '"' + ((r.value || []).indexOf(c.id) > -1 ? ' selected' : '') + '>' + esc(c.title) + '</option>';
				}).join('') + '</select>';
			case 'coupon':
				return '<input type="text" class="regular-text" data-f="value" value="' + esc(r.value || '') + '" placeholder="e.g. WELCOME10" aria-label="Coupon code">';
			case 'products':
				var chosen = (r.value || []).map(function (id) {
					return '<span class="whm-chip" data-id="' + id + '">' + esc(productNames[id] || ('#' + id)) + ' <button type="button" class="button-link whm-chip-x" aria-label="Remove">×</button></span>';
				}).join(' ');
				return '<span class="whm-chips">' + chosen + '</span> <input type="search" class="whm-prod-q" placeholder="Search products…" aria-label="Search products"><span class="whm-prod-res"></span>';
		}
		return '';
	}

	function render() {
		var html = '<p class="whm-match">Include people who match ' +
			'<select id="whm-match" aria-label="Match"><option value="all"' + (def.match === 'all' ? ' selected' : '') + '>all</option><option value="any"' + (def.match === 'any' ? ' selected' : '') + '>any</option></select>' +
			' of these filters:</p><ol class="whm-rules">';
		def.rules.forEach(function (r, i) {
			html += '<li class="whm-rule" data-i="' + i + '"><select class="whm-rule-type" aria-label="Filter">' + typeOptions(r.type) + '</select> ' + valueInput(r) +
				' <button type="button" class="button-link whm-danger whm-rule-del">Remove</button></li>';
		});
		html += '</ol>';
		if (!def.rules.length) {
			html += '<p class="whm-hint">No filters: everyone you\'re allowed to email.</p>';
		}
		html += '<p><button type="button" class="button" id="whm-rule-add">Add filter</button></p>';
		$('#whm-seg-rules').html(html);
		resolveProducts();
		refresh();
	}

	function resolveProducts() {
		var ids = [];
		def.rules.forEach(function (r) {
			if (S.types[r.type].input === 'products') {
				(r.value || []).forEach(function (id) { if (!productNames[id]) { ids.push(id); } });
			}
		});
		if (!ids.length) { return; }
		$.get(S.ajax, { action: 'whm_products', nonce: S.nonce, ids: ids.join(',') }, function (r) {
			if (!r || !r.success) { return; }
			r.data.forEach(function (p) {
				productNames[p.id] = p.title;
				$('.whm-chip[data-id="' + p.id + '"]').contents().first()[0].nodeValue = p.title + ' ';
			});
		});
	}

	var timer = null;
	function refresh() {
		$('#whm-seg-def').val(JSON.stringify(def));
		clearTimeout(timer);
		timer = setTimeout(function () {
			$('#whm-seg-count').text('Counting…');
			$.post(S.ajax, { action: 'whm_segment_preview', nonce: S.nonce, def: JSON.stringify(def) }, function (r) {
				if (!r || !r.success) { $('#whm-seg-count').text('Could not count.'); return; }
				var d = r.data;
				var txt = '<p><strong>' + d.count + '</strong> ' + (d.count === 1 ? 'person' : 'people') + ' you can email.</p>';
				if (d.held) {
					txt += '<p class="whm-hint">' + d.held + ' more match but haven\'t opted in' + (d.softOptIn ? ' (or have unsubscribed)' : '. Turn on soft opt-in in Settings if you can email past customers') + '.</p>';
				}
				$('#whm-seg-count').html(txt);
				var rows = d.rows.map(function (x) {
					return '<tr><td>' + esc(x.email) + '</td><td>' + esc(x.name) + '</td><td>' + esc(x.status) + '</td><td>' + x.orders + '</td><td>' + esc(x.last) + '</td><td>' + esc(x.spent) + '</td></tr>';
				}).join('');
				$('#whm-seg-preview tbody').html(rows || '<tr><td colspan="6">Nobody matches yet.</td></tr>');
			});
		}, 350);
	}

	function idx(el) { return parseInt($(el).closest('.whm-rule').data('i'), 10); }

	$(function () {
		var $w = $('#whm-seg-rules');
		$w.on('change', '#whm-match', function () { def.match = this.value; refresh(); });
		$w.on('change', '.whm-rule-type', function () {
			def.rules[idx(this)] = { type: this.value };
			var t = S.types[this.value].input;
			var r = def.rules[idx(this)];
			if (t === 'period') { r.value = 3; r.unit = 'months'; }
			if (t === 'number') { r.value = 2; }
			if (t === 'money') { r.value = 50; }
			if (t === 'status') { r.value = 'subscribed'; }
			if (t === 'products' || t === 'categories' || t === 'campaigns') { r.value = []; }
			if (t === 'coupon') { r.value = ''; }
			render();
		});
		$w.on('input change', '[data-f]', function () {
			var r = def.rules[idx(this)], f = $(this).data('f');
			if (this.multiple) { r[f] = $(this).val().map(Number); }
			else if (this.type === 'number') { r[f] = parseFloat(this.value) || 0; }
			else { r[f] = this.value; }
			refresh();
		});
		$w.on('click', '.whm-rule-del', function () { def.rules.splice(idx(this), 1); render(); });
		$w.on('click', '#whm-rule-add', function () { def.rules.push({ type: 'order_count_min', value: 2 }); render(); });

		var q = null;
		$w.on('input', '.whm-prod-q', function () {
			var el = this, $res = $(this).siblings('.whm-prod-res');
			clearTimeout(q);
			q = setTimeout(function () {
				if (el.value.length < 2) { $res.empty(); return; }
				$.get(S.ajax, { action: 'whm_products', nonce: S.nonce, q: el.value }, function (r) {
					if (!r || !r.success) { return; }
					$res.html(r.data.map(function (p) {
						productNames[p.id] = p.title;
						return ' <button type="button" class="button button-small whm-prod-pick" data-id="' + p.id + '">' + esc(p.title) + '</button>';
					}).join(''));
				});
			}, 300);
		});
		$w.on('click', '.whm-prod-pick', function () {
			var r = def.rules[idx(this)], id = $(this).data('id');
			r.value = r.value || [];
			if (r.value.indexOf(id) === -1) { r.value.push(id); }
			render();
		});
		$w.on('click', '.whm-chip-x', function () {
			var r = def.rules[idx(this)], id = $(this).parent().data('id');
			r.value = (r.value || []).filter(function (v) { return v !== id; });
			render();
		});
		$('#whm-seg-form').on('submit', function () { $('#whm-seg-def').val(JSON.stringify(def)); });
		render();
	});
})(jQuery);
