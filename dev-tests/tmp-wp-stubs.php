<?php
/**
 * Throwaway: boots the plugin against stubbed WordPress + a fake $wpdb so the
 * small test scripts (tmp-checks.php) can call real plugin code.
 */

$GLOBALS['opts']   = array();
$GLOBALS['screen'] = (object) array( 'id' => 'settings_page_haai-settings' );
$GLOBALS['saved']  = array();
$GLOBALS['db']     = array( 'topics' => array() );
$GLOBALS['db_next_id'] = 1;
$GLOBALS['chunk_rows'] = array();
$GLOBALS['post_terms']  = array();
$GLOBALS['post_excerpt'] = array();
$GLOBALS['post_content'] = array();
$GLOBALS['post_thumb']   = array();
$GLOBALS['meta_sku']     = array();
$GLOBALS['post_stock']   = array();
$GLOBALS['post_ptype']   = array();
$GLOBALS['lookup_rows']  = array();
$GLOBALS['jobs']         = array();
$GLOBALS['api_calls']    = array();
$GLOBALS['categories']   = array();
$GLOBALS['usage_today']  = 0;
$GLOBALS['cache']        = array();

/** The plugin folder this harness drives: one level above dev-tests/. */
define( 'HAAI_DEV_ROOT', rtrim( dirname( __DIR__ ), '\\/' ) . '/' );

