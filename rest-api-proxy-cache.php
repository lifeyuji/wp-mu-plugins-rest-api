<?php
/*
Plugin Name: REST API Proxy for Singular (Local Dev) + Badge + Debug + Title + Toggle
Description: ローカル環境で singular の本文/タイトルをリモートから取得して上書き。REMOTE適用時のみバッジ表示。ON/OFFは RPX_REMOTE_ENABLE で制御。
Version: 1.5
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rpx_log( $msg ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[RPX] ' . $msg );
	}
}

function rpx_mark_remote_applied() {
	set_query_var( 'rpx_remote_applied', 1 );
}

/**
 * リモート反映が有効か？
 * wp-config.php に define( 'RPX_REMOTE_ENABLE', true ); を置く運用推奨
 */
function rpx_is_enabled() {
	if ( ! defined( 'WP_ENV' ) || WP_ENV !== 'local' ) {
		return false;
	}
	if ( defined( 'RPX_REMOTE_ENABLE' ) && RPX_REMOTE_ENABLE === false ) {
		return false;
	}
	return true;
}

/**
 * mu-plugin が読み込まれてるか確認用（WP_DEBUG時だけ）
 */
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

	// ローカル環境 + RPX有効 + フロントの singular のみ
	if ( ! rpx_is_enabled() || ! is_singular() || is_admin() ) {
		return;
	}

	$post_id   = get_queried_object_id();
	$post_type = get_post_type( $post_id );
	$slug      = get_post_field( 'post_name', $post_id );

	rpx_log( 'fetch start. post_id=' . $post_id . ' post_type=' . $post_type . ' slug=' . $slug );

	if ( ! $post_type || ! $slug ) {
		rpx_log( 'Skip: missing post_type or slug.' );
		return;
	}

	$pto = get_post_type_object( $post_type );
	if ( ! $pto ) {
		rpx_log( 'Skip: post type object not found.' );
		return;
	}

	if ( empty( $pto->show_in_rest ) ) {
		rpx_log( 'Skip: show_in_rest is false for ' . $post_type );
		return;
	}

	$rest_base = ! empty( $pto->rest_base ) ? $pto->rest_base : $post_type;

	if ( ! defined( 'REMOTE_API_URL' ) || ! defined( 'REMOTE_API_USERNAME' ) || ! defined( 'REMOTE_API_PASSWORD' ) ) {
		rpx_log( 'Skip: REMOTE constants missing.' );
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
		rpx_log( 'No matched remote item for slug=' . $slug . ' rest_base=' . $rest_base );
		return;
	}

	$remote_content = $data[0]['content']['rendered'] ?? '';
	$remote_title   = $data[0]['title']['rendered'] ?? '';

	if ( empty( $remote_content ) ) {
		rpx_log( 'Remote content empty. slug=' . $slug );
		return;
	}

	// title.rendered はHTMLが入ることがあるのでタグ除去して保持
	$remote_title = wp_strip_all_tags( $remote_title );

	set_query_var( 'rpx_remote_content', $remote_content );
	set_query_var( 'rpx_remote_title', $remote_title );

	rpx_log( 'REMOTE STORED: slug=' . $slug . ' rest_base=' . $rest_base );
	rpx_mark_remote_applied();
}

/**
 * 本文を上書き
 */
add_filter( 'the_content', function( $content ) {
	rpx_fetch_remote_singular_if_needed();

	$remote = get_query_var( 'rpx_remote_content' );
	if ( $remote ) {
		return $remote;
	}
	return $content;
}, 10, 1 );

/**
 * タイトルを上書き（メインの queried object のみ）
 */
add_filter( 'the_title', function( $title, $post_id ) {
	if ( is_admin() || is_feed() ) {
		return $title;
	}

	rpx_fetch_remote_singular_if_needed();

	if ( ! rpx_is_enabled() || ! is_singular() ) {
		return $title;
	}

	$qid = get_queried_object_id();
	if ( (int) $post_id !== (int) $qid ) {
		return $title;
	}

	$remote_title = get_query_var( 'rpx_remote_title' );
	if ( $remote_title !== '' && $remote_title !== null ) {
		return $remote_title;
	}

	return $title;
}, 10, 2 );

/**
 * REMOTE適用時のみバッジ表示
 */
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
