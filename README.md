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
define( 'WP_ENV', 'local' );
define( 'REMOTE_API_URL', 'https://dev.sample.com' );
define( 'REMOTE_API_USERNAME', 'user' );
define( 'REMOTE_API_PASSWORD', 'pass' );
```

3. テストサイトで「https://dev.sample.com/sample-page」が存在する場合、
ローカル側で同じスラッグのページが存在していれば the_title() と the_content() がテストサイトのものを取得してローカルで表示してくれます。
これによりローカル側での編集が不要になり、  
・管理画面の編集 → テストサイト  
・テーマ内のスタイル記述 → ローカルサイト  
という分別ができます。

## デバッグしたい時

wp-config.phpに下記を記載すると wp-content/debug.log にログが出ます。

```
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false ); 
```

