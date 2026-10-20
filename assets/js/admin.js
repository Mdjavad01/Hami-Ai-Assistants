/**
 * Hami AI Assistant — admin behaviour.
 * Media picker, connection test, model fetch, secret masking, search preview,
 * page pickers, dark mode for "auto".
 */
(function () {
        'use strict';

        // "خودکار" arrives as body.haai-theme-auto from PHP; only the browser knows
        // the OS preference, so the dark class is applied (and kept in sync) here
        // instead of repeating the whole token set in a media query.
        if (window.matchMedia) {
                var dark = window.matchMedia('(prefers-color-scheme: dark)');
                var syncDark = function () {
                        var b = document.body;
                        if (!b) return;
                        b.classList.toggle('haai-theme-dark', b.classList.contains('haai-theme-auto') && dark.matches);
                };
                syncDark();
                if (dark.addEventListener) dark.addEventListener('change', syncDark);
                else if (dark.addListener) dark.addListener(syncDark);
        }

        var C = window.haaiAdmin || {};
        var A = C.ajax || '';

        function on(el, ev, fn) {
                if (el) el.addEventListener(ev, fn);
        }

        function qs(sel, ctx) {
                return (ctx || document).querySelector(sel);
        }

        function qsa(sel, ctx) {
                return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
        }

        function busy(btn, on) {
                if (!btn) return;
                btn.disabled = on;
                btn.textContent = on ? (C.i18n && C.i18n.busy) || '…' : btn.getAttribute('data-label') || btn.textContent;
        }

        function post(action, data) {
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', C.nonce || '');
                Object.keys(data || {}).forEach(function (k) {
                        if (data[k] instanceof FileList) {
                                Array.prototype.forEach.call(data[k], function (f) { body.append(k, f); });
                        } else {
                                body.append(k, data[k]);
                        }
                });
                return fetch(A, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) {
                                return r.text().then(function (t) {
                                        var d = null;
                                        try { d = JSON.parse(t); } catch (e) { d = null; }
                                        if (d && typeof d === 'object') return d;
                                        // An HTML/blank reply (fatal page, login page, proxy
                                        // banner) is turned into one readable Persian error
                                        // instead of a cryptic "Unexpected token" crash.
                                        var excerpt = String(t || '').replace(/<style[\s\S]*?<\/style>/gi, ' ')
                                                .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                                                .replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                                        throw new Error('پاسخ سرور JSON نبود' + (r.status ? ' (HTTP ' + r.status + ')' : '')
                                                + (excerpt ? ': ' + excerpt.slice(0, 160) : ''));
                                });
                        });
        }

        function collectProvider() {
                var out = {};
                qsa('input[name^="provider["],select[name^="provider["],textarea[name^="provider["]').forEach(function (el) {
                        var key = el.name.replace(/^provider\[|\]$/g, '');
                        if (el.type === 'checkbox') {
                                out[key] = el.checked ? '1' : '';
                        } else if (el.type === 'radio') {
                                if (el.checked) out[key] = el.value;
                        } else {
                                out[key] = el.value;
                        }
                });
                return out;
        }

        /* ------------------------------------------------------------------
         * Media picker
         * ---------------------------------------------------------------- */

        qsa('[data-haai-media-pick]').forEach(function (btn) {
                on(btn, 'click', function (e) {
                        e.preventDefault();
                        var wrap = btn.closest('[data-haai-media-wrap]');
                        var input = wrap ? wrap.parentNode.querySelector('[data-haai-media]') : null;
                        if (!input) return;

                        var frame = wp.media({
                                title: 'انتخاب تصویر',
                                multiple: false,
                                library: { type: 'image' }
                        });
                        frame.on('select', function () {
                                var att = frame.state().get('selection').first().toJSON();
                                input.value = att.id;
                                if (wrap) {
                                        var img = wrap.querySelector('img');
                                        if (!img) {
                                                img = document.createElement('img');
                                                img.width = 48;
                                                img.height = 48;
                                                img.loading = 'lazy';
                                                wrap.insertBefore(img, wrap.firstChild);
                                        }
                                        img.src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                                }
                        });
                        frame.open();
                });
        });

        qsa('[data-haai-media-clear]').forEach(function (btn) {
                on(btn, 'click', function (e) {
                        e.preventDefault();
                        var wrap = btn.closest('[data-haai-media-wrap]');
                        var input = wrap ? wrap.parentNode.querySelector('[data-haai-media]') : null;
                        if (input) input.value = '0';
                        if (wrap) {
                                var img = wrap.querySelector('img');
                                if (img) img.parentNode.removeChild(img);
                        }
                });
        });

        /* ------------------------------------------------------------------
         * Provider: test connection + model list
         * ---------------------------------------------------------------- */

        // The admin PHP side expects provider[key] form keys, so this posts
        // application/x-www-form-urlencoded instead of multipart.
        function postProvider(action) {
                var body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', C.nonce || '');
                var p = collectProvider();
                Object.keys(p).forEach(function (k) { body.append('provider[' + k + ']', p[k]); });
                return fetch(A, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                }).then(function (r) { return r.json(); });
        }

        var testBtn = qs('[data-haai-test]');
        // While the probe runs the result box becomes a live counter — providers on
        // filtered endpoints hang silently for the whole timeout, and without the
        // counter the button just looks frozen.
        function runTicker(result) {
                var t0 = Date.now();
                var label = (C.i18n && C.i18n.testing) || 'در حال آزمایش اتصال…';
                if (result) {
                        result.className = 'haai-test-result is-waiting';
                        result.textContent = label + ' (0 ثانیه)';
                }
                return setInterval(function () {
                        if (result) result.textContent = label + ' (' + Math.round((Date.now() - t0) / 1000) + ' ثانیه)';
                }, 1000);
        }
        function stopTicker(tick) { clearInterval(tick); }

        on(testBtn, 'click', function (e) {
                e.preventDefault();
                if (!testBtn.getAttribute('data-label')) testBtn.setAttribute('data-label', testBtn.textContent);
                busy(testBtn, true);
                var result = qs('[data-haai-test-result]');
                var tick = runTicker(result);
                postProvider('haai_test_connection')
                        .then(function (d) {
                                stopTicker(tick);
                                busy(testBtn, false);
                                if (!result) return;
                                var failMsg = (d && (d.error || d.message)) || (C.i18n && C.i18n.fail) || 'Failed';
                                result.className = 'haai-test-result ' + (d && d.ok ? 'is-ok' : 'is-fail');
                                result.textContent = d && d.ok
                                        ? ((C.i18n && C.i18n.ok) || 'OK') + (d.latency ? ' — ' + d.latency + 'ms' : '') + (d.model ? ' — ' + d.model : '')
                                        : failMsg;
                        })
                        .catch(function () {
                                stopTicker(tick);
                                busy(testBtn, false);
                                if (result) {
                                        result.className = 'haai-test-result is-fail';
                                        result.textContent = (C.i18n && C.i18n.fail) || 'Failed';
                                }
                        });
        });

        var modelsBtn = qs('[data-haai-models]');
        on(modelsBtn, 'click', function (e) {
                e.preventDefault();
                if (!modelsBtn.getAttribute('data-label')) modelsBtn.setAttribute('data-label', modelsBtn.textContent);
                busy(modelsBtn, true);
                var result = qs('[data-haai-test-result]');
                var tick = runTicker(result);

                postProvider('haai_fetch_models')
                        .then(function (d) {
                                stopTicker(tick);
                                busy(modelsBtn, false);
                                var sel = qs('select[name="provider[model]"]');
                                var list = qs('[data-haai-model-list]');
                                if (!d || !d.ok) {
                                        // Previously a failed fetch left no trace on screen at all.
                                        if (result) {
                                                result.className = 'haai-test-result is-fail';
                                                result.textContent = (d && d.error) || (C.i18n && C.i18n.fail) || 'Failed';
                                        }
                                        return;
                                }
                                if (result) {
                                        result.className = 'haai-test-result is-ok';
                                        result.textContent = (C.i18n && C.i18n.ok) || 'OK';
                                }
                                if (!list) return;
                                list.innerHTML = '';
                                (d.models || []).slice(0, 60).forEach(function (m) {
                                        var opt = document.createElement('option');
                                        opt.value = m;
                                        opt.textContent = m;
                                        list.appendChild(opt);
                                        if (sel && !sel.value) sel.value = m;
                                });
                        })
                        .catch(function () { stopTicker(tick); busy(modelsBtn, false); });
        });

        // Preset chips fill base_url + model without touching the key.
        var presetNote = qs('[data-haai-preset-note]');
        qsa('[data-haai-preset]').forEach(function (btn) {
                on(btn, 'click', function (e) {
                        e.preventDefault();
                        var slug = btn.getAttribute('data-haai-preset');
                        var sel = qs('select[name="provider[provider]"]');
                        var base = qs('input[name="provider[base_url]"]');
                        var model = qs('input[name="provider[model]"]');
                        // Order matters: switching the select fires the per-provider vault
                        // swap FIRST (it must stash the old service's fields untouched and
                        // load the new one's saved row), so the chip's own base/model data
                        // only fills the case the change event cannot — clicking the
                        // already-active chip to restore its preset defaults.
                        if (sel) {
                                var wasSel = sel.value;
                                sel.value = slug;
                                if (slug !== wasSel) sel.dispatchEvent(new Event('change'));
                                else {
                                        if (base && btn.getAttribute('data-base')) base.value = btn.getAttribute('data-base');
                                        if (model && btn.getAttribute('data-model')) model.value = btn.getAttribute('data-model');
                                }
                        } else {
                                if (base && btn.getAttribute('data-base')) base.value = btn.getAttribute('data-base');
                                if (model && btn.getAttribute('data-model')) model.value = btn.getAttribute('data-model');
                        }
                        var opt = sel ? sel.options[sel.selectedIndex] : null;
                        if (opt) opt.selected = true;
                        // Reachability note: providers that are commonly filtered from
                        // Iranian servers warn the admin BEFORE the timeout does.
                        // (The change handler mirrors the note too; this covers the
                        // no-change case of clicking the already-active chip.)
                        if (presetNote) {
                                var note = btn.getAttribute('data-note') || '';
                                presetNote.textContent = note;
                                presetNote.hidden = !note;
                        }
                });
        });

        /* ------------------------------------------------------------------
         * Per-provider credential vault
         *
         * Every preset keeps its own api_key/base_url/models server-side
         * (provider.vault). Switching «سرویس» swaps that service's saved
         * values into the form, so each provider's key stays bound to it
         * until the admin changes it — filling Grok's key can never clobber
         * Gemini's. Unsaved edits are stashed per provider in memory, so
         * toggling back and forth never loses typing. The plaintext keys
         * never reach the browser: a saved key shows as the same
         * haai-unchanged mask the secret fields already speak.
         * ------------------------------------------------------------------ */

        (function () {
                var sel = qs('select[name="provider[provider]"]');
                if (!sel) return;

                var FIELDS = ['api_key', 'base_url', 'model', 'embedding_model', 'image_model'];
                var MASK = 'haai-unchanged';
                var vault = C.vault || {};
                var presets = C.presets || {};
                var stash = {};
                var current = sel.value;
                var fromInput = qs('input[name="provider[vault_from]"]');

                function el(k) { return qs('[name="provider[' + k + ']"]'); }

                function noteEl() {
                        var n = qs('[data-haai-vault-note]');
                        if (!n) {
                                var key = el('api_key');
                                if (!key || !key.parentNode) return null;
                                n = document.createElement('p');
                                n.className = 'description haai-vault-note';
                                n.setAttribute('data-haai-vault-note', '');
                                key.parentNode.insertBefore(n, key.nextSibling);
                        }
                        return n;
                }

                function say(msg) {
                        var n = noteEl();
                        if (n) n.textContent = msg;
                }

                function readRow() {
                        var row = {};
                        FIELDS.forEach(function (k) {
                                var f = el(k);
                                row[k] = f ? f.value : '';
                        });
                        return row;
                }

                function writeRow(row) {
                        FIELDS.forEach(function (k) {
                                var f = el(k);
                                if (!f) return;
                                var v = row && row[k] !== undefined && row[k] !== null ? String(row[k]) : '';
                                if (f.value === v) return;
                                f.value = v;
                        });
                }

                on(sel, 'change', function () {
                        var next = sel.value;
                        if (!next || next === current) return;

                        // Keep the old provider's unsaved edits in memory.
                        stash[current] = readRow();

                        // Load order: unsaved edits > saved vault row > preset defaults.
                        var row = stash[next] || vault[next] || null;
                        if (row) {
                                writeRow(row);
                        } else {
                                writeRow({
                                        api_key: '',
                                        base_url: (presets[next] && presets[next].base_url) || '',
                                        model: (presets[next] && presets[next].model) || '',
                                        embedding_model: '',
                                        image_model: ''
                                });
                        }

                        current = next;
                        // Tell the server the form now really holds THIS service's values.
                        if (fromInput) fromInput.value = next;

                        // Mirror the reachability note the preset chips carry.
                        if (presetNote) {
                                var chip = qs('[data-haai-preset="' + next + '"]');
                                var note = chip ? (chip.getAttribute('data-note') || '') : '';
                                presetNote.textContent = note;
                                presetNote.hidden = !note;
                        }

                        // One honest sentence about what the key field now holds.
                        var stashed = stash[next];
                        if (stashed && stashed.api_key && stashed.api_key !== MASK) {
                                say('تغییرات ذخیره‌نشده‌ی این سرویس برگردانده شد.');
                        } else if (vault[next] && vault[next].has_key) {
                                say('کلید ذخیره‌شده‌ی همین سرویس بارگذاری شد — تا خودتان تغییرش ندهید همین می‌ماند.');
                        } else {
                                say('این سرویس هنوز کلید ذخیره‌شده ندارد — کلیدش را وارد و ذخیره کنید. کلید سرویس قبلی جدا نگه داشته می‌شود.');
                        }
                });
        })();

        /* ------------------------------------------------------------------
         * Secret fields: never submit the mask by accident
         * ---------------------------------------------------------------- */

        qsa('[data-haai-secret]').forEach(function (el) {
                on(el, 'focus', function () {
                        if (el.value === 'haai-unchanged') el.value = '';
                        el.placeholder = '';
                });
                on(el, 'blur', function () {
                        if ('' === el.value) el.value = 'haai-unchanged';
                });
        });

        /* ------------------------------------------------------------------
         * Search preview (semantic-search tab)
         * ---------------------------------------------------------------- */

        var previewForm = qs('[data-haai-search-preview]');
        on(previewForm, 'submit', function (e) {
                e.preventDefault();
                var input = qs('input[name="q"]', previewForm);
                var out = qs('[data-haai-search-results]');
                if (!input || !out) return;

                post('haai_search_preview', { q: input.value })
                        .then(function (d) {
                                out.innerHTML = '';
                                if (!d || !d.ok || !d.hits || !d.hits.length) {
                                        out.innerHTML = '<li>نتیجه‌ای پیدا نشد.</li>';
                                        return;
                                }
                                d.hits.forEach(function (h) {
                                        var li = document.createElement('li');
                                        var t = document.createElement('span');
                                        t.className = 'haai-hit-title';
                                        t.textContent = h.title || '(بدون عنوان)';
                                        var x = document.createElement('span');
                                        x.className = 'haai-hit-excerpt';
                                        x.textContent = (h.content || '').slice(0, 220);
                                        li.appendChild(t);
                                        li.appendChild(x);
                                        out.appendChild(li);
                                });
                        })
                        .catch(function () { out.innerHTML = '<li>خطا در اجرا.</li>'; });
        });

        /* ------------------------------------------------------------------
         * Appearance: live preview
         *
         * The preview is server-rendered from the saved settings; this only paints
         * the values currently in the form on top of it, as inline custom
         * properties on the preview container. It writes no input and submits
         * nothing, so saving stays the only way to change a setting.
         * ---------------------------------------------------------------- */

        var preview = qs('[data-haai-preview]');
        if (preview) {
                var paintVars = {
                        primary_color: ['--haai-primary', ''],
                        bg_color: ['--haai-bg', ''],
                        text_color: ['--haai-text', ''],
                        secondary_color: ['--haai-bubble', ''],
                        dark_bg: ['--haai-dark-bg', ''],
                        dark_text: ['--haai-dark-text', ''],
                        dark_bubble: ['--haai-dark-bubble', ''],
                        border_radius: ['--haai-radius', 'px'],
                        font_size: ['--haai-fs', 'px'],
                        button_size: ['--haai-btn-size', 'px']
                };
                var families = { vazirmatn: '"Vazirmatn"', iransans: '"IRANSans"', dana: '"Dana"', yekan: '"YekanBakh"' };
                var widget = qs('.haai-widget', preview);

                // Same rule as haa_contrast_text(): pale accents get dark text.
                function readableOn(hex) {
                        var s = String(hex).replace('#', '');
                        if (s.length === 3) { s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2]; }
                        if (!/^[0-9a-fA-F]{6}$/.test(s)) return '#ffffff';
                        var c = [0, 1, 2].map(function (i) {
                                var v = parseInt(s.substr(i * 2, 2), 16) / 255;
                                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                        });
                        return (0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]) > 0.19 ? '#12141a' : '#ffffff';
                }

                function valueOf(name) {
                        var el = qs('[name="appearance[' + name + ']"]');
                        return el ? el.value : '';
                }

                function syncPreview() {
                        Object.keys(paintVars).forEach(function (name) {
                                var v = valueOf(name);
                                if ('' === v) return;
                                preview.style.setProperty(paintVars[name][0], v + paintVars[name][1]);
                        });

                        var accent = valueOf('primary_color');
                        if (accent) preview.style.setProperty('--haai-on-accent', readableOn(accent));

                        var font = valueOf('font');
                        if (font) {
                                preview.style.setProperty(
                                        '--haai-font',
                                        (families[font] ? families[font] + ',' : '') + 'Tahoma,"Segoe UI",system-ui,sans-serif'
                                );
                        }

                        if (widget) widget.setAttribute('data-theme', valueOf('theme_mode') || 'light');
                        if (widget) widget.setAttribute('data-skin', valueOf('skin') || 'standard');

                        var bubble = qs('.haai-msg--bot .haai-msg__bubble', preview);
                        if (bubble) bubble.textContent = valueOf('welcome_message');

                        var ta = qs('.haai-form__input', preview);
                        if (ta) ta.setAttribute('placeholder', valueOf('placeholder'));
                }

                qsa('[name^="appearance["]').forEach(function (el) {
                        on(el, 'input', syncPreview);
                        on(el, 'change', syncPreview);
                });
                syncPreview();
        }

        /* ------------------------------------------------------------------
         * Page pickers: AJAX search, chips, and revealing only the picker that
         * the chosen page rule uses.
         * ---------------------------------------------------------------- */

        var MAX_PAGES = 500; // Mirrors the cap in Haa_Settings::sanitize_id_list().

        function pickerChips(root) {
                return qsa('[data-haai-picker-chosen] input[type="hidden"]', root).length;
        }

        function pickerRefresh(root) {
                var count = pickerChips(root);
                var note = qs('[data-haai-picker-count]', root);
                if (note) note.textContent = count ? count + ' / ' + MAX_PAGES : '';
                var empty = qs('[data-haai-picker-chosen] .description', root);
                if (empty && count) empty.parentNode.removeChild(empty);
        }

        qsa('[data-haai-picker]').forEach(function (root) {
                var name = root.getAttribute('data-haai-picker');
                var url = root.getAttribute('data-haai-search');
                var box = qs('[data-haai-picker-q]', root);
                var out = qs('[data-haai-picker-results]', root);
                var chosen = qs('[data-haai-picker-chosen]', root);
                if (!box || !out || !url) return;

                var timer = null;

                function addChip(id, title) {
                        if (pickerChips(root) >= MAX_PAGES) return;
                        var chip = document.createElement('span');
                        chip.className = 'haai-chip haai-chip--on';
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name + '[]';
                        input.value = id;
                        chip.appendChild(input);
                        chip.appendChild(document.createTextNode(' ' + title + ' (#' + id + ') '));
                        var x = document.createElement('button');
                        x.type = 'button';
                        x.className = 'haai-chip__x';
                        x.setAttribute('data-haai-picker-drop', '');
                        x.setAttribute('aria-label', 'حذف');
                        x.innerHTML = '&times;';
                        chip.appendChild(x);
                        chosen.appendChild(chip);
                        pickerRefresh(root);
                }

                function search() {
                        var q = box.value.trim();
                        var sep = url.indexOf('?') === -1 ? '?' : '&';
                        fetch(url + sep + 'q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                        out.innerHTML = '';
                                        var rows = (d && d.results) || [];
                                        if (!rows.length) {
                                                var none = document.createElement('li');
                                                none.textContent = 'نتیجه‌ای نبود.';
                                                out.appendChild(none);
                                        }
                                        rows.forEach(function (row) {
                                                var li = document.createElement('li');
                                                var b = document.createElement('button');
                                                b.type = 'button';
                                                b.textContent = row.title + ' — ' + row.type;
                                                b.setAttribute('data-haai-pick', row.id);
                                                b.setAttribute('data-title', row.title);
                                                li.appendChild(b);
                                                out.appendChild(li);
                                        });
                                        out.hidden = false;
                                })
                                .catch(function () { out.hidden = true; });
                }

                on(box, 'input', function () {
                        if (timer) clearTimeout(timer);
                        timer = setTimeout(search, 350);
                });
                on(box, 'focus', function () { if (box.value.trim()) search(); });

                // One click delegate for adding and removing, so re-rendered chips
                // need no rebinding.
                on(root, 'click', function (e) {
                        var pick = e.target.closest ? e.target.closest('[data-haai-pick]') : null;
                        if (pick) { addChip(pick.getAttribute('data-haai-pick'), pick.getAttribute('data-title')); return; }
                        var drop = e.target.closest ? e.target.closest('[data-haai-picker-drop]') : null;
                        if (drop && drop.parentNode) {
                                drop.parentNode.parentNode.removeChild(drop.parentNode);
                                pickerRefresh(root);
                        }
                });

                pickerRefresh(root);
        });

        // Only the picker belonging to the chosen rule stays visible. 'any' means
        // "both list-building rules", which is the case for the special pages.
        function showMode() {
                var sel = qs('[data-haai-pages-mode]:checked');
                var mode = sel ? sel.value : 'all';
                qsa('[data-haai-show]').forEach(function (el) {
                        var want = el.getAttribute('data-haai-show');
                        if ('any' === want) { el.hidden = 'all' === mode; return; }
                        el.hidden = want !== mode;
                });
        }
        if (qs('[data-haai-pages-mode]')) {
                qsa('[data-haai-pages-mode]').forEach(function (el) { on(el, 'change', showMode); });
                showMode();
        }

        /* Product picker (product-content screen) lives in product-picker.js,
           enqueued on that screen alone. Nothing here must touch its markup, or two
           handlers would race on the same search input. */

        /* ------------------------------------------------------------------
         * Topic planner: live cost estimate for the chosen subjects.
         * The server repeats this check before it queues anything; this only
         * tells the admin what they are about to ask for.
         * ---------------------------------------------------------------- */

        var planForm = qs('[data-haai-plan-form]');
        if (planForm) {
                var planOut = qs('[data-haai-plan-estimate]', planForm);
                var planBtn = qs('[data-haai-plan-submit]', planForm);
                var planImg = qs('[data-haai-plan-image]', planForm);
                var perArticle = parseInt(planForm.getAttribute('data-per-article') || '0', 10);

                // 2.1.36: the row checkboxes are NOT descendants of
                // #haai-plan-queue — they live in the table above it and point at
                // the form with the HTML `form` attribute. The old descendant
                // selector (`#haai-plan-queue input[...]`) never matched them, so
                // the count stayed 0, the estimate froze on «برای برآورد هزینه…»
                // and the submit button never unlocked (live report: «اصلا نمیشه
                // چیزیو انتخاب کرد به صف تولید محتوا برد»). Both spellings count
                // now; querySelectorAll dedupes an element matched twice.
                function planBoxes() {
                        return qsa('input[name="ids[]"][form="haai-plan-queue"], #haai-plan-queue input[name="ids[]"]');
                }
                function planCount() {
                        var n = 0;
                        planBoxes().forEach(function (cb) { if (cb.checked) n++; });
                        return n;
                }
                var planAll = qs('[data-haai-plan-all]');

                function planSync() {
                        var n = planCount();
                        var dbl = planImg && planImg.checked ? 2 : 1;
                        if (planBtn) planBtn.disabled = n === 0;
                        if (planAll) {
                                var total = planBoxes().length;
                                planAll.checked = n > 0 && n === total;
                                planAll.indeterminate = n > 0 && n < total;
                        }
                        if (!planOut) return;
                        planOut.textContent = n
                                ? n + ' موضوع — تخمین: ' + (n * dbl) + ' درخواست و ' + (n * perArticle * dbl).toLocaleString('fa-IR') + ' توکن'
                                : 'برای برآورد هزینه، موضوعی را انتخاب کنید.';
                }

                // «همه» in the table header: one click selects every choosable row
                // (and a second click lets go of all of them).
                if (planAll) {
                        on(planAll, 'change', function () {
                                planBoxes().forEach(function (cb) { cb.checked = planAll.checked; });
                                planSync();
                        });
                }

                // The checkboxes live in the table and point at this form with the HTML
                // `form` attribute, so listen on the document and filter. 2.1.36: the
                // filter matches by NAME, not by a descendant path — the old one never
                // fired for the table checkboxes, so ticking a row changed nothing.
                on(document, 'change', function (e) {
                        if (e.target && e.target.matches && e.target.matches('input[name="ids[]"], [data-haai-plan-image], [data-haai-plan-all]')) planSync();
                });
                planSync();
        }

        /* ------------------------------------------------------------------
         * One-click token spend guard (2.1.36)
         *
         * The keyword panel's «ساخت مقاله برای انتخاب‌شده‌ها» queues real
         * articles from the research list, so it asks once — in Persian, with
         * the consequence named — before the form posts. The server keeps its
         * own caps; this is the polite version of that gate.
         * ---------------------------------------------------------------- */

        qsa('[data-haai-spend]').forEach(function (el) {
                on(el, 'click', function (e) {
                        var msg = el.getAttribute('data-haai-spend') || (C.i18n && C.i18n.confirm) || '';
                        if (!window.confirm(msg)) e.preventDefault();
                });
        });

        /* ------------------------------------------------------------------
         * Reset confirmation
         * ---------------------------------------------------------------- */

        qsa('[data-haai-confirm]').forEach(function (el) {
                on(el, 'submit', function (e) {
                        if (!window.confirm((C.i18n && C.i18n.confirm) || '')) e.preventDefault();
                });
        });

        /* ------------------------------------------------------------------
         * Typed confirmation for the settings reset (A2)
         *
         * The button unlocks only when the typed word matches, so a stray click
         * cannot wipe a working install. The server checks the same word on its
         * own — this is the polite version of that gate, never the gate itself.
         * ---------------------------------------------------------------- */

        qsa('[data-haai-reset-word]').forEach(function (form) {
                var want = form.getAttribute('data-haai-reset-word') || '';
                var btn = form.querySelector('[data-haai-reset-submit]');
                var inp = form.querySelector('input[name="confirm_word"]');
                if (!btn || !inp) return;
                var sync = function () { btn.disabled = inp.value.trim() !== want; };
                on(inp, 'input', sync);
                on(form, 'submit', function (e) {
                        if (inp.value.trim() !== want) e.preventDefault();
                });
                sync();
        });

        /* ------------------------------------------------------------------
         * Keyword research panel (article-writer screen)
         *
         * One AJAX call returns the combined, deduped suggestions; the admin
         * ticks rows and the same form POSTs them into the planner.
         * ---------------------------------------------------------------- */

        (function () {
                var root = document.getElementById('haai-kw');
                if (!root) return;
                var run = document.getElementById('haai-kw-run');
                var seed = document.getElementById('haai-kw-seed');
                var status = document.getElementById('haai-kw-status');
                var diagBox = document.getElementById('haai-kw-diag');
                var form = document.getElementById('haai-kw-form');
                var rows = document.getElementById('haai-kw-rows');

                // Per-source status lines from the server (which engine answered,
                // which failed and why, what to do about it). Always shown when
                // present — the admin never has to guess again.
                function showDiag(d) {
                        if (!diagBox) return;
                        var lines = (d && d.diag && d.diag.length) ? d.diag.join('  •  ') : '';
                        diagBox.textContent = lines;
                        diagBox.hidden = !lines;
                }
                // The GSC opportunities and the site's misses are server-rendered
                // inside the same form — show it without waiting for a search.
                var staticForm = form && form.querySelector('[data-haai-kw-static]');
                if (staticForm) form.hidden = false;
                var SRC = { suggest: 'ساجست کاربران', wiki: 'ویکی‌پدیا', serp: 'نتایج جستجو', snapshot: 'تحلیل رقبا', ai: 'مدل', local: 'پیشنهاد خودکار', planner: 'برنامه‌ریز', misses: 'بی‌نتیجه', gsc: 'سرچ‌کنسول' };
                var INT = { informational: 'اطلاعاتی', commercial: 'تجاری', transactional: 'خرید', local: 'محلی' };
                var COV = { has: 'جواب دارد', half: 'نیمه', none: 'بی‌جواب' };

                function say(msg) {
                        if (!status) return;
                        if (!msg) { status.hidden = true; status.textContent = ''; return; }
                        status.hidden = false;
                        status.textContent = msg;
                }

                function row(r) {
                        var label = document.createElement('label');
                        label.className = 'haai-kw__row';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.name = 'kw[]';
                        cb.value = r.keyword;
                        var text = document.createElement('span');
                        text.className = 'haai-kw__text';
                        text.textContent = r.keyword;
                        var tag = document.createElement('span');
                        tag.className = 'haai-badge haai-kw__src';
                        tag.textContent = SRC[r.source] || r.source;
                        label.appendChild(cb);
                        label.appendChild(text);
                        label.appendChild(tag);
                        if (r.note) {
                                var note = document.createElement('span');
                                note.className = 'haai-kw__note';
                                note.textContent = r.note;
                                label.appendChild(note);
                        }
                        if (r.intent && INT[r.intent]) {
                                var it = document.createElement('span');
                                it.className = 'haai-badge haai-kw__intent';
                                it.textContent = INT[r.intent];
                                label.appendChild(it);
                        }
                        if (r.ai) {
                                var aib = document.createElement('span');
                                aib.className = 'haai-badge haai-kw__ai';
                                aib.textContent = 'AI';
                                aib.title = 'مناسب پاسخ هوش مصنوعی';
                                label.appendChild(aib);
                        }
                        if (r.coverage && r.coverage.state) {
                                var cov = document.createElement('a');
                                cov.className = 'haai-badge haai-kw__cov haai-kw__cov--' + r.coverage.state;
                                cov.textContent = COV[r.coverage.state] || r.coverage.state;
                                if (r.coverage.url) {
                                        cov.href = r.coverage.url;
                                        cov.target = '_blank';
                                        cov.rel = 'noopener';
                                } else {
                                        cov.removeAttribute('href');
                                }
                                if (r.coverage.title) cov.title = r.coverage.title;
                                label.appendChild(cov);
                        }
                        return label;
                }

                var netRetries = 0;

                function search() {
                        var q = (seed.value || '').trim();
                        if (q.length < 3) { seed.focus(); return; }
                        run.disabled = true;
                        say('در حال گرفتن پیشنهادها… اولین تلاش ممکن است تا ۴۰ ثانیه طول بکشد؛ تلاش‌های بعدی از نتایج ذخیره‌شده سریع‌ترند.');
                        rows.innerHTML = '';
                        form.hidden = true;
                        post('haai_kw_research', { seed: q })
                                .then(function (d) {
                                        netRetries = 0;
                                        if (!d || !d.ok) throw new Error((d && d.error) || (C.i18n && C.i18n.fail) || 'خطا');
                                        showDiag(d);
                                        if (!d.rows || !d.rows.length) { say(d.error ? d.error : 'پیشنهادی پیدا نشد؛ موضوع دیگری امتحان کنید.'); return; }
                                        // 2.1.31: rows delivered → no headline. A failed model
                                        // is already named on its own «مدل:» line in the
                                        // per-source diag below; the old global
                                        // «یادداشت: همه منابع در حال خطا…» over a full list
                                        // lied about the sources.
                                        say('');
                                        d.rows.forEach(row);
                                        form.hidden = false;
                                })
                                .catch(function (e) {
                                        // 2.1.32: a browser-level «Failed to fetch» means the
                                        // request died BEFORE any response — usually the
                                        // host's proxy killing an over-budget first run (the
                                        // 2.1.31 live report: first click dead, second/third
                                        // fine). The dead run also warmed the per-seed source
                                        // cache and benched dead providers, so ONE automatic
                                        // retry usually succeeds silently.
                                        var m = String((e && e.message) || '');
                                        var net = (e && e.name === 'TypeError') || /failed to fetch|networkerror|load failed/i.test(m);
                                        if (net && netRetries++ < 1) {
                                                say('ارتباط با سرور قطع شد؛ خودکار یک‌بار دیگر تلاش می‌کنم… (تلاش اول کش منابع را گرم کرده است)');
                                                setTimeout(search, 1500);
                                                return;
                                        }
                                        if (net) {
                                                say('ارتباط با سرور قطع شد (Failed to fetch). معمولاً یعنی پردازش از سقف زمانی سرور گذشته؛ چند لحظه بعد دوباره «پیدا کن» را بزنید — تلاش بعدی از نتایج ذخیره‌شده سریع‌تر جواب می‌دهد.');
                                                return;
                                        }
                                        say(e.message || 'خطا');
                                })
                                .then(function () { run.disabled = false; });
                }

                on(run, 'click', search);
                on(seed, 'keydown', function (e) {
                        if (e.key === 'Enter') { e.preventDefault(); search(); }
                });
        })();

        // Failover sources (provider tab): add/remove rows, reindex the name
        // attributes, fill base URL/model from the chosen preset. The health
        // badges are server-rendered; nothing here talks to the network.
        (function () {
                var wrap = qs('[data-haai-fb]');
                if (!wrap) return;
                var tpl = qs('[data-haai-fb-template]');
                var add = qs('[data-haai-fb-add]');
                var MAX = 5;

                var presets = {};
                try { presets = JSON.parse(wrap.getAttribute('data-presets') || '{}'); } catch (e) { presets = {}; }

                function reindex() {
                        qsa('[data-haai-fb-row]', wrap).forEach(function (row, i) {
                                qsa('input,select', row).forEach(function (el) {
                                        var n = el.getAttribute('name') || '';
                                        el.setAttribute('name', n.replace(/provider\[failovers\]\[[^\]]*\]/, 'provider[failovers][' + i + ']'));
                                });
                        });
                }

                function wire(row) {
                        var sel = row.querySelector('[data-haai-fb-provider]');
                        on(sel, 'change', function () {
                                var p = presets[sel.value];
                                if (!p) return;
                                var inputs = row.querySelectorAll('input[type=text]');
                                // Only fill EMPTY fields — a typed relay address or model
                                // must never be silently overwritten by the preset.
                                if (inputs[0] && '' === inputs[0].value.trim()) inputs[0].value = p.base_url;
                                if (inputs[1] && '' === inputs[1].value.trim()) inputs[1].value = p.model;
                        });
                        on(row.querySelector('[data-haai-fb-remove]'), 'click', function () {
                                row.parentNode.removeChild(row);
                                reindex();
                        });
                }

                qsa('[data-haai-fb-row]', wrap).forEach(wire);

                on(add, 'click', function () {
                        if (!tpl || qsa('[data-haai-fb-row]', wrap).length >= MAX) return;
                        var src = tpl.content && tpl.content.firstElementChild;
                        if (!src) return;
                        var row = src.cloneNode(true);
                        row.innerHTML = row.innerHTML.replace(/__I__/g, String(qsa('[data-haai-fb-row]', wrap).length));
                        wrap.appendChild(row);
                        wire(row);
                });
        })();
})();
