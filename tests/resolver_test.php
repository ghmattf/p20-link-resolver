<?php
// Offline regression harness: WordPress stubs + a mocked Podcast Index API.
// Run: php tests/resolver_test.php

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['opts']       = array( 'p20link_api_key' => 'KEY', 'p20link_api_secret' => 'SECRET' );
$GLOBALS['transients'] = array();
$GLOBALS['http_log']   = array();
$GLOBALS['http_mock']  = array();

function add_shortcode() {}
function add_action() {}
function register_activation_hook() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function home_url() { return 'https://example.test'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function shortcode_atts( $d, $a ) { return array_merge( $d, is_array( $a ) ? array_intersect_key( $a, $d ) : array() ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function current_user_can() { return false; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url_raw( $s ) { return (string) $s; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function esc_attr__( $s ) { return $s; }
function esc_html_e( $s ) { echo $s; }
function esc_attr_e( $s ) { echo $s; }
function get_permalink() { return 'https://example.test/podlink/'; }
function sanitize_hex_color( $c ) { return preg_match( '/^#([0-9a-fA-F]{3}){1,2}$/', $c ) ? $c : ''; }
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function is_singular() { return ! empty( $GLOBALS['is_singular'] ); }
function get_post() { return isset( $GLOBALS['fake_post'] ) ? $GLOBALS['fake_post'] : null; }
function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); }
function get_bloginfo( $k = '' ) { return 'Example Podcasts'; }
class WP_Error {}
function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />'; }
function checked( $a, $b = true, $echo = true ) {
	$r = (string) $a === (string) $b ? ' checked="checked"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function selected( $a, $b = '', $echo = true ) {
	$r = (string) $a === (string) $b ? ' selected="selected"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function submit_button( $t = null, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<p class="submit"><input type="submit" /></p>'; }
// wp_send_json_* exits in WordPress; here they throw so tests can catch the payload.
class P20_Json_Response extends Exception {
	public $ok = false;
	public $payload = null;
	public $status = 0;
}
function wp_send_json_success( $data = null ) {
	$e = new P20_Json_Response( 'json' );
	$e->ok = true;
	$e->payload = $data;
	throw $e;
}
function wp_send_json_error( $data = null, $code = null ) {
	$e = new P20_Json_Response( 'json' );
	$e->ok = false;
	$e->payload = $data;
	$e->status = (int) $code;
	throw $e;
}
function check_ajax_referer( $action, $query_arg = false, $die = true ) { return true; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['http_log'][] = array( $url, $args );
	foreach ( $GLOBALS['http_mock'] as $needle => $resp ) {
		if ( false !== strpos( $url, $needle ) ) {
			return $resp;
		}
	}
	return array( 'code' => 404, 'body' => '' );
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['http_log'][] = array( $url, $args );
	// Match needles against url + body so Fountain lookups can be told
	// apart by the guid they carry (the URL is always the same endpoint).
	$haystack = $url . ' ' . ( isset( $args['body'] ) ? (string) $args['body'] : '' );
	foreach ( $GLOBALS['http_mock'] as $needle => $resp ) {
		if ( false !== strpos( $haystack, $needle ) ) {
			return $resp;
		}
	}
	return array( 'code' => 404, 'body' => '' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

class FakeWpdb {
	public $prefix = 'wp_';
	public $rows   = array();
	public function prepare( $sql, ...$args ) { return 'HASH:' . $args[0]; }
	public function get_row( $q ) {
		$h = substr( $q, 5 );
		// prepare() yields "HASH:<value>": lookup_hash rows are keyed by
		// hash; a value starting with http means a fountain_url query
		// (cache_row_by_fountain_url), which scans for a matching row.
		if ( 0 === strpos( $h, 'http' ) ) {
			foreach ( $this->rows as $row ) {
				if ( isset( $row['fountain_url'] ) && $row['fountain_url'] === $h ) {
					return (object) $row;
				}
			}
			return null;
		}
		return isset( $this->rows[ $h ] ) ? (object) $this->rows[ $h ] : null;
	}
	public function replace( $t, $data ) { $this->rows[ $data['lookup_hash'] ] = $data; return 1; }
	public function update( $t, $data, $where, $format = null, $where_format = null ) {
		$h = isset( $where['lookup_hash'] ) ? $where['lookup_hash'] : null;
		if ( null === $h || ! isset( $this->rows[ $h ] ) ) {
			return 0;
		}
		$this->rows[ $h ] = array_merge( $this->rows[ $h ], $data );
		return 1;
	}
}
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../p20-link-resolver.php';

$o = new P20_Link_Resolver();
function call( $o, $m, ...$a ) {
	$r = new ReflectionMethod( $o, $m );
	$r->setAccessible( true );
	return $r->invoke( $o, ...$a );
}

$pass = 0; $fail = 0;
function check( $name, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok   $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}

// --- guards ---
check( 'guard_show accepts uuid', '9b024349-ccf0-5f69-a609-6b82873eab3c' === call( $o, 'guard_show', '9B024349-CCF0-5F69-A609-6B82873EAB3C' ) );
check( 'guard_show accepts numeric', '75075' === call( $o, 'guard_show', '75075' ) );
check( 'guard_show rejects junk', '' === call( $o, 'guard_show', "abc'; DROP" ) );
check( 'guard_show rejects overlong', '' === call( $o, 'guard_show', str_repeat( 'a', 65 ) ) );
check( 'guard_episode keeps arbitrary guid', 'https://x.com/ep?id=1&a=b c' === call( $o, 'guard_episode', 'https://x.com/ep?id=1&a=b c' ) );
check( 'guard_episode strips control chars', 'abc' === call( $o, 'guard_episode', "a\x00b\x1Fc" ) );
check( 'guard_episode rejects overlong', '' === call( $o, 'guard_episode', str_repeat( 'x', 513 ) ) );

// --- encodeURIComponent parity ---
$enc = new ReflectionMethod( 'P20_Link_Resolver', 'encode_uri_component' );
$enc->setAccessible( true );
check( 'encode_uri_component keeps !*\'()', "a!b*c'd(e)f" === $enc->invoke( null, "a!b*c'd(e)f" ) );
check( 'encode_uri_component encodes / : space', 'a%2Fb%3Ac%20d' === $enc->invoke( null, 'a/b:c d' ) );

// --- auth header ---
$h = call( $o, 'api_headers', 1700000000 );
check( 'auth header = sha1(key.secret.time)', sha1( 'KEYSECRET1700000000' ) === $h['Authorization'] );
check( 'auth headers present', 'KEY' === $h['X-Auth-Key'] && '1700000000' === $h['X-Auth-Date'] && ! empty( $h['User-Agent'] ) );

// --- link patterns ---
$base = array(
	'show_ref' => 'x', 'feed_id' => 75075, 'podcast_guid' => 'g', 'itunes_id' => 1234,
	'show_title' => 'S', 'author' => 'A', 'image' => '', 'episode_guid' => '',
	'pi_episode_id' => 0, 'episode_title' => '', 'episode_image' => '',
	'episode_wanted' => false, 'episode_missing' => false,
);

// Fountain resolves its opaque ids through the public lookup API (mocked;
// the guide's numeric patterns were verified dead on 2026-11-12).
$GLOBALS['http_mock'] = array(
	'podcast:item:guid:PC2084' => array( 'code' => 200, 'body' => json_encode( array( 'hits' => array( array( '_id' => 'EfGhEp456', '_type' => 'EPISODE' ) ) ) ) ),
	'"id":"podcast:guid:g"'    => array( 'code' => 200, 'body' => json_encode( array( 'hits' => array( array( '_id' => 'AbCdShow123', '_type' => 'SHOW' ) ) ) ) ),
);
check( 'fountain show', 'https://fountain.fm/show/AbCdShow123' === call( $o, 'app_link', 'fountain', $base )['url'] );
check( 'curiocaster show', 'https://curiocaster.com/podcast/pi75075' === call( $o, 'app_link', 'curiocaster', $base )['url'] );
check( 'podverse show', 'https://api.podverse.fm/api/v1/podcast/podcastindex/75075' === call( $o, 'app_link', 'podverse', $base )['url'] );
check( 'podfriend show (PI feed id)', 'https://podfriend.com/podcast/75075' === call( $o, 'app_link', 'podfriend', $base )['url'] );
$noapple = $base; $noapple['itunes_id'] = 0;
$nofeed = $base; $nofeed['feed_id'] = 0; $nofeed['podcast_guid'] = '';
check( 'podfriend omitted with no feed id or guid', null === call( $o, 'app_link', 'podfriend', $nofeed ) );
$guidonly = $base; $guidonly['feed_id'] = 0;
check( 'podfriend falls back to podcast guid', 'https://podfriend.com/podcast/g' === call( $o, 'app_link', 'podfriend', $guidonly )['url'] );
check( 'truefans default icon bypasses hotlink protection', 'https://icons.duckduckgo.com/ip3/truefans.fm.ico' === call( $o, 'app_icon', 'truefans', call( $o, 'app_registry' )['truefans'] ) );
check( 'boostmebuddy show', 'https://www.boostmebuddy.com/?podcast=g' === call( $o, 'app_link', 'boostmebuddy', $base )['url'] );
$noguid = $base; $noguid['podcast_guid'] = '';
check( 'boostmebuddy omitted without podcast guid', null === call( $o, 'app_link', 'boostmebuddy', $noguid ) );
check( 'castamatic show', 'https://castamatic.com/guid/g' === call( $o, 'app_link', 'castamatic', $base )['url'] );
check( 'castamatic omitted without podcast guid', null === call( $o, 'app_link', 'castamatic', $noguid ) );
check( 'truefans show', 'https://truefans.fm/g' === call( $o, 'app_link', 'truefans', $base )['url'] );
check( 'truefans omitted without podcast guid', null === call( $o, 'app_link', 'truefans', $noguid ) );
check( 'podcastindex show (guid)', 'https://podcastindex.org/podcast/g' === call( $o, 'app_link', 'podcastindex', $base )['url'] );
check( 'podcastindex show falls back to feed id', 'https://podcastindex.org/podcast/75075' === call( $o, 'app_link', 'podcastindex', $noguid )['url'] );
$noid = $noguid; $noid['feed_id'] = 0;
check( 'podcastindex omitted with no ids', null === call( $o, 'app_link', 'podcastindex', $noid ) );
check( 'podcastguru show', 'https://app.podcastguru.io/podcast/1234' === call( $o, 'app_link', 'podcastguru', $base )['url'] );
check( 'podcastguru omitted without apple id or feed url', null === call( $o, 'app_link', 'podcastguru', $noapple ) );
$gurl = $noapple; $gurl['feed_url'] = 'https://feeds.example.com/show.xml';
check( 'podcastguru PI-only show uses md5(feed url)', 'https://app.podcastguru.io/podcast/' . md5( 'https://feeds.example.com/show.xml' ) === call( $o, 'app_link', 'podcastguru', $gurl )['url'] );

$ep = $base;
$ep['episode_wanted'] = true; $ep['episode_guid'] = 'PC2084/a b'; $ep['pi_episode_id'] = 16795090;
$l = call( $o, 'app_link', 'fountain', $ep );
check( 'fountain episode link', 'https://fountain.fm/episode/EfGhEp456' === $l['url'] && $l['episode'] );
$l = call( $o, 'app_link', 'podfriend', $ep );
check( 'podfriend episode degrades to show (guide subpath is 404)', ! $l['episode'] && 'https://podfriend.com/podcast/75075' === $l['url'] );
$l = call( $o, 'app_link', 'curiocaster', $ep );
check( 'curiocaster episode degrades to show', ! $l['episode'] && 'https://curiocaster.com/podcast/pi75075' === $l['url'] );

// Podverse episode links resolve the Podverse id through the public
// url-resolver (PI feed id + episode guid, live-verified 2026-11-16).
$GLOBALS['http_mock']['url-resolver/episode'] = array( 'code' => 200, 'body' => json_encode( array( 'webUrl' => 'https://podverse.fm/episode/8kDmgoJKa' ) ) );
$l = call( $o, 'app_link', 'podverse', $ep );
check( 'podverse episode link', 'https://podverse.fm/episode/8kDmgoJKa' === $l['url'] && $l['episode'] );
$pv_req = end( $GLOBALS['http_log'] );
check( 'podverse lookup hits url-resolver with PI ids',
	false !== strpos( $pv_req[0], 'url-resolver/episode?podcastIndexId=75075' )
	&& false !== strpos( $pv_req[0], 'episodeGuid=PC2084%2Fa%20b' )
	&& 0 === $pv_req[1]['redirection'] );
$n = count( $GLOBALS['http_log'] );
call( $o, 'app_link', 'podverse', $ep );
check( 'podverse episode URL remembered', count( $GLOBALS['http_log'] ) === $n );
$GLOBALS['http_mock']['url-resolver/episode'] = array( 'code' => 404, 'body' => '{"message":"Episode not found"}' );
$pv_miss = $ep; $pv_miss['episode_guid'] = 'podverse-unknown-guid';
$l = call( $o, 'app_link', 'podverse', $pv_miss );
check( 'podverse unknown episode degrades to show', ! $l['episode'] && 'https://api.podverse.fm/api/v1/podcast/podcastindex/75075' === $l['url'] );
$n = count( $GLOBALS['http_log'] );
call( $o, 'app_link', 'podverse', $pv_miss );
check( 'podverse miss remembered', count( $GLOBALS['http_log'] ) === $n );
$GLOBALS['http_mock']['url-resolver/episode'] = array( 'code' => 500, 'body' => '' );
$pv_err = $ep; $pv_err['episode_guid'] = 'podverse-500-guid';
$l = call( $o, 'app_link', 'podverse', $pv_err );
check( 'podverse server error degrades to show', ! $l['episode'] && 'https://api.podverse.fm/api/v1/podcast/podcastindex/75075' === $l['url'] );
check( 'podverse omitted without feed id', null === call( $o, 'app_link', 'podverse', $noid ) );
unset( $GLOBALS['http_mock']['url-resolver/episode'] );
$pv_nomock = $ep; $pv_nomock['episode_guid'] = 'podverse-no-mock-guid';
$l = call( $o, 'app_link', 'podverse', $pv_nomock ); // no mock -> default 404 (the pre-0.1.10 behaviour)
check( 'podverse episode without resolver mock still degrades', ! $l['episode'] );
$l = call( $o, 'app_link', 'boostmebuddy', $ep );
check( 'boostmebuddy episode link', 'https://www.boostmebuddy.com/?podcast=g&episode=PC2084%2Fa%20b' === $l['url'] && $l['episode'] );
$miss_ep = $ep; $miss_ep['episode_missing'] = true;
$l = call( $o, 'app_link', 'boostmebuddy', $miss_ep );
check( 'boostmebuddy missing episode degrades to show', ! $l['episode'] && 'https://www.boostmebuddy.com/?podcast=g' === $l['url'] );
$l = call( $o, 'app_link', 'castamatic', $ep );
check( 'castamatic episode degrades to show', ! $l['episode'] && 'https://castamatic.com/guid/g' === $l['url'] );
$l = call( $o, 'app_link', 'truefans', $ep );
check( 'truefans episode degrades to show', ! $l['episode'] && 'https://truefans.fm/g' === $l['url'] );
$l = call( $o, 'app_link', 'podcastindex', $ep );
check( 'podcastindex episode degrades to show', ! $l['episode'] && 'https://podcastindex.org/podcast/g' === $l['url'] );
$l = call( $o, 'app_link', 'podcastguru', $ep );
check( 'podcastguru episode degrades to show', ! $l['episode'] && 'https://app.podcastguru.io/podcast/1234' === $l['url'] );
$missing = $ep; $missing['episode_missing'] = true;
$l = call( $o, 'app_link', 'fountain', $missing );
check( 'missing episode -> fountain show link', 'https://fountain.fm/show/AbCdShow123' === $l['url'] && ! $l['episode'] );

// --- fountain lookup: id shapes, failure policy, budget ---
$mk_hit = function ( $hits ) {
	return array( 'code' => 200, 'body' => json_encode( array( 'hits' => $hits ) ) );
};

// The documented "{type}:id:{id}" ContentID form is stripped to the bare id.
$GLOBALS['http_mock']['api.fountain.fm'] = $mk_hit( array( array( '_id' => 'episode:id:PfxEp1', '_type' => 'EPISODE' ) ) );
check( 'fountain id strips content-id prefix', 'PfxEp1' === call( $o, 'fountain_id', 'episode', 'prefixed-guid', 'parent-guid' ) );

// 200 without a match = definitive miss (remembered for 30 minutes).
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 200, 'body' => json_encode( array( 'hits' => array() ) ) );
check( 'fountain empty hits -> miss', '' === call( $o, 'fountain_id', 'show', 'never-there' ) );
check( 'fountain definitive miss TTL', 30 * MINUTE_IN_SECONDS === call( $o, 'fountain_ttl' ) );

// 5xx = unreliable (remembered for 30 seconds only, never a definitive miss).
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 503, 'body' => '' );
check( 'fountain 503 -> uncertain (null, not "")', null === call( $o, 'fountain_id', 'show', 'flaky-guid' ) );
check( 'fountain soft miss TTL', 30 === call( $o, 'fountain_ttl' ) );

// A hit of the wrong type never becomes a URL.
$GLOBALS['http_mock']['api.fountain.fm'] = $mk_hit( array( array( '_id' => 'WrongType1', '_type' => 'SHOW' ) ) );
check( 'fountain wrong type -> miss', '' === call( $o, 'fountain_id', 'episode', 'wrong-type-guid', 'parent-guid' ) );
unset( $GLOBALS['http_mock']['api.fountain.fm'] );

// Resolve budget: no HTTP goes out and nothing is remembered.
$rp = new ReflectionProperty( $o, 'resolve_budgeted' );
$rp->setAccessible( true );
$rp->setValue( $o, true );
for ( $i = 0; $i < 60; $i++ ) {
	call( $o, 'is_rate_limited', 'resolve', 60, MINUTE_IN_SECONDS );
}
$GLOBALS['http_mock']['api.fountain.fm'] = $mk_hit( array( array( '_id' => 'BudgetId1', '_type' => 'SHOW' ) ) );
$n = count( $GLOBALS['http_log'] );
check( 'fountain budget exhausted -> uncertain', null === call( $o, 'fountain_id', 'show', 'budget-guid' ) );
check( 'fountain budget exhausted -> no HTTP', count( $GLOBALS['http_log'] ) === $n );
check( 'fountain budget exhausted -> nothing remembered', false === get_transient( 'p20l_fnt_1_' . md5( 'show|budget-guid|' ) ) );
$rp->setValue( $o, false );
unset( $GLOBALS['http_mock']['api.fountain.fm'] );
// Drop the spent rate-limit windows so the render tests below aren't throttled.
foreach ( array_keys( $GLOBALS['transients'] ) as $tk ) {
	if ( 0 === strpos( $tk, 'p20l_rl_' ) ) {
		unset( $GLOBALS['transients'][ $tk ] );
	}
}

// Degradation chain (user decision 2026-11-12): episode -> show ->
// fountain.fm/discover; unreliable lookups degrade the same way definitive
// ones do, so the button never disappears (regression: live report 2026-11-12).
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 200, 'body' => json_encode( array( 'hits' => array() ) ) );
$dm = $base; $dm['episode_wanted'] = true; $dm['episode_guid'] = 'def-miss-guid';
$l = call( $o, 'app_link', 'fountain', $dm );
check( 'fountain definitive episode miss -> show fallback', is_array( $l ) && 'https://fountain.fm/show/AbCdShow123' === $l['url'] && ! $l['episode'] );

$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 503, 'body' => '' );
$unc = $base; $unc['episode_wanted'] = true; $unc['episode_guid'] = 'soft-guid';
$l = call( $o, 'app_link', 'fountain', $unc );
check( 'fountain unreliable episode miss -> show fallback', is_array( $l ) && 'https://fountain.fm/show/AbCdShow123' === $l['url'] && ! $l['episode'] );
$n = count( $GLOBALS['http_log'] );
check( 'fountain soft miss cached as uncertain, not definitive', null === call( $o, 'fountain_id', 'episode', 'soft-guid', 'g' ) && count( $GLOBALS['http_log'] ) === $n );

// Last resort: unknown show, no guid, or unreliable show lookup -> discover.
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 200, 'body' => json_encode( array( 'hits' => array() ) ) );
$unk = $base; $unk['podcast_guid'] = 'never-seen-show';
$l = call( $o, 'app_link', 'fountain', $unk );
check( 'fountain unknown show -> discover', is_array( $l ) && 'https://fountain.fm/discover' === $l['url'] && ! $l['episode'] && isset( $l['note'] ) && 'discover' === $l['note'] );
$ng = $base; $ng['podcast_guid'] = '';
check( 'fountain without podcast guid -> discover', 'https://fountain.fm/discover' === call( $o, 'app_link', 'fountain', $ng )['url'] );
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 503, 'body' => '' );
$fs = $base; $fs['podcast_guid'] = 'flaky-show';
check( 'fountain unreliable show lookup -> discover', 'https://fountain.fm/discover' === call( $o, 'app_link', 'fountain', $fs )['url'] );
unset( $GLOBALS['http_mock']['api.fountain.fm'] );

