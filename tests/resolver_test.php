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
		return isset( $this->rows[ $h ] ) ? (object) $this->rows[ $h ] : null;
	}
	public function replace( $t, $data ) { $this->rows[ $data['lookup_hash'] ] = $data; return 1; }
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
check( 'podfriend show', 'https://podfriend.com/podcast/1234' === call( $o, 'app_link', 'podfriend', $base )['url'] );
$noapple = $base; $noapple['itunes_id'] = 0;
check( 'podfriend omitted without apple id', null === call( $o, 'app_link', 'podfriend', $noapple ) );
check( 'boostmebuddy show', 'https://www.boostmebuddy.com/?podcast=g' === call( $o, 'app_link', 'boostmebuddy', $base )['url'] );
$noguid = $base; $noguid['podcast_guid'] = '';
check( 'boostmebuddy omitted without podcast guid', null === call( $o, 'app_link', 'boostmebuddy', $noguid ) );

$ep = $base;
$ep['episode_wanted'] = true; $ep['episode_guid'] = 'PC2084/a b'; $ep['pi_episode_id'] = 16795090;
$l = call( $o, 'app_link', 'fountain', $ep );
check( 'fountain episode link', 'https://fountain.fm/episode/EfGhEp456' === $l['url'] && $l['episode'] );
$l = call( $o, 'app_link', 'podfriend', $ep );
check( 'podfriend episode link encodes guid', 'https://podfriend.com/podcast/1234/PC2084%2Fa%20b' === $l['url'] && $l['episode'] );
$l = call( $o, 'app_link', 'curiocaster', $ep );
check( 'curiocaster episode degrades to show', ! $l['episode'] && 'https://curiocaster.com/podcast/pi75075' === $l['url'] );
$l = call( $o, 'app_link', 'podverse', $ep );
check( 'podverse episode degrades to show', ! $l['episode'] );
$l = call( $o, 'app_link', 'boostmebuddy', $ep );
check( 'boostmebuddy episode link', 'https://www.boostmebuddy.com/?podcast=g&episode=PC2084%2Fa%20b' === $l['url'] && $l['episode'] );
$miss_ep = $ep; $miss_ep['episode_missing'] = true;
$l = call( $o, 'app_link', 'boostmebuddy', $miss_ep );
check( 'boostmebuddy missing episode degrades to show', ! $l['episode'] && 'https://www.boostmebuddy.com/?podcast=g' === $l['url'] );
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

// 200 without a match = definitive miss (remembered for an hour).
$GLOBALS['http_mock']['api.fountain.fm'] = array( 'code' => 200, 'body' => json_encode( array( 'hits' => array() ) ) );
check( 'fountain empty hits -> miss', '' === call( $o, 'fountain_id', 'show', 'never-there' ) );
check( 'fountain definitive miss TTL', HOUR_IN_SECONDS === call( $o, 'fountain_ttl' ) );

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

// --- resolution + caching (mocked API) ---
$GLOBALS['http_log'] = array(); // Fountain lookups above already logged.
$feed_json = json_encode( array( 'status' => 'true', 'feed' => array(
	'id' => 920666, 'podcastGuid' => 'abc-guid', 'title' => 'Podcasting 2.0', 'author' => 'Adam',
	'image' => 'https://img/i.jpg', 'artwork' => 'https://img/art.jpg', 'itunesId' => 1554567890,
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
check( 'render: 5 app buttons', 5 === substr_count( $html, 'class="p20link-item ' ) );
check( 'render: episode title shown', false !== strpos( $html, 'Ep 1' ) );
check( 'render: fountain episode href', false !== strpos( $html, 'https://fountain.fm/episode/EpRender1' ) );
check( 'render: degraded apps flagged', substr_count( $html, '(opens the show)' ) === 2 );
$nr = $ep; $nr['episode_title'] = 'T';
$nh = call( $o, 'render_html', array(
	'fountain' => array( 'url' => 'https://fountain.fm/discover', 'label' => 'Fountain', 'episode' => false, 'icon_url' => '', 'note' => 'discover' ),
), $nr, false, 'S', 'A', '' );
check( 'render: discover fallback note', false !== strpos( $nh, '(opens Fountain)' ) );
check( 'render: no share/url param leak of API secret', false === strpos( $html, 'SECRET' ) );
check( 'render: PodcastLinker promo above footer', false !== strpos( $html, 'Share a show with' ) && false !== strpos( $html, 'href="https://phafe.com/podcastlinker"' ) && strpos( $html, 'phafe.com/podcastlinker' ) < strpos( $html, 'podcastindex.org' ) );

$html = $o->render( array( 'show' => 'abc-guid', 'header' => 'false' ) );
check( 'render header=false hides title', false === strpos( $html, 'p20link-title' ) );

// disabled app
$GLOBALS['opts']['p20link_apps'] = array( 'fountain' => array( 'enabled' => false ) );
$html = $o->render( array( 'show' => 'abc-guid' ) );
check( 'disabled app hidden', false === strpos( $html, 'fountain.fm/show' ) && 4 === substr_count( $html, 'class="p20link-item ' ) );

// no show -> search UI; no creds -> message
$html = $o->render( array() );
check( 'no show -> search UI', false !== strpos( $html, 'p20link-search-input' ) );
check( 'search UI has PodcastLinker h1 on top', false !== strpos( $html, '<h1 class="p20link-search-h1">PodcastLinker</h1>' ) && strpos( $html, 'p20link-search-h1' ) < strpos( $html, 'p20link-search-title' ) );
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

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
