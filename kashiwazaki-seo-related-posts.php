<?php
/*
Plugin Name: Kashiwazaki SEO Related Posts
Plugin URI: https://www.tsuyoshikashiwazaki.jp
Description: AI分析・3階層設定・API統計・一括操作で大規模サイトの関連記事を効率管理。OpenAI GPT対応、投稿タイプ別キャッシュ管理、詳細な個別記事設定が可能なエンタープライズ級SEOプラグイン
Version: 1.0.1
Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
*/

if (!defined('ABSPATH')) exit;

define('KASHIWAZAKI_SEO_RELATED_POSTS_VERSION', '1.0.1');
define('KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL', plugin_dir_url(__FILE__));

class KashiwazakiSEORelatedPosts {

    private $admin;
    private $api;
    private $related_posts;
    private $similarity_calculator;
    private $shortcode;
    private $widget;
    private $models;

    public function __construct() {
        $this->load_dependencies();
        $this->init();
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // プラグイン一覧ページにアクションリンクを追加
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
    }

    private function load_dependencies() {
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-api.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-similarity-calculator.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-related-posts.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-shortcode.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-widget.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-admin.php';
    }

    private function init() {
        $this->api = new KashiwazakiSEORelatedPosts_API();
        $this->similarity_calculator = new KashiwazakiSEORelatedPosts_SimilarityCalculator();
        $this->related_posts = new KashiwazakiSEORelatedPosts_RelatedPosts($this->similarity_calculator, $this->api);
        $this->shortcode = new KashiwazakiSEORelatedPosts_Shortcode($this->related_posts);
        $this->widget = new KashiwazakiSEORelatedPosts_Widget($this->related_posts);
        $this->admin = new KashiwazakiSEORelatedPosts_Admin($this->api, $this->related_posts);
    }

    public function enqueue_scripts() {
        $version = KASHIWAZAKI_SEO_RELATED_POSTS_VERSION . '.' . time();
        wp_enqueue_style('kashiwazaki-seo-related-posts', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/css/style.css', array(), $version);

        // カラーテーマのCSS変数を追加
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $color_theme = isset($plugin_options['color_theme']) ? $plugin_options['color_theme'] : 'blue';

        $theme_colors = array(
            'blue' => array('primary' => '#007cba', 'hover' => '#005a87'),
            'orange' => array('primary' => '#ff7f50', 'hover' => '#ff6347'),
            'green' => array('primary' => '#27ae60', 'hover' => '#219a52'),
            'purple' => array('primary' => '#8e44ad', 'hover' => '#7d3c98'),
            'red' => array('primary' => '#e74c3c', 'hover' => '#c0392b'),
            'white' => array('primary' => '#666', 'hover' => '#333')
        );

        $current_theme = isset($theme_colors[$color_theme]) ? $theme_colors[$color_theme] : $theme_colors['blue'];

        $custom_css = "
            .kashiwazaki-related-posts {
                --kashiwazaki-primary-color: {$current_theme['primary']};
                --kashiwazaki-hover-color: {$current_theme['hover']};
            }
        ";

        wp_add_inline_style('kashiwazaki-seo-related-posts', $custom_css);

        wp_enqueue_script('kashiwazaki-seo-related-posts', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/js/script.js', array('jquery'), $version, true);

        // スライダー設定をJavaScriptに渡す
        wp_localize_script('kashiwazaki-seo-related-posts', 'kashiwazaki_slider_config', array(
            'items_desktop' => isset($plugin_options['slider_items_desktop']) ? intval($plugin_options['slider_items_desktop']) : 3,
            'items_tablet' => isset($plugin_options['slider_items_tablet']) ? intval($plugin_options['slider_items_tablet']) : 2,
            'items_mobile' => isset($plugin_options['slider_items_mobile']) ? intval($plugin_options['slider_items_mobile']) : 1
        ));
    }

    public function enqueue_admin_scripts($hook) {
        // プラグイン設定ページまたは投稿編集画面でスクリプトを読み込み
        $is_plugin_page = strpos($hook, 'kashiwazaki-seo-related-posts') !== false;
        $is_edit_page = in_array($hook, array('post.php', 'post-new.php'));

        if (!$is_plugin_page && !$is_edit_page) return;

        $version = KASHIWAZAKI_SEO_RELATED_POSTS_VERSION . '.' . time();
        wp_enqueue_style('kashiwazaki-seo-related-posts-admin', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/css/admin.css', array(), $version);
        wp_enqueue_script('kashiwazaki-seo-related-posts-admin', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), $version, true);
        wp_localize_script('kashiwazaki-seo-related-posts-admin', 'kashiwazaki_related_posts_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kashiwazaki_fetch_related_posts')
        ));
    }

    /**
     * プラグイン一覧ページにアクションリンクを追加
     */
    public function add_plugin_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings') . '">設定</a>'
        );

        return array_merge($plugin_links, $links);
    }
}

new KashiwazakiSEORelatedPosts();