// --- fountain derived URL cache (fountain_url / fountain_date on the row) ---
$clear_memo = function () use ( $o ) {
	$p = new ReflectionProperty( $o, 'row_memo' );
	$p->setAccessible( true );
	$p->setValue( $o, array() );
};
$stale_date = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
$fhash      = md5( 'x|' );
$ehash      = md5( 'x|PC2084/a b' );

// Fresh cached URL: served as-is with no Fountain call.
$clear_memo();
$wpdb->rows[ $fhash ] = array(
	'lookup_hash'   => $fhash,
	'show_ref'      => 'x',
	'episode_guid'  => '',
	'fountain_url'  => 'https://fountain.fm/show/CachedShow1',
	'fountain_date' => gmdate( 'Y-m-d H:i:s' ),
);
$n = count( $GLOBALS['http_log'] );
$l = call( $o, 'app_link', 'fountain', $base );
check( 'fountain fresh derived URL skips lookup', 'https://fountain.fm/show/CachedShow1' === $l['url'] && ! $l['episode'] && count( $GLOBALS['http_log'] ) === $n );

// An episode-shaped cached URL reports episode=true.
$clear_memo();
$wpdb->rows[ $ehash ] = array(
	'lookup_hash'   => $ehash,
	'show_ref'      => 'x',
	'episode_guid'  => 'PC2084/a b',
	'fountain_url'  => 'https://fountain.fm/episode/CachedEp1',
	'fountain_date' => gmdate( 'Y-m-d H:i:s' ),
);
$n = count( $GLOBALS['http_log'] );
$l = call( $o, 'app_link', 'fountain', $ep );
check( 'fountain fresh episode URL flagged, no lookup', 'https://fountain.fm/episode/CachedEp1' === $l['url'] && $l['episode'] && count( $GLOBALS['http_log'] ) === $n );

