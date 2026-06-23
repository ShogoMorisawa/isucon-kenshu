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
