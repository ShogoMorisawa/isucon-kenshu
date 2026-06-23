# findings_app.md — アプリ調査係 専用（読み取り専用担当）

> ルール: ここにだけ書く。ソース編集・ベンチ・restart・DB変更は禁止。
> 信号機(MEMO.md)が RUNNING の間は重い処理を控える。
> 観点: PHPコードのN+1、画像BLOB配信、テンプレートレンダリング、無駄なループ/クエリ、キャッシュ余地。

## 調査メモ

## [初回分析] 画像をDB BLOBからアプリ配信している（最大のボトルネック）
(1)現象: `GET /image/{id}.{ext}` が `SELECT * FROM posts WHERE id=?` で imgdata(BLOB) を読み、PHPの `$response->getBody()->write($post['imgdata'])` で配信。投稿も `INSERT INTO posts (... imgdata ...)` でBLOBをDBに保存（index.php:381, 397-412）。
(2)原因仮説: 画像1枚ごとにPHP-FPMワーカー+MySQL往復が発生。画像はGETリクエストの大半を占めるため、PHP/MySQL双方のCPU・帯域・コネクションを食い潰す。private-isu最大の論点。
(3)推奨アクション（実装係向け）:
  - 投稿時に imgdata を `/home/isucon/private_isu/webapp/public/image/{id}.{ext}` へファイル書き出しし、nginx で静的配信（try_files で存在すればnginx、なければPHPにフォールバック）。
  - 既存画像は initialize 時または一括スクリプトで DB→ファイルへエクスポート。
  - nginx に `location /image/` を追加し `try_files $uri @app;`（設定はインフラ係担当）。
  - 併せて `/image` の `SELECT *` を `SELECT mime, imgdata` に絞る（フォールバック時のみ走る想定）。
(4)根拠: 画像はキャッシュ可能な静的バイナリで毎回DB往復は無駄。ベンチは画像GETが多数。BLOB配信を外せばPHP-FPM/MySQL負荷が桁で減る見込み。

## [初回分析] make_posts が深刻なN+1（投稿×コメント×ユーザー）
(1)現象: `make_posts()`（index.php:130-160）が投稿1件ごとに
  - comment_count: `SELECT COUNT(*) ... WHERE post_id=?`（1クエリ/投稿）
  - comments: `SELECT * FROM comments WHERE post_id=? ORDER BY created_at DESC [LIMIT 3]`（1クエリ/投稿）
  - 各コメントのuser: `SELECT * FROM users WHERE id=?`（コメント数ぶん）
  - 投稿のuser: `SELECT * FROM users WHERE id=?`（1クエリ/投稿）
  を発行。トップページは20投稿×(2+コメント数+1) で軽く100クエリ超。
(2)原因仮説: ループ内クエリの典型N+1。1リクエストでMySQL往復が爆発し、これがアプリのレイテンシ主因。
(3)推奨アクション:
  - comment_count は `posts` に集計カラムを持つ or 1本のGROUP BYで一括取得。
  - コメントは対象post_id群を `WHERE post_id IN (...)` で一括取得し、PHP側でpost_idごとに振り分け。
  - users はコメント・投稿で必要なuser_idを集めて `WHERE id IN (...)` で一括取得しmapキャッシュ。理想はJOIN。
  - 最低限、同一ユーザーの繰り返しfetchをリクエスト内メモ化（static配列キャッシュ）するだけでも効く。
(4)根拠: 20投稿表示で数十〜100超のクエリ。IN句一括化でクエリ数を数本に削減でき、トップ/posts/ユーザーページ全てに効く。