define( 'ABSPATH', __DIR__ . '/wp-stub/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

class Fake_WPDB {
        public $prefix = 'wp_';
        public $posts  = 'wp_posts';
        public $options = 'wp_options';
        public $postmeta = 'wp_postmeta';
        public $insert_id = 0;
        public function get_var( $q = '' ) {
                $q = (string) $q;
                // SHOW TABLES LIKE '<prefix>wc_product_meta_lookup' drives the SKU path.
                if ( preg_match( '/SHOW TABLES LIKE .*wc_product_meta_lookup/i', $q ) ) {
                        return empty( $GLOBALS['lookup_present'] ) ? null : $GLOBALS['wpdb']->prefix . 'wc_product_meta_lookup';
                }
                if ( preg_match( '/SHOW TABLES LIKE .*posts/i', $q ) ) {
                        return $GLOBALS['wpdb']->prefix . 'posts';
                }
                // 2.1.19 — the chunks table exists unless a test forces it missing.
                if ( preg_match( '/SHOW TABLES LIKE .*haai_chunks/i', $q ) ) {
                        return empty( $GLOBALS['chunks_table_missing'] ) ? $GLOBALS['wpdb']->prefix . 'haai_chunks' : null;
                }
                // 2.1.19 — the crawler's stored contact chunk (post_id = -1).
                if ( preg_match( "/SELECT content FROM \S*haai_chunks WHERE post_id = ('?-?\d+'?) AND source = '([^']+)'/i", $q, $m ) ) {
                        $want_id = (int) str_replace( "'", '', $m[1] );
                        $want_sr = stripslashes( (string) $m[2] );
                        foreach ( (array) $GLOBALS['chunk_rows'] as $c ) {
                                if ( (int) $c['post_id'] === $want_id && (string) $c['source'] === $want_sr ) {
                                        return (string) $c['content'];
                                }
                        }
                        return null;
                }
                // Planner: "is this title already proposed?"
                if ( preg_match( '/SELECT id FROM \w+_topics WHERE title/i', $q ) ) {
                        preg_match( "/WHERE title = '([^']*)'/i", $q, $m );
                        $want = isset( $m[1] ) ? stripslashes( $m[1] ) : '';
                        foreach ( (array) $GLOBALS['db']['topics'] as $row ) {
                                if ( (string) $row['title'] === $want ) {
                                        return (int) $row['id'];
                                }
                        }
                        return null;
                }
                // D4: "has this guid / title_hash been seen?" — equality lookups that the
                // news and programmatic modules make before queueing any paid work.
                if ( preg_match( '/SELECT id FROM \w*haai_(news_items|prog_items)/i', $q ) ) {
                        $row = $this->select_row( $q );
                        return $row ? (int) $row['id'] : null;
                }
                // D4: how many rows already produced a post today (news / programmatic caps).
                if ( preg_match( '/SELECT COUNT\(\*\) FROM \w+_(news_items|prog_items)/i', $q ) ) {
                        $key = false !== stripos( $q, 'news_items' ) ? 'news_items' : 'prog_items';
                        $n   = 0;
                        foreach ( (array) ( $GLOBALS['db'][ $key ] ?? array() ) as $row ) {
                                $ok = (int) ( $row['post_id'] ?? 0 ) > 0;
                                if ( $ok && preg_match( "/created >= '([^']*)'/i", $q, $m ) ) {
                                        $ok = (string) ( $row['created'] ?? '' ) >= stripslashes( $m[1] );
                                }
                                if ( $ok ) {
                                        $n++;
                                }
                        }
                        return $n;
                }
                return 0;
        }
        public function get_results( $q = '', $mode = null ) {
                $q = (string) $q;

                // ---- D4 module tables (news_items / prog_items) ----
                if ( preg_match( '/FROM\s+\w*(news_items|prog_items)/i', $q ) ) {
                        $key  = false !== stripos( $q, 'news_items' ) ? 'news_items' : 'prog_items';
                        $rows = (array) ( $GLOBALS['db'][ $key ] ?? array() );

                        if ( preg_match( '/SELECT status, COUNT\(\*\)/i', $q ) ) {
                                $hist = array();
                                foreach ( $rows as $r ) {
                                        $s = (string) ( $r['status'] ?? '' );
                                        $hist[ $s ] = ( isset( $hist[ $s ] ) ? $hist[ $s ] : 0 ) + 1;
                                }
                                $out = array();
                                foreach ( $hist as $s => $n ) {
                                        $out[] = array( 'status' => $s, 'n' => $n );
                                }
                                return $out;
                        }

                        if ( preg_match( "/WHERE status = '([^']*)'/i", $q, $m ) ) {
                                $rows = array_values(
                                        array_filter(
                                                $rows,
                                                function ( $r ) use ( $m ) {
                                                        return (string) ( $r['status'] ?? '' ) === (string) $m[1];
                                                }
                                        )
                                );
                        }

                        if ( preg_match( '/SELECT vars/i', $q ) ) {
                                $out = array();
                                foreach ( $rows as $r ) {
                                        $out[] = array( 'vars' => (string) ( $r['vars'] ?? '' ) );
                                }
                                return $out;
                        }

                        // Newest first, with a LIMIT, matching Haa_*::rows().
                        usort( $rows, function ( $a, $b ) { return (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ); } );
                        if ( preg_match( '/LIMIT (\d+)/i', $q, $m ) ) {
                                $rows = array_slice( $rows, 0, (int) $m[1] );
                        }
                        return $rows;
                }

                if ( preg_match( '/FROM\s+\w*wc_product_meta_lookup/i', $q ) ) {
                        $want = array();
                        if ( preg_match( '/product_id IN \(([^)]*)\)/i', $q, $m ) ) {
                                $want = array_map( 'intval', explode( ',', $m[1] ) );
                        }
                        $out = array();
                        foreach ( (array) $GLOBALS['lookup_rows'] as $r ) {
                                if ( ! $want || in_array( (int) $r['product_id'], $want, true ) ) {
                                        $out[] = $r;
                                }
                        }
                        return $out;
                }
                // Planner status histogram.
                if ( preg_match( '/SELECT status, COUNT\(\*\)/i', $q ) ) {
                        $counts = array();
                        foreach ( (array) $GLOBALS['db']['topics'] as $row ) {
                                $s = (string) $row['status'];
                                $counts[ $s ] = ( isset( $counts[ $s ] ) ? $counts[ $s ] : 0 ) + 1;
                        }
                        $out = array();
                        foreach ( $counts as $s => $c ) {
                                $out[] = array( 'status' => $s, 'c' => $c );
                        }
                        return $out;
                }
                // Planner row listing.
                if ( preg_match( '/SELECT \* FROM \w+_topics/i', $q ) ) {
                        $rows = (array) $GLOBALS['db']['topics'];
                        if ( preg_match( "/WHERE status = '([^']*)'/i", $q, $m ) ) {
                                $want = stripslashes( $m[1] );
                                $rows = array_values( array_filter( $rows, function ( $r ) use ( $want ) {
                                        return (string) $r['status'] === $want;
                                } ) );
                        }
                        usort( $rows, function ( $a, $b ) {
                                return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
                        } );
                        return array_map( function ( $r ) {
                                return array_merge(
                                        array(
                                                'id' => 0, 'title' => '', 'keyword' => '', 'secondary' => '', 'intent' => 'informational',
                                                'priority' => 5, 'reason' => '', 'source' => 'manual', 'status' => 'suggested',
                                                'post_id' => 0, 'job_id' => 0, 'tokens_est' => 0, 'created' => '', 'updated' => '',
                                        ),
                                        $r
                                );
                        }, $rows );
                }
                // Knowledge-base aggregation for the topical map.
                if ( preg_match( '/GROUP BY source/i', $q ) ) {
                        $agg = array();
                        foreach ( (array) $GLOBALS['chunk_rows'] as $c ) {
                                $s = (string) $c['source'];
                                if ( ! isset( $agg[ $s ] ) ) {
                                        $agg[ $s ] = array( 'source' => $s, 'ids' => array(), 'chunks' => 0 );
                                }
                                $agg[ $s ]['ids'][ (int) $c['post_id'] ] = 1;
                                $agg[ $s ]['chunks']++;
                        }
                        $out = array();
                        foreach ( $agg as $row ) {
                                $out[] = array( 'source' => $row['source'], 'items' => count( $row['ids'] ), 'chunks' => $row['chunks'] );
                        }
                        return $out;
                }
                if ( preg_match( '/SELECT post_id, title, source FROM/i', $q ) ) {
                        return array_map(
                                function ( $c ) {
                                        return array( 'post_id' => $c['post_id'], 'title' => $c['title'], 'source' => $c['source'] );
                                },
                                (array) $GLOBALS['chunk_rows']
                        );
                }
                if ( preg_match( '/SELECT DISTINCT title FROM/i', $q ) ) {
                        $out = array();
                        foreach ( (array) $GLOBALS['chunk_rows'] as $c ) {
                                $out[] = $c['title'];
                        }
                        return $out;
                }
                // ---- Knowledge base: BM25 candidates over the chunks table ----
                if ( preg_match( '/FROM\s+\S*haai_chunks\s+c\s+INNER JOIN/i', $q ) && false !== strpos( $q, 'content_norm LIKE' ) ) {
                        $needles = array();
                        if ( preg_match_all( "/LIKE '([^']*)'/i", $q, $m ) ) {
                                foreach ( $m[1] as $n ) {
                                        $needles[] = trim( stripslashes( (string) $n ), '%' );
                                }
                        }
                        $out = array();
                        foreach ( (array) $GLOBALS['chunk_rows'] as $c ) {
                                if ( ! isset( $c['content_norm'] ) ) {
                                        continue; // Seeded metadata rows, not real chunks.
                                }
                                $hay = (string) $c['content_norm'] . ' ' . (string) ( $c['title'] ?? '' );
                                foreach ( $needles as $n ) {
                                        if ( '' !== $n && false !== stripos( $hay, $n ) ) {
                                                $out[] = $c;
                                                break;
                                        }
                                }
                        }
                        return $out;
                }
                // ---- Live wp_posts fallback (RAG without a built index) ----
                // The real query wraps its columns in REPLACE(col, UNHEX('E2808C'), ''):
                // the stub merges ZWNJ out of both sides, exactly like that SQL.
                if ( preg_match( '/FROM\s+\S*posts\s+p\s+WHERE/i', $q ) && false !== strpos( $q, 'post_title' ) && false !== strpos( $q, 'LIKE' ) ) {
                        $types = array( 'post', 'page' );
                        if ( preg_match( '/post_type IN \(([^)]*)\)/i', $q, $tm ) ) {
                                $types = array_map( 'trim', explode( ',', str_replace( "'", '', $tm[1] ) ) );
                        }
                        $needles = array();
                        if ( preg_match_all( "/LIKE '([^']*)'/i", $q, $m ) ) {
                                foreach ( $m[1] as $n ) {
                                        $needles[] = trim( stripslashes( (string) $n ), '%' );
                                }
                        }
                        $merge = function ( $s ) {
                                return str_replace( "\xE2\x80\x8C", '', (string) $s ); // ZWNJ → gone, like the SQL REPLACE.
                        };
                        $out = array();
                        foreach ( (array) $GLOBALS['posts'] as $p ) {
                                $type   = isset( $p['type'] ) ? (string) $p['type'] : 'page';
                                $status = isset( $p['status'] ) ? (string) $p['status'] : 'publish';
                                if ( ! in_array( $type, $types, true ) || 'publish' !== $status ) {
                                        continue;
                                }
                                $body = isset( $GLOBALS['post_content'][ (int) $p['id'] ] ) ? (string) $GLOBALS['post_content'][ (int) $p['id'] ] : '';
                                $tm_  = $merge( $p['title'] );
                                $bm_  = $merge( $body );
                                foreach ( $needles as $n ) {
                                        $n = $merge( $n );
                                        if ( '' !== $n && ( false !== stripos( $tm_, $n ) || false !== stripos( $bm_, $n ) ) ) {
                                                $out[] = array( 'post_id' => (int) $p['id'], 'title' => (string) $p['title'], 'content' => $body );
                                                break;
                                        }
                                }
                        }
                        return $out;
                }
                // ---- Live builder-meta fallback (Elementor copy lives in postmeta) ----
                if ( false !== strpos( $q, '_elementor_data' ) && preg_match( '/FROM\s+\S*posts\s+p\s+INNER\s+JOIN/i', $q ) ) {
                        $types = array( 'post', 'page' );
                        if ( preg_match( '/post_type IN \(([^)]*)\)/i', $q, $tm ) ) {
                                $types = array_map( 'trim', explode( ',', str_replace( "'", '', $tm[1] ) ) );
                        }
                        $needles = array();
                        if ( preg_match_all( "/LIKE '([^']*)'/i", $q, $m ) ) {
                                foreach ( $m[1] as $n ) {
                                        $needles[] = trim( stripslashes( (string) $n ), '%' );
                                }
                        }
                        $merge = function ( $s ) {
                                return str_replace( "\xE2\x80\x8C", '', (string) $s );
                        };
                        $out = array();
                        foreach ( (array) $GLOBALS['posts'] as $p ) {
                                $type   = isset( $p['type'] ) ? (string) $p['type'] : 'page';
                                $status = isset( $p['status'] ) ? (string) $p['status'] : 'publish';
                                if ( ! in_array( $type, $types, true ) || 'publish' !== $status ) {
                                        continue;
                                }
                                $meta = isset( $GLOBALS['post_elementor'][ (int) $p['id'] ] ) ? (string) $GLOBALS['post_elementor'][ (int) $p['id'] ] : '';
                                if ( '' === $meta ) {
                                        continue;
                                }
                                $tm_ = $merge( $p['title'] );
                                $mm_ = $merge( $meta );
                                foreach ( $needles as $n ) {
                                        $n = $merge( $n );
                                        if ( '' !== $n && ( false !== stripos( $tm_, $n ) || false !== stripos( $mm_, $n ) ) ) {
                                                // The raw JSON travels back — the plugin extracts the copy.
                                                $out[] = array( 'post_id' => (int) $p['id'], 'title' => (string) $p['title'], 'content' => $meta );
                                                break;
                                        }
                                }
                        }
                        return $out;
                }
                return array();
        }
        public function get_col( $q = '' ) {
                $q = (string) $q;
                // SKU lookup.
                if ( preg_match( '/SELECT product_id FROM \w*wc_product_meta_lookup WHERE sku LIKE/i', $q ) ) {
                        preg_match( "/LIKE '([^']*)'/i", $q, $m );
                        $needle = trim( $m[1] ?? '', '%' );
                        $out    = array();
                        foreach ( (array) $GLOBALS['lookup_rows'] as $r ) {
                                if ( '' === $needle || false !== stripos( (string) $r['sku'], $needle ) ) {
                                        $out[] = (int) $r['product_id'];
                                }
                        }
                        return $out;
                }
                // Site-profile draft builder reads indexed titles through get_col().
                if ( preg_match( '/SELECT DISTINCT title FROM/i', $q ) ) {
                        $out = array();
                        foreach ( (array) $GLOBALS['chunk_rows'] as $c ) {
                                $out[] = $c['title'];
                        }
                        return $out;
                }
                // Content-state pool: which posts have an empty column / no thumbnail.
                if ( preg_match( '/SELECT \S*\.ID FROM \S*posts/i', $q ) ) {
                        $out = array();
                        foreach ( (array) $GLOBALS['posts'] as $p ) {
                                if ( isset( $p['type'] ) && 'product' !== $p['type'] ) {
                                        continue;
                                }
                                $id   = (int) $p['id'];
                                $keep = true;
                                if ( preg_match( '/post_excerpt/i', $q ) ) {
                                        $keep = $keep && empty( $GLOBALS['post_excerpt'][ $id ] );
                                }
                                if ( preg_match( '/post_content/i', $q ) ) {
                                        $keep = $keep && empty( $GLOBALS['post_content'][ $id ] );
                                }
                                if ( preg_match( '/_thumbnail_id/i', $q ) ) {
                                        $keep = $keep && empty( $GLOBALS['post_thumb'][ $id ] );
                                }
                                if ( $keep ) {
                                        $out[] = $id;
                                }
                        }
                        return $out;
                }
                return array();
        }
        public function get_row( $q = '', $m = null ) {
                return $this->select_row( $q );
        }

        /* ---- minimal in-memory store, only for the patterns Haa_Planner emits ---- */

        /** @return array A row, or null. */
        private function select_row( $q ) {
                $q    = (string) $q;
                $rows = $this->rows_for( $q );

                // Equality lookups the D4 modules make: guid / title_hash / item_key / id.
                // 2.1.22 — cache_key joins the list: Haa_DB::get_cached() reads the
                // answer cache through this path (expires is written far-future by
                // the suites, so the WHERE clause's time comparison is not under test).
                foreach ( array( 'id', 'guid', 'title_hash', 'item_key', 'cache_key' ) as $col ) {
                        if ( preg_match( "/WHERE $col = '?([^']*)'?/i", $q, $m ) ) {
                                $want = stripslashes( (string) $m[1] );
                                foreach ( $rows as $r ) {
                                        if ( 'id' === $col ? (int) ( $r['id'] ?? 0 ) === (int) $want : (string) ( $r[ $col ] ?? '' ) === $want ) {
                                                return $r;
                                        }
                                }
                                return null;
                        }
                }
                return null;
        }

        /** In-memory rows for a query's table. @return array */
        private function rows_for( $q ) {
                $key = $this->key_for( $q );
                if ( 'chunk_rows' === $key ) {
                        return (array) $GLOBALS['chunk_rows'];
                }
                return isset( $GLOBALS['db'][ $key ] ) ? (array) $GLOBALS['db'][ $key ] : array();
        }

        /** @return void */
        private function set_rows( $q, $rows ) {
                $key = $this->key_for( $q );
                if ( 'chunk_rows' === $key ) {
                        $GLOBALS['chunk_rows'] = (array) $rows;
                        return;
                }
                $GLOBALS['db'][ $key ] = (array) $rows;
        }

        /** Which in-memory table a query or table name refers to. @return string */
        private function key_for( $name ) {
                $name = (string) $name;
                if ( false !== strpos( $name, 'news_items' ) ) { return 'news_items'; }
                if ( false !== strpos( $name, 'prog_items' ) )  { return 'prog_items'; }
                if ( false !== strpos( $name, 'haai_chunks' ) ) { return 'chunk_rows'; }
                if ( false !== strpos( $name, 'haai_cache' ) )  { return 'cache_rows'; }
                return 'topics';
        }

        public function insert( $table, $data, $fmt = array() ) {
                $rows   = $this->rows_for( $table );
                $GLOBALS['db_next_id'] = ( isset( $GLOBALS['db_next_id'] ) ? $GLOBALS['db_next_id'] : 1 );
                $row   = array_merge( array( 'id' => $GLOBALS['db_next_id']++ ), (array) $data );
                $rows[] = $row;
                $this->set_rows( $table, $rows );
                $this->insert_id = (int) $row['id'];
                return 1;
        }

        /**
         * 2.1.22 — the chat answer cache (Haa_DB::set_cached) uses REPLACE.
         * Same in-memory semantics as INSERT here: one more row in the table's
         * store; the flush test reads through get_row() so the duplicate-row
         * difference never matters (first match wins, same as LIMIT 1).
         */
        public function replace( $table, $data, $fmt = array() ) {
                return $this->insert( $table, $data, $fmt );
        }

        public function update( $table, $data, $where, $fm = array(), $wm = array() ) {
                $id   = isset( $where['id'] ) ? (int) $where['id'] : 0;
                $rows = $this->rows_for( $table );
                foreach ( $rows as $i => $row ) {
                        if ( (int) $row['id'] === $id ) {
                                $rows[ $i ] = array_merge( (array) $row, (array) $data );
                                $this->set_rows( $table, $rows );
                                return 1;
                        }
                }
                return 0;
        }

        public function delete( $table, $where, $wm = array() ) {
                // remove_post() deletes chunks by post_id, not by row id.
                if ( isset( $where['post_id'] ) ) {
                        $pid     = (int) $where['post_id'];
                        $rows    = $this->rows_for( $table );
                        $kept    = array();
                        $removed = 0;
                        foreach ( $rows as $row ) {
                                if ( (int) ( $row['post_id'] ?? 0 ) === $pid ) { $removed++; continue; }
                                $kept[] = $row;
                        }
                        $this->set_rows( $table, $kept );
                        return $removed;
                }
                $id      = (int) $where['id'];
                $rows    = $this->rows_for( $table );
                $kept    = array();
                $removed = 0;
                foreach ( $rows as $row ) {
                        if ( (int) $row['id'] === $id ) { $removed++; continue; }
                        $kept[] = $row;
                }
                $this->set_rows( $table, $kept );
                return $removed;
        }
        public function prepare( $q, ...$a ) {
                // Real WP unpacks a single array argument into the placeholder list
                // (wpdb::prepare legacy style: prepare($sql, $params_array)).
                if ( 1 === count( $a ) && is_array( $a[0] ) ) {
                        $a = $a[0];
                }
                // Positional, single pass: a naive per-argument preg_replace would match
                // the "%s" inside a value such as '%shoes-v%' and corrupt the query.
                $i   = 0;
                $out = preg_replace_callback(
                        '/%(?:%|(_[a-z]+)?[ds])/',
                        function ( $m ) use ( &$a, &$i ) {
                                if ( '%%' === $m[0] ) {
                                        return '%';
                                }
                                if ( ! array_key_exists( $i, $a ) ) {
                                        return $m[0];
                                }
                                $v = $a[ $i++ ];
                                $v = is_array( $v ) ? '' : (string) $v;
                                return "'" . addslashes( $v ) . "'";
                        },
                        $q
                );
                return $out;
        }
        public function esc_like( $s ) { return addslashes( (string) $s ); }
        // Haa_DB::create() asks for this before handing SQL to dbDelta().
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        public function query( $q ) {
                $q = (string) $q;
                // Haa_Crawl::wipe() deletes crawl slots wholesale.
                if ( preg_match( '/DELETE\s+.*?haai_chunks.*?WHERE\s+post_id\s+<\s*0/is', $q ) ) {
                        $kept    = array();
                        $removed = 0;
                        foreach ( (array) $GLOBALS['chunk_rows'] as $row ) {
                                if ( (int) ( $row['post_id'] ?? 0 ) < 0 ) { $removed++; continue; }
                                $kept[] = $row;
                        }
                        $GLOBALS['chunk_rows'] = $kept;
                        return $removed;
                }
                // 2.1.22 — Haa_DB::flush_cache() truncates the answer cache; the
                // finish-of-crawl flush is exactly what suite 33 asserts on.
                if ( preg_match( '/TRUNCATE(?:\s+TABLE)?\s+\S*haai_cache/i', $q ) ) {
                        $n    = count( (array) ( $GLOBALS['db']['cache_rows'] ?? array() ) );
                        $GLOBALS['db']['cache_rows'] = array();
                        return $n;
                }
                return 0;
        }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

final class Haa_Usage {
        public static function summary( $d ) { return array( 'tokens' => 0, 'messages' => 0 ); }
        public static function today() { return array( 'tokens' => 0, 'messages' => 0 ); }
        public static function autopilot_posts_today() { return 0; }
        /**
         * The real transport logs one usage row per call; record them so a
         * suite can assert on what the plugin logged without touching SQL.
         */
        public static function log( $data ) {
                $GLOBALS['usage_log'][] = $data;
                return true;
        }
        public static function generated_posts_today() {
                // Same deal as tokens_today(): the SQL is not what is under test here,
                // the cap arithmetic in Haa_Planner::room_today() is.
                return isset( $GLOBALS['generated_today'] ) ? (int) $GLOBALS['generated_today'] : 0;
        }
        public static function tokens_today() {
                // Tests drive this directly: a real SUM() over the usage table would
                // only re-test MySQL, not the cap logic.
                return isset( $GLOBALS['usage_today'] ) ? (int) $GLOBALS['usage_today'] : 0;
        }
        public static function site_day_start_utc() {
                // The D4 modules ask "what happened since the site's midnight?" — the
                // arithmetic is what matters, so it is computed here rather than stubbed.
                $hour = isset( $GLOBALS['site_start_of_day'] ) ? (int) $GLOBALS['site_start_of_day'] : 4;
                return gmdate( 'Y-m-d H:i:s', strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00' ) - $hour * 3600 );
        }
        public static function seconds_until_site_day_end() {
                return 3600;
        }
        public static function site_day_key() {
                return (string) gmdate( 'Ymd' );
        }
}
final class Haa_Queue {
        public static function stats() { return array( 'pending' => 0, 'running' => 0, 'failed' => 0, 'done' => 0 ); }
        public static function pending( $m = null ) { return 0; }
        /** Records what a batch queued instead of touching a table. */
        public static function push( $job, $payload = array(), $args = array() ) {
                $GLOBALS['jobs'][] = array( 'job' => $job, 'payload' => $payload, 'args' => $args );
                return count( $GLOBALS['jobs'] );
        }
}

/**
 * Transport fake only.
 *
 * Deliberately the thinnest possible layer: Haa_Content, Haa_Planner and the
 * modules all run as the real code, so a test that asserts on $GLOBALS['api_calls']
 * is asserting on the prompt the plugin actually assembled — profile block,
 * anti-fabrication clause and all. Faking Haa_Content itself would have hidden
 * exactly the things D1 changes.
 *
 * Statics let a test script the model's answer: reply, or force a failure.
 *
 * A suite that must exercise the REAL transport (failover walk, cooldown, the
 * actual adapters against the fake HTTP layer) sets
 * $GLOBALS['haai_use_real_api'] = true BEFORE requiring this file: the real
 * class-haa-api.php is required in its place and the fake below is skipped.
 */
if ( ! empty( $GLOBALS['haai_use_real_api'] ) ) {
        require_once dirname( __DIR__ ) . '/includes/class-haa-api.php';
}

if ( ! class_exists( 'Haa_API' ) ) {
final class Haa_API {
        /** @var string Canned model text. */
        public static $reply = '';
        /** @var bool When true, chat() returns a WP_Error like a real transport failure. */
        public static $fail = false;

        /**
         * The real Haa_API reports whether the last reply hit max_tokens; the code
         * under test reads this, so the fake has to answer it too. A suite can flip
         * it with $GLOBALS['api_truncated'] = true.
         *
         * @return bool
         */
        public static function last_truncated() {
                return ! empty( $GLOBALS['api_truncated'] );
        }

        public function chat( $messages, $opts = array() ) {
                $GLOBALS['api_calls'][] = array( 'messages' => $messages, 'opts' => $opts );

                if ( self::$fail ) {
                        return new WP_Error( 'haai_http', 'transport down' );
                }

                // A staged run needs a *different* answer per call: outline, then one
                // section each, then meta. Queue wins over the single canned reply.
                if ( ! empty( $GLOBALS['api_replies'] ) ) {
                        $next = array_shift( $GLOBALS['api_replies'] );
                        if ( is_array( $next ) && isset( $next['error'] ) ) {
                                return new WP_Error( 'haai_http', (string) $next['error'] );
                        }
                        return (string) $next;
                }

                return self::$reply;
        }

        public function embed( $texts, $opts = array() ) { return array(); }
        public function test_connection() { return array( 'ok' => true, 'message' => '', 'latency' => 1 ); }
        public function models() { return array(); }

        /**
         * Mirror of the real Haa_API::proxy_args(): with no proxy configured the
         * args pass through untouched, which is the only behaviour the research
         * suites rely on. Kept in step so call sites never meet a missing method.
         *
         * @param array     $args wp_remote_* args.
         * @param array|null $cfg  Provider config; ignored here.
         * @return array
         */
        public static function proxy_args( $args, $cfg = null ) { return $args; }

        /**
         * Mirror of the real Haa_API::is_url_safe() decision.
         *
         * The real class is replaced by this fake so chat() can be canned, which
         * means research code has somewhere to call the guard. What the D2 tests
         * assert is the *call site*: that Haa_Research asks before it fetches, and
         * that a refused host produces zero HTTP requests. The guard's own internals
         * are exercised by the real file, not by this copy — a copy can drift, so
         * tmp-checks7.php calls mirror_matches_real_guard() to prove the real
         * implementation still contains the same decision surface.
         *
         * @param string $url Target URL.
         * @return true|WP_Error
         */
        public static function is_url_safe( $url ) {
                $parsed = parse_url( (string) $url );
                if ( ! $parsed || empty( $parsed['host'] ) ) {
                        return new WP_Error( 'haai_ssrf', 'آدرس سرویس نامعتبر است.' );
                }

                $host        = strtolower( $parsed['host'] );
                $allow_local = (bool) Haa_Settings::get( 'provider.allow_localhost', 0 );

                // Mirrors the real guard: an IPv6 literal arrives bracketed from
                // parse_url, and ::ffff:a.b.c.d is an IPv4 address wearing IPv6 clothes.
                if ( 0 === strpos( $host, '[' ) && ']' === substr( $host, -1 ) ) {
                        $host = substr( $host, 1, -1 );
                }
                if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $host, $mm ) ) {
                        $host = $mm[1];
                }

                if ( preg_match( '/^(localhost|127\.|::1|0\.0\.0\.0)/i', $host ) ) {
                        return $allow_local ? true : new WP_Error( 'haai_ssrf', 'آدرس محلی مجاز نیست.' );
                }

                // Numeric IPv4 spellings (2130706433, 0x7f000001, 0177.0.0.1) are refused
                // by the real guard: cURL resolves them, no DNS name looks like them.
                if ( preg_match( '/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){1,3}$/i', $host )
                        && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        return new WP_Error( 'haai_ssrf', 'نشانه‌ی عددی IP مجاز نیست.' );
                }
                if ( preg_match( '/^\d+$/', $host ) || preg_match( '/^0x[0-9a-f]+$/i', $host ) ) {
                        return new WP_Error( 'haai_ssrf', 'نشانه‌ی عددی IP مجاز نیست.' );
                }

                // IPv6 ranges the real guard refuses regardless of the dev flag.
                if ( preg_match( '/^(fe80|fc|fd)/i', $host ) ) {
                        return new WP_Error( 'haai_ssrf', 'آدرس IPv6 خصوصی/link-local مجاز نیست.' );
                }

                if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
                        if ( ! $allow_local && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                                return new WP_Error( 'haai_ssrf', 'آدرس سرویس به بازه IP خصوصی اشاره دارد و مجاز نیست.' );
                        }
                        // Mirrors the real always-refused link-local rule.
                        return preg_match( '/^169\.254\.|^0\./', $host )
                                ? new WP_Error( 'haai_ssrf', 'آدرس link-local همیشه مسدود است.' )
                                : true;
                }

                // Named host: resolve without touching the network in tests.
                $ip = isset( $GLOBALS['dns'][$host] ) ? $GLOBALS['dns'][$host] : $host;
                if ( $ip === $host ) {
                        return true; // Unresolvable: real guard lets the HTTP layer report it.
                }
                if ( $allow_local ) {
                        return preg_match( '/^169\.254\.|^0\./', $ip )
                                ? new WP_Error( 'haai_ssrf', 'آدرس link-local همیشه مسدود است.' )
                                : true;
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                        return new WP_Error( 'haai_ssrf', 'آدرس سرویس به بازه IP خصوصی اشاره دارد و مجاز نیست.' );
                }
                return true;
        }

        /**
         * Consistency guard for the mirror above: the real file must keep the same
         * decision surface, or a future edit silently changes what tests prove.
         *
         * Each marker below is load-bearing for one class of SSRF bypass: bracket
         * stripping (IPv6 literals from parse_url), the numeric-host decoder
         * (decimal/hex/octal IPv4), the always-refused range helper, and the
         * private-range filter with the dev flag that alone may open it.
         *
         * @return bool True when the real implementation still matches.
         */
        public static function mirror_matches_real_guard() {
                $file = dirname( __DIR__ ) . '/includes/class-haa-api.php';
                if ( ! is_readable( $file ) ) {
                        return false;
                }
                $src = (string) file_get_contents( $file );

                return false !== strpos( $src, "0 === strpos( \$host, '[' )" )
                        && false !== strpos( $src, 'decode_numeric_host' )
                        && false !== strpos( $src, 'ip_is_always_forbidden' )
                        && false !== strpos( $src, 'ipv4_mapped' )
                        && false !== strpos( $src, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE' )
                        && false !== strpos( $src, 'provider.allow_localhost' )
                        && false !== strpos( $src, "/^(169\\.254\\.|0\\.)/" );
        }

        public static function presets() {
                return array(
                        'openai' => array( 'label' => 'OpenAI', 'adapter' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini' ),
                        'groq'   => array( 'label' => 'Groq', 'adapter' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile' ),
                        'gemini' => array( 'label' => 'Gemini', 'adapter' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-1.5-flash' ),
                );
        }

        /**
         * Mirror of the real adapters() map — Haa_Settings::resolve_failover_rows()
         * reads it on every provider() call, so the fake must answer it too.
         */
        public static function adapters() {
                return array( 'openai' => 'OpenAI-compatible', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude' );
        }
}
}

/** Image and SEO side effects are recorded, not performed. */
final class Haa_Images {
        public static function product_prompt( $name, $cat ) { return $name . ' / ' . $cat; }
        public static function generate( $prompt, $opts = array() ) {
                if ( false !== strpos( (string) $prompt, 'no-image' ) ) {
                        return array( 'ok' => false, 'error' => 'no image' );
                }
                $GLOBALS['images_made'] = ( isset( $GLOBALS['images_made'] ) ? $GLOBALS['images_made'] : 0 ) + 1;
                return array( 'ok' => true, 'attachment_id' => 7 );
        }
}

/*
 * Haa_Seo is NOT faked: it only touches wp_update_post / update_post_meta /
 * wp_kses, all of which this harness now provides, and D4 writes the noindex
 * marker through it — so faking it would hide the code under test.
 */

/**
 * NOTE: Haa_Content and Haa_Search are deliberately NOT faked here.
 *
 * Haa_Search owns the Persian tokenizer that the planner's duplicate detection
 * and the site profile's relevance selection both depend on; shadowing it with a
 * stats-only stub (as an earlier draft did) made those tests assert against a
 * fake tokenizer instead of the real one. Its stats() / rebuild_progress() read
 * through the Fake_WPDB answers above and come back empty, which is what the
 * dashboard and semantic-search screens are checked against.
 */

function plugin_dir_path( $f ) { return HAAI_DEV_ROOT; }
function plugin_dir_url( $f )  { return 'http://local.test/wp-content/plugins/hami-ai-assistant/'; }
function plugin_basename( $f ) { return 'hami-ai-assistant/hami-ai-assistant.php'; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks']['filter'][ $h ][] = $cb; return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks']['action'][ $h ][] = $cb; return true; }
function remove_action( $h, $cb, $p = 10 ) { return true; }
function do_action( $h ) {}
function add_shortcode( $tag, $cb ) { $GLOBALS['hooks']['shortcode'][ $tag ] = $cb; return true; }
function remove_shortcode( $tag ) { return true; }
function has_shortcode( $c, $t ) { return false !== strpos( (string) $c, '[' . $t ); }
function apply_filters( $h, $v, ...$args ) {
        if ( empty( $GLOBALS['hooks']['filter'][ $h ] ) ) {
                return $v;
        }
        foreach ( $GLOBALS['hooks']['filter'][ $h ] as $cb ) {
                if ( ! is_callable( $cb ) ) {
                        continue;
                }
                $v = call_user_func_array( $cb, array_merge( array( $v ), $args ) );
        }
        return $v;
}
function has_action( $h ) { return ! empty( $GLOBALS['hooks']['action'][ $h ] ); }
function remove_filter( $h, $cb ) { return true; }
function get_transient_type() { return 'yes'; }
function load_plugin_textdomain( $d, $x, $p ) {}
function get_current_screen() { return $GLOBALS['screen']; }

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opts'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = 'yes' ) { $GLOBALS['opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['opts'][ $n ] ); return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['saved'] ) ? $GLOBALS['saved'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['saved'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['saved'][ $k ] ); return true; }

function __( $t, $d = 'default' ) { return $t; }
function _e( $t, $d = 'default' ) { echo $t; }
function _x( $t, $c, $d = 'default' ) { return $t; }
function esc_html__( $t, $d = 'default' ) { return $t; }
function esc_html_e( $t, $d = 'default' ) { echo $t; }
function esc_attr_e( $t, $d = 'default' ) { echo htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = 'default' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return $t; }
function esc_url_raw( $u, $p = null ) { return $u; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function sanitize_textarea_field( $t ) { return trim( (string) $t ); }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ) ?? ''; }
function sanitize_email( $t ) { return $t; }
function sanitize_html_class( $t ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $t ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }

/** @return int */
function wp_is_post_revision( $id ) { return 0; }

/** @return int */
function wp_is_post_autosave( $id ) { return 0; }

/** @param string $t @return string */
function strip_shortcodes( $t ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $t ); }
function wp_kses_post( $t ) { return $t; }
function wp_kses( $t, $a ) { return $t; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_parse_args( $a, $d ) { return array_merge( (array) $d, (array) $a ); }
function wp_html_excerpt( $t, $n ) { return substr( (string) $t, 0, $n ); }
function wp_is_mobile() { return ! empty( $GLOBALS['is_mobile'] ); }
function wp_salt( $s ) { return 'salt-' . $s; }
function wp_get_attachment_url( $id ) { return ''; }
function wp_get_attachment_image( $id, $s = '', $x = false, $a = array() ) { return '<img src="" alt="">'; }
// 2.1.21 — seedable cron: $GLOBALS['crons'][hook] = next-run timestamp; actions
// recorded in $GLOBALS['cron_fired'][] so suites can assert start()/cancel()
// scheduling and that batch_cron() actually drives.
$GLOBALS['crons']      = array();
$GLOBALS['cron_fired'] = array();
function wp_next_scheduled( $h ) { return empty( $GLOBALS['crons'][ $h ] ) ? false : (int) $GLOBALS['crons'][ $h ]; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['crons'][ $h ] = (int) $t; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['crons'][ $h ] ); }
function haai_stub_fire_cron( $h ) { if ( isset( $GLOBALS['crons'][ $h ] ) ) { $GLOBALS['cron_fired'][] = $h; return call_user_func( array( 'Haa_Crawl', 'batch_cron' ) ); } return false; }
function wp_register_style( ...$a ) { return true; }
function wp_enqueue_style( ...$a ) { return true; }
function wp_enqueue_script( ...$a ) { return true; }
function wp_add_inline_style( $h, $css ) { $GLOBALS['inline_css'][ $h ] = $css; return true; }
function wp_add_inline_script( ...$a ) { return true; }
function wp_scripts() { return null; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { echo '<input type="hidden" name="' . $n . '" value="nonce" />'; }
function wp_nonce_url( $u, $a ) { return $u; }
function check_admin_referer( $a, $q = '_wpnonce' ) { return true; }
function check_ajax_referer( $a, $q = '_wpnonce', $die = true ) { return true; }
function wp_create_nonce( $a ) { return 'nonce'; }
function wp_verify_nonce( $n, $a ) { return 1; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; return true; }
function add_submenu_page( ...$a ) { $GLOBALS['hooks']['submenu'][] = $a; return 'page'; }
function add_menu_page( ...$a ) { $GLOBALS['hooks']['menu'][] = $a; return 'page'; }

/* ---- object cache ---- */
function wp_cache_get( $k, $g = '' ) { return isset( $GLOBALS['cache'][ $g ][ $k ] ) ? $GLOBALS['cache'][ $g ][ $k ] : false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { $GLOBALS['cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache'][ $g ][ $k ] ); return true; }

/* ---- post / term readers the picker needs ---- */
function taxonomy_exists( $t ) { return in_array( $t, array( 'product_cat', 'product_type', 'category', 'post_tag' ), true ); }
function wp_list_pluck( $list, $field ) {
        $out = array();
        foreach ( (array) $list as $row ) {
                $out[] = is_object( $row ) ? ( isset( $row->$field ) ? $row->$field : null ) : ( isset( $row[ $field ] ) ? $row[ $field ] : null );
        }
        return $out;
}
function get_post_status( $id = 0 ) {
        foreach ( (array) $GLOBALS['posts'] as $p ) {
                if ( (int) $p['id'] === (int) $id ) { return isset( $p['status'] ) ? $p['status'] : 'publish'; }
        }
        return false;
}
function get_post_field( $field, $id = 0 ) {
        $id = (int) $id;
        if ( 'post_excerpt' === $field ) { return isset( $GLOBALS['post_excerpt'][ $id ] ) ? $GLOBALS['post_excerpt'][ $id ] : ''; }
        if ( 'post_content' === $field ) { return isset( $GLOBALS['post_content'][ $id ] ) ? $GLOBALS['post_content'][ $id ] : ''; }
        foreach ( (array) $GLOBALS['posts'] as $p ) {
                if ( (int) $p['id'] === $id ) {
                        if ( 'post_title' === $field ) { return $p['title']; }
                        if ( 'post_type' === $field ) { return isset( $p['type'] ) ? $p['type'] : 'page'; }
                }
        }
        return '';
}
function get_post_thumbnail_id( $id = 0 ) { return isset( $GLOBALS['post_thumb'][ (int) $id ] ) ? (int) $GLOBALS['post_thumb'][ (int) $id ] : 0; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return $id ? 'http://local.test/uploads/img-' . (int) $id . '.jpg' : ''; }
function wp_get_object_terms( $ids, $taxonomies, $args = array() ) { return array(); }
function get_post_meta( $id, $k, $single = false ) {
        $id = (int) $id;
        if ( '_sku' === $k ) { return isset( $GLOBALS['meta_sku'][ $id ] ) ? $GLOBALS['meta_sku'][ $id ] : ''; }
        if ( '_stock_status' === $k ) { return isset( $GLOBALS['post_stock'][ $id ] ) ? $GLOBALS['post_stock'][ $id ] : ''; }
        // Everything else reads the real in-memory store written by update_post_meta,
        // so the D3 pipeline (gates / flags / backlinks) round-trips like production.
        $store = isset( $GLOBALS['post_meta'] ) ? (array) $GLOBALS['post_meta'] : array();
        return isset( $store[ $id ][ $k ] ) ? $store[ $id ][ $k ] : ( $single ? '' : array() );
}
function wp_safe_redirect( $u, $s = 302 ) { $GLOBALS['redirect'] = $u; return true; }
function rest_url( $p = '' ) { return 'http://local.test/wp-json/' . $p; }
function admin_url( $p = '' ) { return 'http://local.test/wp-admin/' . $p; }
function get_bloginfo( $k ) { return 'Hami Shop'; }
// One live clock for the whole harness: a hardcoded date here made rows written
// "now" older than the live-clock day boundary / TTLs one day later, so daily
// caps and snapshot freshness silently stopped counting anything.
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function number_format_i18n( $n ) { return number_format( (int) $n ); }
function mysql2date( $f, $d ) { return (string) $d; }
function url_to_postid( $u ) {
        // Per-URL map first: the refresh tests need "this link resolves to nothing"
        // and "that link resolves to a trashed post" at the same time.
        if ( ! empty( $GLOBALS['url2post_map'] ) ) {
                foreach ( (array) $GLOBALS['url2post_map'] as $pattern => $id ) {
                        if ( false !== strpos( (string) $u, (string) $pattern ) ) {
                                return (int) $id;
                        }
                }
        }
        return empty( $GLOBALS['url2post'] ) ? 0 : (int) $GLOBALS['url2post'];
}
function is_admin() { return true; }
function is_user_logged_in() { return empty( $GLOBALS['logged_out'] ); }
function is_singular( $t = '' ) { return ! empty( $GLOBALS['q']['singular'] ); }
function is_front_page() { return ! empty( $GLOBALS['q']['front'] ); }
function is_home() { return ! empty( $GLOBALS['q']['home'] ); }
function is_archive() { return ! empty( $GLOBALS['q']['archive'] ); }
function is_search() { return ! empty( $GLOBALS['q']['search'] ); }
function is_404() { return ! empty( $GLOBALS['q']['404'] ); }
function is_product() { return ! empty( $GLOBALS['q']['product'] ); }
function is_shop() { return ! empty( $GLOBALS['q']['shop'] ); }
function is_cart() { return ! empty( $GLOBALS['q']['cart'] ); }
function is_checkout() { return ! empty( $GLOBALS['q']['checkout'] ); }
function is_account_page() { return ! empty( $GLOBALS['q']['account'] ); }
function shortcode_atts( $d, $a, $s = '' ) { return array_merge( (array) $d, (array) $a ); }
function add_query_arg( ...$a ) {
        if ( is_array( $a[0] ) ) {
                $base = isset( $a[1] ) ? $a[1] : '';
                $sep  = false === strpos( $base, '?' ) ? '?' : '&';
                return $base . $sep . http_build_query( $a[0], '', '&', PHP_QUERY_RFC3986 );
        }
        $base = isset( $a[2] ) ? $a[2] : '';
        $sep  = false === strpos( $base, '?' ) ? '?' : '&';
        return $base . $sep . $a[0] . '=' . rawurlencode( (string) $a[1] );
}
function remove_query_arg( $k, $u = '' ) { return $u; }
function get_post_type( $id = 0 ) { return isset( $GLOBALS['pt'][ (int) $id ] ) ? $GLOBALS['pt'][ (int) $id ] : 'page'; }
function get_queried_object_id() { return isset( $GLOBALS['q']['id'] ) ? (int) $GLOBALS['q']['id'] : 0; }
function wp_get_current_user() { $u = new stdClass(); $u->roles = isset( $GLOBALS['roles'] ) ? $GLOBALS['roles'] : array(); $u->ID = 0; return $u; }
function current_user_can( $c ) { return true; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function checked( $c, $x = true, $e = true ) { $r = ( (string) $c === (string) $x ) ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $c, $x = true, $e = true ) { $r = ( (string) $c === (string) $x ) ? ' disabled="disabled"' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $c, $x = true, $e = true ) { return checked( $c, $x, $e ); }
function wpautop( $t ) { return '<p>' . $t . '</p>'; }
function get_post_types( $a = array(), $o = 'names' ) {
        $all = array( 'post', 'page', 'product', 'elementor_library', 'e-floating-button', 'e-slide', 'wp_block', 'wp_font_family', 'nav_menu_item', 'wpcfy_feed' );
        $keep = array( 'post', 'page', 'product', 'elementor_library', 'e-floating-button', 'e-slide', 'wp_block', 'wp_font_family', 'nav_menu_item', 'wpcfy_feed' );

        // publicly_queryable is false for the builder internals, as in real installs.
        $queryable = array( 'post', 'page', 'product', 'elementor_library' );

        $out = array();
        foreach ( $keep as $name ) {
                if ( 'names' === $o ) {
                        if ( empty( $a['publicly_queryable'] ) || in_array( $name, $queryable, true ) ) {
                                $out[] = $name;
                        }
                        continue;
                }
                if ( ! empty( $a['publicly_queryable'] ) && ! in_array( $name, $queryable, true ) ) {
                        continue;
                }
                $pt = new stdClass();
                $pt->name = $name;
                // Real installs always carry `public`; builder internals are not public.
                $pt->public = in_array( $name, $queryable, true ) ? true : false;
                $pt->labels = new stdClass();
                $pt->labels->singular_name = ucfirst( $name );
                $out[ $name ] = $pt;
        }
        return $out;
}
function get_post_type_object( $name ) {
        $public = array( 'post', 'page', 'product', 'elementor_library' );
        $pt = new stdClass();
        $pt->name = $name;
        $pt->public = in_array( (string) $name, $public, true );
        $pt->labels = new stdClass();
        $pt->labels->singular_name = ucfirst( (string) $name );
        return $pt;
}
function get_the_title( $id = 0 ) {
        if ( is_object( $id ) ) { return $id->post_title; }
        // Prefer the live test-post array so tmp-checks3 product ids resolve.
        if ( isset( $GLOBALS['posts'] ) ) {
                foreach ( (array) $GLOBALS['posts'] as $p ) {
                        if ( (int) $p['id'] === (int) $id ) { return (string) $p['title']; }
                }
        }
        $titles = array( 2 => 'درباره ما', 7 => 'تماس با ما', 12 => 'صفحه نخست', 55 => 'کفش چرم' );
        return isset( $titles[ (int) $id ] ) ? $titles[ (int) $id ] : 'برگه ' . (int) $id;
}
function wp_generate_attachment_metadata( $id, $f ) { return array(); }
function get_categories( $a = array() ) {
        $out = array();
        foreach ( (array) $GLOBALS['categories'] as $c ) {
                $out[] = (object) $c;
        }
        return $out;
}
function get_terms( $a = array() ) {
        if ( isset( $a['taxonomy'] ) && 'product_cat' === $a['taxonomy'] && isset( $GLOBALS['product_cats'] ) ) {
                $rows = (array) $GLOBALS['product_cats'];
                if ( ! empty( $a['hide_empty'] ) ) {
                        // Real WP semantics: terms with zero published posts stay hidden.
                        $rows = array_values( array_filter( $rows, function ( $t ) {
                                return (int) ( is_object( $t ) ? ( $t->count ?? 0 ) : ( $t['count'] ?? 0 ) ) > 0;
                        } ) );
                }
                return $rows;
        }
        return array();
}
if ( ! function_exists( 'WC' ) ) {
        /** Marker so Haa_Util::woo() sees a live WooCommerce install. */
        function WC() { return new WooCommerce(); }
}
function get_pages( $a = array() ) { return array(); }
function get_posts( $a = array() ) { return stub_map_rows( stub_filter_posts( $a ), $a ); }

/**
 * Shared row filter behind the get_posts() and WP_Query() stubs.
 *
 * Implements exactly the argument subset the product picker builds, so the
 * tests exercise the real merge/resolve logic rather than a fixed answer.
 *
 * @param array $a Query args.
 * @return array<int,array> Raw seeded rows.
 */
function stub_filter_posts( $a ) {
        $all = isset( $GLOBALS['posts'] ) ? (array) $GLOBALS['posts'] : array();

        // meta_key: only posts that actually carry that meta, read from the real
        // in-memory meta store. Without this, "find generated posts to refresh"
        // would return every seeded post and the selection logic goes untested.
        if ( ! empty( $a['meta_key'] ) && ! isset( $a['meta_compare'] ) ) {
                $key  = (string) $a['meta_key'];
                $want = isset( $a['meta_value'] ) ? (string) $a['meta_value'] : null;
                $all  = array_values(
                        array_filter(
                                $all,
                                function ( $p ) use ( $key, $want ) {
                                        $store = isset( $GLOBALS['post_meta'][ (int) $p['id'] ] ) ? (array) $GLOBALS['post_meta'][ (int) $p['id'] ] : array();
                                        if ( ! array_key_exists( $key, $store ) ) {
                                                return false;
                                        }
                                        return null === $want || (string) $store[ $key ] === $want;
                                }
                        )
                );
        }

        $types = isset( $a['post_type'] ) ? (array) $a['post_type'] : array();
        if ( $types ) {
                $all = array_values( array_filter( $all, function ( $p ) use ( $types ) {
                        return empty( $p['type'] ) || in_array( $p['type'], $types, true );
                } ) );
        }

        $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array();
        if ( $status && ! in_array( 'any', $status, true ) ) {
                $all = array_values( array_filter( $all, function ( $p ) use ( $status ) {
                        return in_array( isset( $p['status'] ) ? $p['status'] : 'publish', $status, true );
                } ) );
        }

        if ( ! empty( $a['p'] ) ) {
                $pid = (int) $a['p'];
                $all = array_values( array_filter( $all, function ( $p ) use ( $pid ) { return (int) $p['id'] === $pid; } ) );
        }

        if ( isset( $a['post__in'] ) ) {
                $want = array_map( 'intval', (array) $a['post__in'] );
                $all  = array_values( array_filter( $all, function ( $p ) use ( $want ) { return in_array( (int) $p['id'], $want, true ); } ) );
        }

        // 2.1.19 — post_name__in (contact-page slug fallback). Rows carry an
        // optional 'slug'; without one it is derived from the title the way WP
        // derives slugs for ASCII titles (spaces → dashes).
        if ( ! empty( $a['post_name__in'] ) ) {
                $want = array_map( 'sanitize_title', array_map( 'strval', (array) $a['post_name__in'] ) );
                $all  = array_values( array_filter( $all, function ( $p ) use ( $want ) {
                        $slug = isset( $p['slug'] ) ? sanitize_title( (string) $p['slug'] ) : sanitize_title( (string) $p['title'] );
                        return in_array( $slug, $want, true );
                } ) );
        }

        if ( ! empty( $a['s'] ) ) {
                $s = (string) $a['s'];
                $all = array_values( array_filter( $all, function ( $p ) use ( $s ) {
                        return false !== mb_stripos( (string) $p['title'], $s );
                } ) );
        }

        foreach ( (array) ( $a['meta_query'] ?? array() ) as $clause ) {
                $key = isset( $clause['key'] ) ? $clause['key'] : '';
                if ( '_sku' === $key ) {
                        $needle = (string) ( $clause['value'] ?? '' );
                        $all    = array_values( array_filter( $all, function ( $p ) use ( $needle ) {
                                $sku = isset( $GLOBALS['meta_sku'][ (int) $p['id'] ] ) ? $GLOBALS['meta_sku'][ (int) $p['id'] ] : '';
                                return '' === $needle || false !== stripos( (string) $sku, $needle );
                        } ) );
                }
                if ( '_stock_status' === $key ) {
                        $want = (string) ( $clause['value'] ?? '' );
                        $all  = array_values( array_filter( $all, function ( $p ) use ( $want ) {
                                return (string) ( $GLOBALS['post_stock'][ (int) $p['id'] ] ?? '' ) === $want;
                        } ) );
                }
        }

        foreach ( (array) ( $a['tax_query'] ?? array() ) as $clause ) {
                $tax   = isset( $clause['taxonomy'] ) ? $clause['taxonomy'] : '';
                $terms = array_map( 'intval', (array) ( $clause['terms'] ?? array() ) );
                if ( 'product_cat' === $tax && $terms ) {
                        $all = array_values( array_filter( $all, function ( $p ) use ( $terms ) {
                                foreach ( (array) ( $GLOBALS['post_terms'][ (int) $p['id'] ]['product_cat'] ?? array() ) as $t ) {
                                        if ( in_array( (int) $t->term_id, $terms, true ) ) {
                                                return true;
                                        }
                                }
                                return false;
                        } ) );
                }
                if ( 'product_type' === $tax && $terms ) {
                        // Seeded as slugs, which is how WooCommerce stores the type.
                        $slugs = array_map( 'strval', (array) ( $clause['terms'] ?? array() ) );
                        $all   = array_values( array_filter( $all, function ( $p ) use ( $slugs ) {
                                return in_array( (string) ( $GLOBALS['post_ptype'][ (int) $p['id'] ] ?? '' ), $slugs, true );
                        } ) );
                }
        }

        usort( $all, function ( $x, $y ) { return strcmp( (string) $x['title'], (string) $y['title'] ); } );

        return $all;
}

/**
 * Shape stub rows the way the caller asked (objects, or bare ids).
 *
 * @param array $rows Seeded rows.
 * @param array $a    Original args.
 * @return array
 */
function stub_map_rows( $rows, $a ) {
        $out = array();
        foreach ( $rows as $p ) {
                if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) {
                        $out[] = (int) $p['id'];
                        continue;
                }
                $o = new stdClass();
                $o->ID          = (int) $p['id'];
                $o->post_title  = (string) $p['title'];
                $o->post_type   = isset( $p['type'] ) ? $p['type'] : 'page';
                $o->post_status = isset( $p['status'] ) ? $p['status'] : 'publish';
                $out[] = $o;
        }
        return $out;
}
function get_comment( $id ) { return null; }
function wp_roles() { $r = new stdClass(); $r->roles = array( 'subscriber' => array( 'name' => 'دستکم‌کننده' ), 'customer' => array( 'name' => 'مشتری' ) ); return $r; }
function paginate_links( $a = array() ) { return ''; }
function get_edit_post_link( $id = 0 ) { return 'http://local.test/edit'; }
function get_the_date( $f = '', $p = null ) { return '2026-01-01'; }
function translate_user_role( $t ) { return $t; }
function get_post_status_object( $s ) { $o = new stdClass(); $o->label = 'Draft'; return $o; }
function wp_upload_bits( $n, $t, $d ) { return array( 'error' => false, 'file' => sys_get_temp_dir() . '/' . $n, 'url' => 'http://local.test/' . $n ); }
function wp_handle_sideload( $a, $d = array() ) { return array(); }
function wp_insert_attachment( $a, $f = '', $p = 0 ) { return 1; }
function wp_update_attachment_metadata( $id, $m ) { return true; }
function update_post_meta( $id, $k, $v ) {
        $GLOBALS['post_meta'][ (int) $id ][ $k ] = $v;
        return true;
}

/**
 * Seedable product object (2.1.18): suites put rows into
 * $GLOBALS['products'][ $id ] = array( 'name' =>, 'price' =>, 'stock' =>,
 * 'short' =>, 'desc' =>, 'sku' => ) and wc_get_product() returns a minimal
 * fake with the getters the plugin actually reads. Unseeded ids still return
 * null — exactly the old behaviour, so older suites are unaffected.
 */
if ( ! class_exists( 'Haa_Stub_Product' ) ) {
        class Haa_Stub_Product {
                private $row;
                public function __construct( $row ) { $this->row = (array) $row; }
                public function get_name() { return (string) ( $this->row['name'] ?? '' ); }
                public function get_short_description() { return (string) ( $this->row['short'] ?? '' ); }
                public function get_description() { return (string) ( $this->row['desc'] ?? '' ); }
                public function get_price() { return (string) ( $this->row['price'] ?? '' ); }
                public function get_stock_status() { return (string) ( $this->row['stock'] ?? 'instock' ); }
                public function get_sku() { return (string) ( $this->row['sku'] ?? '' ); }
                public function get_attributes() { return (array) ( $this->row['attrs'] ?? array() ); }
        }
}
function wc_get_product( $id ) {
        $row = isset( $GLOBALS['products'] ) ? ( $GLOBALS['products'][ (int) $id ] ?? null ) : null;
        if ( null === $row ) {
                return null;
        }
        return new Haa_Stub_Product( $row );
}
/** @return string|WP_Error Archive link for a seeded term object. */
function get_term_link( $term ) {
        if ( is_wp_error( $term ) || ! is_object( $term ) || empty( $term->slug ) ) {
                return new WP_Error( 'invalid_term', 'bad term' );
        }
        return 'http://local.test/product-cat/' . rawurlencode( (string) $term->slug );
}
if ( ! function_exists( 'wc_price' ) ) {
        /** @return string */
        function wc_price( $amount ) { return (string) $amount; }
}
if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
        /** @return string */
        function get_woocommerce_currency_symbol() { return 'تومان'; }
}
function wp_count_posts( $t ) { $o = new stdClass(); $o->publish = 0; return $o; }
function absint( $v ) { return abs( (int) $v ); }
function wp_send_json( $d, $s = 200 ) { $GLOBALS['json'] = $d; }
function wp_send_json_error( $d = null, $s = 200 ) { $GLOBALS['json'] = $d; }
function wp_send_json_success( $d = null, $s = 200 ) { $GLOBALS['json'] = $d; }
function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); }

