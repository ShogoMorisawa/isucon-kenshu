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

---

# 第4ラウンド調査（大会 369,640 / 出発292,146 → 目標 500,000）

確認した適用済(大会実証): 断片HTMLキャッシュ(pf:{id}:c{count})、comment_count非正規化、遅延セッション、主要GET(/ /posts /posts/{id} /@user /image)のSlimバイパス高速パス。
**律速は引き続き php-fpm CPU=80%（mysqld 29%は非律速）**。よって本ラウンドは一貫して「**1リクエストあたりのPHP実行命令数とブロッキング往復(DB/memcached)を削る**」に絞る。

## 計測で判明した前提（今回 php -m / php -i で確認）
- **APCu は未インストール**（`php8.3-apcu` は apt candidate 5.1.22 あり、入れれば使える）。現状リスト描画の断片は**memcached(TCP往復)** で getMulti している。
- opcache: enable=On / memory=128M / max_accelerated_files=10000 / interned_strings_buffer=**8M(小)** / validate_timestamps=On(revalidate_freq=2) / JIT=off / **preload=未設定**。
- igbinary 拡張あり（memcachedのシリアライザに使えるが、断片は文字列なので効果薄）。

## [16:1x 第4R-①] 断片キャッシュを memcached → APCu(プロセス共有メモリ)へ（最有力・要拡張導入）
(1)現象: `build_list_html`(index.php:287-357) は最ホットの GET / で毎回 `feed_cache()->getMulti(~21キー)` を実行。memcachedは127.0.0.1でもTCP(orunix socket)往復＝**php-fpmワーカーが同期ブロックする**。全リスト描画(/ /posts /@user)で発生。
(2)原因仮説: 単一アプリサーバ構成なのに、プロセス内で完結できるキャッシュをネットワーク往復で取りに行っている。getMultiのシリアライズ/デシリアライズ＋ソケットI/Oが php-fpm CPU(80%律速)に乗る。
(3)推奨アクション: `php8.3-apcu` を導入し、断片キャッシュを **APCu(`apcu_fetch`/`apcu_store`)** に置換。APCuはfpm worker間で共有されるmmapメモリで、getは数µs・ネットワーク往復ゼロ。キー体系(`pf:{id}:c{count}`)・TTL・自動invalidate(comment_count変化でキー変化)はそのまま流用可能。`/initialize` の flush は `apcu_clear_cache()` に。**単一サーバなので一貫性問題なし**。
(4)根拠: 律速の php-fpm から「リスト描画ごとのmemcached往復(21キー分のI/O+デシリアライズ)」を丸ごと除去。最頻経路に効くため寄与大の見込み。導入は infra係（拡張install+`systemctl restart php8.3-fpm`）。※APCu不可なら次善として memcached を unix socket 化＋igbinaryシリアライザで往復コスト低減。

## [16:1x 第4R-②] 高速パスを AppFactory::create() より前へ移動（Slim Appの生成を毎回スキップ・零リスク）
(1)現象: 高速パスのバイパス分岐(index.php:386-477)は `AppFactory::setContainer()` + **`AppFactory::create()`**(234-235)の**後**に置かれている。GET / 等の高速パスは最後に `exit` するので `$app->get(...)` 登録(479-825)や `$app->run()` は走らないが、**`AppFactory::create()`（App本体・Router・MiddlewareDispatcher等の生成）は全リクエストで毎回実行されている**。
(2)原因仮説: 高速パスの狙いは「Slimを通さない」ことだが、Slim Appオブジェクトの構築コスト自体は削れていない。最頻リクエストで無駄な生成が残存。
(3)推奨アクション: バイパス分岐ブロックを `$container->set('helper',...)` 完了直後（234行 AppFactory手前）へ移動する。高速パスが使うのは `$container->get('helper')`/`get('flash')` のみで Slim App は不要。フォールスルー時のみ `AppFactory::setContainer/create()` 以降を実行する構成にする。出力は不変（バイト一致は維持）。
(4)根拠: 最頻GETから Slim App + Router + Middleware の生成(数十オブジェクト/回)を恒久的に除去。コストは小さくても全hotリクエストに掛かるため積算で効く。**コード移動のみ・零リスク**。

