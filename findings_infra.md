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

---
# ===== 第2ラウンド調査（score 81758, fail0 時点）=====

## 環境再計測（2026-06-23 12:1x, 信号機IDLE時）
- **ディスク: / が 14G/15G 使用 = 96%（残681MBのみ）← 最優先の危険要因**
- メモリ: used 3.2Gi / available **551Mi** / buff_cache 727Mi / **Swap 0**（過割当=即OOM）
- public/image: **1.6GB（10661ファイル）**。一方 DB posts も **imgdata 1256MB が全行に残存**（10133行すべて imgdata 有り）→ 同じ画像をディスクとDBに二重保持。
- DBデータ: posts data_length 1204.5MB（≒imgdata）/ comments 10.5MB / users 0.2MB。
- /var/lib/mysql 合計 3.4GB の内訳: **binlog 1.8GB（21ファイル, 各~100MB）** + isuconp 1.4GB + redo/undo/ibdata 145MB。
- php-fpm: pm=static, max_children=16（17プロセス稼働）。opcache On / **validate_timestamps=On** / JIT無効 / memory 128MB。
- MySQL: buffer_pool=512M / **innodb_flush_log_at_trx_commit=1** / **sync_binlog=1** / io_capacity=200。
- nginx error.log が **32MB**、`Undefined array key "csrf_token" (views/post.php:30)` の警告で埋まっている。

## [12:15] 第2ラウンド①: MySQL binlog 無効化（最優先・ディスク1.8GB即解放＋書込高速化）
- (1)現象: `@@log_bin=1`, binlog 21ファイル計 **1.8GB** がディスクを占有。`sync_binlog=1` でCOMMITごとにbinlog fsync。ベンチのPOST/コメント投稿でbinlogは増え続け、残681MBはすぐ枯渇 → ⑤で経験した「ディスク/fd枯渇→500・fail167」の二の舞になる。
- (2)原因仮説: ISUCONはレプリケーション不要なのに binlog がデフォルトON。`binlog_expire_logs_seconds=2592000`(30日)なので自動削除もされず溜まる一方。
- (3)推奨アクション（実装係向け, restart要）:
  - `/etc/mysql/mysql.conf.d/mysqld.cnf` に `disable-log-bin`（または `skip-log-bin`）を追加 → restart で既存binlog削除され **1.8GB即解放**（96%→約84%）。
  - 加えて `innodb_flush_log_at_trx_commit = 2`（OS任せfsync, クラッシュ時直近1秒のみ損失=ベンチOK）。binlog無効化で sync_binlog は無関係化。
  - 効果: POST系（投稿/コメント/login）のCOMMITごとの二重fsync（redo+binlog）が消え書込レイテンシ低下＋ディスク逼迫解消。
- (4)根拠: binlog実測1.8GB / sync_binlog=1+flush=1 はCOMMITあたり2回のdisk同期。WAL不要なベンチでは純粋なオーバーヘッド。

## [12:15] 第2ラウンド②: 画像のDB二重保持を解消（ディスク1.2GB解放, app係連携）
- (1)現象: 画像はnginx直配信に移行済みだが、DBの posts.imgdata に **1256MB が全行残存**。ディスク・buffer_pool・mysqldump時間を無駄に消費。
- (2)原因仮説: POST /(投稿)時に imgdata を DB にもINSERTし続けており（MEMO 次候補にも記載）、かつ既存行のBLOBも未削除。/image フォールバックがDB依存のため消せていない。
- (3)推奨アクション（app係/実装係連携。手順注意）:
  - INSERT から imgdata を外す（ファイルのみ書き出し）。/image フォールバックを「DBからではなくファイル前提、無ければ404」に変更（既存10004件は export 済なので実害なし）。
  - 既存 imgdata を空に: `UPDATE posts SET imgdata='' ...`（ただし行は巨大なまま→`OPTIMIZE TABLE posts` で実ファイル収縮、要一時ディスク・ロック注意。binlog無効化後に実施）。
  - インフラ的効果: posts data_length 1.2GB→数MBになり buffer_pool 512M が完全にホットセットを保持、I/O激減。
- (4)根拠: imgdata 1256MB計測。現状 buffer_pool 512MB はimgdataを引かないクエリ設計なら足りるが、テーブル自体が肥大しメモリ・バックアップ・ディスクを圧迫。

## [12:15] 第2ラウンド③: nginx/php error.log 肥大の停止（中・低リスク）
- (1)現象: nginx error.log 32MB が `Undefined array key "csrf_token"` 警告で充満。FastCGI stderr 経由で**全リクエスト級にログ書込**が走り、ディスクI/Oとディスク消費（48MB/var/log/nginx）を生む。
- (2)原因仮説: views/post.php:30 のPHP Warning（app側バグ）＋ `log_errors=On`, `error_reporting=22527`(E_WARNING含む)。ホットパスでの同期ログ書込。
- (3)推奨アクション: (a) app係が post.php の csrf_token 未定義を修正（本筋）。(b)インフラ即効策として php.ini で `error_reporting` から E_WARNING/E_NOTICE を外す or `log_errors=Off`（display_errors は既にOff）。ログI/Oをホットパスから除去。
- (4)根拠: 同一警告が1行に数百回連結で出力＝1リクエストで多数回発火。error.log 32MBは短時間で蓄積した証拠。

## [12:15] 第2ラウンド④: opcache の本番化（小〜中・PHP CPU削減）
- (1)現象: opcache On だが **validate_timestamps=On**（毎includeでファイルmtime stat）、**JIT無効**。
- (2)原因仮説: 画像がnginxに移りPHPはテンプレ描画＋小クエリ中心＝**CPUバウンド寄り**。validate_timestamps と JIT未使用はPHP CPUの取りこぼし。
- (3)推奨アクション: `opcache.validate_timestamps=0`（コード変更時は手動 `systemctl reload php8.3-fpm` 必須）, `opcache.jit_buffer_size=64M` + `opcache.jit=tracing` を試す。`opcache.max_accelerated_files` も index.php+views分確保(既定でも足りる)。
- (4)根拠: コードは更新頻度低い1枚構成。stat省略は確実な微益、JITはテンプレ/文字列処理に効く可能性。要ベンチA/B。