/* ---- WooCommerce / taxonomy stubs ---- */

/** Empty marker class so haa_is_woo() returns true inside tests. */
if ( ! class_exists( 'WooCommerce' ) ) {
        class WooCommerce {}
}

class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
                $this->code    = $code;
                $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/**
 * Return term objects for a post, sourced from $GLOBALS['post_terms'].
 * Shape: $GLOBALS['post_terms'][ $post_id ][ $taxonomy ] = [ (object)['term_id'=>int,'name'=>string,'count'=>int], … ]
 */
function get_the_terms( $post_id, $taxonomy ) {
        $rows = isset( $GLOBALS['post_terms'][ (int) $post_id ][ $taxonomy ] )
                ? $GLOBALS['post_terms'][ (int) $post_id ][ $taxonomy ]
                : array();
        return $rows ? $rows : false;   // WP returns false (not []) when no terms.
}
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
        if ( isset( $args['fields'] ) && 'names' === $args['fields'] ) {
                $out = array();
                foreach ( (array) get_the_terms( $post_id, $taxonomy ) as $t ) {
                        $out[] = $t->name;
                }
                return $out;
        }
        return (array) get_the_terms( $post_id, $taxonomy );
}
function get_post( $id = 0 ) {
        $id = is_object( $id ) ? (int) $id->ID : (int) $id;

        // Posts created by the D3 pipeline live in the full-fidelity store, so
        // post_content/post_status/excerpt are available to the code under test.
        if ( isset( $GLOBALS['written'][ $id ] ) ) {
                $w  = $GLOBALS['written'][ $id ];
                $o  = new stdClass();
                foreach ( $w as $k => $v ) {
                        $o->{ (string) $k } = $v;
                }
                $o->post_title = (string) $o->post_title;
                return $o;
        }

        $all = isset( $GLOBALS['posts'] ) ? (array) $GLOBALS['posts'] : array();
        foreach ( $all as $p ) {
                if ( (int) $p['id'] === (int) $id ) {
                        $o = new stdClass();
                        $o->ID          = (int) $p['id'];
                        $o->post_title  = (string) $p['title'];
                        $o->post_type   = isset( $p['type'] ) ? $p['type'] : 'page';
                        $o->post_status = isset( $p['status'] ) ? $p['status'] : 'publish';
                        // Real WP always hydrates these; refresh and hub code reads them.
                        $o->post_content = isset( $GLOBALS['post_content'][ (int) $p['id'] ] ) ? (string) $GLOBALS['post_content'][ (int) $p['id'] ] : '';
                        $o->post_excerpt = isset( $GLOBALS['post_excerpt'][ (int) $p['id'] ] ) ? (string) $GLOBALS['post_excerpt'][ (int) $p['id'] ] : '';
                        return $o;
                }
        }
        return null;
}

