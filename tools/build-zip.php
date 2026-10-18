<?php
/**
 * Canonical ZIP builder for Hami AI Assistant (2.1.24+) — closes the 2.1.23
 * packaging disaster forever.
 *
 * What happened in 2.1.23: the ZIP shipped without the two new files
 * (class-haa-aeo.php, class-haa-admin-reports.php) while the main plugin file
 * already called Haa_Aeo::hooks() at boot — the autoloader required a missing
 * file on EVERY request and the live site died with the WP critical-error
 * screen. The old ad-hoc packaging had NO post-build verification; this script
 * makes an incomplete package impossible to emit.
 *
 * Recipe (docs.md §15.5): tracked files in git, minus .distignore, under the
 * `hami-ai-assistant/` top folder, `*.min.*` inside.
 *
 * Hard gates (the build FAILS, non-zero exit, if ANY fails):
 *   G1  shipped file set == expected set (both directions, exact paths)
 *   G2  every shipped .php file passes php -l (linted from the ZIP contents)
 *   G3  class closure: every Haa_* class referenced by shipped code maps via
 *       the autoloader rule to a file that is present in the ZIP (the exact
 *       failure mode of 2.1.23 — must never happen again)
 *   G4  no .distignore path (dev-tests/, dev-notes/, *.zip, docs…) leaks in
 *   G5  every shipped file is byte-identical to its git-tracked source
 *       (catches "built from a stale tree")
 *
 * Usage:  php tools/build-zip.php [--out=/path/to/out.zip]
 * Default output: ../download/hami-ai-assistant-<version>.zip (outside repo).
 *
 * @package Hami_AI_Assistant
 */

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

error_reporting( E_ALL );

$repo       = dirname( __DIR__ );
$out_arg    = null;
foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( 0 === strpos( $arg, '--out=' ) ) {
                $out_arg = substr( $arg, 6 );
        }
}

/* --------------------------------------------------------------------------
 * 0) Preconditions — build from a clean, committed tree.
 * ----------------------------------------------------------------------- */

if ( ! is_file( $repo . '/hami-ai-assistant.php' ) ) {
        fwrite( STDERR, "BUILD FAIL: plugin main file not found at {$repo}\n" );
        exit( 1 );
}

$dirty = trim( (string) shell_exec( 'cd ' . escapeshellarg( $repo ) . ' && git status --porcelain 2>&1' ) );
if ( '' !== $dirty ) {
        fwrite( STDERR, "BUILD FAIL: working tree is dirty — commit first (the recipe builds from tracked files, docs.md §15.5):\n{$dirty}\n" );
        exit( 1 );
}

$version = '';
$src     = file_get_contents( $repo . '/hami-ai-assistant.php' );
if ( preg_match( '/^ \* Version:\s+(\S+)/m', $src, $m ) ) {
        $version = $m[1];
}
if ( '' === $version || ! preg_match( "/define\( 'HAAI_VERSION', '(" . preg_quote( $version, '/' ) . ")' \)/", $src ) ) {
        fwrite( STDERR, "BUILD FAIL: header Version and HAAI_VERSION constant disagree ({$version}).\n" );
        exit( 1 );
}

/* --------------------------------------------------------------------------
 * 1) Expected file set: git ls-files minus .distignore.
 * ----------------------------------------------------------------------- */

