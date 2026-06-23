# findings_db.md — DB調査係 専用（読み取り専用担当）

> ルール: ここにだけ書く。ソース編集・ベンチ・restart・DB変更は禁止。
> 信号機(MEMO.md)が RUNNING の間は重いクエリ・プロファイルを控える。
> 観点: スロークエリ、EXPLAIN、不足インデックス、N+1の温床テーブル、MySQL統計。

## 環境・統計（2026-06-23 計測, DB=isuconp, 信号機IDLE時）
- テーブル行数: comments **99,922** / posts **9,401**(data 1,204MB ※imgdata blob) / users 1,005
- 既存インデックス: PRIMARY(各table id), users.account_name(UNIQUE) のみ。**posts/comments にセカンダリインデックスが一切無い**
- innodb_buffer_pool_size = **128MB**（posts実体1.2GBに対し極小）
- RAM 3.8GB(空き約218MB) / CPU 2コア
- slow_query_log = **OFF**, long_query_time = 10s（このままだと計測できない）

---

## 調査メモ（効果が大きい順）

## [計測時] ① comments にインデックスが無くトップページが全件スキャンの嵐【最優先・最大効果】
- (1)現象: `make_posts`（index.php:130-160）が投稿1件ごとに
  `SELECT COUNT(*) FROM comments WHERE post_id=?` と
  `SELECT * FROM comments WHERE post_id=? ORDER BY created_at DESC LIMIT 3` を実行（典型的N+1）。
  EXPLAIN結果は両方 **type: ALL / rows: 99,922 / Using filesort**。
- (2)原因仮説: comments に post_id インデックスが無いため、1クエリごとに10万行フルスキャン+filesort。
  トップページは POSTS_PER_PAGE(20件) 表示につき COUNT+本体で最大40回 ×10万行スキャン。これがスコア律速の本命。
- (3)推奨アクション（実装係へ・DB変更）:
  `ALTER TABLE comments ADD INDEX idx_post_created (post_id, created_at);`
  → WHERE post_id の絞り込みと ORDER BY created_at DESC LIMIT 3 の両方をこの複合indexで解決（filesort消滅）。
  COUNT(*) WHERE post_id も先頭列 post_id で高速化。
- (4)根拠: comments 99,922行 type:ALL → index seek で数行に激減。N+1の発生回数×削減幅が最大。

## [計測時] ② posts の ORDER BY created_at が全件スキャン+filesort（トップ/ページング）
- (1)現象: index.php:305/321 `SELECT ... FROM posts ORDER BY created_at DESC`（および created_at<=? 付き）。
  EXPLAIN: **type: ALL / rows: 9,401 / Using filesort**。
- (2)原因仮説: posts に created_at インデックスが無い。9,401行＋1.2GBの巨大blobテーブルを毎回全スキャン+filesort。
- (3)推奨アクション: `ALTER TABLE posts ADD INDEX idx_created_at (created_at);`
  → ORDER BY created_at DESC / created_at<=? の範囲＋整列をindexで解決。
  ※注意: `SELECT *` 系（index.php:331,402等）はimgdataまで引くので、可能なら必要カラムのみに絞ると更に効く（アプリ係へ連携）。
- (4)根拠: 9,401行 type:ALL+filesort → index 逆順読みで上位20件だけ取得可能に。

## [計測時] ③ /@user（getAccountName）ページの posts・comments も全件スキャン
- (1)現象:
  - index.php:501 `SELECT ... FROM posts WHERE user_id=? ORDER BY created_at DESC` → EXPLAIN type:ALL/9,401/filesort
  - index.php:506 `SELECT COUNT(*) FROM comments WHERE user_id=?` → EXPLAIN type:ALL/99,922
  - index.php:516 `SELECT COUNT(*) FROM comments WHERE post_id IN (...)` → ①のindexで解決
- (2)原因仮説: posts.user_id / comments.user_id にindexが無い。
- (3)推奨アクション:
  `ALTER TABLE posts ADD INDEX idx_user_created (user_id, created_at);`
  `ALTER TABLE comments ADD INDEX idx_user_id (user_id);`
- (4)根拠: 該当ページのCOUNTと一覧取得がフルスキャン→index seek化。

## [計測時] ④ make_posts 内 users 取得もN+1（comment.user_id / post.user_id ごとに都度SELECT）
- (1)現象: index.php:146,151 でコメント・投稿ごとに `SELECT * FROM users WHERE id=?`。
- (2)原因仮説: users.id は PK なのでindex自体は効く（毎回 type:eq_ref で軽い）。ただし発行回数が多くラウンドトリップが増える。
- (3)推奨アクション: index追加は不要。アプリ係でユーザをまとめて取得（IN句/事前ロード/キャッシュ）するとラウンドトリップ削減。→ findings_app 連携事項。
- (4)根拠: PKヒットなので個々は速いが、ページ表示でN+1回数が嵩む。①②③ほどの緊急度ではない。

