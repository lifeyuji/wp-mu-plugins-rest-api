<?php
/*
Plugin Name: REST API Proxy for Singular (Local Dev) + Title + Badge + Inline CSS + Toggle
Description:
  ローカル環境で singular の本文/タイトルをテストサイトから取得して上書き。
  リモートHTMLから inline CSS（global-styles / core-block-supports / block-style-variation）を抽出して head に注入。
  ON/OFF は wp-config.php の RPX_REMOTE_ENABLE で制御。
Version: 1.6
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ログ */
function rpx_log( $msg ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[RPX] ' . $msg );
	}
}

/** 有効判定 */
function rpx_is_enabled() {
	if ( ! defined( 'WP_ENV' ) || WP_ENV !== 'local' ) {
		return false;
	}
	// wp-config.php に define('RPX_REMOTE_ENABLE', false); を置いたら停止
	if ( defined( 'RPX_REMOTE_ENABLE' ) && RPX_REMOTE_ENABLE === false ) {
		return false;
	}
	return true;
}

function rpx_mark_remote_applied() {
	set_query_var( 'rpx_remote_applied', 1 );
}

/** 起動ログ */
add_action( 'init', function() {
	if ( rpx_is_enabled() ) {
		rpx_log( 'Loaded. RPX enabled (WP_ENV=local)' );
	} else {
		rpx_log( 'Loaded. RPX disabled (WP_ENV not local or RPX_REMOTE_ENABLE=false)' );
	}
}, 1 );

/**
 * リモートから本文/タイトルを取得して query_var に保持（同一リクエスト内で1回だけ）
 */
