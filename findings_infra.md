# findings_infra.md — インフラ調査係 専用（読み取り専用担当）

> ルール: ここにだけ書く。ソース編集・ベンチ・restart・DB変更は禁止。
> 信号機(MEMO.md)が RUNNING の間は重い処理を控える。
> 観点: nginx設定（静的配信・gzip・keepalive）、PHP-FPM worker数、MySQL設定（buffer_pool等）、CPU/メモリ/IO、ボトルネックの層判定。

## 環境サマリ（2026-06-23 計測, 信号機IDLE時）
- CPU 2コア / RAM 3.7GiB / Swap 0（スワップ無し → メモリ過割当は即OOM注意）
- nginx(www-data) → php-fpm(TCP 127.0.0.1:9000, pm=dynamic max_children=5) → MySQL 8.0.45 / memcached(session)
- nginx実体: /etc/nginx/sites-available/isucon.conf（リポジトリ内 etc/nginx は未使用）
- **posts テーブル data_length=1204.5MB（画像BLOB imgdata が大半）／ comments 10.5MB / users 0.2MB**
- innodb_buffer_pool_size = **128MB（デフォルト, テーブル1.2GBに全く足りない）**
- MySQL RSS ≒ 500MB（%MEM 13.3）。アイドル時CPUほぼ未使用。
- slow_query_log = OFF（※DB係領域だが計測のため要相談でON推奨）

---

## [12:10] 画像BLOBのnginx直配信化（最優先・最大効果）
- (1)現象: `/image/{id}.{ext}` ルート（index.php:397）が毎回 MySQL から imgdata(BLOB) を SELECT して PHP で出力。posts は 1.2GB あり buffer_pool 128MB に収まらず、画像ごとに MySQL I/O + PHP 経由のメモリコピーが発生。1ページに画像多数 → ここが全層の最大負荷源と推定。
- (2)原因仮説: 画像が DB に格納され静的配信できていない。nginx は location / の try_files で public 配下の実ファイルは直接返すが、画像は public に存在しない（public/img は ajax-loader.gif のみ）ため全て PHP+DB に落ちる。
- (3)推奨アクション（実装係向け・app係と連携）:
  - 投稿時(index.php:381 付近)に imgdata を `public/image/{id}.{ext}` へファイル書き出し。既存分は一度DBからダンプ。
  - nginx で `location ~ ^/image/ { root .../public; try_files $uri @app; }` ＋ `location @app { fastcgi_pass ...; }` とし、実ファイルがあれば nginx 直配信、無ければ PHP フォールバック（初回のみPHPが書き出す方式でも可）。
  - 併せて画像へ `expires 1y; add_header Cache-Control "public, immutable";`。
- (4)根拠: posts 1204.5MB ≫ buffer_pool 128MB。画像配信はリクエスト数が最も多く、PHP/MySQL を介す限り php-fpm worker(5) と MySQL I/O を専有する。nginx 直配信なら PHP/DB を一切経由しない。

## [12:10] php-fpm worker 数が過少（高効果・低リスク）
- (1)現象: pm=dynamic, **max_children=5**, start_servers=2, max_spare=3。現在稼働 worker は3。
- (2)原因仮説: 画像配信が DB I/O 待ちでブロックする間、worker がすぐ枯渇しリクエストがキューイング。CPU余力があっても並列度が頭打ち。
- (3)推奨アクション: 画像をnginx直配信化した後はCPUバウンド寄りになるので `pm=static` + `pm.max_children=10〜20` 程度から調整（2コアでもI/O待ちがあるため物理コア数より多めが有効）。1プロセス実メモリ実測(現状 RSS≒20MB/proc と小さい)を見て上限決定。スワップ無しなので max_children × プロセスRSS が空きRAMを超えないこと。
- (4)根拠: max_children=5 は ISUCON 系の典型ボトルネック。並列負荷で worker 飽和 → 502/遅延。

## [12:10] innodb_buffer_pool_size が 128MB（中〜高効果）
- (1)現象: buffer_pool 128MB に対し posts 1.2GB。
- (2)原因仮説: 画像BLOBがbuffer_poolを圧迫し、posts一覧やcomments等のホットデータまでディスク読みになりうる。
- (3)推奨アクション:
  - **画像をDB外へ出す場合**: 実ホットデータ(comments+users+postsのメタ列)は数十MB級になるので buffer_pool は 256〜512MB で十分。
  - **画像をDBに残す場合**: 1GB 程度へ（RAM 3.7GB, MySQL以外で php-fpm/nginx/memcached が使う分とスワップ無しを考慮し最大でも1〜1.2GB）。
  - 設定先: /etc/mysql/mysql.conf.d/ に `innodb_buffer_pool_size` 追記（要restartは実装係）。
- (4)根拠: data_length 計測値（posts 1204.5MB / comments 10.5MB / users 0.2MB）。

## [12:10] nginx 静的配信・接続まわりの最適化（中効果・低リスク）
- (1)現象: nginx.conf に keepalive_timeout 未設定、gzip は `gzip on` のみで **gzip_types 未設定（= text/html しか圧縮されない）**、static への expires/Cache-Control 無し、open_file_cache 無し。
- (2)原因仮説: css/js/画像で再取得が走り帯域と接続を浪費。gzip がテキスト資産に効いていない。
- (3)推奨アクション:
  - `gzip_types text/css application/javascript application/json image/svg+xml;`（画像バイナリは対象外）
  - css/js/favicon と画像に `expires 1d〜1y; add_header Cache-Control public;`
  - `open_file_cache max=10000 inactive=60s;` でファイルディスクリプタキャッシュ
  - http に `keepalive_timeout 65;`、クライアントとの keepalive 有効化（ベンチは keep-alive を張る）
- (4)根拠: static 資産は style.css/main.js/timeago.min.js 等小さいが取得頻度高。設定欠落で毎回フル転送・無圧縮。

## [12:10] php-fpm を unix socket 化＋fastcgi バッファ（小〜中効果）
- (1)現象: fastcgi_pass が TCP 127.0.0.1:9000。fastcgi_buffering/keepalive 未設定。
- (2)原因仮説: ローカル通信なら unix socket の方がオーバーヘッド低。画像など大きめレスポンスで fastcgi_buffers 不足だと一時ファイル化。
- (3)推奨アクション: pool を `listen = /run/php/php8.3-fpm.sock` 化し nginx 側 `fastcgi_pass unix:/run/php/php8.3-fpm.sock;`。`fastcgi_buffers 16 16k; fastcgi_buffer_size 32k;`。優先度は上記より低い。
- (4)根拠: 効果は限定的だが低リスク。画像をnginx直配信化できれば本項の重要度は下がる。

## 層別ボトルネック判定（現時点の仮説）
- 最大ボトルネックは **画像BLOB配信（MySQL I/O + PHP）**。次いで **php-fpm worker枯渇**。
- 実測はベンチ中の `top`/`iostat`/`mysqladmin ext` が必要 → 信号機RUNNING中は控える。実装係がベンチ回す前後で `ss -s`(接続)、`ps -C php-fpm8.3`(worker飽和)を観測希望。
