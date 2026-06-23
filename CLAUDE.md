# CLAUDE.md — private-isu 並列チューニング共通ルール

## このプロジェクト
private-isu（画像投稿SNS）を **PHP実装** で高速化し、ベンチマークスコアを最大化する。
現在は Ruby 実装が稼働中。まず PHP 実装へ切り替えてから着手する。

## チーム構成（4端末・並列）
- 実装係（Writer）: ただ一人の書き込み手。ファイル編集・ベンチ実行・サービス再起動・DB変更はこの係だけが行う。
- DB調査係（Reader）: 読み取り専用。スロークエリ・EXPLAIN・インデックスを分析し findings_db.md にだけ書く。
- アプリ調査係（Reader）: 読み取り専用。PHPコードのN+1・画像BLOB・ロジックを分析し findings_app.md にだけ書く。
- インフラ調査係（Reader）: 読み取り専用。nginx/PHP-FPM/MySQL設定・CPU・メモリを分析し findings_infra.md にだけ書く。

## 絶対の掟（衝突防止）
1. 1ファイル1ライター: 自分の担当ファイル以外には書き込まない。
   - 実装係 → MEMO.md（とソースコード）
   - DB調査係 → findings_db.md のみ
   - アプリ調査係 → findings_app.md のみ
   - インフラ調査係 → findings_infra.md のみ
2. 調査係はソースコード・設定ファイルを絶対に編集しない。ベンチも回さない。restartもしない。DBも変更しない。提案は自分の findings ファイルに書くだけ。
3. ベンチ実行は実装係のみ。実行前に MEMO.md 先頭の信号機を RUNNING にし、終わったら IDLE に戻す。
4. 信号機が RUNNING の間、調査係は重い処理（大量クエリ、プロファイル）を控える（計測を歪めないため）。
5. 作業開始時、全員まず MEMO.md と自分の findings を読んで現状を把握する。

## 環境
- アプリ(PHP): /home/isucon/private_isu/webapp/php/  （index.php 一枚構成、views/, vendor/ あり）
- nginx設定: /home/isucon/private_isu/webapp/etc/nginx/
- DB接続: /home/isucon/env.sh  （ISUCONP_DB_USER=isuconp / PASSWORD=isuconp / NAME=isuconp）
- 稼働中（2026-06-23 切替済）: php8.3-fpm.service（PHP実装）。isu-ruby は stop&disable 済。
- Webサーバ: nginx → php-fpm(127.0.0.1:9000) → MySQL(3306) + memcached(11211, セッション)
- PHPアプリ本体: /home/isucon/private_isu/webapp/php/index.php （Slim, 1枚構成）
- nginx設定の実体: /etc/nginx/sites-available/isucon.conf （リポジトリ内 etc/nginx ではなくこちらが有効）
- ベンチ: /home/isucon/private_isu/benchmarker/  ※実行は実装係のみ
  - ビルド: cd benchmarker && go build -o bin/benchmarker （または make）
  - 実行（確定）: cd /home/isucon/private_isu/benchmarker && ./bin/benchmarker -t http://localhost -u ./userdata
  - 出力は JSON 1行: {"pass":..,"score":..,"success":..,"fail":..,"messages":[..]}
  - 初期スコア 532（PHP切替直後, 2026-06-23 11:24）

## 報告フォーマット（findings に書く時）
各提案は「## [時刻] 見つけたこと」見出しで、(1)現象 (2)原因の仮説 (3)推奨アクション (4)根拠(数値) を簡潔に。
実装係が拾いやすいよう、効果が大きそうな順に並べる。
