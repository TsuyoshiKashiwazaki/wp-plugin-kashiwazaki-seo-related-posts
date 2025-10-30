<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_API {

    public function __construct() {
        add_action('wp_ajax_get_related_posts_ai', array($this, 'get_related_posts_ai_ajax'));
        add_action('wp_ajax_nopriv_get_related_posts_ai', array($this, 'get_related_posts_ai_ajax'));
        add_action('wp_ajax_check_api_settings', array($this, 'check_api_settings_ajax'));
    }

    public function get_related_posts_ai_ajax() {

        check_ajax_referer('kashiwazaki_seo_related_posts_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $candidate_posts = isset($_POST['candidate_posts']) ? $_POST['candidate_posts'] : array();
        $max_posts = isset($_POST['max_posts']) ? intval($_POST['max_posts']) : 5;


        $current_post = get_post($post_id);
        if (!$current_post) {

            wp_send_json_error('投稿が見つかりません');
        }

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $model = isset($options['model']) ? $options['model'] : $this->models->get_default_model();

        if (empty($api_key)) {

            wp_send_json_error('APIキーが設定されていません。管理画面で設定してください。');
        }

        $current_post_data = $this->extract_post_data($current_post);
        $candidate_posts_data = array();

        foreach ($candidate_posts as $candidate_id) {
            $candidate_post = get_post($candidate_id);
            if ($candidate_post) {
                $candidate_posts_data[] = $this->extract_post_data($candidate_post);
            }
        }

        $related_posts = $this->analyze_related_posts_with_ai($current_post_data, $candidate_posts_data, $api_key, $model, $max_posts);

        if (is_wp_error($related_posts)) {
            $error_message = $related_posts->get_error_message();

            if (!empty($model)) {
                $this->models->add_to_excluded_models($model);
                $fallback_result = $this->try_fallback_model($current_post_data, $candidate_posts_data, $api_key, $max_posts, $model);

                if ($fallback_result['success']) {
                    wp_send_json_success(array(
                        'related_posts' => $fallback_result['related_posts'],
                        'message' => "⚠️ {$model} でエラーが発生したため、{$fallback_result['used_model']} に自動切り替えしました。",
                        'switched_model' => $fallback_result['used_model']
                    ));
                } else {
                    $error_message .= "\n\n⚠️ {$model} でエラーが発生し、他の利用可能なモデルでも処理できませんでした。\n設定画面でモデルを復活させるか、APIキーを確認してください。";
                    wp_send_json_error($error_message);
                }
            } else {
                wp_send_json_error($error_message);
            }
        }


        $actual_model = !empty($model) ? $model : $this->models->get_default_model();
        $model_display_name = $this->models->get_model_display_name($actual_model);

        wp_send_json_success(array(
            'related_posts' => $related_posts,
            'used_model' => $model_display_name,
            'model_id' => $actual_model
        ));
    }

    private function extract_post_data($post) {
        $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
        $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
        $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words(strip_tags($post->post_content), 30);

        $post_path = str_replace(home_url(), '', get_permalink($post->ID));
        $path_segments = array_filter(explode('/', trim($post_path, '/')));

        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => $excerpt,
            'categories' => $categories,
            'tags' => $tags,
            'post_type' => $post->post_type,
            'path_segments' => $path_segments,
            'content_length' => strlen(strip_tags($post->post_content)),
            'publish_date' => $post->post_date
        );
    }

    public function analyze_related_posts_with_ai($current_post_data, $candidate_posts_data, $api_key, $model, $max_posts, $search_methods = null) {
        // API設定を取得
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        // OpenAI APIのみ使用
        $url = 'https://api.openai.com/v1/chat/completions';
        // OpenAI用のモデル設定を取得（デフォルト: gpt-4o-mini）
        $model = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';

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
            'max_tokens' => 200,
            'temperature' => $temperature
        );

        if (!empty($model)) {
            $data['model'] = $model;
        }

        // OpenAI APIのヘッダーを設定
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );

        $json_data = json_encode($data);

        $request_options = array(
            'headers' => $headers,
            'body' => $json_data,
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url(),
            'sslverify' => true,
            'httpversion' => '1.1'
        );


        $response = wp_remote_post($url, $request_options);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_api_failure();
            return new WP_Error('api_error', "API接続エラー: {$error_message}");
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $this->log_api_failure();
            return new WP_Error('api_error', "APIエラー (HTTP {$status_code}): " . $body);
        }

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

    private function try_fallback_model($current_post_data, $candidate_posts_data, $api_key, $max_posts, $failed_model) {
        $available_models = $this->get_fallback_models($failed_model);

        if (empty($available_models)) {
            return array('success' => false, 'message' => '利用可能なフォールバックモデルがありません。');
        }

        foreach ($available_models as $model_id => $model_name) {

            $result = $this->analyze_related_posts_with_ai($current_post_data, $candidate_posts_data, $api_key, $model_id, $max_posts);

            if (!is_wp_error($result)) {
                update_option('kashiwazaki_seo_related_posts_model', $model_id);


                return array(
                    'success' => true,
                    'related_posts' => $result,
                    'used_model' => $this->models->extract_short_model_name($model_name)
                );
            } else {

                $this->models->add_to_excluded_models($model_id);
            }
        }

        return array('success' => false, 'message' => 'すべてのフォールバックモデルが失敗しました。');
    }

    private function get_fallback_models($failed_model) {
        $available_models = $this->models->load_models_from_file();
        unset($available_models[$failed_model]);

        if (empty($available_models)) {
            return array();
        }

        $priority_models = array();
        $models_file = KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'models.txt';

        if (!file_exists($models_file)) {
            return $available_models;
        }

        $lines = file($models_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $category_priority = array(
            'flagship' => 1,
            'premium' => 2,
            'specialized' => 3,
            'lightweight' => 4,
            'custom' => 5
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;

            $parts = explode('|', $line);
            if (count($parts) >= 3) {
                $model_id = trim($parts[0]);
                $display_name = trim($parts[1]);
                $category = trim($parts[2]);

                if (isset($available_models[$model_id])) {
                    $priority = isset($category_priority[$category]) ? $category_priority[$category] : 999;
                    $priority_models[] = array(
                        'model_id' => $model_id,
                        'display_name' => $display_name,
                        'priority' => $priority
                    );
                }
            }
        }

        usort($priority_models, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        $sorted_models = array();
        foreach ($priority_models as $model) {
            $sorted_models[$model['model_id']] = $model['display_name'];
        }

        return $sorted_models;
    }

    public function test_api_key($api_key, $api_provider = 'openai') {

        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => 'APIキーが入力されていません。',
                'log' => 'APIキーが空です'
            );
        }

        // OpenAI APIのみ使用
        $url = 'https://api.openai.com/v1/chat/completions';
        // テストは常に最も安価なモデルで実行
        $data = array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Hello'
                )
            ),
            'max_tokens' => 10,
            'temperature' => 0.7
        );
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );


        $json_data = json_encode($data);

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $json_data,
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url()
        ));


        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            return array(
                'success' => false,
                'message' => 'ネットワークエラー: ' . $error_message,
                'log' => 'WP_Error: ' . $error_message
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);


        $log_info = sprintf(
            "リクエスト: %s\nAPIキー: %s\nステータス: %d\nレスポンス: %s",
            $url,
            substr($api_key, 0, 10) . '...' . substr($api_key, -10),
            $status_code,
            substr($body, 0, 200) . (strlen($body) > 200 ? '...' : '')
        );

        if ($status_code === 200) {
            $decoded = json_decode($body, true);
            if (isset($decoded['choices'][0]['message']['content'])) {
                $ai_response = trim($decoded['choices'][0]['message']['content']);

                return array(
                    'success' => true,
                    'message' => 'APIキーが正常に動作しています（AI応答: ' . substr($ai_response, 0, 50) . '...）',
                    'log' => $log_info . "\nAI応答: " . $ai_response
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'AIからの応答を解析できませんでした',
                    'log' => $log_info
                );
            }
        } else {
            return array(
                'success' => false,
                'message' => "HTTP {$status_code}: " . $this->format_error_message($body),
                'log' => $log_info
            );
        }
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
        return substr($body, 0, 200);
    }

    private function debug_log($message) {
        // Debug logging disabled
    }

    public function check_api_settings_ajax() {
        check_ajax_referer('kashiwazaki_seo_related_posts_nonce', 'nonce');

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $model = isset($options['model']) ? $options['model'] : '';

        $settings = array(
            'api_key_exists' => !empty($api_key),
            'api_key_preview' => !empty($api_key) ? substr($api_key, 0, 10) . '...' . substr($api_key, -10) : '未設定',
            'model' => $model
        );

        wp_send_json_success($settings);
    }

    /**
     * API呼び出しをログに記録
     */
    private function log_api_call() {
        // ログを取得
        $api_logs = get_option('kashiwazaki_seo_related_posts_api_logs', array());

        // 現在のタイムスタンプを追加
        $api_logs[] = time();

        // 1年以上前のログを削除（メモリ節約）
        $one_year_ago = time() - 31536000;
        $api_logs = array_filter($api_logs, function($timestamp) use ($one_year_ago) {
            return $timestamp > $one_year_ago;
        });

        // ログを保存（最大10000件まで）
        if (count($api_logs) > 10000) {
            $api_logs = array_slice($api_logs, -10000);
        }

        update_option('kashiwazaki_seo_related_posts_api_logs', array_values($api_logs));
    }

    /**
     * API失敗をログに記録
     */
    private function log_api_failure() {
        // 失敗ログを取得
        $api_failure_logs = get_option('kashiwazaki_seo_related_posts_api_failure_logs', array());

        // 現在のタイムスタンプを追加
        $api_failure_logs[] = time();

        // 1年以上前のログを削除（メモリ節約）
        $one_year_ago = time() - 31536000;
        $api_failure_logs = array_filter($api_failure_logs, function($timestamp) use ($one_year_ago) {
            return $timestamp > $one_year_ago;
        });

        // ログを保存（最大10000件まで）
        if (count($api_failure_logs) > 10000) {
            $api_failure_logs = array_slice($api_failure_logs, -10000);
        }

        update_option('kashiwazaki_seo_related_posts_api_failure_logs', array_values($api_failure_logs));
    }
}