## [計測時] ⑤ innodb_buffer_pool_size が 128MB と過小
- (1)現象: posts実体1.2GB（imgdata blob）に対し buffer pool 128MB。RAM 3.8GB。
- (2)原因仮説: blob込みのposts全スキャン時にディスクI/Oが頻発しうる。
- (3)推奨アクション（インフラ係へ連携）: buffer_pool を 1GB前後へ引き上げ検討。
  ただし**本質はimgdataをDBから外す（静的ファイル化）**こと。これが効けばposts本体が劇的に小さくなりbuffer収まる。→ findings_app/infra 連携。
- (4)根拠: data_length 1,204MB の大半がimgdata。これをDBから外せばbuffer poolもfilesortも全て楽になる。

## [計測時] ⑥ スロークエリログがOFF（計測基盤）
- (1)現象: slow_query_log=OFF, long_query_time=10s。
- (2)原因仮説: 改善前後の遅いクエリを定量比較できない。
- (3)推奨アクション（インフラ係へ）: slow_query_log=ON, long_query_time=0（または0.1）, log_output=FILE にして
  `pt-query-digest` 等で頻出・累積時間の大きいクエリを特定。計測しながらの改善が可能に。
- (4)根拠: 現状ログ無しでは効果検証が体感頼みになる。

---

## まとめ（実装係向け・適用推奨DDL）
```sql
ALTER TABLE comments ADD INDEX idx_post_created (post_id, created_at);  -- ①最優先
ALTER TABLE posts    ADD INDEX idx_created_at (created_at);             -- ②
ALTER TABLE posts    ADD INDEX idx_user_created (user_id, created_at);  -- ③
ALTER TABLE comments ADD INDEX idx_user_id (user_id);                  -- ③
```
適用後は再EXPLAINで type が ref/range に変わり filesort が消えることを確認したい（IDLE時に再計測する）。

---
---

# 第2ラウンド調査（score 81,758 時点 / 2026-06-23 / 信号機IDLE時計測）

## 総括（DB調査係の見立て）
**DBはもはや律速ではない。** 前回提案のindex4本＋アプリ最適化が効き、ホットクエリは全て
index でカバーされ、全件スキャン(type:ALL)・filesort は消滅した。再EXPLAIN結果：

| クエリ(行) | type | key | rows | Extra |
|---|---|---|---|---|
| L144 per-post COUNT comments | ref | idx_post_created | 8 | **Using index(covering)** |
| L145 comments LIMIT3 | ref | idx_post_created | 8 | Backward index scan |
| L314 index page posts LIMIT100 | index | idx_created_at | 100 | Backward index scan |
| L519 account posts WHERE user_id | ref | idx_user_created | 12 | Backward index scan |
| L534 account comments IN(...) | range | idx_post_created | 48 | Using where; Using index |

→ どれも数〜100行アクセスで完結。**残る律速はアプリ(PHP-CPU)とインフラ(php-fpm worker/2コアCPU)へ移った**
と判断する。DB側の伸びしろは「クエリ高速化」ではなく「ラウンドトリップ削減」と「運用安全性」が中心。

## [12:1x] ① imgdata が依然DBに保存され続けている【スコアより運用リスク・要注意】
- (1)現象: posts.data_length = **1,204MB**（avg 127KB/行, 10,133行で増加中）。`POST /`(L390) が
  `INSERT INTO posts (...imgdata...)` で毎回blobをDBにも書く。ベンチ毎にDB+ファイル両方が増える。
- (2)原因仮説/影響: **クエリ速度への悪影響はほぼ無い**（一覧/詳細はimgdataを引かないカラム限定済み、
  mediumblobはoff-page格納でclustered indexスキャンは20byteポインタしか読まないため）。
  問題は (a)**ディスク逼迫**（MEMOに残~1GB/94%。ベンチ毎に増えると枯渇しfail化リスク）、
  (b)buffer_pool 512MB に対しテーブル1.2GBで二重持ちの無駄。
- (3)推奨アクション: `POST /`(L390) の INSERT から imgdata を外し、ファイル書き出しのみにする。
  ただし `GET /image/{id}`(L417) のフォールバックが `SELECT mime,imgdata` 依存。
  → 投稿時に必ずファイルを書く(MEMO①で実装済)前提なら、imgdataカラムを `''` で挿入 or NOT NULL外す等の設計が必要。
  既存blobは消さずとも新規分を止めるだけでディスク増加は鈍化。**安全側に倒し、まずINSERTから外すのを推奨**。
- (4)根拠: data_length 1,204MB の99%超がimgdata。クエリ実害は無いがディスク94%は次のfail要因になり得る。

## [12:1x] ② comment_count の per-post COUNT を GROUP BY 一括化（ラウンドトリップ削減）
- (1)現象: make_posts(L144) が1ページ表示で投稿20件×`SELECT COUNT(*) ... WHERE post_id=?` を発行（N+1）。
  個々は covering index で <1ms だが、PHP↔MySQL のラウンドトリップが20回/ページ。L145のLIMIT3も同様に20回。
