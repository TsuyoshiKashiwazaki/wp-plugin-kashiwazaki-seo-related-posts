<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_API {

    // GPT の出力の上限。推論モデルは考える分のトークンも含むため余裕を持たせる (使った分だけ課金される)
    const CHAT_MAX_COMPLETION_TOKENS = 4000;

    public function __construct() {
        // AI 連携は class-related-posts.php 経由で呼ばれる。
        // 旧来の get_related_posts_ai / check_api_settings AJAX は未使用かつ
        // 未初期化プロパティ・nonce 不整合を含むため削除した。
    }
    public function analyze_related_posts_with_ai($current_post_data, $candidate_posts_data, $api_key, $model, $max_posts, $search_methods = null) {
        // API設定を取得
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        // OpenAI APIのみ使用
        $url = 'https://api.openai.com/v1/chat/completions';
        // OpenAI用のモデル設定を取得（デフォルト: gpt-4o-mini）
        $model = isset($options['openai_model']) && KashiwazakiSEORelatedPosts_Embeddings::is_valid_model_id($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';

        $current_info = "【現在の記事】\n";
        $current_info .= "タイトル: " . $current_post_data['title'] . "\n";
        $current_info .= "タグ: " . (isset($current_post_data['tags']) && is_array($current_post_data['tags']) ? implode(', ', $current_post_data['tags']) : 'なし') . "\n";
        $current_info .= "カテゴリ: " . (isset($current_post_data['categories']) && is_array($current_post_data['categories']) ? implode(', ', $current_post_data['categories']) : 'なし') . "\n";
        $current_info .= "抜粋: " . $current_post_data['excerpt'] . "\n";
        $current_info .= "URLパス: /" . (isset($current_post_data['path_segments']) && is_array($current_post_data['path_segments']) ? implode('/', $current_post_data['path_segments']) : '') . "\n";
        $current_info .= "投稿日: " . date('Y年m月d日', strtotime($current_post_data['publish_date'])) . "\n\n";

        $candidates_info = "【候補記事一覧】\n";
        foreach ($candidate_posts_data as $index => $candidate) {
            $candidates_info .= "記事{$index}: ID={$candidate['id']}\n";
            $candidates_info .= "  タイトル: {$candidate['title']}\n";
            $candidates_info .= "  タグ: " . (isset($candidate['tags']) && is_array($candidate['tags']) ? implode(', ', $candidate['tags']) : 'なし') . "\n";
            $candidates_info .= "  カテゴリ: " . (isset($candidate['categories']) && is_array($candidate['categories']) ? implode(', ', $candidate['categories']) : 'なし') . "\n";
            $candidates_info .= "  抜粋: {$candidate['excerpt']}\n";
            $candidates_info .= "  URLパス: /" . (isset($candidate['path_segments']) && is_array($candidate['path_segments']) ? implode('/', $candidate['path_segments']) : '') . "\n";
            $candidates_info .= "  投稿日: " . date('Y年m月d日', strtotime($candidate['publish_date'])) . "\n\n";
        }

        // AI分析対象要素に基づいて判定基準を動的生成
        $criteria = array();
        $priority_counter = 1;

        if ($search_methods && is_array($search_methods)) {
            if (in_array('title', $search_methods)) {
                $criteria[] = "{$priority_counter}. タイトルやコンテンツのテーマ的関連性";
                $priority_counter++;
            }
            if (in_array('categories', $search_methods)) {
                $criteria[] = "{$priority_counter}. カテゴリの一致度";
                $priority_counter++;
            }
            // タグ情報は除外
            if (in_array('excerpt', $search_methods)) {
                $criteria[] = "{$priority_counter}. 抜粋内容の関連性";
                $priority_counter++;
            }
            if (in_array('directory', $search_methods)) {
                $criteria[] = "{$priority_counter}. URLパス構造の類似性";
                $priority_counter++;
            }
        }

        // 選択された基準がない場合はデフォルト
        if (empty($criteria)) {
            $criteria = array(
                "1. タイトルやコンテンツのテーマ的関連性",
                "2. カテゴリの一致度",
                "3. 抜粋内容の関連性",
                "4. URLパス構造の類似性"
            );
        }

        $criteria_text = implode("\n", $criteria);

        // モデルに応じて温度と評価基準を設定
        $temperature = 0.1; // デフォルト
        $evaluation_criteria = "";

        if (strpos($model, 'gpt-4.1-mini') !== false) {
            // mini: バランス型（温度0.5、中程度の幅）
            $temperature = 0.5;
            $evaluation_criteria = "## 評価基準\n" .
                                  "- 同一概念・用語（例: メタディスクリプション ↔ meta description）: +40点～60点\n" .
                                  "- タグの一致: +30点～50点\n" .
                                  "- 上位/下位概念の関係（例: SEO ↔ タイトルタグ最適化）: +30点～50点\n" .
                                  "- 同じカテゴリの関連概念（例: 内部リンク ↔ パンくずリスト）: +20点～40点\n" .
                                  "- 同じカテゴリだが異なるテーマ（例: SEO ↔ アクセス解析）: +5点～15点\n" .
                                  "- 無関係（例: SEO ↔ デザインツール）: 0点";
        } elseif (strpos($model, 'gpt-4.1') !== false && strpos($model, 'nano') === false && strpos($model, 'mini') === false) {
            // 4.1: 寛容型（温度0.7、幅が広い）
            $temperature = 0.7;
            $evaluation_criteria = "## 評価基準\n" .
                                  "- 同一概念・用語（例: メタディスクリプション ↔ meta description）: +30点～70点\n" .
                                  "- タグの類似: +25点～65点\n" .
                                  "- 上位/下位概念の関係（例: SEO ↔ タイトルタグ最適化）: +25点～65点\n" .
                                  "- 同じカテゴリの関連概念（例: 内部リンク ↔ パンくずリスト）: +20点～60点\n" .
                                  "- 同じカテゴリだが異なるテーマ（例: SEO ↔ アクセス解析）: +10点～40点\n" .
                                  "- 無関係（例: SEO ↔ デザインツール）: 0点";
        } else {
            // nano: 厳格型（温度0.1、幅が狭い）
            $temperature = 0.1;
            $evaluation_criteria = "## 評価基準\n" .
                                  "- 同一概念・用語（例: メタディスクリプション ↔ meta description）: +90点～100点\n" .
                                  "- タグの完全一致: +70点～80点\n" .
                                  "- 上位/下位概念の関係（例: SEO ↔ タイトルタグ最適化）: +20点～30点\n" .
                                  "- 同じカテゴリの関連概念（例: 内部リンク ↔ パンくずリスト）: +10点～20点\n" .
                                  "- 同じカテゴリだが異なるテーマ（例: SEO ↔ アクセス解析）: +3点～7点\n" .
                                  "- 無関係（例: SEO ↔ デザインツール）: 0点";
        }

        // max_postsに応じた動的な例を生成
        $example_candidates = "";
        $example_ids = array();
        $candidate_examples = array(
            "タイトルタグの最適化（関連概念） → 優先度: 高",
            "構造化データの実装（同カテゴリ） → 優先度: 中",
            "内部リンクの設置方法（関連概念） → 優先度: 高",
            "パンくずリストの実装（関連概念） → 優先度: 中",
            "Googleアナリティクスの設定（無関係） → 優先度: 低",
            "robots.txtの設定方法（関連概念） → 優先度: 中",
            "サイトマップの作成（関連概念） → 優先度: 中",
            "ページ速度の改善（関連概念） → 優先度: 中",
            "モバイルフレンドリー対応（関連概念） → 優先度: 中",
            "SSL証明書の導入（無関係） → 優先度: 低"
        );

        // max_postsの数だけ例を生成（最大10件まで）
        $num_examples = min($max_posts, count($candidate_examples));
        for ($i = 0; $i < $num_examples; $i++) {
            $example_candidates .= "- " . $candidate_examples[$i] . "\n";
            $example_ids[] = (100 + $i * 11); // 100, 111, 122, 133...のようなID例
        }
        $example_output = implode(',', $example_ids);

        // 候補記事数をカウント
        $available_candidates = count($candidate_posts_data);
        $required_count = min($max_posts, $available_candidates);

        $prompt = "あなたは記事の関連性を判定する専門家です。\n\n" .
                  "## タスク\n" .
                  "候補記事一覧から、現在の記事と関連性の高い順に正確に{$required_count}件を選択してください。\n" .
                  "【絶対厳守】候補記事が{$available_candidates}件あります。必ず{$required_count}件を選択してください。{$required_count}件より多くても少なくても選択しないでください。\n\n" .
                  "## 判定手順\n" .
                  "1. 現在記事の主要テーマとキーワードを特定\n" .
                  "2. 各候補記事のテーマとの関連度をスコアリング\n" .
                  "3. 以下の優先順位で評価:\n" .
                  $criteria_text . "\n\n" .
                  $evaluation_criteria . "\n\n" .
                  "## 重要なルール\n" .
                  "- 候補記事が存在する限り、必ず{$required_count}件を選択すること\n" .
                  "- 関連性が低くても、候補から{$required_count}件選ぶこと\n" .
                  "- 記事を除外せず、必ず指定件数を満たすこと\n\n" .
                  "## 例（{$required_count}件選択する場合）\n" .
                  "現在記事: 「SEOにおけるメタディスクリプションの書き方」\n" .
                  "候補:\n" .
                  $example_candidates . "\n" .
                  $current_info . $candidates_info .
                  "## 選択基準\n" .
                  "- タイトルに同じキーワードを含む記事を最優先\n" .
                  "- タグの一致も重要な判断材料\n" .
                  "- 候補リストの順番ではなく、内容の関連性で判断\n" .
                  "- カテゴリ一致よりも内容・テーマの一致を重視\n" .
                  "- 関連性が低い記事も、{$required_count}件を満たすために含める\n\n" .
                  "## 出力形式\n" .
                  "関連性の高い順に{$required_count}件の記事IDをカンマ区切りで出力してください。\n" .
                  "重要: 「ID=」の後の数値を使用してください。\n" .
                  "出力例（{$required_count}件の場合）: {$example_output}\n\n" .
                  "【再確認】必ず{$required_count}件の記事IDを出力してください。説明や理由は不要です。";

        $data = array(
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            // max_tokens は非推奨で o 系・推論モデルに使えないため max_completion_tokens を使う。
            // 推論モデルは考える分のトークンもこの上限に数えるので、答え (記事 ID の列) より大きく取る
            'max_completion_tokens' => self::CHAT_MAX_COMPLETION_TOKENS,
            'temperature' => $temperature
        );

        if (!empty($model)) {
            $data['model'] = $model;
        }

        $sent = $this->post_chat($api_key, $data);
        if (is_wp_error($sent)) {
            $this->log_api_failure();
            return $sent;
        }
        $body = $sent['body'];

        // API呼び出し成功時にログを記録
        $this->log_api_call();

        $json_result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'JSON解析エラー: ' . json_last_error_msg());
        }

                if (isset($json_result['choices'][0]['message']['content'])) {
            $content = trim($json_result['choices'][0]['message']['content']);


            if (empty($content)) {
                return new WP_Error('empty_response', 'AIからの応答が空でした');
            }

            $selected_ids = array_map('intval', array_filter(explode(',', $content)));

            // 候補記事のIDリストを取得
            $candidate_ids = array_column($candidate_posts_data, 'id');

            // AIが返したIDが候補記事に含まれているかチェック
            $valid_selected_ids = array_intersect($selected_ids, $candidate_ids);
            $invalid_ids = array_diff($selected_ids, $candidate_ids);

            if (!empty($invalid_ids)) {
            }

            if (empty($valid_selected_ids)) {
                return new WP_Error('invalid_ai_response', 'AI応答に有効な記事IDが含まれていませんでした');
            }

            // 件数チェック：候補が十分あるのにAIが不足数を返した場合は補完する
            $required_count = min($max_posts, count($candidate_ids));
            if (count($valid_selected_ids) < $required_count && count($candidate_ids) >= $required_count) {
                // 不足分を候補から補完（AIが選ばなかった記事から追加）
                $remaining_candidates = array_diff($candidate_ids, $valid_selected_ids);
                $needed_count = $required_count - count($valid_selected_ids);

                // 残りの候補から必要数だけ追加
                $additional_ids = array_slice($remaining_candidates, 0, $needed_count);
                $valid_selected_ids = array_merge($valid_selected_ids, $additional_ids);
            }

            return array_slice($valid_selected_ids, 0, $max_posts);
        } else {
            return new WP_Error('invalid_response', 'AIからの応答を解析できませんでした');
        }
    }
    /**
     * APIキーの確認（GET /v1/models。トークンを消費しない）
     */
    public function test_api_key($api_key) {
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => 'APIキーが入力されていません。'
            );
        }

        $models = $this->list_models($api_key);
        if (is_wp_error($models)) {
            return array(
                'success' => false,
                'message' => $models->get_error_message()
            );
        }

        $embedding_count = 0;
        foreach ($models as $model) {
            if (isset($model['id']) && is_string($model['id']) && stripos($model['id'], 'embedding') !== false) {
                $embedding_count++;
            }
        }

        return array(
            'success' => true,
            'message' => sprintf('APIキーは有効です（利用できるモデル %d 件、うち embedding モデル %d 件）', count($models), $embedding_count)
        );
    }

    /**
     * POST /v1/chat/completions。モデルが temperature の指定を受け付けない (HTTP 400) ときは、temperature を外して 1 回だけ送り直す
     *
     * @return array{status:int, body:string}|WP_Error HTTP 200 のときだけ配列
     */
    private function post_chat($api_key, array $data) {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($data),
                'timeout' => 60,
                'user-agent' => $this->user_agent(),
            ));

            if (is_wp_error($response)) {
                return new WP_Error('api_connection', 'OpenAI に接続できませんでした: ' . $response->get_error_message(), array('status' => 0));
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($status === 200) {
                return array('status' => $status, 'body' => $body);
            }

            if ($attempt === 0 && $status === 400 && isset($data['temperature']) && $this->is_param_error($body, 'temperature')) {
                unset($data['temperature']);
                continue;
            }

            return new WP_Error('api_http_' . $status, sprintf('OpenAI API エラー（HTTP %d）: %s', $status, $this->format_error_message($body)), array('status' => $status));
        }
        return new WP_Error('api_error', 'OpenAI API の呼び出しに失敗しました', array('status' => 0));
    }

    /**
     * エラー応答が指定したパラメータについてのものか (error.param、無ければ error.message に名前があるか)
     */
    private function is_param_error($body, $param) {
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return false;
        }
        if (isset($decoded['error']['param']) && $decoded['error']['param'] === $param) {
            return true;
        }
        return isset($decoded['error']['message']) && is_string($decoded['error']['message']) && stripos($decoded['error']['message'], $param) !== false;
    }

    /**
     * GPT モデルが関連記事の選定 (chat completions) に使えるかを、短い依頼を 1 回送って確かめる
     *
     * @return true|WP_Error
     */
    public function test_chat_model($api_key, $model) {
        $sent = $this->post_chat($api_key, array(
            'model' => $model,
            'messages' => array(array('role' => 'user', 'content' => 'Reply with OK.')),
            'max_completion_tokens' => self::CHAT_MAX_COMPLETION_TOKENS,
            'temperature' => 0.1
        ));
        if (is_wp_error($sent)) {
            return $sent;
        }
        $decoded = json_decode($sent['body'], true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message'])) {
            return new WP_Error('invalid_response', 'このモデルの応答を解析できませんでした');
        }
        return true;
    }

    /**
     * 利用できるモデルの一覧（GET /v1/models）
     *
     * @return array|WP_Error data 配列（各要素に id / created / owned_by など）
     */
    public function list_models($api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'headers' => array('Authorization' => 'Bearer ' . $api_key),
            'timeout' => 15,
            'user-agent' => $this->user_agent(),
        ));

        $decoded = $this->decode_response($response);
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            return new WP_Error('invalid_response', 'OpenAI からのモデル一覧を解析できませんでした');
        }
        return $decoded['data'];
    }

    /**
     * 文章のベクトルを作る（POST /v1/embeddings、encoding_format=base64）
     *
     * @param string   $api_key
     * @param string   $model
     * @param string[] $texts
     * @return string[]|WP_Error 入力と同じ順の base64 文字列（float32 リトルエンディアン）
     */
    public function create_embeddings($api_key, $model, array $texts) {
        $texts = array_values($texts);
        if (empty($texts)) {
            return array();
        }

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode(array(
                'model' => $model,
                'input' => $texts,
                'encoding_format' => 'base64'
            )),
            'timeout' => 30,
            'user-agent' => $this->user_agent(),
        ));

        $decoded = $this->decode_response($response);
        if (is_wp_error($decoded)) {
            $this->log_api_failure('embedding');
            return $decoded;
        }
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            $this->log_api_failure('embedding');
            return new WP_Error('invalid_response', 'OpenAI からの embedding を解析できませんでした');
        }

        $this->log_api_call('embedding');

        $vectors = array_fill(0, count($texts), '');
        foreach ($decoded['data'] as $item) {
            if (isset($item['index'], $item['embedding']) && is_int($item['index']) && is_string($item['embedding']) && $item['index'] >= 0 && $item['index'] < count($texts)) {
                $vectors[$item['index']] = $item['embedding'];
            }
        }
        return $vectors;
    }

    /**
     * HTTP 応答を JSON として読む。失敗時は HTTP ステータスを data に入れた WP_Error
     */
    private function decode_response($response) {
        if (is_wp_error($response)) {
            return new WP_Error('api_connection', 'OpenAI に接続できませんでした: ' . $response->get_error_message(), array('status' => 0));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status !== 200) {
            return new WP_Error('api_http_' . $status, sprintf('OpenAI API エラー（HTTP %d）: %s', $status, $this->format_error_message($body)), array('status' => $status));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('json_error', 'OpenAI からの応答を解析できませんでした', array('status' => $status));
        }
        return $decoded;
    }

    private function user_agent() {
        return 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url();
    }

    private function format_error_message($body) {
        $decoded = json_decode($body, true);
        if ($decoded && isset($decoded['error'])) {
            if (isset($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }
            if (is_string($decoded['error'])) {
                return $decoded['error'];
            }
        }
        return wp_strip_all_tags(substr((string) $body, 0, 200));
    }

    private function debug_log($message) {
        // Debug logging disabled
    }
    /**
     * API 呼び出し統計の記録先 (種類ごとに成功・失敗の option を分ける)
     * gpt: 関連記事の選定 (chat completions) / embedding: 記事のベクトル作成
     */
    public static function log_option($kind, $type) {
        $options = array(
            'gpt' => array(
                'success' => 'kashiwazaki_seo_related_posts_api_logs',
                'failure' => 'kashiwazaki_seo_related_posts_api_failure_logs',
            ),
            'embedding' => array(
                'success' => 'kashiwazaki_seo_related_posts_embedding_api_logs',
                'failure' => 'kashiwazaki_seo_related_posts_embedding_api_failure_logs',
            ),
        );
        $kind = isset($options[$kind]) ? $kind : 'gpt';
        $type = ($type === 'failure') ? 'failure' : 'success';
        return $options[$kind][$type];
    }

    /**
     * API呼び出しをログに記録
     */
    private function log_api_call($kind = 'gpt') {
        $this->record_log(self::log_option($kind, 'success'));
    }

    /**
     * API失敗をログに記録
     */
    private function log_api_failure($kind = 'gpt') {
        $this->record_log(self::log_option($kind, 'failure'));
    }

    /**
     * 時刻を 1 件足す（1年以上前のものと、10000 件を超えた古いものは消す）
     */
    private function record_log($option_name) {
        $logs = get_option($option_name, array());
        if (!is_array($logs)) {
            $logs = array();
        }
        $logs[] = time();

        $one_year_ago = time() - 31536000;
        $logs = array_filter($logs, function($timestamp) use ($one_year_ago) {
            return $timestamp > $one_year_ago;
        });

        if (count($logs) > 10000) {
            $logs = array_slice($logs, -10000);
        }

        update_option($option_name, array_values($logs));
    }
}
