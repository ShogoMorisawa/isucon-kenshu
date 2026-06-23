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
| 2026-06-23 12:4x | **160239** (pass, success152052/**fail0**) | 【性能4】make_postsのN+1一括化。per-post COUNT+comments(40往復)→comments 1クエリ(IN, DESC)で取得し件数もPHPで算出、user取得もpreload_users(IN)で一括。96704→160239(+66%)。レンダリング件数/コメント数をDBと突合し一致確認。採用 |
| 2026-06-23 12:5x | **168597** (pass, success160084/**fail0**) | 【性能5】PDO `ATTR_PERSISTENT=>true`。接続確立コスト削減。160239→168597(+5%)。max_connections=151に対し常駐~16で安全。採用 |
| 2026-06-23 13:0x | **169984** (pass, success161353/**fail0**) | 【仕上げ6】csrf_token警告抑制。views(post/index/banned).phpの `$_SESSION['csrf_token']`→`?? ''`。168597→169984(+0.8%,変動内)だがerror.log肥大(32MB)を停止。採用 |
| 2026-06-23 13:1x | 166-170k (A/B複数回) | 【仕上げ7】opcache本番化をA/B。JIT(tracing)+validate_timestamps=0=166.0/166.8k、vt=0のみ=169.3k、default=165.9/170.0k。**全て±2.5%の変動幅内で有意差なし**。JITは僅かに不利寄り。footgun(vt=0はコード変更後restart必須)回避のため**defaultへ revert**。スコア確定値は ~170k(169984)とみなす |
| 2026-06-23 13:15 | **170284 / 169319** (pass, fail0/2回) | 【性能1】get_session_user のDB SELECT撤廃→session(memcached)のid/account_name/authorityを返す。score変動幅内(baseline169984比±0)だが**mysqld CPU(39%)を全認証リクエストから1往復削減**＋step4の土台。零リスク・全員一致で採用 |
| 2026-06-23 13:18 | 166597 / 167404 (pass, fail0/2回) | 【性能2】画像 `Cache-Control: public, max-age=31536000, immutable`、Cache-Control重複(public/max-age 2本)を解消。score変動内(既存1d max-ageで既にテスト窓全体キャッシュ済のため伸びず)。重複ヘッダ修正＋immutableは意味的に正しく零リスクのため採用 |
| 2026-06-23 13:25 | **181596 / 182627** (pass, fail0/2回) | 【性能3a】server に `gzip off`（HTML非圧縮）。loopbackで圧縮の帯域メリット無し、nginx圧縮CPU+bench解凍CPU(計測bench60%)の二重浪費を排除。~170k→~182k(**+7%, 変動超で確実**)。採用 |
| 2026-06-23 13:35 | 175733 / 178834 (fail0) | 【性能3b・不採用】php-fpm max_children 16→8。~182k→~177kで一貫して低下（I/O待ちがあり16の方がコア稼働率高い。context-switch懸念は顕在化せず）。**16へ revert**。4はさらに悪化見込みで未試行 |
| 2026-06-23 13:4x | 177621 / 177387 (fail0) | 【性能4 stage1・不採用】GET /posts/{id} を memcached データ構造キャッシュ(post:{id}, コメント時delete)。**fail0で整合機構は実証**したが ~182k→~177k(-2.7%)。当該endpointは元々indexクエリで安価＋memcached往復+unserializeが相殺。revert(機構はstage2へ流用) |
| 2026-06-23 13:5x | **192630 / 191257** (pass, **fail0**/2回) | 【性能4 stage2】GET / フィード($posts)を memcached キャッシュ。`index:v{feed_version}` キー。POST / と POST /comment で feed_version を increment し即時無効化。me/csrf/flash はキャッシュ外合成。/initialize で flush。~182k→~192k(**+5.5%, 変動超**)。**投稿/コメント即時反映 fail0 確認**。採用 |
| 2026-06-23 14:0x | **194567 / 196684** (pass, fail0/2回) | 【性能6】index母数 LIMIT 100→40（/ と /posts）。~192k→~195.6k(+1.9%,小だが両走とも上回る)。cache-miss再構築と/postsの行処理が軽量化。無リスクで採用 |
| 2026-06-23 14:1x | 195555 / 190841 (fail0) | 【追加検証・不採用】匿名GET / のフルHTMLキャッシュ。~195.6k比 変動内で伸びず→GET /は大半が認証済トラフィックでanon cacheがヒットせず。revert。CPU再実測でphp-fpm80%/mysqld29%を確認(下記)。**性能5(comment_count非正規化=DB施策)はmysqld非律速のためskip** |

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
- 2026-06-23 15:2x 【第4R-3】遅延セッション（session固定費削減, index.php）。大会ベンチ待ち。※今後ローカルベンチは省略（大会がfail/score両方判定）。
  - `session_start()` を「cookieがある時のみ」に変更。匿名のcookie無しリクエストでは memcached 読み書き往復・Set-Cookie を省略。
  - 書込経路 `POST /login`・`POST /register` 冒頭で `ensure_session()`（失敗時flashをsession保持するため）。成功時の $_SESSION 設定も同様。
  - Flash サービスをセッション安全化: session未開始時は使い捨て配列をstorageに（Slim Flashの「Session not found」回避）。
  - get_session_user は既にDB非依存(session値返却)。第4R-1/2: 断片HTMLキャッシュ(大会292,146→304,358), comment_count非正規化(304,358→317,479) 採用確定。