## [12:15] 第2ラウンド⑤: php-fpm max_children=16 はメモリ的に上限近い（要A/B、上げない）
- (1)現象: max_children=16・pm=static で17プロセス。メモリ available 551MB / Swap 0。
- (2)原因仮説: 2コアに対し16workerは並列待ち吸収には十分だが、CPUバウンド化した今は**これ以上増やすと文脈切替増＋メモリ枯渇でOOMリスク**（swap無し）。逆に減らしてメモリをbuffer_cache/buffer_poolに回す余地もある。
- (3)推奨アクション: 16のままを基本とし、ベンチ中 `top`/`vmstat 1` で `r`(runqueue)>コア数 と %CPU飽和、`ps -ylC php-fpm8.3` のRSS合計を観測。CPUが2コア飽和なら**worker増は無効**（CPU律速確定）。メモリ逼迫なら12へ減らしテスト。**現状は据え置き推奨**。
- (4)根拠: worker17 × RSS(数十MB) で available 551MBは余裕小。CPU2コアでは同時実行は実質2、残りは待ち。

## 第2ラウンド総括: ボトルネックの層判定と見立て
- **ミドルウェア設定での伸びしろはほぼ出し切った段階**。画像nginx直配信・index・buffer_pool・worker・nginx最適化で I/O待ちとworker枯渇は解消済み。
- 残る infra 施策は **(A)ディスク危機回避(binlog無効化・imgdata削除) ← これは性能というより「ベンチを完走させるための必須保全」**、(B)書込fsync削減(flush_log_at_trx_commit=2)、(C)PHP CPU微減(opcache JIT, error.log停止) のみ。いずれも大幅スコア増は期待薄。
- **今後の主戦場はアプリ/DB層**: PHPのCPU（digest openssl外部起動→hashネイティブ、テンプレ描画、comment_count per-post COUNT）と クエリ最適化。律速はおそらく **2コアCPUのPHP処理**。→ 実装係がベンチ中 `top` で「mysqld と php-fpm の CPU%配分」を確認すれば確定できる(php-fpm側が支配的ならapp係主導, mysqld側ならdb係主導)。
- ⚠️ 緊急: **次のベンチ前に binlog 無効化を強く推奨**。残681MBではベンチのPOSTで生成される画像＋binlog増分でディスクフルになり fail に転落する危険が高い。

---
# ===== 第3ラウンド調査（score ~170,000, 目標400,000）=====

## 大前提の再計測（2026-06-23 13:xx, IDLE時）
- **CPU 2コアが唯一にして最大の制約**。これがすべての律速の根。app/db最適化が進んだ今、勝負は「2コアのCPU時間をいかに無駄なく使うか」。
- メモリは余裕あり: php-fpm 17プロセス計 **286MB**(avg16.8MB) / mysqld 1015MB。→ **worker数の制約はメモリでなくCPU**。
- 画像配信ヘッダ実測: `Cache-Control: max-age=86400`＋`public`(2本重複), ETag/Last-Modified有, **304も正常動作**。静的キャッシュは効いている。
- GET / は **gzip圧縮されている**(Content-Encoding: gzip, comp_level5, 元21953B)。
- **ベンチは同一ホスト(loopback)で実行**。Goの`http.Transport{}`はDisableCompression=falseのため**Accept-Encoding:gzipを自動送信＋透過解凍**する。
- somaxconn=4096, tcp_tw_reuse=2 → ネットワーク系sysctlは問題なし。listen.backlog既定511も loopback では十分。

## [13:xx] 第3ラウンド★最優先: ベンチ中CPU%配分の実測（律速主の確定）
- (1)現象: 「app(php-fpm)とmysqldのどちらが2コアを食っているか」が未確定。これが分からないと残りの一手をapp係/db係どちらに振るか決まらない。
- (2)原因仮説: 性能4でN+1解消・性能5で永続接続済→DB往復は激減済み。**php-fpm側がCPU支配的（CPU律速はアプリのPHP実行）** と推測。mysqldが高いならDB側にまだ余地。
- (3)推奨アクション（実装係へ: ベンチ実行中＝信号機RUNNING中に別端末で以下を回す。調査係は計測歪め回避のため自分では回さない）:
  ```
  # 1) コア全体の飽和度（%idleが0付近ならCPU完全飽和＝これ以上は質的改善のみ）
  mpstat -P ALL 1 10
  # 2) プロセス別CPU内訳（php-fpm合計 vs mysqld vs nginx vs benchmarker）
  pidstat -u 1 10 | awk '/php-fpm|mysqld|nginx|benchmark/{a[$NF]+=$8} END{for(k in a)print k,a[k]"%"}'
  # 簡易版（pidstat無い場合）
  for i in $(seq 10); do ps -eo comm,%cpu --no-headers|awk '{a[$1]+=$2}END{print "php="a["php-fpm8.3"]" mysqld="a["mysqld"]" nginx="a["nginx"]}'; sleep 1; done
  ```
- (4)判定基準: %idle≈0かつ php-fpm合計 ≫ mysqld → **CPU律速・app主導**（残りは仕上げ7同様 worker/gzip/socketの削り込み＋app係のPHP軽量化）。mysqldが拮抗/上 → db係主導（クエリ/インデックス）。benchmarker自身が大きい → 後述gzip解凍負荷の証拠。

