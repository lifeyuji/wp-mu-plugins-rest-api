<?php
/*
Plugin Name: REST API Proxy for All Post Types (Local Dev)
Description: ローカル環境で singular な投稿タイプを REMOTE_API_URL から取得して上書き表示
Version: 1.1
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'the_content', 'override_singular_with_remote_data', 10, 1 );
function override_singular_with_remote_data( $content ) {
    // ローカル環境かつフロントのシングルページのみ
    if ( defined( 'WP_ENV' ) && WP_ENV === 'local' && is_singular() && ! is_admin() ) {

        // 現在表示中の投稿タイプとスラッグを取得
        $post_type    = get_post_type();
        $current_slug = get_post_field( 'post_name', get_queried_object_id() );
        error_log( sprintf( 'RPC: %s singular slug=%s', $post_type, $current_slug ) );

        if ( ! $post_type || ! $current_slug ) {
            return $content;
        }

        // 取得先 REST エンドポイントを動的に生成
        $api_url = rtrim( REMOTE_API_URL, '/' )
                 . '/wp-json/wp/v2/' . rawurlencode( $post_type )
                 . '?per_page=100';
        error_log( 'RPC API URL: ' . $api_url );

        // Basic 認証ヘッダーを作成
        $auth = base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD );
        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
            ],
            'timeout' => 10,
        ];

        // リモートから一括取得
        $response = wp_remote_get( $api_url, $args );
        if ( is_wp_error( $response ) ) {
            error_log( 'RPC Remote Error: ' . $response->get_error_message() );
            return $content;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            error_log( 'RPC Empty response body' );
            return $content;
        }

        $remote_items = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( 'RPC JSON decode error: ' . json_last_error_msg() );
            return $content;
        }

        error_log( 'RPC remote ' . $post_type . ' count: ' . count( (array) $remote_items ) );

        // スラッグ一致するものを探してコンテンツを返す
        if ( is_array( $remote_items ) ) {
            foreach ( $remote_items as $item ) {
                if ( isset( $item['slug'] ) && $item['slug'] === $current_slug ) {
                    error_log( 'RPC found matching ' . $post_type . ': ' . $current_slug );
                    if ( isset( $item['content']['rendered'] ) ) {
                        return $item['content']['rendered'];
                    }
                }
            }
            error_log( 'RPC no match for slug: ' . $current_slug );
        }
    }

    return $content;
}