## [16:1x 第4R-③] build_list_html の「毎回の users IN クエリ」を排除（DB往復をhotパスから除去）
(1)現象: `build_list_html`(index.php:290-291) は毎回 `$user_cache=[]` を作り `preload_users()` で `SELECT * FROM users WHERE id IN (~21)` を実行。これは投稿著者の **del_flg 判定（表示20件の選別）** のため。断片が全ヒットでもこのユーザクエリは必ず走る。
(2)原因仮説: 断片HTMLには著者名が既に焼き込まれているので、全ヒット時にユーザ情報が要るのは「del_flg=1の著者の投稿を除外する」目的のみ。users全体は~1000行・変化は register と admin/banned のみ＝ほぼ不変なのに毎回引いている。
(3)推奨アクション: **banされたユーザID集合(`del_flg=1`、初期~2%=約20件)** を APCu/memcached にキャッシュ（`POST /register` と `POST /admin/banned` で更新）。`build_list_html` の選別は「著者idがban集合に**無い**投稿を上位20件」で判定し、users IN クエリを撤廃。断片ミス時に必要な著者だけ個別取得（稀）。
(4)根拠: 全リスト描画(/ /posts /@user)から同期DB往復を1本除去＝php-fpmワーカーのブロック時間短縮（mysqld負荷も微減）。del_flgはban経路のみ変化＝invalidateが単純で安全。中リスク（選別ロジックの正当性をfail0で要確認）。

## [16:1x 第4R-④] 全リクエスト共通の固定費削減：opcache.preload と interned_strings_buffer
(1)現象: `require 'vendor/autoload.php'`(index.php:8) は全リクエストで走り、Slim/Flash/PSR-7 等の vendor クラスを初回参照時にオートロード（ファイルstat＋include）する。opcache.preload は未設定、interned_strings_buffer=8Mと小さい。
(2)原因仮説: OPcodeはキャッシュ済でも、クラスのオートロード解決（composer ClassLoader のmap探索＋ファイルstat）とクラス初期化は実行毎に発生しうる。preloadで起動時に共有メモリへ常駐させれば毎回の解決を省ける。
(3)推奨アクション（infra係 php.ini）: `opcache.preload` に vendor の主要クラス(Slim系/PSR-7/DI/Flash)をロードするpreloadスクリプトを設定。併せて `opcache.interned_strings_buffer` を 16〜32M に。**注意**: preloadはコード変更後 fpm restart 必須（footgun）。MEMOの「JIT/vt=0は変動内で不採用」とは別物（preloadはクラス常駐化で趣旨が違う）だが、効果は中程度想定なので A/B で変動超を確認してから採用。
(4)根拠: 全リクエストの固定費(オートロード解決)を削る。律速がphp-fpm CPUなので固定費削減は素直に効く可能性。ただし高速パスは既に使用クラスが少なく、効果は①②③より小さい見込み→優先度は中。

## [16:1x 第4R-⑤] GET /posts/{id} 詳細はキャッシュ無しで毎回 make_posts（コメント投稿のたびに叩かれる）
(1)現象: 高速パス /posts/{id}(index.php:400-414) は `make_posts(all_comments=true)` を毎回実行＝post 1クエリ＋comments全件 IN 1クエリ＋user preload。`POST /comment` が完了後この詳細へ redirect するため、コメント追加のたびに詳細が叩かれる。stage1の「データ構造キャッシュ」は-2.7%で不採用だったが、それは memcached往復+unserialize が相殺した結果。
(2)原因仮説: 詳細ページは「投稿本体(不変)」＋「コメント列(comment_countで変化)」。本体部分は断片化でテンプレ実行を回避できる。
(3)推奨アクション: 詳細を `pd:{id}:c{comment_count}` キーで **APCu** 断片キャッシュ（①でAPCu導入後）。本体＋コメント列をまとめて1断片に。comment_count増分で自動invalidate。memcachedで失敗した理由(往復+unserialize)はAPCu化＋HTML文字列キャッシュ(unserialize不要)で解消する可能性。①の後に再検証する価値あり。
(4)根拠: コメント投稿シナリオで叩かれる詳細のテンプレ実行/コメントクエリを削減。①(APCu)前提なので順序は① → ⑤。中優先。