## [13:xx] 第3ラウンド①: php-fpm max_children=16 は2コアに対し過多（CPU律速なら確実な一手）
- (1)現象: pm=static, max_children=**16** で17プロセス常駐。2コアに対し同時実行可能は実質2。
- (2)原因仮説: CPUバウンド化した今、16の実行可能workerが2コアを奪い合い**コンテキストスイッチ過多・CPUキャッシュ汚染・mysqldのCPU飢餓**を招く。CPUバウンドの最適worker数は概ね「コア数〜コア数×数」＝**4〜8**で、16は過剰。メモリは余っているので「メモリのために16」という理由は無い（実測286MBのみ）。
- (3)推奨アクション: `pm.max_children` を **8 → 6 → 4** とA/Bし最良点を採る。`pm=static`維持。永続接続(性能5)と併用なのでmax_connections圧迫も無し。ベンチ変動±2.5%を超える改善が同方向で複数回出るかで判定。
- (4)根拠: avg16.8MB×16=286MBで省メモリ＝worker削減のデメリットはほぼ無く、context-switch削減のメリットだけ取れる可能性。**仕上げ7でopcache/JITに差が出なかった＝既にCPU飽和の兆候**で、ならworker過多の悪影響が出やすい局面。

## [13:xx] 第3ラウンド②: gzipのloopback二重CPU浪費を排除（CPU律速なら効く可能性大）
- (1)現象: GET / 等のHTMLが gzip(comp_level5)で圧縮配信。ベンチは同一ホストでgzipを受け透過解凍。
- (2)原因仮説: **転送がloopbackなのでgzipの帯域メリットはゼロに近い**のに、nginxの圧縮CPU＋benchmarkerの解凍CPUが**両方とも同じ2コア**を消費＝純粋なCPU浪費。CPU飽和環境ではRPS上限を直接押し下げる。
- (3)推奨アクション（A/Bで検証）:
  - 案A: HTMLのみgzip無効化（`gzip off;` を server内 or location/ で）。静的css/jsは小さく頻度低なので影響軽微、画像は元々非圧縮。
  - 案B: `gzip_comp_level 1`（圧縮CPUを最小化しつつ一応圧縮）。
  - まず案Aを試し、スコアが変動幅超で上がれば採用。下がる/不変なら案B→現状維持。
- (4)根拠: HTML 21953B/リクエスト、GET / は最頻アクセス。loopback転送はμ秒。comp_level5は1リクエストごとに無駄な圧縮計算。ISUCON系で「クライアント同居時はgzip切ると伸びる」事例多数。**※ベンチがAccept-Encodingを送る前提は確認済**。

## [13:xx] 第3ラウンド③: nginx⇄php-fpm を unix socket 化（TCPループバックのsyscall削減）
- (1)現象: `fastcgi_pass 127.0.0.1:9000`（TCP）。pool も `listen=127.0.0.1:9000`。
- (2)原因仮説: 全動的リクエストでTCP接続のオーバーヘッド（3way/ポート管理/TCPスタック通過）。高RPS時はこのsyscall積み上げがCPUを食う。unix domain socketはTCPスタックを通らず軽い。
- (3)推奨アクション: pool を `listen = /run/php/php8.3-fpm.sock`＋`listen.owner=www-data; listen.group=www-data; listen.mode=0660`、nginx `fastcgi_pass unix:/run/php/php8.3-fpm.sock;`。要 php8.3-fpm+nginx restart。
- (4)根拠: 効果は数%オーダーだが低リスク・CPU律速時は積み上げが効く。第1ラウンドでも提案済の積み残し。worker削減②・gzip②と独立に併用可。

## [13:xx] 第3ラウンド④: /image のCache-Control最適化（304往復自体を消す）
- (1)現象: 画像に `Cache-Control: max-age=86400` と `public` が**2本重複**出力。`immutable`無し。現状ベンチは If-Modified-Since→**304**を毎回投げ、nginxが304を返している。
- (2)原因仮説: 304でもリクエスト往復・nginxのstat/openは発生。画像はid固定で不変なので `immutable` を付ければ準拠クライアントは**条件付きリクエスト自体を送らない**→往復ゼロ化。重複ヘッダは一部クライアントで先頭しか見ず`public`が無視され得る。
- (3)推奨アクション: 静的location を1本に統合 →
  `add_header Cache-Control "public, max-age=31536000, immutable";`（expiresディレクティブは外しadd_header一本化で重複解消）。画像のみ長期、css/jsは更新あり得るので別扱い可。
- (4)根拠: 304応答が観測された＝条件付きリクエストが飛んでいる。immutableで往復を消せばnginx/CPUと接続数が浮く。ただしベンチのHTTPクライアントがimmutable準拠かは不明→A/Bで304が消えるか＆スコアを確認。**効果はgzip/workerより小さい見込み**。

## [13:xx] 第3ラウンド⑤【攻めの一手・高リスク高リターン】GET / の fastcgi マイクロキャッシュ
- (1)現象: 最頻endpoint GET /（と/posts）は毎回 php-fpm+MySQL を起動。CPU律速の主因がここなら、**キャッシュでPHP/DBを丸ごとバイパス**できれば桁で効く可能性。
- (2)原因仮説: 40万到達チームは「動的処理をどれだけ減らすか」で差をつけているはず。nginx `fastcgi_cache` で GET / を 1秒だけTTLキャッシュすれば、その1秒間の同一GETはPHPを起動せず配信＝CPU解放。
- (3)推奨アクション（要慎重・app係/実装係と相談）:
  - `fastcgi_cache_path` 定義＋GET / に短TTL（`fastcgi_cache_valid 200 1s`）。
  - ⚠️**重大な正当性リスク**: ベンチは「投稿/コメント直後にそれが画面へ即時反映」を検証する。全画面キャッシュは新着が遅延しfailを誘発しうる。→ 投稿系POST後にキャッシュpurge、またはログイン済(Cookie有)は`fastcgi_cache_bypass`して未ログインGETのみキャッシュ、等の限定が必須。
  - まずは①〜③のローリスク策で頭打ちを確認してから、伸び代が残る場合のみ実験的に投入。
- (4)根拠: CPU2コア飽和下ではPHP起動回数の削減が最も効く。ただし正当性担保の設計コストが高く、private-isuでは「/をキャッシュしない」判断のチームも多い。**博打枠**として記載。

