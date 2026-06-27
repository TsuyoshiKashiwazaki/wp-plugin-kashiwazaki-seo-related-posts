<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_Shortcode {

    private $related_posts;

    public function __construct($related_posts) {
        $this->related_posts = $related_posts;
        add_shortcode('kashiwazaki_related_posts', array($this, 'render_shortcode'));
        add_action('init', array($this, 'register_shortcode'));
    }

    public function register_shortcode() {
        static $filter_added = false;

        if (!$filter_added) {
            // 常にthe_contentフィルターを追加（カスタムフィールドで個別制御）
            add_filter('the_content', array($this, 'auto_insert_related_posts'));

            $filter_added = true;
        }
    }

    public function render_shortcode($atts) {

        $atts = shortcode_atts(array(
            'max_posts' => 5,
            'use_ai' => 'auto',
            'post_id' => null,
            'post_types' => 'post',
            'min_score' => 25,
            'template' => 'list',
            'show_excerpt' => 'true',
            'show_thumbnail' => 'true',
            'show_date' => 'false',
            'title' => '',
            'exclude' => '',
            'search_methods' => '',
            'filter_categories' => ''
        ), $atts, 'kashiwazaki_related_posts');


        $post_id = $atts['post_id'] ? intval($atts['post_id']) : get_the_ID();

        if (!$post_id) {
            return '';
        }

        $post_types = array_map('trim', explode(',', $atts['post_types']));
        $exclude_ids = array();
        if (!empty($atts['exclude'])) {
            $exclude_ids = array_map('intval', explode(',', $atts['exclude']));
        }

        $use_ai = $this->parse_boolean_or_auto($atts['use_ai']);

        // search_methodsパラメータの処理
        $search_methods = array();
        if (!empty($atts['search_methods'])) {
            $search_methods = array_map('trim', explode(',', $atts['search_methods']));
        }

        // filter_categoriesパラメータの処理
        $filter_categories = array();
        if (!empty($atts['filter_categories'])) {
            $filter_categories = array_map('intval', explode(',', $atts['filter_categories']));
        }

        $options = array(
            'max_posts' => intval($atts['max_posts']),
            'use_ai' => $use_ai,
            'method' => 'similarity',
            'exclude_current' => true,
            'post_types' => $post_types,
            'min_score' => intval($atts['min_score']),
            'exclude_ids' => $exclude_ids,
            'search_methods' => $search_methods,
            'filter_categories' => $filter_categories
        );


        // 検索方法の詳細分析
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $search_methods_setting = isset($plugin_options['search_methods']) ? $plugin_options['search_methods'] : array('tags', 'categories');

        $search_method_details = array();
        if (in_array('tags', $search_methods_setting)) $search_method_details[] = 'タグ';
        if (in_array('categories', $search_methods_setting)) $search_method_details[] = 'カテゴリ';
        if (in_array('directory', $search_methods_setting)) $search_method_details[] = 'ディレクトリ構造';
        if (in_array('title', $search_methods_setting)) $search_method_details[] = 'タイトル類似度';
        if (in_array('excerpt', $search_methods_setting)) $search_method_details[] = '抜粋類似度';
        if ($use_ai) $search_method_details[] = 'AI分析';


        // まずキャッシュされた関連記事をチェック
        $cached_results = get_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results', true);
        $cached_timestamp = get_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp', true);

        // 設定からキャッシュ有効期限を取得
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $cache_lifetime_hours = isset($plugin_options['cache_lifetime']) ? $plugin_options['cache_lifetime'] : 24;
        $cache_lifetime = $cache_lifetime_hours * 60 * 60; // 時間を秒に変換
        $use_cache = false;

        if ($cached_results && $cached_timestamp && is_array($cached_results)) {
            $cache_age = time() - $cached_timestamp;
            if ($cache_age < $cache_lifetime) {
                $use_cache = true;
            } else {
            }
        } else {
        }

        if ($use_cache) {
            // キャッシュされた結果を使用し、適切な形式に変換
            // 管理画面で保存したキャッシュもショートコードで保存したキャッシュも両方対応
            $related_posts = array();
            foreach ($cached_results as $cached_item) {
                $cached_post_id = isset($cached_item['post_id']) ? $cached_item['post_id'] : (isset($cached_item['id']) ? $cached_item['id'] : null);
                if ($cached_post_id && get_post($cached_post_id)) {
                    $related_posts[] = array(
                        'post_id' => $cached_post_id,
                        'score' => isset($cached_item['score']) ? $cached_item['score'] : 0,
                        'post' => get_post($cached_post_id),
                        'method' => isset($cached_item['method']) ? $cached_item['method'] : 'cached'
                    );
                }
            }
        } else {
            // 新規取得してキャッシュに保存
            $related_posts = $this->related_posts->get_related_posts($post_id, $options);

            // 結果をキャッシュに保存（管理画面と同じ形式で保存）
            if (!empty($related_posts)) {
                $cache_data = array();
                foreach ($related_posts as $related_post) {
                    $post_obj = isset($related_post['post']) ? $related_post['post'] : get_post($related_post['post_id']);
                    if ($post_obj) {
                        $cache_data[] = array(
                            'post_id' => $related_post['post_id'],
                            'title' => $post_obj->post_title,
                            'post_type' => $post_obj->post_type,
                            'date' => date('Y-m-d', strtotime($post_obj->post_date)),
                            'score' => isset($related_post['score']) ? $related_post['score'] : 0,
                            'reason' => isset($related_post['reason']) ? $related_post['reason'] : ''
                        );
                    }
                }

                $timestamp = time();
                update_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results', $cache_data);
                update_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp', $timestamp);
            }
        }

        // デバッグモード対応
        $debug_mode = get_option('kashiwazaki_seo_related_posts_debug_mode', false);
        $debug_output = '';

        if ($debug_mode) {
            $debug_info = get_post_meta($post_id, '_kashiwazaki_debug_info', true);
            if ($debug_info) {
                $debug_output = $this->render_debug_info($debug_info, $post_id);
            }
        }

        if (empty($related_posts)) {
            if ($debug_mode && $debug_info) {
                return $debug_output . '<div class="kashiwazaki-no-related-posts" style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; margin: 20px 0;"><strong>⚠️ 関連記事が見つかりませんでした</strong><br>デバッグ情報を確認してください。</div>';
            }
            return '';
        }

        $html_output = $this->render_related_posts_html($related_posts, $atts);

        return $debug_output . $html_output;
    }

    public function auto_insert_related_posts($content) {
        if (!is_singular()) {
            return $content;
        }

                $current_post_id = get_the_ID();

        // カスタムフィールドをチェック
        $custom_field_enabled = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_enabled', true);

        if ($custom_field_enabled !== '1') {
            return $content;
        }


        // デフォルト値を取得
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        // 投稿タイプ別設定を取得
        $post_type = get_post_type($current_post_id);
        $pt_settings_key = 'post_type_settings_' . $post_type;
        $pt_settings = isset($options[$pt_settings_key]) ? $options[$pt_settings_key] : array();

        // デフォルト値（投稿タイプ別設定があればそれを優先、なければグローバル設定）
        $use_custom_settings = isset($pt_settings['use_custom_settings']) && $pt_settings['use_custom_settings'];
        $default_search_methods = isset($options['search_methods']) ? $options['search_methods'] : array('tags', 'categories');
        $default_max_posts = ($use_custom_settings && isset($pt_settings['max_posts'])) ? $pt_settings['max_posts'] : (isset($options['max_posts']) ? $options['max_posts'] : 5);
        $default_display_method = ($use_custom_settings && isset($pt_settings['display_method'])) ? $pt_settings['display_method'] : (isset($options['display_method']) ? $options['display_method'] : 'list');
        $default_insert_position = ($use_custom_settings && isset($pt_settings['insert_position'])) ? $pt_settings['insert_position'] : (isset($options['insert_position']) ? $options['insert_position'] : 'after_content');
        $default_target_post_types = ($use_custom_settings && isset($pt_settings['target_post_types'])) ? $pt_settings['target_post_types'] : (isset($options['target_post_types']) ? $options['target_post_types'] : array('post'));
        $default_filter_categories = ($use_custom_settings && isset($pt_settings['filter_categories'])) ? $pt_settings['filter_categories'] : (isset($options['filter_categories']) ? $options['filter_categories'] : array());
        $default_color_theme = ($use_custom_settings && isset($pt_settings['color_theme'])) ? $pt_settings['color_theme'] : (isset($options['color_theme']) ? $options['color_theme'] : 'blue');
        $default_heading_text = ($use_custom_settings && isset($pt_settings['heading_text'])) ? $pt_settings['heading_text'] : (isset($options['heading_text']) ? $options['heading_text'] : '関連記事');
        $default_heading_tag = ($use_custom_settings && isset($pt_settings['heading_tag'])) ? $pt_settings['heading_tag'] : (isset($options['heading_tag']) ? $options['heading_tag'] : 'h2');

        // 記事のカスタムフィールドから設定を取得（空の場合はデフォルト値を使用）
        $search_methods = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_search_methods', true);
        $search_methods = $search_methods ? $search_methods : $default_search_methods;
        $max_posts = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_max_posts', true);
        $max_posts = $max_posts ? intval($max_posts) : $default_max_posts;
        $display_method = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_display_method', true);
        $display_method = $display_method ? $display_method : $default_display_method;
        $position = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_insert_position', true);
        $position = $position ? $position : $default_insert_position;
        $target_post_types = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_target_post_types', true);
        $target_post_types = $target_post_types ? $target_post_types : $default_target_post_types;
        $filter_categories = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_filter_categories', true);
        $filter_categories = $filter_categories ? $filter_categories : $default_filter_categories;
        $color_theme = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_color_theme', true);
        $color_theme = $color_theme ? $color_theme : $default_color_theme;
        $heading_text = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_heading_text', true);
        $heading_text = $heading_text ? $heading_text : $default_heading_text;
        $heading_tag = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_heading_tag', true);
        $heading_tag = $heading_tag ? $heading_tag : $default_heading_tag;

        // API分析は常時実行（APIキーが設定されている場合）
        $openrouter_api_key = isset($options['openrouter_api_key']) ? $options['openrouter_api_key'] : '';
        $openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
        $legacy_api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $use_ai = !empty($openrouter_api_key) || !empty($openai_api_key) || !empty($legacy_api_key);

        $template_map = array(
            'list' => 'list',
            'grid' => 'grid',
            'slider' => 'slider'
        );
        $template = isset($template_map[$display_method]) ? $template_map[$display_method] : 'list';
        $target_post_types_string = implode(',', $target_post_types);
        $search_methods_string = implode(',', $search_methods);
        $filter_categories_string = !empty($filter_categories) ? implode(',', $filter_categories) : '';

        $shortcode_attrs = array(
            'max_posts="' . $max_posts . '"',
            'use_ai="' . ($use_ai ? 'true' : 'false') . '"',
            'template="' . $template . '"',
            'post_types="' . $target_post_types_string . '"',
            'search_methods="' . $search_methods_string . '"',
            'title="' . esc_attr($heading_text) . '"'
        );

        if (!empty($filter_categories_string)) {
            $shortcode_attrs[] = 'filter_categories="' . $filter_categories_string . '"';
        }

        $shortcode = '[kashiwazaki_related_posts ' . implode(' ', $shortcode_attrs) . ']';


        switch ($position) {
            case 'before_content':
                return do_shortcode($shortcode) . $content;
            case 'after_first_paragraph':
                $paragraphs = explode('</p>', $content);
                if (count($paragraphs) > 1) {
                    $paragraphs[0] .= '</p>' . do_shortcode($shortcode);
                    return implode('</p>', $paragraphs);
                }
                return $content . do_shortcode($shortcode);
            case 'after_content':
            default:
                return $content . do_shortcode($shortcode);
        }
    }

    private function render_related_posts_html($related_posts, $atts) {
        $template = $atts['template'];
        $show_excerpt = $this->parse_boolean($atts['show_excerpt']);
        $show_thumbnail = $this->parse_boolean($atts['show_thumbnail']);
        $show_date = $this->parse_boolean($atts['show_date']);
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        // 現在の投稿タイプを取得（ショートコードで post_id 指定時はその投稿を対象にする）
        $current_post_id = !empty($atts['post_id']) ? intval($atts['post_id']) : get_the_ID();
        $post_type = get_post_type($current_post_id);
        $pt_settings_key = 'post_type_settings_' . $post_type;
        $pt_settings = isset($options[$pt_settings_key]) ? $options[$pt_settings_key] : array();

        // 個別投稿のカスタムフィールドを確認（最優先）
        $custom_heading_text = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_heading_text', true);
        $custom_color_theme = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_color_theme', true);
        $custom_heading_tag = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_heading_tag', true);

        // 優先順位: 個別投稿 > 投稿タイプ別設定 > 共通設定
        $default_title = $custom_heading_text ? $custom_heading_text : (isset($pt_settings['heading_text']) ? $pt_settings['heading_text'] : (isset($options['heading_text']) ? $options['heading_text'] : '関連記事'));
        $title = !empty($atts['title']) ? $atts['title'] : $default_title;

        $color_theme = $custom_color_theme ? $custom_color_theme : (isset($pt_settings['color_theme']) ? $pt_settings['color_theme'] : (isset($options['color_theme']) ? $options['color_theme'] : 'blue'));

        $classes = array('kashiwazaki-related-posts');
        if ($template !== 'default') {
            $classes[] = 'template-' . $template;
        }
        $classes[] = 'theme-' . $color_theme;

        // テーマカラーのインラインスタイルを生成
        $theme_colors = array(
            'blue' => '#007cba',
            'orange' => '#ff7f50',
            'green' => '#27ae60',
            'purple' => '#8e44ad',
            'red' => '#e74c3c'
        );
        $theme_color = isset($theme_colors[$color_theme]) ? $theme_colors[$color_theme] : $theme_colors['blue'];

           $output = '<div class="' . esc_attr(implode(' ', $classes)) . '">';

        // 見出しを表示（自動挿入時）
        if (!empty($atts['title'])) {
            $heading_tag = $custom_heading_tag ? $custom_heading_tag : (isset($pt_settings['heading_tag']) ? $pt_settings['heading_tag'] : (isset($options['heading_tag']) ? $options['heading_tag'] : 'h2'));
            $heading_tag = kashiwazaki_seo_related_posts_sanitize_heading_tag($heading_tag);
            $output .= '<' . $heading_tag . ' class="kashiwazaki-related-posts-title">' . esc_html($atts['title']) . '</' . $heading_tag . '>';
        }

        switch ($template) {
            case 'grid':
                $output .= $this->render_grid_template($related_posts, $show_excerpt, $show_thumbnail, $show_date);
                break;
            case 'list':
                $output .= $this->render_list_template($related_posts, $show_excerpt, $show_thumbnail, $show_date);
                break;
            case 'slider':
                $output .= $this->render_slider_template($related_posts, $show_excerpt, $show_thumbnail, $show_date);
                break;
            case 'minimal':
                $output .= $this->render_minimal_template($related_posts);
                break;
            case 'card':
                $output .= $this->render_card_template($related_posts, $show_excerpt, $show_thumbnail, $show_date);
                break;
            case 'default':
            default:
                $output .= $this->render_default_template($related_posts, $show_excerpt, $show_thumbnail, $show_date);
                break;
        }

        $output .= '</div>';

        return $output;
    }

    private function render_default_template($related_posts, $show_excerpt, $show_thumbnail, $show_date) {
        $output = '<div class="kashiwazaki-related-posts-list">';

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
            $title = mb_strlen($post->post_title) > 45 ? mb_substr($post->post_title, 0, 45) . '...' : $post->post_title;
            $output .= '<div class="kashiwazaki-related-post-title">';
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($title) . '</a>';
            $output .= '</div>';

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
        return $output;
    }

    private function render_grid_template($related_posts, $show_excerpt, $show_thumbnail, $show_date) {
        $output = '<div class="kashiwazaki-related-posts-grid">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];

            $output .= '<div class="kashiwazaki-related-post-grid-item">';

            if ($show_thumbnail && has_post_thumbnail($post->ID)) {
                $output .= '<div class="kashiwazaki-related-post-thumbnail">';
                $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">';
                $output .= get_the_post_thumbnail($post->ID, 'medium');
                $output .= '</a>';
                $output .= '</div>';
            }

            $output .= '<div class="kashiwazaki-related-post-content">';
            $title = mb_strlen($post->post_title) > 45 ? mb_substr($post->post_title, 0, 45) . '...' : $post->post_title;
            $output .= '<div class="kashiwazaki-related-post-title">';
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($title) . '</a>';
            $output .= '</div>';

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
        return $output;
    }

    private function render_list_template($related_posts, $show_excerpt, $show_thumbnail, $show_date) {
        $output = '<ul class="kashiwazaki-related-posts-simple-list">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];

            $output .= '<li class="kashiwazaki-related-post-list-item">';
            $title = mb_strlen($post->post_title) > 40 ? mb_substr($post->post_title, 0, 40) . '...' : $post->post_title;
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($title) . '</a>';

            if ($show_date) {
                $output .= ' <span class="kashiwazaki-related-post-date">(' . esc_html(get_the_date('Y年m月d日', $post->ID)) . ')</span>';
            }

            $output .= '</li>';
        }

        $output .= '</ul>';
        return $output;
    }

    private function render_minimal_template($related_posts) {
        $output = '<div class="kashiwazaki-related-posts-minimal">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '" class="kashiwazaki-related-post-minimal-link">';
            $output .= esc_html($post->post_title);
            $output .= '</a>';
        }

        $output .= '</div>';
        return $output;
    }

    private function render_card_template($related_posts, $show_excerpt, $show_thumbnail, $show_date) {
        $output = '<div class="kashiwazaki-related-posts-cards">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];

            $output .= '<div class="kashiwazaki-related-post-card">';
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '" class="kashiwazaki-related-post-card-link">';

            if ($show_thumbnail && has_post_thumbnail($post->ID)) {
                $output .= '<div class="kashiwazaki-related-post-card-image">';
                $output .= get_the_post_thumbnail($post->ID, 'medium');
                $output .= '</div>';
            }

            $output .= '<div class="kashiwazaki-related-post-card-content">';
            $title = mb_strlen($post->post_title) > 40 ? mb_substr($post->post_title, 0, 40) . '...' : $post->post_title;
            $output .= '<div class="kashiwazaki-related-post-card-title">' . esc_html($title) . '</div>';

            if ($show_date) {
                $output .= '<div class="kashiwazaki-related-post-card-date">';
                $output .= esc_html(get_the_date('Y年m月d日', $post->ID));
                $output .= '</div>';
            }

            if ($show_excerpt) {
                $excerpt = !empty($post->post_excerpt) ? wp_trim_words($post->post_excerpt, 30) : wp_trim_words(strip_tags($post->post_content), 30);
                $output .= '<div class="kashiwazaki-related-post-card-excerpt">';
                $output .= esc_html($excerpt);
                $output .= '</div>';
            }

            $output .= '</div>';
            $output .= '</a>';
            $output .= '</div>';
        }

        $output .= '</div>';
        return $output;
    }

    private function render_slider_template($related_posts, $show_excerpt, $show_thumbnail, $show_date) {
        static $slider_id = 0;
        $slider_id++;

        $output = '<div class="kashiwazaki-related-posts-slider" id="slider-' . $slider_id . '">';
        $output .= '<div class="kashiwazaki-slider-container">';
        $output .= '<div class="kashiwazaki-slider-track">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];

            $output .= '<div class="kashiwazaki-slider-item">';

            if ($show_thumbnail && has_post_thumbnail($post->ID)) {
                $output .= '<div class="kashiwazaki-slider-image">';
                $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">';
                $output .= get_the_post_thumbnail($post->ID, 'medium');
                $output .= '</a>';
                $output .= '</div>';
            }

            $output .= '<div class="kashiwazaki-slider-content">';
            $title = mb_strlen($post->post_title) > 40 ? mb_substr($post->post_title, 0, 40) . '...' : $post->post_title;
            $output .= '<div class="kashiwazaki-slider-title">';
            $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($title) . '</a>';
            $output .= '</div>';

            if ($show_date) {
                $output .= '<div class="kashiwazaki-slider-date">';
                $output .= esc_html(get_the_date('Y年m月d日', $post->ID));
                $output .= '</div>';
            }

            if ($show_excerpt) {
                $excerpt = !empty($post->post_excerpt) ? wp_trim_words($post->post_excerpt, 30) : wp_trim_words(strip_tags($post->post_content), 30);
                $output .= '<div class="kashiwazaki-slider-excerpt">';
                $output .= esc_html($excerpt);
                $output .= '</div>';
            }

            $output .= '</div>';
            $output .= '</div>';
        }

        $output .= '</div>';
        $output .= '<button class="kashiwazaki-slider-prev" data-slider="slider-' . $slider_id . '">‹</button>';
        $output .= '<button class="kashiwazaki-slider-next" data-slider="slider-' . $slider_id . '">›</button>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    private function parse_boolean($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function parse_boolean_or_auto($value) {
        if ($value === 'auto') {
            $options = get_option('kashiwazaki_seo_related_posts_options', array());
            $search_methods = isset($options['search_methods']) ? $options['search_methods'] : array();
            return in_array('ai', $search_methods);
        }
        return $this->parse_boolean($value);
    }

    private function render_debug_info($debug_info, $post_id) {
        $output = '<div class="kashiwazaki-debug-panel" style="background: #f8f9fa; border: 2px solid #007bff; border-radius: 8px; padding: 20px; margin: 20px 0; font-family: monospace; font-size: 13px;">';
        $output .= '<h3 style="margin-top: 0; color: #007bff; font-size: 18px;">🔍 関連記事デバッグ情報（投稿ID: ' . esc_html($post_id) . '）</h3>';

        $output .= '<div style="background: white; padding: 15px; border-radius: 5px; margin-bottom: 15px;">';
        $output .= '<strong>📊 サマリー</strong><br>';
        $output .= '候補記事数: <span style="color: #28a745; font-weight: bold;">' . esc_html($debug_info['candidate_count']) . '件</span><br>';
        $output .= '最低スコア基準: <span style="color: #ffc107; font-weight: bold;">' . esc_html($debug_info['min_score']) . '点</span><br>';
        $output .= '基準を満たした記事: <span style="color: ' . ($debug_info['passed_count'] > 0 ? '#28a745' : '#dc3545') . '; font-weight: bold;">' . esc_html($debug_info['passed_count']) . '件</span><br>';
        $output .= '最終表示数: <span style="color: #17a2b8; font-weight: bold;">' . esc_html($debug_info['final_count']) . '件</span>';
        $output .= '</div>';

        if (!empty($debug_info['all_candidates'])) {
            $output .= '<div style="background: white; padding: 15px; border-radius: 5px;">';
            $output .= '<strong>📋 全候補記事の詳細（スコア順）</strong><br><br>';

            // スコア順にソート
            $candidates = $debug_info['all_candidates'];
            usort($candidates, function($a, $b) {
                return $b['total_score'] - $a['total_score'];
            });

            foreach ($candidates as $index => $candidate) {
                $bg_color = $candidate['passed'] ? '#d4edda' : '#f8d7da';
                $border_color = $candidate['passed'] ? '#28a745' : '#dc3545';
                $status_icon = $candidate['passed'] ? '✅' : '❌';

                $output .= '<div style="background: ' . $bg_color . '; border-left: 4px solid ' . $border_color . '; padding: 12px; margin-bottom: 10px; border-radius: 4px;">';
                $output .= '<strong>' . $status_icon . ' [' . ($index + 1) . '] ' . esc_html($candidate['title']) . '</strong><br>';
                $output .= '<small style="color: #666;">ID: ' . esc_html($candidate['post_id']) . '</small><br>';
                $output .= '<div style="margin-top: 8px;">';
                $output .= '<strong style="font-size: 16px; color: ' . $border_color . ';">合計スコア: ' . number_format($candidate['total_score'], 2) . '点</strong>';

                if (isset($candidate['details']['raw_scores'])) {
                    $output .= '<br><br><table style="width: 100%; border-collapse: collapse; margin-top: 5px;">';
                    $output .= '<tr style="background: rgba(0,0,0,0.05);"><th style="padding: 5px; text-align: left; border: 1px solid #ddd;">項目</th><th style="padding: 5px; text-align: right; border: 1px solid #ddd;">生スコア</th><th style="padding: 5px; text-align: right; border: 1px solid #ddd;">重み</th><th style="padding: 5px; text-align: right; border: 1px solid #ddd;">加重スコア</th></tr>';

                    foreach ($candidate['details']['raw_scores'] as $key => $raw_score) {
                        $weight = $candidate['details']['weights'][$key];
                        $weighted = $candidate['details']['weighted_scores'][$key];
                        $output .= '<tr>';
                        $output .= '<td style="padding: 5px; border: 1px solid #ddd;">' . esc_html($key) . '</td>';
                        $output .= '<td style="padding: 5px; text-align: right; border: 1px solid #ddd;">' . number_format($raw_score, 2) . '</td>';
                        $output .= '<td style="padding: 5px; text-align: right; border: 1px solid #ddd;">' . number_format($weight, 2) . '</td>';
                        $output .= '<td style="padding: 5px; text-align: right; border: 1px solid #ddd; font-weight: bold;">' . number_format($weighted, 2) . '</td>';
                        $output .= '</tr>';
                    }
                    $output .= '</table>';
                }

                $output .= '</div>';
                $output .= '</div>';
            }
            $output .= '</div>';
        }

        $output .= '<div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 5px; font-size: 12px;">';
        $output .= '<strong>💡 ヒント:</strong> デバッグモードを無効にするには、プラグイン設定で「デバッグモード」をオフにしてください。';
        $output .= '</div>';

        $output .= '</div>';

        return $output;
    }
}