## [初回分析] posts全件をSQLで取得しPHPで20件に絞っている
(1)現象: `/`（index.php:305）と `/posts`（321）と `/@account`（501）が `ORDER BY created_at DESC` で LIMIT なし全件 fetchAll → make_posts内で `count>=POSTS_PER_PAGE(20)` で break。
(2)原因仮説: 全posts行をPHPメモリへロードしてから捨てている。posts行数が多いほど無駄なメモリ転送・GC負荷。del_flgユーザー除外をPHPでやるため安全にLIMITを付けにくい構造になっている。
(3)推奨アクション:
  - users と JOIN して `del_flg=0` をSQL側で絞り、`LIMIT 20`（/posts はページング用に余裕を見て LIMIT を付与）。
  - これにより make_posts に渡る行数が激減し、上記N+1の母数も減る。
(4)根拠: 全件取得は posts 件数に比例して悪化。SQL側 LIMIT 化はメモリ/転送/後続N+1すべてを縮小。

## [初回分析] SELECT * による不要なBLOB読み込み
(1)現象: `/posts/{id}`（index.php:331）と `/image`（402）と make_posts内 comments(`SELECT *`) が `SELECT *` を使用。posts の `*` は巨大な imgdata カラムを含む。
(2)原因仮説: 詳細ページ表示で imgdata(数MB) を毎回ロードしているが、本文表示に imgdata は不要（画像はimgタグ経由で別取得）。無駄なメモリ・転送。
(3)推奨アクション: posts からの SELECT は必要カラム（id,user_id,body,mime,created_at）に限定。imgdata が要るのは画像配信エンドポイントのみ。
(4)根拠: 1行あたり数MBの転送削減。詳細ページのレイテンシ改善。

## [初回分析] パスワードハッシュが openssl のシェル外部プロセス起動
(1)現象: `digest()`（index.php:197-201）が `printf ... | openssl dgst -sha512` をバッククォートで都度起動。login/register で2回（salt+passhash）呼ばれる。
(2)原因仮説: リクエストごとにシェル+opensslプロセスをfork/exec。低頻度だが1回あたりコストは大きい。
(3)推奨アクション: PHPネイティブの `hash('sha512', ...)` に置換（出力フォーマット互換に注意。初期データのpasshash生成方式と一致が必要）。ログイン負荷が高いシナリオで効く。
(4)根拠: 外部プロセス起動はミリ秒級。ネイティブhashはマイクロ秒級。

## [初回分析] /admin/banned の更新がループ内prepare
(1)現象: `POST /admin/banned`（index.php:484-487）が uid ごとにループで prepare+execute。
(2)原因仮説: 件数ぶんの往復。ただし管理操作で低頻度のため優先度は低い。
(3)推奨アクション: prepare をループ外で1回、または `WHERE id IN (...)` で一括UPDATE。
(4)根拠: 低頻度ゆえ効果小。余力があれば。

## 優先度まとめ（実装係向け・効果大きい順）
1. 画像BLOB→ファイル化＋nginx静的配信（最大効果。インフラ係と連携）
2. make_posts のN+1解消（IN句一括 / JOIN / user メモ化）
3. posts 全件取得をやめ JOIN+LIMIT で del_flg 除外＆20件化
4. SELECT * 廃止（imgdata 不要箇所）
5. digest を hash() ネイティブ化（ログイン多シナリオ）
6. /admin/banned 一括UPDATE（余力があれば）

※インデックスは DB係担当だが、上記IN句/JOIN化は `comments(post_id)`、`posts(user_id)`、`posts(created_at)` 等のインデックス前提。DB係の findings_db.md と突き合わせ推奨。

---

# 第2ラウンド調査（532→81758 達成後の再分析）

前提: ①画像ファイル化+nginx直配信 ②index4本 ③fpm/mysql調整 ④user取得メモ化+LIMIT100+SELECTカラム限定 ⑤nginx静的最適化 が適用済み。
画像GETはnginx直配信となりPHPを通らない。よって現在PHPを通る主要負荷は GET /・GET /posts・GET /posts/{id}・POST /comment・POST /・login/register。
見立て: 画像がPHPから外れた今、**PHP-FPMのCPU/DBラウンドトリップ**が律速。以下を効果順に。

