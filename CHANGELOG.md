# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-04-25

### Fixed
- PHP 8.1+ 互換性: 管理画面で発生していた `strpos(null, ...)` / `str_replace(..., null)` の Deprecated 警告を解消（投稿タイプ別設定ページの `add_submenu_page()` 第1引数 `null` を空文字列 `''` に変更）
- PHP 8.1+ 互換性: 隠しサブメニューページ表示時の `admin-header.php:41` における `strip_tags(null)` Deprecated 警告を解消（`load-{$hook}` アクションでページタイトルをグローバルに補完）
- AJAX 経路 4 箇所の nonce 検証で `$_POST['nonce']` 未定義時に発生していた `Undefined array key` 警告を防御（`isset()` チェック + `wp_unslash()` 適用：`ajax_fetch_related_posts` / `ajax_clear_cache` / `ajax_reset_all_posts_to_defaults` / `ajax_enable_all_posts`）
- AJAX 経路 3 箇所の `$_POST['post_id']` 直接アクセスを `isset()` ガード付きに変更（API クラスを含む）
- 設定保存処理 (`max_posts`) およびその他 2 箇所の `$_POST` 直接アクセスを `isset()` ガード付きに変更

### Security
- nonce 検証前の `$_POST['nonce']` 直接参照を `isset()` ガードに切り替え、不正リクエスト時のログ汚染を防止

### Improved
- WordPress 公式パターン（Yoast SEO / Jetpack / Site Kit と同等）に沿った隠しサブメニュー登録方式へ刷新

## [1.0.1] - 2026-02-01

### Fixed
- メタボックスのHTML構造（`</div>`タグ欠落）を修正
- 他プラグインのメタボックス開閉が正常に動作しない問題を解消

## [1.0.0] - 2025-10-30

### Added
- 3階層設定システム（共通設定・投稿タイプ別設定・個別記事設定）
- OpenAI GPT-4o-mini/4o/4-turbo対応のAI分析機能
- API統計ダッシュボード（成功/失敗率・期間別集計・30日間推移グラフ）
- 投稿タイプ別の一括有効化機能
- 投稿タイプ別の一括デフォルト値リセット機能
- 投稿タイプ別・全体のキャッシュクリア機能
- 投稿タイプ一覧でのキャッシュ数/記事数可視化
- 個別記事での8項目詳細設定（対象投稿タイプ・カテゴリフィルタ・カラーテーマ等）
- APIキーのマスク表示と表示切り替え機能
- 3種類のテンプレート（リスト・グリッド・スライダー）
- 6色のカラーテーマ
- レスポンシブスライダー（デバイス別設定）
- 最大8760時間（365日）のキャッシュ有効期限設定
- ショートコード・ウィジェット・自動挿入の3つの表示方法

### Fixed
- フォーム入れ子問題によるキャッシュクリアボタンの動作不良を修正
- 確認ダイアログの重複表示を修正
- 投稿タイプ別設定で共通設定が正しく反映されない問題を修正
- 全設定リセット時に個別投稿のカスタムフィールドが削除されない問題を修正
- カスタムフィールドのデフォルト値判定ロジックを改善

### Improved
- 投稿タイプ別設定一覧に見やすいストライプデザインを適用
- 投稿タイプ名から記事一覧へのリンクを追加
- API呼び出しログの自動クリーンアップ（1年以上前のログを削除）
- セキュリティ強化（APIキーのマスク表示）