## [16:1x 第4R-⑥] 高速パス GET / の細かな固定費（軽微・低リスク）
(1)現象/推奨:
  - **flashの遅延化**: 高速パス GET /(396行)は毎回 `$container->get('flash')->getFirstMessage('notice')` を呼び Slim\Flash を生成・オートロード。flashは「直前のredirectでsessionに積まれた通知」専用なので、**session未開始(匿名cookie無し)なら必ず空** → `session_status()===ACTIVE` の時だけ flash を引き、それ以外は `null` 即返しにすれば Slim\Flash の生成/オートロードを匿名hotパスから除去できる。
  - **先頭の静的fチェック**(index.php:12-24 `is_file($file)`)は動的パス(/ /posts 等)でも毎回1 statを発行。nginxが静的を直配信する構成なので、PHP到達時点で静的の可能性は低い。明確に動的なメソッド/パスでは stat を省く余地（軽微）。
  - **postsクエリの列**: 全ヒット時に必要なのは選別用の id/user_id/comment_count のみ。body/mime/created_at はミス時の断片描画にしか使わない。`SELECT id,user_id,comment_count`＋ミス分だけ本体取得にすると、毎回40行ぶんの body テキスト転送を削減できる（小）。
(2)根拠: いずれも単体は小さいが、最頻GET /の固定費。①②の後の積み増しとして。

## [16:1x 第4R] 50万への戦略まとめ（実装係向け・効果/リスク順）
1. **① 断片キャッシュを APCu 化**（最有力。memcached往復をhotパスから除去）。`php8.3-apcu`導入が前提＝infra係と連携。APCu不可なら memcached unix socket+igbinary で次善。
2. **② 高速パスを AppFactory::create() の前へ移動**（零リスク・コード移動のみ・全hotリクエストでSlim App生成を回避）。**まず即やる**。
3. **③ build_list_html の users IN を ban集合キャッシュで撤廃**（hotパスから同期DB往復1本除去・中リスク・fail0要確認）。
4. **⑤ /posts/{id} 詳細を APCu 断片キャッシュ**（①の後に再挑戦。コメント投稿シナリオに効く）。
5. **④ opcache.preload + interned_strings_buffer 拡大**（infra係・全固定費削減・A/Bで変動超確認）。
6. **⑥ flash遅延化・列削減等の細かな固定費**（軽微・低リスク・積み増し）。

50万チームの大技推測（アプリ側）: (a)**プロセス内(APCu/OPcache preload)で完結させ、memcached/DBへの同期往復を最頻パスからほぼ消す**、(b)Slim等フレームワーク生成を完全に回避した薄いフロントコントローラ、(c)リスト/詳細をHTML断片で持ちテンプレ実行を実質ゼロに、の徹底。**律速がphp-fpm CPUなので、残る勝負は「1リクエストのPHP命令数とブロッキングI/O回数をどこまで0に近づけるか」**。本ラウンドの①②③が直撃ターゲット。要 findings_infra.md（APCu導入・preload・fpm worker配分）と突き合わせ。

## [16:3x 第4R-補] Go言語移植の是非（律速がphp-fpm CPUなので「効く方向」だが大博打）
(1)現象/前提: 律速は php-fpm CPU=80%（PHPインタプリタ実行＋リクエスト毎bootstrap）。リポジトリに Go実装あり（`webapp/golang/app.go`, ビルド済`app`, `isu-go.service`=disabled）が**完全に素のリファレンス**＝画像DB BLOB配信(`getImage`が`post.Imgdata`を返す)・makPostsがper-post COUNT+コメントN+1・FORCE INDEX無し・アプリ内キャッシュ無し。
(2)なぜGoが速くなりうるか（方向性は正しい）:
  - **リクエスト毎bootstrapが消える**: PHPは毎回 autoload＋Container＋(AppFactory)＋opcode実行。Goは常駐プロセスで固定費ほぼ0。今の第4R-②④で削ろうとしている固定費が構造的にゼロになる。
  - **アプリ内キャッシュが無料**: 第4R-①でAPCu化を狙っている「memcached往復の排除」は、Goなら `sync.Map`/mapで**ネットワーク往復もシリアライズも無し**＝ネイティブ。これが律速のphp-fpm CPUを直撃する核心。
  - **コンパイル済＋goroutine＋DBコネクションプール**で同一処理あたりCPUサイクルが大幅に小さい。private-isu高得点帯は実際Goが定番。
  → **CPUが唯一の壁である以上、同じアルゴリズムをGoで実装すれば天井は確実に上がる**（500k超も射程）。
