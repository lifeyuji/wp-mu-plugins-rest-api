<?php
/*
Plugin Name: REST API プロキシ＆FSE同期＋コアブロックCSS補完（ローカル開発用）
Description:
  ローカル環境のみ…
  1) 通常固定ページは the_content でリモート fetch → 上書き  
  2) FSEテンプレート／パターン／グローバルスタイルは REST API プロキシ＆キャッシュ  
     （編集画面はキャッシュバイパス）  
  3) 固定ページ表示時、Cover/Button/Gallery/Group ブロックCSSを手動enqueue
Version: 2.0
Author: lifeyuji
*/

if ( ! defined( 'ABSPATH' ) ) exit;

function rpx_log( $msg ) {
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[RPX] ' . $msg);
    }
}

add_filter( 'rest_pre_dispatch', 'rpx_proxy_and_cache', 10, 3 );
function rpx_proxy_and_cache( $result, $server, $request ) {
    if ( ! ( defined('WP_ENV') && WP_ENV === 'local' ) ) {
        return $result;
    }

    $route  = $request->get_route();
    $params = $request->get_query_params();

    // プロキシ対象ルートのベース一覧
    $bases = [
        '/wp/v2/templates',
        '/wp/v2/template-parts',
        '/wp/v2/global-styles',
        '/wp/v2/block-patterns',
        '/wp/v2/pattern-directory',
        '/wp/v2/wp_template',
        '/wp/v2/wp_template_part',
        '/wp/v2/block-renderer',    // ← 追加！
        '/wp/v2/categories',        // ← 追加！
        '/wp/v2/taxonomies',        // ← 追加！
        '/wp/v2/settings',
    ];

    foreach ( $bases as $base ) {
        if ( strpos( $route, $base ) === 0 ) {
            rpx_log( "Proxying route: $route" );

            // 編集モード（context=edit）はキャッシュしない
            $is_edit = ( isset( $params['context'] ) && $params['context'] === 'edit' );
            $ttl     = $is_edit ? 0 : ( defined('RPX_CACHE_TTL') ? RPX_CACHE_TTL : 300 );

            // キャッシュキー用にルートを正規化（// → /）
            $clean_route = preg_replace('#/+#','/',$route);
            $key_src     = REMOTE_API_URL . $clean_route . $request->get_method() . serialize( $params );
            $cache_key   = 'rpx_' . md5( $key_src );

            if ( $ttl > 0 && false !== ( $cached = get_transient( $cache_key ) ) ) {
                rpx_log( "Cache hit: $cache_key" );
                return rest_ensure_response( $cached );
            }

            // クエリ再構築
            $qs = $params ? '?' . http_build_query( $params ) : '';
            $remote = rtrim( REMOTE_API_URL, '/' ) . '/wp-json' . $clean_route . $qs;
            rpx_log( "Fetching remote URL: $remote" );

            // ヘッダーをフラットに
            $flat_headers = ['Authorization' => 'Basic ' . base64_encode(REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD)];
            foreach( $request->get_headers() as $name => $vals ) {
                if ( is_array($vals) ) {
                    $flat_headers[$name] = implode(',', $vals);
                }
            }

            $res = wp_remote_request( $remote, [
                'method'  => $request->get_method(),
                'headers' => $flat_headers,
                'body'    => $request->get_body(),
                'timeout' => 10,
            ]);

            if ( is_wp_error($res) ) {
                rpx_log( 'Proxy error: ' . $res->get_error_message() );
                return new WP_Error('rpx_error','プロキシに失敗しました');
            }

            $body = wp_remote_retrieve_body( $res );
            $data = json_decode( $body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                rpx_log( 'JSON decode error: ' . json_last_error_msg() );
            }

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
    if ( ! ( defined('WP_ENV') && WP_ENV==='local' && is_page() ) ) {
        return $content;
    }
    $slug = get_post_field('post_name', get_queried_object_id());
    if ( ! $slug ) return $content;

    $url = rtrim(REMOTE_API_URL,'/') . '/wp-json/wp/v2/pages?slug=' . rawurlencode($slug) . '&per_page=1';
    $res = wp_remote_get( $url, [
        'headers' => ['Authorization'=>'Basic ' . base64_encode(REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD)],
        'timeout' => 10,
    ]);
    if ( is_wp_error($res) ) return $content;
    $arr = json_decode(wp_remote_retrieve_body($res), true);
    return !empty($arr[0]['content']['rendered']) ? $arr[0]['content']['rendered'] : $content;
}

add_action( 'wp_enqueue_scripts', function(){
    if ( defined('WP_ENV') && WP_ENV==='local' && is_page() ) {
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-cover',      includes_url('blocks/cover/style.min.css'),      [], null);
        wp_enqueue_style('wp-block-button',     includes_url('blocks/button/style.min.css'),     [], null);
        wp_enqueue_style('wp-block-gallery',    includes_url('blocks/gallery/style.min.css'),    [], null);
        wp_enqueue_style('wp-block-group',      includes_url('blocks/group/style.min.css'),      [], null);
        wp_enqueue_style('wp-block-media-text', includes_url('blocks/media-text/style.min.css'), [], null);
    }
}, 0);

/**
 * フロント側のブロックテンプレートをリモートから丸ごと上書き
 */
add_filter( 'pre_get_block_template', 'rpx_override_block_template', 10, 3 );
function rpx_override_block_template( $template, $slug, $theme ) {
    if ( defined('WP_ENV') && WP_ENV === 'local' ) {
        // 正しいエンドポイント URL を組み立て
        $remote_url = rtrim( REMOTE_API_URL, '/' )
                    . "/wp-json/wp/v2/templates/{$theme}/{$slug}?context=view";
        rpx_log( "Fetching template: {$remote_url}" );
        $res = wp_remote_get( $remote_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD )
            ],
            'timeout' => 10,
        ] );
        if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            // 単一オブジェクトとして返ってくるケースをまずチェック
            if ( isset( $data['content']['rendered'] ) && $data['content']['rendered'] ) {
                return (object)[
                    'slug'    => $slug,
                    'theme'   => $theme,
                    'type'    => 'wp_template',
                    'content' => $data['content']['rendered'],
                ];
            }
            // 万が一配列で返ってきたらこちら
            if ( isset( $data[0]['content']['rendered'] ) && $data[0]['content']['rendered'] ) {
                return (object)[
                    'slug'    => $slug,
                    'theme'   => $theme,
                    'type'    => 'wp_template',
                    'content' => $data[0]['content']['rendered'],
                ];
            }
        }
    }
    return $template;
}

