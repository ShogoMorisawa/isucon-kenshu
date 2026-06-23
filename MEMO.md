# MEMO.md — 実装ログ & 司令塔（実装係が管理）

## 🚦 ベンチ状態: IDLE
<!-- 実装係がベンチ前に RUNNING、後に IDLE へ。調査係は RUNNING 中は重い処理を控える -->

## 📊 スコア履歴
| 時刻 | スコア | 変更内容 |
|------|--------|----------|
| 2026-06-23 11:24 | **532** (pass, success672/fail10) | PHP実装へ切替後の初期計測 |
| 2026-06-23 11:41 | **0** (pass, success400/fail56) | ①画像BLOB→ファイル化+nginx直配信。画像は正しく静的配信(Last-Modified/ETag確認)できているが、GET / が idle で 1.52s かかりタイムアウト多発。画像高速化で並列が上がり未indexのN+1(comments10万行フルスキャン)が露呈し崩壊。→revertせず②index追加で解消を狙う |
| 2026-06-23 11:48 | **27588** (pass, success26230/**fail0**) | ②index4本追加(comments idx_post_created/idx_user_id, posts idx_created_at/idx_user_created)。GET / が 1.52s→0.04s(38x)。①+②合算で 532→27588(52x)。①は単体では悪化だが②と合わせ必須の土台と確定 |
| 2026-06-23 11:53 | **29574** (pass, success28129/fail0) | ③php-fpm pm=static/max_children=16, MySQL innodb_buffer_pool_size=512M。27588→29574(+7%)。採用 |
| 2026-06-23 12:01 | **40135** (pass, success37072/fail0) | ④make_postsのユーザ取得をリクエスト内メモ化、/ と /posts を `ORDER BY created_at DESC LIMIT 100`(idx_created_at逆順読み・filesort無)で母数9000→100に削減、/posts/{id}の`SELECT *`をカラム限定(imgdata除外)。29574→40135(+36%)。採用。※JOIN+del_flg版はfilesort誘発で不採用 |
| 2026-06-23 12:05 | 30304 (**fail167**, 500/静的不一致) | ⑤nginx最適化 第1版。`open_file_cache max=10000` に対し fd上限が低く `Too many open files`→500・画像破損。→nginx設定そのままで fd上限引き上げを追加投入 |
| 2026-06-23 12:08 | **81758** (pass, success78939/**fail0**) | ⑤+fd上限修正。`worker_rlimit_nofile 65535`, `worker_connections 8192`, `multi_accept on`。gzip_types/keepalive65/open_file_cache/静的にexpires1d+Cache-Control public。40135→81758(+104%)。採用 |
| 2026-06-23 12:2x | **79041** (pass, success76121/fail0) | 【保全1】MySQL binlog無効化(`disable-log-bin`)+`RESET MASTER`で1.8GB解放(96%→84%)、`innodb_flush_log_at_trx_commit=2`。スコアは81758比-3%だが変動幅内。ディスク危機回避が主目的。採用 |
| 2026-06-23 12:30 | 65377 (pass, fail0/2回再現) | 【保全2前半】imgdataのDB保存停止+UPDATE imgdata=''+OPTIMIZE TABLE で posts 1.4GB→7MB(disk 84%→75%)。但しテーブル縮小でオプティマイザが idx_created_at を捨て全件scan+filesort化→79041→65377に悪化(2回再現,変動でない) |
| 2026-06-23 12:34 | **81957** (pass, success78655/**fail0**) | 【保全2後半】/ と /posts に `FORCE INDEX (idx_created_at)` を付与しBackward index scan強制。65377→81957で完全回復(81758比+0.2%)。保全2は最終的にスコア維持+ディスク3GB解放。採用 |
| 2026-06-23 12:38 | **96704** (pass, success91961/**fail0**) | 【性能3】digest()を `hash('sha512',$src)` にネイティブ化（openssl外部プロセス起動を排除。バイト一致確認済）。81957→96704(+18%)。login/register多シナリオで大きく寄与。採用 |

> 初期ベンチの fail10 は GET /logout, GET /posts, POST /login, POST /register のタイムアウト。
> 原因候補: php-fpm `pm.max_children=5` が小さく並列不足の可能性（インフラ調査係に確認依頼）。

## 🏃 ベンチ実行コマンド（実装係用・確定）
```
cd /home/isucon/private_isu/benchmarker
./bin/benchmarker -t http://localhost -u ./userdata
```
- 出力は JSON 1行（pass/score/success/fail/messages）。score がスコア。
- ビルドし直す場合: `cd /home/isucon/private_isu/benchmarker && go build -o bin/benchmarker`（または `make`）。
- ベンチ前に上の信号機を RUNNING、後に IDLE へ戻すこと。

## ✅ 確定した変更（適用済み）
- 2026-06-23 12:38 【性能3】digest() ネイティブhash化。score 81957→96704(+18%)。
  - `digest($src)` を `return hash('sha512', $src);` に。openssl外部プロセス起動/escapeshellarg/sed が不要に。
  - 既存passhash互換（shellのopenssl出力とhash()がバイト一致を実測確認）。calculate_salt/calculate_passhash はそのまま。
- 2026-06-23 12:34 【保全2】imgdataのDB保存停止＋領域回収（index.php + DB）。score維持(81957)・disk 3.8GB空き(75%)。
  - `POST /`: imgdataを `''` でINSERT（NOT NULL回避）、tmpは1回だけ読む、ファイル書出しは必須化(@外し)。
  - `GET /image/{id}.{ext}`: DB依存を撤廃。public/image のファイルを is_file→file_get_contents で返す、無ければ404（nginxが基本直配信、ここは保険）。
  - DB: `UPDATE posts SET imgdata=''` + `OPTIMIZE TABLE posts`（1.4GB→7MB）。実施前に export_images.php で全画像ファイル化を再確認済（DB blob消失で画像欠落しないこと）。
  - ⚠️落とし穴: OPTIMIZEでテーブルが極小化→オプティマイザが idx_created_at を捨て全件scan+filesortに退行。→ / と /posts に `FORCE INDEX (idx_created_at)` を付与して解決（これが無いと-17%）。
- 2026-06-23 12:2x 【保全1】MySQL binlog無効化＋fsync緩和。disk 681MB→2.5GB解放(96%→84%)。
  - `mysqld.cnf` に `disable-log-bin` / `innodb_flush_log_at_trx_commit = 2`。bak: mysqld.cnf.bak2
  - 既存binlog(21ファイル1.8GB)は `RESET MASTER` で purge 済（restart前に実行）。
  - 要 `systemctl restart mysql`。レプリ不要なベンチ用途のため安全。
- 2026-06-23 12:08 ⑤nginx静的最適化（/etc/nginx/nginx.conf + sites-available/isucon.conf）。score 40135→81758。
  - nginx.conf: `worker_rlimit_nofile 65535`, events `worker_connections 8192; multi_accept on;`,
    `keepalive_timeout 65`, `open_file_cache max=10000 inactive=60s`(+valid/min_uses/errors),
    `gzip_vary/comp_level 5/min_length 1024/gzip_types`(テキスト系+svg。画像は非圧縮)。bak: nginx.conf.bak
  - isucon.conf: 静的拡張子(css|js|jpg|jpeg|png|gif|ico|svg) location に `expires 1d; add_header Cache-Control "public"; access_log off;` + try_files フォールバック維持。bak: isucon.conf.bak
  - 教訓: open_file_cache を大きくする時は worker_rlimit_nofile を必ず上げる（fd枯渇で500/静的破損）。要 `systemctl restart nginx`（reload不可）。
- 2026-06-23 12:01 ④アプリのN+1/SELECT最適化（index.php）。score 29574→40135。
  - make_posts: ユーザ取得を `$get_user` クロージャでリクエスト内メモ化（投稿/コメントで重複するuser SELECTを排除）。
  - `/`・`/posts`: `SELECT ... FROM posts ORDER BY created_at DESC LIMIT 100`（idx_created_at の Backward index scan, filesort無）。del_flg除外は従来通りmake_posts内。母数 ~9000→100。
  - `/posts/{id}`: `SELECT *`→必要カラム限定（imgdata BLOB を引かない）。
  - 注意: JOIN+del_flg=0+LIMIT版は `Using temporary; Using filesort` を誘発したため不採用。posts単独+LIMITが最速。
- 2026-06-23 11:53 ③インフラ調整。score 27588→29574。
  - `/etc/mysql/mysql.conf.d/mysqld.cnf` に `innodb_buffer_pool_size = 512M`（imgdataはDB外なのでホットセットに十分）。bak: mysqld.cnf.bak
  - `/etc/php/8.3/fpm/pool.d/www.conf` を `pm=static` / `pm.max_children=16`。bak: www.conf.bak
  - 要 `systemctl restart mysql php8.3-fpm`。メモリ available ≈1GB 維持（swap無のため上げ過ぎ注意）。
- 2026-06-23 11:48 ②インデックス4本（DDLは findings_db.md ④まとめ通り）。score 0→27588。
  - `comments ADD INDEX idx_post_created (post_id, created_at)` / `comments ADD INDEX idx_user_id (user_id)`
  - `posts ADD INDEX idx_created_at (created_at)` / `posts ADD INDEX idx_user_created (user_id, created_at)`
  - revert方法: `ALTER TABLE ... DROP INDEX <名前>`。
- 2026-06-23 11:41 ①画像BLOB→ファイル化＋nginx直配信（土台。単体では悪化したが②と必須セット）。
  - 既存画像を `webapp/php/export_images.php` で `public/image/{id}.{ext}` へ全件(10004,1194MB)エクスポート済。
  - `POST /`(投稿) で挿入後にファイル書き出し追加。`GET /image/{id}.{ext}` は `SELECT mime,imgdata` に限定＋ファイル無ければ書き出すフォールバック化。
  - nginx は既存 `location / { try_files $uri /index.php }` で実ファイルを直配信（専用locationは⑤で追加予定）。
- 2026-06-23 11:23 Ruby→PHP 切替完了。
  - nginx `/etc/nginx/sites-available/isucon.conf` を proxy(8080) から FastCGI(127.0.0.1:9000) へ変更。
    root=`/home/isucon/private_isu/webapp/public/`, `try_files $uri /index.php`,
    `SCRIPT_FILENAME=/home/isucon/private_isu/webapp/php/index.php`（PHPは public 外のため固定指定）。
  - `isu-ruby.service` を stop & disable。`php8.3-fpm.service` を enable --now。
  - 動作確認: / 200, /login 200, /css/style.css 200, /image/1.jpg 200(image/jpeg)。

## 🔧 いま作業中（実装係が宣言）
- なし（①〜⑤完了。532→81758, fail0）
- 2026-06-23 12:1x git管理開始（初回コミット完了、スコア81758）。.gitignore で画像生成物/vendor/node_modules/.venv/userdata/bin/*.log/*.bak 等を除外し .git=1.2M。以降は各ステップのベンチ後に `git commit`（メッセージに施策名+スコア）運用。

## ⏭️ 次の候補（未着手・効果見込み順）
- digest() の openssl 外部プロセス起動を PHP の hash('sha512') ネイティブ化（login多シナリオ／findings_app ⑤）。出力フォーマット互換に注意。
- POST / で imgdata を DB にも保存し続けている → DB INSERT から imgdata を外す（ファイルのみ）検討。ただし /image フォールバックがDB依存なので要設計。
- comment_count の per-post COUNT を GROUP BY 一括 or postsに集計列。
- php-fpm max_children のさらなる調整（ベンチ中 top で CPU/worker飽和を観測してから）。
- ⚠️ ディスク残 ~1GB（94%）。ベンチ毎に画像ファイル+DB imgdata が増える。逼迫したら public/image の id>10000 や DBの不要blobを整理。

## 📥 調査係からの提案 採否
- findings_db.md / findings_app.md / findings_infra.md を参照し、採用したものをここに記録

## 📌 環境メモ（判明した事実を実装係が追記）
- 稼働中スタック: nginx → php8.3-fpm(127.0.0.1:9000) → MySQL(3306) + memcached(11211, セッション用)。
- PHPアプリ: `/home/isucon/private_isu/webapp/php/index.php`（Slim, 1枚構成）。views/ vendor/ あり。
- 静的: `/home/isucon/private_isu/webapp/public/`（css/ js/ img/ favicon.ico）。画像はDB BLOBから `/image/{id}.{ext}` で配信。
- php-fpm pool `/etc/php/8.3/fpm/pool.d/www.conf`: user=isucon, listen=127.0.0.1:9000, pm=dynamic, **max_children=5**(小さい), clear_env=no, EnvironmentFile=env.sh。
- DB接続: env.sh の ISUCONP_DB_USER=isuconp / PASSWORD=isuconp / NAME=isuconp。host=localhost, port=3306。
- DB件数(初期): posts=10004。テーブル: users, posts, comments。
- 他言語サービス(go/node/python)は disabled。Rubyは disabled 済み。