(3)ただしリスク/コスト（重要）:
  - リポジトリのGoは**未最適化ベースライン**。素のまま切替えると「PHP切替直後＋BLOB配信」相当へ一旦**大幅後退**する。369kを超えるには全最適化の再移植が必要。
  - 救い: **DB側資産(index4本・comment_count列)とnginx側資産(画像静的ファイル・immutable cache・gzip off)・MySQL/fpm設定は言語非依存でそのまま流用可能**。再実装が要るのは app.go のロジックのみ（posts LIMIT40+FORCE INDEX、コメント/ユーザのIN一括、ban集合、投稿リストのインメモリ断片キャッシュ、画像はファイル書出し＆nginx直配信）。
  - レスポンスHTMLのバイト一致再達成とfail0再検証に相応の工数。テンプレ(`golang/templates`)はPHP views と別物。
(4)推奨アクション（意思決定）:
  - **残り時間が十分あるなら Go移植が最高天井**。手順: ①isu-go用にDB/nginx資産はそのまま、②app.goへ既存PHP最適化を移植（特にインメモリ断片キャッシュ＝APCu相当をmapで）、③コンテストベンチで PHP現行369k と A/B、④下回れば即 php-fpm へ戻す（両service togglableなので可逆）。
  - **残り時間が少ない／確実性重視なら**、第4R-①②③（APCu化＋bootstrap削減＋ban集合）でPHPを詰める方が低リスク。これだけでも ~450-500k は狙える可能性。
  - 中間案: まず②(零リスク)と③をPHPで入れて確実に積み、並行してGoプロトタイプを別端末で検証→コンテストベンチで上回ったら切替。
(5)根拠: 律速＝PHPインタプリタCPU。Goは「per-request bootstrap」「memcached往復」「フレームワーク生成」という第4Rで削ろうとしている固定費を**構造的に全部ゼロ化**する。方向は完全に正しいが、未最適化ベースラインからの再移植コストと可逆性をどう見るかの経営判断。要 MEMO（実装係の残時間/工数感）と突き合わせ。

---

# [17:0x Go分析] なぜGoが大会でPHPに負けるのか（PHP392k > Go278k、ローカルはGo228k>PHP195k）

go-port の `webapp/golang/app.go` 全行＋templates を精読。**逆転の主因はほぼ1点に特定できた**。以下、効果(=伸びしろ)順。

## 【主因・致命傷】テンプレートを毎リクエスト `template.ParseFiles` で再パースしている
(1)現象: `getIndex`(640-666)・`getAccountName`・`getPosts`・`getPostsID`・`getLogin`等が**毎リクエスト**
```
template.Must(template.New("layout.html").Funcs(fmap).ParseFiles(layout.html, index.html, posts.html, post.html)).Execute(...)
```
を実行。`ParseFiles` は **4ファイルをディスクから読み、Goテンプレートを毎回パース＆コンパイル**する。断片キャッシュ(`.Rendered`)があっても、それを差し込む**外側テンプレートのパースは毎回まるごと再実行**。しかも `posts.html` は `{{.Rendered}}` を出すだけ(確認済)なのに `post.html` まで毎回parse（実行すらされない無駄parse）。
(2)なぜ致命的か＝**local→contest逆転の正体**:
  - PHPは views を `require` するだけ＝**OPcacheがコンパイル済opcodeをキャッシュ**し、テンプレ「コンパイル」コストは実質ゼロに償却される。Goの`ParseFiles`は**どこにもキャッシュされず毎回パース**＝PHPに無くGoだけにある巨大な固定費。
  - パースは**CPU重 + 大量アロケーション**(パースツリー生成)。→ リクエスト毎にGCゴミを量産。
  - **ローカル**: benchmarkerが同居CPU67%を食い総RPSが頭打ち→このparse固定費が律速に達せず、Goのコンパイル速度/no-bootstrap優位が僅差で勝つ(228>195)。
  - **大会**: benchmarker分離でアプリがCPU専有＋高RPS。**parse固定費とGCがRPSに比例して増大**し、ここが壁になる。PHPはOPcacheでparse≒0＋PHP-FPMはGC無し(リクエスト終了でメモリ一括解放)のため高負荷で素直にスケール。→ **高負荷ほどGoのparse/GC固定費が効いてPHPに抜かれる**。これが392k vs 278k(-29%)の最大要因。