/* ---- WP_Query stub ----
 * Shares stub_filter_posts() with get_posts(), then applies paging, so the
 * picker's offset / posts_per_page / found_posts arithmetic is really tested.
 */
class WP_Query {
        public $posts         = array();
        public $found_posts   = 0;
        public $max_num_pages = 1;

        public function __construct( $args = array() ) {
                $rows          = stub_filter_posts( $args );
                $this->found_posts = count( $rows );

                $per = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
                if ( $per < 0 ) {
                        $per = $this->found_posts; // -1 means "all".
                }
                $page   = max( 1, isset( $args['paged'] ) ? (int) $args['paged'] : 1 );
                $offset = isset( $args['offset'] ) ? (int) $args['offset'] : ( $page - 1 ) * $per;

                $this->max_num_pages = $per > 0 ? (int) ceil( $this->found_posts / $per ) : 1;

                $slice = array_slice( $rows, $offset, $per > 0 ? $per : null );
                $this->posts = stub_map_rows( $slice, $args );
        }
}

require HAAI_DEV_ROOT . 'hami-ai-assistant.php';
require_once HAAI_PATH . 'templates/chat-widget.php';

Haa_Settings::install();

/**
 * Handlers end in back() -> exit, which would take the test process with them.
 * When HAAI_DUMP names a file, the interesting globals are written there on
 * shutdown instead, so a child run can be asserted by the parent.
 */
