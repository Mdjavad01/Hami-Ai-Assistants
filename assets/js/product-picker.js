/**
 * Hami AI Assistant — product picker (product-content screen only).
 *
 * Loaded exclusively on its own screen by Haa_Module_Product_Content::assets(),
 * so a store that never uses this module downloads nothing here.
 *
 * The server returns a flat parent => child category list and one page of
 * product rows; the tree, the checkbox states and the selection set are built
 * here. Neither a full catalogue nor a full selection ever crosses the wire in
 * one payload or lands in the DOM.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-haai-prodpicker]');
	var cfg = window.haaiPicker || {};
	if (!root || !cfg.ajax) return;

	var i18n = cfg.i18n || {};
	var CAP = parseInt(cfg.cap || 500, 10);

	function qs(sel, ctx) { return (ctx || root).querySelector(sel); }
	function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
	function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* ------------------------------------------------------------------
	 * State
	 * ---------------------------------------------------------------- */

	var terms = [];                 // flat rows: {id,parent,name,count}
	var kidsOf = {};                // parent id => [rows]
	var nodeById = {};              // id => {row, children, input, branch}
	var chosenCats = {};            // term id => 1  (the filter set)
	var selected = {};              // product id => title (the batch set)
	var page = 1;
	var per = parseInt(cfg.per || 20, 10);
	var pages = 1;
	var treeQuery = '';
	var loading = false;

	var treeBox = qs('[data-haai-tree]');
	var treeQ = qs('[data-haai-tree-q]');
	var childToggle = qs('[data-haai-tree-children]');
	var body = qs('[data-haai-pbody]');
	var allBox = qs('[data-haai-pall]');
	var selBar = qs('[data-haai-selbar]');
	var selCount = qs('[data-haai-selcount]');
	var pager = qs('[data-haai-ppager]');
	var counter = qs('[data-haai-pcounter]');
	var info = qs('[data-haai-pinfo]');
	var batchIds = qs('[data-haai-batch-ids]');
	var batchSubmit = qs('[data-haai-batch-submit]');
	var batchEstimate = qs('[data-haai-batch-estimate]');
	var withImage = qs('input[name="with_image"]');
	var openId = root.getAttribute('data-open') || '';

	/* ------------------------------------------------------------------
	 * Transport
	 * ---------------------------------------------------------------- */

	function filterParams() {
		var p = new URLSearchParams();
		p.set('nonce', cfg.nonce || '');
		p.set('q', (qs('[data-haai-pq]') || { value: '' }).value.trim());
		p.set('page', page);
		p.set('per', per);
		p.set('children', childToggle && childToggle.checked ? '1' : '0');
		Object.keys(chosenCats).forEach(function (id) { p.append('cat[]', id); });
		qsa('[data-haai-pstatus] option:checked').forEach(function (o) { p.append('status[]', o.value); });
		var t = qs('[data-haai-ptype]');
		if (t && t.value) p.set('type', t.value);
		var s = qs('[data-haai-pstock]');
		if (s && s.value) p.set('stock', s.value);
		qsa('[data-haai-pcontent] option:checked').forEach(function (o) { p.append('content[]', o.value); });
		return p;
	}

	function call(action, extra) {
		var p = filterParams();
		if (extra) {
			Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); });
		}
		p.set('action', action);
		return fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=utf-8' },
			body: p.toString()
		}).then(function (r) { return r.json(); });
	}

	function busy(flag) {
		loading = !!flag;
		if (pager) pager.classList.toggle('is-busy', loading);
	}

	/* ------------------------------------------------------------------
	 * Category tree
	 * ---------------------------------------------------------------- */

	function indexTerms(rows) {
		terms = rows || [];
		kidsOf = {};
		nodeById = {};
		terms.forEach(function (row) {
			(kidsOf[row.parent] = kidsOf[row.parent] || []).push(row);
			nodeById[row.id] = { row: row, children: [], input: null, branch: null };
		});
	}

	/** Does this node, or anything under it, match the tree search? */
	function matches(node) {
		if (!treeQuery) return true;
		if (node.row.name.toLowerCase().indexOf(treeQuery) !== -1) return true;
		return (kidsOf[node.row.id] || []).some(function (kid) { return matches(nodeById[kid.id]); });
	}

	function descendantsOf(node, acc) {
		acc = acc || [];
		(kidsOf[node.row.id] || []).forEach(function (kid) {
			acc.push(kid.id);
			descendantsOf(nodeById[kid.id], acc);
		});
		return acc;
	}

	/** Leaves under a node — the count basis for a parent's checkbox state. */
	function selectableLeaves(node) {
		var kids = kidsOf[node.row.id] || [];
		if (!kids.length) return [node.row.id];
		var out = [];
		kids.forEach(function (kid) { out = out.concat(selectableLeaves(nodeById[kid.id])); });
		return out;
	}

	function renderTree() {
		if (!treeBox) return;
		treeBox.innerHTML = '';
		var roots = (kidsOf[0] || []).slice();
		if (!roots.length) {
			var none = document.createElement('p');
			none.className = 'description';
			none.textContent = i18n.noCat || '—';
			treeBox.appendChild(none);
			return;
		}
		var ul = document.createElement('ul');
		ul.setAttribute('role', 'tree');
		roots.forEach(function (row) { ul.appendChild(treeItem(nodeById[row.id], 0)); });
		treeBox.appendChild(ul);
		refreshTreeChecks();
	}

	function treeItem(node, depth) {
		var li = document.createElement('li');
		li.className = 'haai-tree__item';
		li.setAttribute('role', 'treeitem');

		var kids = (kidsOf[node.row.id] || []).filter(function (row) { return matches(nodeById[row.id]); });
		// matches() already folds in "this name hits", so one test is enough.
		if (treeQuery && !matches(node)) { li.hidden = true; }

		var row = document.createElement('span');
		row.className = 'haai-tree__row';
		row.style.setProperty('--haai-tree-depth', depth);

		var twist = document.createElement('button');
		twist.type = 'button';
		twist.className = 'haai-tree__twist' + (kids.length ? '' : ' is-leaf');
		twist.setAttribute('aria-label', kids.length ? 'باز/بسته' : '');
		twist.setAttribute('aria-expanded', kids.length ? 'true' : 'false');
		twist.tabIndex = kids.length ? 0 : -1;

		var box = document.createElement('input');
		box.type = 'checkbox';
		box.className = 'haai-tree__check';
		box.value = node.row.id;
		box.id = 'haai-cat-' + node.row.id;
		node.input = box;
		box.setAttribute('aria-label', node.row.name);

		var label = document.createElement('label');
		label.className = 'haai-tree__label';
		label.setAttribute('for', box.id);
		label.innerHTML = esc(node.row.name) + ' <em class="haai-tree__count">' + esc(node.row.count) + '</em>';

		row.appendChild(twist);
		row.appendChild(box);
		row.appendChild(label);
		li.appendChild(row);

		var branch = null;
		if (kids.length) {
			branch = document.createElement('ul');
			branch.className = 'haai-tree__branch';
			branch.setAttribute('role', 'group');
			kids.forEach(function (kid) { branch.appendChild(treeItem(nodeById[kid.id], depth + 1)); });
			li.appendChild(branch);
		}
		node.branch = branch;

		on(twist, 'click', function () {
			if (!branch) return;
			branch.hidden = !branch.hidden;
			twist.setAttribute('aria-expanded', branch.hidden ? 'false' : 'true');
			twist.classList.toggle('is-closed', branch.hidden);
		});

		on(box, 'change', function () {
			// "Include sub-categories" decides whether ticking a parent means
			// only that node or its whole subtree.
			var ids = (childToggle && childToggle.checked) ? [node.row.id].concat(descendantsOf(node)) : [node.row.id];
			ids.forEach(function (id) {
				if (box.checked) chosenCats[id] = 1; else delete chosenCats[id];
			});
			refreshTreeChecks();
			go(1);
		});

		return li;
	}

	/** Checked / indeterminate / empty for every parent, from the leaves. */
	function refreshTreeChecks() {
		terms.forEach(function (row) {
			var node = nodeById[row.id];
			if (!node || !node.input) return;
			var leaves = selectableLeaves(node);
			var hit = leaves.filter(function (id) { return chosenCats[id]; }).length;
			node.input.checked = hit > 0 && hit === leaves.length;
			node.input.indeterminate = hit > 0 && hit < leaves.length;
		});
	}

	var treeTimer = null;
	on(treeQ, 'input', function () {
		if (treeTimer) clearTimeout(treeTimer);
		treeTimer = setTimeout(function () {
			treeQuery = treeQ.value.trim().toLowerCase();
			renderTree();
		}, 200);
	});
	on(childToggle, 'change', function () { refreshTreeChecks(); go(1); });

	/* ------------------------------------------------------------------
	 * Rows
	 * ---------------------------------------------------------------- */

	/**
	 * Completeness badge (P7): the word stays short, the detail moves to the
	 * tooltip and an aria-label, so a narrow column never wraps into noise and
	 * a screen reader still hears exactly what is missing.
	 */
	function contentBadge(row) {
		var missing = [];
		if (!row.has_excerpt) missing.push('توضیح کوتاه');
		if (!row.has_content) missing.push('توضیح کامل');
		if (!row.has_thumb) missing.push('تصویر');
		if (!missing.length) return '<span class="haai-badge haai-badge--on">کامل</span>';
		var list = missing.join('، ');
		return '<span class="haai-badge haai-badge--warn" title="' + esc('کمبود: ' + list) + '"'
			+ ' aria-label="' + esc('ناقص — کمبود: ' + list) + '">ناقص</span>';
	}

	function renderRows(d) {
		body.innerHTML = '';
		var rows = (d && d.rows) || [];

		if (!rows.length) {
			var tr = document.createElement('tr');
			tr.innerHTML = '<td colspan="7"><div class="haai-empty"><span class="haai-empty__title">'
				+ esc(i18n.empty || '—') + '</span></div></td>';
			body.appendChild(tr);
		}

	rows.forEach(function (row) {
		var tr = document.createElement('tr');
		tr.innerHTML =
			'<td class="haai-col-check"><input type="checkbox" class="haai-row-check" value="' + esc(row.id) +
				'" data-title="' + esc(row.title) + '" aria-label="' + esc(row.title) + '"></td>' +
			'<td>' + (row.thumb
				? '<img src="' + esc(row.thumb) + '" alt="" width="44" height="44" loading="lazy" class="haai-prod-thumb">'
				: '<span class="haai-prod-thumb is-none" aria-hidden="true"></span>') + '</td>' +
			'<td class="haai-prod-title"><a href="' + esc(cfg.screen) + '&product=' + encodeURIComponent(row.id) + '" title="' + esc(row.title) + '">' + esc(row.title) + '</a>' +
				'<span class="haai-prod-meta">#' + esc(row.id) + (row.type ? ' · ' + esc(row.type) : '') + (row.stock ? ' · ' + esc(row.stock) : '') + '</span>' +
				(row.desc ? '<span class="haai-prod-desc">' + esc(row.desc) + '</span>' : '') + '</td>' +
			'<td>' + (row.sku ? esc(row.sku) : '<span class="haai-dim">—</span>') + '</td>' +
			'<td>' + (row.price ? esc(row.price) : '<span class="haai-dim">—</span>') + '</td>' +
			'<td>' + ((row.cats && row.cats.length) ? esc(row.cats.join('، ')) : '<span class="haai-dim">—</span>') + '</td>' +
			'<td>' + contentBadge(row) + '</td>';
		body.appendChild(tr);
		if (openId && String(row.id) === String(openId)) tr.classList.add('is-open');
		if (selected[String(row.id)]) tr.classList.add('is-selected');
	});

		page = (d && d.page) || page;
		pages = Math.max(1, (d && d.pages) || 1);
		if (counter) counter.textContent = page + ' / ' + pages;
		if (info) info.textContent = ((d && d.total) || 0) + ' محصول';
		if (pager) pager.hidden = false;
		qsa('.haai-row-check').forEach(function (box) {
			box.checked = !!selected[box.value];
			on(box, 'change', function () { toggle(box.value, box.getAttribute('data-title'), box.checked); });
		});
		syncHead();
		syncSelection();
		refreshDdCount();
	}

	function syncHead() {
		if (!allBox) return;
		var boxes = qsa('.haai-row-check');
		var hit = boxes.filter(function (b) { return selected[b.value]; }).length;
		allBox.checked = boxes.length > 0 && hit === boxes.length;
		allBox.indeterminate = hit > 0 && hit < boxes.length;
	}

	allBox && on(allBox, 'change', function () {
		qsa('.haai-row-check').forEach(function (b) {
			b.checked = allBox.checked;
			toggle(b.value, b.getAttribute('data-title'), allBox.checked);
		});
	});

	/** Keeping the title with the id is what lets the summary line be honest. */
	function toggle(id, title, onOff) {
		if (onOff) selected[id] = title || ('#' + id);
		else delete selected[id];
		syncSelection();
	}

	function clearSelection() {
		selected = {};
		qsa('.haai-row-check').forEach(function (b) { b.checked = false; });
		syncHead();
		syncSelection();
	}

	function countSelected() { return Object.keys(selected).length; }

	function syncSelection() {
		var n = countSelected();
		if (selBar) selBar.hidden = n === 0;
		if (selCount) selCount.textContent = n + ' ' + (i18n.selected || 'selected');
		if (batchIds) batchIds.value = n ? JSON.stringify(Object.keys(selected).map(Number)) : '';
		if (batchSubmit) batchSubmit.disabled = n === 0;
		var img = withImage && withImage.checked && cfg.images;
		if (batchEstimate) {
			var base = batchEstimate.getAttribute('data-base') || '';
			batchEstimate.textContent = base + (n ? ' — ' + (i18n.estimate || '') + ': ' + n * (img ? 4 : 3) : '');
		}
	}
	withImage && on(withImage, 'change', syncSelection);

	on(qs('[data-haai-selclear]'), 'click', clearSelection);

	on(qs('[data-haai-selall]'), 'click', function () {
		if (!window.confirm(i18n.confirmAll || 'Select all?')) return;
		call('haai_pc_product_ids', { page: 1, per: CAP })
			.then(function (d) {
				if (!d || !d.ok) return;
				(d.ids || []).forEach(function (id) { selected[String(id)] = '#' + id; });
				if (d.truncated && i18n.truncated) window.alert(i18n.truncated);
				qsa('.haai-row-check').forEach(function (b) { b.checked = !!selected[b.value]; });
				syncHead();
				syncSelection();
			})
			.catch(function () {});
	});

	/* ------------------------------------------------------------------
	 * Driving the list
	 * ---------------------------------------------------------------- */

	/**
	 * The table is replaced wholesale on every filter change, so a checkbox
	 * that had keyboard focus would simply vanish (P8). The row is identified
	 * by its product id, which survives re-rendering, so focus can be put back
	 * where the admin was working.
	 */
	function focusKey() {
		var a = document.activeElement;
		if (!a || !body || !body.contains(a)) return '';
		if (a.classList && a.classList.contains('haai-row-check')) return 'row:' + a.value;
		return '';
	}

	function restoreFocus(key) {
		if (!key || key.indexOf('row:') !== 0 || !body) return;
		var el = body.querySelector('.haai-row-check[value="' + key.slice(4) + '"]');
		if (el) el.focus();
	}

	function go(toPage) {
		if (loading) return;
		page = toPage || page;
		var keep = focusKey();
		busy(true);
		if (body) body.innerHTML = '<tr><td colspan="7"><span class="description">' + esc(i18n.loading || '…') + '</span></td></tr>';
		call('haai_pc_products')
			.then(function (d) {
				busy(false);
				if (!d || !d.ok) {
					if (body) body.innerHTML = '<tr><td colspan="7">' + esc(d && d.error ? d.error : (i18n.fail || '—')) + '</td></tr>';
					return;
				}
				renderRows(d);
				restoreFocus(keep);
			})
			.catch(function () { busy(false); if (body) body.innerHTML = '<tr><td colspan="7">' + esc(i18n.fail || '—') + '</td></tr>'; });
	}

	var goTimer = null;
	on(qs('[data-haai-pq]'), 'input', function () {
		if (goTimer) clearTimeout(goTimer);
		goTimer = setTimeout(function () { go(1); }, 350);
	});
	on(qs('[data-haai-pgo]'), 'click', function () { go(1); });
	qsa('[data-haai-pstatus],[data-haai-ptype],[data-haai-pstock],[data-haai-pcontent]').forEach(function (el) {
		on(el, 'change', function () { refreshDdCount(); go(1); });
	});
	on(qs('[data-haai-pprev]'), 'click', function () { if (page > 1) { page--; go(); } });
	on(qs('[data-haai-pnext]'), 'click', function () { if (page < pages) { page++; go(); } });

	on(qs('[data-haai-pper]'), 'change', function () {
		per = parseInt(this.value, 10) || per;
		go(1);
	});

	on(qs('[data-haai-pclear]'), 'click', function () {
		qsa('[data-haai-pstatus] option,[data-haai-pcontent] option').forEach(function (o) { o.selected = false; });
		var t = qs('[data-haai-ptype]');
		var s = qs('[data-haai-pstock]');
		if (t) t.selectedIndex = 0;
		if (s) s.selectedIndex = 0;
		refreshDdCount();
		go(1);
	});

	/* ------------------------------------------------------------------
	 * Filter dropdown: one button, one panel, an outside click closes it
	 * ---------------------------------------------------------------- */

	var dd = qs('[data-haai-dd]');
	var ddBtn = qs('[data-haai-dd-btn]');
	var ddPanel = qs('[data-haai-dd-panel]');
	var ddCount = qs('[data-haai-dd-count]');

	function ddClose() {
		if (!dd) return;
		dd.classList.remove('is-open');
		if (ddPanel) ddPanel.hidden = true;
		if (ddBtn) ddBtn.setAttribute('aria-expanded', 'false');
	}

	on(ddBtn, 'click', function (e) {
		e.stopPropagation();
		if (!dd) return;
		var open = dd.classList.toggle('is-open');
		if (ddPanel) ddPanel.hidden = !open;
		if (ddBtn) ddBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
	});
	on(document, 'click', function (e) {
		if (dd && dd.classList.contains('is-open') && !dd.contains(e.target)) ddClose();
	});
	on(document, 'keydown', function (e) {
		if (e.key === 'Escape' || e.keyCode === 27) ddClose();
	});

	function refreshDdCount() {
		if (!ddCount) return;
		var n = 0;
		qsa('[data-haai-pstatus] option:checked').forEach(function () { n++; });
		qsa('[data-haai-pcontent] option:checked').forEach(function () { n++; });
		var t = qs('[data-haai-ptype]');
		var s = qs('[data-haai-pstock]');
		if (t && t.value) n++;
		if (s && s.value) n++;
		ddCount.hidden = n === 0;
		ddCount.textContent = n;
	}

	/* ------------------------------------------------------------------
	 * Preview editor: tabs, counters, FAQ rows, live view
	 *
	 * The editor is the apply form itself, a sibling of the picker, so it is
	 * looked up inside the shared screen wrapper — never with a bare
	 * document-wide query. Everything below hangs off that one root.
	 * ---------------------------------------------------------------- */

	var pvScope = root.closest ? root.closest('.haai-wrap') : null;
	var pvEdit  = qs('[data-haai-pvedit]', pvScope || document.body);
	if (pvEdit) {
		var pvTabs = qsa('[data-haai-tab]', pvEdit);
		var pvPanes = qsa('[data-haai-pane]', pvEdit);
		var pvSrc = qs('[data-haai-pvsrc]', pvEdit);
		var pvBody = qs('[data-haai-pvbody]', pvEdit);
		var pvFaq = qs('[data-haai-pvfaq]', pvEdit);
		var pvFaqBody = qs('[data-haai-pvfaqbody]', pvEdit);

		/**
		 * Rebuild the FAQ part of the preview from the editable rows, with the
		 * same markup Haa_Seo::faq_html() appends at apply time — so what the
		 * admin sees here is literally what lands on the product (P6). Text is
		 * written with textContent, never interpolated into HTML.
		 */
		function paintFaq() {
			if (!pvFaqBody) return;
			var pairs = [];
			qsa('[data-haai-faqrow]', pvEdit).forEach(function (row) {
				var q = row.querySelector('input[name^="faq["]');
				var a = row.querySelector('textarea[name^="faq["]');
				var qt = q ? q.value.trim() : '';
				var at = a ? a.value.trim() : '';
				if (qt !== '' && at !== '') pairs.push([qt, at]);
			});
			pvFaqBody.textContent = '';
			if (pvFaq) pvFaq.hidden = pairs.length === 0; // nothing will be appended
			if (!pairs.length) return;
			var h2 = document.createElement('h2');
			h2.textContent = i18n.faqHeading || 'سؤالات متداول';
			pvFaqBody.appendChild(h2);
			pairs.forEach(function (p) {
				var h3 = document.createElement('h3');
				h3.textContent = p[0];
				var para = document.createElement('p');
				para.textContent = p[1];
				pvFaqBody.appendChild(h3);
				pvFaqBody.appendChild(para);
			});
		}

		function paintView() {
			if (pvBody && pvSrc) pvBody.innerHTML = pvSrc.value;
			paintFaq();
		}

		/** One visible tab, one tab stop: arrows/Home/End move between them (P9). */
		function selectTab(btn, moveFocus) {
			if (!btn) return;
			pvTabs.forEach(function (b) {
				var isIt = b === btn;
				b.classList.toggle('is-active', isIt);
				b.setAttribute('aria-selected', isIt ? 'true' : 'false');
				b.tabIndex = isIt ? 0 : -1;
			});
			var want = btn.getAttribute('data-haai-tab');
			pvPanes.forEach(function (p) {
				var isIt = p.getAttribute('data-haai-pane') === want;
				p.hidden = !isIt;
				p.classList.toggle('is-active', isIt);
			});
			if (moveFocus) btn.focus();
			if (want === 'view') paintView();
		}

		pvTabs.forEach(function (btn, i) {
			on(btn, 'click', function () { selectTab(btn, false); });
			on(btn, 'keydown', function (e) {
				var k = e.key || '';
				var to = null;
				if (k === 'ArrowRight' || k === 'Right') to = (i + 1) % pvTabs.length;
				else if (k === 'ArrowLeft' || k === 'Left') to = (i - 1 + pvTabs.length) % pvTabs.length;
				else if (k === 'Home') to = 0;
				else if (k === 'End') to = pvTabs.length - 1;
				if (to === null) return;
				e.preventDefault();
				selectTab(pvTabs[to], true);
			});
		});

		var paintTimer = null;
		on(pvSrc, 'input', function () {
			if (paintTimer) clearTimeout(paintTimer);
			paintTimer = setTimeout(function () { paintView(); refreshCounts(); }, 300);
		});

		function refreshCounts() {
			qsa('[data-haai-count]', pvEdit).forEach(function (el) {
				var out = qs('[data-haai-countfor="' + el.id + '"]', pvEdit);
				if (!out) return;
				var target = parseInt(el.getAttribute('data-haai-count'), 10) || 0;
				var n = el.value.length;
				out.textContent = n + ' / ' + target;
				out.classList.toggle('is-over', n > target);
			});
			var bc = qs('[data-haai-bodycount]', pvEdit);
			if (bc && pvSrc) {
				// Tags and entities are not words: strip them, then count.
				var text = pvSrc.value.replace(/<[^>]*>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ');
				var words = (text.trim().match(/\S+/g) || []).length;
				bc.textContent = words + ' واژه';
			}
		}
		qsa('[data-haai-count]', pvEdit).forEach(function (el) { on(el, 'input', refreshCounts); });
		refreshCounts();

		var faqBox = qs('[data-haai-faq]', pvEdit);
		var faqTpl = qs('[data-haai-faqtpl]', pvEdit);
		var pvForm = pvEdit.tagName === 'FORM' ? pvEdit : (pvEdit.closest ? pvEdit.closest('form') : null);

		/** Row order is the payload: faq[0][q]/[0][a], faq[1][…], … */
		function faqRenumber() {
			if (!faqBox) return;
			qsa('[data-haai-faqrow]', faqBox).forEach(function (row, i) {
				var q = row.querySelector('input[name^="faq["]');
				var a = row.querySelector('textarea[name^="faq["]');
				if (q) q.name = 'faq[' + i + '][q]';
				if (a) a.name = 'faq[' + i + '][a]';
			});
		}

		on(qs('[data-haai-faqadd]', pvEdit), 'click', function () {
			if (!faqBox || !faqTpl || !faqTpl.content) return;
			faqBox.appendChild(faqTpl.content.firstElementChild.cloneNode(true));
			faqRenumber();
			paintFaq();
			markDirty();
		});
		faqBox && on(faqBox, 'click', function (e) {
			var del = e.target && e.target.closest ? e.target.closest('[data-haai-faqdel]') : null;
			if (!del) return;
			var row = del.closest('[data-haai-faqrow]');
			if (row && row.parentNode) {
				row.parentNode.removeChild(row);
				faqRenumber();
				paintFaq();
				markDirty();
			}
		});
		// And once more right before the browser serialises the form.
		on(pvForm, 'submit', faqRenumber);
		faqRenumber();

		/* ------------------------------------------------------------------
		 * Open review notes
		 *
		 * A preview that still carries notes must not be applied blindly, so
		 * the tick is what unlocks the button. This is convenience only:
		 * handle_apply refuses the request without the field, so a disabled
		 * button is never the actual guard.
		 * ---------------------------------------------------------------- */
		var notesAck = qs('[data-haai-notesack]', pvEdit);
		if (notesAck) {
			var applyBtn = pvForm ? qs('.haai-actions--apply button[type="submit"]', pvForm) : null;
			var syncAck = function () {
				if (!applyBtn) return;
				applyBtn.disabled = !notesAck.checked;
			};
			on(notesAck, 'change', syncAck);
			syncAck();
		}

		/* ------------------------------------------------------------------
		 * Unsaved work (P2)
		 *
		 * Every preview field is editable, so the page has to protect the
		 * admin's typing — but only while there IS unsaved typing. The guard
		 * is armed by the first real edit, disarmed the moment the apply form
		 * is accepted, and «generate again» / «delete preview» ask first.
		 * ---------------------------------------------------------------- */
		var dirty = false;
		function markDirty() { dirty = true; }
		function clearDirty() { dirty = false; }

		on(pvEdit, 'input', function (e) {
			markDirty();
			// Keep the preview honest while a question or answer is typed.
			if (e.target && e.target.name && e.target.name.indexOf('faq[') === 0) paintFaq();
		});
		on(pvEdit, 'change', markDirty);
		on(pvForm, 'submit', clearDirty);

		on(window, 'beforeunload', function (e) {
			if (!dirty) return;
			e.preventDefault();
			e.returnValue = i18n.leavePage || '';
			return e.returnValue;
		});

		qsa('[data-haai-dirty-confirm]').forEach(function (f) {
			on(f, 'submit', function (e) {
				if (e.defaultPrevented) return;
				if (dirty && !window.confirm(i18n.discard || '')) e.preventDefault();
			});
		});
	}

	// Batch submit guards itself: an empty selection is caught before the post.
	on(qs('[data-haai-batch-form]'), 'submit', function (e) {
		if (!countSelected()) { e.preventDefault(); return; }
		var n = countSelected();
		var img = withImage && withImage.checked && cfg.images;
		var total = n * (img ? 4 : 3);
		if (!window.confirm((i18n.estimate || '') + ': ' + n + ' → ' + total + ' requests')) e.preventDefault();
	});

	/* ------------------------------------------------------------------
	 * One click, one run (P3)
	 *
	 * A generation takes tens of seconds, so a second click, an impatient
	 * Enter key or a double submit would pay the model twice for the same
	 * answer. The button is switched off the moment the form leaves and
	 * re-labelled so the wait is legible; the server keeps a short
	 * per-product lock as the second line of defence.
	 * ---------------------------------------------------------------- */

	/** Find the form a hidden `action` input belongs to. */
	function formByAction(value) {
		var inp = document.querySelector('input[name="action"][value="' + value + '"]');
		if (!inp) return null;
		return inp.form || (inp.closest ? inp.closest('form') : null);
	}

	function guardSubmit(form, busyText) {
		if (!form) return;
		on(form, 'submit', function (e) {
			if (e.defaultPrevented) return;
			if (form.getAttribute('data-haai-submitting') === '1') { e.preventDefault(); return; }
			var btn = form.querySelector('button[type="submit"], input[type="submit"]');
			if (!btn) return;
			form.setAttribute('data-haai-submitting', '1');
			btn.setAttribute('disabled', 'disabled');
			btn.setAttribute('data-haai-label', btn.textContent);
			btn.textContent = busyText;
			/* A navigation that never happened (blocked by an extension, a dead
			   proxy) must not leave the screen frozen for good. */
			setTimeout(function () {
				form.removeAttribute('data-haai-submitting');
				btn.removeAttribute('disabled');
				btn.textContent = btn.getAttribute('data-haai-label') || btn.textContent;
			}, 180000);
		});
	}

	guardSubmit(formByAction('haai_pc_generate'), i18n.generating || 'در حال ساخت…');
	guardSubmit(formByAction('haai_pc_apply'), i18n.applying || 'در حال اعمال…');

	/* ------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	if (batchEstimate) batchEstimate.setAttribute('data-base', batchEstimate.textContent);

	fetch(cfg.ajax + '?action=haai_pc_categories&nonce=' + encodeURIComponent(cfg.nonce || ''), { credentials: 'same-origin' })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			indexTerms(d && d.ok ? d.terms : []);
			renderTree();
		})
		.catch(function () { renderTree(); });

	go(1);
})();