## [12:1x 第2R-①] digest() の openssl 外部プロセス起動が未対応（最優先・確証あり）
(1)現象: `digest()`(index.php:205-209) が今も `printf ... | openssl dgst -sha512 | sed` をバッククォートで都度起動。login/register で `calculate_passhash`→`calculate_salt`+本体 で **1リクエストにつき最低2回**シェル+openssl+sedをfork/exec。
(2)原因仮説: プロセス起動はミリ秒級でPHPネイティブhash(マイクロ秒級)の数百〜千倍。login負荷シナリオでCPUとプロセステーブルを圧迫し、ベンチ初期にlogin/register/logoutのタイムアウトを出していた根本要因がここ。
(3)推奨アクション: `digest($src)` を `return hash('sha512', $src);` に置換するだけ。**出力は完全互換を実測確認済**（下記根拠）。escapeshellarg/printf/sed すべて不要になる。
(4)根拠: 実測で `printf "%s" "test:salt" | openssl dgst -sha512 | sed 's/^.*= //'` と `hash('sha512','test:salt')` がバイト一致（ccfba6...cf36）。よって既存passhashと完全互換でリスクほぼ無し。1回あたり数ms→数µsへ。login多シナリオで大きな余地。

## [12:1x 第2R-②] PDO接続がリクエスト毎に新規（persistent化の余地）
(1)現象: `$container->set('db', ...)` (index.php:53-60) が毎リクエスト `new PDO(...)` を生成。PDOオプション未指定で persistent でない。
(2)原因仮説: GET / 等のたびにMySQLへ新規接続(TCP+認証ハンドシェイク)。高RPS下では接続確立/破棄のコストとMySQL側のスレッド生成が無視できない。
(3)推奨アクション: PDOコンストラクタ第4引数に `[PDO::ATTR_PERSISTENT => true]` を付与し接続を再利用。pm=static/max_children=16なので常駐接続は最大16前後、MySQL `max_connections` に収まる範囲で安全。併せて `PDO::ATTR_EMULATE_PREPARES => true`(既定) はラウンドトリップ削減になり現状維持で良い。
(4)根拠: fpmワーカー16が高頻度で接続を張り直すと、接続コスト×RPSが積み上がる。persistentで接続ハンドシェイクをほぼ消去。

## [12:1x 第2R-③] make_posts の comment_count / comments がまだ投稿数ぶんN+1
(1)現象: `make_posts`(index.php:144-152) は user取得こそメモ化されたが、投稿1件ごとに
  - `SELECT COUNT(*) FROM comments WHERE post_id=?`（1本/投稿）
  - `SELECT * FROM comments WHERE post_id=? ORDER BY created_at DESC [LIMIT 3]`（1本/投稿）
を発行。GET / は20件表示で約20+20=**40往復/リクエスト**（最ホットエンドポイント）。
(2)原因仮説: indexで各クエリは速いが、往復回数×RPSでPHP↔MySQLのsyscall/レイテンシが積算。GET / の主コスト。
(3)推奨アクション（いずれか）:
  - 案A(最小): 表示対象の post_id を集め、`SELECT post_id, COUNT(*) ... WHERE post_id IN (...) GROUP BY post_id` で件数一括、`SELECT * FROM comments WHERE post_id IN (...) ORDER BY post_id, created_at DESC` でコメント一括取得→PHPでpost_idごとに振り分け＆上位3件。40往復→2往復。
  - 案B(本命): `posts` に `comment_count` 集計列を追加し、`POST /comment` のINSERT時に `UPDATE posts SET comment_count=comment_count+1` 。COUNT(*)自体を消す（DB係と要相談）。
  - ※`SELECT *` のコメント取得も `id,post_id,user_id,comment,created_at` 等に限定推奨（commentにBLOBは無いが習慣として）。