register_shutdown_function( function () {
        $to = getenv( 'HAAI_DUMP' );
        if ( ! $to ) {
                return;
        }
        file_put_contents( $to, json_encode( array(
                'jobs'     => isset( $GLOBALS['jobs'] ) ? $GLOBALS['jobs'] : array(),
                'redirect' => isset( $GLOBALS['redirect'] ) ? $GLOBALS['redirect'] : null,
                'saved'    => isset( $GLOBALS['saved'] ) ? $GLOBALS['saved'] : array(),
                'images'   => isset( $GLOBALS['images_made'] ) ? $GLOBALS['images_made'] : 0,
        ) ) );
} );

/* -------------------------------------------------------------------------
 * Fake HTTP transport (D2 research tests)
 *
 * A programmable network: tests register URL patterns and this either returns
 * them or produces a transport error. Nothing here touches the real network,
 * and every call is logged so a test can assert *how many* requests were made
 * and *with which* User-Agent / size cap / timeout.
 * ------------------------------------------------------------------------- */

$GLOBALS['http_map'] = array();   // pattern => array('code'=>int,'body'=>string) | array('error'=>string)
$GLOBALS['http_log'] = array();   // list of array('url','args','method')

/**
 * Register a canned response, matched as a substring of the request URL.
 *
 * @param string $pattern Substring of the URL.
 * @param array  $reply   array(code,body,headers) or array(error).
 * @return void
 */