- (2)原因仮説: 1件ずつ問い合わせる実装。クエリ単体は速いが回数が多くRTT/PHPオーバーヘッドが累積。
- (3)推奨アクション（アプリ係と連携／DB観点の提案）:
  COUNT をまとめる →
  `SELECT post_id, COUNT(*) AS c FROM comments WHERE post_id IN (?,...) GROUP BY post_id;`（1発）。
  EXPLAINでは idx_post_created の range + Using index で完結見込み。20回→1回に削減。
  ※さらに踏み込むなら posts に `comment_count` 集計列を持たせ comment INSERT 時に更新（COUNT自体を消す）。
- (4)根拠: クエリ高速化効果は小さいが、トップページは最頻アクセスで20→1のRTT削減はPHP-CPU/レイテンシに効く可能性。
  **効果は中程度・①より低リスク。アプリ係の改修コストと相談。**

## [12:1x] ③ getAccountName が posts WHERE user_id を2回投げている（重複クエリ）
- (1)現象: `/@{account_name}` で L519(全カラム取得) と L526(`SELECT id` のみ) が同じ
  `posts WHERE user_id=?` を2回実行。L526はpost_ids収集→L534のIN用。
- (2)原因仮説: post_count と post_ids を別途取得しているが、L519/L521 の $results から `array_column($results,'id')` で流用可能。
- (3)推奨アクション（アプリ係連携）: L526 のクエリを削除し L519 の結果を再利用。クエリ1本削減。
- (4)根拠: 両クエリともindex効くが、/@user での冗長な往復。低コストで1本削減。

## [12:1x] ④ スロークエリログは依然OFF（計測の所感）
- (1)現象: slow_query_log=OFF のまま。innodb_buffer_pool_size=512M は適用確認済。
- (2)所感: 現時点で long_query_time=0 にしてもDB側に重いクエリはほぼ出ないはず（全indexカバー済）。
  律速がアプリ/インフラに移った裏付けとして、**ベンチ中の `SHOW PROCESSLIST` でもDBがidle寄りなら確定**。
  逆に計測したい場合のみ一時的に slow_query_log=ON / long_query_time=0 で digest を取る程度でよい。
- (3)推奨: DB側の追加チューニングより、アプリ(digest/openssl→hashネイティブ化, レンダリング)とinfra(php-fpm/CPU)を優先すべき。

## 第2ラウンドまとめ（実装係向け・優先度順）
1. **①imgdata の INSERT 停止**（スコアより先にディスク枯渇リスク回避。要 /image フォールバック設計）
2. **②comment_count を GROUP BY 一括 or 集計列化**（最頻ページのRTT 20→1。アプリ係と協働）
3. ③getAccountName の重複 posts クエリ削除（低コスト）
- **新規index追加は不要**。DBクエリは出尽くしており、次の伸びはアプリ/インフラ側。

---
---

# 第3ラウンド調査（score ~170,000 → 目標400,000 / 2026-06-23 / 信号機IDLE時計測）

## 総括：DBは「速い」が、まだ「往復が多い」。40万へはクエリ"本数"を削る
実測でDBは完全にメモリ常駐・disk I/Oゼロ：
- **Buffer pool hit rate = 1000/1000、reads/s = 0.00**（全データがbuffer内、ディスク読み無し）
- 全ホットクエリは index seek（PK/ref/range）で数〜178行アクセス、filesortも軽微
- → **個々のクエリ速度はもう絞り尽くした**。40万点の壁はクエリ高速化では破れない。

ではどこを攻めるか。CPUは**2コアを nginx + php-fpm(16) + mysqld で奪い合う**構成。
MySQLが1クエリ処理するたびにパース/オプティマイズ＋PHP↔MySQLのRTTでCPUを消費する。
**=「1リクエストあたりのDB往復回数」を削ることが、PHPにCPUを明け渡し全体スループットを上げる最大のDBレバー。**
GET / が最頻エンドポイントで、現状1リクエストあたり概ね5往復（後述）。これを削りにいく。

## [13:2x] ① get_session_user の「毎リクエスト SELECT users」を撤廃（最頻・最大効果）
- (1)現象: `get_session_user()`(L122-130) が **認証済みの全ページで毎回** `SELECT * FROM users WHERE id=?` を発行。
  GET / は最頻エンドポイントで、`me` 取得のためこの1クエリが必ず走る。users は既に2,072行に増加。
- (2)原因仮説: セッションには user.id しか入れておらず、表示に必要な account_name/authority を毎回DBから引き直している。
  PK lookupで個々は速いが、**全リクエスト×1往復**ぶんMySQL CPUとRTTを消費。最頻パスなので累積は大きい。
- (3)推奨アクション（アプリ係連携）: ログイン/登録時に `$_SESSION['user']` へ
  `id・account_name・authority` を丸ごと格納し、get_session_user はDBを引かずセッション値を返す。
  セッションは memcached 常駐済なのでDB往復がまるごと消える。
  ※注意点: 管理者BAN(del_flg更新)はセッションに即反映されない。BANはまれかつ /admin 経路のみ確認すれば実害小。
   気になるなら authority/del_flg だけ必要箇所で確認、表示用フィールドはセッションキャッシュで可。
