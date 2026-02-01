# Kashiwazaki SEO Related Posts

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.1-orange.svg)](https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-related-posts/releases)

AI分析・3階層設定・API統計・一括操作で大規模サイトの関連記事を効率管理。OpenAI GPT対応、投稿タイプ別キャッシュ管理、詳細な個別記事設定が可能なエンタープライズ級SEOプラグイン。

> エンタープライズ級の関連記事管理システム。3階層設定で柔軟な制御、API統計で完全監視。

## 主な機能

### 3階層設定システム
- **共通設定**: サイト全体のデフォルト値を設定
- **投稿タイプ別設定**: 投稿・固定ページ等タイプごとにカスタマイズ
- **個別記事設定**: 各記事で詳細な設定が可能（8項目）

### API統計ダッシュボード
- 成功/失敗の回数と成功率を期間別に集計（24時間/1週間/1ヶ月/3ヶ月/1年/すべて）
- 30日間の推移グラフで視覚的に確認
- API使用量を把握してコスト管理

### 一括操作機能
- 投稿タイプ別の一括有効化
- 投稿タイプ別の一括デフォルト値リセット
- 投稿タイプ別・全体のキャッシュクリア

### キャッシュ管理
- 最大8760時間（365日）の有効期限設定
- 投稿タイプ一覧でキャッシュ数/記事数を可視化
- 投稿タイプ別キャッシュクリア

### 表示機能
- 3種類のテンプレート（リスト・グリッド・スライダー）
- 6色のカラーテーマ（ブルー・オレンジ・グリーン・パープル・レッド・ホワイト）
- レスポンシブスライダー（PC・タブレット・スマホ別設定）

### AI分析
- OpenAI GPT-4o-mini/4o/4-turbo対応
- タグ・カテゴリ・タイトル・抜粋・URLパス・投稿日を総合分析
- AIなしでも類似度計算のみで動作可能

## クイックスタート

1. プラグインファイルを `/wp-content/plugins/kashiwazaki-seo-related-posts/` にアップロード
2. WordPress管理画面の「プラグイン」で有効化
3. 「関連記事AI」→「AIのAPI設定」でOpenAI APIキーを設定
4. 「共通設定」でサイト全体のデフォルト値を設定
5. 必要に応じて「投稿タイプ別設定」で個別にカスタマイズ

## 使い方

### ショートコード
```
[kashiwazaki_related_posts]
```

パラメータ例：
```
[kashiwazaki_related_posts max_posts="5" template="grid" filter_categories="1,2,3"]
```

### 自動挿入
「共通設定」または「投稿タイプ別設定」で挿入位置を設定すると、記事に自動で関連記事が表示されます。

### 個別記事設定
編集画面のメタボックスで記事ごとに以下を設定可能：
- 表示ON/OFF
- 最大表示記事数
- 表示形式（リスト/グリッド/スライダー）
- 挿入位置
- 対象投稿タイプ
- カテゴリフィルタ
- カラーテーマ
- 見出しテキスト・見出しタグ

## 技術仕様

- **WordPress**: 5.0以上
- **PHP**: 7.4以上
- **ライセンス**: GPL-2.0-or-later
- **AI API**: OpenAI GPT（APIキー必要）

## 更新履歴

### Version 1.0.1 - 2026-02-01
メタボックスのHTML構造を修正。他プラグインのメタボックス開閉が正常に動作するよう改善。

### Version 1.0.0 - 2025-10-30
初回リリース。3階層設定システム・API統計ダッシュボード・一括操作機能・詳細キャッシュ管理を実装。

詳細は [CHANGELOG.md](CHANGELOG.md) を参照してください。

## ライセンス

GPL-2.0-or-later

## サポート・開発者

**開発者**: 柏崎剛 (Tsuyoshi Kashiwazaki)
**ウェブサイト**: https://www.tsuyoshikashiwazaki.jp/
**サポート**: プラグインに関するご質問や不具合報告は、開発者ウェブサイトまでお問い合わせください。

---

<div align="center">

**Keywords**: WordPress, Related Posts, AI, OpenAI, GPT, SEO, Cache, Analytics, Bulk Operations

Made with by [Tsuyoshi Kashiwazaki](https://github.com/TsuyoshiKashiwazaki)

</div>