function http_mock( $pattern, $reply ) {
        $GLOBALS['http_map'][ $pattern ] = $reply;
}

/** @return void Forget every canned response and log. */
function http_reset() {
        $GLOBALS['http_map'] = array();
        $GLOBALS['http_log'] = array();
}

/** @return array<int,array> Requests made, in order. */
function http_requests() {
        return $GLOBALS['http_log'];
}

/**
 * @param string $url    Request URL.
 * @param array  $args   Transport args.
 * @param string $method Verb.
 * @return array|WP_Error Response array shaped like WP's, or a transport error.
 */
function fake_http_request( $url, $args, $method = 'GET' ) {
        $GLOBALS['http_log'][] = array(
                'url'    => (string) $url,
                'args'   => (array) $args,
                'method' => $method,
        );

        $reply = null;
        foreach ( (array) $GLOBALS['http_map'] as $pattern => $candidate ) {
                if ( false !== strpos( (string) $url, (string) $pattern ) ) {
                        $reply = $candidate;
                        break;
                }
        }

        if ( null === $reply ) {
                // An unmocked host must fail loudly. Returning empty HTML instead would
                // let a test pass for the wrong reason.
                return new WP_Error( 'cURL error 6', 'fake: unmocked host ' . $url );
        }

        if ( isset( $reply['error'] ) ) {
                return new WP_Error( 'http_request_failed', $reply['error'] );
        }

        $body = (string) ( $reply['body'] ?? '' );

        // Real WP enforces limit_response_size; mirror it so the cap is testable.
        if ( ! empty( $args['limit_response_size'] ) ) {
                $cap  = (int) $args['limit_response_size'];
                $body = strlen( $body ) > $cap ? substr( $body, 0, $cap ) : $body;
        }

        return array(
                'response' => array( 'code' => (int) ( $reply['code'] ?? 200 ), 'message' => 'OK' ),
                'headers'  => (array) ( $reply['headers'] ?? array() ),
                'body'     => $body,
        );
}