## 第3ラウンド総括: 40万へインフラが貢献できる最大の一手（見立て）
- **核心は「2コアのCPUの奪い合いを減らす」こと**。確度の高い順に: ①worker数を16→4〜8へ削減（context-switch減・mysqldにCPU譲る）＋②gzipのloopback浪費を排除、が**インフラ側で残る最大の的**。③unix socket・④immutable は数%の積み増し。
- ただし正直な見立てとして、**170k→400kの大半はapp/db層（PHP実行コストとクエリ）でしか埋まらない**。インフラ①②③④を全部足しても効くのは合計で十数%〜数十%程度（CPUの無駄取り）で、2.3倍には届きにくい。残りは「PHPの処理量そのものを減らす」＝GET /のレンダリング軽量化・クエリ削減・(⑤の)キャッシュといったapp/db主導の構造変更が必要。
- **次アクション提案**: まず★のCPU実測でphp-fpm vs mysqldの配分を確定 → php-fpm支配的なら①②③を順次A/B（各々変動±2.5%超で判定）→ それでも頭打ちなら⑤のマイクロキャッシュをapp係と設計。実測なしに worker や gzip をいじると変動に埋もれて判断を誤るので、**必ず実測とセットで**。

---
# ===== 第4ラウンド調査（大会 369,640, 目標 500,000）=====

## 大前提の再計測（2026-06-23 16:xx, IDLE時）
- **ボトルネックは確定済: CPU 2コア完全飽和、php-fpm=80%が壁、mysqld=29%(非律速)**（第3R実測）。脱フレームワーク/各種キャッシュ後も「PHP実行が2コアを食い尽くす」構造は不変。
- DB実体は**極小**: posts.ibd=実10MB、imgdata=0件(完全にファイル化済)。`information_schema`の1204.5MBは**OPTIMIZE後未更新のstale統計**で実害なし。→ **buffer_pool 512MはDB全体を余裕で内包**。DBメモリ/IOは一切問題でない。
- メモリ: used 3.1Gi / available 663Mi / Swap 0。mysqld≒1GB(大半はbuffer_pool 512M+各種)。**DBが小さい今、buffer_poolを増やす意味はゼロ**（むしろ256Mへ減らしてphp-fpm用に空ける余地すらある）。
- 画像: public/image にファイル**のみ**(10818枚/1.6GB, DB非依存)。disk 75%(3.7G空き, /initializeで自動掃除済)。
- 現行接続形態: nginx→php-fpm は **TCP 127.0.0.1:9000（unix socket未適用）**。MySQL `bind_address=127.0.0.1`（ローカル専用）。nginx `listen 80`（HTTP/2なし）。

## [16:xx] 第4ラウンド★最優先・本丸: 複数台構成（500kはこれ無しでは到達困難）
- (1)現象: **2コア1台では物理的にCPUが頭打ち**。app(php-fpm)が80%で壁、これ以上のPHP軽量化は逓減。369k→500k(+35%)は「PHP実行に使えるCPUコアを増やす」のが最短かつ唯一の構造的打開策。
- (2)原因仮説: スコアは実質「2コアで捌けるPHPリクエスト数」に比例。コアを増やす＝サーバを足す以外に上限は破れない。台数が使えるなら**最大の一手はこれ**。
- (3)推奨アクション（使える台数別の構成案。実装係＋全係で合意の上）:
  - **【2台】DB分離（最小工事・確実）**: server1=nginx+php-fpm+memcached+画像 / server2=MySQL専用。
    - 変更点: ①server2 mysqld.cnf `bind-address=0.0.0.0`(or 内部IP) ②`CREATE USER 'isuconp'@'<app内部IP>' ... ; GRANT`（現状はlocalhostのみ） ③appのDB接続 host=localhost→server2内部IP ④SG/firewallで3306を内部のみ開放。
    - 効果見立て: mysqld 29%(≒0.58コア)＋memcached/カーネルのDB由来割込がserver1から消え、**php-fpm が使えるCPUが実質1.4→2コアへ拡大**。+20〜30%（≒440〜480k）を見込む。永続接続(性能5)はTCP越しでもRTT<1msなら維持可（接続数 max_children×worker数 ≤ max_connections=151に注意）。
  - **【3台以上】app水平スケール（500k本命）**: フロントnginx(LB)＋app(php-fpm)×N＋DB×1。php-fpmが律速＝水平に台数分スケール。ベンチは1つの target IP を叩くので、その host を **nginx を `upstream { server app1; server app2; }` で振るLB** にする（または各appにnginx置きDNS/LB）。
    - 必須の共有化（ここを外すと整合性fail）:
      - **memcached を1ノードに集約**し全appが同じインスタンスを指す（セッション＋断片HTMLキャッシュ＋feedが全app間で一貫する必要）。listen を内部IPに、appの session.save_path とアプリMemcached接続先を共有memcached(例:DBノード)へ。
      - **画像ファイルの共有**: 現在 public/image はローカルディスク。POSTを受けたappにしか画像が無い→他appで404。対策(いずれか): (a)画像保存先をNFS等の共有ストレージ (b)POST /（画像書込）だけを特定1ノードへルーティングし、他ノードは/imageミス時にそのノードへproxy_pass (c)フロントnginxが/imageを共有ディレクトリから直配信。**最も簡単なのは「フロントLBが画像を共有ボリュームから直配信、appは/imageを持たない」**。
    - 効果見立て: app2台で理想2倍弱、現実1.6〜1.9倍 → **500k超は十分射程**。
  - **段階投入を推奨**: まず【2台:DB分離】で確実に底上げ→整合機構(共有memcached/画像)を整えてから【3台:app追加】。一気に多要素変更するとfail切り分け不能になる。
- (4)根拠: 第3R実測 php-fpm80%/mysqld29%。律速がapp(水平スケール可能)でDBが軽い＝「DBを切り離してappにコアを集中＋app増設」が綺麗に効く理想形。1台では2コアが絶対上限。

