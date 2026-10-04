<?php
/**
 * アンインストール時のクリーンアップ。
 * プラグインが管理画面から削除された際に、保存した option と post meta を除去する。
 */

// WordPress から直接呼ばれた場合のみ実行（直接アクセス禁止）。
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * 1 サイト分のデータを削除する。
 */
function kashiwazaki_seo_related_posts_uninstall_site() {
    global $wpdb;

    // 予約しているベクトル作成を取り消す。
    wp_unschedule_hook('kashiwazaki_seo_related_posts_embed_missing');
    wp_unschedule_hook('kashiwazaki_seo_related_posts_gpt_select');

    // 既知の option を削除。
    $options = array(
        'kashiwazaki_seo_related_posts_options',
        'kashiwazaki_seo_related_posts_weights',
        'kashiwazaki_seo_related_posts_template',
        'kashiwazaki_seo_related_posts_show_excerpt',
        'kashiwazaki_seo_related_posts_show_thumbnail',
        'kashiwazaki_seo_related_posts_show_date',
        'kashiwazaki_seo_related_posts_debug_mode',
        'kashiwazaki_seo_related_posts_candidate_limit',
        'kashiwazaki_seo_related_posts_ai_threshold',
        'kashiwazaki_seo_related_posts_api_logs',
        'kashiwazaki_seo_related_posts_api_failure_logs',
        'kashiwazaki_seo_related_posts_model',
    );
    foreach ($options as $option) {
        delete_option($option);
    }

    // 接頭辞に一致する option を念のため一括削除（将来追加分も含む）。
    $like = $wpdb->esc_like('kashiwazaki_seo_related_posts_') . '%';
    $wpdb->query(
        $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
    );

    // 既知の post meta を削除。
    $meta_keys = array(
        '_kashiwazaki_seo_related_posts_cached_results',
        '_kashiwazaki_seo_related_posts_cached_timestamp',
        '_kashiwazaki_seo_related_posts_enabled',
        '_kashiwazaki_seo_related_posts_search_methods',
        '_kashiwazaki_seo_related_posts_max_posts',
        '_kashiwazaki_seo_related_posts_display_method',
        '_kashiwazaki_seo_related_posts_insert_position',
        '_kashiwazaki_seo_related_posts_target_post_types',
        '_kashiwazaki_seo_related_posts_filter_categories',
        '_kashiwazaki_seo_related_posts_color_theme',
        '_kashiwazaki_seo_related_posts_heading_text',
        '_kashiwazaki_seo_related_posts_heading_tag',
        '_kashiwazaki_seo_related_posts_used_model',
        '_kashiwazaki_seo_related_posts_embedding',
        '_kashiwazaki_debug_info',
    );
    foreach ($meta_keys as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }
}

if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        kashiwazaki_seo_related_posts_uninstall_site();
        restore_current_blog();
    }
} else {
    kashiwazaki_seo_related_posts_uninstall_site();
}