(4)根拠: GET / 1回で40→2往復は約95%削減。最ホットパスなのでスコア寄与大の可能性。indexは idx_post_created 済で IN+GROUP BY も効く。

## [12:1x 第2R-④] POST / で imgdata を依然DBへ書込み＋tmpファイルを3回読み
(1)現象: `POST /`(index.php:384,395,403) が `file_get_contents($_FILES['file']['tmp_name'])` を**3回**実行（サイズ検査・INSERT・ファイル書出し）。さらに `INSERT INTO posts (...imgdata...)` でBLOBをDBにも保存し続けている。
(2)原因仮説: 最大10MBのアップロードを3回ディスクから読み直し。かつDBにもBLOBを二重保存しDBサイズ/書込みI/Oを増やす。MEMOの「ディスク残~1GB(94%)」逼迫の主因。投稿頻度は低いが、ディスク枯渇は500/破損リスク直結。
(3)推奨アクション:
  - tmp内容を `$data = file_get_contents(...)` で1回だけ読み、以降は変数を使い回す。
  - imgdata の DB保存を廃止しファイルのみに（INSERTから imgdata 列を外す）。ただし `GET /image` フォールバックがDB依存(index.php:417,424)のため、廃止する場合は **POST時に必ずファイル書出し成功を保証**し、フォールバックも「ファイル優先・無ければ404 or 再生成不可」へ設計変更が必要。安全策として当面は「DB保存は残しつつ tmp読みを1回化」だけ先行でも可。
(4)根拠: 10MB×3読み→1読み。DB BLOB廃止でディスク逼迫(94%)を緩和。投稿頻度低いためスコア直効果は中だが、ディスク枯渇による崩壊リスク回避の意味が大きい。

## [12:1x 第2R-⑤] get_session_user が毎リクエストで users をSELECT
(1)現象: `get_session_user`(index.php:120-128) はログイン中の全リクエストで `SELECT * FROM users WHERE id=?` を実行。GET /・/posts・/posts/{id}・comment 等すべてで1往復。
(2)原因仮説: PK1行引きで軽量だが最ホットパスに毎回1往復が乗る。`SELECT *` で不要列(passhash等)も取得。
(3)推奨アクション: 最低限 `SELECT id, account_name, authority, del_flg` にカラム限定。さらにセッションに user スナップショットを持たせ毎回のSELECTを省く案もあるが、del_flg/authority変更(admin/banned)が即時反映されない副作用に注意。まずはカラム限定が安全。
(4)根拠: passhash等の不要列除外で行サイズ減。効果は中の下だが全ホットパスに効く。

## [12:1x 第2R-⑥] テンプレートレンダリングは現状ボトルネックではない（参考）
(1)現象: layout.php→各view、posts.php が投稿をforeachし post.php を `require`。escape_html(htmlspecialchars)を各フィールドで実行。
(2)原因仮説: 1ページ20投稿程度では描画コストはDB往復に比べ十分小さい。OPcacheが効いていれば実行コストは低い。
(3)推奨アクション: 当面手を入れる優先度は低い。OPcache有効化(infra係)が未なら確認推奨。優先は①〜④。
(4)根拠: DB往復40回 vs PHP描画。律速は前者。

## [12:1x 第2R-⑦] initialize 時の画像書き出しの妥当性（再投入耐性の確認）
(1)現象: `GET /initialize`(index.php:222-225) は `db_initialize()` で posts id>10000 / comments id>100000 / users id>1000 をDELETEし del_flg を再設定するのみ。画像ファイルの再生成・掃除は**していない**。既存画像は別途 `export_images.php` で全件書出し済（MEMO ①）。
(2)原因仮説: ベンチが投稿した id>10000 の画像ファイルは public/image に残り続け、DBからはDELETEされる→**ファイルとDBの不整合(ゴミファイル累積)**。`GET /image` フォールバックがDB前提のため、DB削除済みidのファイルが残っても実害は少ないが、ディスクを食う。再投入(initialize連打)でファイルが単調増加。
(3)推奨アクション: initialize時に `public/image` の id>10000 のファイルを掃除する処理を追加すると、ディスク逼迫(94%)とゴミ蓄積を防げる。ただしベンチのinitializeはタイムアウトがあるため重い全削除は避け、`find public/image -name 'glob' ...` 相当を軽量に。実害が出るまでは優先度中。
(4)根拠: ディスク残~1GB(94%)。ベンチ毎に id>10000 画像が累積。掃除しないと数回のベンチで枯渇しうる。