## [16:xx] 第4ラウンド①: nginx⇄php-fpm の unix socket 化＋fastcgi keepalive（1台のまま・未適用の残弾）
- (1)現象: 第1/3Rで提案済だが**未適用**。今もTCP 127.0.0.1:9000。fastcgi の upstream keepalive も無し（毎リクエストでFastCGI接続をopen/close）。
- (2)原因仮説: 全動的リクエストでTCPループバックのsyscall＋接続確立。php-fpmが80%まで詰まった今、この固定費削減は直接スループットに乗る可能性。
- (3)推奨アクション:
  - pool `listen=/run/php/php8.3-fpm.sock`＋`listen.owner/group=www-data`、nginx `fastcgi_pass unix:/run/php/php8.3-fpm.sock;`。
  - さらに `upstream fcgi { server unix:/run/php/php8.3-fpm.sock; keepalive 32; }` ＋ location で `fastcgi_pass fcgi; fastcgi_keep_conn on; include fastcgi_params;`（要 `fastcgi_param HTTP_CONNECTION ""`等の調整は基本不要）。
  - ⚠️**測定の罠**: ローカルベンチは bench が67%CPUを奪うため socket化の数%は変動に埋もれる。**大会ベンチ（bench別マシン）でA/B**すること。
- (4)根拠: php-fpm CPU支配下では1リクエストあたりの接続オーバーヘッド削減が積み上がる。低リスク・複数台構成とも併用可。

## [16:xx] 第4ラウンド②: php-fpm max_children を「大会環境で」再チューニング（重要な測定ナンス）
- (1)現象: 第3R local A/B で 16>8（8は-2.7%）だったため16維持。だが**そのlocal測定はbenchが67%CPUを食う環境**だった。
- (2)原因仮説: 大会環境では bench が別マシン＝app server の2コアがフルにphp-fpmへ回る。**CPUの空き具合が違うので最適worker数も変わる**（bench非同居なら待ち時間が減り、より多workerで詰めるか、逆にCPU純飽和でコア数近くが最適か、はlocalでは判定不能）。
- (3)推奨アクション: **大会環境で** max_children = 12 / 16 / 24 / 32 を各2回A/B。CPU実測(mpstat %idle≈0で純飽和ならコア数×2〜3程度が頭、I/O/RTT待ちがあるならさらに上)と合わせ最良点を採る。DB分離後はDB往復がTCP化しRTT待ちが増える→**worker数を上げる余地が出る**点も併せて再評価。
- (4)根拠: 「16が最適」は同居bench下の結論で、大会/分離環境には外挿できない。メモリは16で286MBのみ＝64程度まで増やしてもメモリは余裕（avail663MB）。**メモリ制約ではなくCPU/待ちで決まる**ので実測必須。

## [16:xx] 第4ラウンド③: HTTP/2 は今回**効果なし**（やらない判断の明示）
- (1)現象: nginx `listen 80`（平文HTTP/1.1）。HTTP/2化の検討余地が観点に挙がっている。
- (2)原因仮説: ベンチは Go の `http.Transport{}`。**標準TransportはTLS無しのh2c(平文HTTP/2)を話さない**（ALPN無し平文では HTTP/1.1 固定）。TLSを張ればHTTP/2可能だが、ローカルbench対象がhttp://localhostで、TLSハンドシェイクCPUを2コアに追加するだけで逆効果。
- (3)推奨アクション: **HTTP/2 は今回スキップ**。工数とCPUを①(socket)や複数台に回すべき。
- (4)根拠: クライアント(bench)がHTTP/2を要求しない以上サーバ側で有効化しても使われない。CPU律速下でTLS追加は純損失。

## [16:xx] 第4ラウンド④: 細かい削り（低優先・低リスク、複数台が本筋なら後回し可）
- access_log: 静的は既にoff。動的(/、/posts等)も大量だが、access_log を `buffer=32k flush=5s` でバッファリング or off にすればI/O/CPUを僅かに削減（ログ不要なら off）。
- `worker_processes auto`=2で適正。`open_file_cache`は適用済。`tcp_nopush on`/`sendfile on`済。**nginx側はほぼ出し切り**。
- buffer_pool 512M → DBが10MBしかないので **256Mへ減らしてメモリをOS/php側に空ける**ことは可能だが、メモリは現状余裕(avail663MB)で実益薄。複数台でDB分離するなら関係なくなる。

## [16:xx] 第4ラウンド: ベンチ中CPU再実測の依頼（脱フレームワーク後の配分確認）
- 第3R実測(php-fpm80/mysqld29/nginx20/bench67)は**性能4R-1〜5(断片キャッシュ/Slimバイパス/comment_count非正規化)適用前**の比率。脱フレームワークでphp-fpmのframework固定費が減った今、配分が動いているはず（php-fpmはさらに描画/DB/memcached比率へシフト、mysqldはさらに低下の見込み）。
- 実装係へ（RUNNING中に別端末で）:
  ```
  mpstat -P ALL 1 10          # %idle と usr/sys
  pidstat -u 1 10 | awk '/php-fpm|mysqld|nginx|memcached|benchmark/{a[$NF]+=$8} END{for(k in a)print k,a[k]"%"}'
  ```
  - 見るポイント: (a)mysqldが10%台まで落ちていれば**DB分離の伸びは限定的→app水平増設が本命**。(b)memcachedのCPU%が無視できない大きさなら共有memcached化時のネックに留意。(c)nginxが上がっていればsocket化①の効きしろ。