$ignore_patterns = array();
foreach ( file( $repo . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $line = trim( $line );
        if ( '' === $line || 0 === strpos( $line, '#' ) ) {
                continue;
        }
        $ignore_patterns[] = $line;
}

/**
 * Minimal distignore matcher: exact path, `dir/` prefix, `*.ext` suffix,
 * `dir/*` prefix. Deliberately conservative — no fancy globs.
 *
 * @param string   $rel      Repo-relative path.
 * @param string[] $patterns .distignore patterns.
 * @return bool
 */
function haai_dist_ignored( $rel, $patterns ) {
        foreach ( $patterns as $p ) {
                $p = rtrim( $p, '/' );
                if ( '' === $p ) {
                        continue;
                }
                if ( $rel === $p || 0 === strpos( $rel, $p . '/' ) ) {
                        return true;
                }
                if ( 0 === strpos( $p, '*' ) && substr( $rel, -strlen( $p ) + 1 ) === substr( $p, 1 ) ) {
                        return true; // *.zip, *.distignore …
                }
        }
        return false;
}

$tracked = explode( "\n", trim( (string) shell_exec( 'cd ' . escapeshellarg( $repo ) . ' && git ls-files' ) ) );
$expected = array();
foreach ( $tracked as $rel ) {
        $rel = trim( $rel );
        if ( '' === $rel || haai_dist_ignored( $rel, $ignore_patterns ) ) {
                continue;
        }
        $expected[ $rel ] = true;
}
if ( ! $expected ) {
        fwrite( STDERR, "BUILD FAIL: empty expected file list.\n" );
        exit( 1 );
}

/* --------------------------------------------------------------------------
 * 2) Fresh *.min.* assets (docs.md §15.4) — minify.mjs must succeed.
 * ----------------------------------------------------------------------- */

if ( is_file( $repo . '/tools/minify.mjs' ) ) {
        passthru( 'cd ' . escapeshellarg( $repo ) . ' && node tools/minify.mjs', $min_exit );
        if ( 0 !== $min_exit ) {
                fwrite( STDERR, "BUILD FAIL: tools/minify.mjs exited with {$min_exit}.\n" );
                exit( 1 );
        }
        // Minify may have refreshed tracked .min.* files — the tree must be clean again.
        $dirty2 = trim( (string) shell_exec( 'cd ' . escapeshellarg( $repo ) . ' && git status --porcelain 2>&1' ) );
        if ( '' !== $dirty2 ) {
                fwrite( STDERR, "BUILD FAIL: minify.mjs changed tracked files — commit the refreshed *.min.* first:\n{$dirty2}\n" );
                exit( 1 );
        }
}

/* --------------------------------------------------------------------------
 * 3) Pack: hami-ai-assistant/ prefix, outside the repo.
 * ----------------------------------------------------------------------- */

$out = $out_arg ? $out_arg : dirname( $repo ) . '/download/hami-ai-assistant-' . $version . '.zip';
$out_dir = dirname( $out );
if ( ! is_dir( $out_dir ) ) {
        mkdir( $out_dir, 0775, true );
}
if ( is_file( $out ) ) {
        unlink( $out );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
        fwrite( STDERR, "BUILD FAIL: cannot open {$out} for writing.\n" );
        exit( 1 );
}
foreach ( array_keys( $expected ) as $rel ) {
        $zip->addFile( $repo . '/' . $rel, 'hami-ai-assistant/' . $rel );
}
if ( ! $zip->close() ) {
        fwrite( STDERR, "BUILD FAIL: ZipArchive::close() failed for {$out}.\n" );
        exit( 1 );
}

/* --------------------------------------------------------------------------
 * 4) Gates — verify the BUILT ZIP, not the working tree.
 * ----------------------------------------------------------------------- */

$php     = PHP_BINARY;
$tmp     = rtrim( sys_get_temp_dir(), '/' ) . '/haai-build-verify-' . getmypid() . '-' . time();
$checks  = array();
$failure = '';

$za    = new ZipArchive();
$zopen = $za->open( $out );
if ( true !== $zopen ) {
        fwrite( STDERR, "BUILD FAIL: built ZIP cannot be re-opened (code {$zopen}).\n" );
        exit( 1 );
}
if ( ! $za->extractTo( $tmp ) ) {
        fwrite( STDERR, "BUILD FAIL: built ZIP cannot be extracted.\n" );
        exit( 1 );
}
$za->close();
$base = $tmp . '/hami-ai-assistant';

// ---- G1: exact file-set equality ----------------------------------------
$zip_files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
        if ( $f->isFile() ) {
                $zip_files[ ltrim( substr( (string) $f->getPathname(), strlen( $base ) ), '/' ) ] = true;
        }
}
$missing = array_diff_key( $expected, $zip_files ); // tracked but not shipped — THE 2.1.23 bug.
$extra   = array_diff_key( $zip_files, $expected ); // shipped but not tracked.
$checks['G1 file-set equality'] = ( ! $missing && ! $extra );
if ( ! $failure && ( $missing || $extra ) ) {
        $failure = "G1 FAIL — shipped file set != tracked set.\n"
                . ( $missing ? "  MISSING from ZIP (would fatal if referenced):\n    - " . implode( "\n    - ", array_keys( $missing ) ) . "\n" : '' )
                . ( $extra ? "  UNTRACKED inside ZIP:\n    - " . implode( "\n    - ", array_keys( $extra ) ) . "\n" : '' );
}

// ---- G2: php -l on every shipped PHP file --------------------------------
$lint_bad = array();
foreach ( array_keys( $zip_files ) as $rel ) {
        if ( '.php' !== substr( $rel, -4 ) ) {
                continue;
        }
        exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $base . '/' . $rel ) . ' 2>&1', $o, $rc );
        if ( 0 !== $rc ) {
                $lint_bad[] = $rel . ' :: ' . implode( ' ', $o );
        }
        $o = array();
}
$checks['G2 php -l all shipped files'] = ! $lint_bad;
if ( ! $failure && $lint_bad ) {
        $failure = "G2 FAIL — syntax errors inside the ZIP:\n  - " . implode( "\n  - ", $lint_bad );
}

// ---- G3: autoloader class closure ----------------------------------------
/**
 * Haa_* -> includes/class-haa-*.php (rule from hami-ai-assistant.php).
 *
 * @param string $class Class name.
 * @return string|null Expected repo-relative file, or null when not Haa_*.
 */
