<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_RelatedPosts {

    private $similarity_calculator;
    private $api;

    public function __construct($similarity_calculator, $api) {
        $this->similarity_calculator = $similarity_calculator;
        $this->api = $api;
    }

    public function get_related_posts($post_id, $options = array()) {
        $defaults = array(
            'max_posts' => 5,
            'use_ai' => false,
            'method' => 'similarity',
            'exclude_current' => true,
            'post_types' => array('post'),
            'min_score' => 25
        );

        $options = wp_parse_args($options, $defaults);

        // post_typesが文字列の場合は配列に変換
        if (isset($options['post_types']) && is_string($options['post_types'])) {
            $options['post_types'] = array_map('trim', explode(',', $options['post_types']));
        }



        $current_post = get_post($post_id);
        if (!$current_post) {
            return array();
        }

        $is_ai_enabled = $this->is_ai_enabled();

        if ($options['use_ai'] && $is_ai_enabled) {
            $result = $this->get_related_posts_with_ai($post_id, $options);
        } else {
            $result = $this->get_related_posts_by_similarity($post_id, $options);
        }
        return $result;
    }

    private function get_related_posts_by_similarity($post_id, $options) {
        $candidate_posts = $this->get_candidate_posts($post_id, $options);
        $all_scored_posts = array(); // すべての候補のスコア
        $added_post_ids = array(); // 追加済みのpost_idを追跡
        $debug_info = array(); // デバッグ情報を保存

        $debug_mode = get_option('kashiwazaki_seo_related_posts_debug_mode', false);

        // すべての候補記事のスコアを計算
        foreach ($candidate_posts as $candidate_id) {
            if ($options['exclude_current'] && $candidate_id == $post_id) {
                continue;
            }

            // 重複チェック：既に追加済みの記事はスキップ
            if (in_array($candidate_id, $added_post_ids)) {
                continue;
            }

            $score = $this->similarity_calculator->calculate_similarity($post_id, $candidate_id, null, $debug_mode, $options);

            // すべての記事を配列に保存（min_scoreでフィルタリングしない）
            $all_scored_posts[] = array(
                'post_id' => $candidate_id,
                'score' => $score,
                'post' => get_post($candidate_id)
            );

            // デバッグモードの場合、詳細情報を保存
            if ($debug_mode) {
                $candidate_post = get_post($candidate_id);
                $score_details = $this->similarity_calculator->get_last_score_details();
                $debug_info[] = array(
                    'post_id' => $candidate_id,
                    'title' => $candidate_post ? $candidate_post->post_title : 'Unknown',
                    'total_score' => $score,
                    'details' => $score_details,
                    'passed' => $score >= $options['min_score']
                );
            }

            $added_post_ids[] = $candidate_id; // 追加したIDを記録
        }

        // スコア順にソート（降順）
        usort($all_scored_posts, function($a, $b) {
            $score_a = floatval($a['score']);
            $score_b = floatval($b['score']);
            if ($score_b == $score_a) return 0;
            return ($score_b > $score_a) ? 1 : -1;
        });

        // min_scoreを満たす記事を抽出
        $high_score_posts = array_filter($all_scored_posts, function($post) use ($options) {
            return $post['score'] >= $options['min_score'];
        });

        // 候補が十分にある場合は、必ず max_posts 件を返す
        if (count($high_score_posts) >= $options['max_posts']) {
            // 高スコア記事から指定数を返す
            $final_result = array_slice($high_score_posts, 0, $options['max_posts']);
        } else {
            // 高スコア記事が不足している場合は、全候補から上位を返す
            $final_result = array_slice($all_scored_posts, 0, $options['max_posts']);
        }

        // デバッグ情報を保存
        if ($debug_mode) {
            update_post_meta($post_id, '_kashiwazaki_debug_info', array(
                'candidate_count' => count($candidate_posts),
                'min_score' => $options['min_score'],
                'passed_count' => count($high_score_posts),
                'final_count' => count($final_result),
                'all_candidates' => $debug_info
            ));
        }

        return $final_result;
    }

    private function get_related_posts_with_ai($post_id, $options) {
        $candidate_posts = $this->get_candidate_posts($post_id, $options);

        if (empty($candidate_posts)) {
            return $this->get_related_posts_by_similarity($post_id, $options);
        }

                $ai_threshold = get_option('kashiwazaki_seo_related_posts_ai_threshold', 25);
        $pre_filtered_candidates = array();

        // AI分析用候補数追加設定を取得
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $ai_candidate_buffer = isset($plugin_options['ai_candidate_buffer']) ? $plugin_options['ai_candidate_buffer'] : 20;
        $ai_candidate_limit = $options['max_posts'] + $ai_candidate_buffer;

        if (count($candidate_posts) > $ai_candidate_limit) {
            $candidate_scores = array();

            foreach ($candidate_posts as $candidate_id) {
                if ($options['exclude_current'] && $candidate_id == $post_id) {
                    continue;
                }

                $score = $this->similarity_calculator->calculate_similarity($post_id, $candidate_id, null, false, $options);
                $candidate_scores[] = array(
                    'post_id' => $candidate_id,
                    'score' => $score
                );
            }

            // スコア順でソート
            usort($candidate_scores, function($a, $b) {
                $score_a = floatval($a['score']);
                $score_b = floatval($b['score']);
                if ($score_b == $score_a) return 0;
                return ($score_b > $score_a) ? 1 : -1;
            });

                        // 上位（最大表示数+設定値）件を取得
            $pre_filtered_candidates = array_slice($candidate_scores, 0, $ai_candidate_limit);
            $candidate_post_ids = array_column($pre_filtered_candidates, 'post_id');

            $last_index = count($pre_filtered_candidates) - 1;
        } else {
            $candidate_post_ids = $candidate_posts;
            $current_count = count($candidate_post_ids);

            if ($current_count < $ai_candidate_limit) {
                // 不足分をランダムに追加
                $needed_count = $ai_candidate_limit - $current_count;

                $random_posts = $this->get_random_posts($post_id, $options, $needed_count, $candidate_post_ids);
                $candidate_post_ids = array_merge($candidate_post_ids, $random_posts);

            } else {
            }
        }

        if (empty($candidate_post_ids)) {
            return $this->get_related_posts_by_similarity($post_id, $options);
        }


        $current_post = get_post($post_id);
        $current_post_data = $this->extract_post_data($current_post);
        $candidate_posts_data = array();

        foreach ($candidate_post_ids as $candidate_id) {
            $candidate_post = get_post($candidate_id);
            if ($candidate_post) {
                $candidate_posts_data[] = $this->extract_post_data($candidate_post);
            }
        }

        // API設定を取得
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_provider = isset($plugin_options['api_provider']) ? $plugin_options['api_provider'] : 'openrouter';

        // API選択に基づいてAPIキーを取得
        if ($api_provider === 'openrouter') {
            $api_key = isset($plugin_options['openrouter_api_key']) ? $plugin_options['openrouter_api_key'] : '';
            // 互換性のため、古いapi_keyも確認
            if (empty($api_key) && isset($plugin_options['api_key'])) {
                $api_key = $plugin_options['api_key'];
            }
            $model = isset($plugin_options['model']) ? $plugin_options['model'] : '';
        } else {
            $api_key = isset($plugin_options['openai_api_key']) ? $plugin_options['openai_api_key'] : '';
            $model = 'gpt-4.1-nano'; // OpenAI GPTでは固定モデル
        }

        if (empty($api_key)) {
            return $this->get_related_posts_by_similarity($post_id, $options);
        }


        $search_methods = isset($options['search_methods']) ? $options['search_methods'] : null;

        $ai_result = $this->api->analyze_related_posts_with_ai(
            $current_post_data,
            $candidate_posts_data,
            $api_key,
            $model,
            $options['max_posts'],
            $search_methods
        );

        if (is_wp_error($ai_result)) {
            return $this->get_related_posts_by_similarity($post_id, $options);
        }


        // AIの結果から重複を完全に排除
        $ai_result_unique = array_unique($ai_result);

        $related_posts = array();
        $added_post_ids = array(); // 追加済みのpost_idを追跡

        foreach ($ai_result_unique as $index => $selected_id) {
            // 念のため、既に追加されていないか再チェック
            if (in_array($selected_id, $added_post_ids)) {
                continue;
            }

            $post = get_post($selected_id);
            if ($post) {
                // AI順位に基づいてスコアを計算（1位=100, 2位=95, 3位=90...）
                $ai_score = 100 - ($index * 5);
                $ai_score = max($ai_score, 10); // 最低10点

                $related_posts[] = array(
                    'post_id' => $selected_id,
                    'score' => $ai_score,
                    'post' => $post,
                    'method' => 'ai'
                );

                $added_post_ids[] = $selected_id;
            }
        }

        return $related_posts;
    }

    /**
     * search_methodsの配列から検索戦略を決定
     */
    private function determine_search_method($search_methods) {
        if (empty($search_methods)) {
            return 'comprehensive';
        }

        // 選択された方法に基づいて戦略を決定
        $has_categories = in_array('categories', $search_methods);
        $has_tags = in_array('tags', $search_methods);
        $has_title = in_array('title', $search_methods);
        $has_excerpt = in_array('excerpt', $search_methods);
        $has_directory = in_array('directory', $search_methods);

        if ($has_categories && $has_tags) {
            return 'category_tag';
        } elseif ($has_categories && !$has_tags) {
            return 'category_only';
        } elseif ($has_tags && !$has_categories) {
            return 'tag_only';
        } elseif ($has_title) {
            return 'title_only';
        } elseif ($has_excerpt) {
            return 'excerpt_only';
        } elseif ($has_directory) {
            return 'path_based';
        } else {
            return 'comprehensive';
        }
    }

    private function get_candidate_posts($post_id, $options) {
        $current_post = get_post($post_id);
        if (!$current_post) {
            return array();
        }

        // search_methodsから検索方法を決定
        $search_methods = isset($options['search_methods']) ? $options['search_methods'] : array('tags', 'categories');
        $search_method = $this->determine_search_method($search_methods);
        $candidate_limit = get_option('kashiwazaki_seo_related_posts_candidate_limit', 100);

        if (empty($search_methods)) {
        }

        $candidates = array();

        switch ($search_method) {
            case 'category_tag':
                $candidates = $this->get_candidates_by_category_and_tag($post_id, $options, $candidate_limit);
                break;
            case 'category_only':
                $candidates = $this->get_candidates_by_category($post_id, $options, $candidate_limit);
                break;
            case 'tag_only':
                $candidates = $this->get_candidates_by_tag($post_id, $options, $candidate_limit);
                break;
            case 'title_only':
                $candidates = $this->get_candidates_by_title($post_id, $options, $candidate_limit);
                break;
            case 'excerpt_only':
                $candidates = $this->get_candidates_by_excerpt($post_id, $options, $candidate_limit);
                break;
            case 'path_based':
                $candidates = $this->get_candidates_by_path($post_id, $options, $candidate_limit);
                break;
            case 'comprehensive':
            default:
                $candidates = $this->get_candidates_comprehensive($post_id, $options, $candidate_limit);
                break;
        }


        return $candidates;
    }

    private function get_candidates_by_category_and_tag($post_id, $options, $limit) {
        $tags = wp_get_post_tags($post_id, array('fields' => 'ids'));
        $current_tags = wp_get_post_tags($post_id, array('fields' => 'names'));


        // 汎用的なタグを特定
        $generic_tags = array(
            'SEO', 'SEO対策', 'Google', '検索エンジン', 'ウェブサイト', 'コンテンツ', 'HTML',
            'WordPress', 'ブログ', '記事', '投稿', 'サイト', 'ページ', 'Web', 'インターネット',
            'おすすめ', 'まとめ', '紹介', '解説', '方法', '使い方', '初心者', '入門'
        );
        $specific_tags = array_diff($current_tags, $generic_tags);
        $generic_only_tags = array_intersect($current_tags, $generic_tags);


        $candidate_ids = array();

        // 1. 特定的なタグで検索（最優先）
        if (!empty($specific_tags)) {
            $specific_tag_ids = array();
            foreach ($specific_tags as $tag_name) {
                $tag = get_term_by('name', $tag_name, 'post_tag');
                if ($tag) {
                    $specific_tag_ids[] = $tag->term_id;
                }
            }

            if (!empty($specific_tag_ids)) {
                $args = array(
                    'post_type' => $options['post_types'],
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in' => array($post_id),
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'post_tag',
                            'field' => 'term_id',
                            'terms' => $specific_tag_ids,
                            'operator' => 'IN'
                        )
                    ),
                    'orderby' => 'date',
                    'order' => 'DESC'
                );

                $query = new WP_Query($args);
                $candidate_ids = wp_list_pluck($query->posts, 'ID');
            }
        }

        // 2. 特定的タグで足りない場合、汎用的タグで補完
        if (count($candidate_ids) < $limit && !empty($generic_only_tags)) {
            $generic_tag_ids = array();
            foreach ($generic_only_tags as $tag_name) {
                $tag = get_term_by('name', $tag_name, 'post_tag');
                if ($tag) {
                    $generic_tag_ids[] = $tag->term_id;
                }
            }

            if (!empty($generic_tag_ids)) {
                $args = array(
                    'post_type' => $options['post_types'],
                    'post_status' => 'publish',
                    'posts_per_page' => $limit - count($candidate_ids),
                    'post__not_in' => array_merge(array($post_id), $candidate_ids),
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'post_tag',
                            'field' => 'term_id',
                            'terms' => $generic_tag_ids,
                            'operator' => 'IN'
                        )
                    ),
                    'orderby' => 'date',
                    'order' => 'DESC'
                );

                $query = new WP_Query($args);
                $generic_candidates = wp_list_pluck($query->posts, 'ID');
                $candidate_ids = array_merge($candidate_ids, $generic_candidates);
            }
        }

        // 3. タグで足りない場合はカテゴリで補完
        if (count($candidate_ids) < $limit) {
            $categories = wp_get_post_categories($post_id);

            if (!empty($categories)) {
                $args = array(
                    'post_type' => $options['post_types'],
                    'post_status' => 'publish',
                    'posts_per_page' => $limit - count($candidate_ids),
                    'post__not_in' => array_merge(array($post_id), $candidate_ids),
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'category',
                            'field' => 'term_id',
                            'terms' => $categories,
                            'operator' => 'IN'
                        )
                    ),
                    'orderby' => 'date',
                    'order' => 'DESC'
                );

                $query = new WP_Query($args);
                $category_candidates = wp_list_pluck($query->posts, 'ID');
                $candidate_ids = array_merge($candidate_ids, $category_candidates);
            }
        }

        // 4. まだ足りない場合は同じ投稿タイプから補完
        if (count($candidate_ids) < $limit) {
            $args = array(
                'post_type' => $options['post_types'],
                'post_status' => 'publish',
                'posts_per_page' => $limit - count($candidate_ids),
                'post__not_in' => array_merge(array($post_id), $candidate_ids),
                'orderby' => 'date',
                'order' => 'DESC'
            );

            $query = new WP_Query($args);
            $fallback_candidates = wp_list_pluck($query->posts, 'ID');
            $candidate_ids = array_merge($candidate_ids, $fallback_candidates);
        }

        $candidate_ids = array_unique($candidate_ids);

        return $candidate_ids;
    }

    private function get_candidates_by_category($post_id, $options, $limit) {
        $categories = wp_get_post_categories($post_id);

        if (empty($categories)) {
            return array();
        }

        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($post_id),
            'category__in' => $categories,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        // カテゴリフィルタが設定されている場合は適用
        if (!empty($options['filter_categories'])) {
            // 既存のcategory__inと filter_categories の交差を取る
            $filtered_categories = array_intersect($categories, $options['filter_categories']);
            if (empty($filtered_categories)) {
                // 交差がない場合は、filter_categories から検索
                $args['category__in'] = $options['filter_categories'];
            } else {
                $args['category__in'] = $filtered_categories;
            }
        }

        $query = new WP_Query($args);
        $candidate_ids = wp_list_pluck($query->posts, 'ID');
        return array_unique($candidate_ids);
    }

    private function get_candidates_by_tag($post_id, $options, $limit) {
        $tags = wp_get_post_tags($post_id, array('fields' => 'ids'));

        if (empty($tags)) {
            return array();
        }

        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($post_id),
            'tag__in' => $tags,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $candidate_ids = wp_list_pluck($query->posts, 'ID');
        return array_unique($candidate_ids);
    }

    private function get_candidates_by_path($post_id, $options, $limit) {
        $current_post_path = str_replace(home_url(), '', get_permalink($post_id));
        $current_segments = array_filter(explode('/', trim($current_post_path, '/')));

        if (empty($current_segments)) {
            return array();
        }

        $search_segment = $current_segments[0];

        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $limit * 2,
            'post__not_in' => array($post_id),
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $matching_posts = array();

        foreach ($query->posts as $post) {
            $post_path = str_replace(home_url(), '', get_permalink($post->ID));
            $post_segments = array_filter(explode('/', trim($post_path, '/')));

            if (!empty($post_segments) && $post_segments[0] === $search_segment) {
                $matching_posts[] = $post->ID;
                if (count($matching_posts) >= $limit) {
                    break;
                }
            }
        }

        return array_unique($matching_posts);
    }

    private function get_candidates_by_title($post_id, $options, $limit) {
        $current_post = get_post($post_id);
        if (!$current_post) {
            return array();
        }


        $title_words = $this->extract_keywords($current_post->post_title);

        if (empty($title_words)) {
            return array();
        }

        $search_terms = implode(' ', array_slice($title_words, 0, 3));

        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($post_id),
            's' => $search_terms,
            'orderby' => 'relevance',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $candidate_ids = wp_list_pluck($query->posts, 'ID');

        return array_unique($candidate_ids);
    }

    private function get_candidates_by_excerpt($post_id, $options, $limit) {
        $current_post = get_post($post_id);
        if (!$current_post) {
            return array();
        }

        $excerpt = !empty($current_post->post_excerpt) ? $current_post->post_excerpt : wp_trim_words(strip_tags($current_post->post_content), 30);
        $excerpt_words = $this->extract_keywords($excerpt);

        if (empty($excerpt_words)) {
            return array();
        }

        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($post_id),
            's' => implode(' ', array_slice($excerpt_words, 0, 5)),
            'orderby' => 'relevance',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $candidate_ids = wp_list_pluck($query->posts, 'ID');
        return array_unique($candidate_ids);
    }

    private function extract_keywords($text) {
        $original_text = $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text);

        $words = array_filter($words, function($word) {
            return mb_strlen($word) >= 2 && !$this->is_stop_word($word);
        });

        $keywords = array_unique($words);

        return $keywords;
    }

    private function is_stop_word($word) {
        $stop_words = array(
            'の', 'に', 'は', 'を', 'た', 'が', 'で', 'て', 'と', 'し', 'れ', 'さ', 'な', 'み', 'る', 'ます',
            'です', 'ある', 'いる', 'する', 'した', 'して', 'ない', 'から', 'まで', 'より', 'など', 'また',
            'この', 'その', 'あの', 'どの', 'ここ', 'そこ', 'あそこ', 'どこ', 'こう', 'そう', 'ああ', 'どう',
            'これ', 'それ', 'あれ', 'どれ', 'だ', 'である', 'だった', 'だろう', 'でしょう', 'かもしれない'
        );

        return in_array($word, $stop_words);
    }

    private function get_candidates_comprehensive($post_id, $options, $limit) {
        $category_candidates = $this->get_candidates_by_category($post_id, $options, intval($limit * 0.4));
        $tag_candidates = $this->get_candidates_by_tag($post_id, $options, intval($limit * 0.3));
        $path_candidates = $this->get_candidates_by_path($post_id, $options, intval($limit * 0.2));

        $remaining_limit = $limit - count($category_candidates) - count($tag_candidates) - count($path_candidates);
        $recent_candidates = array();

        if ($remaining_limit > 0) {
            $args = array(
                'post_type' => $options['post_types'],
                'post_status' => 'publish',
                'posts_per_page' => $remaining_limit,
                'post__not_in' => array_merge(array($post_id), $category_candidates, $tag_candidates, $path_candidates),
                'orderby' => 'date',
                'order' => 'DESC'
            );

            // カテゴリフィルタが設定されている場合は適用
            if (!empty($options['filter_categories'])) {
                $args['category__in'] = $options['filter_categories'];
            }

            $query = new WP_Query($args);
            $recent_candidates = wp_list_pluck($query->posts, 'ID');
        }

        $all_candidates = array_unique(array_merge($category_candidates, $tag_candidates, $path_candidates, $recent_candidates));
        return array_slice($all_candidates, 0, $limit);
    }

    private function extract_post_data($post) {
        $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
        $excerpt = !empty($post->post_excerpt) ? wp_trim_words($post->post_excerpt, 30) : wp_trim_words(strip_tags($post->post_content), 30);

        $post_path = str_replace(home_url(), '', get_permalink($post->ID));
        $path_segments = array_filter(explode('/', trim($post_path, '/')));

        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => $excerpt,
            'categories' => $categories,
            'post_type' => $post->post_type,
            'path_segments' => $path_segments,
            'content_length' => strlen(strip_tags($post->post_content)),
            'publish_date' => $post->post_date
        );
    }

    private function is_ai_enabled() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_provider = isset($options['api_provider']) ? $options['api_provider'] : 'openrouter';

        // API選択に基づいてAPIキーを取得
        if ($api_provider === 'openrouter') {
            $api_key = isset($options['openrouter_api_key']) ? $options['openrouter_api_key'] : '';
            // 互換性のため、古いapi_keyも確認
            if (empty($api_key) && isset($options['api_key'])) {
                $api_key = $options['api_key'];
            }
        } else {
            $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
        }

        $api_key_exists = !empty($api_key);


        return $api_key_exists;
    }

    public function render_related_posts($post_id, $options = array()) {
        $related_posts = $this->get_related_posts($post_id, $options);

        if (empty($related_posts)) {
            return '';
        }

        $template = get_option('kashiwazaki_seo_related_posts_template', 'default');
        $show_excerpt = get_option('kashiwazaki_seo_related_posts_show_excerpt', true);
        $show_thumbnail = get_option('kashiwazaki_seo_related_posts_show_thumbnail', true);
        $show_date = get_option('kashiwazaki_seo_related_posts_show_date', false);

        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $heading_text = isset($plugin_options['heading_text']) ? $plugin_options['heading_text'] : '関連記事';
        $heading_tag = isset($plugin_options['heading_tag']) ? $plugin_options['heading_tag'] : 'h2';

        $output = '<div class="kashiwazaki-related-posts">';
        $output .= '<' . esc_attr($heading_tag) . ' class="kashiwazaki-related-posts-title">' . esc_html($heading_text) . '</' . esc_attr($heading_tag) . '>';
        $output .= '<div class="kashiwazaki-related-posts-list">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];
            $score = isset($related_post['score']) ? $related_post['score'] : 0;
            $method = isset($related_post['method']) ? $related_post['method'] : 'similarity';

            $output .= '<div class="kashiwazaki-related-post-item" data-score="' . esc_attr($score) . '" data-method="' . esc_attr($method) . '">';

            if ($show_thumbnail && has_post_thumbnail($post->ID)) {
                $output .= '<div class="kashiwazaki-related-post-thumbnail">';
                $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">';
                $output .= get_the_post_thumbnail($post->ID, 'thumbnail');
                $output .= '</a>';
                $output .= '</div>';
            }

            $output .= '<div class="kashiwazaki-related-post-content">';
            $output .= '<h4 class="kashiwazaki-related-post-title">';
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($post->post_title) . '</a>';
            $output .= '</h4>';

            if ($show_date) {
                $output .= '<div class="kashiwazaki-related-post-date">';
                $output .= esc_html(get_the_date('Y年m月d日', $post->ID));
                $output .= '</div>';
            }

            if ($show_excerpt) {
                $excerpt = !empty($post->post_excerpt) ? wp_trim_words($post->post_excerpt, 30) : wp_trim_words(strip_tags($post->post_content), 30);
                $output .= '<div class="kashiwazaki-related-post-excerpt">';
                $output .= esc_html($excerpt);
                $output .= '</div>';
            }

            $output .= '</div>';
            $output .= '</div>';
        }

        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * ランダムに記事を取得してAI候補を補完
     */
    private function get_random_posts($current_post_id, $options, $needed_count, $exclude_ids = array()) {
        if ($needed_count <= 0) {
            return array();
        }

        // 除外IDリストに現在の記事も追加し、重複を排除
        $exclude_ids[] = $current_post_id;
        $exclude_ids = array_unique($exclude_ids);


        $args = array(
            'post_type' => $options['post_types'],
            'post_status' => 'publish',
            'posts_per_page' => $needed_count * 2, // 余裕を持って多めに取得
            'post__not_in' => $exclude_ids,
            'orderby' => 'rand', // ランダム順
            'fields' => 'ids' // IDのみ取得で高速化
        );

        $query = new WP_Query($args);
        $random_post_ids = $query->posts;

        // 重複を排除してから必要な数だけ切り取り
        $random_post_ids = array_unique($random_post_ids);
        $final_random_ids = array_slice($random_post_ids, 0, $needed_count);


        return $final_random_ids;
    }
}