/**
 * フロント側のテンプレートパーツをリモートから丸ごと上書き
 */
add_filter( 'pre_get_block_template_part', 'rpx_override_block_template_part', 10, 4 );
function rpx_override_block_template_part( $template, $slug, $theme, $area ) {
    if ( defined('WP_ENV') && WP_ENV === 'local' ) {
        $remote_url = rtrim( REMOTE_API_URL, '/' )
                    . "/wp-json/wp/v2/template-parts/{$theme}/{$slug}?context=view";
        rpx_log( "Fetching template part: {$remote_url}" );
        $res = wp_remote_get( $remote_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD )
            ],
            'timeout' => 10,
        ] );
        if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( isset( $data['content']['rendered'] ) && $data['content']['rendered'] ) {
                return (object)[
                    'slug'    => $slug,
                    'theme'   => $theme,
                    'area'    => $area,
                    'type'    => 'wp_template_part',
                    'content' => $data['content']['rendered'],
                ];
            }
            if ( isset( $data[0]['content']['rendered'] ) && $data[0]['content']['rendered'] ) {
                return (object)[
                    'slug'    => $slug,
                    'theme'   => $theme,
                    'area'    => $area,
                    'type'    => 'wp_template_part',
                    'content' => $data[0]['content']['rendered'],
                ];
            }
        }
    }
    return $template;
}

/**
 * 4) テストサイトの FSE テンプレートをローカル DB に同期
 */