function haai_class_file( $class ) {
        if ( 0 !== strpos( $class, 'Haa_' ) ) {
                return null;
        }
        if ( 0 === strpos( $class, 'Haa_Adapter_' ) ) {
                return 'includes/class-haa-api.php'; // All adapters live together.
        }
        return 'includes/class-haa-' . strtolower( str_replace( '_', '-', substr( $class, 4 ) ) ) . '.php';
}

$refs_bad = array();
foreach ( array_keys( $zip_files ) as $rel ) {
        if ( '.php' !== substr( $rel, -4 ) ) {
                continue;
        }
        $code = (string) file_get_contents( $base . '/' . $rel );
        if ( ! preg_match_all( '/\bHaa_[A-Za-z0-9_]+/', $code, $mm ) ) {
                continue;
        }
        foreach ( array_unique( $mm[0] ) as $class ) {
                $want = haai_class_file( $class );
                if ( null === $want ) {
                        continue;
                }
                // Only enforce when the class actually exists in THIS tree (comments may
                // mention classes from other products); the gate then demands the ZIP
                // carries the file the autoloader will require.
                if ( is_file( $repo . '/' . $want ) && ! isset( $zip_files[ $want ] ) ) {
                        $refs_bad[] = "{$rel} references {$class} -> {$want} MISSING from ZIP";
                }
        }
}
$checks['G3 autoloader class closure'] = ! $refs_bad;
if ( ! $failure && $refs_bad ) {
        $failure = "G3 FAIL — referenced Haa_* classes whose file is not in the ZIP:\n  - " . implode( "\n  - ", $refs_bad );
}

// ---- G4: no .distignore path leaked into the package ---------------------
$leaked = array();
foreach ( array_keys( $zip_files ) as $rel ) {
        if ( haai_dist_ignored( $rel, $ignore_patterns ) ) {
                $leaked[] = $rel;
        }
}
$checks['G4 distignore respected'] = ! $leaked;
if ( ! $failure && $leaked ) {
        $failure = "G4 FAIL — excluded paths inside the ZIP:\n  - " . implode( "\n  - ", $leaked );
}

// ---- G5: byte-identity with the git-tracked sources ----------------------
$drift = array();
foreach ( array_keys( $expected ) as $rel ) {
        $a = (string) file_get_contents( $repo . '/' . $rel );
        $b = isset( $zip_files[ $rel ] ) ? (string) file_get_contents( $base . '/' . $rel ) : '';
        if ( $a !== $b ) {
                $drift[] = $rel;
        }
}
$checks['G5 byte-identical to tracked sources'] = ! $drift;
if ( ! $failure && $drift ) {
        $failure = "G5 FAIL — ZIP contents drift from the tracked tree (stale/partial build):\n  - " . implode( "\n  - ", $drift );
}

// ---- Minified assets present (docs.md §15.5: *.min.* must ship) ----------
$min_expected = 0;
$min_found    = 0;
foreach ( array_keys( $expected ) as $rel ) {
        if ( false !== strpos( $rel, '.min.' ) ) {
                $min_expected++;
                $min_found += isset( $zip_files[ $rel ] ) ? 1 : 0;
        }
}
$checks['G6 minified assets shipped'] = ( $min_expected === $min_found && $min_expected > 0 );
if ( ! $failure && ! $checks['G6 minified assets shipped'] ) {
        $failure = "G6 FAIL — expected {$min_expected} *.min.* files, found {$min_found} in the ZIP.";
}

/* --------------------------------------------------------------------------
 * 5) Report.
 * ----------------------------------------------------------------------- */

@unlink( $tmp );
// rmdir the tree the extraction created.
haai_rrmdir( $tmp );

$size = filesize( $out );
$all  = ! in_array( false, $checks, true );

echo "hami-ai-assistant {$version}\n";
foreach ( $checks as $name => $ok ) {
        echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $name . "\n";
}
echo '  files: ' . count( $zip_files ) . " | min assets: {$min_found} | size: {$size} bytes\n";
echo '  out: ' . $out . "\n";
echo '  sha256: ' . hash_file( 'sha256', $out ) . "\n";

if ( ! $all ) {
        fwrite( STDERR, "\n================ BUILD REFUSED ================\n{$failure}\nNo verified package was emitted.\n===============================================\n" );
        exit( 1 );
}
echo "ALL GATES GREEN — package verified.\n";
exit( 0 );

/**
 * Recursive delete for the verification temp dir.
 *
 * @param string $dir Directory.
 * @return void
 */
function haai_rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
                return;
        }
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
                $f->isDir() ? @rmdir( (string) $f->getPathname() ) : @unlink( (string) $f->getPathname() );
        }
        @rmdir( $dir );
}