- 2026-06-23 15:0x 【第4R-2】comment_count 非正規化＋フィード構造の刷新（DB + index.php）。大会 304,358→317,479 で**採用確定**。
  - 大会: 第4R-1 断片HTMLキャッシュは **292,146→304,358(+4%)** で採用確定。
  - DB: `posts.comment_count INT NOT NULL DEFAULT 0` 追加＋バックフィル。`db_initialize` で再集計（DELETE後に reset→GROUP BY 集計）。
  - `POST /comment`: INSERT後 `UPDATE posts SET comment_count=comment_count+1`（feed_version bump を置換）。`POST /`: bump不要に。
  - **相乗効果**: GET / /posts /@user は posts を comment_count 込みで取得し `build_list_html()` へ。断片キー `pf:{id}:c{count}` を**コメントfetch前**に算出→getMulti。**断片ヒット時はコメントクエリを一切発行しない**。ミスした投稿だけコメント(最新3)をまとめて取得。
  - **feed_version＋index:vデータキャッシュを撤廃**: 新規投稿はcreated_at順クエリに即出現、新規コメントはcomment_count増分→断片キー変化で自動再生成＝即時可視（version不要）。
  - make_posts は /posts/{id}(all_comments) 専用に縮小。posts.php/user.php は `$post_list_html` を echo。/initialize の cache flush は継続。
- 2026-06-23 14:3x 【第4R-1】投稿リストの断片HTMLキャッシュ（index.php + views/post.php,posts.php）。大会 292,146→304,358(+4%) で**採用確定**。
  - ⚠️前提変更: ローカル計測はベンチ同居でCPU食合いのため数値は信用しない。fail0確認のみに使用。大会ベンチ(別マシン)が真値（直近292,146）。
  - `render_post_list($posts)`: 各投稿のpost.php出力を `pf:{id}:c{comment_count}` キーで memcached キャッシュ（getMulti/setMulti, TTL600）。
  - **自動invalidate**: コメント追加→feed_version bump→feedデータ再構築→comment_count変化→断片キーが変わり自動再生成（explicit delete不要）。
  - csrf_token はユーザ固有のため断片内をプレースホルダ`@@CSRFTOKEN@@`にして描画し、最後に `str_replace` で実トークン合成。→1断片を匿名/全ログインユーザで共有。
  - post.php は `$csrf_token ?? ($_SESSION...)` 参照に変更。/posts/{id} 詳細renderにも csrf_token を渡す。
  - 理論的効果: 認証済GET /含む全リスト描画で post.php テンプレ実行を「投稿×コメント状態」毎に1回へ削減。大会環境(ベンチ分のCPUが空く)でphp-fpm(律速80%)の描画CPUを削減する狙い。
- 2026-06-23 14:0x 【性能6】index母数 LIMIT 100→40（/ と /posts, index.php）。score ~192k→~195.6k。削除ユーザ~2%なので40で20件確実。
- 2026-06-23 13:5x 【性能4】GET / フィードキャッシュ（index.php, memcached）。score ~182k→~192k(+5.5%, fail0)。
  - `feed_cache()`=アプリ用Memcached永続接続(127.0.0.1:11211, persistent_id=feedpool)。`feed_version()`/`bump_feed_version()`=increment整数。
  - GET /: `$posts` を `index:v{feed_version}` でキャッシュ(TTL10s)。me/csrf/flashはキャッシュ外でテンプレ合成しユーザ別表示維持。
  - 無効化: `POST /`(新規投稿) と `POST /comment`(コメント) で `bump_feed_version()`。→ 次GET /は新keyで再構築し**投稿/コメント即時反映**(ベンチfail0)。
  - `/initialize` で `feed_cache()->flush()`（DBリセットと同時に古いキャッシュ全消去。最初の呼出で保持すべきセッション無し）。
  - ※GET /posts/{id} のデータ構造キャッシュは stage1 で -2.7% のため不採用（元々安価なクエリ）。
  - ※GET /posts キャッシュも追加検証(~188k<192k)→低頻度＋max_created_at別でヒット率低く効果無く revert。GET / のみ採用。
