<?php
/*
Plugin Name: REST API Proxy with Cache for Local Development
Description: ローカル環境での REST API リクエストをテストサイトから取得し、Transients API でキャッシュする
Version: 1.0
Author: Your Name
*/

// ローカル環境でのみ動作させるための条件（例：WP_ENV 定数）
// if ( defined( 'WP_ENV' ) && WP_ENV === 'local' ) {
//     add_filter( 'rest_pre_dispatch', 'my_rest_api_proxy_with_cache', 10, 3 );
// }

add_filter( 'the_content', 'override_all_pages_with_remote_data_no_cache', 10, 1 );
function override_all_pages_with_remote_data_no_cache( $content ) {
    if ( defined( 'WP_ENV' ) && WP_ENV === 'local' && is_page() ) {
        $current_slug = get_post_field( 'post_name', get_queried_object_id() );
        error_log('Current page slug: ' . $current_slug);

        // wp-config.php で定義した REMOTE_API_URL を利用
        $api_url = REMOTE_API_URL . '/wp-json/wp/v2/pages?per_page=100';
        error_log('API URL: ' . $api_url);

        // wp-config.php の定数から Basic 認証情報を取得
        $username = REMOTE_API_USERNAME;
        $password = REMOTE_API_PASSWORD;
        $auth = base64_encode( $username . ':' . $password );
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
            ),
            'timeout' => 10,
        );
        
        $response = wp_remote_get( $api_url, $args );
        if ( is_wp_error( $response ) ) {
            error_log('WP Remote Error: ' . $response->get_error_message());
            return $content;
        }
        
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            error_log('Empty response body');
            return $content;
        }
        
        $remote_pages = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log('JSON decode error: ' . json_last_error_msg());
            return $content;
        }
        
        error_log('Remote pages count: ' . count($remote_pages));
        
        if ( ! empty( $remote_pages ) && is_array( $remote_pages ) ) {
            foreach ( $remote_pages as $page ) {
                if ( isset( $page['slug'] ) && $page['slug'] === $current_slug ) {
                    error_log('Matching page found for slug: ' . $current_slug);
                    if ( isset( $page['content']['rendered'] ) ) {
                        return $page['content']['rendered'];
                    }
                }
            }
            error_log('No matching page found for slug: ' . $current_slug);
        }
    }
    return $content;
}

?>