## 第4ラウンド総括: 500kへインフラが貢献できる最大の一手（結論）
- **最大の一手は明確に「複数台構成」**。1台2コアは物理上限で、369k→500kの+35%は単一ノードのミドルウェア削りでは届きにくい（①socket等を全部足しても数%〜十数%）。
- 台数が使えるなら: **まず【2台=DB分離】で確実に+20〜30%**（app boxにコア集中）。**さらに台数があれば【app水平スケール】で500k超が射程**。鍵は「memcached集約」と「画像ファイル共有」の整合設計（ここを誤るとfail）。
- 台数が1台に固定なら: 残る現実的な弾は **①unix socket化（未適用・要大会A/B）** と **②worker数の大会環境再チューニング** のみ。あとはPHP実行量削減=app/db係の領域（断片キャッシュ拡張・テンプレ実行回避）に戻る。
- ⚠️測定規律: ローカルbenchはbench自身が67%CPUを食い数%差を覆い隠す。**①②および複数台効果は必ず大会ベンチ（bench別マシン）で、同方向・複数回・変動±2.5%超で判定**すること。

---
# ===== Go分析（大会: PHP 392,566 > Go 278,809 / ローカル: Go 228k > PHP 195k の逆転）=====

## 結論（先に）
逆転の主因は**言語の速さでも最適化差でもなく、Go側の「接続まわりが高並列で破綻する」構造欠陥**。Go実装はDB接続プール無設定＋nginx⇄Goにkeepalive無し。**ローカルは低並列なので欠陥が眠り、大会は高並列で欠陥が爆発**する。PHPの pm=static=16＋永続接続は「高並列で自動的に接続数を絞って再利用する」ため大会でこそ強い。

## 計測した事実（2026-06-23, nginx→Go:8080, isu-go active）
- **app.go: `sqlx.Open("mysql",dsn)` のみ。`SetMaxOpenConns`/`SetMaxIdleConns`/`SetConnMaxLifetime` が一切無い**
  → Goデフォルト = **MaxIdleConns=2 / MaxOpenConns=無制限 / lifetime=無制限**。
- **nginx→Go: `proxy_pass http://127.0.0.1:8080` のみ。`upstream{keepalive}`・`proxy_http_version 1.1`・`proxy_set_header Connection ""` が無い**
  → nginxは**HTTP/1.0でupstream接続し毎リクエストTCPを張り直す**（keepaliveされない）。
- MySQL GLOBAL STATUS に**接続チャーンの痕跡**: `Connections=110743`(累積接続試行が膨大) / `Threads_created=1105`(thread_cache_size=9に対し過大＝スレッド生成の繰り返し) / `Max_used_connections=28`(PHPなら~16で頭打ちのはず) / Aborted_connects=0。
- GOMAXPROCS: env未設定 → 既定で `runtime.NumCPU()=2`。**2コアには適正**（ここは問題でない）。
- Go実装は**十分に最適化済み**（L94 comment_count非正規化バックフィル, L222 sync.Mapユーザキャッシュ, 断片キャッシュ, L317/330 posts/comments の IN 一括取得）。→ **「Goが素のリファレンスで最適化負け」説は否定**。

## ★逆転の構造的メカニズム（最重要）
**ローカル（bench同居・CPU希少・低並列）**
- benchが約67%CPUを奪う＝**bench自身が高並列を生成できない**（リクエスト生成goroutineがCPU飢餓）。app到達の同時実行数は低い。
- 低並列ではGoはidle接続2本でほぼ足り、接続チャーンは起きない。CPUが唯一の希少資源なので、**1リクエストの素のCPUが軽いGoが勝つ** → Go 228k > PHP 195k。

**大会（bench別マシン・2コアフル・高並列）**
- benchがフル同時接続を叩き込む。ここでGoの2欠陥が同時発火:
  - **(A) DBプール: MaxIdleConns=2＋MaxOpenConns無制限** → 同時要求がidle2本を超えると**新規接続を次々open→使用後closeで捨てる**（idleに戻せない）。MySQLのスレッド生成/認証/破棄が暴走し、**mysqld CPUとapp側sys CPUを食う＋取得待ちでレイテンシ増**。`Max_used_connections=28`/`Threads_created=1105`がこの挙動の裏付け。最悪 max_connections=151 に迫り 1040 リスク。
  - **(B) nginx⇄Go keepalive無し** → 高RPSで**毎リクエストloopback TCPを張り直し**、sys CPU＋TIME_WAIT積み上げ。
- 結果: **大会で増えたCPUをGoは「接続管理(sys)と待ち」に浪費し、有用処理(usr)に変換できない**。CPUを使い切れず頭打ち → Go 278k。
- 一方PHP: `pm=static=16`＋`PDO ATTR_PERSISTENT` = **常に16本の温かい接続を再利用**。同時実行は16で自然に頭打ち（=組込みの admission control）。高並列でもチャーンゼロ・接続枯渇ゼロ。**PHPの「硬い」プロセスモデルが高並列でこそ美点**になり 392k。

→ 要約: **「Goは並列度不足」ではなく「並列度が無制限すぎて下流(DB接続)を破綻させ、backpressureが無い」**。PHPは構造的に並列を絞り接続を再利用するので大会の高並列に強い。ローカルは並列が低くこの差が出ないため逆転して見える。

## 推奨アクション（実装係向け・効果順。コードは実装係が変更）
1. **【最優先・ほぼ確実】Go の DB接続プールを明示設定**（app.go の sqlx.Open 直後）:
   - `db.SetMaxOpenConns(N)` … Nは「有用な同時実行数」に合わせ**有界化**（2コアなら目安 24〜64 で大会A/B。max_connections=151内）。
   - `db.SetMaxIdleConns(N)` … **MaxOpenConnsと同値**にして接続を**捨てずに再利用**（チャーン停止の肝。デフォルト2が諸悪の根源）。
   - `db.SetConnMaxLifetime(0 or 数分)`。
   - 併せて MySQL `max_connections` を 256〜512 へ引上げ（Nを大きく試す場合の安全弁）、`thread_cache_size` を 64程度へ。
   - 期待: 大会高並列でmysqldのスレッド生成/認証CPUとapp側待ちが消え、CPUがusr(有用処理)へ回る。**逆転の最大要因の解消**。