- 2026-06-23 13:25 【性能3a】HTML gzip無効化（isucon.conf, server に `gzip off;`）。score ~170k→~182k(+7%)。
  - loopbackベンチでは圧縮の帯域利益が無く、nginx圧縮+benchmarker解凍が同2コアを二重に食う純損失だった（計測でbench=60%CPU）。
  - 注: `gzip off` を location ~ \.php に置くと try_files内部redirectで効かず、server直下に置く必要があった。css/js/svgも非圧縮になるが小容量・低頻度で影響軽微。nginx.conf側の `gzip on`/gzip_types は残置（このserverでoff上書き）。
- 2026-06-23 13:18 【性能2】画像Cache-Control最適化（isucon.conf）。score変動内・ヘッダ正規化。
  - location分割: `\.(jpg|jpeg|png|gif)$`→`add_header Cache-Control "public, max-age=31536000, immutable"`（expires撤去で重複解消）。
  - `\.(css|js|ico|svg)$`→`public, max-age=86400`。bak: isucon.conf.bak2
- 2026-06-23 13:15 【性能1】get_session_user のセッションキャッシュ化（index.php）。score 変動内・mysqld往復削減。
  - `POST /login`・`POST /register` で `$_SESSION['user']` に id/account_name/authority を格納。
  - `get_session_user()` はDBを引かず `$_SESSION['user']` を返すだけ。全認証リクエストから `SELECT users` 1往復を除去。
  - 注: del_flg/authority のban即時反映はしないが、現状get_session_userもdel_flg未チェックで挙動同等。banは/admin経路のみ。
- 2026-06-23 13:1x 【仕上げ7・不採用】opcache JIT/validate_timestamps=0 を A/B 検証→**default へ revert**。
  - 結果は全て±2.5%変動幅内で有意差なし。JITはむしろ僅かに不利。`/etc/php/8.3/fpm/conf.d/99-opcache-tuning.ini` は削除済（default: opcache On / validate_timestamps On / JIT off）。
  - 📐 教訓: **このベンチは±2.5%の変動がある**。1〜2回の差(数千点)では効果と判定しない。明確な効果は同方向で複数回・変動幅超のときのみ採用。
- 2026-06-23 13:0x 【仕上げ6】csrf_token警告抑制（views 3ファイル）。error.log肥大停止。score 168597→169984。
  - post.php/index.php/banned.php の `escape_html($_SESSION['csrf_token'])`→`... ?? ''`。匿名ユーザでも警告を出さない。ログイン時の出力は不変。
- 2026-06-23 12:5x 【性能5】PDO永続接続。score 160239→168597(+5%)。
  - `new PDO(...)` 第4引数に `[PDO::ATTR_PERSISTENT => true]`。fpm worker16で常駐接続最大~16（max_connections=151内）。
- 2026-06-23 12:4x 【性能4】make_posts のN+1一括化（index.php）。score 96704→160239(+66%)。
  - per-post `COUNT(*)` と per-post コメントSELECT（計~40往復/ページ）を撤廃。
  - 表示post確定後、`SELECT ... FROM comments WHERE post_id IN (...) ORDER BY created_at DESC` を1クエリで取得。
    件数は結果から PHP集計、表示は先頭3件(all_commentsなら全件)→array_reverse。idx_post_created が効く。
  - user取得は `preload_users()`（`WHERE id IN (...)`）でpost-user/コメントuserを一括プリロード＋get_userメモ化。
  - 正当性: 20件表示・コメント件数をDBのCOUNTと突合し一致を確認済。
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
- なし。**第3ラウンド完了。現在 ~195,000（fail0）**。git管理運用中(各ステップcommit)。
- ✅ ディスク危機 恒久対策済: /initialize で id>10000 画像を自動掃除（ベンチ反復で再逼迫しない）。現在74%。
- 第3R確定の伸び: HTML gzip無効化(+7%) / GET /フィードキャッシュ(+5.5%) / LIMIT40(+2%)。session・画像immutableは零リスクで維持(score変動内)。