## [12:1x 第2R] 律速の見立てまとめ（実装係向け・効果順）
1. **digest()→hash('sha512')**（①）: 確証あり・互換実測済・低リスク・login負荷で大。まず即やる。
2. **PDO persistent接続**（②）: 全ホットパスの接続コストを削減。低リスク。
3. **make_posts の comment_count/comments を IN一括 or 集計列**（③）: GET / の40往復→2往復、最ホットパスで寄与大。
4. **POST / の tmp1回読み＋imgdata DB保存見直し**（④）＋**initialize画像掃除**（⑦）: ディスク逼迫(94%)対策。崩壊リスク回避。
5. get_session_user カラム限定（⑤）: 中の下。
6. テンプレ/OPcache（⑥）: 確認のみ。

総括: 画像をnginxへ逃がした今、残るPHP律速は「login時のopenssl fork」と「GET /のDB往復40回」。①③が次の二大ターゲット。CPUプロファイル(infra係)でlogin時のopensslプロセスとmake_postsのDB待ちが上位に出るはず。要 findings_infra.md と突き合わせ。

---

# 第3ラウンド調査（現在 ~170,000 / 目標 400,000・他チーム到達済）

前提確認(適用済): 画像ファイル化+nginx直配信、index4本、fpm static16、make_postsのN+1一括化(comments IN 1本+preload_users IN)、PDO persistent、digest native、imgdata DB保存停止+FORCE INDEX。
→ もう「軽いN+1潰し」では2倍は出ない。**リクエスト数そのものを減らす(キャッシュ)／1リクエストの固定費(session+DB往復+framework)を削る**の2方向で攻める。

## 律速の再特定（どこを叩くべきか）
ベンチのリクエスト内訳は概ね「GET /image(1ページ20枚) >> GET / ≈ GET /posts(もっと見る) > GET /posts/{id} > POST /comment,POST / > login系」。
- 画像はnginx静的配信済 → **画像はクライアントキャッシュに載せて再取得を消すのが最大の物量削減**（下記③）。
- 動的ページ(GET / 等)は今や1リクエストで「session_start(memcached往復) + get_session_user(DB) + posts(DB) + comments IN(DB) + users IN(DB) + Slim/PSR-7 + テンプレ描画」。**固定費が高い**。ここを削る（①②④）。

## [13:2x 第3R-①] get_session_user が全動的リクエストで毎回 `SELECT *`／セッションにユーザを載せて消す（低リスク・全ホットパス）
(1)現象: `get_session_user`(index.php:122-130)が GET /・/posts・/posts/{id}・comment・post 等**すべての動的リクエストで** `SELECT * FROM users WHERE id=?` を1往復実行（passhash等の不要列込み）。ログインユーザのなりすまし行で必ず走る。
(2)原因仮説: 最ホットパスに毎回1 DB往復＋不要列転送が固定で乗る。RPSが上がるほど積算。
(3)推奨アクション: **ログイン時(`POST /login`,`POST /register`)に `$_SESSION['user']` へ `id,account_name,authority` を丸ごと格納**し、`get_session_user` はDBを引かずセッションの値を返すだけにする。del_flg/authority変更は admin/banned のみ＝低頻度。banされたユーザの即時反映が要るならその経路だけ `del_flg` を確認、もしくは ban時にmemcachedのそのユーザ無効化フラグを立てる。
(4)根拠: 全動的リクエストからDB1往復を除去。GET /・/posts のもっとも数が出る経路に効く。リスクは低（書込み経路が限定的）。MEMO「次の候補(App提案⑤)」の上位互換。