2. **【高・低リスク】nginx⇄Go を keepalive 化**:
   - `upstream goapp { server 127.0.0.1:8080; keepalive 64; }` ＋ location で `proxy_pass http://goapp; proxy_http_version 1.1; proxy_set_header Connection "";`
   - 毎リクエストのloopback TCP張り直しを排除。高RPSのsys CPUとTIME_WAITを削減。
3. **【中】Go側に同時実行の上限（backpressure）を持たせる**: PHPの max_children=16 に相当する仕組みがGoに無い。(1)のMaxOpenConnsがDB側の事実上のリミッタになるが、加えてハンドラ前段にセマフォ（buffered channel）で同時処理数を制限すると、過負荷時の暴走（ゴルーチン爆発・メモリ/スケジューラ圧）を防げる。まず(1)で十分な可能性大、伸び悩めば検討。
4. GOMAXPROCS は2のままで適正（変更不要）。`GOGC` を 200 程度に上げGCのCPUを減らす微調整は大会A/Bの余地（優先度低）。

## 検証方法（大会 or bench別マシン環境で。ローカルは低並列で再現しないため判定不可）
- ベンチ中に app server で:
  ```
  mpstat -P ALL 1 10     # %usr vs %sys vs %idle。Goで%sysが高い/idleが残るなら接続チャーン律速の証拠
  pidstat -u 1 10 | awk '/app|mysqld|nginx/{a[$NF]+=$8}END{for(k in a)print k,a[k]"%"}'
  # MySQL接続チャーンの増分（30秒で取り差分を見る）
  mysql -e "SHOW GLOBAL STATUS LIKE 'Threads_created'; SHOW GLOBAL STATUS LIKE 'Connections'; SHOW STATUS LIKE 'Threads_running';"
  ss -tan state time-wait | wc -l   # TIME_WAIT のloopback積み上げ（keepalive無しの裏付け）
  ```
- 判定: (1)適用後に **Threads_created の増加が止まり、Max_used_connections が設定上限近くで安定、%sys低下・%usr上昇** すれば原因確定＆解消。
- 期待される結末: プール+keepalive修正後のGoは、低CPU/リクエストの本来の強みを大会の高並列でも発揮し、**PHP(392k)を上回る可能性が高い**。現状の278kは「Goが遅い」のではなく「設定欠落で大会並列に対応できていない」だけ。

## どちらに賭けるかの示唆
- **Goを採るなら (1)(2) は必須**。これ無しのGoは大会で不利。修正は数行＋nginx数行で低リスク・高期待値。
- ただしPHPは既に392kで安定実績。**「Goプール修正→大会A/Bで392k超を確認できたら切替、未達ならPHP継続」**が安全。さらに前述の**複数台構成は言語非依存**で、どちらを採っても効くので並行検討推奨。

---
# ===== Go第2R（大会 Go 479,617 / トップ 693,053, +44%の伸びしろ / 1台2コア）=====

## 適用済みの確認（効いている）
- DBプール: `SetMaxOpenConns(64)/SetMaxIdleConns(64)/ConnMaxLifetime(0)`、DSN `interpolateParams=true`(prepare往復排除)。
- nginx: `upstream goapp {keepalive 64}` + `proxy_http_version 1.1` + `Connection ""`、gzip off、画像immutable。
- MySQL: `max_connections=512`, `thread_cache_size=100`, buffer_pool=512M, flush_trx=2。
- メモリ余裕: available **1.1Gi** / buff_cache 824Mi / Swap0。→ GC緩和やGOMEMLIMITの余地あり。

## [16:xx] Go第2R★最優先: memcached クライアントの接続チャーン（DBで直した罠が memcached に残存）
- (1)現象: `memcacheClient = memcache.New(memdAddr)` に **MaxIdleConns 未設定 → gomemcache デフォルト=2**。かつ **session は gorilla-sessions-memcache で毎リクエスト memcached 往復**（`getSession`→`store.Get`、getSessionUser/csrf/flash から呼ばれ、ほぼ全リクエストで発火）。断片キャッシュ等も同じ1クライアント経由。
- (2)原因仮説: **DBで直したのと全く同じ接続チャーン**が memcached 側に残っている。大会の高並列では idle 2本を超えた同時要求が**新規TCP接続をopen→即close**を繰り返し、memcached/app双方の sys CPU・loopback TIME_WAIT・取得待ちレイテンシを生む。**DBより悪質**で、session往復は全リクエストに乗るため影響面が最大。app.go内コメントに「無設定だとMaxIdleConns=2でchurn」とDB側で認識済なのに**memcacheClientには適用漏れ**。
- (3)推奨アクション（実装係・数行）:
  - `memcacheClient = memcache.New(memdAddr)` の直後に **`memcacheClient.MaxIdleConns = 128`**（max_children/同時実行に見合う値）。`memcacheClient.Timeout = 100 * time.Millisecond` 程度も明示。
  - これだけで全リクエストの session 往復が温かい接続を再利用 → sys CPU と待ちが激減する見込み。**Go第2Rの最有力の一手**。
- (4)根拠: ss -lnx で memcached は **TCP(127.0.0.1:11211)のみ**（unixソケット無し）＝毎接続がTCPスタックを通る。session が全リクエストに乗る設計と MaxIdleConns=2 の組合せは、479k規模の高並列で確実にネック化する。DBプール修正で+72%(278→479k)伸びた事実が、同種修正の効きを裏付ける。

## [16:xx] Go第2R①: GC チューニング（GOGC / GOMEMLIMIT）— 高スループットの定番CPU回収
- (1)現象: `GOGC`/`GOMEMLIMIT` env 未設定 = **GOGC=100 デフォルト**（ヒープが倍になる度GC）。479k規模ではテンプレ描画・[]byte・session構造体・クエリ結果で**アロケーション率が高くGCがCPUを食っている**可能性大。メモリは available 1.1Gi と空いており、GCを我慢させる余地がある。
- (2)原因仮説: GCのmark/sweepが2コアのCPUを定常的に消費。GOGCを上げGC頻度を下げれば、その分のCPUが有用処理(usr)へ回る。
- (3)推奨アクション（systemd `Environment=` で。コード変更不要）:
  - まず **`GODEBUG=gctrace=1`** をつけてベンチ → ログでGC頻度・各GCのCPU%・pauseを実測（これでGC圧の有無を定量化）。
  - GC圧があれば **`GOGC=200`→`400`** を大会A/B。または **`GOMEMLIMIT=1200MiB`**（available内）でソフト上限を切りGOGCを高め/offに。Swap0なのでGOMEMLIMITは過大設定OOM回避にも有効。
  - 期待: GC由来CPUを数%〜十数%回収。低リスク・env一行。
