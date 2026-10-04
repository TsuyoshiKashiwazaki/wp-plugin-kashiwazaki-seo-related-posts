<?php
/*
Plugin Name: Kashiwazaki SEO Related Posts
Plugin URI: https://www.tsuyoshikashiwazaki.jp
Description: AI分析・3階層設定・API統計・一括操作で大規模サイトの関連記事を効率管理。OpenAI Embeddings（意味の近さ）・GPT対応、投稿タイプ別キャッシュ管理、詳細な個別記事設定が可能なエンタープライズ級SEOプラグイン
Version: 1.0.4
Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
*/

if (!defined('ABSPATH')) exit;

define('KASHIWAZAKI_SEO_RELATED_POSTS_VERSION', '1.0.4');
define('KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * 見出しタグ名をホワイトリストで検証する（XSS 防止）。
 * 許可タグ以外は既定値 'h2' にフォールバックする。
 */
function kashiwazaki_seo_related_posts_sanitize_heading_tag($tag) {
    $allowed = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p');
    $tag = is_string($tag) ? strtolower(trim($tag)) : '';
    return in_array($tag, $allowed, true) ? $tag : 'h2';
}

/**
 * 投稿タイプ別のカスタム設定があればその値、なければ共通設定の値を返す
 * （記事ごとに持たない設定: キャッシュ有効期限・AI 用の候補数・スライダー表示数）
 */
function kashiwazaki_seo_related_posts_get_type_setting($post_id, $key, $default) {
    $options = get_option('kashiwazaki_seo_related_posts_options', array());
    if (!is_array($options)) {
        $options = array();
    }
    $post_type = $post_id ? get_post_type($post_id) : '';
    if ($post_type) {
        $pt_settings = isset($options['post_type_settings_' . $post_type]) && is_array($options['post_type_settings_' . $post_type]) ? $options['post_type_settings_' . $post_type] : array();
        if (!empty($pt_settings['use_custom_settings']) && isset($pt_settings[$key]) && $pt_settings[$key] !== '') {
            return $pt_settings[$key];
        }
    }
    return isset($options[$key]) && $options[$key] !== '' ? $options[$key] : $default;
}

/**
 * 保存する秘密値（APIキー）の暗号化。
 * 鍵は wp-config.php のソルト（wp_salt('auth')）から作り、libsodium の secretbox（XSalsa20-Poly1305）で暗号化する。
 * nonce は保存のたびに random_bytes() で作り、暗号文の前に付けて保存する。
 */
define('KASHIWAZAKI_SEO_RELATED_POSTS_SECRET_PREFIX', 'ksrp-enc:v1:');

function kashiwazaki_seo_related_posts_secret_key() {
    return hash('sha256', 'kashiwazaki-seo-related-posts|openai-api-key|' . wp_salt('auth'), true);
}

/**
 * @return string|WP_Error 暗号化した文字列。暗号化できない環境では平文で保存せず WP_Error を返す
 */
function kashiwazaki_seo_related_posts_encrypt_secret($plain) {
    if (!is_string($plain) || $plain === '') {
        return '';
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        return new WP_Error('no_sodium', 'このサーバーでは APIキーを暗号化できないため保存しません（libsodium が使えません）。wp-config.php の定数 KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY で設定してください。');
    }
    try {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, kashiwazaki_seo_related_posts_secret_key());
    } catch (Exception $e) {
        return new WP_Error('encrypt_failed', 'APIキーを暗号化できなかったため保存しません: ' . $e->getMessage());
    }
    return KASHIWAZAKI_SEO_RELATED_POSTS_SECRET_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * @return string|null 復号した平文。暗号化されていない値（旧形式）はそのまま返す。復号できなければ null
 */
function kashiwazaki_seo_related_posts_decrypt_secret($stored) {
    if (!is_string($stored) || $stored === '') {
        return '';
    }
    if (strpos($stored, KASHIWAZAKI_SEO_RELATED_POSTS_SECRET_PREFIX) !== 0) {
        return $stored; // 旧形式（平文）。1.0.4 への移行で暗号化する
    }
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return null;
    }
    $raw = base64_decode(substr($stored, strlen(KASHIWAZAKI_SEO_RELATED_POSTS_SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    try {
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), kashiwazaki_seo_related_posts_secret_key());
    } catch (Exception $e) {
        return null;
    }
    return ($plain === false) ? null : $plain;
}

/**
 * OpenAI APIキーを取得する。
 * wp-config.php に定数 KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY があればそれを優先する（データベースに置かない運用）。
 * 保存済みのキーを復号できない（wp-config.php の鍵が変わった等）ときは空文字（キーなし）として扱う。
 */
function kashiwazaki_seo_related_posts_get_api_key() {
    if (kashiwazaki_seo_related_posts_api_key_from_constant()) {
        return trim(KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY);
    }
    $options = get_option('kashiwazaki_seo_related_posts_options', array());
    if (!is_array($options) || !isset($options['openai_api_key']) || !is_string($options['openai_api_key'])) {
        return '';
    }
    $plain = kashiwazaki_seo_related_posts_decrypt_secret($options['openai_api_key']);
    return is_string($plain) ? trim($plain) : '';
}

/**
 * 保存済みのキーがあるのに復号できないか（設定画面で入れ直しを促す）
 */
function kashiwazaki_seo_related_posts_api_key_undecryptable() {
    if (kashiwazaki_seo_related_posts_api_key_from_constant()) {
        return false;
    }
    $options = get_option('kashiwazaki_seo_related_posts_options', array());
    if (!is_array($options) || empty($options['openai_api_key']) || !is_string($options['openai_api_key'])) {
        return false;
    }
    return kashiwazaki_seo_related_posts_decrypt_secret($options['openai_api_key']) === null;
}

/**
 * APIキーが wp-config.php の定数で設定されているか
 */
function kashiwazaki_seo_related_posts_api_key_from_constant() {
    return defined('KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY') && is_string(KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY) && trim(KASHIWAZAKI_SEO_RELATED_POSTS_OPENAI_API_KEY) !== '';
}

/**
 * バージョンアップ時のデータ移行。
 * 1.0.4: OpenRouter 時代の設定（APIキーを含む）を削除し、embedding の既定値を入れる。
 */
function kashiwazaki_seo_related_posts_maybe_upgrade() {
    kashiwazaki_seo_related_posts_maybe_encrypt_stored_key();

    $stored = get_option('kashiwazaki_seo_related_posts_db_version', '0');
    if (version_compare($stored, '1.0.4', '>=')) {
        return;
    }

    // 旧バージョンが単独の option に保存していた OpenRouter のキー・モデル・有効フラグ
    delete_option('kashiwazaki_seo_related_posts_api_key');
    delete_option('kashiwazaki_seo_related_posts_model');
    delete_option('kashiwazaki_seo_related_posts_ai_enabled');

    $options = get_option('kashiwazaki_seo_related_posts_options', array());
    if (!is_array($options)) {
        $options = array();
    }
    foreach (array('openrouter_api_key', 'api_key', 'api_provider', 'model') as $legacy_key) {
        unset($options[$legacy_key]);
    }
    if (!isset($options['selection_mode'])) {
        $options['selection_mode'] = 'embedding';
    }
    if (!isset($options['embedding_model'])) {
        $options['embedding_model'] = 'text-embedding-3-small';
    }
    update_option('kashiwazaki_seo_related_posts_options', $options);

    update_option('kashiwazaki_seo_related_posts_db_version', '1.0.4');
}
add_action('plugins_loaded', 'kashiwazaki_seo_related_posts_maybe_upgrade');

/**
 * 平文で保存されている APIキー（1.0.3 以前の形式）を暗号化して保存し直す。
 * 暗号化できない環境では平文のまま残す（設定画面で wp-config.php の定数の利用を促す）
 */
function kashiwazaki_seo_related_posts_maybe_encrypt_stored_key() {
    $options = get_option('kashiwazaki_seo_related_posts_options', array());
    if (!is_array($options) || empty($options['openai_api_key']) || !is_string($options['openai_api_key'])) {
        return;
    }
    if (strpos($options['openai_api_key'], KASHIWAZAKI_SEO_RELATED_POSTS_SECRET_PREFIX) === 0) {
        return;
    }
    $encrypted = kashiwazaki_seo_related_posts_encrypt_secret(trim($options['openai_api_key']));
    if (is_string($encrypted) && $encrypted !== '') {
        $options['openai_api_key'] = $encrypted;
        update_option('kashiwazaki_seo_related_posts_options', $options);
    }
}

/**
 * 停止時: 予約しているベクトル作成を取り消す
 */
function kashiwazaki_seo_related_posts_deactivate() {
    // 引数の違いに関係なく、その hook の予約をすべて消す
    wp_unschedule_hook('kashiwazaki_seo_related_posts_embed_missing');
    wp_unschedule_hook('kashiwazaki_seo_related_posts_gpt_select');
}
register_deactivation_hook(__FILE__, 'kashiwazaki_seo_related_posts_deactivate');

class KashiwazakiSEORelatedPosts {

    private $admin;
    private $api;
    private $related_posts;
    private $similarity_calculator;
    private $shortcode;
    private $widget;
    private $embeddings;

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
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-embeddings.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-similarity-calculator.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-related-posts.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-shortcode.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-widget.php';
        require_once KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_DIR . 'includes/class-admin.php';
    }

    private function init() {
        $this->api = new KashiwazakiSEORelatedPosts_API();
        $this->similarity_calculator = new KashiwazakiSEORelatedPosts_SimilarityCalculator();
        $this->embeddings = new KashiwazakiSEORelatedPosts_Embeddings($this->api);
        $this->related_posts = new KashiwazakiSEORelatedPosts_RelatedPosts($this->similarity_calculator, $this->api, $this->embeddings);
        $this->shortcode = new KashiwazakiSEORelatedPosts_Shortcode($this->related_posts);
        $this->widget = new KashiwazakiSEORelatedPosts_Widget($this->related_posts);
        $this->admin = new KashiwazakiSEORelatedPosts_Admin($this->api, $this->related_posts, $this->embeddings);
    }

    public function enqueue_scripts() {
        $version = KASHIWAZAKI_SEO_RELATED_POSTS_VERSION;
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
        // 表示中の記事の投稿タイプにカスタム設定があればその値を使う
        $slider_post_id = is_singular() ? get_queried_object_id() : 0;
        wp_localize_script('kashiwazaki-seo-related-posts', 'kashiwazaki_slider_config', array(
            'items_desktop' => max(1, intval(kashiwazaki_seo_related_posts_get_type_setting($slider_post_id, 'slider_items_desktop', 3))),
            'items_tablet' => max(1, intval(kashiwazaki_seo_related_posts_get_type_setting($slider_post_id, 'slider_items_tablet', 2))),
            'items_mobile' => max(1, intval(kashiwazaki_seo_related_posts_get_type_setting($slider_post_id, 'slider_items_mobile', 1)))
        ));
    }

    public function enqueue_admin_scripts($hook) {
        // プラグイン設定ページまたは投稿編集画面でスクリプトを読み込み
        $is_plugin_page = strpos($hook, 'kashiwazaki-seo-related-posts') !== false;
        $is_edit_page = in_array($hook, array('post.php', 'post-new.php'));

        if (!$is_plugin_page && !$is_edit_page) return;

        $version = KASHIWAZAKI_SEO_RELATED_POSTS_VERSION;
        wp_enqueue_style('kashiwazaki-seo-related-posts-admin', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/css/admin.css', array(), $version);
        // 設定項目の「?」ツールチップ
        wp_add_inline_style('kashiwazaki-seo-related-posts-admin', '
            .kashiwazaki-help{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:5px;border-radius:50%;background:#787c82;color:#fff;font-size:11px;font-weight:600;line-height:1;cursor:help;position:relative;vertical-align:middle;font-style:normal}
            .kashiwazaki-help:hover,.kashiwazaki-help:focus{background:#2271b1;outline:none}
            .kashiwazaki-help:hover::after,.kashiwazaki-help:focus::after{content:attr(data-tip);position:absolute;left:-8px;top:calc(100% + 8px);width:max-content;max-width:340px;padding:8px 10px;background:#1d2327;color:#fff;font-size:12px;font-weight:400;line-height:1.7;text-align:left;white-space:pre-line;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.25);z-index:100000}
            .kashiwazaki-help--right:hover::after,.kashiwazaki-help--right:focus::after{left:auto;right:-8px}
        ');
        wp_enqueue_script('kashiwazaki-seo-related-posts-admin', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), $version, true);
        if ($is_plugin_page) {
            // API 統計グラフ用（外部 CDN を使わず同梱版を読み込む。グラフの描画スクリプトより先に必要なので head で読む）
            wp_enqueue_script('kashiwazaki-seo-related-posts-chartjs', KASHIWAZAKI_SEO_RELATED_POSTS_PLUGIN_URL . 'assets/js/lib/chart.min.js', array(), '3.9.1', false);
        }
        wp_localize_script('kashiwazaki-seo-related-posts-admin', 'kashiwazaki_related_posts_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kashiwazaki_fetch_related_posts'),
            'is_settings_page' => $is_plugin_page
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
