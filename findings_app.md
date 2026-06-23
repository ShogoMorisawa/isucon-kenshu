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