function rpx_fetch_remote_singular_if_needed() {
	if ( get_query_var( 'rpx_remote_fetched' ) ) {
		return;
	}
	set_query_var( 'rpx_remote_fetched', 1 );

	if ( ! rpx_is_enabled() || ! is_singular() || is_admin() ) {
		return;
	}

	$post_id   = get_queried_object_id();
	$post_type = get_post_type( $post_id );
	$slug      = get_post_field( 'post_name', $post_id );

	rpx_log( 'fetch start. post_id=' . $post_id . ' post_type=' . $post_type . ' slug=' . $slug );

	if ( ! $post_type || ! $slug ) {
		return;
	}

	$pto = get_post_type_object( $post_type );
	if ( ! $pto || empty( $pto->show_in_rest ) ) {
		return;
	}

	$rest_base = ! empty( $pto->rest_base ) ? $pto->rest_base : $post_type;

	if (
		! defined( 'REMOTE_API_URL' ) ||
		! defined( 'REMOTE_API_USERNAME' ) ||
		! defined( 'REMOTE_API_PASSWORD' )
	) {
		return;
	}

	$remote_base = rtrim( REMOTE_API_URL, '/' );
	$auth        = base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD );

	$api_url = $remote_base
		. '/wp-json/wp/v2/'
		. rawurlencode( $rest_base )
		. '?slug=' . rawurlencode( $slug )
		. '&per_page=1'
		. '&status=publish'
		. '&_fields=slug,title,content';

	rpx_log( 'Remote URL: ' . $api_url );

	$res = wp_remote_get( $api_url, [
		'headers' => [
			'Authorization' => 'Basic ' . $auth,
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) ) {
		rpx_log( 'Remote error: ' . $res->get_error_message() );
		return;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	rpx_log( 'Remote response code: ' . $code . ' body_len=' . strlen( (string) $body ) );

	if ( 200 !== (int) $code || empty( $body ) ) {
		return;
	}

	$data = json_decode( $body, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		rpx_log( 'JSON decode error: ' . json_last_error_msg() );
		return;
	}

	if ( ! is_array( $data ) || empty( $data[0] ) ) {
		return;
	}

	$remote_content = $data[0]['content']['rendered'] ?? '';
	$remote_title   = $data[0]['title']['rendered'] ?? '';

	if ( empty( $remote_content ) ) {
		return;
	}

	set_query_var( 'rpx_remote_content', $remote_content );
	set_query_var( 'rpx_remote_title', wp_strip_all_tags( $remote_title ) );

	rpx_mark_remote_applied();
}

/** 本文上書き */
add_filter( 'the_content', function( $content ) {
	rpx_fetch_remote_singular_if_needed();
	$remote = get_query_var( 'rpx_remote_content' );
	return $remote ? $remote : $content;
}, 10 );

/** タイトル上書き（表示中の投稿のみ） */
add_filter( 'the_title', function( $title, $post_id ) {
	if ( is_admin() || is_feed() ) {
		return $title;
	}
	if ( ! rpx_is_enabled() || ! is_singular() ) {
		return $title;
	}

	rpx_fetch_remote_singular_if_needed();

	if ( (int) $post_id !== (int) get_queried_object_id() ) {
		return $title;
	}

	$remote = get_query_var( 'rpx_remote_title' );
	return ( $remote !== null && $remote !== '' ) ? $remote : $title;
}, 10, 2 );

/**
 * リモートHTMLから inline CSS 抽出
 * - global-styles-inline-css
 * - core-block-supports-inline-css
 * - block-style-variation-styles-inline-css  ← 追加
 */
function rpx_fetch_remote_head_inline_css() {
	if ( ! rpx_is_enabled() ) {
		return [];
	}

	if (
		! defined( 'REMOTE_API_URL' ) ||
		! defined( 'REMOTE_API_USERNAME' ) ||
		! defined( 'REMOTE_API_PASSWORD' )
	) {
		return [];
	}

	$req_uri    = $_SERVER['REQUEST_URI'] ?? '/';
	$remote_url = rtrim( REMOTE_API_URL, '/' ) . $req_uri;

	$cache_key = 'rpx_head_css_' . md5( $remote_url );
	if ( $cached = get_transient( $cache_key ) ) {
		return $cached;
	}

	$auth = base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD );
	$res  = wp_remote_get( $remote_url, [
		'headers' => [
			'Authorization' => 'Basic ' . $auth,
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) ) {
		rpx_log( 'head fetch error: ' . $res->get_error_message() );
		return [];
	}

	$html = wp_remote_retrieve_body( $res );
	if ( ! $html ) {
		rpx_log( 'head fetch empty body' );
		return [];
	}

	// DOMで <style> を全抽出（正規表現より堅い）
	$css_map = [];
	$want_exact = [
		'global-styles-inline-css',
		'core-block-supports-inline-css',
	];

	libxml_use_internal_errors( true );
	$dom = new DOMDocument();

	// 文字化け回避
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();

	$styles = $dom->getElementsByTagName( 'style' );

	foreach ( $styles as $style ) {
		$id = $style->getAttribute( 'id' );
		if ( ! $id ) {
			continue;
		}

		// 1) 厳密一致（global/core-supports）
		if ( in_array( $id, $want_exact, true ) ) {
			$css_map[ $id ] = trim( $style->textContent );
			continue;
		}

		// 2) block-style-variation は “含む” で拾う（id揺れ対策）
		if ( strpos( $id, 'block-style-variation' ) !== false ) {
			$css_map[ $id ] = trim( $style->textContent );
			continue;
		}
	}

	// デバッグ：何を拾えたか
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		foreach ( $css_map as $k => $v ) {
			rpx_log( 'head css found: ' . $k . ' len=' . strlen( (string) $v ) );
		}
	}

	set_transient( $cache_key, $css_map, 300 );
	return $css_map;
}

/** head に inline CSS 注入 */
add_action( 'wp_head', function() {
	if ( ! rpx_is_enabled() || is_admin() ) {
		return;
	}

	$css_map = rpx_fetch_remote_head_inline_css();
	foreach ( $css_map as $id => $css ) {
		if ( $css !== '' && $css !== null ) {
			echo '<style id="' . esc_attr( $id ) . '">' . $css . "</style>\n";
		}
	}
}, 20 );

/** REMOTE適用時のみバッジ表示 */
add_action( 'wp_footer', function() {
	if ( ! rpx_is_enabled() || is_admin() || ! get_query_var( 'rpx_remote_applied' ) ) {
		return;
	}

	$remote = defined( 'REMOTE_API_URL' ) ? REMOTE_API_URL : '';
	$remote = preg_replace( '#^https?://#', '', $remote );
	$remote = rtrim( $remote, '/' );
	?>
	<div id="rpx-remote-badge">REMOTE: <?php echo esc_html( $remote ); ?></div>
	<style>
		#rpx-remote-badge{
			position:fixed;
			left:12px;
			bottom:12px;
			z-index:999999;
			padding:6px 10px;
			border-radius:999px;
			background:rgba(0,0,0,.75);
			color:#fff;
			font-size:11px;
			line-height:1;
			letter-spacing:.02em;
			backdrop-filter:blur(6px);
			-webkit-backdrop-filter:blur(6px);
			box-shadow:0 8px 24px rgba(0,0,0,.25);
			pointer-events:none;
			user-select:none;
		}
	</style>
	<?php
} );