- (4)根拠: メモリに1.1Giの余裕＝「メモリを使ってCPUを買う」典型局面。トップ層はほぼ確実にGOGC/GOMEMLIMITを触っている。

## [16:xx] Go第2R②★: ベンチ中CPU再実測（待ちの正体を特定＝次の一手を確定）
- (1)現象: 479k時点の isu-go/mysqld/nginx の配分と、**%usr vs %sys vs %idle** が未測定。Goが「計算で詰まっている」のか「待ち(memcached/DB/lock)で遊んでいる」のかで打ち手が変わる。
- (2)推奨実測（実装係がRUNNING中に別端末。bench別マシンの大会環境が理想だが、ローカルでも%sys/%idleの傾向は参考になる）:
  ```
  mpstat -P ALL 1 15                     # %usr/%sys/%idle/%iowait。idle残=待ち, sys高=syscall(接続/round-trip)律速
  pidstat -u 1 15 | awk '/app|mysqld|nginx|memcached/{a[$NF]+=$8}END{for(k in a)print k,a[k]"%"}'
  pidstat -w 1 5 -C 'app'                # cswch/s(コンテキストスイッチ)。膨大ならchurn/round-trip過多
  ss -tan | awk '{print $1}' | sort | uniq -c        # TIME_WAIT激増ならkeepalive/pool不足
  ss -tan state time-wait | wc -l
  ```
- (3)判定: **%idle>0 かつ cswch/s 大 → 待ち律速**（memcached churn=最優先項目, DB round-trip）。**%sys高 → syscall律速**（unixソケット化③が効く）。**%usrでGC痕跡 → ①GOGC**。mysqld高 → DB係へ。
- (4)根拠: 1台2コアでは「CPUを usr 100% に張り付かせ無駄なsys/idleを消す」のが勝ち筋。何で遊んでいるかを測れば残り44%の埋め方が一意に決まる。

## [16:xx] Go第2R③: ローカル間通信の unix ソケット化（sys CPU 削減）
- (1)現象: Go⇄MySQL は **TCP 127.0.0.1:3306**（mysqld.sock は存在するのに未使用）。Go⇄memcached も **TCP 127.0.0.1:11211**。nginx⇄Go も TCP:8080。全てloopback TCP。
- (2)原因仮説: 高RPSでloopback TCPのsyscall/プロトコル処理が積み上がりsys CPUを消費。unix domain socketはTCPスタックを通らず軽い。
- (3)推奨アクション（②で%sysが高いと確認できたら）:
  - **Go⇄MySQL**: DSNを `unix(/var/run/mysqld/mysqld.sock)` に（最も簡単・確実）。
  - **nginx⇄Go**: Goを `net.Listen("unix","/run/goapp.sock")` で listen、nginx upstream を `server unix:/run/goapp.sock`。
  - **Go⇄memcached**: memcached を `-s /run/memcached.sock` で unix listen＋Go接続先変更（要memcached再起動）。優先度は最優先のMaxIdleConns修正の後。
- (4)根拠: mysqld.sock が既に存在＝MySQL unix化は即可能。トップ層の常套手段。①(GC)②(実測)の後の積み増し（各数%）。

## [16:xx] Go第2R④: MySQL 追加チューニング（低impact・DBは非律速のため後回し可）
- `skip_name_resolve=OFF` → **ON** に（新規接続時の逆引きDNS停止。プール再利用で頻度は低いが無害な定番）。
- `innodb_doublewrite=ON` → **OFF**、`innodb_flush_method=fsync` → **O_DIRECT**（書込I/O削減・二重バッファ解消）。ただしDB CPUは元々低く、buffer_poolにDB全部載るため**伸びは小さい**見込み。②でmysqldが高いと出た場合のみ優先度を上げる。

## Go第2R総括: 479k→693k(+44%)へインフラが貢献できる最大の一手＋トップ層推測
- **最大の一手は memcached の MaxIdleConns 修正（★）**。DBプール修正が +72% を生んだのと同型の欠落が、より影響面の広い session 往復に残っている。**ここが今の新ボトルネックの最有力**。次点が ①GOGC（メモリ余裕をCPUに変換）。
- **トップ69万チームの1台2コア構成の推測**:
  - GC緩和（GOGC高 or GOMEMLIMIT）でGC CPUを最小化。
  - **全ローカル通信をunixソケット化**（nginx⇄app⇄mysql⇄memcached）でsys CPUを削減。
  - **session/round-trip の徹底削減**: session を memcached往復でなく**プロセス内キャッシュ or JWT/署名Cookie**で持ち、毎リクエストのI/Oを消す（これが効けば大きい）。または getSession を1リクエスト1回に集約。
  - **CPUの使い切り**: %idle/%sysを削り usr~100% へ。場合により **CPUアフィニティ**（mysqldを1コア、Goを2コアに寄せる等 taskset/cpuset）でキャッシュ局所性とスケジューラ競合を最適化。
  - 静的/画像は sendfile+immutable+open_file_cache で既に最適、page cache(画像1.5G>mem1.1G)に乗り切らない冷データのみdisk—ホット集合は乗るので影響限定。
- **測定規律**: ローカルbenchは低並列で memcached churn等の高並列バグを再現しきれない。**★①③は必ず大会/別マシンbenchで、同方向・複数回・変動±2.5%超で判定**。まず②の実測→★memcached修正→①GOGC→③unixソケット、の順が確度高い。
