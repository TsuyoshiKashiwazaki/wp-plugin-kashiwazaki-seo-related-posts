<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_Widget extends WP_Widget {

    private $related_posts;

    public function __construct($related_posts) {
        $this->related_posts = $related_posts;

        parent::__construct(
            'kashiwazaki_seo_related_posts_widget',
            'Kashiwazaki SEO Related Posts',
            array(
                'description' => 'AI・タグ・カテゴリから関連記事を表示'
            )
        );

        add_action('widgets_init', array($this, 'register_widget'));
    }

    public function register_widget() {
        register_widget($this);
    }

    public function widget($args, $instance) {
        if (!is_single() && !is_page()) {
            return;
        }

        $current_post_id = get_the_ID();
        if (!$current_post_id) {
            return;
        }

        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());

        // 投稿タイプ別設定を取得
        $post_type = get_post_type($current_post_id);
        $pt_settings_key = 'post_type_settings_' . $post_type;
        $pt_settings = isset($plugin_options[$pt_settings_key]) ? $plugin_options[$pt_settings_key] : array();
        $use_custom_settings = isset($pt_settings['use_custom_settings']) && $pt_settings['use_custom_settings'];

        // 個別投稿のカスタムフィールドから設定を取得
        $custom_filter_categories = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_filter_categories', true);
        $custom_target_post_types = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_target_post_types', true);

        // デフォルト値
        $default_title = isset($plugin_options['heading_text']) ? $plugin_options['heading_text'] : '関連記事';
        $default_filter_categories = ($use_custom_settings && isset($pt_settings['filter_categories'])) ? $pt_settings['filter_categories'] : (isset($plugin_options['filter_categories']) ? $plugin_options['filter_categories'] : array());
        $default_target_post_types = ($use_custom_settings && isset($pt_settings['target_post_types'])) ? $pt_settings['target_post_types'] : (isset($plugin_options['target_post_types']) ? $plugin_options['target_post_types'] : array('post'));

        $title = !empty($instance['title']) ? $instance['title'] : $default_title;
        $max_posts = !empty($instance['max_posts']) ? intval($instance['max_posts']) : 5;
        $use_ai = !empty($instance['use_ai']) ? true : false;
        $show_thumbnail = !empty($instance['show_thumbnail']) ? true : false;
        $show_excerpt = !empty($instance['show_excerpt']) ? true : false;
        $show_date = !empty($instance['show_date']) ? true : false;

        // 投稿タイプ: ウィジェット設定 > 個別投稿 > デフォルト
        $post_types = !empty($instance['post_types']) ? array_map('trim', explode(',', $instance['post_types'])) : ($custom_target_post_types ? $custom_target_post_types : $default_target_post_types);

        // カテゴリフィルタ: 個別投稿 > デフォルト
        $filter_categories = $custom_filter_categories ? $custom_filter_categories : $default_filter_categories;

        $options = array(
            'max_posts' => $max_posts,
            'use_ai' => $use_ai,
            'method' => 'similarity',
            'exclude_current' => true,
            'post_types' => $post_types,
            'min_score' => 25,
            'filter_categories' => $filter_categories
        );

        // キャッシュをチェック
        $cached_results = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_cached_results', true);
        $cached_timestamp = get_post_meta($current_post_id, '_kashiwazaki_seo_related_posts_cached_timestamp', true);
        $cache_lifetime_hours = 24;
        $cache_lifetime = $cache_lifetime_hours * 60 * 60;
        $use_cache = false;

        if ($cached_results && $cached_timestamp && is_array($cached_results)) {
            $cache_age = time() - $cached_timestamp;
            if ($cache_age < $cache_lifetime) {
                $use_cache = true;
            }
        }

        if ($use_cache) {
            // キャッシュから関連記事を復元
            $related_posts = array();
            foreach ($cached_results as $cached_item) {
                $post_id = isset($cached_item['post_id']) ? $cached_item['post_id'] : null;
                if ($post_id && get_post($post_id)) {
                    $related_posts[] = array(
                        'post_id' => $post_id,
                        'score' => isset($cached_item['score']) ? $cached_item['score'] : 0,
                        'post' => get_post($post_id),
                        'method' => isset($cached_item['method']) ? $cached_item['method'] : 'cached'
                    );
                }
            }
        } else {
            // 新規取得
            $related_posts = $this->related_posts->get_related_posts($current_post_id, $options);
        }

        if (empty($related_posts)) {
            return;
        }

        echo $args['before_widget'];

        if ($title) {
            echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        }

        echo '<div class="kashiwazaki-related-posts-widget">';

        foreach ($related_posts as $related_post) {
            $post = $related_post['post'];
            $score = isset($related_post['score']) ? $related_post['score'] : 0;
            $method = isset($related_post['method']) ? $related_post['method'] : 'similarity';

            echo '<div class="kashiwazaki-related-post-widget-item" data-score="' . esc_attr($score) . '" data-method="' . esc_attr($method) . '">';

            if ($show_thumbnail && has_post_thumbnail($post->ID)) {
                echo '<div class="kashiwazaki-related-post-widget-thumbnail">';
                echo '<a href="' . esc_url(get_permalink($post->ID)) . '">';
                echo get_the_post_thumbnail($post->ID, 'thumbnail');
                echo '</a>';
                echo '</div>';
            }

            echo '<div class="kashiwazaki-related-post-widget-content">';
            echo '<h4 class="kashiwazaki-related-post-widget-title">';
            echo '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($post->post_title) . '</a>';
            echo '</h4>';

            if ($show_date) {
                echo '<div class="kashiwazaki-related-post-widget-date">';
                echo esc_html(get_the_date('Y年m月d日', $post->ID));
                echo '</div>';
            }

            if ($show_excerpt) {
                $excerpt = !empty($post->post_excerpt) ? wp_trim_words($post->post_excerpt, 30) : wp_trim_words(strip_tags($post->post_content), 30);
                echo '<div class="kashiwazaki-related-post-widget-excerpt">';
                echo esc_html($excerpt);
                echo '</div>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '関連記事';
        $max_posts = !empty($instance['max_posts']) ? $instance['max_posts'] : 5;
        $use_ai = !empty($instance['use_ai']) ? true : false;
        $show_thumbnail = !empty($instance['show_thumbnail']) ? true : false;
        $show_excerpt = !empty($instance['show_excerpt']) ? true : false;
        $show_date = !empty($instance['show_date']) ? true : false;
        $post_types = !empty($instance['post_types']) ? $instance['post_types'] : 'post';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">タイトル:</label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('max_posts')); ?>">表示記事数:</label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('max_posts')); ?>" name="<?php echo esc_attr($this->get_field_name('max_posts')); ?>" type="number" step="1" min="1" max="20" value="<?php echo esc_attr($max_posts); ?>" size="3">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('post_types')); ?>">投稿タイプ:</label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('post_types')); ?>" name="<?php echo esc_attr($this->get_field_name('post_types')); ?>" type="text" value="<?php echo esc_attr($post_types); ?>" placeholder="post,page">
            <small>カンマ区切りで複数指定可能</small>
        </p>

        <p>
            <input class="checkbox" type="checkbox" <?php checked($use_ai); ?> id="<?php echo esc_attr($this->get_field_id('use_ai')); ?>" name="<?php echo esc_attr($this->get_field_name('use_ai')); ?>">
            <label for="<?php echo esc_attr($this->get_field_id('use_ai')); ?>">AI分析を使用</label>
        </p>

        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_thumbnail); ?> id="<?php echo esc_attr($this->get_field_id('show_thumbnail')); ?>" name="<?php echo esc_attr($this->get_field_name('show_thumbnail')); ?>">
            <label for="<?php echo esc_attr($this->get_field_id('show_thumbnail')); ?>">サムネイル表示</label>
        </p>

        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_excerpt); ?> id="<?php echo esc_attr($this->get_field_id('show_excerpt')); ?>" name="<?php echo esc_attr($this->get_field_name('show_excerpt')); ?>">
            <label for="<?php echo esc_attr($this->get_field_id('show_excerpt')); ?>">抜粋表示</label>
        </p>

        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_date); ?> id="<?php echo esc_attr($this->get_field_id('show_date')); ?>" name="<?php echo esc_attr($this->get_field_name('show_date')); ?>">
            <label for="<?php echo esc_attr($this->get_field_id('show_date')); ?>">投稿日表示</label>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        $instance['max_posts'] = (!empty($new_instance['max_posts'])) ? absint($new_instance['max_posts']) : 5;
        $instance['use_ai'] = (!empty($new_instance['use_ai'])) ? 1 : 0;
        $instance['show_thumbnail'] = (!empty($new_instance['show_thumbnail'])) ? 1 : 0;
        $instance['show_excerpt'] = (!empty($new_instance['show_excerpt'])) ? 1 : 0;
        $instance['show_date'] = (!empty($new_instance['show_date'])) ? 1 : 0;
        $instance['post_types'] = (!empty($new_instance['post_types'])) ? sanitize_text_field($new_instance['post_types']) : 'post';

        return $instance;
    }
}