add_action( 'init', 'rpx_sync_remote_block_templates' );
function rpx_sync_remote_block_templates() {
    if ( ! ( defined('WP_ENV') && WP_ENV === 'local' ) ) {
        return;
    }
    // ５分以内に同期済みならスキップ
    if ( get_transient( 'rpx_synced_templates' ) ) {
        return;
    }

    $endpoints = [
        'wp_template'      => '/wp-json/wp/v2/wp_template?per_page=100&context=edit',
        'wp_template_part' => '/wp-json/wp/v2/wp_template_part?per_page=100&context=edit',
    ];

    foreach ( $endpoints as $post_type => $path ) {
        $url  = rtrim( REMOTE_API_URL, '/' ) . $path;
        $res  = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD),
            ],
            'timeout' => 10,
        ] );
        if ( is_wp_error( $res ) ) {
            rpx_log( 'Sync error (' . $post_type . '): ' . $res->get_error_message() );
            continue;
        }
        $items = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $items ) ) {
            rpx_log( 'Sync decode error (' . $post_type . ')' );
            continue;
        }

        foreach ( $items as $item ) {
            // ガード: 配列でない or slugキーがなければスキップ
            if ( ! is_array( $item ) || ! isset( $item['slug'] ) ) {
                rpx_log( 'Sync: 非配列 or slugキーなし のアイテムをスキップ' );
                continue;
            }

            $slug = $item['slug'];
            $raw  = isset( $item['content']['raw'] ) ? $item['content']['raw'] : '';
            $found = get_posts([
                'post_type'   => $post_type,
                'name'        => $slug,
                'post_status' => ['publish','draft'],
                'numberposts' => 1,
            ]);
            if ( $found ) {
                wp_update_post([
                    'ID'           => $found[0]->ID,
                    'post_content' => $raw,
                ]);
            } else {
                wp_insert_post([
                    'post_type'    => $post_type,
                    'post_name'    => $slug,
                    'post_title'   => $item['title']['rendered'] ?? $slug,
                    'post_content' => $raw,
                    'post_status'  => 'publish',
                ]);
            }
        }
    }

    set_transient( 'rpx_synced_templates', true, MINUTE_IN_SECONDS * 5 );
}



/**
 * フロントの全ページをリモートサイトの HTML で丸ごと置き換え
 */
/**
 * フロントの全ページをリモートサイトの HTML で丸ごと置き換え
 *   → CSS/JS の URL はローカル URL に置き換える
 */
add_action( 'template_redirect', 'rpx_proxy_full_page', 0 );
function rpx_proxy_full_page() {
    if (
        defined('WP_ENV') && WP_ENV==='local'
        && ! is_admin()
        && ! ( defined('REST_REQUEST') && REST_REQUEST )
    ) {
        $uri        = $_SERVER['REQUEST_URI'];
        $remote_url = rtrim( REMOTE_API_URL, '/' ) . $uri;
        rpx_log( "Proxy full page: {$remote_url}" );

        $response = wp_remote_get( $remote_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( REMOTE_API_USERNAME . ':' . REMOTE_API_PASSWORD ),
            ],
            'timeout' => 10,
        ] );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            // 1) 元の HTML を取ってくる
            $body = wp_remote_retrieve_body( $response );

            // 2) CSS／JS の URL をリモート→ローカルに書き換え
            $remote_base = rtrim( REMOTE_API_URL, '/' );
            $body = preg_replace_callback(
                // href="https://remotesite.com/wp-content/... .css" / src="... .js"
                '/(href|src)=([\'"])(?:' . preg_quote( $remote_base, '/' ) . ')(\/wp-(?:content|includes)\/[^\'"]+\.(?:css|js))\2/',
                function( $m ) {
                    // $m[3] が “/wp-content/…css” or “/wp-includes/…js”
                    return "{$m[1]}={$m[2]}" . home_url( $m[3] ) . "{$m[2]}";
                },
                $body
            );

            // 3) 出力して終了
            echo $body;
            exit;
        }
    }
}