/** @return array|WP_Error */
function wp_remote_get( $url, $args = array() ) {
        return fake_http_request( $url, $args, 'GET' );
}

/** @return array|WP_Error */
function wp_remote_post( $url, $args = array() ) {
        return fake_http_request( $url, $args, 'POST' );
}

/** @return int */
function wp_remote_retrieve_response_code( $r ) {
        if ( ! is_array( $r ) || ! isset( $r['response']['code'] ) ) {
                return 0;
        }
        return (int) $r['response']['code'];
}

/** @return string */
function wp_remote_retrieve_body( $r ) {
        return is_array( $r ) && isset( $r['body'] ) ? (string) $r['body'] : '';
}

/** 2.1.21 — crawl fetch() reads the content-type header. */
function wp_remote_retrieve_header( $r, $name ) {
        return is_array( $r ) && isset( $r['headers'][ strtolower( (string) $name ) ] ) ? $r['headers'][ strtolower( (string) $name ) ] : '';
}

/** @return string|int|null */
function wp_parse_url( $url, $component = -1 ) {
        return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

/* -------------------------------------------------------------------------
 * Post store (D3 pipeline)
 *
 * wp_insert_post / wp_update_post write into an in-memory table that get_post(),
 * get_post_status() and get_post_meta() read back, so a full pipeline run and
 * the review screen that follows it behave like production. Every update counts
 * as a revision, because that is what WP does — and it is what makes "roll the
 * article back" a real claim instead of a hopeful one.
 * ------------------------------------------------------------------------- */

$GLOBALS['written']       = array();  // id => post row
$GLOBALS['next_post_id']  = 7000;
$GLOBALS['revisions']     = array();  // id => count
$GLOBALS['post_meta']     = array();

/** @return int|WP_Error New post id. */
function wp_insert_post( $args, $wp_error = false ) {
        if ( ! isset( $args['post_title'] ) || '' === trim( (string) $args['post_title'] ) ) {
                return $wp_error ? new WP_Error( 'haai_empty_title', 'title required' ) : 0;
        }

        $id = (int) $GLOBALS['next_post_id']++;
        $GLOBALS['written'][ $id ] = array(
                'ID'           => $id,
                'id'           => $id,
                'post_title'   => (string) $args['post_title'],
                'post_name'    => (string) ( $args['post_name'] ?? '' ),
                'post_content' => (string) ( $args['post_content'] ?? '' ),
                'post_excerpt' => (string) ( $args['post_excerpt'] ?? '' ),
                'post_status'  => (string) ( $args['post_status'] ?? 'draft' ),
                'post_type'    => (string) ( $args['post_type'] ?? 'post' ),
                'post_author'  => (int) ( $args['post_author'] ?? 0 ),
                'post_date'    => (string) ( $args['post_date'] ?? gmdate( 'Y-m-d H:i:s' ) ),
                'thumbnail'    => 0,
        );
        $GLOBALS['posts'][] = array( 'id' => $id, 'title' => (string) $args['post_title'], 'type' => 'post', 'status' => (string) ( $args['post_status'] ?? 'draft' ) );
        return $id;
}

/** @return int|WP_Error Post id. */
function wp_update_post( $args, $wp_error = false ) {
        $id = (int) ( $args['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['written'][ $id ] ) ) {
                return $wp_error ? new WP_Error( 'haai_no_post', 'no such post' ) : 0;
        }

        // WP records a revision before touching an existing post.
        $GLOBALS['revisions'][ $id ] = ( isset( $GLOBALS['revisions'][ $id ] ) ? (int) $GLOBALS['revisions'][ $id ] : 0 ) + 1;

        foreach ( $args as $k => $v ) {
                if ( 'ID' === $k ) {
                        continue;
                }
                $GLOBALS['written'][ $id ][ $k ] = is_scalar( $v ) ? (string) $v : $v;
        }

        foreach ( $GLOBALS['posts'] as $i => $p ) {
                if ( (int) $p['id'] === $id ) {
                        if ( isset( $args['post_status'] ) ) { $GLOBALS['posts'][ $i ]['status'] = (string) $args['post_status']; }
                        if ( isset( $args['post_title'] ) )  { $GLOBALS['posts'][ $i ]['title']  = (string) $args['post_title']; }
                }
        }

        return $id;
}

/** @return string Permalink for a stored post. */
function get_permalink( $id = 0 ) {
        $id = is_object( $id ) ? (int) $id->ID : (int) $id;
        return 'http://local.test/?post=' . $id;
}

/** @return int */
function get_current_user_id() { return isset( $GLOBALS['user_id'] ) ? (int) $GLOBALS['user_id'] : 1; }

/** @return int */
function get_current_blog_id() { return 1; }

/**
 * WP's sanitize_title, closely enough for slug tests: lowercase, dashes, and
 * Persian letters survive (WP keeps non-ASCII slugs when the title is RTL).
 *
 * @return string
 */
function sanitize_title( $t, $id = 0 ) {
        $t = strtolower( trim( (string) $t ) );
        $t = str_replace( array( ' ', '_', "\t", "\n", "\r" ), '-', $t );
        $t = preg_replace( '/[^a-z0-9\-\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', '', (string) $t );
        $t = preg_replace( '/-+/', '-', (string) $t );
        return trim( $t, '-' );
}

/** @return int Attachment id recorded on the post. */
function set_post_thumbnail( $post_id, $att_id ) {
        if ( isset( $GLOBALS['written'][ (int) $post_id ] ) ) {
                $GLOBALS['written'][ (int) $post_id ]['thumbnail'] = (int) $att_id;
        }
        return true;
}

/** @return int How many revisions an update produced. */
function haai_test_revisions( $id ) {
        return isset( $GLOBALS['revisions'][ (int) $id ] ) ? (int) $GLOBALS['revisions'][ (int) $id ] : 0;
}

/* -------------------------------------------------------------------------
 * Odds and ends for D4 (news / video / programmatic / refresh)
 * ------------------------------------------------------------------------- */

/** @return string Base URL, used to tell internal links from external ones. */
function home_url( $path = '' ) { return 'http://local.test' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) ); }

/** @return array|WP_Error Head request through the same fake transport. */
function wp_remote_head( $url, $args = array() ) {
        $GLOBALS['head_log'][] = (string) $url;
        return fake_http_request( $url, $args, 'HEAD' );
}

$GLOBALS['head_log'] = array();

/**
 * Modified time, driven by the test, so "how old is this post" is assertable
 * without waiting for real days. Defaults to very old: seeded posts are due
 * unless a test says otherwise.
 *
 * @return int|string
 */
function get_post_modified_time( $fmt = 'U', $gmt = false, $post = 0 ) {
        $id  = is_object( $post ) ? (int) $post->ID : (int) $post;
        $map = isset( $GLOBALS['modified'] ) ? (array) $GLOBALS['modified'] : array();
        if ( ! isset( $map[ $id ] ) ) {
                $map[ $id ] = time() - ( 700 * DAY_IN_SECONDS );
        }
        $ts = (int) $map[ $id ];
        return 'U' === $fmt ? $ts : gmdate( (string) $fmt, $ts );
}

// NOTE: is_uploaded_file() is a PHP builtin and cannot be redefined here, so the
// upload branch is covered by calling Haa_Module_Video_Article::read_upload()
// directly in tmp-checks9.php.
