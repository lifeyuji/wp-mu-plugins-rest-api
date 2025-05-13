<?php
/*
Plugin Name: REST API プロキシ＆FSE同期＋コアブロックCSS補完（ローカル開発用）
Description:
  ローカル環境のみ…
  1) 通常固定ページは the_content でリモート fetch → 上書き  
  2) FSEテンプレート／パターンは REST API プロキシ＆キャッシュ  
  3) 固定ページ表示時、Cover/Button/Gallery/Group ブロックCSSを手動enqueue
Version: 1.7
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** デバッグ用 */
function rpx_log( $msg ) {
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log( '[RPX] ' . $msg );
    }
}

/**
 * 1) FSE／パターン用 REST API をプロキシ＋キャッシュ
 */
add_filter( 'rest_pre_dispatch', 'rpx_proxy_and_cache', 10, 3 );
function rpx_proxy_and_cache( $result, $server, $request ) {
    if ( ! ( defined('WP_ENV') && WP_ENV==='local' ) ) {
        return $result;
    }

    $route = $request->get_route();
    $patterns = [
        '/wp/v2/block-patterns',
        '/wp/v2/patterns',
        '/wp/v2/wp_template',
        '/wp/v2/wp_template_part',
        '/wp/v2/themes/',
    ];

    foreach ( $patterns as $base ) {
        if ( strpos( $route, $base ) === 0 ) {
            rpx_log( "Proxying route: $route" );

            $ttl       = defined('RPX_CACHE_TTL') ? RPX_CACHE_TTL : 300;
            $cache_key = 'rpx_' . md5( REMOTE_API_URL . $route . $request->get_method() . serialize( $request->get_query_params() ) );

            if ( $ttl > 0 && false !== ( $cached = get_transient( $cache_key ) ) ) {
                rpx_log( "Cache hit: $cache_key" );
                return rest_ensure_response( $cached );
            }

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

/**
 * 2) 固定ページ the_content 上書き（直接 fetch）
 */
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

/**
 * 3) 固定ページ表示時にコアブロックの CSS を手動 enqueue
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( defined('WP_ENV') && WP_ENV === 'local' && is_page() ) {
        // コアブロック共通スタイル
        wp_enqueue_style( 'wp-block-library' );

        // Cover ブロック
        wp_enqueue_style(
            'wp-block-cover',
            includes_url( 'blocks/cover/style.min.css' ),
            array(),
            null
        );
        // Button ブロック
        wp_enqueue_style(
            'wp-block-button',
            includes_url( 'blocks/button/style.min.css' ),
            array(),
            null
        );
        // Gallery ブロック
        wp_enqueue_style(
            'wp-block-gallery',
            includes_url( 'blocks/gallery/style.min.css' ),
            array(),
            null
        );
        // Group ブロック
        wp_enqueue_style(
            'wp-block-group',
            includes_url( 'blocks/group/style.min.css' ),
            array(),
            null
        );
    }
}, 0 );
