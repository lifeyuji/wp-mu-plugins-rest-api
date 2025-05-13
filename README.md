# Wordpress テーマ構築 作業効率化プラグイン

## このプラグインについて

コーポレートサイトなどの Wordpress テーマ構築において、複数のコーダーで作業する際に作業を効率化するプラグインです。  
Basic認証をかけたテストサイトの固定ページのエディタを https://localwp.com/ などで作成した自身のローカルに REST API を使ってテストサイトの内容を自動反映します。  
他の機能は順次アップします。

## 注意事項

配布用ではないため、動作保証はしません。

## 使い方

1. wp-contentフォルダに「mu-plugins」フォルダを作り、チェックアウトした「rest-api-proxy-cache.php」を設置
2. wp-config.phpにテストサイトの情報を追記

```
define( 'REMOTE_API_URL', 'https://dev.sample.com' );
define( 'REMOTE_API_USERNAME', 'user' );
define( 'REMOTE_API_PASSWORD', 'pass' );
define( 'WP_ENV', 'local' );
// キャッシュの有効期限（秒）。0 にするとキャッシュ無効化
define( 'RPX_CACHE_TTL', 0 );
```