- (4)根拠: 最頻ページの固定1往復削減。GET / が5→4往復(-20%)、ログイン状態の全ページに効く。CPU2コア争奪の緩和に直結。

## [13:2x] ② posts に comment_count 非正規化列を持たせCOUNTの素を断つ＋コメントもキャッシュ余地
- (1)現象: GET / の make_posts は表示20件のコメントを `WHERE post_id IN(20件) ORDER BY created_at DESC` で1クエリ取得し、
  件数はPHP集計、表示は先頭3件。実測：直近投稿はコメントが少なく転送行数は小さい（上位20で数行）が、
  EXPLAINは `range / idx_post_created / Using index condition / **Using filesort**`（created_at降順整列で発生）。
- (2)原因仮説: 「件数」と「最新3件」を毎回コメントテーブルから引いている。件数は本来不変的に増えるだけの値。
- (3)推奨アクション（アプリ係＋DB連携）:
  - `ALTER TABLE posts ADD COLUMN comment_count INT NOT NULL DEFAULT 0;`
    `POST /comment`(L451付近) で `UPDATE posts SET comment_count = comment_count + 1 WHERE id=?` を同トランザクションで。
    初期値は `UPDATE posts p SET comment_count=(SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id);` でバックフィル。
    → indexページでコメント"件数"のためのコメント取得が不要になり、必要なのは「最新3件」だけに。
  - さらに踏み込むなら「最新3コメント」も memcached にキャッシュ（投稿/コメント追加時に該当post_idをinvalidate）。
    GET / のコメント往復自体を消せる。ベンチの鮮度検証に注意して段階導入。
- (4)根拠: COUNTの素を断ち、indexページのコメント取得を「3件×表示」に最小化。filesortも消える。中〜大。

## [13:2x] ③ index母数100件フェッチの無駄＋comments IN の filesort
- (1)現象: GET / は表示20件のため `LIMIT 100` で100行フェッチ（del_flgユーザ~2%除外の安全マージン）。
  80行は捨てられる。comments IN は filesort 発生。
- (2)原因仮説: del_flg除外をアプリ側で行う設計上、母数を多めに取っている。
- (3)推奨アクション: 効果は小。LIMITを40〜50程度に絞れば転送・処理が軽くなる（削除2%なら20件確保に十分）。
  filesortは行数178で軽微につき優先度低。①②を優先。
- (4)根拠: 80→20行程度の削減。CPU2コア環境では塵も積もるが単独効果は小。

## [13:2x] ④ /@user(getAccountName) の冗長クエリ（第2R③の再掲・未対応）
- L519(posts全カラム) と L526(`SELECT id`)が同じ `posts WHERE user_id` を2回。L519結果を `array_column` で流用しL526削除。
- COUNT comments WHERE user_id も comment_count列があれば不要化の余地。/@user は低頻度につき優先度中の下。

## 40万点チームがDB側でやっている可能性（推測）
1. **セッション/ユーザ情報のアプリ内キャッシュ化**でリクエストあたりのuser SELECTをゼロに（=①）。
2. **comment_count を非正規化列 or キャッシュ**し、indexページからCOUNT/コメント取得を削減（=②）。
3. **indexページ自体の準キャッシュ**（最新投稿リスト＋3コメントを memcached/APCuに短時間キャッシュし、新規投稿時のみ更新）。
   GET / のDB往復を実質ゼロに近づける。鮮度検証との両立が鍵で最も高難度・最も高天井。
4. **MySQLにCPUを使わせない方向**（往復削減＝①②③）でPHP-CPUへ予算を回す。
   ※DB単体のmy.cnf追いチューニング（buffer/flush）は hit率1000/1000・I/O0の今、伸びしろほぼ無し。

## 第3ラウンドまとめ（実装係向け・効果順）
1. **①get_session_user のセッションキャッシュ化**（全認証ページの固定1往復削減・最頻・低リスク）★最優先
2. **②posts.comment_count 非正規化**（+ 余力あれば最新3コメントのキャッシュ）★中〜大
3. ③index母数LIMIT削減（小）・④/@user冗長クエリ削除（小）
- 🔑 重要認識: **DBは hit率1000/1000・disk read 0 で既に高速。40万への鍵は「クエリ本数削減」と「キャッシュでDBを回避」**。
  純粋なDBチューニング（index/my.cnf）の伸びしろは尽きた。次の主戦場はアプリのキャッシュ設計とPHP-CPU/インフラ。

---
---

# 第4ラウンド調査（大会 369,640 / 出発292,146 → 目標500,000 / 2026-06-23 / 信号機IDLE時計測）