(3)推奨アクション: **テンプレートを起動時に1度だけパースして package変数 or `map[string]*template.Template` に保持**し、各ハンドラは `Execute` のみ呼ぶ。断片用 `postFragTmpl` が既に `sync.Once` でやっているのと同じ手法を全テンプレに展開するだけ。さらにcache-hit主体の `/`・`/posts` では `post.html` をparse対象から外す。
(4)根拠: 最頻GETの毎回parse＋アロケを除去＝PHPがOPcacheで得ている償却をGoでも得る。**これ単体でlocalの優位が大会でも出る公算が高い**。最優先。

## 【移植漏れ①】遅延セッションがGoに無い（PHPは匿名で session 往復をスキップ）
(1)現象: PHPは「cookieがある時だけ session_start」で**匿名GETのmemcached往復とSet-Cookieを丸ごと省略**(第4R確定施策)。Goの `getSessionUser`→`getSession`→`store.Get`(gsm/memcache) は**常に実行**され、cookie付きリクエストでは毎回 memcached からセッションをロード。`getIndex` は getSessionUser/getCSRFToken/getFlash でsessionを参照（gorillaのリクエストレジストリで1リクエスト1ロードに集約はされるが、ロード自体は走る）。
(2)原因仮説: PHPで効いた「匿名はsession触らない」最適化がGo未移植。大会は認証済主体とはいえ、匿名/初回や静的近傍でも一律にセッション機構を起動している分の固定費差。
(3)推奨アクション: cookie(`isuconp-go.session`)が無いリクエストでは `store.Get` を呼ばず空Userで通す薄いガードを入れる。書込経路(login/register/comment/post)はそのまま。
(4)根拠: PHPに有ってGoに無い固定費を埋める。主因(テンプレ)ほどではないが移植漏れの穴。

## 【移植漏れ②/Go特有】インメモリキャッシュが無制限増加（TTL/eviction無し）→ 長時間走行でGC劣化
(1)現象: `fragmentCache`/`userCache` は `sync.Map` で **TTLもeviction も無い**。`pf:{id}:c{count}` はコメントが付くたび `comment_count` が変わり**新キーが増え続け、旧キーは永久に残る**(`clearAppCaches` は /initialize 時のみ)。大会の高コメント負荷では1投稿が c0,c1,c2... と多数の死蔵エントリを生む。
(2)原因仮説: PHP版は memcached(TTL600 + LRU eviction)で古い断片を自動破棄。Goの sync.Map は**捨てない**ため、ベンチ進行とともにマップが肥大→**GCが全エントリを走査するコストが時間とともに増加**＝走行後半でスループット低下。sync.Map は高頻度Storeで内部dirty昇格コストも高い。「ヒット率」自体はsync.Map(プロセス内共有)で問題ないが、**肥大によるGC圧**が差を生む。
(3)推奨アクション: (a)断片キャッシュにサイズ上限＋LRU/世代破棄を入れる（または同一post_idの旧comment_countキーをコメント時にdelete）。(b)少なくとも `comment` 投稿時に該当 `pf:{post_id}:c{old}` を削除し死蔵を防ぐ。(c)`GOGC` 調整(例: 200〜)でGC頻度を下げるのも有効(infra)。
(4)根拠: contestの長時間・高write下でGoだけ進行劣化する典型。PHPはeviction有りで劣化しない。逆転を助長する第2要因の候補。