// Stale derived URL: re-derived and the row date refreshed.
$clear_memo();
$wpdb->rows[ $fhash ]['fountain_url']  = 'https://fountain.fm/show/StaleShow1';
$wpdb->rows[ $fhash ]['fountain_date'] = $stale_date;
$l    = call( $o, 'app_link', 'fountain', $base );
$ts   = strtotime( $wpdb->rows[ $fhash ]['fountain_date'] . ' UTC' );
check( 'fountain stale derived URL regenerated', 'https://fountain.fm/show/AbCdShow123' === $l['url'] );
check( 'fountain regen refreshes fountain_date', 'https://fountain.fm/show/AbCdShow123' === $wpdb->rows[ $fhash ]['fountain_url'] && $ts && ( time() - $ts ) < 60 );

// Re-derivation failure: the stale URL wins over discover, silently.
$clear_memo();
$wpdb->rows[ $fhash ]['fountain_url']  = 'https://fountain.fm/show/StaleShow1';
$wpdb->rows[ $fhash ]['fountain_date'] = $stale_date;
$ng2 = $base; $ng2['podcast_guid'] = '';
$n   = count( $GLOBALS['http_log'] );
$l   = call( $o, 'app_link', 'fountain', $ng2 );
check( 'fountain stale URL survives failed regen', 'https://fountain.fm/show/StaleShow1' === $l['url'] && ! isset( $l['note'] ) && count( $GLOBALS['http_log'] ) === $n );

