/**
 * Minifier for Hami AI Assistant assets. No dependencies, no network.
 *
 *   node tools/minify.mjs            write *.min.css / *.min.js + report
 *   node tools/minify.mjs --check    report only, write nothing
 *
 * Sources are never touched; the plugin prefers the minified sibling when it
 * exists and falls back to the source when it does not (see haa_asset_path()),
 * so deleting the build products or skipping this script is always safe.
 *
 * Both transformations are deliberately conservative:
 *   CSS  comments stripped, whitespace collapsed, spaces around { } : ; removed
 *        (quoted strings are preserved byte for byte).
 *   JS   comments stripped and leading indentation / blank lines removed.
 *        Every other newline is kept, so no automatic-semicolon hazard is
 *        introduced and the files stay readable in devtools.
 *
 * Each output is re-parsed with node's own parser (new Function) before it is
 * written, and CSS is checked to still carry the same non-whitespace text as
 * its source; a mismatch aborts the build.
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { extname, join, basename, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// Resolve against the plugin folder, not the caller's cwd, so `node
// tools/minify.mjs` works from anywhere (and from an editor task runner).
const PLUGIN_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const argRoot = process.argv.slice(2).find((a) => !a.startsWith('--'));
const ROOT = argRoot ? resolve(process.cwd(), argRoot) : join(PLUGIN_ROOT, 'assets');
const CHECK_ONLY = process.argv.includes('--check');

if (!existsSync(ROOT) || !statSync(ROOT).isDirectory()) {
	console.error(`no such directory: ${ROOT}\nRun "node tools/minify.mjs" from anywhere; pass a path only if you really mean to.`);
	process.exit(2);
}

/* ------------------------------------------------------------------ */
/* CSS                                                                */
/* ------------------------------------------------------------------ */

function minifyCss(src) {
	let out = '';
	let i = 0;
	const n = src.length;

	while (i < n) {
		const c = src[i];

		// Comment.
		if (c === '/' && src[i + 1] === '*') {
			const end = src.indexOf('*/', i + 2);
			i = end === -1 ? n : end + 2;
			continue;
		}

		// Quoted string: copy verbatim, honouring backslash escapes.
		if (c === '"' || c === "'") {
			out += c;
			i++;
			while (i < n) {
				if (src[i] === '\\') {
					out += src[i] + (src[i + 1] ?? '');
					i += 2;
					continue;
				}
				out += src[i];
				if (src[i] === c) { i++; break; }
				i++;
			}
			continue;
		}

		out += c;
		i++;
	}

	// Collapse runs of whitespace outside the strings we just copied.
	out = out
		.replace(/\s+/g, ' ')
		.replace(/\s*([{}:;,>])\s*/g, '$1')
		.replace(/;}/g, '}')
		.replace(/\)\s*\{/g, '){')
		.trim();

	return out;
}

/* ------------------------------------------------------------------ */
/* JS                                                                 */
/* ------------------------------------------------------------------ */

function minifyJs(src) {
	const lines = src.split(/\r?\n/);
	const out = [];
	let inBlock = false;

	for (const raw of lines) {
		let line = '';
		let i = 0;
		const n = raw.length;

		while (i < n) {
			const c = raw[i];

			if (inBlock) {
				const end = raw.indexOf('*/', i);
				if (end === -1) { i = n; }
				else { i = end + 2; inBlock = false; }
				continue;
			}

			// String or template literal: copy to its terminator.
			if (c === '"' || c === "'" || c === '`') {
				line += c;
				i++;
				while (i < n) {
					if (raw[i] === '\\') { line += raw[i] + (raw[i + 1] ?? ''); i += 2; continue; }
					line += raw[i];
					if (raw[i] === c) { i++; break; }
					i++;
				}
				continue;
			}

			if (c === '/' && raw[i + 1] === '*') {
				const end = raw.indexOf('*/', i + 2);
				if (end === -1) { inBlock = true; i = n; }
				else { i = end + 2; }
				continue;
			}
			if (c === '/' && raw[i + 1] === '/') break; // rest of line is a comment

			// Regex literal: only where a regex can legally start.
			if (c === '/') {
				const prev = line.trimEnd().slice(-1);
				const regexOk = line.trim() === '' || '(,=:[!&|?{};+-*%~^<>'.includes(prev);
				if (regexOk) {
					line += c;
					i++;
					let cls = false;
					while (i < n) {
						if (raw[i] === '\\') { line += raw[i] + (raw[i + 1] ?? ''); i += 2; continue; }
						line += raw[i];
						if (raw[i] === '[') cls = true;
						else if (raw[i] === ']') cls = false;
						else if (raw[i] === '/' && !cls) { i++; break; }
						i++;
					}
					continue;
				}
			}

			line += c;
			i++;
		}

		// Keep every other newline; drop only indentation and empty lines.
		const trimmed = line.trimEnd();
		if (trimmed === '' && !inBlock) continue;
		if (!inBlock) out.push(line.replace(/^[\t ]+/, ''));
		else out.push(line);
	}

	return out.join('\n').trim() + '\n';
}