## 【Go特有の非効率】csrf合成 `strings.ReplaceAll` を断片ごとに実行（全ヒットでもアロケーション）
(1)現象: `buildListPosts`(305, 368) は**投稿1件ごと**に `strings.ReplaceAll(fragment, "@@CSRFTOKEN@@", csrf)` を実行＝20件で20回のフルスキャン＋20個の新規文字列アロケーション。**全ヒット時でも毎リクエスト発生**。PHP版は結合後の `$html` 全体に対し `str_replace` を**1回**(第4R, index.php:356)。
(2)原因仮説: Goは断片ごと置換でアロケーションがPHPの20倍。GCゴミ増。「無視できるか」への答えは**高RPSでは無視できない**(主因テンプレより小だが積算)。
(3)推奨アクション: 断片を連結してから1回 `ReplaceAll`、もしくは csrf を `@@CSRFTOKEN@@` 文字列置換でなく **テンプレ側のフィールド**として後段layoutテンプレで描画（断片には出さない）。最善は「断片からcsrf入力を外し、ページ末尾の hidden input でcsrfを1回だけ出す」設計。
(4)根拠: アロケーション/GC削減。主因対策後の積み増しとして効く。

## 【副次】その他のGo側の穴（優先度中〜低）
- `getPostsID`(813) は `SELECT *`＝imgdata列(現在は空''だが列取得は発生)を引く。PHP高速パスはカラム限定。`SELECT id,user_id,body,mime,created_at,comment_count` に限定推奨。
- `getPostsID` 詳細ページは**断片キャッシュ無し**で毎回 makePosts＋全テンプレparse。`POST /comment` 後に必ず叩かれる経路なので、テンプレ事前パース(主因対策)が特に効く。将来は `pd:{id}:c{count}` 断片化も。
- `getImage` フォールバック(962) は `os.ReadFile`→`w.Write` で全読込。`http.ServeFile`/`ServeContent` なら sendfile + 自動 Last-Modified/Range/304。ただし通常はnginx直配信なので影響小。
- GOMAXPROCS/`GOGC` などランタイム調整は infra係領域。CPU専有環境ではGC設定が効く。

## 結論（実装係向け・大会で逆転を取り返す順）
1. **テンプレートを起動時1回パースして使い回す（最優先・主因）**。これで local の Go優位が大会でも出る公算大。
2. **インメモリキャッシュのeviction/上限＋GOGC**（長時間走行のGC劣化を止める）。
3. **遅延セッション移植**（匿名のsession往復スキップ）。
4. **csrf置換を1回化 or テンプレフィールド化**（アロケ削減）。
5. 副次: getPostsID の SELECT * 限定、詳細ページのテンプレ事前パース、getImageのServeFile。

要点: **「ローカルで速いのに大会で負ける」＝高RPS・長時間でしか露呈しない固定費(=毎回テンプレparse)とGC劣化(=無制限キャッシュ)が犯人**。PHPはOPcache(parse償却)＋FPMのGC無し＋memcached eviction で高負荷に強い。Goでも 1.テンプレ事前パース と 2.キャッシュeviction を入れれば、構造的優位(no-bootstrap/in-process cache)が大会スコアに反映されるはず。要 findings_infra.md（GOGC/GOMAXPROCS）・findings_db.md（idx_feed が covering か）と突き合わせ。

---

# [18:0x Go第2R] Go 479,617 → 目標 693,053（トップ, あと約1.4倍）の次のボトルネック

確認済(適用済): テンプレ事前パース(`cachedTmpl`/`sync.Map`)、断片キャッシュ＋上限eviction(`fragStore`/fragCacheLimit)、ban集合、digest native、unix socket、interpolateParams、コネクションプール(64/64)。
→ テンプレparseが消えた今、**最頻GET(/ ・ /posts)で残る同期コストとアロケーション**が次の壁。コードから推定する律速を効果順に。

## 計測準備（最優先・推測を裏取りする土台）
- **pprof 未導入**（`net/http/pprof` 非import確認）。`GOGC`/`GOMAXPROCS` 未設定（既定 GOGC=100, GOMAXPROCS=2）。CPUは2コア。
- 推奨: `import _ "net/http/pprof"` を入れ、別goroutineで `http.ListenAndServe("localhost:6060", nil)`。ベンチ中に `go tool pprof http://localhost:6060/debug/pprof/profile?seconds=20` を取得。**以下①〜③のどれが実際に重いかを確定**してから着手するのが最短（実装係依頼）。