// A metadata refresh (cache_store REPLACE) must not drop the derived URL.
$clear_memo();
$wpdb->rows[ $fhash ]['fountain_url']  = 'https://fountain.fm/show/KeepMe';
$wpdb->rows[ $fhash ]['fountain_date'] = $stale_date;
call( $o, 'cache_store', 'x', '', array(
	'feed_id' => 1, 'podcast_guid' => 'g', 'itunes_id' => 0, 'show_title' => 'S',
	'author' => 'A', 'image' => '', 'pi_episode_id' => 0, 'episode_title' => '', 'episode_image' => '',
) );
check( 'cache_store preserves derived Fountain URL', 'https://fountain.fm/show/KeepMe' === $wpdb->rows[ $fhash ]['fountain_url'] && $stale_date === $wpdb->rows[ $fhash ]['fountain_date'] );

// --- resolution + caching (mocked API) ---
$GLOBALS['http_log'] = array(); // Fountain lookups above already logged.
$feed_json = json_encode( array( 'status' => 'true', 'feed' => array(
	'id' => 920666, 'podcastGuid' => 'abc-guid', 'title' => 'Podcasting 2.0', 'author' => 'Adam',
	'image' => 'https://img/i.jpg', 'artwork' => 'https://img/art.jpg', 'itunesId' => 1554567890,
	'url' => 'https://feeds.podcastindex.org/pc20.xml',
) ) );
$ep_json = json_encode( array( 'status' => 'true', 'episode' => array( 'id' => 555, 'title' => 'Ep 1', 'guid' => 'PC2084', 'image' => 'https://img/ep.jpg' ) ) );
$GLOBALS['http_mock'] = array(
	'/podcasts/byguid'    => array( 'code' => 200, 'body' => $feed_json ),
	'/episodes/byguid'    => array( 'code' => 200, 'body' => $ep_json ),
);

$r = call( $o, 'resolve_release', 'abc-guid', '' );
check( 'resolve show: title/feed/apple', 'Podcasting 2.0' === $r['show_title'] && 920666 === $r['feed_id'] && 1554567890 === $r['itunes_id'] );
check( 'resolve show: prefers artwork', 'https://img/art.jpg' === $r['image'] );
check( 'resolve show: used byguid with auth', count( $GLOBALS['http_log'] ) === 1 && false !== strpos( $GLOBALS['http_log'][0][0], '/podcasts/byguid?guid=abc-guid' ) && ! empty( $GLOBALS['http_log'][0][1]['headers']['Authorization'] ) );
check( 'resolve show: redirection disabled', 0 === $GLOBALS['http_log'][0][1]['redirection'] );

$before = count( $GLOBALS['http_log'] );
$r2 = call( $o, 'resolve_release', 'abc-guid', '' );
check( 'second show resolve served from cache (no HTTP)', count( $GLOBALS['http_log'] ) === $before && 'Podcasting 2.0' === $r2['show_title'] );
check( 'resolve show: feed_url carried fetch and cache-row paths',
	'https://feeds.podcastindex.org/pc20.xml' === $r['feed_url']
	&& 'https://feeds.podcastindex.org/pc20.xml' === $r2['feed_url'] );

$r3 = call( $o, 'resolve_release', 'abc-guid', 'PC2084' );
check( 'resolve episode: ids', 555 === $r3['pi_episode_id'] && 'Ep 1' === $r3['episode_title'] && ! $r3['episode_missing'] );
check( 'resolve episode reused cached show (only 1 new call)', count( $GLOBALS['http_log'] ) === $before + 1 );
check( 'episode call passes feedid', false !== strpos( end( $GLOBALS['http_log'] )[0], 'feedid=920666' ) );

// numeric show -> byfeedid
$GLOBALS['http_mock'] = array( '/podcasts/byfeedid' => array( 'code' => 200, 'body' => $feed_json ) );
call( $o, 'resolve_release', '920666', '' );
check( 'numeric show uses byfeedid', false !== strpos( end( $GLOBALS['http_log'] )[0], '/podcasts/byfeedid?id=920666' ) );

// unknown show -> null + negative cache
$GLOBALS['http_mock'] = array( '/podcasts/byguid' => array( 'code' => 200, 'body' => json_encode( array( 'status' => 'false', 'feed' => array() ) ) ) );
$n = count( $GLOBALS['http_log'] );
check( 'unknown show -> null', null === call( $o, 'resolve_release', 'nope-guid', '' ) );
call( $o, 'resolve_release', 'nope-guid', '' );
check( 'unknown show remembered (1 call, not 2)', count( $GLOBALS['http_log'] ) === $n + 1 );