## [13:2x 第3R-②] GET / と GET /posts のフィード結果を memcached キャッシュ（本命の大技・要厳密invalidate）
(1)現象: GET / は毎回 posts/comments/users を引き、make_posts→テンプレで20件分を組み立て直す。フィード内容(最新20投稿＋各最新3コメント＋件数)は**全ユーザ共通**で、変化するのは投稿/コメントが入った時だけ。ヘッダの `me` 部分だけがユーザ別(header.php)。
(2)原因仮説: 読み取り回数 >> 書き込み回数。同一フィードを毎回DBから再構築するのが純粋な無駄。
(3)推奨アクション:
  - make_posts が返す `$posts` 配列（または posts.php をレンダリングしたHTML断片）を memcached にキャッシュ。`me`/csrf はキャッシュ外でテンプレ合成（header.phpは別描画）。
  - **invalidate設計（必須・ベンチの整合チェック対策）**: グローバルな「feedバージョン」整数を memcached に置き、`POST /`（新規投稿）と `POST /comment`（コメント投稿）で必ずインクリメント。読み取りは現バージョンをキー(`feed:v{N}:p{max_created_at}`)に使う。これで投稿/コメント直後の GET は確実に最新を返す（古いキーは参照されず期限切れ）。
  - GET /posts/{id} も `post:{id}:v{M}` でキャッシュし、その post への comment 投稿時のみ M をbump（または該当キー削除）。
  - 単一アプリサーバ＋共有memcachedなので整合は取りやすい。
(4)根拠: 最ホットの読み取り経路から DB往復3〜4本＋make_posts計算＋（断片キャッシュなら）テンプレ描画を丸ごと除去。読み多write少の典型でキャッシュ効果大。**ただしベンチは「投稿/コメントが直後に見える」ことを検査する**ため、invalidateが甘いと fail 直行。まず②は GET /posts/{id} だけ等、影響範囲の小さい所から段階導入し、ベンチで整合(fail0)を確認しつつ広げるのが安全。

## [13:2x 第3R-③] 画像の Cache-Control を immutable + 長期max-ageへ（物量を根本から削る・infra連携）
(1)現象: nginx静的locationは現在 `expires 1d; Cache-Control "public"`(MEMO⑤)。画像URLは `/image/{id}.{ext}` で**id採番＝内容不変(immutable)**。
(2)原因仮説: `1d`でもベンチ実行中(数分)はクライアントキャッシュに載るが、`public`のみだと条件付きGET(If-Modified-Since)で 304 往復が発生しうる。304でもnginx→OSのstatとネットワーク往復は消えない。
(3)推奨アクション: 画像location を `add_header Cache-Control "public, max-age=31536000, immutable";` に。`immutable` でブラウザ/ベンチHTTPクライアントは**再検証すら行わず**ローカルキャッシュを使う→同一画像の再GET(304含む)が消滅。最ページ閲覧で1ページ20枚のうち既見分の往復がゼロに。（設定実体は infra係 isucon.conf 担当。app観点としてURL不変性ゆえ安全と保証できる）
(4)根拠: 画像はリクエスト物量の最大要素。再取得・304往復を消すとnginx/ネットワークの負荷が大きく下がり、空いたCPU/帯域が動的処理に回る。private-isu高得点帯の定石。要 findings_infra.md と調整。

