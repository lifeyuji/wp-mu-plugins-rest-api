<?php
/*
Plugin Name: REST API プロキシ＆FSE同期＋コアブロックCSS補完（ローカル開発用）
Description:
  ローカル環境のみ…
  1) 通常固定ページは the_content でリモート fetch → 上書き  
  2) FSEテンプレート／パターン／グローバルスタイルは REST API プロキシ＆キャッシュ  
  3) 固定ページ表示時、Cover/Button/Gallery/Group ブロックCSSを手動enqueue
Version: 1.8
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function rpx_log( $msg ) {
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log( '[RPX] ' . $msg );
    }
}

add_filter( 'rest_pre_dispatch', 'rpx_proxy_and_cache', 10, 3 );
function rpx_proxy_and_cache( $result, $server, $request ) {
    if ( ! ( defined('WP_ENV') && WP_ENV==='local' ) ) {
        return $result;
    }

    $route = $request->get_route();

    // プロキシ対象パターンに global-styles を追加
    $patterns = [
        '/wp/v2/block-patterns',
        '/wp/v2/pattern-directory',
        '/wp/v2/templates',
        '/wp/v2/template-parts',
        '/wp/v2/wp_template',
        '/wp/v2/wp_template_part',
        '/wp/v2/themes/',
        '/wp/v2/global-styles',   // ← 追加
        '/wp/v2/settings',        // ← 必要であれば設定も
    ];

    foreach ( $patterns as $base ) {
        if ( strpos( $route, $base ) === 0 ) {
            rpx_log( "Proxying route: $route" );

            $ttl       = defined('RPX_CACHE_TTL') ? RPX_CACHE_TTL : 300;
            $cache_key = 'rpx_' . md5( REMOTE_API_URL . $route . $request->get_method() . serialize( $request->get_query_params() ) );

            // キャッシュヒット判定
            if ( $ttl > 0 && false !== ( $cached = get_transient( $cache_key ) ) ) {
                rpx_log( "Cache hit: $cache_key" );
                return rest_ensure_response( $cached );
            }

            // クエリ文字列再構築
            $qs = '';
            if ( $q = $request->get_query_params() ) {
                $qs = '?' . http_build_query( $q );
            }

            $remote_url = rtrim( REMOTE_API_URL, '/' ) . '/wp-json' . $route . $qs;
            rpx_log( "Fetching remote URL: $remote_url" );

            $auth    = base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD );
            $headers = $request->get_headers();
            $headers['Authorization'] = 'Basic ' . $auth;

            $args = [
                'method'  => $request->get_method(),
                'headers' => $headers,
                'body'    => $request->get_body(),
                'timeout' => 10,
            ];

            $response = wp_remote_request( $remote_url, $args );
            if ( is_wp_error( $response ) ) {
                rpx_log( 'Proxy error: ' . $response->get_error_message() );
                return new WP_Error( 'rpx_error', 'プロキシに失敗しました' );
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( $ttl > 0 ) {
                set_transient( $cache_key, $data, $ttl );
                rpx_log( "Stored cache: $cache_key (TTL={$ttl})" );
            }

            return rest_ensure_response( $data );
        }
    }

    return $result;
}

add_filter( 'the_content', 'rpx_override_page_content', 10, 1 );
function rpx_override_page_content( $content ) {
    rpx_log( 'the_content fired' );

    if ( ! ( defined('WP_ENV') && WP_ENV==='local' && is_page() ) ) {
        rpx_log( 'Skip override' );
        return $content;
    }

    $slug = get_post_field( 'post_name', get_queried_object_id() );
    rpx_log( "Page slug: $slug" );
    if ( ! $slug ) {
        return $content;
    }

    $api_url = rtrim( REMOTE_API_URL, '/' ) . '/wp-json/wp/v2/pages?slug=' . rawurlencode( $slug ) . '&per_page=1';
    rpx_log( "Direct fetch URL: $api_url" );

    $response = wp_remote_get( $api_url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD ),
        ],
        'timeout' => 10,
    ]);

    if ( is_wp_error( $response ) ) {
        rpx_log( 'Error fetching page: ' . $response->get_error_message() );
        return $content;
    }

    $body = wp_remote_retrieve_body( $response );
    rpx_log( 'Fetched body length: ' . strlen( $body ) );

    $pages = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        rpx_log( 'JSON decode error: ' . json_last_error_msg() );
        return $content;
    }

    if ( ! empty( $pages[0]['content']['rendered'] ) ) {
        rpx_log( 'Override content for ' . $slug );
        return $pages[0]['content']['rendered'];
    }

    return $content;
}

add_action( 'wp_enqueue_scripts', function() {
    if ( defined('WP_ENV') && WP_ENV === 'local' && is_page() ) {
        wp_enqueue_style( 'wp-block-library' );
        wp_enqueue_style( 'wp-block-cover',        includes_url( 'blocks/cover/style.min.css' ),        [], null );
        wp_enqueue_style( 'wp-block-button',       includes_url( 'blocks/button/style.min.css' ),       [], null );
        wp_enqueue_style( 'wp-block-gallery',      includes_url( 'blocks/gallery/style.min.css' ),      [], null );
        wp_enqueue_style( 'wp-block-group',        includes_url( 'blocks/group/style.min.css' ),        [], null );
        wp_enqueue_style( 'wp-block-media-text',   includes_url( 'blocks/media-text/style.min.css' ),   [], null );
    }
}, 0 );