// unknown episode -> show still returned, flagged missing
$GLOBALS['http_mock'] = array( '/episodes/byguid' => array( 'code' => 200, 'body' => json_encode( array( 'status' => 'false', 'episode' => array() ) ) ) );
$r4 = call( $o, 'resolve_release', 'abc-guid', 'does-not-exist' );
check( 'unknown episode -> show kept, episode_missing', $r4 && $r4['episode_missing'] && 'Podcasting 2.0' === $r4['show_title'] && '' === $r4['episode_guid'] );

// 401 (bad creds) is a soft failure
$GLOBALS['transients'] = array();
$GLOBALS['http_mock']  = array( '/podcasts/byguid' => array( 'code' => 401, 'body' => '' ) );
check( 'api 401 -> null', null === call( $o, 'resolve_release', 'authfail-guid', '' ) );
check( '401 is a soft failure (30s), not a definitive miss', 30 === call( $o, 'neg_ttl' ) );

// --- render ---
$GLOBALS['http_mock'] = array(
	'/podcasts/byguid' => array( 'code' => 200, 'body' => $feed_json ),
	'/episodes/byguid' => array( 'code' => 200, 'body' => $ep_json ),
	'podcast:item:guid:PC2084'      => array( 'code' => 200, 'body' => json_encode( array( 'hits' => array( array( '_id' => 'EpRender1', '_type' => 'EPISODE' ) ) ) ) ),
	'"id":"podcast:guid:abc-guid"' => array( 'code' => 200, 'body' => json_encode( array( 'hits' => array( array( '_id' => 'ShowRender1', '_type' => 'SHOW' ) ) ) ) ),
);
$GLOBALS['transients'] = array();
$html = $o->render( array( 'show' => 'abc-guid', 'episode' => 'PC2084' ) );
check( 'render: 9 app buttons', 9 === substr_count( $html, 'class="p20link-item ' ) );
check( 'render: episode title shown', false !== strpos( $html, 'Ep 1' ) );
check( 'render: fountain episode href', false !== strpos( $html, 'https://fountain.fm/episode/EpRender1' ) );
check( 'render: degraded apps flagged', substr_count( $html, '(opens the show)' ) === 7 );
$nr = $ep; $nr['episode_title'] = 'T';
$nh = call( $o, 'render_html', array(
	'fountain' => array( 'url' => 'https://fountain.fm/discover', 'label' => 'Fountain', 'episode' => false, 'icon_url' => '', 'note' => 'discover' ),
), $nr, false, 'S', 'A', '' );
check( 'render: discover fallback note', false !== strpos( $nh, '(opens Fountain)' ) );
check( 'render: no share/url param leak of API secret', false === strpos( $html, 'SECRET' ) );
check( 'render: PodcastLink promo above footer', false !== strpos( $html, 'Share a show with' ) && false !== strpos( $html, 'href="https://phafe.com/podcastlink"' ) && strpos( $html, 'phafe.com/podcastlink' ) < strpos( $html, 'Powered by' ) );

// app_link()'s note must survive render()'s link assembly: on an episode page
// where Fountain can only link the show, the button says "(opens Fountain)".
$GLOBALS['transients'] = array();
$wpdb->rows           = array();
$GLOBALS['http_mock'] = array(
	'/podcasts/byguid' => array( 'code' => 200, 'body' => $feed_json ),
	'/episodes/byguid' => array( 'code' => 200, 'body' => $ep_json ),
);
$dh = $o->render( array( 'show' => 'discover-guid', 'episode' => 'PC2084' ) );
check( 'render: fountain discover note reaches HTML', false !== strpos( $dh, '(opens Fountain)' ) );
check( 'render: other apps still say opens the show', 7 === substr_count( $dh, '(opens the show)' ) );

$html = $o->render( array( 'show' => 'abc-guid', 'header' => 'false' ) );
check( 'render header=false hides title', false === strpos( $html, 'p20link-title' ) );

// disabled app
$GLOBALS['opts']['p20link_apps'] = array( 'fountain' => array( 'enabled' => false ) );
$html = $o->render( array( 'show' => 'abc-guid' ) );
check( 'disabled app hidden', false === strpos( $html, 'fountain.fm/show' ) && 8 === substr_count( $html, 'class="p20link-item ' ) );

// no show -> search UI; no creds -> message
$html = $o->render( array() );
check( 'no show -> search UI', false !== strpos( $html, 'p20link-search-input' ) );
check( 'search UI has PodcastLink h1 on top', false !== strpos( $html, '<h1 class="p20link-search-h1">PodcastLink</h1>' ) && strpos( $html, 'p20link-search-h1' ) < strpos( $html, 'p20link-search-title' ) );
$GLOBALS['opts']['p20link_api_key'] = '';
$html = $o->render( array( 'show' => 'abc-guid' ) );
check( 'no credentials -> notice, no API call', false !== strpos( $html, 'not available' ) );

// unknown show renders search UI with message
$GLOBALS['opts']['p20link_api_key'] = 'KEY';
$GLOBALS['http_mock'] = array( '/podcasts/byguid' => array( 'code' => 200, 'body' => json_encode( array( 'feed' => array() ) ) ) );
$html = $o->render( array( 'show' => 'zzz-unknown' ) );
check( 'unknown show -> search UI + "not found"', false !== strpos( $html, 'Podcast not found' ) && false !== strpos( $html, 'p20link-search-input' ) );

// sanitize apps
$clean = $o->sanitize_apps_settings( array(
	'fountain'     => array( 'enabled' => '1', 'order' => '3', 'icon_bg' => '#ff0000' ),
	'curiocaster'  => array( 'order' => '0' ),
	'podverse'     => array( 'enabled' => '1', 'order' => '1', 'icon_bg_none' => '1', 'icon_bg' => '#00ff00' ),
	'podfriend'    => array( 'enabled' => '1', 'order' => '2' ),
	'boostmebuddy' => array( 'enabled' => '1', 'order' => '4' ),
) );
check( 'sanitize: orders normalised 0..n-1', array( 0, 1, 2, 3, 4 ) === array_values( array_map( function ( $c ) { return $c['order']; }, array( $clean['curiocaster'], $clean['podverse'], $clean['podfriend'], $clean['fountain'], $clean['boostmebuddy'] ) ) ) );
check( 'sanitize: "None" beats colour', '' === $clean['podverse']['icon_bg'] && '#ff0000' === $clean['fountain']['icon_bg'] );
check( 'sanitize: unchecked app disabled', false === $clean['curiocaster']['enabled'] );

// --- settings icon previews ---
ob_start();
call( $o, 'render_settings_tab' );
$st = ob_get_clean();
check( 'settings: icon preview per app', 9 === substr_count( $st, 'p20link-icon-preview' ) );
check( 'settings: default favicon preview', false !== strpos( $st, 'https://fountain.fm/favicon.ico' ) );
check( 'settings: preview img is escaped', false !== strpos( $st, 'src="https://fountain.fm/favicon.ico"' ) );
$GLOBALS['opts']['p20link_apps']['fountain']['icon_url'] = 'https://example.test/custom.png';
ob_start();
call( $o, 'render_settings_tab' );
$st2 = ob_get_clean();
check( 'settings: override icon preview', false !== strpos( $st2, 'https://example.test/custom.png' ) );
check( 'settings: broken preview hides itself', false !== strpos( $st2, 'onerror="this.style.display=\'none\'"' ) );
unset( $GLOBALS['opts']['p20link_apps']['fountain']['icon_url'] );

