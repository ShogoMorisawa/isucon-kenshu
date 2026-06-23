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