## 前提の再確認（実測）
- 接続は **unix socket** 接続済（`host=localhost`→mysqlnd が `/var/run/mysqld/mysqld.sock` 使用）。TCPループバックの syscall 浪費は無し＝**socket化の手当ては不要**。
- 全データ完全メモリ常駐（前R: hit率1000/1000, disk read 0）。件数: posts≈10,818 / comments≈101,497 / users≈2,595。
- 機械は **2コア / 3.8GB、CPU飽和、php-fpm=80%が壁・mysqld=29%**（ローカル同居計測）。
  ただし大会ベンチは別マシン→ローカルの比率そのままではない。**残るDB施策は「mysqld CPUを削る」より「PHPが扱うデータ量・往復を削る」方向が効く**。

## 残っているDBアクセスの棚卸し（断片キャッシュ後）
脱フレーム＋断片キャッシュで GET / の往復は激減したが、**毎 GET / と POST / 応答で必ず1本走る固定クエリ**が残る：
```
SELECT id,user_id,body,mime,created_at,comment_count
  FROM posts FORCE INDEX (idx_created_at) ORDER BY created_at DESC LIMIT 40   (index.php:392,592)
```
EXPLAIN: `type:index / key:idx_created_at / rows:40 / Backward index scan`。
→ **Extra に "Using index" が無い** = idx_created_at は (created_at) 単独のため、40件ぶん**クラスタPK lookupで body(TEXT)/user_id/mime/comment_count を毎回materialize**している。
断片キャッシュが**ヒットする投稿では body も user も使わない**（鍵は `pf:{id}:c{comment_count}` のみ）。**毎GET/で40件のTEXT body をDBから引きPHP配列に展開するのは純粋な無駄**（mysqld CPU＋php-fpm CPU両方を食う）。

## [16:1x] ① フィード用 covering index で「鍵取得」をindex-onlyにし、本体fetchをミス分だけに【最優先・低リスク】
- (1)現象: 上記フィードクエリが毎回40件フル行（TEXT body含む）をfetch。断片ヒット時はそのうち id と comment_count しか使わない。
- (2)原因仮説: 鍵算出 (id, comment_count) のためだけに全カラムを引いている。covering indexが無いのでPK lookup＋body読みが発生。
- (3)推奨アクション（DB＋アプリ係連携）:
  - `ALTER TABLE posts ADD INDEX idx_feed (created_at, comment_count);`（leafにPK id を含むので id も index-only で取れる）
  - GET / の処理を2段に：
    1. 鍵用クエリ `SELECT id, comment_count FROM posts USE INDEX (idx_feed) ORDER BY created_at DESC LIMIT 40`
       → EXPLAIN期待値 **Using index; Backward index scan**（テーブル無アクセス）。
    2. 断片 getMulti → **ミスした post_id だけ** `SELECT id,user_id,body,mime,created_at FROM posts WHERE id IN (missed)` で本体取得。
  - 効果: ヒット率が高い通常時、毎GET/の「40件PK lookup＋TEXT body読み＋PHP配列展開」が消え、index-only 40件＋ミス分のみに。
    **mysqld CPUとphp-fpm CPU(body文字列のPDO materialize)を同時に削減**＝律速のphp-fpmにも効く。
  - コスト: 二次index1本増。`POST /`(新規post)と`POST /comment`(comment_count+1)で idx_feed も更新されるが、書き込みは低頻度で軽微。
- (4)根拠: 毎GET/固定の40行フル取得→index-only化。最頻endpointの固定費を直接削る。FORCE INDEXは idx_feed へ張り替え（idx_created_at は /posts の created_at<=? 用に残置可、または idx_feed が両方を兼ねられるか検証）。

## [16:1x] ② フィードの id 一覧自体を memcached 化し、GET / を「DB往復ゼロ」に【高天井・要設計】
- (1)現象: ①でもフィードクエリ1本は毎GET/残る。これも消せる。
- (2)着眼: **フィードの「並び順と表示40件のid」は新規投稿(POST /)でしか変わらない**。新規コメントは順序を変えず comment_count だけ変える。
- (3)推奨アクション（アプリ主導／DB観点の提案）:
  - 「最新40件の post_id 順リスト」を memcached にキャッシュし、**invalidate は POST /(新規投稿)時のみ**（コメントでは無効化不要＝高ヒット率）。
  - 各 post の comment_count も memcached に増分キャッシュ（POST /comment で increment、DBの非正規化列と二重更新）。
  - これで GET / は **memcached だけで鍵を組み立て→断片getMulti**＝**DBクエリ完全ゼロ**（ミス時のみ本体/コメントをDBへ）。
  - ※過去に feed_version+index:v データキャッシュは撤廃した経緯あり（フル行キャッシュで unserialize 相殺）。今回は**軽量な id+count のみ**をキャッシュする点が異なり、unserializeコストが桁違いに小さい。
- (4)根拠: 最頻 GET / の唯一残るDB往復を消す。①の上位互換だが invalidation 設計の正確さ（fail0維持）が条件。①を先に入れ、②は余力で。