// --- social preview buffer (OUTSTANDING #11: theme og duplicates) ---
$GLOBALS['opts']['p20link_social_preview'] = 'on';
$GLOBALS['is_singular']                    = true;
$GLOBALS['fake_post']                      = (object) array( 'post_content' => 'x [p20link show="abc-guid"] y' );
$_GET['show']                              = 'abc-guid';
unset( $_GET['episode'] );
// Drop spent rate-limit windows so the og resolve isn't throttled.
foreach ( array_keys( $GLOBALS['transients'] ) as $tk ) {
	if ( 0 === strpos( $tk, 'p20l_rl_' ) ) {
		unset( $GLOBALS['transients'][ $tk ] );
	}
}

check( 'wants_preview: on for singular shortcode page', true === call( $o, 'wants_preview' ) );
$GLOBALS['is_singular'] = false;
check( 'wants_preview: off when not singular', false === call( $o, 'wants_preview' ) );
$GLOBALS['is_singular'] = true;
$GLOBALS['fake_post']   = (object) array( 'post_content' => 'no shortcode' );
check( 'wants_preview: off without shortcode', false === call( $o, 'wants_preview' ) );
$GLOBALS['fake_post'] = (object) array( 'post_content' => '[p20link show="abc-guid"]' );

// Buffer strips the theme's blank og:description but keeps non-og head tags.
ob_start();
$o->og_buffer_start();
echo "<meta property=\"og:description\" content=\" \" />\n";
echo "<meta name=\"twitter:card\" content=\"summary\" />\n";
echo "<title>Theme Title</title>\n";
$o->output_social_preview_meta();
$o->og_buffer_end();
$out = ob_get_clean();
check( 'og buffer: our og:title emitted', false !== strpos( $out, 'og:title' ) && false !== strpos( $out, 'Podcasting 2.0' ) );
check( 'og buffer: theme blank og:description stripped', false === strpos( $out, 'content=" "' ) && 1 === substr_count( $out, 'og:description' ) );
check( 'og buffer: theme twitter card replaced', 1 === substr_count( $out, 'twitter:card' ) );
check( 'og buffer: non-og head tags kept', false !== strpos( $out, '<title>Theme Title</title>' ) );
$bp = new ReflectionProperty( $o, 'og_buffered' );
$bp->setAccessible( true );
check( 'og buffer: closed after end', false === $bp->getValue( $o ) );

// No show arg -> no buffer opened, theme tags pass through untouched.
unset( $_GET['show'] );
ob_start();
$o->og_buffer_start();
echo "<meta property=\"og:description\" content=\" \" />\n";
$o->og_buffer_end();
$out = ob_get_clean();
check( 'og buffer: skipped without show arg', false !== strpos( $out, 'content=" "' ) );
$_GET['show'] = 'abc-guid';

// Failed resolve -> our tags empty -> theme tags left alone (no blank strip).
$_GET['show'] = 'nope-guid';
$GLOBALS['http_mock'] = array( '/podcasts/byguid' => array( 'code' => 200, 'body' => json_encode( array( 'feed' => array() ) ) ) );
ob_start();
$o->og_buffer_start();
echo "<meta property=\"og:description\" content=\" \" />\n";
$o->output_social_preview_meta();
$o->og_buffer_end();
$out = ob_get_clean();
check( 'og buffer: failed resolve keeps theme tags', false !== strpos( $out, 'content=" "' ) && false === strpos( $out, 'og:title' ) );
unset( $_GET['show'], $GLOBALS['is_singular'], $GLOBALS['fake_post'] );
$GLOBALS['opts']['p20link_social_preview'] = 'on';

// --- fountain share-link search (paste a fountain.fm URL into the search box) ---
// Reverse of /search/lookup: GET /v1/content/{type}:id:{20-char id} -> guid(s).
// Live-verified 2026-11-16 with the user's own example ids (show
// DGn4FIswSZciwkqcow5d, episode 1Q17yntZitfbLF4Fm2vx), round-tripped back
// through /search/lookup to prove _guid is the podcast/episode guid.
$GLOBALS['http_mock'] = array();

// Not a Fountain share link -> null (plain titles keep using byterm search).
check( 'fountain search ignores a plain title', null === call( $o, 'search_fountain_link', 'some podcast' ) );
check( 'fountain search ignores other URLs', null === call( $o, 'search_fountain_link', 'https://podlink.example/show/x' ) );

// Cache hit: the derived fountain_url on the row recovers the guids with no HTTP.
$wpdb->rows['fsearch1'] = array(
	'lookup_hash'  => 'fsearch1',
	'show_ref'     => '254ceab2-c561-5479-bf9f-6980f7015689',
	'episode_guid' => '',
	'fountain_url' => 'https://fountain.fm/show/CacheShowId000000001',
);
$n = count( $GLOBALS['http_log'] );
check( 'fountain search show link from cache',
	array( 'ref' => '254ceab2-c561-5479-bf9f-6980f7015689', 'episode' => '' ) === call( $o, 'search_fountain_link', 'https://fountain.fm/show/CacheShowId000000001' )
	&& count( $GLOBALS['http_log'] ) === $n );
$wpdb->rows['fsearch2'] = array(
	'lookup_hash'  => 'fsearch2',
	'show_ref'     => '254ceab2-c561-5479-bf9f-6980f7015689',
	'episode_guid' => 'a961bb32-5ebc-4d1e-ba68-30242271fa1b',
	'fountain_url' => 'https://fountain.fm/episode/CacheEpId0000000001',
);
$n = count( $GLOBALS['http_log'] );
check( 'fountain search episode link from cache, query string ignored',
	array( 'ref' => '254ceab2-c561-5479-bf9f-6980f7015689', 'episode' => 'a961bb32-5ebc-4d1e-ba68-30242271fa1b' ) === call( $o, 'search_fountain_link', 'fountain.fm/episode/CacheEpId0000000001?utm_source=share' )
	&& count( $GLOBALS['http_log'] ) === $n );

// Cache miss: one GET to Fountain's content API returns the guids, remembered.
$GLOBALS['http_mock']['content/show:id:ApiShowId0000000001'] = array( 'code' => 200, 'body' => json_encode( array(
	'hit' => array( '_id' => 'ApiShowId0000000001', '_guid' => '11111111-2222-3333-4444-555555555555', '_type' => 'SHOW' ),
) ) );
check( 'fountain search show via content API',
	array( 'ref' => '11111111-2222-3333-4444-555555555555', 'episode' => '' ) === call( $o, 'search_fountain_link', 'https://fountain.fm/show/ApiShowId0000000001' ) );
$req = end( $GLOBALS['http_log'] );
check( 'fountain reverse hits the content endpoint without redirects',
	false !== strpos( $req[0], '/content/show:id:ApiShowId0000000001' ) && 0 === $req[1]['redirection'] );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_fountain_link', 'https://fountain.fm/show/ApiShowId0000000001' );
check( 'fountain reverse result remembered', count( $GLOBALS['http_log'] ) === $n );

