<?php
/**
 * Plugin Name: Podcast 2.0 Link Resolver
 * Description: pod.link-style smart links for podcasts, limited to Podcasting 2.0 apps (Fountain, CurioCaster, Podverse, Podfriend, BoostMeBuddy, Castamatic, TrueFans, Podcast Index, Podcast Guru). Link to a show or a single episode by its podcast:guid / episode guid; metadata comes from the Podcast Index API. Shortcode: [p20link].
 * Version: 0.1.15
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Matt Finlay
 * License: GPL-2.0-or-later
 * Text Domain: p20-link
 *
 * USAGE:
 * 1. Install and activate. Get a free API key + secret at https://api.podcastindex.org
 *    and enter them under Settings > Podcast 2.0 Link (or define P20LINK_API_KEY /
 *    P20LINK_API_SECRET in wp-config.php, which takes priority).
 * 2. Create a Page (e.g. slug "podlink") containing the shortcode: [p20link]
 *    Link to it as:
 *      https://example.com/podlink                                  (search box)
 *      https://example.com/podlink?show={podcast-guid}
 *      https://example.com/podlink?show={podcast-guid}&episode={episode-guid}
 *    `show` may also be a numeric Podcast Index feed id.
 *    Or hardcode a placement inline in any post/page:
 *      [p20link show="{podcast-guid}"]
 *      [p20link show="{podcast-guid}" episode="{episode-guid}"]
 *      [p20link show="{podcast-guid}" header="false"]
 *
 * TARGET APPS: CurioCaster / Podverse / Podfriend / Castamatic / TrueFans /
 * Podcast Index / Podcast Guru follow the Podcast Index
 * "podcast-platform-links" guide (show links only for the last four - their
 * episode links need app-internal ids).
 * Fountain is the exception: its public URLs use opaque Fountain ids, so show
 * and episode links are resolved from the podcast:guid / episode guid through
 * Fountain's public lookup API (api.fountain.fm, no key required), cached in
 * transients and remembered on the cache row as a derived URL for 30 days.
 * Links degrade episode -> show -> fountain.fm/discover, so
 * the Fountain button always appears and never opens the wrong episode; the
 * UI note says what it opens when it can't open the requested episode.
 *
 * CACHING: one row per show/episode in {prefix}p20link_cache. Stale rows keep
 * serving while a refresh runs on the shutdown hook, so a Podcast Index outage
 * never blanks an already-seen release. Misses are remembered in short
 * transients (Podcast Index definitive: 1 hour, unreliable: 30 seconds;
 * Fountain definitive: 30 minutes, unreliable: 30 seconds).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class P20_Link_Resolver {

	const VERSION             = '0.1.15';
	const PI_API              = 'https://api.podcastindex.org/api/1.0';
	const REFRESH_DEFAULT     = 12;       // Hours between background refreshes.
	const FAIL_TTL            = HOUR_IN_SECONDS;
	const SOFT_FAIL_TTL       = 30;
	const NEG_SCHEMA          = 1;        // Bump to invalidate remembered misses.
	const TABLE_VERSION       = 3;        // Bump when a CREATE TABLE changes (3: +feed_url).
	const FOUNTAIN_FAIL_TTL   = 30 * MINUTE_IN_SECONDS; // Fountain definitive misses (shorter than neg_ttl()'s hour).
	const DERIVED_URL_TTL     = 30 * DAY_IN_SECONDS;    // Fountain URL cached on the row before re-deriving.
	const SEARCH_MAX          = 40;
	const SEARCH_CACHE_TTL    = HOUR_IN_SECONDS;
	const SEARCH_CACHE_SCHEMA = 1;
	const EPISODES_MAX        = 25;
	const RESOLVE_RATE_MAX    = 60;       // Upstream resolves per IP per minute.
	const SEARCH_RATE_MAX     = 30;       // Search relay calls per IP per minute.
	const FOUNTAIN_API        = 'https://api.fountain.fm/v1';
	const FOUNTAIN_HIT_TTL    = 30 * DAY_IN_SECONDS; // Fountain ids are permanent.
	const FOUNTAIN_SCHEMA     = 1;        // Bump to invalidate remembered Fountain lookups.
	const PODVERSE_API        = 'https://api.podverse.fm/api/v1';
	const PODVERSE_HIT_TTL    = 30 * DAY_IN_SECONDS; // Podverse episode URLs are stable.
	const PODVERSE_FAIL_TTL   = HOUR_IN_SECONDS;     // Definitive Podverse misses (PI neg_ttl policy).
	const DIRECT_HIT_TTL      = 30 * DAY_IN_SECONDS; // Direct-input lookups (Apple id, Podverse web ids) are stable.
	const DIRECT_SCHEMA       = 1;        // Bump to invalidate remembered direct-input lookups.

	/** HTTP status of the last Podcast Index call (0 = transport/undecodable). */
	private $last_status = 0;

	/** HTTP status of the last Fountain lookup (0 = transport/undecodable). */
	private $fountain_status = 0;

	/** HTTP status of the last Podverse url-resolver call (0 = transport/undecodable). */
	private $podverse_status = 0;

	/** True only while rendering a page: allows queueing background refreshes. */
	private $refresh_allowed = false;

	/** True while handling visitor-supplied ids: upstream calls spend a per-IP budget. */
	private $resolve_budgeted = false;

	/** hash => array( show, episode ) queued for refresh on shutdown. */
	private $refresh_pending = array();

	/** Per-request memo of cache-row reads. */
	private $row_memo = array();

	/** True while our own wp_head output buffer is open. */
	private $og_buffered = false;

	/** Our og/twitter meta HTML, held until the buffer closes (or emitted directly). */
	private $og_meta_html = '';

	public function __construct() {
		add_shortcode( 'p20link', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'og_buffer_start' ), 0 );
		add_action( 'wp_head', array( $this, 'output_social_preview_meta' ) );
		add_action( 'wp_head', array( $this, 'og_buffer_end' ), 999 );
		add_action( 'wp_ajax_p20link_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_p20link_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_p20link_log_open', array( $this, 'ajax_log_open' ) );
		add_action( 'wp_ajax_nopriv_p20link_log_open', array( $this, 'ajax_log_open' ) );
		add_action( 'wp_ajax_p20link_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'wp_ajax_nopriv_p20link_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );
		add_action( 'admin_post_p20link_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_p20link_delete_cache_row', array( $this, 'handle_delete_cache_row' ) );
		add_action( 'admin_post_p20link_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'shutdown', array( $this, 'run_queued_refresh' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Options / credentials
	 * ------------------------------------------------------------------ */

	private function opt( $name, $default = '' ) {
		return get_option( 'p20link_' . $name, $default );
	}

	private function credentials() {
		$key    = defined( 'P20LINK_API_KEY' ) ? (string) P20LINK_API_KEY : (string) $this->opt( 'api_key' );
		$secret = defined( 'P20LINK_API_SECRET' ) ? (string) P20LINK_API_SECRET : (string) $this->opt( 'api_secret' );
		return array( trim( $key ), trim( $secret ) );
	}

	private function has_credentials() {
		list( $key, $secret ) = $this->credentials();
		return '' !== $key && '' !== $secret;
	}

	/* ------------------------------------------------------------------ *
	 * Identifier guards
	 * ------------------------------------------------------------------ */

	/** A show reference: podcast:guid (UUID-ish) or a numeric Podcast Index feed id. */
	private function guard_show( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/', $value ) ? strtolower( $value ) : '';
	}

	/** Episode guids are arbitrary strings: strip control characters, cap the length. */
	private function guard_episode( $value ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value );
		$value = trim( (string) $value );
		return ( '' !== $value && strlen( $value ) <= 512 ) ? $value : '';
	}

	private function is_feed_id( $show ) {
		return '' !== $show && ctype_digit( $show );
	}

	private function get_arg( $key ) {
		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return 'show' === $key ? $this->guard_show( $raw ) : $this->guard_episode( $raw );
	}

	private static function encode_uri_component( $value ) {
		return strtr( rawurlencode( (string) $value ), array(
			'%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')',
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Database
	 * ------------------------------------------------------------------ */

	private function cache_table() {
		global $wpdb;
		return $wpdb->prefix . 'p20link_cache';
	}

	private function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'p20link_log';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$cache   = $wpdb->prefix . 'p20link_cache';
		$log     = $wpdb->prefix . 'p20link_log';

		dbDelta( "CREATE TABLE {$cache} (
			lookup_hash char(32) NOT NULL,
			show_ref varchar(64) NOT NULL DEFAULT '',
			episode_guid varchar(512) NOT NULL DEFAULT '',
			feed_id bigint(20) unsigned NOT NULL DEFAULT 0,
			podcast_guid varchar(64) NOT NULL DEFAULT '',
			itunes_id bigint(20) unsigned NOT NULL DEFAULT 0,
			feed_url text NOT NULL,
			show_title text NOT NULL,
			author text NOT NULL,
			image_url text NOT NULL,
			pi_episode_id bigint(20) unsigned NOT NULL DEFAULT 0,
			episode_title text NOT NULL,
			episode_image text NOT NULL,
			fountain_url varchar(512) NOT NULL DEFAULT '',
			fountain_date datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (lookup_hash)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			show_title text NOT NULL,
			episode_title text NOT NULL,
			app varchar(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY logged_at (logged_at)
		) {$charset};" );

		update_option( 'p20link_table_version', self::TABLE_VERSION );
	}

	/** Activation hooks never fire on direct file overwrite, so check on admin load too. */
	public function maybe_create_tables() {
		if ( (int) get_option( 'p20link_table_version', 0 ) < self::TABLE_VERSION ) {
			self::create_tables();
		}
	}

	private function row_hash( $show, $episode ) {
		return md5( strtolower( $show ) . '|' . $episode );
	}

	private function cache_get_row( $hash ) {
		global $wpdb;
		if ( array_key_exists( $hash, $this->row_memo ) ) {
			return $this->row_memo[ $hash ];
		}
		$table = $this->cache_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lookup_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->row_memo[ $hash ] = $row ? $row : false;
		return $this->row_memo[ $hash ];
	}

	private function cache_store( $show, $episode, $d ) {
		global $wpdb;
		$hash = $this->row_hash( $show, $episode );
		// REPLACE overwrites the whole row: carry the derived Fountain URL
		// across so a metadata refresh never drops it.
		$prev   = $this->cache_get_row( $hash );
		$f_url  = ( $prev && isset( $prev->fountain_url ) ) ? (string) $prev->fountain_url : '';
		$f_date = ( $prev && isset( $prev->fountain_date ) && '' !== $prev->fountain_date ) ? (string) $prev->fountain_date : '1970-01-01 00:00:00';
		$wpdb->replace(
			$this->cache_table(),
			array(
				'lookup_hash'   => $hash,
				'show_ref'      => $show,
				'episode_guid'  => $episode,
				'feed_id'       => (int) $d['feed_id'],
				'podcast_guid'  => (string) $d['podcast_guid'],
				'itunes_id'     => (int) $d['itunes_id'],
				'feed_url'      => isset( $d['feed_url'] ) ? (string) $d['feed_url'] : '',
				'show_title'    => (string) $d['show_title'],
				'author'        => (string) $d['author'],
				'image_url'     => (string) $d['image'],
				'pi_episode_id' => (int) $d['pi_episode_id'],
				'episode_title' => (string) $d['episode_title'],
				'episode_image' => (string) $d['episode_image'],
				'fountain_url'  => $f_url,
				'fountain_date' => $f_date,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		unset( $this->row_memo[ $hash ] );
	}

	/** Remember a derived Fountain URL on its cache row (row must already exist). */
	private function cache_set_fountain_url( $hash, $url ) {
		global $wpdb;
		$url  = (string) $url;
		$when = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			$this->cache_table(),
			array(
				'fountain_url'  => $url,
				'fountain_date' => $when,
			),
			array( 'lookup_hash' => $hash ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( isset( $this->row_memo[ $hash ] ) && is_object( $this->row_memo[ $hash ] ) ) {
			$this->row_memo[ $hash ]->fountain_url  = $url;
			$this->row_memo[ $hash ]->fountain_date = $when;
		}
	}

	private function refresh_interval() {
		$hours = (int) $this->opt( 'refresh_hours', self::REFRESH_DEFAULT );
		return max( 1, min( 720, $hours ) ) * HOUR_IN_SECONDS;
	}

	private function row_is_stale( $row ) {
		$ts = strtotime( $row->updated_at . ' UTC' );
		return ! $ts || ( time() - $ts ) > $this->refresh_interval();
	}

	/* ------------------------------------------------------------------ *
	 * Podcast Index API
	 * ------------------------------------------------------------------ */

	/** Auth headers per the Podcast Index docs: Authorization = sha1( key . secret . unixtime ). */
	private function api_headers( $time = null ) {
		list( $key, $secret ) = $this->credentials();
		$time                 = null === $time ? time() : (int) $time;
		return array(
			'User-Agent'    => 'P20LinkResolver/' . self::VERSION . ' (' . home_url() . ')',
			'X-Auth-Key'    => $key,
			'X-Auth-Date'   => (string) $time,
			'Authorization' => sha1( $key . $secret . $time ),
		);
	}

	private function api_get( $path, $query ) {
		if ( ! $this->has_credentials() ) {
			$this->last_status = 0;
			return null;
		}
		$url = add_query_arg( array_map( 'rawurlencode', $query ), self::PI_API . $path );

		// redirection => 0: never follow a remote redirect (SSRF hardening).
		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => $this->api_headers(),
		) );

		if ( is_wp_error( $response ) ) {
			$this->last_status = 0;
			return null;
		}
		$this->last_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $this->last_status ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$this->last_status = 0; // An undecodable 200 proves nothing.
			return null;
		}
		return $body;
	}

	private function neg_ttl() {
		return ( 200 === $this->last_status || 404 === $this->last_status ) ? self::FAIL_TTL : self::SOFT_FAIL_TTL;
	}

	private function neg_key( $kind, $show, $episode = '' ) {
		return 'p20l_neg_' . self::NEG_SCHEMA . '_' . md5( $kind . '|' . $show . '|' . $episode );
	}

	/** Per-IP fixed-window counter on REMOTE_ADDR only (never a spoofable header). */
	private function is_rate_limited( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'p20l_rl_' . md5( $bucket . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	/** False when this request must not make another upstream call. */
	private function may_call_upstream() {
		if ( $this->resolve_budgeted && $this->is_rate_limited( 'resolve', self::RESOLVE_RATE_MAX, MINUTE_IN_SECONDS ) ) {
			return false;
		}
		return true;
	}

	private function fetch_show( $show ) {
		$body = $this->is_feed_id( $show )
			? $this->api_get( '/podcasts/byfeedid', array( 'id' => $show ) )
			: $this->api_get( '/podcasts/byguid', array( 'guid' => $show ) );

		if ( null === $body ) {
			return null;
		}
		$feed = isset( $body['feed'] ) && is_array( $body['feed'] ) ? $body['feed'] : array();
		if ( empty( $feed['id'] ) ) {
			return null; // 200 with an empty feed = definitive "unknown show".
		}
		$image = ! empty( $feed['artwork'] ) ? $feed['artwork'] : ( isset( $feed['image'] ) ? $feed['image'] : '' );

		return array(
			'feed_id'       => (int) $feed['id'],
			'podcast_guid'  => isset( $feed['podcastGuid'] ) ? (string) $feed['podcastGuid'] : '',
			'itunes_id'     => isset( $feed['itunesId'] ) ? (int) $feed['itunesId'] : 0,
			'feed_url'      => isset( $feed['url'] ) ? (string) $feed['url'] : '',
			'show_title'    => isset( $feed['title'] ) ? (string) $feed['title'] : '',
			'author'        => ! empty( $feed['author'] ) ? (string) $feed['author'] : ( isset( $feed['ownerName'] ) ? (string) $feed['ownerName'] : '' ),
			'image'         => (string) $image,
			'pi_episode_id' => 0,
			'episode_title' => '',
			'episode_image' => '',
		);
	}

	private function fetch_episode( $feed_id, $episode_guid ) {
		$body = $this->api_get( '/episodes/byguid', array( 'guid' => $episode_guid, 'feedid' => (string) $feed_id ) );
		if ( null === $body ) {
			return null;
		}
		$ep = isset( $body['episode'] ) && is_array( $body['episode'] ) ? $body['episode'] : array();
		if ( empty( $ep['id'] ) ) {
			return null;
		}
		return array(
			'pi_episode_id' => (int) $ep['id'],
			'episode_title' => isset( $ep['title'] ) ? (string) $ep['title'] : '',
			'episode_image' => isset( $ep['image'] ) ? (string) $ep['image'] : '',
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fountain lookup API
	 * ------------------------------------------------------------------ */

	/**
	 * Fountain's public URL ids are opaque tokens, not Podcast Index ids:
	 * the platform-links guide's fountain.fm/show|episode/{PI id} patterns
	 * render Fountain's client-side 404 (verified live 2026-11-12), as do
	 * all numeric ids. Resolve guid -> Fountain id through Fountain's
	 * documented lookup endpoint (no key required), cached in transients:
	 * hits are remembered long (ids are permanent); definitive misses for
	 * 30 minutes, unreliable answers for 30 seconds (see fountain_ttl()).
	 *
	 * @param string $kind   'show' or 'episode'.
	 * @param string $guid   podcast:guid (show) or RSS item guid (episode).
	 * @param string $parent podcast:guid of the feed; required for episodes.
	 * @return string|null Fountain id; '' = definitive miss (Fountain doesn't
	 *                      have it); null = unreliable answer (soft failure or
	 *                      spent budget) - callers must never treat null as
	 *                      "Fountain doesn't have it".
	 */
	private function fountain_id( $kind, $guid, $parent = '' ) {
		$guid   = (string) $guid;
		$parent = (string) $parent;
		if ( ( 'show' !== $kind && 'episode' !== $kind ) || '' === $guid ) {
			return '';
		}
		if ( 'episode' === $kind && '' === $parent ) {
			return ''; // Item lookups require the parent feed guid.
		}
		$key = 'p20l_fnt_' . self::FOUNTAIN_SCHEMA . '_' . md5( $kind . '|' . $guid . '|' . $parent );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '~' === $cached ? null : (string) $cached;
		}
		// Budget rejection happens before any HTTP and remembers nothing, so
		// the window - not the cache - is what limits a burst (same policy
		// as the Podcast Index resolves in resolve_release()).
		if ( ! $this->may_call_upstream() ) {
			return null;
		}
		$id = $this->fountain_lookup( $kind, $guid, $parent );
		if ( is_string( $id ) ) {
			set_transient( $key, $id, '' !== $id ? self::FOUNTAIN_HIT_TTL : $this->fountain_ttl() );
			return $id;
		}
		// Unreliable answer: remember briefly under a value outside the id
		// alphabet ('~' is not in [A-Za-z0-9_-]) so a soft miss can never be
		// read back as a definitive one and pin the fallback for a full
		// FOUNTAIN_FAIL_TTL instead of retrying in 30 s.
		set_transient( $key, '~', $this->fountain_ttl() );
		return null;
	}

	/**
	 * One POST to Fountain's public guid lookup. Returns the Fountain id,
	 * '' for a definitive miss (200 without a matching hit, 404), or null
	 * for an unreliable answer (transport error, 5xx, undecodable body) -
	 * mirroring api_get()'s contract so fountain_ttl() can apply a
	 * 30-minute / 30-second remembered-miss policy (shorter than neg_ttl()'s
	 * hour: one Fountain lookup is cheap to retry).
	 */
	private function fountain_lookup( $kind, $guid, $parent ) {
		$payload = array( 'id' => ( 'show' === $kind ? 'podcast:guid:' : 'podcast:item:guid:' ) . $guid );
		if ( 'episode' === $kind ) {
			$payload['parent'] = 'podcast:guid:' . $parent;
		}

		// redirection => 0: never follow a remote redirect (SSRF hardening).
		$response = wp_remote_post( self::FOUNTAIN_API . '/search/lookup', array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'P20LinkResolver/' . self::VERSION . ' (' . home_url() . ')',
			),
			'body'        => json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->fountain_status = 0;
			return null;
		}
		$this->fountain_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $this->fountain_status ) {
			return 404 === $this->fountain_status ? '' : null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['hits'] ) || ! is_array( $body['hits'] ) ) {
			$this->fountain_status = 0; // An undecodable 200 proves nothing.
			return null;
		}
		$want = 'show' === $kind ? 'SHOW' : 'EPISODE';
		foreach ( $body['hits'] as $hit ) {
			if ( ! is_array( $hit ) || ! isset( $hit['_id'], $hit['_type'] ) ) {
				continue;
			}
			if ( $want !== strtoupper( (string) $hit['_type'] ) ) {
				continue;
			}
			// The docs describe ContentIDs as "{type}:id:{id}"; the live
			// response has carried the bare id. Accept both, and refuse
			// anything outside Fountain's opaque id alphabet so a
			// surprising payload can never reach a URL.
			$id = preg_replace( '/^(show|episode):id:/', '', (string) $hit['_id'] );
			return preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $id ) ? $id : null;
		}
		return ''; // Readable hits without a match: Fountain doesn't know it (yet).
	}

	/** TTL for a remembered Fountain miss: definitive misses retry sooner than neg_ttl()'s. */
	private function fountain_ttl() {
		return ( 200 === $this->fountain_status || 404 === $this->fountain_status ) ? self::FOUNTAIN_FAIL_TTL : self::SOFT_FAIL_TTL;
	}

	/**
	 * Resolve a pasted Fountain share link (…/show/{id} or …/episode/{id})
	 * into the guids a resolver page needs.
	 *
	 * Returns null when $q is not a Fountain share link, array('ref' => …,
	 * 'episode' => …) when resolved, array() for a definitive miss and
	 * false for an unreliable answer - ajax_search() turns those into empty
	 * results and a 502 respectively.
	 */
	private function search_fountain_link( $q ) {
		$q = trim( (string) $q );
		if ( ! preg_match( '~^(?:https?://)?(?:www\.)?fountain\.fm/(show|episode)/([A-Za-z0-9_-]{1,64})(?:[/?#].*)?$~i', $q, $m ) ) {
			return null;
		}
		$kind  = strtolower( $m[1] );
		$id    = $m[2];
		$canon = 'https://fountain.fm/' . $kind . '/' . $id;

		// The derived URL is remembered on the cache row (fountain_url):
		// matching it recovers the guids with no upstream call at all.
		$row = $this->cache_row_by_fountain_url( $canon );
		if ( $row && ! empty( $row->show_ref ) ) {
			$ref = $this->guard_show( $row->show_ref );
			if ( '' !== $ref ) {
				$ep = ( 'episode' === $kind && ! empty( $row->episode_guid ) ) ? $this->guard_episode( $row->episode_guid ) : '';
				if ( 'show' === $kind || '' !== $ep ) {
					return array( 'ref' => $ref, 'episode' => $ep );
				}
			}
		}

		// Not cached: ask Fountain's content API for the guids behind the id.
		$key    = 'p20l_frv_' . self::FOUNTAIN_SCHEMA . '_' . md5( $kind . '|' . $id );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			if ( '~' === $cached ) {
				return false;
			}
			if ( '' === $cached ) {
				return array();
			}
			return is_array( $cached ) ? $cached : array();
		}
		$found = $this->fountain_reverse( $kind, $id );
		if ( is_array( $found ) ) {
			set_transient( $key, $found, self::FOUNTAIN_HIT_TTL );
			return $found;
		}
		if ( '' === $found ) {
			set_transient( $key, '', self::FOUNTAIN_FAIL_TTL );
			return array();
		}
		set_transient( $key, '~', self::SOFT_FAIL_TTL );
		return false;
	}

	/**
	 * One GET to Fountain's content endpoint ({type}:id:{fountain id}) -
	 * the reverse of fountain_id(). Returns array('ref' => podcast guid,
	 * 'episode' => episode guid or ''), '' for a definitive miss (404, or
	 * a readable hit without usable guids), or null for an unreliable
	 * answer (transport, 5xx, undecodable body).
	 */
	private function fountain_reverse( $kind, $fountain_id ) {
		// redirection => 0: never follow a remote redirect (SSRF hardening).
		$response = wp_remote_get( self::FOUNTAIN_API . '/content/' . $kind . ':id:' . $fountain_id, array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'P20LinkResolver/' . self::VERSION . ' (' . home_url() . ')',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->fountain_status = 0;
			return null;
		}
		$this->fountain_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $this->fountain_status ) {
			return 404 === $this->fountain_status ? '' : null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['hit'] ) || ! is_array( $body['hit'] ) ) {
			$this->fountain_status = 0; // An undecodable 200 proves nothing.
			return null;
		}
		$hit  = $body['hit'];
		$type = isset( $hit['_type'] ) ? strtoupper( (string) $hit['_type'] ) : '';
		$want = 'show' === $kind ? 'SHOW' : 'EPISODE';
		if ( $want !== $type ) {
			$this->fountain_status = 0;
			return null;
		}
		if ( 'show' === $kind ) {
			$ref = $this->guard_show( isset( $hit['_guid'] ) ? $hit['_guid'] : '' );
			return '' !== $ref ? array( 'ref' => $ref, 'episode' => '' ) : '';
		}
		$episode = $this->guard_episode( isset( $hit['_guid'] ) ? $hit['_guid'] : '' );
		$parent  = '';
		if ( isset( $body['parent'] ) && is_array( $body['parent'] ) ) {
			$parent = $this->guard_show( isset( $body['parent']['_guid'] ) ? $body['parent']['_guid'] : '' );
		}
		if ( '' === $parent && isset( $hit['links'] ) && is_array( $hit['links'] ) ) {
			foreach ( $hit['links'] as $link ) {
				if ( is_string( $link ) && 0 === strpos( $link, 'show:guid:' ) ) {
					$parent = $this->guard_show( substr( $link, 10 ) );
					if ( '' !== $parent ) {
						break;
					}
				}
			}
		}
		return ( '' !== $episode && '' !== $parent ) ? array( 'ref' => $parent, 'episode' => $episode ) : '';
	}

	/**
	 * Find a cache row by its remembered Fountain URL (reverse of the
	 * derived link). fountain_url is unindexed, but this only runs for a
	 * pasted Fountain link and the cache table is one row per
	 * show/episode, so the scan stays cheap.
	 */
	private function cache_row_by_fountain_url( $url ) {
		global $wpdb;
		$table = $this->cache_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE fountain_url = %s LIMIT 1", (string) $url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Any recognised share link -> direct payload: Fountain first, then
	 * the other target apps' URL shapes (search_app_link()). Same contract
	 * as search_fountain_link() throughout: null / array() / false.
	 */
	private function search_direct_link( $q ) {
		$link = $this->search_fountain_link( $q );
		if ( null !== $link ) {
			return $link;
		}
		return $this->search_app_link( $q );
	}

	/**
	 * Resolve another target app's share URL (the README app table) into
	 * array('ref' => …, 'episode' => …). Show links carrying a
	 * podcast:guid or PI feed id resolve with no HTTP at all; the
	 * Apple-id keyed apps (Podfriend, Podcast Guru) need one PI
	 * byitunesid call; Podverse web ids need one reverse lookup on
	 * Podverse's own API (verified live 2026-11-18).
	 *
	 * Contract as search_fountain_link(): null when $q is not one of
	 * these URLs, array() for a definitive miss, false for unreliable.
	 */
	private function search_app_link( $q ) {
		$q = trim( (string) $q );
		if ( ! preg_match( '~^[A-Za-z][A-Za-z0-9+.\-]*://~', $q ) ) {
			$q = 'https://' . $q; // Bare domain pastes still parse.
		}
		$parts = parse_url( $q );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( preg_replace( '/^www\./i', '', (string) $parts['host'] ) );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		switch ( $host ) {
			case 'curiocaster.com':
				// curiocaster.com/podcast/pi{PI feed id}
				if ( ! preg_match( '#^/podcast/pi(\d{1,12})/?$#', $path, $m ) ) {
					return null;
				}
				return array( 'ref' => $m[1], 'episode' => '' );

			case 'podcastindex.org':
				// podcastindex.org/podcast/{podcast:guid or PI feed id}
				if ( ! preg_match( '#^/podcast/([A-Za-z0-9\-]{1,64})/?$#', $path, $m ) ) {
					return null;
				}
				$ref = $this->guard_show( $m[1] );
				return '' !== $ref ? array( 'ref' => $ref, 'episode' => '' ) : array();

			case 'castamatic.com':
				// castamatic.com/guid/{podcast:guid}
				if ( ! preg_match( '#^/guid/([A-Za-z0-9\-]{1,64})/?$#', $path, $m ) ) {
					return null;
				}
				$ref = $this->guard_show( $m[1] );
				return '' !== $ref ? array( 'ref' => $ref, 'episode' => '' ) : array();

			case 'truefans.fm':
				// truefans.fm/{podcast:guid}
				if ( ! preg_match( '#^/([A-Za-z0-9\-]{1,64})/?$#', $path, $m ) ) {
					return null;
				}
				$ref = $this->guard_show( $m[1] );
				return '' !== $ref ? array( 'ref' => $ref, 'episode' => '' ) : array();

			case 'boostmebuddy.com':
				// www.boostmebuddy.com/?podcast={guid}[&episode={guid}]
				if ( '' !== $path && '/' !== $path ) {
					return null;
				}
				if ( ! array_key_exists( 'podcast', $query ) ) {
					return null;
				}
				$raw_podcast = is_scalar( $query['podcast'] ) ? (string) $query['podcast'] : '';
				$ref         = $this->guard_show( $raw_podcast );
				if ( '' === $ref ) {
					return array();
				}
				$raw_episode = ( isset( $query['episode'] ) && is_scalar( $query['episode'] ) ) ? (string) $query['episode'] : '';
				return array( 'ref' => $ref, 'episode' => '' !== $raw_episode ? $this->guard_episode( $raw_episode ) : '' );

			case 'podfriend.com':
				// podfriend.com/podcast/{PI feed id|podcast:guid}[/{episode
				// guid}] - the Apple-id route is 410 (live 2026-11-20), so
				// digits go to byfeedid first and only fall back to
				// byitunesid for legacy Apple-id pastes; a podcast:guid
				// pastes straight through; slug-canonical shares can't be
				// reversed offline.
				if ( ! preg_match( '~^/podcast/([A-Za-z0-9\-]{1,64})(?:/([^/?#]+))?/?$~', $path, $m ) ) {
					return null;
				}
				$ep   = isset( $m[2] ) && '' !== $m[2] ? $this->guard_episode( rawurldecode( $m[2] ) ) : '';
				$base = $m[1];
				if ( ctype_digit( $base ) ) {
					$ref = $this->feedid_ref( $base );
					if ( null === $ref ) {
						return false;
					}
					if ( '' === $ref ) {
						$ref = $this->apple_ref( $base ); // Legacy Apple-id paste.
					}
				} elseif ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $base ) ) {
					$ref = $this->guard_show( $base );
				} else {
					return null;
				}
				if ( null === $ref ) {
					return false;
				}
				return '' !== $ref ? array( 'ref' => $ref, 'episode' => $ep ) : array();

			case 'app.podcastguru.io':
				// app.podcastguru.io/podcast/{Apple id}
				if ( ! preg_match( '#^/podcast/(\d{1,12})/?$#', $path, $m ) ) {
					return null;
				}
				$ref = $this->apple_ref( $m[1] );
				if ( null === $ref ) {
					return false;
				}
				return '' !== $ref ? array( 'ref' => $ref, 'episode' => '' ) : array();

			case 'podverse.fm':
				// podverse.fm/podcast|episode/{Podverse web id} - reversed
				// through Podverse's own API (podverse_reverse_ref()).
				if ( preg_match( '#^/podcast/([A-Za-z0-9_\-]{1,64})/?$#', $path, $m ) ) {
					return $this->podverse_reverse_ref( 'podcast', $m[1] );
				}
				if ( preg_match( '#^/episode/([A-Za-z0-9_\-]{1,64})/?$#', $path, $m ) ) {
					return $this->podverse_reverse_ref( 'episode', $m[1] );
				}
				return null;

			case 'api.podverse.fm':
				// The show pattern our own links emit: PI feed id in the path.
				if ( ! preg_match( '#^/api/v1/podcast/podcastindex/(\d{1,12})/?$#', $path, $m ) ) {
					return null;
				}
				return array( 'ref' => $m[1], 'episode' => '' );
		}
		return null;
	}

	/**
	 * PI feed reference (podcast:guid, else feed id) from a byitunesid or
	 * byfeedid response body. '' = the API knows no such feed.
	 */
	private function ref_from_feed_body( $body ) {
		$feed = isset( $body['feed'] ) && is_array( $body['feed'] ) ? $body['feed'] : array();
		if ( empty( $feed['id'] ) ) {
			return '';
		}
		$ref = ! empty( $feed['podcastGuid'] ) ? $this->guard_show( $feed['podcastGuid'] ) : '';
		return '' !== $ref ? $ref : $this->guard_show( (string) (int) $feed['id'] );
	}

	/**
	 * Shared cache/read for the two PI id lookups (apple_ref, feedid_ref):
	 * '' = definitive miss, null = unreliable, remembered in p20l_{kind}_*
	 * transients keyed on the raw id.
	 */
	private function pi_id_ref( $kind, $path, $query, $id ) {
		$key    = 'p20l_' . $kind . '_' . self::DIRECT_SCHEMA . '_' . md5( $id );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '~' === $cached ? null : (string) $cached;
		}
		$body = $this->api_get( $path, $query );
		if ( ! is_array( $body ) ) {
			// 404 is definitive; transport / 5xx / undecodable is not.
			$def = 404 === $this->last_status;
			set_transient( $key, $def ? '' : '~', $this->neg_ttl() );
			return $def ? '' : null;
		}
		$ref = $this->ref_from_feed_body( $body );
		set_transient( $key, $ref, '' !== $ref ? self::DIRECT_HIT_TTL : $this->neg_ttl() );
		return $ref;
	}

	/**
	 * PI feed reference for an Apple Podcasts id via podcasts/byitunesid.
	 * Returns the ref, '' for a definitive miss or null for an unreliable
	 * answer; remembered in p20l_aid_* transients.
	 */
	private function apple_ref( $apple_id ) {
		$apple_id = preg_replace( '/\D/', '', (string) $apple_id );
		if ( '' === $apple_id ) {
			return '';
		}
		return $this->pi_id_ref( 'aid', '/podcasts/byitunesid', array( 'id' => $apple_id ), $apple_id );
	}

	/**
	 * PI feed reference for a numeric PI feed id via podcasts/byfeedid -
	 * Podfriend's current show URL space (live 2026-11-20), so pasted
	 * Podfriend links (and our own) resolve without guessing whether the
	 * digits are an Apple id. Same contract as apple_ref(); remembered in
	 * p20l_fid_* transients.
	 */
	private function feedid_ref( $feed_id ) {
		$feed_id = preg_replace( '/\D/', '', (string) $feed_id );
		if ( '' === $feed_id || '0' === $feed_id ) {
			return '';
		}
		return $this->pi_id_ref( 'fid', '/podcasts/byfeedid', array( 'id' => $feed_id ), $feed_id );
	}

	/**
	 * One GET to Podverse's own API for the guids behind a podverse.fm
	 * share URL (verified live 2026-11-18): /podcast/{id} -> podcastGuid
	 * (or podcastIndexId), /episode/{id} -> RSS guid + the parent
	 * podcastGuid. Contract as search_fountain_link(); remembered in
	 * p20l_pvr_* transients. An episode that resolves but carries no
	 * usable guid degrades to its show rather than to nothing.
	 */
	private function podverse_reverse_ref( $kind, $pv_id ) {
		$key    = 'p20l_pvr_' . self::DIRECT_SCHEMA . '_' . md5( $kind . '|' . $pv_id );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			if ( '~' === $cached ) {
				return false;
			}
			if ( '' === $cached ) {
				return array();
			}
			return is_array( $cached ) ? $cached : array();
		}

		// redirection => 0: never follow a remote redirect (SSRF hardening).
		$response = wp_remote_get( self::PODVERSE_API . '/' . ( 'episode' === $kind ? 'episode' : 'podcast' ) . '/' . rawurlencode( $pv_id ), array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'P20LinkResolver/' . self::VERSION . ' (' . home_url() . ')',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->podverse_status = 0;
			set_transient( $key, '~', self::SOFT_FAIL_TTL );
			return false;
		}
		$this->podverse_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $this->podverse_status ) { // Podverse doesn't know it.
			set_transient( $key, '', $this->podverse_ttl() );
			return array();
		}
		if ( 200 !== $this->podverse_status ) {
			set_transient( $key, '~', self::SOFT_FAIL_TTL );
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$this->podverse_status = 0; // An undecodable 200 proves nothing.
			set_transient( $key, '~', self::SOFT_FAIL_TTL );
			return false;
		}

		// The show response is the podcast object itself; the episode
		// response nests it under 'podcast'.
		$parent = 'episode' === $kind
			? ( isset( $body['podcast'] ) && is_array( $body['podcast'] ) ? $body['podcast'] : array() )
			: $body;
		$ref    = ! empty( $parent['podcastGuid'] ) ? $this->guard_show( $parent['podcastGuid'] ) : '';
		if ( '' === $ref ) {
			$pid = isset( $parent['podcastIndexId'] ) ? trim( (string) $parent['podcastIndexId'] ) : '';
			if ( '' !== $pid && '0' !== $pid ) {
				$ref = $this->guard_show( $pid );
			}
		}
		if ( 'episode' === $kind ) {
			$ep = ! empty( $body['guid'] ) ? $this->guard_episode( $body['guid'] ) : '';
			if ( '' !== $ep && '' !== $ref ) {
				$found = array( 'ref' => $ref, 'episode' => $ep );
				set_transient( $key, $found, self::DIRECT_HIT_TTL );
				return $found;
			}
			if ( '' !== $ref ) { // Readable payload without a usable guid: open the show.
				$found = array( 'ref' => $ref, 'episode' => '' );
				set_transient( $key, $found, self::DIRECT_HIT_TTL );
				return $found;
			}
		} elseif ( '' !== $ref ) {
			$found = array( 'ref' => $ref, 'episode' => '' );
			set_transient( $key, $found, self::DIRECT_HIT_TTL );
			return $found;
		}
		// Readable payload but nothing usable: definitive, remember as a miss.
		set_transient( $key, '', self::PODVERSE_FAIL_TTL );
		return array();
	}

	/**
	 * Podverse episode URL: PI feed id + episode guid -> podverse.fm/episode/{id}
	 * through Podverse's public url-resolver (verified live 2026-11-16).
	 * Returns the URL, '' for a definitive miss (404), or null for an
	 * unreliable answer (transport, 5xx, undecodable body) or a spent
	 * resolve budget - mirroring fountain_id()'s contract and cache shape.
	 */
	private function podverse_episode_url( $feed_id, $episode_guid ) {
		$feed_id      = (int) $feed_id;
		$episode_guid = (string) $episode_guid;
		if ( $feed_id <= 0 || '' === $episode_guid ) {
			return '';
		}
		$key    = 'p20l_pve_' . md5( $feed_id . '|' . $episode_guid );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '~' === $cached ? null : (string) $cached;
		}
		// Budget rejection happens before any HTTP and remembers nothing
		// (same policy as fountain_id() and resolve_release()).
		if ( ! $this->may_call_upstream() ) {
			return null;
		}
		$url = $this->podverse_lookup( $feed_id, $episode_guid );
		if ( is_string( $url ) ) {
			set_transient( $key, $url, '' !== $url ? self::PODVERSE_HIT_TTL : $this->podverse_ttl() );
			return $url;
		}
		// Unreliable answer: remember briefly under a value outside the
		// accepted URL shape so a soft miss can never be read back as a
		// definitive one ('https://' never starts with '~').
		set_transient( $key, '~', $this->podverse_ttl() );
		return null;
	}

	/**
	 * One GET to Podverse's url-resolver. Returns the full episode URL,
	 * '' for a definitive miss (404), or null for an unreliable answer
	 * (transport error, 5xx, undecodable 200). Only the exact guide
	 * shape https://podverse.fm/episode/{id} is accepted, so a
	 * surprising payload can never reach the rendered link.
	 */
	private function podverse_lookup( $feed_id, $episode_guid ) {
		$url = self::PODVERSE_API . '/url-resolver/episode?' . http_build_query(
			array(
				'podcastIndexId' => $feed_id,
				'episodeGuid'    => $episode_guid,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		// redirection => 0: never follow a remote redirect (SSRF hardening).
		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'P20LinkResolver/' . self::VERSION . ' (' . home_url() . ')',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->podverse_status = 0;
			return null;
		}
		$this->podverse_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $this->podverse_status ) {
			return 404 === $this->podverse_status ? '' : null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['webUrl'] ) ) {
			$this->podverse_status = 0; // An undecodable 200 proves nothing.
			return null;
		}
		$found = (string) $body['webUrl'];
		if ( ! preg_match( '#^https://podverse\.fm/episode/[A-Za-z0-9_-]{1,128}$#', $found ) ) {
			$this->podverse_status = 0; // Not the guide's shape: refuse it.
			return null;
		}
		return $found;
	}

	/** TTL for a remembered Podverse miss: 404 is definitive, everything else retries in 30 s. */
	private function podverse_ttl() {
		return 404 === $this->podverse_status ? self::PODVERSE_FAIL_TTL : self::SOFT_FAIL_TTL;
	}

	/* ------------------------------------------------------------------ *
	 * Release resolution (cache-first)
	 * ------------------------------------------------------------------ */

	private function release_from_row( $row, $episode ) {
		return array(
			'show_ref'        => $row->show_ref,
			'feed_id'         => (int) $row->feed_id,
			'podcast_guid'    => $row->podcast_guid,
			'itunes_id'       => (int) $row->itunes_id,
			'feed_url'        => isset( $row->feed_url ) ? (string) $row->feed_url : '',
			'show_title'      => $row->show_title,
			'author'          => $row->author,
			'image'           => $row->image_url,
			'episode_guid'    => $row->episode_guid,
			'pi_episode_id'   => (int) $row->pi_episode_id,
			'episode_title'   => $row->episode_title,
			'episode_image'   => $row->episode_image,
			'episode_wanted'  => '' !== $episode,
			'episode_missing' => false,
		);
	}

	private function release_from_data( $show, $d, $episode, $missing ) {
		return array(
			'show_ref'        => $show,
			'feed_id'         => (int) $d['feed_id'],
			'podcast_guid'    => $d['podcast_guid'],
			'itunes_id'       => (int) $d['itunes_id'],
			'feed_url'        => isset( $d['feed_url'] ) ? (string) $d['feed_url'] : '',
			'show_title'      => $d['show_title'],
			'author'          => $d['author'],
			'image'           => $d['image'],
			'episode_guid'    => ( '' !== $episode && ! $missing ) ? $episode : '',
			'pi_episode_id'   => (int) $d['pi_episode_id'],
			'episode_title'   => $d['episode_title'],
			'episode_image'   => $d['episode_image'],
			'episode_wanted'  => '' !== $episode,
			'episode_missing' => $missing,
		);
	}

	private function row_to_data( $row ) {
		return array(
			'feed_id'       => (int) $row->feed_id,
			'podcast_guid'  => $row->podcast_guid,
			'itunes_id'     => (int) $row->itunes_id,
			'feed_url'      => isset( $row->feed_url ) ? (string) $row->feed_url : '',
			'show_title'    => $row->show_title,
			'author'        => $row->author,
			'image'         => $row->image_url,
			'pi_episode_id' => 0,
			'episode_title' => '',
			'episode_image' => '',
		);
	}

	/**
	 * Show (and optionally one episode) metadata. Returns the release array,
	 * or null when the show cannot be resolved. An unknown episode still
	 * returns the show, flagged episode_missing.
	 */
	private function resolve_release( $show, $episode ) {
		$row = $this->cache_get_row( $this->row_hash( $show, $episode ) );
		if ( $row ) {
			if ( $this->refresh_allowed && $this->row_is_stale( $row ) ) {
				$this->refresh_pending[ $row->lookup_hash ] = array( $show, $episode );
			}
			return $this->release_from_row( $row, $episode );
		}

		// Show level: a stored show row, else one upstream fetch.
		$base = '' !== $episode ? $this->cache_get_row( $this->row_hash( $show, '' ) ) : false;
		if ( $base ) {
			$data = $this->row_to_data( $base );
		} else {
			if ( get_transient( $this->neg_key( 'show', $show ) ) || ! $this->may_call_upstream() ) {
				return null;
			}
			$data = $this->fetch_show( $show );
			if ( ! $data ) {
				set_transient( $this->neg_key( 'show', $show ), 1, $this->neg_ttl() );
				return null;
			}
			$this->cache_store( $show, '', $data );
		}

		if ( '' === $episode ) {
			return $this->release_from_data( $show, $data, '', false );
		}

		// Episode level.
		if ( get_transient( $this->neg_key( 'episode', $show, $episode ) ) || ! $this->may_call_upstream() ) {
			return $this->release_from_data( $show, $data, $episode, true );
		}
		$ep = $this->fetch_episode( $data['feed_id'], $episode );
		if ( ! $ep ) {
			set_transient( $this->neg_key( 'episode', $show, $episode ), 1, $this->neg_ttl() );
			return $this->release_from_data( $show, $data, $episode, true );
		}
		$data = array_merge( $data, $ep );
		$this->cache_store( $show, $episode, $data );
		return $this->release_from_data( $show, $data, $episode, false );
	}

	/** Shutdown hook: refresh stale rows after the page has been sent. */
	public function run_queued_refresh() {
		if ( empty( $this->refresh_pending ) ) {
			return;
		}
		$this->resolve_budgeted = false;
		$pending                = $this->refresh_pending;
		$this->refresh_pending  = array();

		foreach ( $pending as list( $show, $episode ) ) {
			$data = $this->fetch_show( $show );
			if ( ! $data ) {
				continue; // Keep serving the old row.
			}
			if ( '' !== $episode ) {
				$ep = $this->fetch_episode( $data['feed_id'], $episode );
				if ( ! $ep ) {
					continue;
				}
				$data = array_merge( $data, $ep );
			}
			$this->cache_store( $show, $episode, $data );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Apps
	 * ------------------------------------------------------------------ */

	private function app_registry() {
		return array(
			'fountain'     => array( 'label' => 'Fountain', 'domain' => 'fountain.fm' ),
			'curiocaster'  => array( 'label' => 'CurioCaster', 'domain' => 'curiocaster.com' ),
			'podverse'     => array( 'label' => 'Podverse', 'domain' => 'podverse.fm' ),
			// Registry 'icon' is needed where /favicon.ico is missing, blocked or
			// refused cross-origin: Podfriend and Podcast Guru serve no favicon.ico
			// (and both send a Cross-Origin-Resource-Policy the browser enforces on
			// <img>), Podcast Index 403s every asset, TrueFans hotlink-blocks.
			'podfriend'    => array( 'label' => 'Podfriend', 'domain' => 'podfriend.com', 'icon' => 'https://icons.duckduckgo.com/ip3/podfriend.com.ico' ),
			'boostmebuddy' => array( 'label' => 'BoostMeBuddy', 'domain' => 'boostmebuddy.com', 'icon' => 'https://www.boostmebuddy.com/icons/buddy/favicon.svg' ),
			'castamatic'   => array( 'label' => 'Castamatic', 'domain' => 'castamatic.com' ),
			'truefans'     => array( 'label' => 'TrueFans', 'domain' => 'truefans.fm', 'icon' => 'https://icons.duckduckgo.com/ip3/truefans.fm.ico' ),
			'podcastindex' => array( 'label' => 'Podcast Index', 'domain' => 'podcastindex.org', 'icon' => 'https://icons.duckduckgo.com/ip3/podcastindex.org.ico' ),
			'podcastguru'  => array( 'label' => 'Podcast Guru', 'domain' => 'app.podcastguru.io', 'icon' => 'https://icons.duckduckgo.com/ip3/app.podcastguru.io.ico' ),
			// breez.link serves an HTML shell at /favicon.ico; the site's real
			// icon is the PNG its own <link rel="icon"> points at (200, no
			// Cross-Origin-Resource-Policy).
			'breez'        => array( 'label' => 'Breez', 'domain' => 'breez.link', 'icon' => 'https://breez.technology/oim/favicon-32x32.png' ),
		);
	}

	/**
	 * Run the Fountain degradation chain (episode -> show) for one release.
	 * Returns array( 'url', 'episode' ) for a real Fountain id, or null when
	 * no id can be produced (no guid, definitive miss, unreliable answer).
	 * The fountain.fm/discover last resort lives in app_link() so a stale
	 * derived URL can win over it.
	 */
	private function fountain_derive( $pguid, $has_ep, $ep_guid ) {
		if ( '' === $pguid ) {
			return null;
		}
		if ( $has_ep ) {
			$ep_f = $this->fountain_id( 'episode', $ep_guid, $pguid );
			if ( is_string( $ep_f ) && '' !== $ep_f ) {
				return array( 'url' => 'https://fountain.fm/episode/' . $ep_f, 'episode' => true );
			}
			// Definitive miss or unreliable answer: show next.
		}
		$show_f = $this->fountain_id( 'show', $pguid );
		if ( is_string( $show_f ) && '' !== $show_f ) {
			return array( 'url' => 'https://fountain.fm/show/' . $show_f, 'episode' => false );
		}
		return null;
	}

	/**
	 * URL for one app, or null when it cannot be built from what we know.
	 * 'episode' says whether the URL points at the episode (true) or at the
	 * show (false). Patterns: Podcast Index "podcast-platform-links", except
	 * Fountain (opaque ids, see fountain_id()) and BoostMeBuddy (the app's
	 * own guid query params). Fountain additionally serves a derived URL
	 * remembered on the cache row (see fountain_derive()).
	 */
	private function app_link( $slug, $r ) {
		$feed_id  = (int) $r['feed_id'];
		$ep_guid  = (string) $r['episode_guid'];
		$pguid    = (string) $r['podcast_guid'];
		$has_ep   = $r['episode_wanted'] && ! $r['episode_missing'] && '' !== $ep_guid;

		switch ( $slug ) {
			case 'fountain':
				// Opaque Fountain ids must be looked up from the guids
				// (see fountain_id()); the platform-links guide's numeric
				// patterns 404. Degradation chain (user decision
				// 2026-11-12): episode -> show -> fountain.fm/discover, so
				// the button always appears and never opens the wrong
				// episode; the UI note says what it opens instead.
				//
				// Derived URLs are remembered on the cache row for 30 days:
				// a fresh one is served with no Fountain call at all, a
				// stale one is re-derived, and when re-derivation cannot
				// produce a real id the stale URL is served silently rather
				// than degrading to discover.
				$hash   = $this->row_hash( $r['show_ref'], $has_ep ? $ep_guid : '' );
				$row    = $this->cache_get_row( $hash );
				$cached = ( $row && isset( $row->fountain_url ) ) ? (string) $row->fountain_url : '';
				$ts     = ( $row && isset( $row->fountain_date ) ) ? strtotime( $row->fountain_date . ' UTC' ) : 0;
				if ( $cached && $ts && ( time() - $ts ) < self::DERIVED_URL_TTL ) {
					return array(
						'url'     => $cached,
						'episode' => false !== strpos( $cached, 'fountain.fm/episode/' ),
					);
				}
				$link = $this->fountain_derive( $pguid, $has_ep, $ep_guid );
				if ( $link ) {
					$this->cache_set_fountain_url( $hash, $link['url'] );
					return $link;
				}
				if ( $cached ) {
					return array(
						'url'     => $cached,
						'episode' => false !== strpos( $cached, 'fountain.fm/episode/' ),
					);
				}
				return array( 'url' => 'https://fountain.fm/discover', 'episode' => false, 'note' => 'discover' );

			case 'curiocaster':
				// No episode pattern is documented for CurioCaster.
				return $feed_id > 0 ? array( 'url' => 'https://curiocaster.com/podcast/pi' . $feed_id, 'episode' => false ) : null;

			case 'podverse':
				if ( $feed_id <= 0 ) {
					return null;
				}
				// Episode links resolve the Podverse id from the PI feed id +
				// episode guid via Podverse's public url-resolver (live-verified
				// 2026-11-16); a miss or lookup failure degrades to the show.
				if ( $has_ep ) {
					$url = $this->podverse_episode_url( $feed_id, $ep_guid );
					if ( is_string( $url ) && '' !== $url ) {
						return array( 'url' => $url, 'episode' => true );
					}
				}
				return array( 'url' => 'https://api.podverse.fm/api/v1/podcast/podcastindex/' . $feed_id, 'episode' => false );

			case 'podfriend':
				// The guide's Apple-id route is dead (410, live 2026-11-20);
				// Podfriend's show pages key on the PI feed id (301 to the
				// slug-canonical) or the podcast:guid (200). Every
				// /podcast/{base}/{episode guid} form 404s, so an episode
				// request opens the show.
				if ( $feed_id > 0 ) {
					return array( 'url' => 'https://podfriend.com/podcast/' . $feed_id, 'episode' => false );
				}
				if ( '' !== $pguid ) {
					return array( 'url' => 'https://podfriend.com/podcast/' . rawurlencode( $pguid ), 'episode' => false );
				}
				return null;

			case 'boostmebuddy':
				// The app's own query-param pattern, keyed on the guids
				// (not in the platform-links guide; verified live 2026-11-12).
				if ( '' === $pguid ) {
					return null;
				}
				$url = 'https://www.boostmebuddy.com/?podcast=' . rawurlencode( $pguid );
				if ( $has_ep ) {
					$url .= '&episode=' . rawurlencode( $ep_guid );
				}
				return array( 'url' => $url, 'episode' => $has_ep );

			case 'castamatic':
				// Guide pattern (show podcast:guid); the episode URL needs a
				// Castamatic-internal id, so an episode request opens the show.
				return '' !== $pguid ? array( 'url' => 'https://castamatic.com/guid/' . rawurlencode( $pguid ), 'episode' => false ) : null;

			case 'truefans':
				// Guide pattern (show podcast:guid); the episode URL needs a
				// TrueFans-internal id, so an episode request opens the show.
				return '' !== $pguid ? array( 'url' => 'https://truefans.fm/' . rawurlencode( $pguid ), 'episode' => false ) : null;

			case 'podcastindex':
				// Guide pattern: the directory accepts either the podcast:guid
				// or the PI feed id; no episode permalink is documented, so an
				// episode request opens the show.
				if ( '' !== $pguid ) {
					return array( 'url' => 'https://podcastindex.org/podcast/' . rawurlencode( $pguid ), 'episode' => false );
				}
				return $feed_id > 0 ? array( 'url' => 'https://podcastindex.org/podcast/' . $feed_id, 'episode' => false ) : null;

			case 'podcastguru':
				// Guide pattern keyed on the Apple Podcasts id. Shows with
				// no Apple id (listed only in the Podcast Index) use the
				// site's other addressing: the show id is md5(feed URL)
				// (verified live 2026-11-20 - the bare hex route renders).
				// Episode URLs need Podcast Guru's internal ids, so an
				// episode request opens the show.
				$apple = (int) $r['itunes_id'];
				if ( $apple > 0 ) {
					return array( 'url' => 'https://app.podcastguru.io/podcast/' . $apple, 'episode' => false );
				}
				$feed_url = isset( $r['feed_url'] ) ? trim( (string) $r['feed_url'] ) : '';
				if ( '' !== $feed_url ) {
					return array( 'url' => 'https://app.podcastguru.io/podcast/' . md5( $feed_url ), 'episode' => false );
				}
				return null;

			case 'breez':
				// Guide pattern keyed on the feed URL: the show is
				// ?feedURL=..., an episode appends &episodeID={episode guid}.
				// Both need feedURL, so a row with no feed URL gets no button.
				$feed_url = isset( $r['feed_url'] ) ? trim( (string) $r['feed_url'] ) : '';
				if ( '' === $feed_url ) {
					return null;
				}
				$url = 'https://breez.link/p?feedURL=' . self::encode_uri_component( $feed_url );
				if ( $has_ep ) {
					$url .= '&episodeID=' . self::encode_uri_component( $ep_guid );
				}
				return array( 'url' => $url, 'episode' => $has_ep );
		}
		return null;
	}

	private function get_app_config( $slug ) {
		$all      = get_option( 'p20link_apps', array() );
		$defaults = array( 'enabled' => true, 'icon_url' => '', 'icon_bg' => '' );
		return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? wp_parse_args( $all[ $slug ], $defaults ) : $defaults;
	}

	private function is_app_enabled( $slug ) {
		return (bool) $this->get_app_config( $slug )['enabled'];
	}

	private function app_icon( $slug, $reg ) {
		$cfg = $this->get_app_config( $slug );
		if ( ! empty( $cfg['icon_url'] ) ) {
			return $cfg['icon_url'];
		}
		// Registry 'icon' overrides the generic favicon (apps without one).
		return ! empty( $reg['icon'] ) ? $reg['icon'] : 'https://' . $reg['domain'] . '/favicon.ico';
	}

	private function app_icon_bg( $slug ) {
		$cfg   = $this->get_app_config( $slug );
		$color = isset( $cfg['icon_bg'] ) ? (string) $cfg['icon_bg'] : '';
		return preg_match( '/^#([0-9a-fA-F]{3}){1,2}$/', $color ) ? strtolower( $color ) : '';
	}

	private static function sort_slugs_by_order( $slugs, $orders ) {
		$registry_order = array_flip( $slugs );
		$sorted         = $slugs;
		usort( $sorted, function ( $a, $b ) use ( $registry_order, $orders ) {
			$pa = isset( $orders[ $a ] ) ? (int) $orders[ $a ] : $registry_order[ $a ];
			$pb = isset( $orders[ $b ] ) ? (int) $orders[ $b ] : $registry_order[ $b ];
			return $pa === $pb ? $registry_order[ $a ] - $registry_order[ $b ] : $pa - $pb;
		} );
		return $sorted;
	}

	private function ordered_slugs() {
		$all    = get_option( 'p20link_apps', array() );
		$orders = array();
		foreach ( array_keys( $this->app_registry() ) as $slug ) {
			if ( isset( $all[ $slug ]['order'] ) && '' !== $all[ $slug ]['order'] ) {
				$orders[ $slug ] = $all[ $slug ]['order'];
			}
		}
		return self::sort_slugs_by_order( array_keys( $this->app_registry() ), $orders );
	}

	public function sanitize_apps_settings( $value ) {
		$slugs  = array_keys( $this->app_registry() );
		$orders = array();
		$clean  = array();
		foreach ( $slugs as $slug ) {
			$row = isset( $value[ $slug ] ) && is_array( $value[ $slug ] ) ? $value[ $slug ] : array();
			if ( isset( $row['order'] ) && '' !== $row['order'] ) {
				$orders[ $slug ] = $row['order'];
			}
			$icon_bg = '';
			if ( empty( $row['icon_bg_none'] ) && isset( $row['icon_bg'] ) ) {
				$color   = sanitize_hex_color( $row['icon_bg'] );
				$icon_bg = $color ? $color : '';
			}
			$clean[ $slug ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'icon_url' => ! empty( $row['icon_url'] ) ? esc_url_raw( trim( $row['icon_url'] ) ) : '',
				'icon_bg'  => $icon_bg,
			);
		}
		foreach ( self::sort_slugs_by_order( $slugs, $orders ) as $position => $slug ) {
			$clean[ $slug ]['order'] = $position;
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ *
	 * Front end: shortcode
	 * ------------------------------------------------------------------ */

	public function render( $atts ) {
		$this->refresh_allowed  = true;
		$this->resolve_budgeted = true;

		$atts = shortcode_atts( array(
			'show'    => '',
			'episode' => '',
			'header'  => '',
		), $atts, 'p20link' );

		// Explicit attributes win over the query string.
		$from_url = false;
		$show     = $atts['show'] ? $this->guard_show( sanitize_text_field( $atts['show'] ) ) : '';
		if ( '' === $show ) {
			$show     = $this->get_arg( 'show' );
			$from_url = '' !== $show;
		}
		$episode = '';
		if ( '' !== $show ) {
			$episode = $atts['episode'] && ! $from_url
				? $this->guard_episode( sanitize_text_field( $atts['episode'] ) )
				: ( $from_url ? $this->get_arg( 'episode' ) : '' );
		}

		if ( ! $this->has_credentials() ) {
			$msg = current_user_can( 'manage_options' )
				? __( 'Podcast 2.0 Link: add your Podcast Index API key and secret under Settings > Podcast 2.0 Link.', 'p20-link' )
				: __( 'This link page is not available right now.', 'p20-link' );
			return '<p class="p20link-error">' . esc_html( $msg ) . '</p>';
		}

		if ( '' === $show ) {
			return $this->render_search_ui();
		}

		$release = $this->resolve_release( $show, $episode );
		if ( ! $release ) {
			return $this->render_search_ui( __( 'Podcast not found', 'p20-link' ) );
		}

		$links = array();
		foreach ( $this->ordered_slugs() as $slug ) {
			if ( ! $this->is_app_enabled( $slug ) ) {
				continue;
			}
			$link = $this->app_link( $slug, $release );
			if ( ! $link ) {
				continue;
			}
			$reg                = $this->app_registry()[ $slug ];
			$links[ $slug ]     = array(
				'url'      => $link['url'],
				'label'    => $reg['label'],
				'episode'  => $link['episode'],
				'icon_url' => $this->app_icon( $slug, $reg ),
				'note'     => isset( $link['note'] ) ? $link['note'] : '',
			);
		}

		$share_url = $from_url ? $this->page_url( $show, $episode ) : (string) get_permalink();

		return $this->render_html( $links, $release, $this->resolve_show_header( $atts['header'] ), $show, $episode, $share_url );
	}

	private function page_url( $show, $episode ) {
		$args = array( 'show' => $show );
		if ( '' !== $episode ) {
			$args['episode'] = $episode;
		}
		$base = get_permalink();
		return $base ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : '';
	}

	private function resolve_show_header( $raw ) {
		$default = (bool) $this->opt( 'show_header', true );
		$raw     = strtolower( trim( (string) $raw ) );
		if ( in_array( $raw, array( 'true', '1', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $raw, array( 'false', '0', 'no' ), true ) ) {
			return false;
		}
		return $default;
	}

	private function render_html( $links, $r, $show_header, $show, $episode, $share_url ) {
		$has_ep     = $r['episode_wanted'] && ! $r['episode_missing'] && '' !== $r['episode_title'];
		$title_line = $has_ep ? $r['episode_title'] : $r['show_title'];
		$sub_line   = $has_ep ? $r['show_title'] : $r['author'];
		$art        = $has_ep && $r['episode_image'] ? $r['episode_image'] : $r['image'];
		$heading    = $has_ep ? __( 'Listen to this episode:', 'p20-link' ) : __( 'Listen to this podcast:', 'p20-link' );
		$random     = (bool) $this->opt( 'random_app_order', false ) && count( $links ) > 1;

		ob_start();
		?>
		<div class="p20link" data-show="<?php echo esc_attr( $show ); ?>" data-episode="<?php echo esc_attr( $episode ); ?>">
			<?php if ( $show_header && ( $title_line || $art ) ) : ?>
				<div class="p20link-header">
					<div class="p20link-info">
						<?php if ( $title_line ) : ?>
							<h2 class="p20link-title"><?php echo esc_html( $title_line ); ?></h2>
						<?php endif; ?>
						<?php if ( $sub_line ) : ?>
							<p class="p20link-sub"><?php echo esc_html( $has_ep ? sprintf( /* translators: %s: podcast name. */ __( 'from %s', 'p20-link' ), $sub_line ) : sprintf( /* translators: %s: author name. */ __( 'by %s', 'p20-link' ), $sub_line ) ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( $art ) : ?>
						<img class="p20link-art" src="<?php echo esc_url( $art ); ?>" alt="<?php echo esc_attr( $title_line ); ?>" loading="lazy" />
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $r['episode_wanted'] && $r['episode_missing'] ) : ?>
				<p class="p20link-notice"><?php esc_html_e( 'That episode is not in the Podcast Index yet, so these links open the show instead.', 'p20-link' ); ?></p>
			<?php endif; ?>

			<?php if ( $share_url ) : ?>
				<p class="p20link-share">
					<button type="button" class="p20link-share-btn" data-url="<?php echo esc_url( $share_url ); ?>">
						<span aria-hidden="true">🔗</span>
						<span class="p20link-share-label"><?php esc_html_e( 'Share', 'p20-link' ); ?></span>
					</button>
				</p>
			<?php endif; ?>

			<p class="p20link-heading"><?php echo esc_html( $heading ); ?></p>
			<?php if ( empty( $links ) ) : ?>
				<p class="p20link-error"><?php esc_html_e( 'No podcast apps are available for this show.', 'p20-link' ); ?></p>
			<?php else : ?>
				<ul class="p20link-list"<?php echo $random ? ' data-p20-shuffle="1"' : ''; ?>>
					<?php foreach ( $links as $slug => $link ) : ?>
						<?php $bg = $this->app_icon_bg( $slug ); ?>
						<li class="p20link-item p20link-<?php echo esc_attr( $slug ); ?>" data-app="<?php echo esc_attr( $slug ); ?>">
							<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img class="p20link-icon" src="<?php echo esc_url( $link['icon_url'] ); ?>" alt="" width="40" height="40" loading="lazy"<?php echo '' !== $bg ? ' style="background-color:' . esc_attr( $bg ) . '"' : ''; ?> />
								<span class="p20link-app-label"><?php echo esc_html( $link['label'] ); ?></span>
								<?php if ( $r['episode_wanted'] && ! $link['episode'] ) : ?>
									<span class="p20link-app-note"><?php echo esc_html( isset( $link['note'] ) && 'discover' === $link['note'] ? __( '(opens Fountain)', 'p20-link' ) : __( '(opens the show)', 'p20-link' ) ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="p20link-footer">
				<?php esc_html_e( 'Share a show with', 'p20-link' ); ?>
				<a href="https://phafe.com/podcastlink" target="_blank" rel="noopener noreferrer">PodcastLink</a>
			</p>
			<p class="p20link-footer">
				<?php esc_html_e( 'Powered by', 'p20-link' ); ?>
				<a href="https://podcastindex.org" target="_blank" rel="noopener noreferrer">Podcast Index</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_search_ui( $message = '' ) {
		ob_start();
		?>
		<div class="p20link-search">
			<h1 class="p20link-search-h1">PodcastLink</h1>
			<h2 class="p20link-search-title"><?php esc_html_e( 'Find a podcast', 'p20-link' ); ?></h2>
			<p class="p20link-search-subtitle"><?php esc_html_e( 'Search, pick a show (or one of its episodes) and get a link that opens it in Podcasting 2.0 apps.', 'p20-link' ); ?></p>
			<?php if ( $message ) : ?>
				<p class="p20link-error"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<form class="p20link-search-row" role="search">
				<input type="search" class="p20link-search-input" placeholder="<?php esc_attr_e( 'Podcast name…', 'p20-link' ); ?>" maxlength="100" aria-label="<?php esc_attr_e( 'Search podcasts', 'p20-link' ); ?>" />
				<button type="submit" class="p20link-search-btn"><?php esc_html_e( 'Search', 'p20-link' ); ?></button>
			</form>
			<div class="p20link-search-status" aria-live="polite"></div>
			<button type="button" class="p20link-back" hidden></button>
			<ul class="p20link-results"></ul>
			<div class="p20link-pager" hidden>
				<button type="button" class="p20link-pager-btn p20link-pager-prev"></button>
				<span class="p20link-pager-label"></span>
				<button type="button" class="p20link-pager-btn p20link-pager-next"></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Social previews (Open Graph / Twitter Card)
	 * ------------------------------------------------------------------ */

	/** True when this request should own the page's social tags. */
	private function wants_preview() {
		if ( 'on' !== $this->opt( 'social_preview', 'on' ) || ! is_singular() || ! $this->has_credentials() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'p20link' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Open a wp_head buffer when we are going to emit tags, so the theme's
	 * (or an SEO plugin's) competing og:/twitter: metas can be dropped before
	 * ours go out - scrapers that read the first og:description were getting
	 * a blank theme one (Telegram report, OUTSTANDING #11).
	 */
	public function og_buffer_start() {
		if ( ! $this->wants_preview() || '' === $this->get_arg( 'show' ) ) {
			return;
		}
		ob_start();
		$this->og_buffered  = true;
		$this->og_meta_html = '';
	}

	/**
	 * Close the buffer: strip every og:/twitter: meta the head accumulated,
	 * then append our own set (only if we built one - a failed resolve leaves
	 * the existing tags alone rather than blanking the preview).
	 */
	public function og_buffer_end() {
		if ( ! $this->og_buffered ) {
			return;
		}
		$this->og_buffered = false;
		$html              = (string) ob_get_clean();
		$ours              = $this->og_meta_html;
		$this->og_meta_html = '';
		if ( '' === $ours ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$html = (string) preg_replace(
			'#<meta\b[^>]*\b(?:property|name)\s*=\s*["\'](?:og|twitter):[^"\']*["\'][^>]*>\s*#i',
			'',
			$html
		);
		echo $html . $ours; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function output_social_preview_meta() {
		$this->og_meta_html = '';
		if ( ! $this->wants_preview() ) {
			return;
		}
		$show = $this->get_arg( 'show' );
		if ( '' === $show ) {
			return;
		}
		$episode = $this->get_arg( 'episode' );

		$this->resolve_budgeted = true;
		$r                      = $this->resolve_release( $show, $episode ); // Never queues a refresh (refresh_allowed is render-only).
		if ( ! $r ) {
			return;
		}

		$has_ep = $r['episode_wanted'] && ! $r['episode_missing'] && '' !== $r['episode_title'];
		$title  = $has_ep ? sprintf( '%s - %s', $r['episode_title'], $r['show_title'] ) : $r['show_title'];
		$desc   = $r['author'] ? sprintf( /* translators: %s: author. */ __( 'Listen in a Podcasting 2.0 app. By %s', 'p20-link' ), $r['author'] ) : __( 'Listen in a Podcasting 2.0 app.', 'p20-link' );
		$image  = $has_ep && $r['episode_image'] ? $r['episode_image'] : $r['image'];
		$url    = $this->page_url( $show, $episode );

		$meta = array(
			array( 'property', 'og:type', 'website' ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $desc ),
			array( 'property', 'og:url', $url ),
			array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
			array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' ),
			array( 'name', 'twitter:title', $title ),
			array( 'name', 'twitter:description', $desc ),
		);
		if ( $image ) {
			$meta[] = array( 'property', 'og:image', $image );
			$meta[] = array( 'name', 'twitter:image', $image );
		}
		$html = '';
		foreach ( $meta as $m ) {
			$value = in_array( $m[1], array( 'og:url', 'og:image', 'twitter:image' ), true ) ? esc_url( $m[2] ) : esc_attr( $m[2] );
			$html .= sprintf( "<meta %s=\"%s\" content=\"%s\" />\n", esc_attr( $m[0] ), esc_attr( $m[1] ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$this->og_meta_html = $html;
		if ( ! $this->og_buffered ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ------------------------------------------------------------------ *
	 * AJAX: search relay
	 * ------------------------------------------------------------------ */

	public function ajax_search() {
		if ( ! check_ajax_referer( 'p20link_search', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad nonce' ), 403 );
		}
		if ( $this->is_rate_limited( 'search', self::SEARCH_RATE_MAX, MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'rate limited' ), 429 );
		}
		if ( ! $this->has_credentials() ) {
			wp_send_json_error( array( 'message' => 'not configured' ), 503 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'shows';

		if ( 'episodes' === $type ) {
			$feed_id = isset( $_POST['feed'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['feed'] ) ) : '';
			if ( '' === $feed_id || strlen( $feed_id ) > 12 ) {
				wp_send_json_error( array( 'message' => 'bad feed' ), 400 );
			}
			$ck  = 'p20l_s_' . self::SEARCH_CACHE_SCHEMA . '_ep_' . $feed_id;
			$out = get_transient( $ck );
			if ( false === $out ) {
				$body = $this->api_get( '/episodes/byfeedid', array( 'id' => $feed_id, 'max' => (string) self::EPISODES_MAX ) );
				if ( null === $body ) {
					wp_send_json_error( array( 'message' => 'upstream' ), 502 );
				}
				$out = array();
				foreach ( isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array() as $item ) {
					if ( empty( $item['guid'] ) ) {
						continue;
					}
					$out[] = array(
						'guid'  => (string) $item['guid'],
						'title' => isset( $item['title'] ) ? (string) $item['title'] : '',
						'date'  => ! empty( $item['datePublished'] ) ? wp_date( get_option( 'date_format' ), (int) $item['datePublished'] ) : '',
					);
				}
				set_transient( $ck, $out, self::SEARCH_CACHE_TTL );
			}
			wp_send_json_success( array( 'episodes' => $out ) );
		}

		$q = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		if ( '' === $q ) {
			wp_send_json_error( array( 'message' => 'bad query' ), 400 );
		}

		// A pasted share link (Fountain first, then the other target apps'
		// URL shapes) resolves to guids and jumps straight to that page.
		// Checked before the length cap so long share URLs (utm params
		// etc.) still work.
		$direct = $this->search_direct_link( $q );
		if ( null !== $direct ) {
			if ( false === $direct ) {
				wp_send_json_error( array( 'message' => 'upstream' ), 502 );
			}
			if ( array() === $direct ) {
				wp_send_json_success( array( 'shows' => array() ) );
			}
			wp_send_json_success( array( 'direct' => $direct ) );
		}

		if ( mb_strlen( $q ) > 100 ) {
			wp_send_json_error( array( 'message' => 'bad query' ), 400 );
		}
		$ck  = 'p20l_s_' . self::SEARCH_CACHE_SCHEMA . '_q_' . md5( strtolower( $q ) );
		$out = get_transient( $ck );
		if ( false === $out ) {
			$body = $this->api_get( '/search/byterm', array( 'q' => $q, 'max' => (string) self::SEARCH_MAX ) );
			if ( null === $body ) {
				wp_send_json_error( array( 'message' => 'upstream' ), 502 );
			}
			$out = array();
			foreach ( isset( $body['feeds'] ) && is_array( $body['feeds'] ) ? $body['feeds'] : array() as $feed ) {
				if ( empty( $feed['id'] ) || ! empty( $feed['dead'] ) ) {
					continue;
				}
				$ref = ! empty( $feed['podcastGuid'] ) ? $this->guard_show( $feed['podcastGuid'] ) : '';
				$out[] = array(
					'ref'    => '' !== $ref ? $ref : (string) (int) $feed['id'],
					'feed'   => (int) $feed['id'],
					'title'  => isset( $feed['title'] ) ? (string) $feed['title'] : '',
					'author' => isset( $feed['author'] ) ? (string) $feed['author'] : '',
				);
			}
			set_transient( $ck, $out, self::SEARCH_CACHE_TTL );
		}
		wp_send_json_success( array( 'shows' => $out ) );
	}

	/* ------------------------------------------------------------------ *
	 * AJAX: usage logging
	 * ------------------------------------------------------------------ */

	private function logging_enabled() {
		return (bool) $this->opt( 'log_enabled', true );
	}

	private function log_rate_max() {
		return max( 1, min( 600, (int) $this->opt( 'log_rate', 30 ) ) );
	}

	private function log_max_rows() {
		return max( 1000, min( 5000000, (int) $this->opt( 'log_max_rows', 100000 ) ) );
	}

	private function log_beacon_args() {
		if ( ! check_ajax_referer( 'p20link_log', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad nonce' ), 403 );
		}
		if ( $this->is_rate_limited( 'log', $this->log_rate_max(), MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'rate limited' ), 429 );
		}
		if ( ! $this->logging_enabled() ) {
			wp_send_json_success();
		}
		$show    = isset( $_POST['show'] ) ? $this->guard_show( sanitize_text_field( wp_unslash( $_POST['show'] ) ) ) : '';
		$episode = isset( $_POST['episode'] ) ? $this->guard_episode( sanitize_text_field( wp_unslash( $_POST['episode'] ) ) ) : '';
		if ( '' === $show ) {
			wp_send_json_error( array( 'message' => 'bad show' ), 400 );
		}
		return array( $show, $episode );
	}

	public function ajax_log_open() {
		list( $show, $episode ) = $this->log_beacon_args();
		$this->resolve_budgeted = true;
		$r                      = $this->resolve_release( $show, $episode );
		if ( $r ) {
			$this->log_row( $r, '' );
		}
		wp_send_json_success();
	}

	public function ajax_log_app() {
		list( $show, $episode ) = $this->log_beacon_args();
		$app = isset( $_POST['app'] ) ? sanitize_key( wp_unslash( $_POST['app'] ) ) : '';
		if ( ! isset( $this->app_registry()[ $app ] ) ) {
			wp_send_json_error( array( 'message' => 'bad app' ), 400 );
		}
		$this->resolve_budgeted = true;
		$r                      = $this->resolve_release( $show, $episode );
		if ( $r ) {
			$this->log_row( $r, $app );
		}
		wp_send_json_success();
	}

	/** Only resolved titles are stored - never raw guids. */
	private function log_row( $r, $app ) {
		global $wpdb;
		$wpdb->insert(
			$this->log_table(),
			array(
				'logged_at'     => gmdate( 'Y-m-d H:i:s' ),
				'show_title'    => $r['show_title'],
				'episode_title' => ( $r['episode_wanted'] && ! $r['episode_missing'] ) ? $r['episode_title'] : '',
				'app'           => $app,
			),
			array( '%s', '%s', '%s', '%s' )
		);
		$this->prune_log();
	}

	/** Trim to the newest N rows, at most once an hour. */
	private function prune_log() {
		if ( get_transient( 'p20l_prune_gate' ) ) {
			return;
		}
		set_transient( 'p20l_prune_gate', 1, HOUR_IN_SECONDS );
		global $wpdb;
		$table  = $this->log_table();
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $this->log_max_rows() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/* ------------------------------------------------------------------ *
	 * Assets
	 * ------------------------------------------------------------------ */

	public function enqueue_assets() {
		wp_register_style( 'p20-link', false, array(), self::VERSION );
		wp_enqueue_style( 'p20-link' );
		wp_add_inline_style( 'p20-link', $this->inline_css() );

		wp_register_script( 'p20-link', false, array(), self::VERSION, true );
		wp_enqueue_script( 'p20-link' );
		wp_localize_script( 'p20-link', 'p20Link', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'p20link_search' ),
			'logNonce' => wp_create_nonce( 'p20link_log' ),
			'perPage'  => 8,
			'i18n'     => array(
				'searching'   => __( 'Searching…', 'p20-link' ),
				'noResults'   => __( 'No results found.', 'p20-link' ),
				'error'       => __( 'Something went wrong - please try again.', 'p20-link' ),
				'copied'      => __( 'Link copied!', 'p20-link' ),
				'prev'        => __( '‹ Previous', 'p20-link' ),
				'next'        => __( 'Next ›', 'p20-link' ),
				'page'        => __( 'Page', 'p20-link' ),
				'loadingEps'  => __( 'Loading episodes…', 'p20-link' ),
				'wholeShow'   => __( 'Link to the whole show', 'p20-link' ),
				'pickHint'    => __( 'Or pick an episode:', 'p20-link' ),
				'back'        => __( '‹ Back to results', 'p20-link' ),
				'noEpisodes'  => __( 'No episodes found for this show.', 'p20-link' ),
			),
		) );
		wp_add_inline_script( 'p20-link', $this->inline_js() );
	}

	private function inline_css() {
		return '
			.p20link, .p20link-search { max-width: 480px; margin: 1.5em 0; }
			.p20link-header { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1.2em; margin-bottom: 0.75em; }
			.p20link-info { flex: 1 1 220px; min-width: 0; }
			.p20link-title { font-weight: 700; font-size: 1.8em; line-height: 1.2; margin: 0 0 0.2em; }
			.p20link-sub { font-weight: 600; font-size: 1.05em; margin: 0; }
			.p20link-art { width: 200px; height: 200px; object-fit: cover; border-radius: 8px; flex: 0 0 200px; order: 2; }
			.p20link-notice { margin: 0 0 1em; padding: 0.6em 0.9em; border-left: 4px solid #dba617; background: #fcf9e8; }
			.p20link-share { margin: 0 0 1.1em; }
			.p20link-share-btn { display: inline-flex; align-items: center; gap: 0.4em; padding: 0.5em 0.9em; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; cursor: pointer; font-size: 0.95em; }
			.p20link-share-btn:hover { background-color: #f0f0f0; }
			.p20link-heading { font-weight: 600; margin-bottom: 0.75em; }
			.p20link-list, .p20link-results { list-style: none; margin: 0; padding: 0; }
			.p20link-item { margin-bottom: 0.5em; }
			.p20link-item a { display: flex; align-items: center; gap: 0.6em; padding: 0.75em 1em; border: 2px solid #ddd; border-radius: 6px; text-decoration: none; transition: background-color 0.15s ease; }
			.p20link-item a:hover { background-color: #f5f5f5; }
			.p20link-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 6px; }
			.p20link-app-note { color: #666; font-size: 0.85em; }
			.p20link-error { color: #a00; }
			.p20link-footer { margin-top: 1.25em; font-size: 0.85em; color: #444; }
			.p20link-footer + .p20link-footer { margin-top: 0.3em; }
			.p20link-footer a { color: inherit; text-decoration: underline; }
			.p20link-search-h1 { font-weight: 700; font-size: 2em; line-height: 1.2; margin: 0 0 0.25em; }
			.p20link-search-title { font-weight: 700; font-size: 1.8em; line-height: 1.2; margin: 0 0 0.2em; }
			.p20link-search-subtitle { color: #666; margin: 0 0 1.1em; }
			.p20link-search-row { display: flex; gap: 0.5em; margin-bottom: 1em; }
			.p20link-search-input { flex: 1 1 auto; padding: 0.65em 0.9em; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; }
			.p20link-search-btn { flex: 0 0 auto; padding: 0.65em 1.2em; border: 1px solid #333; border-radius: 6px; background: #333; color: #fff; cursor: pointer; font-size: 1em; }
			.p20link-search-btn:hover { background: #111; }
			.p20link-search-status { color: #666; margin-bottom: 0.75em; }
			.p20link-back { margin: 0 0 0.75em; padding: 0.4em 0.8em; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; }
			.p20link-back[hidden] { display: none; }
			.p20link-result { margin-bottom: 0.5em; }
			.p20link-result button { display: block; width: 100%; text-align: left; padding: 0.7em 1em; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; }
			.p20link-result button:hover { background-color: #f5f5f5; }
			.p20link-result-hint { color: #666; font-weight: 600; margin: 0.9em 0 0.5em; list-style: none; }
			.p20link-result-title { font-weight: 600; }
			.p20link-result-sub { color: #666; font-size: 0.9em; }
			.p20link-pager { display: flex; align-items: center; gap: 0.6em; margin-top: 0.9em; }
			.p20link-pager[hidden] { display: none; }
			.p20link-pager-btn { padding: 0.45em 0.9em; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; font-size: 0.95em; }
			.p20link-pager-btn:disabled { opacity: 0.45; cursor: default; }
			.p20link-pager-label { color: #666; font-size: 0.9em; }
		';
	}

	private function inline_js() {
		return <<<'JS'
		(function () {
			var cfg = window.p20Link || {};
			var t = cfg.i18n || {};

			function post(data) {
				var body = new URLSearchParams();
				Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
				return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true })
					.then(function (r) { return r.json(); });
			}

			function beacon(action, extra) {
				var d = { action: action, nonce: cfg.logNonce };
				Object.keys(extra).forEach(function (k) { d[k] = extra[k]; });
				try { post(d).catch(function () {}); } catch (e) {}
			}

			function el(tag, cls, text) {
				var n = document.createElement(tag);
				if (cls) { n.className = cls; }
				if (text) { n.textContent = text; }
				return n;
			}

			function ready(fn) {
				if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
			}

			ready(function () {
				// Share buttons.
				document.querySelectorAll('.p20link-share-btn').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var url = btn.getAttribute('data-url');
						var label = btn.querySelector('.p20link-share-label');
						var old = label ? label.textContent : '';
						function done() { if (label) { label.textContent = t.copied; setTimeout(function () { label.textContent = old; }, 1800); } }
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(url).then(done, function () { window.prompt('', url); });
						} else { window.prompt('', url); }
					});
				});

				// Launch logging.
				document.querySelectorAll('.p20link').forEach(function (box) {
					box.querySelectorAll('.p20link-item a').forEach(function (a) {
						a.addEventListener('click', function () {
							var li = a.closest('.p20link-item');
							beacon('p20link_log_app', {
								app: li ? li.getAttribute('data-app') : '',
								show: box.getAttribute('data-show') || '',
								episode: box.getAttribute('data-episode') || ''
							});
						});
					});
				});

				// Optional random button order.
				document.querySelectorAll('[data-p20-shuffle="1"]').forEach(function (ul) {
					var items = Array.prototype.slice.call(ul.children);
					for (var j = items.length - 1; j > 0; j--) {
						var k = Math.floor(Math.random() * (j + 1));
						var tmp = items[j]; items[j] = items[k]; items[k] = tmp;
					}
					items.forEach(function (li) { ul.appendChild(li); });
				});

				// Search UI.
				document.querySelectorAll('.p20link-search').forEach(function (root) {
					var form = root.querySelector('.p20link-search-row');
					var input = root.querySelector('.p20link-search-input');
					var status = root.querySelector('.p20link-search-status');
					var list = root.querySelector('.p20link-results');
					var pager = root.querySelector('.p20link-pager');
					var prev = root.querySelector('.p20link-pager-prev');
					var next = root.querySelector('.p20link-pager-next');
					var plabel = root.querySelector('.p20link-pager-label');
					var back = root.querySelector('.p20link-back');
					var shows = [], page = 0, per = cfg.perPage || 8;

					prev.textContent = t.prev; next.textContent = t.next; back.textContent = t.back;

					function go(ref, episode) {
						var extra = { show: ref, episode: episode || '' };
						beacon('p20link_log_open', extra);
						var u = new URLSearchParams();
						u.set('show', ref);
						if (episode) { u.set('episode', episode); }
						// Give the beacon a moment to leave before navigating.
						setTimeout(function () { window.location.href = window.location.pathname + '?' + u.toString(); }, 150);
					}

					function renderShows() {
						list.innerHTML = '';
						back.hidden = true;
						var start = page * per, slice = shows.slice(start, start + per);
						slice.forEach(function (s) {
							var li = el('li', 'p20link-result');
							var b = el('button');
							b.type = 'button';
							b.appendChild(el('div', 'p20link-result-title', s.title));
							if (s.author) { b.appendChild(el('div', 'p20link-result-sub', s.author)); }
							b.addEventListener('click', function () { showEpisodes(s); });
							li.appendChild(b);
							list.appendChild(li);
						});
						var pages = Math.ceil(shows.length / per);
						pager.hidden = pages <= 1;
						prev.disabled = page === 0;
						next.disabled = page >= pages - 1;
						plabel.textContent = t.page + ' ' + (page + 1) + ' / ' + pages;
					}

					function showEpisodes(s) {
						pager.hidden = true;
						list.innerHTML = '';
						status.textContent = t.loadingEps;
						back.hidden = false;
						post({ action: 'p20link_search', nonce: cfg.nonce, type: 'episodes', feed: s.feed }).then(function (res) {
							status.textContent = '';
							var li = el('li', 'p20link-result');
							var b = el('button');
							b.type = 'button';
							b.appendChild(el('div', 'p20link-result-title', t.wholeShow));
							b.appendChild(el('div', 'p20link-result-sub', s.title));
							b.addEventListener('click', function () { go(s.ref, ''); });
							li.appendChild(b);
							list.appendChild(li);
							var eps = (res && res.success && res.data.episodes) || [];
							if (!eps.length) { status.textContent = t.noEpisodes; return; }
							list.appendChild(el('li', 'p20link-result-hint', t.pickHint));
							eps.forEach(function (e) {
								var li2 = el('li', 'p20link-result');
								var b2 = el('button');
								b2.type = 'button';
								b2.appendChild(el('div', 'p20link-result-title', e.title || e.guid));
								if (e.date) { b2.appendChild(el('div', 'p20link-result-sub', e.date)); }
								b2.addEventListener('click', function () { go(s.ref, e.guid); });
								li2.appendChild(b2);
								list.appendChild(li2);
							});
						}).catch(function () { status.textContent = t.error; });
					}

					back.addEventListener('click', function () { status.textContent = ''; renderShows(); });
					prev.addEventListener('click', function () { if (page > 0) { page--; renderShows(); } });
					next.addEventListener('click', function () { page++; renderShows(); });

					form.addEventListener('submit', function (ev) {
						ev.preventDefault();
						var q = input.value.trim();
						if (!q) { return; }
						status.textContent = t.searching;
						list.innerHTML = ''; pager.hidden = true; back.hidden = true;
						post({ action: 'p20link_search', nonce: cfg.nonce, type: 'shows', q: q }).then(function (res) {
							if (!res || !res.success) { status.textContent = t.error; return; }
							if (res.data.direct) { go(res.data.direct.ref, res.data.direct.episode || ''); return; }
							shows = res.data.shows || [];
							page = 0;
							status.textContent = shows.length ? '' : t.noResults;
							renderShows();
						}).catch(function () { status.textContent = t.error; });
					});
				});
			});
		})();
JS;
	}

	/* ------------------------------------------------------------------ *
	 * Admin
	 * ------------------------------------------------------------------ */

	public function register_settings_page() {
		add_options_page(
			__( 'Podcast 2.0 Link', 'p20-link' ),
			__( 'Podcast 2.0 Link', 'p20-link' ),
			'manage_options',
			'p20-link',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		$group = 'p20link_settings';
		register_setting( $group, 'p20link_api_key', array( 'sanitize_callback' => array( $this, 'sanitize_text_setting' ) ) );
		register_setting( $group, 'p20link_api_secret', array( 'sanitize_callback' => array( $this, 'sanitize_secret' ) ) );
		register_setting( $group, 'p20link_apps', array( 'sanitize_callback' => array( $this, 'sanitize_apps_settings' ) ) );
		register_setting( $group, 'p20link_show_header', array( 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => true ) );
		register_setting( $group, 'p20link_random_app_order', array( 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => false ) );
		register_setting( $group, 'p20link_social_preview', array( 'sanitize_callback' => array( $this, 'sanitize_social_mode' ), 'default' => 'on' ) );
		register_setting( $group, 'p20link_refresh_hours', array( 'sanitize_callback' => array( $this, 'sanitize_refresh_hours' ), 'default' => self::REFRESH_DEFAULT ) );
		register_setting( $group, 'p20link_log_enabled', array( 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => true ) );
		register_setting( $group, 'p20link_log_rate', array( 'sanitize_callback' => array( $this, 'sanitize_log_rate' ), 'default' => 30 ) );
		register_setting( $group, 'p20link_log_max_rows', array( 'sanitize_callback' => array( $this, 'sanitize_log_max_rows' ), 'default' => 100000 ) );
	}

	public function sanitize_text_setting( $v ) {
		return sanitize_text_field( (string) $v );
	}

	/** A blank secret field keeps the stored secret instead of wiping it. */
	public function sanitize_secret( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		return '' === $v ? (string) get_option( 'p20link_api_secret', '' ) : $v;
	}

	public function sanitize_bool( $v ) {
		return $v ? 1 : 0;
	}

	public function sanitize_social_mode( $v ) {
		return 'off' === $v ? 'off' : 'on';
	}

	public function sanitize_refresh_hours( $v ) {
		return max( 1, min( 720, (int) $v ) );
	}

	public function sanitize_log_rate( $v ) {
		return max( 1, min( 600, (int) $v ) );
	}

	public function sanitize_log_max_rows( $v ) {
		return max( 1000, min( 5000000, (int) $v ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'settings' => __( 'Settings', 'p20-link' ),
			'log'      => __( 'View Log', 'p20-link' ),
			'reports'  => __( 'Reports', 'p20-link' ),
			'cache'    => __( 'Cache', 'p20-link' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Podcast 2.0 Link', 'p20-link' ) . ' <span class="description" style="font-size:14px;font-weight:400;">' . esc_html( self::VERSION ) . '</span></h1>';
		if ( isset( $_GET['p20msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['p20msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( array( 'page' => 'p20-link', 'tab' => $slug ), admin_url( 'options-general.php' ) ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';
		switch ( $tab ) {
			case 'log':
				$this->render_log_tab();
				break;
			case 'reports':
				$this->render_reports_tab();
				break;
			case 'cache':
				$this->render_cache_tab();
				break;
			default:
				$this->render_settings_tab();
		}
		echo '</div>';
	}

	private function render_settings_tab() {
		list( $key, ) = $this->credentials();
		$const        = defined( 'P20LINK_API_KEY' ) || defined( 'P20LINK_API_SECRET' );
		$apps         = $this->app_registry();
		$order        = $this->ordered_slugs();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'p20link_settings' ); ?>

			<h2><?php esc_html_e( 'Podcast Index API', 'p20-link' ); ?></h2>
			<p><?php
			printf(
				/* translators: %s: link to the Podcast Index API signup page. */
				esc_html__( 'Free credentials from %s. Used for show/episode lookups and search.', 'p20-link' ),
				'<a href="https://api.podcastindex.org" target="_blank" rel="noopener noreferrer">api.podcastindex.org</a>'
			);
			?></p>
			<?php if ( $const ) : ?>
				<p><em><?php esc_html_e( 'Credentials are defined in wp-config.php (P20LINK_API_KEY / P20LINK_API_SECRET) and take priority over the fields below.', 'p20-link' ); ?></em></p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="p20link_api_key"><?php esc_html_e( 'API key', 'p20-link' ); ?></label></th>
					<td><input type="text" id="p20link_api_key" name="p20link_api_key" class="regular-text" value="<?php echo esc_attr( (string) $this->opt( 'api_key' ) ); ?>" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="p20link_api_secret"><?php esc_html_e( 'API secret', 'p20-link' ); ?></label></th>
					<td>
						<input type="password" id="p20link_api_secret" name="p20link_api_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $this->opt( 'api_secret' ) ? esc_attr__( '(saved - leave blank to keep)', 'p20-link' ) : ''; ?>" />
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Apps', 'p20-link' ); ?></h2>
			<p><?php esc_html_e( 'Lower order numbers are listed first. Podfriend and Podcast Guru only appear for shows that have an Apple Podcasts id; Castamatic, TrueFans and Podcast Index need the podcast:guid (Podcast Index also accepts the feed id).', 'p20-link' ); ?></p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'App', 'p20-link' ); ?></th>
					<th><?php esc_html_e( 'Enabled', 'p20-link' ); ?></th>
					<th><?php esc_html_e( 'Order', 'p20-link' ); ?></th>
					<th><?php esc_html_e( 'Icon URL (blank = app favicon)', 'p20-link' ); ?></th>
					<th><?php esc_html_e( 'Icon background', 'p20-link' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $order as $position => $slug ) :
					$cfg = $this->get_app_config( $slug );
					$n   = 'p20link_apps[' . $slug . ']';
					?>
					<tr>
						<td><strong><?php echo esc_html( $apps[ $slug ]['label'] ); ?></strong><br /><small><?php echo esc_html( $apps[ $slug ]['domain'] ); ?></small></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $cfg['enabled'] ); ?> /></td>
						<td><input type="number" min="0" max="99" style="width:4em" name="<?php echo esc_attr( $n ); ?>[order]" value="<?php echo esc_attr( (string) $position ); ?>" /></td>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[icon_url]" value="<?php echo esc_attr( $cfg['icon_url'] ); ?>" />
							<?php
							$preview_bg = $this->app_icon_bg( $slug );
							?>
							<img class="p20link-icon-preview" src="<?php echo esc_url( $this->app_icon( $slug, $apps[ $slug ] ) ); ?>" alt="" width="24" height="24" style="vertical-align:middle;margin-left:6px;border:1px solid #ccd0d4;border-radius:4px;<?php echo '' !== $preview_bg ? 'background-color:' . esc_attr( $preview_bg ) . ';' : ''; ?>" onerror="this.style.display='none'" />
						</td>
						<td>
							<input type="color" name="<?php echo esc_attr( $n ); ?>[icon_bg]" value="<?php echo esc_attr( $this->app_icon_bg( $slug ) ? $this->app_icon_bg( $slug ) : '#ffffff' ); ?>" />
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[icon_bg_none]" value="1" <?php checked( '' === $this->app_icon_bg( $slug ) ); ?> /> <?php esc_html_e( 'None', 'p20-link' ); ?></label>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Display', 'p20-link' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Header', 'p20-link' ); ?></th>
					<td><label><input type="checkbox" name="p20link_show_header" value="1" <?php checked( (bool) $this->opt( 'show_header', true ) ); ?> /> <?php esc_html_e( 'Show title, author and artwork above the buttons (override per placement with header="false")', 'p20-link' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Button order', 'p20-link' ); ?></th>
					<td><label><input type="checkbox" name="p20link_random_app_order" value="1" <?php checked( (bool) $this->opt( 'random_app_order', false ) ); ?> /> <?php esc_html_e( 'Randomise the order on every page load', 'p20-link' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="p20link_social_preview"><?php esc_html_e( 'Social previews', 'p20-link' ); ?></label></th>
					<td>
						<select id="p20link_social_preview" name="p20link_social_preview">
							<option value="on" <?php selected( $this->opt( 'social_preview', 'on' ), 'on' ); ?>><?php esc_html_e( 'On (Open Graph / Twitter Card tags on link pages)', 'p20-link' ); ?></option>
							<option value="off" <?php selected( $this->opt( 'social_preview', 'on' ), 'off' ); ?>><?php esc_html_e( 'Off', 'p20-link' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'If an SEO plugin already outputs tags on the same page you may get two sets; turn this off in that case.', 'p20-link' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="p20link_refresh_hours"><?php esc_html_e( 'Podcast Index refresh', 'p20-link' ); ?></label></th>
					<td><input type="number" min="1" max="720" id="p20link_refresh_hours" name="p20link_refresh_hours" value="<?php echo esc_attr( (string) (int) $this->opt( 'refresh_hours', self::REFRESH_DEFAULT ) ); ?>" /> <?php esc_html_e( 'hours before cached metadata is refreshed in the background (1-720)', 'p20-link' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Usage logging', 'p20-link' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log usage', 'p20-link' ); ?></th>
					<td><label><input type="checkbox" name="p20link_log_enabled" value="1" <?php checked( $this->logging_enabled() ); ?> /> <?php esc_html_e( 'Record search picks and app-button clicks (titles only, never raw ids; page views are not logged)', 'p20-link' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="p20link_log_rate"><?php esc_html_e( 'Beacon rate limit', 'p20-link' ); ?></label></th>
					<td><input type="number" min="1" max="600" id="p20link_log_rate" name="p20link_log_rate" value="<?php echo esc_attr( (string) $this->log_rate_max() ); ?>" /> <?php esc_html_e( 'requests per minute per IP', 'p20-link' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="p20link_log_max_rows"><?php esc_html_e( 'Keep newest rows', 'p20-link' ); ?></label></th>
					<td><input type="number" min="1000" max="5000000" id="p20link_log_max_rows" name="p20link_log_max_rows" value="<?php echo esc_attr( (string) $this->log_max_rows() ); ?>" /></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_log_tab() {
		global $wpdb;
		$table = $this->log_table();
		$rows  = $wpdb->get_results( "SELECT logged_at, show_title, episode_title, app FROM {$table} ORDER BY id DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<p><?php esc_html_e( 'Newest 200 rows. "Pick" = a visitor chose a result in the search box; otherwise the app that was launched.', 'p20-link' ); ?></p>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'When (UTC)', 'p20-link' ); ?></th><th><?php esc_html_e( 'Show', 'p20-link' ); ?></th><th><?php esc_html_e( 'Episode', 'p20-link' ); ?></th><th><?php esc_html_e( 'Event', 'p20-link' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Nothing logged yet.', 'p20-link' ); ?></td></tr>
			<?php else : foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->logged_at ); ?></td>
					<td><?php echo esc_html( $row->show_title ); ?></td>
					<td><?php echo esc_html( $row->episode_title ); ?></td>
					<td><?php echo esc_html( '' === $row->app ? __( 'Pick', 'p20-link' ) : ( isset( $this->app_registry()[ $row->app ] ) ? $this->app_registry()[ $row->app ]['label'] : $row->app ) ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em" onsubmit="return confirm('<?php echo esc_js( __( 'Delete every log row?', 'p20-link' ) ); ?>');">
			<input type="hidden" name="action" value="p20link_clear_log" />
			<?php wp_nonce_field( 'p20link_clear_log' ); ?>
			<?php submit_button( __( 'Clear log', 'p20-link' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_reports_tab() {
		global $wpdb;
		$table  = $this->log_table();
		$ranges = array( '7' => __( 'Last 7 days', 'p20-link' ), '30' => __( 'Last 30 days', 'p20-link' ), '90' => __( 'Last 90 days', 'p20-link' ), 'all' => __( 'All time', 'p20-link' ) );
		$range  = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $ranges[ $range ] ) ) {
			$range = '30';
		}
		$since = 'all' === $range ? '1970-01-01 00:00:00' : gmdate( 'Y-m-d H:i:s', time() - ( (int) $range * DAY_IN_SECONDS ) );

		$by_show = $wpdb->get_results( $wpdb->prepare( "SELECT show_title, episode_title, COUNT(*) AS c FROM {$table} WHERE app = '' AND logged_at >= %s GROUP BY show_title, episode_title ORDER BY c DESC LIMIT 100", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_app  = $wpdb->get_results( $wpdb->prepare( "SELECT app, COUNT(*) AS c FROM {$table} WHERE app <> '' AND logged_at >= %s GROUP BY app ORDER BY c DESC", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<form method="get" style="margin:1em 0">
			<input type="hidden" name="page" value="p20-link" /><input type="hidden" name="tab" value="reports" />
			<select name="range" onchange="this.form.submit()">
				<?php foreach ( $ranges as $k => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $k ); ?>" <?php selected( $range, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
		<h2><?php esc_html_e( 'Picks by show / episode', 'p20-link' ); ?></h2>
		<table class="widefat striped" style="max-width:800px">
			<thead><tr><th><?php esc_html_e( 'Show', 'p20-link' ); ?></th><th><?php esc_html_e( 'Episode', 'p20-link' ); ?></th><th><?php esc_html_e( 'Count', 'p20-link' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $by_show ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No data in this range.', 'p20-link' ); ?></td></tr>
			<?php else : foreach ( $by_show as $row ) : ?>
				<tr><td><?php echo esc_html( $row->show_title ); ?></td><td><?php echo esc_html( $row->episode_title ); ?></td><td><?php echo esc_html( (string) $row->c ); ?></td></tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<h2><?php esc_html_e( 'Launches by app', 'p20-link' ); ?></h2>
		<table class="widefat striped" style="max-width:400px">
			<thead><tr><th><?php esc_html_e( 'App', 'p20-link' ); ?></th><th><?php esc_html_e( 'Count', 'p20-link' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $by_app ) ) : ?>
				<tr><td colspan="2"><?php esc_html_e( 'No data in this range.', 'p20-link' ); ?></td></tr>
			<?php else : foreach ( $by_app as $row ) : ?>
				<tr><td><?php echo esc_html( isset( $this->app_registry()[ $row->app ] ) ? $this->app_registry()[ $row->app ]['label'] : $row->app ); ?></td><td><?php echo esc_html( (string) $row->c ); ?></td></tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_cache_tab() {
		global $wpdb;
		$table = $this->cache_table();
		$rows  = $wpdb->get_results( "SELECT lookup_hash, show_title, episode_title, feed_id, itunes_id, pi_episode_id, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<p><?php esc_html_e( 'Cached Podcast Index metadata (newest 200). Deleting a row just forces a fresh lookup on its next view.', 'p20-link' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Show', 'p20-link' ); ?></th><th><?php esc_html_e( 'Episode', 'p20-link' ); ?></th>
				<th><?php esc_html_e( 'Feed id', 'p20-link' ); ?></th><th><?php esc_html_e( 'Apple id', 'p20-link' ); ?></th><th><?php esc_html_e( 'Episode id', 'p20-link' ); ?></th>
				<th><?php esc_html_e( 'Fetched (UTC)', 'p20-link' ); ?></th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Cache is empty.', 'p20-link' ); ?></td></tr>
			<?php else : foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->show_title ); ?></td>
					<td><?php echo esc_html( $row->episode_title ); ?></td>
					<td><?php echo esc_html( (string) $row->feed_id ); ?></td>
					<td><?php echo $row->itunes_id ? esc_html( (string) $row->itunes_id ) : '&ndash;'; ?></td>
					<td><?php echo $row->pi_episode_id ? esc_html( (string) $row->pi_episode_id ) : '&ndash;'; ?></td>
					<td><?php echo esc_html( $row->updated_at ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="p20link_delete_cache_row" />
							<input type="hidden" name="hash" value="<?php echo esc_attr( $row->lookup_hash ); ?>" />
							<?php wp_nonce_field( 'p20link_delete_cache_row' ); ?>
							<button class="button button-small"><?php esc_html_e( 'Delete', 'p20-link' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the whole cache?', 'p20-link' ) ); ?>');">
			<input type="hidden" name="action" value="p20link_clear_cache" />
			<?php wp_nonce_field( 'p20link_clear_cache' ); ?>
			<?php submit_button( __( 'Clear cache', 'p20-link' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private function admin_redirect( $tab, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'p20-link', 'tab' => $tab, 'p20msg' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	private function admin_guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'p20-link' ), 403 );
		}
		check_admin_referer( $action );
	}

	public function handle_clear_log() {
		$this->admin_guard( 'p20link_clear_log' );
		global $wpdb;
		$table = $this->log_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->admin_redirect( 'log', __( 'Log cleared.', 'p20-link' ) );
	}

	public function handle_delete_cache_row() {
		$this->admin_guard( 'p20link_delete_cache_row' );
		global $wpdb;
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( preg_match( '/^[0-9a-f]{32}$/', $hash ) ) {
			$wpdb->delete( $this->cache_table(), array( 'lookup_hash' => $hash ), array( '%s' ) );
		}
		$this->admin_redirect( 'cache', __( 'Row deleted.', 'p20-link' ) );
	}

	public function handle_clear_cache() {
		$this->admin_guard( 'p20link_clear_cache' );
		global $wpdb;
		$table = $this->cache_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->admin_redirect( 'cache', __( 'Cache cleared.', 'p20-link' ) );
	}
}

register_activation_hook( __FILE__, array( 'P20_Link_Resolver', 'create_tables' ) );
new P20_Link_Resolver();