## [16:1x] ③ 複数台構成（DB分離）── CPU2コア飽和に対する構造的レバー【大会topology次第で最大】
- (1)現象: ローカルは2コア飽和でphp-fpmが壁。**単一ホストのCPUが物理的上限**。50万到達チームは複数台に分散している可能性が高い。
- (2)見立て: 大会が複数サーバを提供しているなら、**MySQL を2台目へ分離**すればアプリ機の2コアを丸ごと nginx+php-fpm に明け渡せる。mysqld(ローカル29%相当)ぶんのCPUが空く＝php-fpm スループット上昇。
- (3)推奨アクション（DB側の分離準備。インフラ係と要連携）:
  - `bind-address = 0.0.0.0`、`GRANT ... TO 'isuconp'@'<appサーバIP>'`（または '%'）、ファイアウォール3306開放。
  - アプリの接続を `ISUCONP_DB_HOST=<DB機IP>` でTCPへ（socket→TCPでlatency微増するが、1台ぶんのCPU獲得が圧勝）。**PDO永続接続は維持必須**（接続確立コストをネットワーク越しで毎回払わないため）。
  - 専用DB機では `innodb_buffer_pool_size` をさらに増やせる（imgdata除去後データは数十MBで余裕）。`innodb_flush_log_at_trx_commit=2` 維持。
  - **memcached の置き場所に注意**: アプリを2台に増やすなら断片キャッシュ＋セッションは**共有memcached**（ネットワーク上）に集約しないと整合が崩れる。アプリ1台＋DB1台ならmemcachedはアプリ機据え置きでよい。
- (4)根拠: CPUバウンドな単一ホストを横に割るのがISUCON的50万定石。**効果は最大級だが大会のサーバ台数に依存**＝まず台数確認を。

## [16:1x] ④ /@user(getAccountName) の集計クエリ非正規化（中〜小）
- (1)現象: index.php:804 posts WHERE user_id（LIMIT無）、:809 `COUNT(*) comments WHERE user_id`、:534相当 commented_count(`COUNT comments WHERE post_id IN(ユーザの全post)`)。
  実測: 1ユーザの最大投稿数23・comments WHERE user_id は ref/Using index/rows~101 で軽量。
- (2)原因仮説: /@user は GET / ほど高頻度ではないが、3本のクエリ＋IN集計が毎回走る。
- (3)推奨アクション（優先度中の下）:
  - `users` に `post_count` / `comment_count`(本人のコメント数) 非正規化列を持たせ POST /・POST /comment で増分 → :804の件数と:809を消せる。
  - commented_count（自投稿への被コメント総数）は `posts.comment_count` を**ユーザの全postでSUM**すれば comments を引かず算出可：
    `SELECT COUNT(*) cnt, SUM(comment_count) commented FROM posts WHERE user_id=?`（idx_user_created でrange、Using indexにcomment_count含めれば covering）。
  - posts WHERE user_id に表示ぶんの LIMIT を付ける（最大23行なので効果小）。
- (4)根拠: /@user の往復を3→1〜2本へ。頻度が中のため①②③より下。

## 書き込み経路の評価（POST / / POST /comment）── 現状ほぼ最適
- `POST /comment`(index.php:732,741): `INSERT INTO comments` + `UPDATE posts SET comment_count=comment_count+1 WHERE id=?`（PK更新）。2本ともindex効き軽量。`flush_log_at_trx_commit=2`でcommitも安価。**ボトルネックでない**。①でidx_feed追加時、この UPDATE が idx_feed(comment_count) も更新する点だけ留意（行は1件・軽微）。
- `POST /`(index.php:671): `INSERT INTO posts`（imgdata=''）。ファイル書き出しはアプリ/インフラ管轄。DB的に問題なし。
- → 書き込みは低頻度かつ最適化済。**50万へのDB側レバーは読み取り(GET /)固定費の削減＝①②と、構造の③**。

## 50万点チームがDB側でやっている可能性（推測）
1. **GET / を完全にDBレス化**（②：軽量id+countキャッシュ or フルHTMLキャッシュ）。最頻endpointのDB往復を消す。
2. **covering index で全ホット読みを index-only 化**（①）。TEXT body をホットパスで引かない。
3. **MySQL/memcached を別サーバへ分離**（③）し、アプリ機CPUを独占。CPUバウンド単一ホストの構造打破。
4. 書き込みは write-through キャッシュ＋非正規化で集計クエリ全廃（④の発展）。
5. （DB単体my.cnfは hit率1000/1000・I/O0 の今、追いチューニングの伸びは無い＝やらない）。