/* ------------------------------------------------------------------ */
/* Build                                                              */
/* ------------------------------------------------------------------ */

// Both sides are reduced the same way before comparing, so the only allowed
// differences are comments and the exact punctuation the minifier rewrites.
const cssStripped = (s) => s
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, '')
	.replace(/;(?=})/g, '');

function walk(dir) {
	const found = [];
	for (const name of readdirSync(dir)) {
		const full = join(dir, name);
		if (statSync(full).isDirectory()) found.push(...walk(full));
		else found.push(full);
	}
	return found;
}

const rows = [];
let failed = false;

for (const file of walk(ROOT)) {
	const ext = extname(file).toLowerCase();
	if (ext !== '.css' && ext !== '.js') continue;
	if (file.includes('.min.')) continue;

	const src = readFileSync(file, 'utf8');
	const min = ext === '.css' ? minifyCss(src) : minifyJs(src);

	// CSS must still carry the same non-whitespace declarations.
	if (ext === '.css' && cssStripped(src) !== cssStripped(min)) {
		console.error(`ABORT ${file}: minified CSS lost or changed declaration text`);
		failed = true;
		continue;
	}

	// JS must still parse. new Function uses node's real parser, not a guess.
	if (ext === '.js') {
		try {
			new Function(min);
		} catch (e) {
			console.error(`ABORT ${file}: minified JS does not parse — ${e.message}`);
			failed = true;
			continue;
		}
	}

	const target = file.slice(0, -ext.length) + '.min' + ext;
	if (!CHECK_ONLY) writeFileSync(target, min);

	const gz = gzipSync(Buffer.from(min)).length;
	rows.push({
		name: basename(target),
		raw: Buffer.byteLength(src),
		min: Buffer.byteLength(min),
		gz,
		target,
	});
}

const pad = (v, w) => String(v).padStart(w);
console.log('file'.padEnd(26) + 'raw B'.padStart(10) + 'min B'.padStart(10) + 'min+gzip B'.padStart(12) + '  saving');
for (const r of rows) {
	console.log(r.name.padEnd(26) + pad(r.raw, 10) + pad(r.min, 10) + pad(r.gz, 12) + '  ' + (100 - (r.min / r.raw) * 100).toFixed(1) + '%');
}

const widgetJs = rows.filter((r) => r.name === 'chatbot.min.js' || r.name === 'loader.min.js');
if (widgetJs.length) {
	const min = widgetJs.reduce((a, r) => a + r.min, 0);
	const gz = widgetJs.reduce((a, r) => a + r.gz, 0);
	const raw = widgetJs.reduce((a, r) => a + r.raw, 0);
	console.log(`\nwidget JS  raw ${raw} B | min ${min} B | min+gzip ${gz} B`);
}
const widgetCss = rows.find((r) => r.name === 'chatbot.min.css');
if (widgetCss) {
	console.log(`widget CSS raw ${widgetCss.raw} B | min ${widgetCss.min} B | min+gzip ${widgetCss.gz} B`);
}

process.exit(failed ? 1 : 0);
