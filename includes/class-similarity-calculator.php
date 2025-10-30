<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_SimilarityCalculator {

    private $last_score_details = array();

    public function __construct() {

    }

    public function get_last_score_details() {
        return $this->last_score_details;
    }

    public function calculate_similarity($current_post_id, $candidate_post_id, $weights = null, $debug_mode = false, $options = null) {
        if (!$weights) {
            $weights = $this->get_similarity_weights($options);
        }

        $current_post = get_post($current_post_id);
        $candidate_post = get_post($candidate_post_id);

        if (!$current_post || !$candidate_post) {
            return 0;
        }

        $category_score = $this->calculate_category_similarity($current_post_id, $candidate_post_id);
        $tag_score = $this->calculate_tag_similarity($current_post_id, $candidate_post_id);
        $title_score = $this->calculate_title_similarity($current_post->post_title, $candidate_post->post_title);
        $excerpt_score = $this->calculate_excerpt_similarity($current_post, $candidate_post);
        $path_score = $this->calculate_path_similarity($current_post_id, $candidate_post_id);
        $date_score = $this->calculate_date_similarity($current_post->post_date, $candidate_post->post_date);

        $weighted_scores = array(
            'category' => $category_score * $weights['category'],
            'tag' => $tag_score * $weights['tag'],
            'title' => $title_score * $weights['title'],
            'excerpt' => $excerpt_score * $weights['excerpt'],
            'path' => $path_score * $weights['path'],
            'date' => $date_score * $weights['date']
        );

        $total_score = array_sum($weighted_scores);

        // デバッグモードの場合、詳細を保存
        if ($debug_mode) {
            $this->last_score_details = array(
                'raw_scores' => array(
                    'category' => $category_score,
                    'tag' => $tag_score,
                    'title' => $title_score,
                    'excerpt' => $excerpt_score,
                    'path' => $path_score,
                    'date' => $date_score
                ),
                'weights' => $weights,
                'weighted_scores' => $weighted_scores,
                'total' => $total_score
            );
        }

        return min(100, max(0, $total_score));
    }

    private function calculate_category_similarity($post_id_1, $post_id_2) {
        $categories_1 = wp_get_post_categories($post_id_1);
        $categories_2 = wp_get_post_categories($post_id_2);

        if (empty($categories_1) && empty($categories_2)) {
            return 0;
        }

        if (empty($categories_1) || empty($categories_2)) {
            return 0;
        }

        $intersection = array_intersect($categories_1, $categories_2);
        $union = array_unique(array_merge($categories_1, $categories_2));

        if (empty($union)) {
            return 0;
        }

        return (count($intersection) / count($union)) * 100;
    }

    private function calculate_tag_similarity($post_id_1, $post_id_2) {
        $tags_1 = wp_get_post_tags($post_id_1, array('fields' => 'ids'));
        $tags_2 = wp_get_post_tags($post_id_2, array('fields' => 'ids'));

        if (empty($tags_1) && empty($tags_2)) {
            return 0;
        }

        if (empty($tags_1) || empty($tags_2)) {
            return 0;
        }

        $intersection = array_intersect($tags_1, $tags_2);
        $union = array_unique(array_merge($tags_1, $tags_2));

        if (empty($union)) {
            return 0;
        }

        return (count($intersection) / count($union)) * 100;
    }

    private function calculate_title_similarity($title_1, $title_2) {
        $title_1 = mb_strtolower(trim($title_1));
        $title_2 = mb_strtolower(trim($title_2));

        if (empty($title_1) || empty($title_2)) {
            return 0;
        }

        if ($title_1 === $title_2) {
            return 100;
        }

        $words_1 = $this->extract_keywords($title_1);
        $words_2 = $this->extract_keywords($title_2);

        if (empty($words_1) || empty($words_2)) {
            return 0;
        }

        $intersection = array_intersect($words_1, $words_2);
        $union = array_unique(array_merge($words_1, $words_2));

        if (empty($union)) {
            return 0;
        }

        $jaccard_similarity = count($intersection) / count($union);
        $levenshtein_similarity = $this->calculate_levenshtein_similarity($title_1, $title_2);

        return ($jaccard_similarity * 0.7 + $levenshtein_similarity * 0.3) * 100;
    }

    private function calculate_excerpt_similarity($post_1, $post_2) {
        $excerpt_1 = !empty($post_1->post_excerpt) ? $post_1->post_excerpt : wp_trim_words(strip_tags($post_1->post_content), 30);
        $excerpt_2 = !empty($post_2->post_excerpt) ? $post_2->post_excerpt : wp_trim_words(strip_tags($post_2->post_content), 30);

        $excerpt_1 = mb_strtolower(trim($excerpt_1));
        $excerpt_2 = mb_strtolower(trim($excerpt_2));

        if (empty($excerpt_1) || empty($excerpt_2)) {
            return 0;
        }

        $words_1 = $this->extract_keywords($excerpt_1);
        $words_2 = $this->extract_keywords($excerpt_2);

        if (empty($words_1) || empty($words_2)) {
            return 0;
        }

        $intersection = array_intersect($words_1, $words_2);
        $union = array_unique(array_merge($words_1, $words_2));

        if (empty($union)) {
            return 0;
        }

        return (count($intersection) / count($union)) * 100;
    }

    private function calculate_path_similarity($post_id_1, $post_id_2) {
        $path_1 = str_replace(home_url(), '', get_permalink($post_id_1));
        $path_2 = str_replace(home_url(), '', get_permalink($post_id_2));

        $segments_1 = array_filter(explode('/', trim($path_1, '/')));
        $segments_2 = array_filter(explode('/', trim($path_2, '/')));

        if (empty($segments_1) && empty($segments_2)) {
            return 100;
        }

        if (empty($segments_1) || empty($segments_2)) {
            return 0;
        }

        $common_segments = 0;
        $max_segments = max(count($segments_1), count($segments_2));

        for ($i = 0; $i < min(count($segments_1), count($segments_2)); $i++) {
            if ($segments_1[$i] === $segments_2[$i]) {
                $common_segments++;
            } else {
                break;
            }
        }

        return ($common_segments / $max_segments) * 100;
    }

    private function calculate_date_similarity($date_1, $date_2) {
        $timestamp_1 = strtotime($date_1);
        $timestamp_2 = strtotime($date_2);

        if (!$timestamp_1 || !$timestamp_2) {
            return 0;
        }

        $diff_days = abs($timestamp_1 - $timestamp_2) / (60 * 60 * 24);

        if ($diff_days <= 7) {
            return 50;
        } elseif ($diff_days <= 30) {
            return 40;
        } elseif ($diff_days <= 90) {
            return 30;
        } elseif ($diff_days <= 365) {
            return 20;
        } else {
            return 10;
        }
    }

    private function extract_keywords($text) {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text);

        $words = array_filter($words, function($word) {
            return mb_strlen($word) >= 2 && !$this->is_stop_word($word);
        });

        return array_unique($words);
    }

    private function is_stop_word($word) {
        $stop_words = array(
            'の', 'に', 'は', 'を', 'た', 'が', 'で', 'て', 'と', 'し', 'れ', 'さ', 'な', 'み', 'る', 'ます',
            'です', 'ある', 'いる', 'する', 'した', 'して', 'ない', 'から', 'まで', 'より', 'など', 'また',
            'この', 'その', 'あの', 'どの', 'ここ', 'そこ', 'あそこ', 'どこ', 'こう', 'そう', 'ああ', 'どう',
            'これ', 'それ', 'あれ', 'どれ', 'だ', 'である', 'だった', 'だろう', 'でしょう', 'かもしれない',
            'もの', 'こと', 'とき', 'ため', 'よう', 'わけ', 'はず', 'つもり', 'ところ', 'もっと', 'さらに',
            'すぐ', 'ぜひ', 'とても', '非常', '大変', 'すべて', '全て', 'ような', 'として', 'という',
            'やすい', 'にくい', 'やすく', 'にくく', 'られ', 'られる', 'せる', 'させる'
        );

        return in_array($word, $stop_words);
    }

    private function calculate_levenshtein_similarity($str1, $str2) {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        if ($len1 === 0 || $len2 === 0) {
            return 0;
        }

        $distance = levenshtein($str1, $str2);
        $max_len = max($len1, $len2);

        return 1 - ($distance / $max_len);
    }

    private function get_default_weights() {
        return array(
            'category' => 0.10,  // カテゴリ
            'tag' => 0.35,       // タグ（最重視）
            'title' => 0.35,     // タイトル（最重視）
            'excerpt' => 0.15,   // 抜粋（Description）重要度アップ
            'path' => 0.04,      // URLパス
            'date' => 0.01       // 投稿日
        );
    }

    public function get_similarity_weights($options = null) {
        $default_weights = $this->get_default_weights();

        // optionsにsearch_methodsが含まれている場合、それに基づいて重みを調整
        if (isset($options['search_methods']) && is_array($options['search_methods'])) {
            $search_methods = $options['search_methods'];
            $adjusted_weights = array();

            // 選択された要素のみに重みを配分
            $active_elements = 0;
            if (in_array('categories', $search_methods)) $active_elements++;
            if (in_array('tags', $search_methods)) $active_elements++;
            if (in_array('title', $search_methods)) $active_elements++;
            if (in_array('excerpt', $search_methods)) $active_elements++;
            if (in_array('directory', $search_methods)) $active_elements++;

            if ($active_elements > 0) {
                $weight_per_element = 0.85 / $active_elements; // 85%を選択要素に配分
                $adjusted_weights['category'] = in_array('categories', $search_methods) ? $weight_per_element : 0.0;
                $adjusted_weights['tag'] = in_array('tags', $search_methods) ? $weight_per_element : 0.0;
                $adjusted_weights['title'] = in_array('title', $search_methods) ? $weight_per_element : 0.0;
                $adjusted_weights['excerpt'] = in_array('excerpt', $search_methods) ? $weight_per_element : 0.0;
                $adjusted_weights['path'] = in_array('directory', $search_methods) ? $weight_per_element : 0.0;
                $adjusted_weights['date'] = 0.15; // 日付は常に15%

                return $adjusted_weights;
            }
        }

        // デフォルト設定またはグローバル設定を使用
        $weights = get_option('kashiwazaki_seo_related_posts_weights', $default_weights);
        return wp_parse_args($weights, $default_weights);
    }

    public function update_similarity_weights($weights) {
        $default_weights = $this->get_default_weights();
        $new_weights = array();
        $total = 0;

        // 入力値の検証と正規化
        foreach ($default_weights as $key => $default_value) {
            $value = isset($weights[$key]) ? floatval($weights[$key]) : $default_value;
            $new_weights[$key] = max(0, min(1, $value));
            $total += $new_weights[$key];
        }

        // 合計が0以下の場合はデフォルト値を使用
        if ($total <= 0) {
            $new_weights = $default_weights;
            $total = array_sum($new_weights);
        }

        // 正規化（合計を1にする）
        if ($total > 0) {
            foreach ($new_weights as $key => $value) {
                $new_weights[$key] = $value / $total;
            }
        }

        // データベースに保存
        $saved = update_option('kashiwazaki_seo_related_posts_weights', $new_weights);

        return $new_weights;
    }
}