## 【最有力①】セッションが認証済リクエスト毎に memcached 往復している
(1)現象: `getSession`→`store.Get`（gsm = gorilla-sessions-**memcache**）は、cookie付き(=認証済)リクエストで**毎回 memcached GET＋gob デコード**してセッションを読む。`getIndex` 等の最頻GETは getSessionUser でこれを必ず通る（gorillaのper-requestレジストリで1リクエスト1回には集約されるが、**1往復は残る**）。MEMOより「GET /は認証済が主」＝大半がこの往復を払っている。
(2)原因仮説: テンプレparseを消した今、ホットパスに残る唯一のネットワーク同期I/Oがこのセッションロード。memcached往復(数十〜100µs)＋gobデコードのアロケが全認証GETに乗る＝スループット天井を直接縛る。userCache(in-process)でDBは消えたが、**セッションだけ外部往復のまま**。
(3)推奨アクション（いずれか）:
  - **(本命) セッションを署名cookieに格納**（gorilla `CookieStore`/`securecookie`）。session中身は user_id＋csrf_token のみで極小。cookieに入れればサーバ側ストアが不要＝**memcached往復が完全消滅**、HMAC検証＋小gobデコードのみ（CPU内・ゼロ往復）。/initialize やログインの整合は維持。リスク中（cookie形式変更。ベンチは毎回ログインし直すので実害小）。
  - **(低リスク) session-id→デコード済セッションを in-process `sync.Map` キャッシュ**。同一ユーザは同cookieで多数リクエストするためヒット率高。login/logout/register でそのidを無効化。memcached往復を再訪リクエストから除去。
(4)根拠: 最頻パス唯一の外部同期I/Oを除去。in-process化はGoの構造的優位そのもの。**①が今ラウンド最大の伸びしろ候補**。pprofで `gomemcache`/`gob` がCPU上位なら確定。

## 【②】リストページの html/template.Execute と csrf合成(ReplaceAll×20)を排除
(1)現象: 
  - 断片はすでに完成HTML(`Rendered`)なのに、`getIndex`/`getPosts`/`getAccountName` は **html/template.Execute** で posts.html を range 実行（reflection＋html/templateの安全機構が全リクエストで走る）。
  - `buildListPosts`(321,384) は**投稿1件ごとに** `strings.ReplaceAll(frag, "@@CSRFTOKEN@@", csrf)`＝20件で**20回フルスキャン＋20本の新規string確保**。**全ヒット時でも毎回発生**（post.html の csrf は comment フォームの hidden 1箇所=L30、確認済）。
(2)原因仮説: テンプレparse除去後、リスト描画の残コストは「Execute のreflection」＋「per-fragment ReplaceAllのアロケ」。高RPSでGCゴミと化す。
(3)推奨アクション（段階）:
  - **(即) csrf置換を1回化**: 断片を `strings.Builder` で連結→**連結後の全体に1回だけ ReplaceAll**（PHP版と同じ）。20アロケ→1。
  - **(踏み込み) リストページを html/template を介さず生バイト連結で組む**: 断片HTMLは完成済なので、ヘッダ(me/ログインリンク)＋投稿フォーム(csrf)＋連結済リスト＋フッタを `strings.Builder`/`[]byte` で直結し `w.Write`。reflectionベースのExecuteを最頻パスから除去。出力バイト一致を要diff検証。
  - **(最大) 連結済みリスト本体HTMLを丸ごとキャッシュ**: 可視投稿の (id,comment_count) 並びを署名にしたキーで「連結済み本体(csrfプレースホルダ入り)」を sync.Map キャッシュ。GET /全ヒット時は「軽量クエリ＋本体1取得＋csrf 1回置換＋ヘッダ合成」だけになり、断片ループ自体も消える。
(4)根拠: Execute のreflectionと20回アロケを削減。②(即)は零〜低リスクで今すぐ。生バイト化／本体キャッシュは中工数だが効果大。