## [13:2x 第3R-④] Slim/PSR-7 + PhpRenderer の固定オーバーヘッド（CPU律速の本丸・中〜大工事）
(1)現象: 全動的リクエストで Slim のルーティング、PSR-7 Request/Response 生成、無名クラスView生成、layout.php→header→viewの多段 `require` が走る。1枚あたりは小さいが高RPSで積算し、170k帯では**PHP-FPMのCPUがこの固定費で食われている**可能性が高い。
(2)原因仮説: DBを削り切った後の動的ページの残コストはフレームワーク/テンプレ実行が主。
(3)推奨アクション（効果見込み順・工数大）:
  - **OPcache が default On（MEMO仕上げ7）であることは前提。** JITは効果無しと判定済なのでOFFのままで良い。
  - 最ホットの GET / は②のフラグメント/全文キャッシュで「テンプレ実行自体を回避」するのが一番効く（②と統合）。
  - 余力があれば GET /image 以外でも、本当に重い経路のみ Slim を介さない薄い処理に切り出す（大工事のため②③の後）。
(4)根拠: CPUプロファイル(infra係)で php-fpm の self時間がフレームワーク/テンプレ関数に集中していれば本項が当たり。②のキャッシュで大半が回避できるため、独立施策としての優先度は②の後。

## [13:2x 第3R-⑤] GET / と /posts の `LIMIT 100` を縮小（軽微・即効）
(1)現象: del_flg除外のため母数100行を取得し make_posts で20件に絞る(index.php:357,373)。削除ユーザは id%50==0 ＝約2%なので、20件表示に必要なのは概ね21件、最悪でも余裕をみて~40件。
(2)原因仮説: 100行→実質20行で、80行ぶんの行転送・PHP配列構築が無駄。
(3)推奨アクション: `LIMIT 100`→`LIMIT 40` 程度へ縮小（FORCE INDEX idx_created_at はそのまま）。ベンチで20件揃うこと(fail0)を確認。
(4)根拠: 行転送/メモリ/ループを~2.5倍削減。軽微だが無リスクに近い即効。

## [13:2x 第3R-⑥] /@{account_name} の冗長クエリ（低traffic・DB係提案と重複）
(1)現象: posts WHERE user_id を **2回**実行(index.php:563 make_posts用 と 570 post_ids用)。さらに comment_count と commented_count を別COUNTで取得。
(2)原因仮説: L563の結果から id を array_column すれば L570 は不要。
(3)推奨アクション: L563取得結果を流用し L570-572 を削除。post_count はその件数。（MEMO「次の候補(DB提案③)」と同一。低traffic経路なので優先度は②③より下）
(4)根拠: 1リクエストあたりpostsクエリ1本削減。ユーザページは数が出ないため効果は限定的。

## [13:2x 第3R] 40万点への戦略まとめ（実装係向け・効果/リスク順）
1. **③ 画像 Cache-Control immutable+長期**（最大物量削減・低リスク・infra連携）: まず入れる。
2. **① get_session_user をセッション格納で消す**（全動的経路から1 DB往復除去・低リスク）。
3. **② memcached フィード/詳細キャッシュ**（最ホット読み取りをDB+描画ごと回避・**高効果だが要厳密invalidate**）: /posts/{id}など小範囲から段階導入しfail0確認しつつ拡大。
4. **④ フレームワーク/テンプレ固定費削減**: 大半は②で回避。残りはプロファイル次第。
5. **⑤ LIMIT 100→40**（無リスク即効・小）。
6. **⑥ /@user の冗長posts query削除**（小）。

40万点チームの大技推測: (a)画像をimmutable長期キャッシュ＋nginx sendfileで再取得を消し物量を激減、(b)read-heavyなトップ/詳細をmemcachedキャッシュしDB・PHP描画を回避、(c)php-fpm worker数をCPUコアに最適化しnginx/mysqlとCPUを取り合わない配分、の組合せが濃厚。アプリ側で効くのは(a)(b)＝本ラウンドの③②。要 findings_db.md / findings_infra.md と突き合わせ（特に③のnginx設定と④のCPUプロファイル）。