// Episode: parent._guid is the show guid; hit.links is the fallback.
$GLOBALS['http_mock']['content/episode:id:ApiEpId000000000001'] = array( 'code' => 200, 'body' => json_encode( array(
	'hit'    => array( '_id' => 'ApiEpId000000000001', '_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', '_type' => 'EPISODE' ),
	'parent' => array( '_id' => 'ApiShowId0000000001', '_guid' => '99999999-8888-7777-6666-555555555555', '_type' => 'SHOW' ),
) ) );
check( 'fountain search episode via content API',
	array( 'ref' => '99999999-8888-7777-6666-555555555555', 'episode' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ) === call( $o, 'search_fountain_link', 'https://fountain.fm/episode/ApiEpId000000000001' ) );

$GLOBALS['http_mock']['content/episode:id:LinkEpId00000000001'] = array( 'code' => 200, 'body' => json_encode( array(
	'hit' => array(
		'_id'   => 'LinkEpId00000000001',
		'_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
		'_type' => 'EPISODE',
		'links' => array( 'show:id:whatever', 'show:guid:77777777-6666-5555-4444-333333333333' ),
	),
) ) );
check( 'fountain search episode show-guid falls back to links',
	array( 'ref' => '77777777-6666-5555-4444-333333333333', 'episode' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ) === call( $o, 'search_fountain_link', 'https://fountain.fm/episode/LinkEpId00000000001' ) );

// 404 = definitive miss (remembered), surfaced as empty results.
$GLOBALS['http_mock']['content/show:id:MissingId00000000001'] = array( 'code' => 404, 'body' => '' );
check( 'fountain search 404 -> empty results', array() === call( $o, 'search_fountain_link', 'https://fountain.fm/show/MissingId00000000001' ) );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_fountain_link', 'https://fountain.fm/show/MissingId00000000001' );
check( 'fountain search miss remembered', count( $GLOBALS['http_log'] ) === $n );

// 5xx = unreliable (false -> 502), remembered only briefly, never as a miss.
$GLOBALS['http_mock']['content/show:id:FlakyId0000000000001'] = array( 'code' => 503, 'body' => '' );
check( 'fountain search 503 -> unreliable', false === call( $o, 'search_fountain_link', 'https://fountain.fm/show/FlakyId0000000000001' ) );
$n = count( $GLOBALS['http_log'] );
check( 'fountain search unreliable remembered briefly', false === call( $o, 'search_fountain_link', 'https://fountain.fm/show/FlakyId0000000000001' ) && count( $GLOBALS['http_log'] ) === $n );

// A readable hit without usable guids is a miss; a wrong type proves nothing.
$GLOBALS['http_mock']['content/show:id:NoGuidId00000000001'] = array( 'code' => 200, 'body' => json_encode( array(
	'hit' => array( '_id' => 'NoGuidId00000000001', '_guid' => '', '_type' => 'SHOW' ),
) ) );
check( 'fountain search hit without guid -> empty results', array() === call( $o, 'search_fountain_link', 'https://fountain.fm/show/NoGuidId00000000001' ) );
$GLOBALS['http_mock']['content/show:id:WrongTypeId000000001'] = array( 'code' => 200, 'body' => json_encode( array(
	'hit' => array( '_id' => 'WrongTypeId000000001', '_guid' => '11111111-2222-3333-4444-555555555555', '_type' => 'EPISODE' ),
) ) );
check( 'fountain search wrong type -> unreliable', false === call( $o, 'search_fountain_link', 'https://fountain.fm/show/WrongTypeId000000001' ) );

// ajax_search wiring: a direct payload short-circuits the byterm search.
function p20_catch_json( $o ) {
	try {
		$o->ajax_search();
	} catch ( P20_Json_Response $r ) {
		return $r;
	}
	return null;
}
$_POST = array( 'type' => 'shows', 'q' => 'https://fountain.fm/show/ApiShowId0000000001' );
$r = p20_catch_json( $o );
check( 'ajax fountain show -> direct payload',
	$r && $r->ok && isset( $r->payload['direct']['ref'] )
	&& '11111111-2222-3333-4444-555555555555' === $r->payload['direct']['ref']
	&& '' === $r->payload['direct']['episode'] );
$_POST = array( 'type' => 'shows', 'q' => 'https://fountain.fm/show/MissingId00000000001' );
$r = p20_catch_json( $o );
check( 'ajax fountain miss -> empty shows',
	$r && $r->ok && isset( $r->payload['shows'] ) && array() === $r->payload['shows'] && ! isset( $r->payload['direct'] ) );
$_POST = array( 'type' => 'shows', 'q' => 'plain title' );
$GLOBALS['http_mock']['search/byterm'] = array( 'code' => 200, 'body' => json_encode( array(
	'feeds' => array( array( 'id' => 75075, 'title' => 'S', 'author' => 'A', 'podcastGuid' => 'g' ) ),
) ) );
$r = p20_catch_json( $o );
check( 'ajax plain query still uses byterm search',
	$r && $r->ok && isset( $r->payload['shows'][0]['ref'] ) && 'g' === $r->payload['shows'][0]['ref'] );
$_POST = array();
$GLOBALS['http_mock'] = array();