## 【③】sync.Pool でバッファ再利用＋GOGC調整（GC圧削減・CPU専有環境で効く）
(1)現象: 各リクエストで `strings.Builder`(renderFragment)、ReplaceAll の新規string、`[]Post`/`map[int][]Comment`/userMap 等を都度確保。`renderFragment` の Builder も毎回new。GOGC=既定100で、2コア専有・高RPSだとGCがCPUを奪う。
(2)原因仮説: 大会CPU専有＝アロケ由来のGCがそのままスループット損。PHP-FPM(GC無し)に対するGoの弱点を埋め切れていない。
(3)推奨アクション: (a)レスポンス組み立て用 `strings.Builder`/`bytes.Buffer` を `sync.Pool` で再利用（②の生バイト化と併せると効果大）。(b)`GOGC=200`〜`400` 程度に上げGC頻度を下げる（メモリに余裕がある前提。infra係と。MEMOのメモリ枠要確認）。(c)`buildListPosts` の `out`/`keys`/`missIdx` 等は容量プリアロック済だが、ホットなら Pool 化検討。
(4)根拠: GCサイクル削減＝実効CPUが増える。①②でアロケ源を減らした上で GOGC を上げると相乗。pprofの `runtime.gcBgMarkWorker` 割合で効果を確認。

## 【④】GET /posts/{id} 詳細：断片キャッシュ無し＋`SELECT *`（コメント投稿後に必ず叩かれる）
(1)現象: `getPostsID`(836) は `SELECT *`(imgdata列含む。現在空だが取得は走る)＋`makePosts(all_comments=true)` を毎回実行＝本体1+コメント全件IN+ユーザIN。`postComment` 後に必ず `/posts/{id}` へredirectするため、**コメント投稿シナリオで高頻度**に叩かれる経路。断片化されていない。
(2)推奨アクション: (a)`SELECT id,user_id,body,mime,created_at,comment_count` にカラム限定（imgdata除外）。(b)本体＋コメント列を `pd:{id}:c{comment_count}` で断片キャッシュ（comment_count増分で自動invalidate）。コメント追加→count変化→新キーで再生成＝即時可視。
(3)根拠: 高頻度経路のDB往復とExecuteを削減。①②③ほどではないがコメント多シナリオで効く。

## 【⑤】GET /@user の冗長クエリ（中traffic）
(1)現象: `getAccountName` は posts を2回引く（714: 一覧用 results＝LIMIT無で全件、734: post_ids/post_count用）。さらに user別 COUNT(727) と commented_count(755) を毎回DB集計。
(2)推奨アクション: 734の再取得を 714 の results から `len`/idで賄い削除。postCountも results 由来に。COUNT系はユーザページ表示頻度が中なら user単位でキャッシュ可（comment投稿でinvalidate）。
(3)根拠: ユーザページのDB往復削減。優先度は①〜④の下。

## 補足: 通知/ポーリング系endpointは無い
- ルートは login/register/logout/`/`/posts/posts{id}/`/`(POST)/image/comment/admin/banned/@user のみ。**自動ポーリングや /fetch 系は存在しない**。「もっと見る」= `GET /posts?max_created_at=` はボタン起因のページングで、列処理は `/` と同じ `buildListPosts` 経由＝①②の改善がそのまま効く。新規の高頻度endpoint対策は不要。

## 結論（実装係向け・480k→690kへ効果順）
1. **① セッションの memcached 往復を消す**（cookie格納 or session in-processキャッシュ）。最頻パス唯一の外部同期I/O＝最大の伸びしろ。
2. **② リスト描画の脱html/template＋csrf置換1回化**（即: ReplaceAll 1回化 / 踏み込み: 生バイト連結 / 最大: 連結済本体キャッシュ）。reflectionと20アロケを除去。
3. **③ sync.Pool＋GOGC調整**（①②でアロケ源を絞った上でGCを減らす）。
4. **④ /posts/{id} 詳細の断片キャッシュ＋SELECT列限定**（コメント投稿シナリオ）。
5. **⑤ /@user 冗長クエリ削減**。
0. **（先に）pprof導入で①〜③のどれが実際に重いかを確定**してから着手。

69万チームの大技推測（アプリ側）: (a)**セッションをcookie/オンメモリ化し外部ストア往復をゼロに**、(b)**ホットページを html/template に通さず事前レンダリング断片の生バイト連結で組み、レスポンス本体をまるごとキャッシュ**、(c)`sync.Pool`＋`GOGC`チューニングでGCを限界まで抑制、(d)2コアをアプリが専有できるよう nginx/mysql とのCPU配分最適化(infra)。本ラウンドの①②③が直撃。要 findings_infra.md（GOGC/GOMAXPROCS/CPU配分）と突き合わせ。