## 🧱 現在の律速（第3R実測の結論）
- CPU 2コア完全飽和。**php-fpm=80%が壁**、mysqld=29%(非律速)、bench自身=67%。
- → これ以上はDB施策ではなく**PHP実行量(framework/session/テンプレ)の削減**でしか伸びない。
- 検証で分かったこと: GET /は**認証済トラフィックが主**（匿名フルHTMLキャッシュが効かなかった）。
  session_start(memcached往復)+Slim/PSR-7+feed_cache get/unserialize が認証済GET /の固定費。

## ⏭️ 次の候補（400kへ・効果見込み順 / いずれもphp-fpm削減狙い）
- 認証済GET /の固定費削減: ①session_startの最適化（memcached往復は必須だが、cache getと統合 or 1回化）②Slimを介さない薄いルートに GET / を切り出し（大工事）。
- GET / の posts.php 断片HTMLをキャッシュし、ヘッダ(me)だけ動的合成（テンプレ実行の大半を回避。認証済にも効く）。匿名フルHTMLと違い認証済にもヒット。
- bench自身が67%CPU→相乗りで上限を押し下げている。これは実機分離環境なら消える要素（同一ホスト計測の限界）。
- ⚠️ ベンチ変動幅±2.5%。効果判定は同方向・複数回・変動超のときのみ。
- 【skip済】性能5 comment_count非正規化: mysqld非律速のため効果薄と判断しskip。やるなら断片キャッシュの後。

## 📥 調査係からの提案 採否
- findings_db.md / findings_app.md / findings_infra.md を参照し、採用したものをここに記録

## 🔬 CPU再実測（性能4/6適用後, 2026-06-23 13:52）
- 依然CPU飽和(idle~0.2%)。プロセス別: **php-fpm=80.6%(↑69→81, 最大の壁)** / benchmarker=67.3% / **mysqld=28.9%(↓39→29, キャッシュ/sessionでDB負荷減)** / nginx=19.8%。
- **重要転換: 律速は完全に php-fpm(PHP実行=フレームワーク+テンプレ描画)。mysqld はもう壁でない。**
  → 【性能5 comment_count非正規化(=DB施策)は的外れになった】mysqld 29%を削っても頭打ちは破れない。skip。
  → 次の的は「PHPの実行量削減」: GET / の**HTMLレンダリング自体をキャッシュ**(テンプレ実行回避)＝匿名フルページキャッシュ等。

## 🔬 CPU実測（第3R step0, 2026-06-23 13:09, ベンチRUNNING中 mpstat/pidstat）
- **2コアCPUは完全飽和: %idle ≈ 0.06%**（usr~76% + sys~21%）。CPUが唯一の壁。CPU節約=即スループット増。
- プロセス別CPU（1コア=100%換算, 合計上限200%）:
  - **php-fpm8.3 = 69%（サーバ側で最大消費）** / mysqld = 39% / nginx = 23%
  - **benchmarker = 60%（同一2コアを専有！）** ← HTMLのgzipをnginxが圧縮＋benchが解凍で二重浪費の裏付け
- 判定: php-fpm支配的＝**app主導**（キャッシュ/描画削減/DB往復削減が本丸）。加えてbench60%から**HTML gzip無効化(step3)も有効見込み**。mysqld39%はstep1/5のDB往復削減で軽減可。
- → 方針確定: step1(session) → step2(画像cache) → step3(gzip/worker A/B) → step4(feedキャッシュ本命)。

## 📌 環境メモ（判明した事実を実装係が追記）
- 稼働中スタック: nginx → php8.3-fpm(127.0.0.1:9000) → MySQL(3306) + memcached(11211, セッション用)。
- PHPアプリ: `/home/isucon/private_isu/webapp/php/index.php`（Slim, 1枚構成）。views/ vendor/ あり。
- 静的: `/home/isucon/private_isu/webapp/public/`（css/ js/ img/ favicon.ico）。画像はDB BLOBから `/image/{id}.{ext}` で配信。
- php-fpm pool `/etc/php/8.3/fpm/pool.d/www.conf`: user=isucon, listen=127.0.0.1:9000, pm=dynamic, **max_children=5**(小さい), clear_env=no, EnvironmentFile=env.sh。
- DB接続: env.sh の ISUCONP_DB_USER=isuconp / PASSWORD=isuconp / NAME=isuconp。host=localhost, port=3306。
- DB件数(初期): posts=10004。テーブル: users, posts, comments。
- 他言語サービス(go/node/python)は disabled。Rubyは disabled 済み。