## 第4ラウンドまとめ（実装係向け・効果順）
1. **①フィード covering index `idx_feed (created_at, comment_count)` ＋ GET / を「鍵index-only取得→ミス分だけ本体fetch」の2段化**。最頻GET/の40件フル行(TEXT body)取得を撲滅。mysqld/php-fpm両方に効く・低リスク。★最優先
2. **③MySQL別サーバ分離**（大会が複数台提供なら最大レバー。bind-address/GRANT/TCP+永続接続/memcached配置をインフラ係と設計）。まず**提供サーバ台数の確認**を。
3. **②GET / のフィードid一覧 memcached 化でDB往復ゼロ**（①の上位互換・高天井。invalidationはPOST /時のみで高ヒット率。fail0維持が条件）。
4. ④/@user の集計を users 非正規化＋`SUM(posts.comment_count)`で往復削減（中〜小）。
- 🔑 結論: **DBの「速度」はもう限界まで来ている。残る伸びは(a)ホットパスでDBを触る回数・量を更に削る[①②④]、(b)サーバを横に割ってCPUを増やす[③]の2系統**。純DBチューニング(index追加/my.cnf)単独の伸びは無い。

---
---

# [16:5x] Go分析（go-port / 大会: PHP 392,566 vs Go 278,809・ローカルは Go 228k > PHP 195k の逆転）

## 結論（DB視点の核心）
**Goのindex/N+1/非正規化の移植は正しくできている。負ける原因は「DBコネクション管理が高並列向けに全く設定されていない」こと。**
ローカル(低並列)では露見せず、大会(別マシンから高並列)で初めて牙を剥く3点が揃っている：
1. **コネクションプール無設定**（最大の犯人）
2. **TCP接続（PHPはunix socket）**
3. **interpolateParams未指定＝パラメータ付きクエリが prepare+execute の2往復**

「ローカルで勝つが大会で負ける」のは、**ローカルはベンチが同一2コアを食い合い実効並列が抑制される**ため上記が顕在化せず、Goの低い1リクエストCPUが勝つ。
**大会はベンチが別マシンから高並列を叩く**ため、コネクション嵐・往復倍増が爆発し、安定した16本常駐プールのPHPに逆転される。

## ① 【最大の犯人】DBコネクションプールが無設定 → 高並列で接続嵐＆枯渇リスク
- (1)現象: `app.go:1111 sqlx.Open(...)` の後に **`SetMaxOpenConns`/`SetMaxIdleConns`/`SetConnMaxLifetime` が一切無い**（grep確認）。
  → database/sql のデフォルトは **MaxOpenConns=0（無制限）/ MaxIdleConns=2 / ConnMaxLifetime=0**。
- (2)原因仮説（なぜ大会で負けるか）:
  - net/http はリクエスト毎にgoroutineを無制限に起こす。**アプリ並列度に上限が無い**（PHPの `pm.max_children=16` のような天井が無い）。
  - 各goroutineがDBを触ると、**warm接続は2本しか保持されない**ので3本目以降は毎回 TCP接続＋MySQL認証ハンドシェイクで**新規接続→使用後即クローズ**を繰り返す（churn）。
    PHPは `PDO ATTR_PERSISTENT` ×16workerで **16本を常時再利用**（接続確立コストはほぼゼロ）。ここが決定的な差。
  - 高並列が進むと MaxOpenConns無制限ゆえ接続数が `max_connections=151` に張り付き、**`Error 1040: Too many connections` でリクエストfail**→スコア急落（278k）。
  - 実測の傍証: ローカルベンチ後 `Max_used_connections=28`（151未満で耐えた）/ `Aborted_connects=0` / `Connection_errors_max_connections=0`。
    **= ローカルの並列度では28本で収まり問題化しないが、大会の並列度では151を超え得る**。これが逆転の主因。
- (3)推奨アクション（実装係へ）:
  - `db.SetMaxOpenConns(N)` と **`db.SetMaxIdleConns(N)` を同値**に設定（idle=openにすると接続が閉じられず常時再利用＝churn消滅）。`db.SetConnMaxLifetime(0)`（張り替え不要）。
  - Nは **max_connections(151)に十分な余裕を残しつつ、2コアDBが捌ける範囲**。まず **N=64** 程度を起点にA/B（mysqldがCPU飽和するなら32へ。多すぎるとロック競合/コンテキストスイッチで逆効果）。
  - 効果: 接続確立コストをゼロ化（PHPと同条件）＋ MaxOpenConnsが**自然なバックプレッシャ**になり、過剰goroutineは接続待ちで整列→DB枯渇(1040)を防止。**これ単体で大会の逆転は解消見込み。最優先。**
- (4)根拠: PHPの安定性の正体は「16本常駐の有界プール」。Goは無界＆warm2本で真逆。高並列で接続コスト×回数が爆発。

## ② TCP接続のまま（PHPはunix socket）→ ①のchurnコストを増幅
- (1)現象: `app.go:1101 cfg.Net="tcp"`, Addr=`localhost:3306`。PHP実装は `host=localhost`→**unix socket**（実測 `/var/run/mysqld/mysqld.sock`）。
- (2)原因仮説: 同一ホストならsocketの方がTCPループバックより接続確立・往復が軽い。①のchurnで毎回新規接続を張る現状、TCPハンドシェイク分が上乗せされ二重に損。
- (3)推奨アクション: 同一ホスト構成なら `cfg.Net="unix"; cfg.Addr="/var/run/mysqld/mysqld.sock"`。
  （※将来DBを別サーバへ分離するならTCPのまま。その時こそ①のプール常駐再利用が更に重要）。