// --- direct search: the other target apps' share URLs (README app table) ---
// Guid/feed-id-carrying apps resolve with no HTTP at all.
$n = count( $GLOBALS['http_log'] );
check( 'search curiocaster share link', array( 'ref' => '75075', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://curiocaster.com/podcast/pi75075' ) );
check( 'search podcastindex share link (guid)', array( 'ref' => 'b2d9f1a0-1111-2222-3333-444455556666', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podcastindex.org/podcast/b2d9f1a0-1111-2222-3333-444455556666' ) );
check( 'search podcastindex share link (feed id, schemeless)', array( 'ref' => '75075', 'episode' => '' ) === call( $o, 'search_direct_link', 'podcastindex.org/podcast/75075' ) );
check( 'search castamatic share link', array( 'ref' => 'g', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://castamatic.com/guid/g' ) );
check( 'search truefans share link (query string ignored)', array( 'ref' => 'g', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://truefans.fm/g?utm_source=share' ) );
check( 'search api.podverse.fm show link', array( 'ref' => '75075', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://api.podverse.fm/api/v1/podcast/podcastindex/75075' ) );
check( 'search boostmebuddy link with episode', array( 'ref' => 'g', 'episode' => 'PC2084/a b' ) === call( $o, 'search_direct_link', 'https://www.boostmebuddy.com/?podcast=g&episode=PC2084%2Fa%20b' ) );
check( 'search boostmebuddy show-only link', array( 'ref' => 'g', 'episode' => '' ) === call( $o, 'search_direct_link', 'boostmebuddy.com/?podcast=g' ) );
check( 'search boostmebuddy without podcast param ignored', null === call( $o, 'search_direct_link', 'https://boostmebuddy.com/?other=1' ) );
check( 'guid/feed-id share links need no HTTP', count( $GLOBALS['http_log'] ) === $n );
check( 'search dispatcher still handles fountain links', array( 'ref' => '11111111-2222-3333-4444-555555555555', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://fountain.fm/show/ApiShowId0000000001' ) );

// Apple-id keyed links (Podcast Guru; legacy Podfriend pastes) need one
// PI byitunesid call, then remember the mapping. Podfriend's digits go
// to byfeedid first (Podfriend's current URL space, live 2026-11-20)
// and only fall back to byitunesid when PI knows no such feed id.
$GLOBALS['http_mock']['podcasts/byfeedid']   = array( 'code' => 200, 'body' => json_encode( array( 'feed' => null ) ) );
$GLOBALS['http_mock']['podcasts/byitunesid'] = array( 'code' => 200, 'body' => json_encode( array( 'feed' => array( 'id' => 1234, 'podcastGuid' => 'appleshowguid-001' ) ) ) );
$n = count( $GLOBALS['http_log'] );
check( 'search podfriend legacy apple link (feed id miss, apple fallback)', array( 'ref' => 'appleshowguid-001', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/1234' ) );
check( 'search podfriend episode link keeps guid', array( 'ref' => 'appleshowguid-001', 'episode' => 'PC2084/a b' ) === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/1234/PC2084%2Fa%20b' ) );
check( 'search podcastguru share link via apple id', array( 'ref' => 'appleshowguid-001', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://app.podcastguru.io/podcast/1234' ) );
check( 'apple id resolved with feedid-miss + itunesid calls, then remembered', count( $GLOBALS['http_log'] ) === $n + 2 );
$GLOBALS['http_mock']['podcasts/byitunesid'] = array( 'code' => 200, 'body' => json_encode( array( 'feed' => null ) ) );
check( 'search unknown apple id -> empty results', array() === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/999999999' ) );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_direct_link', 'https://podfriend.com/podcast/999999999' );
check( 'apple id miss remembered', count( $GLOBALS['http_log'] ) === $n );
$GLOBALS['http_mock']['podcasts/byitunesid'] = array( 'code' => 500, 'body' => '' );
check( 'search apple id 500 -> unreliable', false === call( $o, 'search_direct_link', 'https://app.podcastguru.io/podcast/555555555' ) );

// Podfriend's current share links carry the PI feed id: resolved first,
// no Apple guess; a podcast:guid pastes straight through; slugs can't be
// reversed offline.
$GLOBALS['http_mock']['podcasts/byfeedid'] = array( 'code' => 200, 'body' => json_encode( array( 'feed' => array( 'id' => 75075, 'podcastGuid' => 'g' ) ) ) );
check( 'search podfriend feed-id link', array( 'ref' => 'g', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/75075' ) );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_direct_link', 'https://podfriend.com/podcast/75075' );
check( 'podfriend feed-id lookup remembered', count( $GLOBALS['http_log'] ) === $n );
check( 'search podfriend guid link needs no HTTP', array( 'ref' => '917393e3-1b1e-5cef-ace4-edaa54e1f810', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/917393e3-1b1e-5cef-ace4-edaa54e1f810' ) && count( $GLOBALS['http_log'] ) === $n );
check( 'search podfriend slug link ignored', null === call( $o, 'search_direct_link', 'https://podfriend.com/podcast/podcasting-2-0' ) );

// Podverse web ids reverse through Podverse's own API (live-verified
// 2026-11-18): podcast -> podcastGuid, episode -> RSS guid + parent.
$GLOBALS['http_mock']['api.podverse.fm/api/v1/podcast/peLVTHMwlg'] = array( 'code' => 200, 'body' => json_encode( array(
	'id'             => 'peLVTHMwlg',
	'podcastIndexId' => '920666',
	'podcastGuid'    => '917393e3-1b1e-5cef-ace4-edaa54e1f810',
) ) );
check( 'search podverse show link reversed', array( 'ref' => '917393e3-1b1e-5cef-ace4-edaa54e1f810', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podverse.fm/podcast/peLVTHMwlg' ) );
$pv_rev = end( $GLOBALS['http_log'] );
check( 'podverse reverse hits its API without redirects',
	false !== strpos( $pv_rev[0], '/api/v1/podcast/peLVTHMwlg' )
	&& 0 === $pv_rev[1]['redirection'] );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_direct_link', 'https://podverse.fm/podcast/peLVTHMwlg' );
check( 'podverse show reverse remembered', count( $GLOBALS['http_log'] ) === $n );
$GLOBALS['http_mock']['api.podverse.fm/api/v1/episode/8kDmgoJKa'] = array( 'code' => 200, 'body' => json_encode( array(
	'guid'    => 'PC20-278',
	'podcast' => array(
		'id'             => 'peLVTHMwlg',
		'podcastIndexId' => '920666',
		'podcastGuid'    => '917393e3-1b1e-5cef-ace4-edaa54e1f810',
	),
) ) );
check( 'search podverse episode link reversed', array( 'ref' => '917393e3-1b1e-5cef-ace4-edaa54e1f810', 'episode' => 'PC20-278' ) === call( $o, 'search_direct_link', 'https://podverse.fm/episode/8kDmgoJKa' ) );
$GLOBALS['http_mock']['api.podverse.fm/api/v1/episode/PvNoGuidEpisode01'] = array( 'code' => 200, 'body' => json_encode( array(
	'id'      => 'PvNoGuidEpisode01',
	'podcast' => array( 'podcastIndexId' => '920666' ),
) ) );
check( 'search podverse episode without guid degrades to show', array( 'ref' => '920666', 'episode' => '' ) === call( $o, 'search_direct_link', 'https://podverse.fm/episode/PvNoGuidEpisode01' ) );
$GLOBALS['http_mock']['api.podverse.fm/api/v1/podcast/PvNoGuidShow01'] = array( 'code' => 200, 'body' => json_encode( array( 'id' => 'PvNoGuidShow01', 'name' => 'X' ) ) );
check( 'search podverse show without usable guid -> empty results', array() === call( $o, 'search_direct_link', 'https://podverse.fm/podcast/PvNoGuidShow01' ) );
$GLOBALS['http_mock']['api.podverse.fm/api/v1/podcast/PvMissing0000001'] = array( 'code' => 404, 'body' => '' );
check( 'search podverse unknown show -> empty results', array() === call( $o, 'search_direct_link', 'https://podverse.fm/podcast/PvMissing0000001' ) );
$n = count( $GLOBALS['http_log'] );
call( $o, 'search_direct_link', 'https://podverse.fm/podcast/PvMissing0000001' );
check( 'podverse reverse miss remembered', count( $GLOBALS['http_log'] ) === $n );
$GLOBALS['http_mock']['api.podverse.fm/api/v1/podcast/PvFlaky00000001'] = array( 'code' => 500, 'body' => '' );
check( 'search podverse 500 -> unreliable', false === call( $o, 'search_direct_link', 'https://podverse.fm/podcast/PvFlaky00000001' ) );

// Anything else still falls through to the byterm search.
check( 'search ignores unknown hosts', null === call( $o, 'search_direct_link', 'https://example.com/show/1' ) );
check( 'search ignores non-share paths', null === call( $o, 'search_direct_link', 'https://castamatic.com/about' ) );
check( 'search ignores bare truefans domain', null === call( $o, 'search_direct_link', 'truefans.fm' ) );
check( 'search ignores plain titles', null === call( $o, 'search_direct_link', 'some podcast' ) );

// ajax_search wiring: an app share link jumps just like a Fountain one.
$_POST = array( 'type' => 'shows', 'q' => 'https://curiocaster.com/podcast/pi75075' );
$r = p20_catch_json( $o );
check( 'ajax app share link -> direct payload',
	$r && $r->ok && isset( $r->payload['direct']['ref'] ) && '75075' === $r->payload['direct']['ref'] );
$_POST = array();
$GLOBALS['http_mock'] = array();

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
