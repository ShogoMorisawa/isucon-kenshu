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