- (4)根拠: ①を直せばchurn自体が消えるので寄与は中。だが socket化はゼロリスクで効く。①と併せて実施推奨。

## ③ interpolateParams 未指定 → パラメータ付きクエリが毎回2往復（PHPは1往復）
- (1)現象: `cfg.Params` は charset のみ（grep確認、interpolateParams無し）。go-sql-driver は `?` 付きクエリを**binary protocolのprepared statement**で実行＝**prepare＋execute＋（closeで）最大2〜3往復/クエリ**。
  PHPのPDO mysqlは既定で **emulated prepares＝client側展開で1往復**。
- (2)原因仮説: 1 GET / でフィード鍵クエリ＋(ミス時)本体/コメント/ユーザ＝複数クエリ。各クエリが2往復だと**DB往復が実質倍増**。高並列＋①のchurnと重なり、接続占有時間が伸び、さらに接続が枯渇しやすくなる悪循環。
- (3)推奨アクション: DSNに **`interpolateParams=true`**（`cfg.Params["interpolateParams"]="true"`）。client側でパラメータ展開し1往復化＝PHPと同条件に。
  併せて `db.SelectContext/GetContext` のラウンドトリップが半減。SQLは全て `?` プレースホルダで multi-statement не使用のため安全。
- (4)根拠: 往復数はDB律速・接続占有の主因。PHPが1往復で済ませている所をGoは2往復している＝高並列で効く差。

## ④ 移植は概ね良好だった点（DB施策の移植OK確認）
- **idx_feed covering＋2段化は正しく機能**: `idx_feed=(created_at, comment_count, user_id)` と確認。getIndex(640)/getPosts(776)の鍵クエリ `SELECT id,user_id,comment_count ... USE INDEX(idx_feed)` は
  EXPLAIN **`Backward index scan; Using index`（テーブル無アクセス＝真のindex-only）**。user_idもindexに入っており断片ヒット時は本体fetch無し。**ここはPHPと同等以上。問題なし。**
- **N+1のIN一括化も移植OK**: makePosts/buildListPosts は comments を `WHERE post_id IN(?)` 1クエリ、users も getUsersByIDs の `IN(?)`一括＋sync.Mapキャッシュ。**むしろGoはuserCache/bannedSetをプロセス常駐**でき、PHPのリクエスト内メモ化より強い（往復はPHP以下）。
- comment_count非正規化（postComment:998のUPDATE、dbInitialize再集計）も移植OK。
- → **「DBに来るクエリの種類・本数」自体はGo≦PHPで良好。問題は1クエリあたりの往復数(③)と接続の張り方(①②)という"クエリの外側"。**

## ⑤ 軽微なDB往復の無駄（①②③の後の余地）
- `getAccountName`: `SELECT id,user_id,comment_count FROM posts WHERE user_id=?`(687) と `SELECT id FROM posts WHERE user_id=?`(707) で**posts-by-userを2回**実行（PHPでも指摘した重複）。707は687の結果からid流用で削除可。
  さらに commentCount(700) と commentedCount(728) は users非正規化 or `SUM(posts.comment_count)`で往復削減可（第4R④と同じ）。/@user頻度ぶんだけ効く。

## 非DB（管轄外だがGo逆転の共犯。アプリ係へ要連携）
- **テンプレートを毎リクエスト ParseFiles**: getIndex(656)/getAccountName(741)/getPosts(797)/getPostsID(838)/getLogin/getRegister が**ハンドラ内で `template.ParseFiles` をディスクから実行・毎回パース**。PHPはopcacheでコンパイル済。
  高並列ではこのディスクI/O＋パースCPUが致命的。**`var tmpl = template.Must(...)` でグローバルに1回だけパースし使い回す**べき（postFragTmplはOnceで正しくやれている＝これを全テンプレに横展開）。これはCPU律速を直撃する大物。→ findings_app 連携。

## まとめ（実装係向け・効果順 / Goを大会でPHP超えにする）
1. **①DBプール設定 `SetMaxOpenConns(64)＋SetMaxIdleConns(64)＋SetConnMaxLifetime(0)`**（接続churn/枯渇を解消・最優先・大会逆転の主因）
2. **③DSNに `interpolateParams=true`**（クエリ往復を2→1へ半減・PHPと同条件化）
3. **②`cfg.Net="unix"` でsocket接続**（接続コスト低減・ゼロリスク）
4. （アプリ係）**テンプレートのグローバル1回パース化**（毎リクエストParseFiles撤廃・CPU直撃の大物）
5. ⑤getAccountNameの重複postsクエリ削除＋/@user集計の非正規化（中〜小）
- 🔑 **Goが遅いのは言語のせいでもDB施策の移植漏れでもない。「無界・非常駐・2往復」なコネクション設定が高並列で崩れているだけ**。①②③は数行の追加で、大会スコアはPHP超えに戻る可能性が高い。
