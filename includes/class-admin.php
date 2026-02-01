<?php

if (!defined('ABSPATH')) exit;

class KashiwazakiSEORelatedPosts_Admin {

    private $api;
    private $related_posts;

    public function __construct($api, $related_posts) {
        $this->api = $api;
        $this->related_posts = $related_posts;
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_metabox'));
        add_action('save_post', array($this, 'save_metabox'));

        // AJAX処理を登録
        add_action('wp_ajax_kashiwazaki_fetch_related_posts', array($this, 'ajax_fetch_related_posts'));
        add_action('wp_ajax_kashiwazaki_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_kashiwazaki_reset_all_posts_to_defaults', array($this, 'ajax_reset_all_posts_to_defaults'));
        add_action('wp_ajax_kashiwazaki_enable_all_posts', array($this, 'ajax_enable_all_posts'));
    }

    /**
     * システムのデフォルト値を取得
     */
    private function get_system_defaults() {
        return array(
            'max_posts' => 5,
            'ai_candidate_buffer' => 20,
            'cache_lifetime' => 24,
            'display_method' => 'list',
            'color_theme' => 'blue',
            'heading_text' => '関連記事',
            'heading_tag' => 'h2',
            'insert_position' => 'after_content',
            'slider_items_desktop' => 3,
            'slider_items_tablet' => 2,
            'slider_items_mobile' => 1,
            'target_post_types' => array('post'),
            'metabox_post_types' => array('post'),
            'search_methods' => array('tags', 'categories', 'directory', 'title', 'excerpt')
        );
    }

    public function add_admin_menu() {
        // メインメニュー（基本設定ページを直接開く）
        add_menu_page(
            'Kashiwazaki SEO Related Posts',
            'Kashiwazaki SEO Related Posts',
            'manage_options',
            'kashiwazaki-seo-related-posts-settings',
            array($this, 'settings_page'),
            'dashicons-admin-generic',
            81
        );

        // 投稿タイプ別設定ページ（メニューには表示しない）
        add_submenu_page(
            null, // 親メニューをnullにすることでメニューに表示されない
            '投稿タイプ別設定',
            '投稿タイプ別設定',
            'manage_options',
            'kashiwazaki-seo-related-posts-settings-post-type',
            array($this, 'post_type_settings_page')
        );
    }

    // 削除：main_page関数は不要になったため削除
    // main_page() の内容は render_guide_tab() に移動済み

    // 削除：api_test_page関数は不要になったため削除
    // api_test_page() の内容は render_api_settings_tab() に移動済み

    public function settings_page() {
        // 保存処理
        if ($_POST && isset($_POST['save_api_settings'])) {
            $this->handle_save_api_settings();
        } elseif ($_POST && isset($_POST['save_common_settings'])) {
            $this->handle_save_common_settings();
        } elseif ($_POST && isset($_POST['reset_common_settings'])) {
            $this->handle_reset_common_settings();
        } elseif ($_POST && isset($_POST['clear_all_cache'])) {
            $this->handle_clear_all_cache();
        } elseif ($_POST && isset($_POST['save_metabox_settings'])) {
            $this->handle_save_metabox_settings();
        } elseif ($_POST && isset($_POST['reset_all_settings'])) {
            $this->handle_reset_all_settings();
        } elseif ($_POST && isset($_POST['save_post_type_settings'])) {
            $this->handle_save_post_type_settings();
        }

        // 現在のタブを取得
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'common';

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_key_set = !empty($options['openai_api_key']);
        $openai_model = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';
        $search_methods = isset($options['search_methods']) ? $options['search_methods'] : array('tags', 'categories');
        $max_posts = isset($options['max_posts']) ? $options['max_posts'] : 5;
        $ai_candidate_buffer = isset($options['ai_candidate_buffer']) ? $options['ai_candidate_buffer'] : 20;
        $cache_lifetime = isset($options['cache_lifetime']) ? $options['cache_lifetime'] : 24;
        $color_theme = isset($options['color_theme']) ? $options['color_theme'] : 'blue';
        $slider_items_desktop = isset($options['slider_items_desktop']) ? $options['slider_items_desktop'] : 3;
        $slider_items_tablet = isset($options['slider_items_tablet']) ? $options['slider_items_tablet'] : 2;
        $slider_items_mobile = isset($options['slider_items_mobile']) ? $options['slider_items_mobile'] : 1;
        $display_method = isset($options['display_method']) ? $options['display_method'] : 'list';
        $insert_position = isset($options['insert_position']) ? $options['insert_position'] : 'after_content';
        $target_post_types = isset($options['target_post_types']) ? $options['target_post_types'] : array('post');
        $metabox_post_types = isset($options['metabox_post_types']) ? $options['metabox_post_types'] : array('post');
        $all_post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <div class="wrap">
            <h1>Kashiwazaki SEO Related Posts</h1>

            <?php if (!$api_key_set): ?>
            <div class="notice notice-warning">
                <p><strong>注意:</strong> <a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=api'); ?>">AIのAPI設定</a>でAPIキーを設定してからご利用ください。</p>
            </div>
            <?php endif; ?>

            <!-- タブナビゲーション -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=kashiwazaki-seo-related-posts-settings&tab=api" class="nav-tab <?php echo $current_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    🤖 AIのAPI設定
                </a>
                <a href="?page=kashiwazaki-seo-related-posts-settings&tab=common" class="nav-tab <?php echo $current_tab === 'common' ? 'nav-tab-active' : ''; ?>">
                    🌐 共通設定
                </a>
                <a href="?page=kashiwazaki-seo-related-posts-settings&tab=post_types" class="nav-tab <?php echo $current_tab === 'post_types' ? 'nav-tab-active' : ''; ?>">
                    📋 投稿タイプ別設定
                </a>
                <a href="?page=kashiwazaki-seo-related-posts-settings&tab=guide" class="nav-tab <?php echo $current_tab === 'guide' ? 'nav-tab-active' : ''; ?>">
                    📚 使い方
                </a>
            </h2>

            <?php if ($current_tab === 'api'): ?>
                <?php $this->render_api_settings_tab($options); ?>
            <?php elseif ($current_tab === 'common'): ?>
                <?php $this->render_common_settings_tab($options, $api_key_set, $all_post_types); ?>
            <?php elseif ($current_tab === 'post_types'): ?>
                <?php $this->render_post_type_settings_tab($options, $all_post_types); ?>
            <?php elseif ($current_tab === 'guide'): ?>
                <?php $this->render_guide_tab($options); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // AIのAPI設定タブのレンダリング
    private function render_api_settings_tab($options) {
        $test_message = '';
        $test_success = null;

        // API設定の保存処理
        if (isset($_POST['save_api_settings'])) {
            $this->handle_save_api_settings();
        } elseif (isset($_POST['test_api'])) {
            $test_api_key = sanitize_text_field($_POST['api_key']);
            $test_result = $this->api->test_api_key($test_api_key, 'openai');

            if ($test_result['success']) {
                $test_message = $test_result['message'];
                $test_success = true;
            } else {
                $test_message = $test_result['message'];
                $test_success = false;
            }
        }

        $openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
        $openai_model = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';
        ?>

        <div style="background: #e7f4f9; border: 2px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 8px;">
            <h3 style="margin-top: 0;">💡 API設定について</h3>
            <p>OpenAI GPT のAPIキーを設定し、使用するAIモデルを選択してください。</p>
            <p>APIキーはAIによる関連記事の分析に必要です。</p>
        </div>

        <form method="post">
            <input type="hidden" name="save_api_settings" value="1" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="openai_api_key">🔑 OpenAI API キー</label></th>
                    <td>
                        <div style="position: relative; display: inline-block; width: 100%; max-width: 25em;">
                            <input type="password"
                                   id="openai_api_key"
                                   name="openai_api_key"
                                   value="<?php echo esc_attr($openai_api_key); ?>"
                                   class="regular-text"
                                   style="padding-right: 40px; width: 100%;" />
                            <button type="button"
                                    id="toggle-api-key-visibility"
                                    style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; padding: 5px; font-size: 16px;"
                                    title="表示/非表示を切り替え">
                                <span class="dashicons dashicons-visibility" style="width: 20px; height: 20px;"></span>
                            </button>
                        </div>
                        <p class="description"><a href="https://platform.openai.com/api-keys" target="_blank">OpenAI APIキーを取得</a></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">🤖 AIモデル</th>
                    <td>
                        <select name="openai_model">
                            <option value="gpt-4o-mini" <?php selected($openai_model, 'gpt-4o-mini'); ?>>gpt-4o-mini（推奨・最も低コスト）</option>
                            <option value="gpt-4o" <?php selected($openai_model, 'gpt-4o'); ?>>gpt-4o（バランス型）</option>
                            <option value="gpt-4-turbo" <?php selected($openai_model, 'gpt-4-turbo'); ?>>gpt-4-turbo（高性能）</option>
                        </select>
                        <p class="description">使用するAIモデルを選択してください。</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="save_api_settings" class="button button-primary" value="API設定を保存" />
            </p>
        </form>

        <hr />

        <h3>🧪 APIキーテスト</h3>
        <form method="post">
            <input type="hidden" name="test_api" value="1" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="test_api_key">テスト用APIキー</label></th>
                    <td>
                        <input type="text" id="test_api_key" name="api_key" class="regular-text" />
                        <p class="description">テストしたいOpenAI APIキーを入力してください。</p>
                    </td>
                </tr>
            </table>

            <?php if ($test_message): ?>
                <div style="margin: 10px 0; padding: 10px; border-radius: 4px; <?php echo $test_success ? 'background: #d4edda; border: 1px solid #c3e6cb; color: #155724;' : 'background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;'; ?>">
                    <strong><?php echo $test_success ? '✅' : '❌'; ?> <?php echo esc_html($test_message); ?></strong>
                </div>
            <?php endif; ?>

            <?php submit_button('APIキーをテスト', 'secondary'); ?>
        </form>

        <hr />

        <h3>📊 API呼び出し統計</h3>
        <div style="background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 4px;">
            <?php
            // API呼び出しログを取得
            $api_logs = get_option('kashiwazaki_seo_related_posts_api_logs', array());
            $api_failure_logs = get_option('kashiwazaki_seo_related_posts_api_failure_logs', array());
            $now = time();

            // 期間ごとのカウント（成功）
            $counts = array(
                '24h' => 0,
                '1w' => 0,
                '1m' => 0,
                '3m' => 0,
                '1y' => 0,
                'all' => count($api_logs)
            );

            foreach ($api_logs as $timestamp) {
                $age = $now - $timestamp;
                if ($age <= 86400) $counts['24h']++; // 24時間
                if ($age <= 604800) $counts['1w']++; // 1週間
                if ($age <= 2592000) $counts['1m']++; // 30日
                if ($age <= 7776000) $counts['3m']++; // 90日
                if ($age <= 31536000) $counts['1y']++; // 365日
            }

            // 期間ごとのカウント（失敗）
            $failure_counts = array(
                '24h' => 0,
                '1w' => 0,
                '1m' => 0,
                '3m' => 0,
                '1y' => 0,
                'all' => count($api_failure_logs)
            );

            foreach ($api_failure_logs as $timestamp) {
                $age = $now - $timestamp;
                if ($age <= 86400) $failure_counts['24h']++; // 24時間
                if ($age <= 604800) $failure_counts['1w']++; // 1週間
                if ($age <= 2592000) $failure_counts['1m']++; // 30日
                if ($age <= 7776000) $failure_counts['3m']++; // 90日
                if ($age <= 31536000) $failure_counts['1y']++; // 365日
            }
            ?>
            <table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>期間</th>
                        <th style="text-align: right;">成功</th>
                        <th style="text-align: right;">失敗</th>
                        <th style="text-align: right;">合計</th>
                        <th style="text-align: right;">成功率</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background: #f9f9f9;">
                        <td><strong>直近24時間</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['24h']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['24h']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['24h'] + $failure_counts['24h']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['24h'] + $failure_counts['24h'];
                            echo $total > 0 ? number_format($counts['24h'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>直近1週間</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['1w']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['1w']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['1w'] + $failure_counts['1w']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['1w'] + $failure_counts['1w'];
                            echo $total > 0 ? number_format($counts['1w'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                    <tr style="background: #f9f9f9;">
                        <td><strong>直近1ヶ月</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['1m']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['1m']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['1m'] + $failure_counts['1m']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['1m'] + $failure_counts['1m'];
                            echo $total > 0 ? number_format($counts['1m'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>直近3ヶ月</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['3m']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['3m']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['3m'] + $failure_counts['3m']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['3m'] + $failure_counts['3m'];
                            echo $total > 0 ? number_format($counts['3m'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                    <tr style="background: #f9f9f9;">
                        <td><strong>直近1年</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['1y']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['1y']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['1y'] + $failure_counts['1y']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['1y'] + $failure_counts['1y'];
                            echo $total > 0 ? number_format($counts['1y'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                    <tr style="background: #e7f4f9; font-weight: bold;">
                        <td><strong>すべて</strong></td>
                        <td style="text-align: right; color: #00a32a;"><strong><?php echo number_format($counts['all']); ?></strong></td>
                        <td style="text-align: right; color: #d63638;"><strong><?php echo number_format($failure_counts['all']); ?></strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($counts['all'] + $failure_counts['all']); ?></strong></td>
                        <td style="text-align: right;">
                            <?php
                            $total = $counts['all'] + $failure_counts['all'];
                            echo $total > 0 ? number_format($counts['all'] / $total * 100, 1) . '%' : '-';
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top: 10px;">
                AI分析機能を使用した際のAPI呼び出し回数を集計しています。<br>
                ログは自動的に1年以上経過したものから削除されます。
            </p>
        </div>

        <h3 style="margin-top: 30px;">📈 直近30日間の推移</h3>
        <div style="background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 4px;">
            <?php
            // 直近30日間の日別カウント（成功）
            $daily_counts = array();
            $thirty_days_ago = strtotime('-30 days', strtotime('today'));

            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("+$i days", $thirty_days_ago));
                $daily_counts[$date] = 0;
            }

            foreach ($api_logs as $timestamp) {
                $date = date('Y-m-d', $timestamp);
                if (isset($daily_counts[$date])) {
                    $daily_counts[$date]++;
                }
            }

            // 直近30日間の日別カウント（失敗）
            $daily_failure_counts = array();
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("+$i days", $thirty_days_ago));
                $daily_failure_counts[$date] = 0;
            }

            foreach ($api_failure_logs as $timestamp) {
                $date = date('Y-m-d', $timestamp);
                if (isset($daily_failure_counts[$date])) {
                    $daily_failure_counts[$date]++;
                }
            }

            $dates = array_keys($daily_counts);
            $counts_data = array_values($daily_counts);
            $failure_counts_data = array_values($daily_failure_counts);
            ?>

            <canvas id="api-usage-chart" style="max-height: 300px;"></canvas>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
            <script>
            var ctx = document.getElementById('api-usage-chart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($date) {
                        return date('m/d', strtotime($date));
                    }, $dates)); ?>,
                    datasets: [
                        {
                            label: '成功',
                            data: <?php echo json_encode($counts_data); ?>,
                            borderColor: '#00a32a',
                            backgroundColor: 'rgba(0, 163, 42, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: '失敗',
                            data: <?php echo json_encode($failure_counts_data); ?>,
                            borderColor: '#d63638',
                            backgroundColor: 'rgba(214, 54, 56, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
            </script>
            <p class="description" style="margin-top: 10px;">
                直近30日間の日別API呼び出し回数（成功/失敗）を表示しています。<br>
                <span style="color: #00a32a;">■</span> <strong>緑色:</strong> API呼び出し成功
                <span style="color: #d63638;">■</span> <strong>赤色:</strong> API呼び出し失敗
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // APIキーの表示/非表示切り替え
            $('#toggle-api-key-visibility').on('click', function(e) {
                e.preventDefault();
                var $input = $('#openai_api_key');
                var $icon = $(this).find('.dashicons');

                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
        });
        </script>
        <?php
    }

    // 共通設定タブのレンダリング
    private function render_common_settings_tab($options, $api_key_set, $all_post_types) {
        $defaults = $this->get_system_defaults();
        $openai_model = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4.1-nano';
        $max_posts = isset($options['max_posts']) ? $options['max_posts'] : $defaults['max_posts'];
        $ai_candidate_buffer = isset($options['ai_candidate_buffer']) ? $options['ai_candidate_buffer'] : $defaults['ai_candidate_buffer'];
        $cache_lifetime = isset($options['cache_lifetime']) ? $options['cache_lifetime'] : $defaults['cache_lifetime'];
        $color_theme = isset($options['color_theme']) ? $options['color_theme'] : $defaults['color_theme'];
        $display_method = isset($options['display_method']) ? $options['display_method'] : $defaults['display_method'];
        $insert_position = isset($options['insert_position']) ? $options['insert_position'] : $defaults['insert_position'];
        $target_post_types = isset($options['target_post_types']) ? $options['target_post_types'] : $defaults['target_post_types'];
        $metabox_post_types = isset($options['metabox_post_types']) ? $options['metabox_post_types'] : $defaults['metabox_post_types'];
        $slider_items_desktop = isset($options['slider_items_desktop']) ? $options['slider_items_desktop'] : $defaults['slider_items_desktop'];
        $slider_items_tablet = isset($options['slider_items_tablet']) ? $options['slider_items_tablet'] : $defaults['slider_items_tablet'];
        $slider_items_mobile = isset($options['slider_items_mobile']) ? $options['slider_items_mobile'] : $defaults['slider_items_mobile'];

        ?>

        <div style="background: #e7f4f9; border: 2px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 8px;">
            <h3 style="margin-top: 0;">共通設定について</h3>
            <p>ここで設定した値は、すべての投稿タイプで共通のデフォルト値として使用されます。</p>
            <p>投稿タイプごとに個別の設定を行いたい場合や、メタボックス（編集画面の設定ボックス）の表示設定を変更したい場合は、「投稿タイプ別設定」タブから設定してください。</p>
        </div>

        <form method="post" id="common-settings-form">
            <input type="hidden" name="save_common_settings" value="1" />
            <table class="form-table">
                <tr>
                    <th scope="row">🔍 関連記事の検索範囲</th>
                    <td>
                        <!-- 投稿タイプの選択 -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <h4 style="margin: 0;">📄 投稿タイプ</h4>
                            <div>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = true; });">全チェック</button>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = false; });">全解除</button>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = cb.defaultChecked; });">リセット</button>
                            </div>
                        </div>
                        <fieldset style="margin-bottom: 20px;">
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                <?php foreach ($all_post_types as $post_type):
                                    // 投稿タイプの記事数を取得
                                    $post_count = wp_count_posts($post_type->name);
                                    $published_count = isset($post_count->publish) ? $post_count->publish : 0;
                                ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox"
                                               name="target_post_types[]"
                                               value="<?php echo esc_attr($post_type->name); ?>"
                                               <?php checked(in_array($post_type->name, $target_post_types)); ?> />
                                        <?php echo esc_html($post_type->label); ?>
                                        <small>(<?php echo esc_html($post_type->name); ?>)</small>
                                        <strong style="color: #555;">(<?php echo number_format($published_count); ?>記事)</strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <!-- カテゴリの選択 -->
                        <?php
                        $filter_categories = isset($options['filter_categories']) ? $options['filter_categories'] : array();
                        $categories = get_categories(array(
                            'orderby' => 'name',
                            'order' => 'ASC',
                            'hide_empty' => false
                        ));
                        ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; margin-top: 20px;">
                            <h4 style="margin: 0;">📁 カテゴリ</h4>
                            <div>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = true; });">全チェック</button>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = false; });">全解除</button>
                                <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = cb.defaultChecked; });">リセット</button>
                            </div>
                        </div>
                        <fieldset>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                <?php foreach ($categories as $category):
                                    $post_count = $category->count;
                                ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox"
                                               name="filter_categories[]"
                                               value="<?php echo esc_attr($category->term_id); ?>"
                                               <?php checked(in_array($category->term_id, $filter_categories)); ?> />
                                        <?php echo esc_html($category->name); ?>
                                        <strong style="color: #555;">(<?php echo number_format($post_count); ?>記事)</strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <p class="description" style="margin-top: 15px;">
                            関連記事の検索範囲を設定します。<br>
                            <strong>投稿タイプ:</strong> チェックを入れた投稿タイプから関連記事が検索されます。<br>
                            <strong>カテゴリ:</strong> 特定のカテゴリに絞り込む場合はチェックを入れてください。チェックなしの場合はすべてのカテゴリから検索されます。
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="max_posts">📊 デフォルト最大表示記事数</label></th>
                    <td>
                        <input type="number" id="max_posts" name="max_posts" value="<?php echo esc_attr($max_posts); ?>" min="1" max="20" style="width: 80px;" />
                        <p class="description">すべての投稿タイプで使用されるデフォルトの最大表示記事数です。</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ai_candidate_buffer">🤖 AI分析用候補数追加</label></th>
                    <td>
                        <input type="number" id="ai_candidate_buffer" name="ai_candidate_buffer" value="<?php echo esc_attr($ai_candidate_buffer); ?>" min="5" max="100" style="width: 80px;" /> 件
                        <p class="description">AIで分析する候補記事数を増やします。例：最大表示5件の場合、5+この値（20）=25件の候補からAIが最適な5件を選びます。</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="cache_lifetime">💾 キャッシュ有効期限</label></th>
                    <td>
                        <input type="number" id="cache_lifetime" name="cache_lifetime" value="<?php echo esc_attr($cache_lifetime); ?>" min="1" max="8760" style="width: 100px;" /> 時間
                        <p class="description">関連記事キャッシュの有効期限です。最大8760時間（365日）まで設定可能です。</p>

                        <div style="margin-top: 15px;">
                            <a href="#" id="toggle-cache-info" style="text-decoration: none;">▶ 現在のキャッシュ情報</a>
                            <div id="cache-info-content" style="display: none; margin-top: 10px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                                <?php
                                global $wpdb;

                                // 全投稿タイプのキャッシュ情報を取得
                                $all_post_types = get_post_types(array('public' => true), 'objects');
                                echo '<table style="width: 100%; border-collapse: collapse;">';
                                echo '<thead><tr style="background: #f0f0f0;">';
                                echo '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">投稿タイプ</th>';
                                echo '<th style="padding: 8px; text-align: right; border: 1px solid #ddd;">キャッシュ数</th>';
                                echo '</tr></thead><tbody>';

                                $total_cache = 0;
                                foreach ($all_post_types as $pt) {
                                    $cache_count = $wpdb->get_var($wpdb->prepare(
                                        "SELECT COUNT(DISTINCT pm.post_id)
                                         FROM {$wpdb->postmeta} pm
                                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                                         WHERE pm.meta_key = '_kashiwazaki_seo_related_posts_cached_results'
                                         AND p.post_type = %s",
                                        $pt->name
                                    ));

                                    if ($cache_count > 0) {
                                        echo '<tr>';
                                        echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($pt->label) . ' <small>(' . esc_html($pt->name) . ')</small></td>';
                                        echo '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;"><strong>' . number_format($cache_count) . '</strong> 件</td>';
                                        echo '</tr>';
                                        $total_cache += $cache_count;
                                    }
                                }

                                echo '<tr style="background: #f0f0f0; font-weight: bold;">';
                                echo '<td style="padding: 8px; border: 1px solid #ddd;">合計</td>';
                                echo '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' . number_format($total_cache) . ' 件</td>';
                                echo '</tr>';
                                echo '</tbody></table>';
                                ?>
                            </div>
                        </div>

                        <div style="margin-top: 10px;">
                            <button type="button" id="clear-all-cache-btn" class="button button-secondary">全キャッシュをクリア</button>
                        </div>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var toggle = document.getElementById('toggle-cache-info');
                            var content = document.getElementById('cache-info-content');
                            if (toggle && content) {
                                toggle.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    if (content.style.display === 'none') {
                                        content.style.display = 'block';
                                        toggle.innerHTML = '▼ 現在のキャッシュ情報';
                                    } else {
                                        content.style.display = 'none';
                                        toggle.innerHTML = '▶ 現在のキャッシュ情報';
                                    }
                                });
                            }

                            // キャッシュクリアボタンの処理
                            var clearBtn = document.getElementById('clear-all-cache-btn');
                            if (clearBtn) {
                                clearBtn.addEventListener('click', function(e) {
                                    if (!confirm('全ての投稿の関連記事キャッシュをクリアしますか？')) {
                                        return;
                                    }
                                    // 隠しフォームを作成して送信
                                    var form = document.createElement('form');
                                    form.method = 'post';
                                    form.style.display = 'none';

                                    var input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'clear_all_cache';
                                    input.value = '1';

                                    form.appendChild(input);
                                    document.body.appendChild(form);
                                    form.submit();
                                });
                            }
                        });
                        </script>
                    </td>
                </tr>

                <tr>
                    <th scope="row">🎨 デフォルト表示形式</th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="display_method" value="list" <?php checked($display_method, 'list'); ?> /> リスト</label><br/>
                            <label><input type="radio" name="display_method" value="grid" <?php checked($display_method, 'grid'); ?> /> グリッド</label><br/>
                            <label><input type="radio" name="display_method" value="slider" <?php checked($display_method, 'slider'); ?> /> スライダー</label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row">🎨 カラーテーマ</th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="color_theme" value="blue" <?php checked($color_theme, 'blue'); ?> /> <span style="color: #007cba;">■</span> ブルー</label><br/>
                            <label><input type="radio" name="color_theme" value="orange" <?php checked($color_theme, 'orange'); ?> /> <span style="color: #ff7f50;">■</span> オレンジ</label><br/>
                            <label><input type="radio" name="color_theme" value="green" <?php checked($color_theme, 'green'); ?> /> <span style="color: #27ae60;">■</span> グリーン</label><br/>
                            <label><input type="radio" name="color_theme" value="purple" <?php checked($color_theme, 'purple'); ?> /> <span style="color: #8e44ad;">■</span> パープル</label><br/>
                            <label><input type="radio" name="color_theme" value="red" <?php checked($color_theme, 'red'); ?> /> <span style="color: #e74c3c;">■</span> レッド</label><br/>
                            <label><input type="radio" name="color_theme" value="white" <?php checked($color_theme, 'white'); ?> /> ホワイト</label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="heading_text">📝 関連記事見出し</label></th>
                    <td>
                        <input type="text" id="heading_text" name="heading_text" value="<?php echo esc_attr(isset($options['heading_text']) ? $options['heading_text'] : '関連記事'); ?>" class="regular-text" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="heading_tag">🏷️ 見出しタグ</label></th>
                    <td>
                        <select id="heading_tag" name="heading_tag">
                            <?php
                            $current_heading_tag = isset($options['heading_tag']) ? $options['heading_tag'] : 'h2';
                            ?>
                            <option value="h2" <?php selected($current_heading_tag, 'h2'); ?>>H2</option>
                            <option value="h3" <?php selected($current_heading_tag, 'h3'); ?>>H3</option>
                            <option value="h4" <?php selected($current_heading_tag, 'h4'); ?>>H4</option>
                            <option value="div" <?php selected($current_heading_tag, 'div'); ?>>DIV</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="insert_position">📍 デフォルト挿入位置</label></th>
                    <td>
                        <select id="insert_position" name="insert_position">
                            <option value="before_content" <?php selected($insert_position, 'before_content'); ?>>記事の前</option>
                            <option value="after_content" <?php selected($insert_position, 'after_content'); ?>>記事の後</option>
                            <option value="after_first_paragraph" <?php selected($insert_position, 'after_first_paragraph'); ?>>最初の段落の後</option>
                        </select>
                    </td>
                </tr>
            </table>

            <style>
            .ksrp-button-group {
                display: flex !important;
                gap: 10px;
                align-items: center;
                margin-top: 20px;
            }
            </style>
            <div class="ksrp-button-group">
                <input type="submit" name="save_common_settings" class="button button-primary" value="共通設定を保存" />
        </form>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="reset_common_settings" value="1" />
                    <input type="submit" class="button button-secondary" value="初期値にリセット" onclick="return confirm('共通設定を初期値にリセットしますか？');" />
                </form>
            </div>
        <?php
    }

    // 使い方タブのレンダリング
    private function render_guide_tab($options) {
        $api_key_set = !empty($options['openai_api_key']);

        $settings_count = count(array_filter($options, function($value, $key) {
            return !in_array($key, array('api_key', 'openrouter_api_key', 'openai_api_key', 'api_provider')) && !empty($value);
        }, ARRAY_FILTER_USE_BOTH));
        ?>
        <div style="background: #f0f8ff; border: 2px solid #0073aa; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>📋 プラグイン概要</h2>
            <p>タグ、カテゴリ、ディレクトリ構造、タイトル、抜粋からAIを使って関連記事を表示するSEOツールです。</p>

            <h3>🚀 セットアップガイド</h3>
            <ol>
                <li><strong><a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=api'); ?>">API設定</a></strong> - OpenAI GPT のAPIキーを設定</li>
                <li><strong><a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=common'); ?>">共通設定</a></strong> - 検索方法、表示設定、自動挿入設定を行う</li>
                <li><strong><a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=post_types'); ?>">投稿タイプ別設定</a></strong> - 投稿タイプごとの個別設定（オプション）</li>
            </ol>

            <h3>📊 現在の状態</h3>
            <p>API キー: <?php echo $api_key_set ? '<span style="color: green;">✅ 設定済み</span>' : '<span style="color: red;">❌ 未設定</span>'; ?></p>
            <p>基本設定: <?php echo $settings_count > 0 ? '<span style="color: green;">✅ 設定済み (' . $settings_count . '項目)</span>' : '<span style="color: red;">❌ 未設定</span>'; ?></p>
        </div>

        <div style="margin-top: 30px; padding: 20px; border: 2px solid #007cba; background: #f0f8ff; border-radius: 8px;">
            <h3 style="color: #007cba;">📝 ショートコード使用方法</h3>
            <p>記事や固定ページで関連記事を表示するには、以下のショートコードを使用してください。</p>

            <h4>🔹 基本的な使い方</h4>
            <div style="background: #fff; border: 1px solid #ccc; padding: 10px; border-radius: 4px; font-family: monospace; margin: 10px 0;">
                [kashiwazaki_related_posts]
            </div>

            <h4>🔹 パラメータ一覧</h4>
            <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">パラメータ</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">説明</th>
                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">デフォルト値</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;"><code>max_posts</code></td>
                        <td style="border: 1px solid #ddd; padding: 8px;">最大表示記事数（1-20）</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">5</td>
                    </tr>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;"><code>template</code></td>
                        <td style="border: 1px solid #ddd; padding: 8px;">表示形式（list/grid/slider/card/minimal）</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">list</td>
                    </tr>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;"><code>use_ai</code></td>
                        <td style="border: 1px solid #ddd; padding: 8px;">AI分析使用（true/false/auto）</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">auto</td>
                    </tr>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;"><code>title</code></td>
                        <td style="border: 1px solid #ddd; padding: 8px;">セクションタイトル</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">関連記事</td>
                    </tr>
                </tbody>
            </table>

            <h4>🔹 使用例</h4>
            <div style="background: #fff; border: 1px solid #ccc; padding: 10px; border-radius: 4px; font-family: monospace; margin: 10px 0;">
                [kashiwazaki_related_posts max_posts="10" template="grid" title="おすすめ記事"]
            </div>
        </div>

        <div style="margin-top: 30px; padding: 20px; border: 2px solid #dc3232; background: #fff; border-radius: 8px;">
            <h3 style="color: #dc3232; margin-top: 0;">🗑️ 全設定リセット</h3>
            <p>すべての設定を削除してプラグインを初期状態に戻します。APIキーも削除されます。</p>
            <form method="post">
                <input type="hidden" name="reset_all_settings" value="1" />
                <input type="submit" class="button button-link-delete" value="全設定をリセット" onclick="return confirm('本当に全ての設定を削除しますか？この操作は取り消せません。');" />
            </form>
        </div>
        <?php
    }

    // 投稿タイプ別設定タブのレンダリング
    private function render_post_type_settings_tab($options, $all_post_types) {
        ?>
        <div style="background: #e7f4f9; border: 2px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 8px;">
            <h3 style="margin-top: 0;">投稿タイプ別設定について</h3>
            <p>各投稿タイプごとに「関連記事を表示」のチェックボックスで、編集画面にメタボックスを表示するかどうかを設定できます。</p>
            <p>また、「共通設定を使う」または「カスタム設定」を選択できます。カスタム設定では、最大表示記事数、表示形式、カラーテーマなどを個別に設定できます。</p>
        </div>

        <div style="margin: 15px 0;">
            <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;metabox_post_types[]&quot;]').forEach(function(cb){ cb.checked = true; });">全チェック</button>
            <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;metabox_post_types[]&quot;]').forEach(function(cb){ cb.checked = false; });">全解除</button>
            <button type="button" class="button button-small" onclick="document.querySelectorAll('input[name=&quot;metabox_post_types[]&quot;]').forEach(function(cb){ cb.checked = cb.defaultChecked; });">リセット</button>
        </div>

        <style>
        .post-types-table tbody tr:nth-child(odd) {
            background-color: #f9f9f9;
        }
        .post-types-table tbody tr:nth-child(even) {
            background-color: #ffffff;
        }
        .post-types-table tbody tr:hover {
            background-color: #f0f0f1;
        }
        </style>

        <form method="post">
            <input type="hidden" name="save_metabox_settings" value="1" />
            <table class="widefat post-types-table" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>投稿タイプ</th>
                        <th style="text-align: center;">キャッシュ / 記事数</th>
                        <th style="text-align: center;">関連記事を表示</th>
                        <th>設定状態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $metabox_post_types = isset($options['metabox_post_types']) ? $options['metabox_post_types'] : array('post');
                    foreach ($all_post_types as $post_type):
                        $pt_settings_key = 'post_type_settings_' . $post_type->name;
                        $pt_settings = isset($options[$pt_settings_key]) ? $options[$pt_settings_key] : array();
                        $use_custom_settings = isset($pt_settings['use_custom_settings']) && $pt_settings['use_custom_settings'];

                        // 投稿タイプの記事数を取得
                        $post_count = wp_count_posts($post_type->name);
                        $published_count = isset($post_count->publish) ? $post_count->publish : 0;

                        // キャッシュ数を取得
                        global $wpdb;
                        $cache_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(DISTINCT pm.post_id)
                             FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                             WHERE pm.meta_key = '_kashiwazaki_seo_related_posts_cached_results'
                             AND p.post_type = %s",
                            $post_type->name
                        ));
                        $cache_count = $cache_count ? $cache_count : 0;
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('edit.php?post_type=' . esc_attr($post_type->name)); ?>" style="text-decoration: none;">
                                <strong><?php echo esc_html($post_type->label); ?></strong>
                            </a>
                            <small>(<?php echo esc_html($post_type->name); ?>)</small>
                        </td>
                        <td style="text-align: center;">
                            <strong><?php echo number_format($cache_count); ?></strong> / <?php echo number_format($published_count); ?>
                            <?php if ($published_count > 0): ?>
                                <small>(<?php echo round($cache_count / $published_count * 100, 1); ?>%)</small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox"
                                   name="metabox_post_types[]"
                                   value="<?php echo esc_attr($post_type->name); ?>"
                                   <?php checked(in_array($post_type->name, $metabox_post_types)); ?>
                                   class="metabox-post-type-checkbox"
                                   data-post-type="<?php echo esc_attr($post_type->name); ?>" />
                        </td>
                        <td>
                            <?php if ($use_custom_settings): ?>
                                <span style="color: #d63638;">●</span> カスタム設定
                            <?php else: ?>
                                <span style="color: #2271b1;">●</span> 共通設定を使用
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings-post-type&post_type=' . esc_attr($post_type->name)); ?>" class="button button-primary button-small">
                                <?php echo $use_custom_settings ? '設定を編集' : 'カスタム設定を作成'; ?>
                            </a>
                            <?php if (in_array($post_type->name, $metabox_post_types) && $published_count > 0): ?>
                                <button type="button"
                                        class="button button-small kashiwazaki-enable-all-posts"
                                        data-post-type="<?php echo esc_attr($post_type->name); ?>"
                                        data-post-type-label="<?php echo esc_attr($post_type->label); ?>"
                                        style="margin-left: 5px; background: #00a32a; color: white; border-color: #00a32a;">
                                    すべて有効化
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f1;">
                        <th>合計</th>
                        <th style="text-align: center;">
                            <?php
                            // 全投稿タイプの合計を計算
                            $total_posts = 0;
                            $total_cache = 0;
                            foreach ($all_post_types as $post_type) {
                                $post_count = wp_count_posts($post_type->name);
                                $total_posts += isset($post_count->publish) ? $post_count->publish : 0;

                                $cache_count = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(DISTINCT pm.post_id)
                                     FROM {$wpdb->postmeta} pm
                                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                                     WHERE pm.meta_key = '_kashiwazaki_seo_related_posts_cached_results'
                                     AND p.post_type = %s",
                                    $post_type->name
                                ));
                                $total_cache += $cache_count ? $cache_count : 0;
                            }
                            echo '<strong>' . number_format($total_cache) . '</strong> / ' . number_format($total_posts);
                            if ($total_posts > 0) {
                                echo ' <small>(' . round($total_cache / $total_posts * 100, 1) . '%)</small>';
                            }
                            ?>
                        </th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
            <p class="submit" style="margin-top: 15px;">
                <input type="submit" name="save_metabox_settings" class="button button-primary" value="表示設定を保存" />
            </p>
        </form>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // すべて有効化ボタンの処理
            $('.kashiwazaki-enable-all-posts').on('click', function() {
                var $button = $(this);
                var postType = $button.data('post-type');
                var postTypeLabel = $button.data('post-type-label');
                var $originalText = $button.text();

                if (!confirm('「' + postTypeLabel + '」のすべての記事で関連記事を表示しますか？\n\nこの操作は取り消せません。')) {
                    return;
                }

                // ボタンを無効化して処理中表示
                $button.prop('disabled', true).text('処理中...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'kashiwazaki_enable_all_posts',
                        post_type: postType,
                        nonce: '<?php echo wp_create_nonce('kashiwazaki_admin_action'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('成功: ' + response.data.message);
                        } else {
                            alert('エラー: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('通信エラーが発生しました。');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text($originalText);
                    }
                });
            });

            // チェックボックスの変更を監視して、ボタンの表示を動的に変更
            $('.metabox-post-type-checkbox').on('change', function() {
                var $checkbox = $(this);
                var postType = $checkbox.data('post-type');
                var $button = $('.kashiwazaki-enable-all-posts[data-post-type="' + postType + '"]');

                if ($checkbox.is(':checked')) {
                    $button.show();
                } else {
                    $button.hide();
                }
            });
        });
        </script>
        <?php
    }

    // API設定の保存処理
    public function handle_save_api_settings() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        // OpenAI APIキー
        $options['openai_api_key'] = isset($_POST['openai_api_key']) ? sanitize_text_field($_POST['openai_api_key']) : '';

        // OpenAI モデル設定
        $options['openai_model'] = isset($_POST['openai_model']) ? sanitize_text_field($_POST['openai_model']) : 'gpt-4o-mini';

        // APIプロバイダーは常にOpenAI
        $options['api_provider'] = 'openai';

        update_option('kashiwazaki_seo_related_posts_options', $options);
        echo '<div class="notice notice-success"><p>API設定を保存しました。</p></div>';
    }

    // メタボックス表示設定の保存処理
    public function handle_save_metabox_settings() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        $options['metabox_post_types'] = isset($_POST['metabox_post_types']) && is_array($_POST['metabox_post_types'])
            ? array_map('sanitize_text_field', $_POST['metabox_post_types'])
            : array();

        update_option('kashiwazaki_seo_related_posts_options', $options);

        echo '<div class="notice notice-success"><p>関連記事の表示設定を保存しました。</p></div>';
    }

    // 共通設定の保存処理
    public function handle_save_common_settings() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $defaults = $this->get_system_defaults();

        $options['search_methods'] = array('tags', 'categories', 'directory', 'title', 'excerpt');
        $options['max_posts'] = isset($_POST['max_posts']) ? absint($_POST['max_posts']) : $defaults['max_posts'];
        $options['ai_candidate_buffer'] = isset($_POST['ai_candidate_buffer']) ? absint($_POST['ai_candidate_buffer']) : $defaults['ai_candidate_buffer'];
        $options['cache_lifetime'] = isset($_POST['cache_lifetime']) ? max(1, min(8760, absint($_POST['cache_lifetime']))) : $defaults['cache_lifetime'];
        $options['color_theme'] = isset($_POST['color_theme']) ? sanitize_text_field($_POST['color_theme']) : $defaults['color_theme'];
        $options['display_method'] = isset($_POST['display_method']) ? sanitize_text_field($_POST['display_method']) : $defaults['display_method'];
        $options['insert_position'] = isset($_POST['insert_position']) ? sanitize_text_field($_POST['insert_position']) : $defaults['insert_position'];
        $options['target_post_types'] = isset($_POST['target_post_types']) && !empty($_POST['target_post_types']) ? array_map('sanitize_text_field', $_POST['target_post_types']) : $defaults['target_post_types'];
        $options['filter_categories'] = isset($_POST['filter_categories']) && is_array($_POST['filter_categories']) ? array_map('intval', $_POST['filter_categories']) : array();
        $options['heading_text'] = isset($_POST['heading_text']) ? sanitize_text_field($_POST['heading_text']) : $defaults['heading_text'];
        $options['heading_tag'] = isset($_POST['heading_tag']) ? sanitize_text_field($_POST['heading_tag']) : $defaults['heading_tag'];

        update_option('kashiwazaki_seo_related_posts_options', $options);

        echo '<div class="notice notice-success"><p>共通設定を保存しました。</p></div>';
    }


        /**
     * プラグイン設定状況を表示
     */
    private function render_plugin_status() {
        // 「この記事でKashiwazaki SEO Related Postsを使用する」がチェックされている記事を取得
        global $wpdb;
        $enabled_posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_kashiwazaki_seo_related_posts_enabled'
             AND pm.meta_value = '1'
             AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.post_modified DESC"
        );

        ?>
        <div class="kashiwazaki-status-section">
            <h4>✅ プラグインが有効化されている記事一覧</h4>
            <p style="margin-bottom: 15px; color: #666;">
                「この記事でKashiwazaki SEO Related Postsを使用する」がチェックされている記事
            </p>
            <?php if (!empty($enabled_posts)): ?>
                <div style="margin-bottom: 10px;">
                    <strong>合計: <?php echo count($enabled_posts); ?>件</strong>
                </div>
                <ul class="kashiwazaki-status-list" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white;">
                    <?php foreach ($enabled_posts as $post): ?>
                        <li style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0;">
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $post->ID . '&action=edit')); ?>" target="_blank" style="font-weight: 500;">
                                <?php echo esc_html($post->post_title); ?>
                            </a>
                            <div style="font-size: 12px; color: #666; margin-top: 3px;">
                                投稿タイプ: <span style="color: #0073aa;"><?php echo esc_html($post->post_type); ?></span> |
                                ステータス: <span style="color: <?php echo $post->post_status === 'publish' ? '#46b450' : '#dc3232'; ?>;"><?php echo esc_html($post->post_status); ?></span> |
                                投稿日: <?php echo esc_html(date('Y-m-d H:i', strtotime($post->post_date))); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 10px;">
                    <small style="color: #666;">
                        💡 記事タイトルをクリックすると編集画面に移動します
                    </small>
                </p>
            <?php else: ?>
                <p class="kashiwazaki-status-empty" style="text-align: center; padding: 20px; background: #f9f9f9; border: 1px dashed #ccc;">
                    プラグインが有効化されている記事はありません
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_save_settings() {


        $options = get_option('kashiwazaki_seo_related_posts_options', array());

        if (isset($_POST['model'])) {
            $options['model'] = sanitize_text_field($_POST['model']);
        }
        if (isset($_POST['openai_model'])) {
            $options['openai_model'] = sanitize_text_field($_POST['openai_model']);
        }
        // AI分析対象要素は必須として固定
        $options['search_methods'] = array('tags', 'categories', 'directory', 'title', 'excerpt');
        $options['max_posts'] = absint($_POST['max_posts']);
        $options['ai_candidate_buffer'] = isset($_POST['ai_candidate_buffer']) ? absint($_POST['ai_candidate_buffer']) : 20;
        $options['cache_lifetime'] = isset($_POST['cache_lifetime']) ? max(1, min(168, absint($_POST['cache_lifetime']))) : 24;
        $options['color_theme'] = isset($_POST['color_theme']) ? sanitize_text_field($_POST['color_theme']) : 'blue';
        $options['slider_items_desktop'] = isset($_POST['slider_items_desktop']) ? max(1, min(8, absint($_POST['slider_items_desktop']))) : 3;
        $options['slider_items_tablet'] = isset($_POST['slider_items_tablet']) ? max(1, min(6, absint($_POST['slider_items_tablet']))) : 2;
        $options['slider_items_mobile'] = isset($_POST['slider_items_mobile']) ? max(1, min(3, absint($_POST['slider_items_mobile']))) : 1;
        $options['display_method'] = sanitize_text_field($_POST['display_method']);
        $options['insert_position'] = sanitize_text_field($_POST['insert_position']);
        $options['target_post_types'] = isset($_POST['target_post_types']) ? array_map('sanitize_text_field', $_POST['target_post_types']) : array();
        $options['metabox_post_types'] = isset($_POST['metabox_post_types']) ? array_map('sanitize_text_field', $_POST['metabox_post_types']) : array();
        $options['heading_text'] = isset($_POST['heading_text']) ? sanitize_text_field($_POST['heading_text']) : '関連記事';
        $options['heading_tag'] = isset($_POST['heading_tag']) ? sanitize_text_field($_POST['heading_tag']) : 'h2';

        update_option('kashiwazaki_seo_related_posts_options', $options);

        // デバッグモードの保存（別オプション）
        $debug_mode = isset($_POST['debug_mode']) ? 1 : 0;
        update_option('kashiwazaki_seo_related_posts_debug_mode', $debug_mode);



        echo '<div class="notice notice-success"><p>設定を保存しました。</p></div>';
    }

    public function handle_reset_all_settings() {
        global $wpdb;

        // システムデフォルトを取得
        $defaults = $this->get_system_defaults();

        // 完全にデフォルト値で上書き（APIキーも削除）
        update_option('kashiwazaki_seo_related_posts_options', $defaults);

        // 全ての投稿から関連記事のカスタムフィールドを削除
        $deleted_count = $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE '_kashiwazaki_seo_related_posts_%'"
        );

        echo '<div class="notice notice-success"><p>全ての設定を初期値にリセットしました。（プラグイン設定とすべての投稿のカスタム設定を削除: ' . intval($deleted_count) . '件）<br>APIキーの再設定が必要です。</p></div>';
    }

    // 共通設定を初期値にリセット
    public function handle_reset_common_settings() {
        $defaults = $this->get_system_defaults();

        // 現在のオプションを取得
        $current_options = get_option('kashiwazaki_seo_related_posts_options', array());

        // APIキー関連のみ保持
        $preserved = array();
        if (isset($current_options['openai_api_key'])) {
            $preserved['openai_api_key'] = $current_options['openai_api_key'];
        }
        if (isset($current_options['openai_model'])) {
            $preserved['openai_model'] = $current_options['openai_model'];
        }

        // 投稿タイプ別設定を保持
        foreach ($current_options as $key => $value) {
            if (strpos($key, 'post_type_settings_') === 0) {
                $preserved[$key] = $value;
            }
        }

        // デフォルト値と保持する値をマージ（デフォルトが優先）
        $new_options = array_merge($preserved, $defaults);

        // 確実にtarget_post_typesとmetabox_post_typesをリセット
        $new_options['target_post_types'] = array('post');
        $new_options['metabox_post_types'] = array('post');

        // オプションを更新
        update_option('kashiwazaki_seo_related_posts_options', $new_options);

        // 成功メッセージを直接表示
        echo '<div class="notice notice-success is-dismissible"><p>共通設定を初期値にリセットしました。APIキーと投稿タイプ別設定は保持されています。</p></div>';
    }

    // 全キャッシュをクリア
    public function handle_clear_all_cache() {
        global $wpdb;

        // 全ての投稿から関連記事キャッシュを削除
        $deleted_count = $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_kashiwazaki_seo_related_posts_cached_results', '_kashiwazaki_seo_related_posts_cached_timestamp', '_kashiwazaki_seo_related_posts_used_model')"
        );

        echo '<div class="notice notice-success is-dismissible"><p>全ての関連記事キャッシュをクリアしました。（' . intval($deleted_count / 3) . '件の投稿）</p></div>';
    }

    // 投稿タイプ別設定を共通設定の値にリセット
    public function handle_reset_post_type_settings() {
        $post_type = sanitize_text_field($_POST['post_type']);

        // 投稿タイプの検証
        if (!post_type_exists($post_type)) {
            echo '<div class="notice notice-error"><p>無効な投稿タイプです。</p></div>';
            return;
        }

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $pt_settings_key = 'post_type_settings_' . $post_type;

        // 投稿タイプ別設定を削除（共通設定を使うようにする）
        if (isset($options[$pt_settings_key])) {
            unset($options[$pt_settings_key]);
            update_option('kashiwazaki_seo_related_posts_options', $options);
        }

        echo '<div class="notice notice-success"><p>この投稿タイプの設定を共通設定の値にリセットしました。</p></div>';
    }

    // 投稿タイプ別キャッシュをクリア
    public function handle_clear_post_type_cache() {
        global $wpdb;

        $post_type = sanitize_text_field($_POST['post_type']);

        // 投稿タイプの検証
        if (!post_type_exists($post_type)) {
            echo '<div class="notice notice-error"><p>無効な投稿タイプです。</p></div>';
            return;
        }

        // この投稿タイプの投稿からキャッシュを削除
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            $post_type
        ));

        $deleted_count = 0;
        foreach ($post_ids as $post_id) {
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_used_model');
            $deleted_count++;
        }

        $post_type_obj = get_post_type_object($post_type);
        $post_type_label = $post_type_obj ? $post_type_obj->label : $post_type;

        echo '<div class="notice notice-success is-dismissible"><p>「' . esc_html($post_type_label) . '」の関連記事キャッシュをクリアしました。（' . $deleted_count . '件）</p></div>';
    }

    public function post_type_settings_page() {
        // 保存処理
        if (isset($_POST['save_post_type_settings']) && isset($_POST['post_type'])) {
            $this->handle_save_post_type_settings();
        } elseif (isset($_POST['reset_post_type_settings']) && isset($_POST['post_type'])) {
            $this->handle_reset_post_type_settings();
        } elseif (isset($_POST['clear_post_type_cache']) && isset($_POST['post_type'])) {
            $this->handle_clear_post_type_cache();
        }

        // 投稿タイプの取得
        $post_type_name = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
        if (empty($post_type_name)) {
            echo '<div class="wrap"><h1>エラー</h1><p>投稿タイプが指定されていません。</p></div>';
            return;
        }

        $post_type_obj = get_post_type_object($post_type_name);
        if (!$post_type_obj) {
            echo '<div class="wrap"><h1>エラー</h1><p>無効な投稿タイプです。</p></div>';
            return;
        }

        // オプション取得
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $pt_settings_key = 'post_type_settings_' . $post_type_name;
        $pt_settings = isset($options[$pt_settings_key]) ? $options[$pt_settings_key] : array();

        // 設定モード（共通設定を使うか個別設定を使うか）
        $use_custom_settings = isset($pt_settings['use_custom_settings']) ? $pt_settings['use_custom_settings'] : false;

        // システムデフォルト値を取得
        $system_defaults = $this->get_system_defaults();

        // デフォルト値（基本設定から取得、なければシステムデフォルト）
        $default_max_posts = isset($options['max_posts']) ? $options['max_posts'] : $system_defaults['max_posts'];
        $default_ai_candidate_buffer = isset($options['ai_candidate_buffer']) ? $options['ai_candidate_buffer'] : $system_defaults['ai_candidate_buffer'];
        $default_cache_lifetime = isset($options['cache_lifetime']) ? $options['cache_lifetime'] : $system_defaults['cache_lifetime'];
        $default_display_method = isset($options['display_method']) ? $options['display_method'] : $system_defaults['display_method'];
        $default_color_theme = isset($options['color_theme']) ? $options['color_theme'] : $system_defaults['color_theme'];
        $default_heading_text = isset($options['heading_text']) ? $options['heading_text'] : $system_defaults['heading_text'];
        $default_heading_tag = isset($options['heading_tag']) ? $options['heading_tag'] : $system_defaults['heading_tag'];
        $default_insert_position = isset($options['insert_position']) ? $options['insert_position'] : $system_defaults['insert_position'];
        $default_slider_items_desktop = isset($options['slider_items_desktop']) ? $options['slider_items_desktop'] : $system_defaults['slider_items_desktop'];
        $default_slider_items_tablet = isset($options['slider_items_tablet']) ? $options['slider_items_tablet'] : $system_defaults['slider_items_tablet'];
        $default_slider_items_mobile = isset($options['slider_items_mobile']) ? $options['slider_items_mobile'] : $system_defaults['slider_items_mobile'];

        // デフォルト対象投稿タイプ（基本設定から取得、なければシステムデフォルト）
        $default_target_post_types = isset($options['target_post_types']) ? $options['target_post_types'] : $system_defaults['target_post_types'];

        // 投稿タイプ別設定値（設定されていればそれを使用、なければデフォルト）
        $target_post_types = isset($pt_settings['target_post_types']) ? $pt_settings['target_post_types'] : $default_target_post_types;
        $max_posts = isset($pt_settings['max_posts']) ? $pt_settings['max_posts'] : $default_max_posts;
        $ai_candidate_buffer = isset($pt_settings['ai_candidate_buffer']) ? $pt_settings['ai_candidate_buffer'] : $default_ai_candidate_buffer;
        $cache_lifetime = isset($pt_settings['cache_lifetime']) ? $pt_settings['cache_lifetime'] : $default_cache_lifetime;
        $display_method = isset($pt_settings['display_method']) ? $pt_settings['display_method'] : $default_display_method;
        $color_theme = isset($pt_settings['color_theme']) ? $pt_settings['color_theme'] : $default_color_theme;
        $heading_text = isset($pt_settings['heading_text']) ? $pt_settings['heading_text'] : $default_heading_text;
        $heading_tag = isset($pt_settings['heading_tag']) ? $pt_settings['heading_tag'] : $default_heading_tag;
        $insert_position = isset($pt_settings['insert_position']) ? $pt_settings['insert_position'] : $default_insert_position;
        $slider_items_desktop = isset($pt_settings['slider_items_desktop']) ? $pt_settings['slider_items_desktop'] : $default_slider_items_desktop;
        $slider_items_tablet = isset($pt_settings['slider_items_tablet']) ? $pt_settings['slider_items_tablet'] : $default_slider_items_tablet;
        $slider_items_mobile = isset($pt_settings['slider_items_mobile']) ? $pt_settings['slider_items_mobile'] : $default_slider_items_mobile;

        // 全投稿タイプを取得
        $all_post_types = get_post_types(array('public' => true), 'objects');

        ?>
        <style>
        .ksrp-button-group {
            display: flex !important;
            gap: 10px;
            align-items: center;
            margin-top: 20px;
        }
        </style>
        <div class="wrap">
            <h1>投稿タイプ別詳細設定: <?php echo esc_html($post_type_obj->label); ?> (<?php echo esc_html($post_type_name); ?>)</h1>
            <p>
                <a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=post_types'); ?>">&larr; 投稿タイプ別設定一覧に戻る</a>
            </p>

            <div style="background: #e7f4f9; border: 2px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 8px;">
                <h3 style="margin-top: 0;">この画面について</h3>
                <p>この投稿タイプ「<?php echo esc_html($post_type_obj->label); ?>」専用の設定を行います。</p>
                <p>「共通設定を使用」を選択すると共通設定タブで設定した値が適用され、「この投稿タイプ専用の設定を使用」を選択すると下記のカスタム設定が適用されます。</p>
            </div>

            <form method="post">
                <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type_name); ?>" />
                <input type="hidden" name="save_post_type_settings" value="1" />

                <table class="form-table">
                    <tr>
                        <th scope="row">⚙️ 設定モード</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="use_custom_settings" value="0" <?php checked(!$use_custom_settings); ?> id="use_common_settings" />
                                    共通設定を使用
                                </label>
                                <br/>
                                <label>
                                    <input type="radio" name="use_custom_settings" value="1" <?php checked($use_custom_settings); ?> id="use_custom_settings" />
                                    この投稿タイプ専用の設定を使用
                                </label>
                            </fieldset>
                            <p class="description">共通設定を使用する場合、基本設定タブで設定した値が適用されます。</p>
                        </td>
                    </tr>
                </table>

                <!-- 共通設定使用時の表示 -->
                <div id="common_settings_info" style="<?php echo !$use_custom_settings ? '' : 'display: none;'; ?>">
                    <div style="background: #f0f8ff; border: 1px solid #007cba; padding: 15px; margin: 20px 0; border-radius: 5px;">
                        <h3 style="margin-top: 0;">📊 現在適用される共通設定の値</h3>
                        <ul style="margin: 10px 0;">
                            <li><strong>最大表示記事数:</strong> <?php echo $default_max_posts; ?>件</li>
                            <li><strong>AI分析用候補数追加:</strong> <?php echo $default_ai_candidate_buffer; ?>件</li>
                            <li><strong>キャッシュ有効期限:</strong> <?php echo $default_cache_lifetime; ?>時間</li>
                            <li><strong>表示形式:</strong> <?php
                                $display_labels = array('list' => 'リスト', 'grid' => 'グリッド', 'slider' => 'スライダー');
                                echo isset($display_labels[$default_display_method]) ? $display_labels[$default_display_method] : $default_display_method;
                            ?></li>
                            <li><strong>カラーテーマ:</strong> <?php
                                $color_labels = array('blue' => 'ブルー', 'orange' => 'オレンジ', 'green' => 'グリーン', 'purple' => 'パープル', 'red' => 'レッド', 'white' => 'ホワイト');
                                echo isset($color_labels[$default_color_theme]) ? $color_labels[$default_color_theme] : $default_color_theme;
                            ?></li>
                            <li><strong>関連記事の検索範囲:</strong> <?php
                                $default_types_labels = array();
                                foreach ($default_target_post_types as $type_name) {
                                    if (isset($all_post_types[$type_name])) {
                                        $default_types_labels[] = $all_post_types[$type_name]->label;
                                    }
                                }
                                echo esc_html(implode(', ', $default_types_labels));
                            ?></li>
                        </ul>
                        <p>これらの値を変更するには、<a href="<?php echo admin_url('admin.php?page=kashiwazaki-seo-related-posts-settings&tab=common'); ?>">共通設定ページ</a>で編集してください。</p>
                    </div>
                </div>

                <!-- カスタム設定のフォーム -->
                <div id="custom_settings_section" style="<?php echo $use_custom_settings ? '' : 'display: none;'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">🔍 関連記事の検索範囲</th>
                        <td>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <h4 style="margin: 0;">📄 投稿タイプ</h4>
                                <div>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = true; });">全チェック</button>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = false; });">全解除</button>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;target_post_types[]&quot;]').forEach(function(cb){ cb.checked = cb.defaultChecked; });">リセット</button>
                                </div>
                            </div>
                            <fieldset>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                    <?php foreach ($all_post_types as $post_type):
                                        // 投稿タイプの記事数を取得
                                        $post_count = wp_count_posts($post_type->name);
                                        $published_count = isset($post_count->publish) ? $post_count->publish : 0;
                                    ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox"
                                                   name="target_post_types[]"
                                                   value="<?php echo esc_attr($post_type->name); ?>"
                                                   <?php checked(in_array($post_type->name, $target_post_types)); ?> />
                                            <?php echo esc_html($post_type->label); ?>
                                        <small>(<?php echo esc_html($post_type->name); ?>)</small>
                                        <strong style="color: #555;">(<?php echo number_format($published_count); ?>記事)</strong>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <?php
                            // カテゴリフィルター
                            $filter_categories = isset($pt_settings['filter_categories']) ? $pt_settings['filter_categories'] : array();
                            $default_filter_categories = isset($options['filter_categories']) ? $options['filter_categories'] : array();
                            if (!$use_custom_settings) {
                                $filter_categories = $default_filter_categories;
                            }
                            $categories = get_categories(array(
                                'orderby' => 'name',
                                'order' => 'ASC',
                                'hide_empty' => false
                            ));
                            ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; margin-top: 20px;">
                                <h4 style="margin: 0;">📁 カテゴリ</h4>
                                <div>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = true; });">全チェック</button>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = false; });">全解除</button>
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('#custom_settings_section input[name=&quot;filter_categories[]&quot;]').forEach(function(cb){ cb.checked = cb.defaultChecked; });">リセット</button>
                                </div>
                            </div>
                            <fieldset>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                    <?php foreach ($categories as $category):
                                        $post_count = $category->count;
                                    ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox"
                                                   name="filter_categories[]"
                                                   value="<?php echo esc_attr($category->term_id); ?>"
                                                   <?php checked(in_array($category->term_id, $filter_categories)); ?> />
                                            <?php echo esc_html($category->name); ?>
                                            <strong style="color: #555;">(<?php echo number_format($post_count); ?>記事)</strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <p class="description" style="margin-top: 15px;">
                                関連記事の検索範囲を設定します。<br>
                                <strong>投稿タイプ:</strong> チェックを入れた投稿タイプから関連記事が検索されます。<br>
                                <strong>カテゴリ:</strong> 特定のカテゴリに絞り込む場合はチェックを入れてください。チェックなしの場合はすべてのカテゴリから検索されます。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>📊 最大表示記事数</label></th>
                        <td>
                            <input type="number"
                                   name="max_posts"
                                   value="<?php echo esc_attr($max_posts); ?>"
                                   min="1"
                                   max="20"
                                   style="width: 80px;" />
                            <p class="description">
                                この投稿タイプで表示される最大記事数です。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>🤖 AI分析用候補数追加</label></th>
                        <td>
                            <input type="number"
                                   name="ai_candidate_buffer"
                                   value="<?php echo esc_attr($ai_candidate_buffer); ?>"
                                   min="5"
                                   max="100"
                                   style="width: 80px;" />
                            件
                            <p class="description">
                                AIで分析する候補記事数を増やします。例：最大表示5件の場合、5+この値（20）=25件の候補からAIが最適な5件を選びます。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>💾 キャッシュ有効期限</label></th>
                        <td>
                            <input type="number"
                                   name="cache_lifetime"
                                   value="<?php echo esc_attr($cache_lifetime); ?>"
                                   min="1"
                                   max="8760"
                                   style="width: 80px;" />
                            時間
                            <p class="description">
                                関連記事キャッシュの有効期限です。最大8760時間（365日）まで設定可能です。
                            </p>

                            <div style="margin-top: 15px;">
                                <a href="#" id="toggle-pt-cache-info" style="text-decoration: none;">▶ 現在のキャッシュ情報</a>
                                <div id="pt-cache-info-content" style="display: none; margin-top: 10px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                                    <?php
                                    global $wpdb;

                                    // この投稿タイプのキャッシュ情報を取得
                                    $cache_count = $wpdb->get_var($wpdb->prepare(
                                        "SELECT COUNT(DISTINCT pm.post_id)
                                         FROM {$wpdb->postmeta} pm
                                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                                         WHERE pm.meta_key = '_kashiwazaki_seo_related_posts_cached_results'
                                         AND p.post_type = %s",
                                        $post_type_name
                                    ));

                                    echo '<p><strong>' . esc_html($post_type_obj->label) . '</strong> のキャッシュ数: <strong>' . number_format($cache_count) . '</strong> 件</p>';
                                    ?>
                                </div>
                            </div>

                            <div style="margin-top: 10px;">
                                <button type="button" id="clear-post-type-cache-btn" class="button button-secondary"
                                        data-post-type="<?php echo esc_attr($post_type_name); ?>">この投稿タイプのキャッシュをクリア</button>
                            </div>

                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var toggle = document.getElementById('toggle-pt-cache-info');
                                var content = document.getElementById('pt-cache-info-content');
                                if (toggle && content) {
                                    toggle.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        if (content.style.display === 'none') {
                                            content.style.display = 'block';
                                            toggle.innerHTML = '▼ 現在のキャッシュ情報';
                                        } else {
                                            content.style.display = 'none';
                                            toggle.innerHTML = '▶ 現在のキャッシュ情報';
                                        }
                                    });
                                }

                                // 投稿タイプ別キャッシュクリアボタンの処理
                                var clearPtBtn = document.getElementById('clear-post-type-cache-btn');
                                if (clearPtBtn) {
                                    clearPtBtn.addEventListener('click', function(e) {
                                        if (!confirm('この投稿タイプの全ての関連記事キャッシュをクリアしますか？')) {
                                            return;
                                        }
                                        // 隠しフォームを作成して送信
                                        var form = document.createElement('form');
                                        form.method = 'post';
                                        form.style.display = 'none';

                                        var inputCache = document.createElement('input');
                                        inputCache.type = 'hidden';
                                        inputCache.name = 'clear_post_type_cache';
                                        inputCache.value = '1';

                                        var inputPostType = document.createElement('input');
                                        inputPostType.type = 'hidden';
                                        inputPostType.name = 'post_type';
                                        inputPostType.value = e.target.getAttribute('data-post-type');

                                        form.appendChild(inputCache);
                                        form.appendChild(inputPostType);
                                        document.body.appendChild(form);
                                        form.submit();
                                    });
                                }
                            });
                            </script>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🎨 デフォルト表示形式</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>デフォルト表示形式</span></legend>
                                <label><input type="radio" name="display_method" value="list" <?php checked($display_method, 'list'); ?> /> リスト</label><br/>
                                <label><input type="radio" name="display_method" value="grid" <?php checked($display_method, 'grid'); ?> /> グリッド</label><br/>
                                <label><input type="radio" name="display_method" value="slider" <?php checked($display_method, 'slider'); ?> /> スライダー</label>
                            </fieldset>
                            <p class="description">
                                この投稿タイプで使用される表示形式です。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🎨 カラーテーマ</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>カラーテーマ</span></legend>
                                <label><input type="radio" name="color_theme" value="blue" <?php checked($color_theme, 'blue'); ?> /> <span style="color: #007cba;">■</span> ブルー</label><br/>
                                <label><input type="radio" name="color_theme" value="orange" <?php checked($color_theme, 'orange'); ?> /> <span style="color: #ff7f50;">■</span> オレンジ</label><br/>
                                <label><input type="radio" name="color_theme" value="green" <?php checked($color_theme, 'green'); ?> /> <span style="color: #27ae60;">■</span> グリーン</label><br/>
                                <label><input type="radio" name="color_theme" value="purple" <?php checked($color_theme, 'purple'); ?> /> <span style="color: #8e44ad;">■</span> パープル</label><br/>
                                <label><input type="radio" name="color_theme" value="red" <?php checked($color_theme, 'red'); ?> /> <span style="color: #e74c3c;">■</span> レッド</label><br/>
                                <label><input type="radio" name="color_theme" value="white" <?php checked($color_theme, 'white'); ?> /> <span style="color: #666; border: 1px solid #ccc; background: #fff; padding: 1px 3px;">■</span> ホワイト</label>
                            </fieldset>
                            <p class="description">
                                関連記事のアクセントカラーを選択してください。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>📝 見出しテキスト</label></th>
                        <td>
                            <input type="text"
                                   name="heading_text"
                                   value="<?php echo esc_attr($heading_text); ?>"
                                   class="regular-text" />
                            <p class="description">
                                関連記事セクションの見出しテキストです。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="heading_tag">🏷️ 見出しタグ</label></th>
                        <td>
                            <select id="heading_tag" name="heading_tag">
                                <option value="h2" <?php selected($heading_tag, 'h2'); ?>>H2</option>
                                <option value="h3" <?php selected($heading_tag, 'h3'); ?>>H3</option>
                                <option value="h4" <?php selected($heading_tag, 'h4'); ?>>H4</option>
                                <option value="div" <?php selected($heading_tag, 'div'); ?>>DIV</option>
                            </select>
                            <p class="description">
                                見出しに使用するHTMLタグです。<br>
                                <strong>共通設定のデフォルト値:</strong> <?php echo strtoupper($default_heading_tag); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="insert_position">📍 挿入位置</label></th>
                        <td>
                            <select id="insert_position" name="insert_position">
                                <option value="before_content" <?php selected($insert_position, 'before_content'); ?>>記事の前</option>
                                <option value="after_content" <?php selected($insert_position, 'after_content'); ?>>記事の後</option>
                                <option value="after_first_paragraph" <?php selected($insert_position, 'after_first_paragraph'); ?>>最初の段落の後</option>
                            </select>
                            <p class="description">
                                関連記事を自動挿入する位置です。<br>
                                <strong>共通設定のデフォルト値:</strong>
                                <?php
                                $position_labels = array(
                                    'before_content' => '記事の前',
                                    'after_content' => '記事の後',
                                    'after_first_paragraph' => '最初の段落の後'
                                );
                                echo isset($position_labels[$default_insert_position]) ? $position_labels[$default_insert_position] : $default_insert_position;
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="slider_settings" style="<?php echo $display_method !== 'slider' ? 'display: none;' : ''; ?>">
                        <th scope="row">📱 スライダー表示数</th>
                        <td>
                            <label>デスクトップ:
                                <input type="number" name="slider_items_desktop" value="<?php echo esc_attr($slider_items_desktop); ?>" min="1" max="6" style="width: 60px;" />
                            </label><br/>
                            <label>タブレット:
                                <input type="number" name="slider_items_tablet" value="<?php echo esc_attr($slider_items_tablet); ?>" min="1" max="4" style="width: 60px;" />
                            </label><br/>
                            <label>モバイル:
                                <input type="number" name="slider_items_mobile" value="<?php echo esc_attr($slider_items_mobile); ?>" min="1" max="2" style="width: 60px;" />
                            </label>
                            <p class="description">
                                各デバイスで同時に表示するスライダーアイテム数です。<br>
                                <strong>共通設定のデフォルト値:</strong> デスクトップ: <?php echo $default_slider_items_desktop; ?>, タブレット: <?php echo $default_slider_items_tablet; ?>, モバイル: <?php echo $default_slider_items_mobile; ?>
                            </p>
                        </td>
                    </tr>

                </table>
                </div><!-- #custom_settings_section -->

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <input type="submit" name="save_post_type_settings" class="button button-primary" value="設定を保存" />
            </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type_name); ?>" />
                        <input type="hidden" name="reset_post_type_settings" value="1" />
                        <input type="submit" class="button button-secondary" value="共通設定の値にリセット" onclick="return confirm('この投稿タイプの設定を共通設定の値にリセットしますか？');" />
                    </form>
                </div>

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // 設定モードの切り替え
                $('input[name="use_custom_settings"]').on('change', function() {
                    if ($(this).val() === '1') {
                        $('#custom_settings_section').slideDown();
                        $('#common_settings_info').slideUp();
                    } else {
                        $('#custom_settings_section').slideUp();
                        $('#common_settings_info').slideDown();
                    }
                });

                // 表示形式の切り替えでスライダー設定を表示/非表示
                $('input[name="display_method"]').on('change', function() {
                    if ($(this).val() === 'slider') {
                        $('#slider_settings').show();
                    } else {
                        $('#slider_settings').hide();
                    }
                });
            });
            </script>
        </div>
        <?php
    }


    public function handle_save_post_type_settings() {
        $post_type = sanitize_text_field($_POST['post_type']);

        // 投稿タイプの検証
        if (!post_type_exists($post_type)) {
            echo '<div class="notice notice-error"><p>無効な投稿タイプです。</p></div>';
            return;
        }

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $pt_settings_key = 'post_type_settings_' . $post_type;

        // 設定モードを保存
        $use_custom_settings = isset($_POST['use_custom_settings']) && $_POST['use_custom_settings'] === '1';

        // 投稿タイプ別設定を保存
        $pt_settings = array(
            'use_custom_settings' => $use_custom_settings,
            'target_post_types' => isset($_POST['target_post_types']) ? array_map('sanitize_text_field', $_POST['target_post_types']) : array(),
            'filter_categories' => isset($_POST['filter_categories']) ? array_map('intval', $_POST['filter_categories']) : array(),
            'max_posts' => isset($_POST['max_posts']) ? absint($_POST['max_posts']) : 5,
            'ai_candidate_buffer' => isset($_POST['ai_candidate_buffer']) ? absint($_POST['ai_candidate_buffer']) : 20,
            'cache_lifetime' => isset($_POST['cache_lifetime']) ? max(1, min(8760, absint($_POST['cache_lifetime']))) : 24,
            'display_method' => isset($_POST['display_method']) ? sanitize_text_field($_POST['display_method']) : 'list',
            'color_theme' => isset($_POST['color_theme']) ? sanitize_text_field($_POST['color_theme']) : 'blue',
            'heading_text' => isset($_POST['heading_text']) ? sanitize_text_field($_POST['heading_text']) : '関連記事',
            'heading_tag' => isset($_POST['heading_tag']) ? sanitize_text_field($_POST['heading_tag']) : 'h2',
            'insert_position' => isset($_POST['insert_position']) ? sanitize_text_field($_POST['insert_position']) : 'after_content',
            'slider_items_desktop' => isset($_POST['slider_items_desktop']) ? absint($_POST['slider_items_desktop']) : 3,
            'slider_items_tablet' => isset($_POST['slider_items_tablet']) ? absint($_POST['slider_items_tablet']) : 2,
            'slider_items_mobile' => isset($_POST['slider_items_mobile']) ? absint($_POST['slider_items_mobile']) : 1,
        );

        $options[$pt_settings_key] = $pt_settings;
        update_option('kashiwazaki_seo_related_posts_options', $options);

        echo '<div class="notice notice-success"><p>投稿タイプ別設定を保存しました。</p></div>';
    }

    private function get_available_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $available_types = array();

        foreach ($post_types as $post_type) {
            if ($post_type->name === 'attachment') {
                $available_types[$post_type->name] = $post_type->label . ' (メディア)';
            } else {
                $available_types[$post_type->name] = $post_type->label;
            }
        }

        $custom_post_types = get_post_types(array('_builtin' => false), 'objects');
        foreach ($custom_post_types as $post_type) {
            if (!isset($available_types[$post_type->name])) {
                $available_types[$post_type->name] = $post_type->label . ' (カスタム投稿)';
            }
        }

        return $available_types;
    }

    public function handle_api_test() {
        $api_key = '';

        if (isset($_POST['api_key']) && !empty($_POST['api_key'])) {
            $api_key = sanitize_text_field($_POST['api_key']);
        } else {
            $options = get_option('kashiwazaki_seo_related_posts_options', array());
            $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        }

        if (empty($api_key)) {
            echo '<div class="notice notice-error"><p>APIキーが入力されていません。</p></div>';
            return;
        }

        $result = $this->api->test_api_key($api_key);

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
            echo '<div style="background: #f0f8ff; border: 1px solid #0073aa; padding: 10px; margin: 10px 0;"><h4>通信ログ:</h4><pre>' . esc_html($result['log']) . '</pre></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            echo '<div style="background: #ffe; border: 1px solid #dc3232; padding: 10px; margin: 10px 0;"><h4>通信ログ:</h4><pre>' . esc_html($result['log']) . '</pre></div>';
        }
    }

    /**
     * メタボックスの追加
     */
        public function add_metabox() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $metabox_post_types = isset($options['metabox_post_types']) ? $options['metabox_post_types'] : array('post');

        foreach ($metabox_post_types as $post_type) {
            add_meta_box(
                'kashiwazaki_seo_related_posts',
                '🔗 Kashiwazaki SEO Related Posts',
                array($this, 'render_metabox'),
                $post_type,
                'normal',
                'high'
            );
        }
    }

        /**
     * メタボックスのレンダリング
     */
    public function render_metabox($post) {
        wp_nonce_field('kashiwazaki_seo_related_posts_nonce', 'kashiwazaki_seo_related_posts_nonce');

        // デフォルト値とAPIキー設定を確認
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $api_key_set = !empty($options['api_key']);

        // 投稿タイプ別設定を取得
        $post_type = get_post_type($post->ID);
        $pt_settings_key = 'post_type_settings_' . $post_type;
        $pt_settings = isset($options[$pt_settings_key]) ? $options[$pt_settings_key] : array();
        $use_custom_settings = isset($pt_settings['use_custom_settings']) && $pt_settings['use_custom_settings'];

        // デフォルト値を設定（カスタム設定を使用している場合のみ投稿タイプ別設定を優先）
        $default_search_methods = isset($options['search_methods']) ? $options['search_methods'] : array('tags', 'categories');
        $default_max_posts = ($use_custom_settings && isset($pt_settings['max_posts'])) ? $pt_settings['max_posts'] : (isset($options['max_posts']) ? $options['max_posts'] : 5);
        $default_display_method = ($use_custom_settings && isset($pt_settings['display_method'])) ? $pt_settings['display_method'] : (isset($options['display_method']) ? $options['display_method'] : 'list');
        $default_insert_position = ($use_custom_settings && isset($pt_settings['insert_position'])) ? $pt_settings['insert_position'] : (isset($options['insert_position']) ? $options['insert_position'] : 'after_content');
        $default_target_post_types = ($use_custom_settings && isset($pt_settings['target_post_types'])) ? $pt_settings['target_post_types'] : (isset($options['target_post_types']) ? $options['target_post_types'] : array('post'));
        $default_filter_categories = ($use_custom_settings && isset($pt_settings['filter_categories'])) ? $pt_settings['filter_categories'] : (isset($options['filter_categories']) ? $options['filter_categories'] : array());
        $default_color_theme = ($use_custom_settings && isset($pt_settings['color_theme'])) ? $pt_settings['color_theme'] : (isset($options['color_theme']) ? $options['color_theme'] : 'blue');
        $default_heading_text = ($use_custom_settings && isset($pt_settings['heading_text'])) ? $pt_settings['heading_text'] : (isset($options['heading_text']) ? $options['heading_text'] : '関連記事');
        $default_heading_tag = ($use_custom_settings && isset($pt_settings['heading_tag'])) ? $pt_settings['heading_tag'] : (isset($options['heading_tag']) ? $options['heading_tag'] : 'h2');

        // カスタムフィールドの値を取得（空の場合はデフォルト値を使用）
        $enabled = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_enabled', true);

        $search_methods = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_search_methods', true);
        $search_methods = !empty($search_methods) ? $search_methods : $default_search_methods;

        $max_posts = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_max_posts', true);
        $max_posts = ($max_posts !== '' && $max_posts !== false) ? intval($max_posts) : $default_max_posts;

        $display_method = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_display_method', true);
        $display_method = !empty($display_method) ? $display_method : $default_display_method;

        $insert_position = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_insert_position', true);
        $insert_position = !empty($insert_position) ? $insert_position : $default_insert_position;

        $target_post_types = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_target_post_types', true);
        $target_post_types = !empty($target_post_types) ? $target_post_types : $default_target_post_types;

        $filter_categories = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_filter_categories', true);
        $filter_categories = ($filter_categories !== false && $filter_categories !== '') ? $filter_categories : $default_filter_categories;

        $color_theme = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_color_theme', true);
        $color_theme = !empty($color_theme) ? $color_theme : $default_color_theme;

        $heading_text = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_heading_text', true);
        $heading_text = ($heading_text !== '' && $heading_text !== false) ? $heading_text : $default_heading_text;

        $heading_tag = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_heading_tag', true);
        $heading_tag = !empty($heading_tag) ? $heading_tag : $default_heading_tag;

        $all_post_types = get_post_types(array('public' => true), 'objects');


        ?>

        <div id="kashiwazaki-related-posts-settings" style="border: 2px solid #0073aa; padding: 20px; background: #fff; border-radius: 6px;">

            <!-- メイン有効化スイッチ -->
            <div style="background: #f0f8ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 10px; font-size: 14px;">
                    <input type="checkbox"
                           id="kashiwazaki_seo_related_posts_enabled"
                           name="kashiwazaki_seo_related_posts_enabled"
                           value="1"
                           <?php checked($enabled, '1'); ?>
                           style="width: 20px; height: 20px;" />
                    <strong style="font-size: 15px;">この記事で関連記事を表示する</strong>
                </label>
            </div>

            <!-- 設定エリア -->
            <div id="kashiwazaki-related-posts-detailed-settings" style="<?php echo $enabled ? '' : 'display: none;'; ?>">

                <!-- 現在の設定状況 -->
                <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">現在の設定状況</h4>
                    <ul style="margin: 5px 0; list-style: none; padding: 0;">
                        <li><strong>最大表示:</strong> <?php echo $max_posts; ?>件</li>
                        <li><strong>表示形式:</strong> <?php
                            $display_labels = array('list' => 'リスト', 'grid' => 'グリッド', 'slider' => 'スライダー');
                            echo isset($display_labels[$display_method]) ? $display_labels[$display_method] : $display_method;
                        ?></li>
                        <li><strong>挿入位置:</strong> <?php
                            $position_labels = array('before_content' => '記事の前', 'after_content' => '記事の後', 'after_first_paragraph' => '最初の段落の後');
                            echo isset($position_labels[$insert_position]) ? $position_labels[$insert_position] : $insert_position;
                        ?></li>
                        <li><strong>カラーテーマ:</strong> <?php
                            $color_labels = array('blue' => 'ブルー', 'orange' => 'オレンジ', 'green' => 'グリーン', 'purple' => 'パープル', 'red' => 'レッド', 'white' => 'ホワイト');
                            echo isset($color_labels[$color_theme]) ? $color_labels[$color_theme] : $color_theme;
                        ?></li>
                        <li><strong>見出しテキスト:</strong> <?php echo esc_html($heading_text); ?></li>
                        <li><strong>見出しタグ:</strong> <?php echo strtoupper($heading_tag); ?></li>
                        <li><strong>検索対象カテゴリ:</strong> <?php
                            if (empty($filter_categories)) {
                                echo 'すべてのカテゴリ';
                            } else {
                                $cat_names = array();
                                foreach ($filter_categories as $cat_id) {
                                    $cat = get_category($cat_id);
                                    if ($cat) {
                                        $cat_names[] = $cat->name;
                                    }
                                }
                                echo !empty($cat_names) ? implode(', ', $cat_names) : 'すべてのカテゴリ';
                            }
                        ?></li>
                        <li><strong>検索対象投稿タイプ:</strong> <?php
                            $type_labels = array();
                            foreach ($target_post_types as $type_name) {
                                $type_obj = get_post_type_object($type_name);
                                if ($type_obj) {
                                    $type_labels[] = $type_obj->label;
                                }
                            }
                            echo implode(', ', $type_labels);
                        ?></li>
                    </ul>
                    <button type="button" id="toggle-detailed-settings" class="button button-secondary" style="margin-top: 10px;">詳細設定を開く</button>
                </div>

                <!-- 詳細設定フォーム -->
                <div id="detailed-settings-content" style="display: none; background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">
                    <h4 style="margin: 0; font-size: 14px;">詳細設定</h4>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" id="kashiwazaki-reset-to-defaults" class="button button-small">
                            この記事を現在のデフォルト値に戻す
                        </button>
                        <button type="button" id="kashiwazaki-reset-all-posts" class="button button-small" style="background: #d63638; color: white; border-color: #d63638;">
                            「<?php echo esc_html(get_post_type_object($post->post_type)->label); ?>」をすべて現在のデフォルト値に戻す
                        </button>
                    </div>
                </div>

                <table class="form-table">

                    <tr>
                        <th scope="row"><label for="kashiwazaki_seo_related_posts_max_posts">最大表示記事数</label></th>
                        <td>
                            <input type="number"
                                   id="kashiwazaki_seo_related_posts_max_posts"
                                   name="kashiwazaki_seo_related_posts_max_posts"
                                   value="<?php echo esc_attr($max_posts); ?>"
                                   min="1"
                                   max="20"
                                   style="width: 80px;" />
                            <p class="description">
                                表示する関連記事の最大数（1〜20）<br>
                                <strong>デフォルト値:</strong> <?php echo $default_max_posts; ?>件
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">表示形式</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>表示形式</span></legend>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_display_method" value="list" <?php checked($display_method, 'list'); ?> /> リスト</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_display_method" value="grid" <?php checked($display_method, 'grid'); ?> /> グリッド</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_display_method" value="slider" <?php checked($display_method, 'slider'); ?> /> スライダー</label>
                            </fieldset>
                            <p class="description">
                                関連記事の表示レイアウトを選択してください。<br>
                                <strong>デフォルト値:</strong> <?php echo $default_display_method; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="kashiwazaki_seo_related_posts_insert_position">挿入位置</label></th>
                        <td>
                            <select id="kashiwazaki_seo_related_posts_insert_position" name="kashiwazaki_seo_related_posts_insert_position">
                                <option value="before_content" <?php selected($insert_position, 'before_content'); ?>>記事の前</option>
                                <option value="after_content" <?php selected($insert_position, 'after_content'); ?>>記事の後</option>
                                <option value="after_first_paragraph" <?php selected($insert_position, 'after_first_paragraph'); ?>>最初の段落の後</option>
                            </select>
                            <p class="description">
                                関連記事を挿入する位置を選択してください。<br>
                                <strong>デフォルト値:</strong>
                                <?php
                                $position_labels = array(
                                    'before_content' => '記事の前',
                                    'after_content' => '記事の後',
                                    'after_first_paragraph' => '最初の段落の後'
                                );
                                echo isset($position_labels[$default_insert_position]) ? $position_labels[$default_insert_position] : $default_insert_position;
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">対象投稿タイプ</th>
                        <td>
                            <div style="margin-bottom: 10px;">
                                <button type="button" id="metabox-select-all-target-types" class="button button-small">全チェック</button>
                                <button type="button" id="metabox-deselect-all-target-types" class="button button-small">全解除</button>
                                <button type="button" id="metabox-reset-target-types" class="button button-small">リセット</button>
                            </div>
                            <fieldset>
                                <legend class="screen-reader-text"><span>対象投稿タイプ</span></legend>
                                <?php foreach ($all_post_types as $post_type): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="kashiwazaki_seo_related_posts_target_post_types[]"
                                               value="<?php echo esc_attr($post_type->name); ?>"
                                               id="metabox_target_post_type_<?php echo esc_attr($post_type->name); ?>"
                                               <?php checked(in_array($post_type->name, $target_post_types)); ?>
                                               <?php echo in_array($post_type->name, $default_target_post_types) ? 'data-default="true"' : ''; ?> />
                                        <?php echo esc_html($post_type->label); ?>
                                        <small>(<?php echo esc_html($post_type->name); ?>)</small>
                                    </label><br/>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description">
                                関連記事として検索対象とする投稿タイプを選択してください。<br>
                                <strong>デフォルト値:</strong>
                                <?php
                                $default_labels = array();
                                foreach ($all_post_types as $post_type) {
                                    if (in_array($post_type->name, $default_target_post_types)) {
                                        $default_labels[] = $post_type->label;
                                    }
                                }
                                echo implode(', ', $default_labels);
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">カテゴリフィルタ</th>
                        <td>
                            <?php
                            $categories = get_categories(array(
                                'orderby' => 'name',
                                'order' => 'ASC',
                                'hide_empty' => false
                            ));
                            ?>
                            <div style="margin-bottom: 10px;">
                                <button type="button" id="metabox-select-all-categories" class="button button-small">全チェック</button>
                                <button type="button" id="metabox-deselect-all-categories" class="button button-small">全解除</button>
                            </div>
                            <fieldset>
                                <legend class="screen-reader-text"><span>カテゴリフィルタ</span></legend>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                    <?php foreach ($categories as $category): ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox"
                                                   name="kashiwazaki_seo_related_posts_filter_categories[]"
                                                   value="<?php echo esc_attr($category->term_id); ?>"
                                                   <?php checked(in_array($category->term_id, $filter_categories)); ?> />
                                            <?php echo esc_html($category->name); ?>
                                            <strong style="color: #555;">(<?php echo number_format($category->count); ?>記事)</strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                            <p class="description">
                                特定のカテゴリに絞り込む場合はチェックを入れてください。チェックなしの場合はすべてのカテゴリから検索されます。<br>
                                <strong>デフォルト値:</strong>
                                <?php
                                if (empty($default_filter_categories)) {
                                    echo 'すべてのカテゴリ';
                                } else {
                                    $default_cat_names = array();
                                    foreach ($default_filter_categories as $cat_id) {
                                        $cat = get_category($cat_id);
                                        if ($cat) {
                                            $default_cat_names[] = $cat->name;
                                        }
                                    }
                                    echo implode(', ', $default_cat_names);
                                }
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">カラーテーマ</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>カラーテーマ</span></legend>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="blue" <?php checked($color_theme, 'blue'); ?> /> <span style="color: #007cba;">■</span> ブルー</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="orange" <?php checked($color_theme, 'orange'); ?> /> <span style="color: #ff7f50;">■</span> オレンジ</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="green" <?php checked($color_theme, 'green'); ?> /> <span style="color: #27ae60;">■</span> グリーン</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="purple" <?php checked($color_theme, 'purple'); ?> /> <span style="color: #8e44ad;">■</span> パープル</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="red" <?php checked($color_theme, 'red'); ?> /> <span style="color: #e74c3c;">■</span> レッド</label><br/>
                                <label><input type="radio" name="kashiwazaki_seo_related_posts_color_theme" value="white" <?php checked($color_theme, 'white'); ?> /> ホワイト</label>
                            </fieldset>
                            <p class="description">
                                関連記事の表示カラーテーマを選択してください。<br>
                                <strong>デフォルト値:</strong>
                                <?php
                                $color_labels = array('blue' => 'ブルー', 'orange' => 'オレンジ', 'green' => 'グリーン', 'purple' => 'パープル', 'red' => 'レッド', 'white' => 'ホワイト');
                                echo isset($color_labels[$default_color_theme]) ? $color_labels[$default_color_theme] : $default_color_theme;
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="kashiwazaki_seo_related_posts_heading_text">関連記事見出し</label></th>
                        <td>
                            <input type="text"
                                   id="kashiwazaki_seo_related_posts_heading_text"
                                   name="kashiwazaki_seo_related_posts_heading_text"
                                   value="<?php echo esc_attr($heading_text); ?>"
                                   class="regular-text" />
                            <p class="description">
                                関連記事セクションの見出しテキストを設定します。<br>
                                <strong>デフォルト値:</strong> <?php echo esc_html($default_heading_text); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="kashiwazaki_seo_related_posts_heading_tag">見出しタグ</label></th>
                        <td>
                            <select id="kashiwazaki_seo_related_posts_heading_tag" name="kashiwazaki_seo_related_posts_heading_tag">
                                <option value="h2" <?php selected($heading_tag, 'h2'); ?>>H2</option>
                                <option value="h3" <?php selected($heading_tag, 'h3'); ?>>H3</option>
                                <option value="h4" <?php selected($heading_tag, 'h4'); ?>>H4</option>
                                <option value="div" <?php selected($heading_tag, 'div'); ?>>DIV</option>
                            </select>
                            <p class="description">
                                見出しのHTMLタグを選択してください。<br>
                                <strong>デフォルト値:</strong> <?php echo strtoupper($default_heading_tag); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                </div><!-- #detailed-settings-content -->

                <!-- 関連記事の生成 -->
                <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-top: 20px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">関連記事の生成</h4>
                <div id="kashiwazaki-related-posts-preview">
                    <?php
                    // 保存された関連記事データを取得
                    $cached_results = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_cached_results', true);
                    $cached_timestamp = get_post_meta($post->ID, '_kashiwazaki_seo_related_posts_cached_timestamp', true);

                    // キャッシュの有効期限をチェック
                    $cache_valid = false;
                    $cache_status_message = '';
                    if ($cached_results && $cached_timestamp && is_array($cached_results)) {
                        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
                        $cache_lifetime_hours = isset($plugin_options['cache_lifetime']) ? $plugin_options['cache_lifetime'] : 24;
                        $cache_lifetime = $cache_lifetime_hours * 60 * 60;
                        $cache_age = time() - $cached_timestamp;

                        if ($cache_age < $cache_lifetime) {
                            $cache_valid = true;
                            $hours_ago = round($cache_age / 3600, 1);
                            $cache_status_message = " (生成から{$hours_ago}時間経過、有効期限{$cache_lifetime_hours}時間)";
                        } else {
                            $cached_results = null; // 期限切れキャッシュは表示しない
                            $cached_timestamp = null;
                            $cache_status_message = " ⚠️ キャッシュが期限切れです";
                        }
                    }
                    ?>
                    <div style="margin-bottom: 15px;">
                        <button type="button" id="kashiwazaki-fetch-related-posts" class="button button-primary">
                            関連記事を取得
                        </button>
                        <?php if ($cached_results): ?>
                        <button type="button" id="kashiwazaki-clear-cache" class="button button-secondary" style="margin-left: 10px;">
                            キャッシュクリア
                        </button>
                        <?php endif; ?>
                        <span id="kashiwazaki-fetch-status" style="margin-left: 10px; color: #666;"></span>
                    </div>

                    <?php if ($cached_timestamp):
                        $cached_model = get_post_meta(get_the_ID(), '_kashiwazaki_seo_related_posts_used_model', true);
                        $model_info = $cached_model ? ' | モデル: ' . esc_html($cached_model) : '';
                    ?>
                        <p style="color: #666; font-size: 12px; margin: 5px 0;">
                            📅 最終取得：<?php echo date('Y-m-d H:i:s', $cached_timestamp); ?><?php echo $model_info; ?><?php echo $cache_status_message; ?>
                        </p>
                    <?php elseif (!empty($cache_status_message)): ?>
                        <p style="color: #ff9800; font-size: 12px; margin: 5px 0;">
                            <?php echo $cache_status_message; ?>
                        </p>
                    <?php endif; ?>

                    <div id="kashiwazaki-related-posts-list" style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; min-height: 60px;">
                        <?php if ($cached_results && is_array($cached_results)): ?>
                            <?php if (empty($cached_results)): ?>
                                <p style="color: #666; margin: 0;">関連記事が見つかりませんでした。</p>
                            <?php else: ?>
                                <ol style="margin: 0; padding-left: 20px;">
                                    <?php foreach ($cached_results as $index => $related_post): ?>
                                        <?php
                                        // データの安全な取得
                                        $title = '';
                                        $post_type = '';
                                        $date = '';
                                        $score = '';
                                        $reason = '';

                                        if (is_array($related_post)) {
                                            $title = isset($related_post['title']) ? $related_post['title'] : '(タイトル不明)';
                                            $post_type = isset($related_post['post_type']) ? $related_post['post_type'] : '(投稿タイプ不明)';
                                            $date = isset($related_post['date']) ? $related_post['date'] : '(日付不明)';
                                            $score = isset($related_post['score']) ? $related_post['score'] : 'N/A';
                                            $reason = isset($related_post['reason']) ? $related_post['reason'] : '';

                                            // post_idまたはid（古い形式）がある場合、実際の投稿データから再取得
                                            $target_id = null;
                                            if (isset($related_post['post_id']) && $related_post['post_id']) {
                                                $target_id = $related_post['post_id'];
                                            } elseif (isset($related_post['id']) && $related_post['id']) {
                                                $target_id = $related_post['id']; // 古い形式との互換性
                                            }

                                            if ($target_id) {
                                                $post_obj = get_post($target_id);
                                                if ($post_obj) {
                                                    $title = $post_obj->post_title ?: $title;
                                                    $post_type = $post_obj->post_type ?: $post_type;
                                                    $date = date('Y-m-d', strtotime($post_obj->post_date));
                                                }
                                            }
                                        }
                                        ?>
                                        <li style="margin-bottom: 8px;">
                                            <strong><?php echo esc_html($title); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                投稿タイプ: <?php echo esc_html($post_type); ?> |
                                                日付: <?php echo esc_html($date); ?> |
                                                スコア: <?php echo esc_html($score); ?>
                                                <?php if (!empty($reason)): ?>
                                                    <br>理由: <?php echo esc_html($reason); ?>
                                                <?php endif; ?>
                                            </small>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color: #999; margin: 0; text-align: center;">
                                「関連記事を取得」ボタンをクリックして関連記事を表示してください。
                            </p>
                        <?php endif; ?>
                    </div>
                </div><!-- #kashiwazaki-related-posts-preview -->
                </div><!-- 関連記事の生成セクション終了 -->
            </div><!-- kashiwazaki-related-posts-detailed-settings -->
        </div><!-- kashiwazaki-related-posts-settings -->

        <style>
        #kashiwazaki-related-posts-settings .form-table th {
            width: 160px;
            padding-left: 0;
        }
        #kashiwazaki-related-posts-settings .form-table td {
            padding-left: 20px;
        }
        #kashiwazaki-related-posts-settings h4 {
            margin: 10px 0;
            color: #23282d;
        }

        /* カラーテーマCSS */
        <?php
        $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
        $color_theme = isset($plugin_options['color_theme']) ? $plugin_options['color_theme'] : 'blue';

        $theme_colors = array(
            'blue' => array('primary' => '#007cba', 'hover' => '#005a87'),
            'orange' => array('primary' => '#ff7f50', 'hover' => '#ff6347'),
            'green' => array('primary' => '#27ae60', 'hover' => '#219a52'),
            'purple' => array('primary' => '#8e44ad', 'hover' => '#7d3c98'),
            'red' => array('primary' => '#e74c3c', 'hover' => '#c0392b')
        );

        $current_theme = isset($theme_colors[$color_theme]) ? $theme_colors[$color_theme] : $theme_colors['blue'];
        ?>
        #kashiwazaki-related-posts-preview {
            --kashiwazaki-primary-color: <?php echo $current_theme['primary']; ?>;
            --kashiwazaki-hover-color: <?php echo $current_theme['hover']; ?>;
        }

        #kashiwazaki-related-posts-preview a:hover {
            color: var(--kashiwazaki-primary-color) !important;
        }

        #kashiwazaki-related-posts-preview .button:hover {
            background-color: var(--kashiwazaki-primary-color) !important;
            border-color: var(--kashiwazaki-primary-color) !important;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#kashiwazaki_seo_related_posts_enabled').on('change', function() {
                var isEnabled = $(this).is(':checked');
                $('#kashiwazaki-related-posts-detailed-settings').toggle(isEnabled);
            });

            // 詳細設定の開閉
            $('#toggle-detailed-settings').on('click', function() {
                var $content = $('#detailed-settings-content');
                var $button = $(this);
                if ($content.is(':visible')) {
                    $content.slideUp();
                    $button.text('詳細設定を開く');
                } else {
                    $content.slideDown();
                    $button.text('詳細設定を閉じる');
                }
            });

            // 対象投稿タイプのボタン
            $('#metabox-select-all-target-types').on('click', function() {
                $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').prop('checked', true);
            });
            $('#metabox-deselect-all-target-types').on('click', function() {
                $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').prop('checked', false);
            });
            $('#metabox-reset-target-types').on('click', function() {
                $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').each(function() {
                    // data-default属性がtrueの場合はチェック、そうでない場合はチェック解除
                    this.checked = $(this).data('default') === true;
                });
            });

            // カテゴリフィルタのボタン
            $('#metabox-select-all-categories').on('click', function() {
                $('input[name="kashiwazaki_seo_related_posts_filter_categories[]"]').prop('checked', true);
            });
            $('#metabox-deselect-all-categories').on('click', function() {
                $('input[name="kashiwazaki_seo_related_posts_filter_categories[]"]').prop('checked', false);
            });

            // この記事を現在のデフォルト値に戻すボタン
            $('#kashiwazaki-reset-to-defaults').on('click', function() {
                if (!confirm('この記事の関連記事設定を現在のデフォルト値に戻しますか？')) {
                    return;
                }

                // デフォルト値を設定
                var defaultMaxPosts = <?php echo json_encode($default_max_posts); ?>;
                var defaultDisplayMethod = <?php echo json_encode($default_display_method); ?>;
                var defaultInsertPosition = <?php echo json_encode($default_insert_position); ?>;
                var defaultTargetPostTypes = <?php echo json_encode($default_target_post_types); ?>;
                var defaultFilterCategories = <?php echo json_encode($default_filter_categories); ?>;
                var defaultColorTheme = <?php echo json_encode($default_color_theme); ?>;
                var defaultHeadingText = <?php echo json_encode($default_heading_text); ?>;
                var defaultHeadingTag = <?php echo json_encode($default_heading_tag); ?>;

                // 最大記事数をリセット
                $('#kashiwazaki_seo_related_posts_max_posts').val(defaultMaxPosts);

                // 表示形式をリセット（ラジオボタン）
                $('input[name="kashiwazaki_seo_related_posts_display_method"]').prop('checked', false);
                $('input[name="kashiwazaki_seo_related_posts_display_method"][value="' + defaultDisplayMethod + '"]').prop('checked', true);

                // 挿入位置をリセット
                $('#kashiwazaki_seo_related_posts_insert_position').val(defaultInsertPosition);

                // 対象投稿タイプをリセット
                $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').each(function() {
                    // data-default属性に基づいてチェック状態を設定
                    this.checked = $(this).data('default') === true;
                });

                // カテゴリフィルタをリセット
                $('input[name="kashiwazaki_seo_related_posts_filter_categories[]"]').prop('checked', false);
                if (defaultFilterCategories && defaultFilterCategories.length > 0) {
                    defaultFilterCategories.forEach(function(catId) {
                        $('input[name="kashiwazaki_seo_related_posts_filter_categories[]"][value="' + catId + '"]').prop('checked', true);
                    });
                }

                // カラーテーマをリセット
                $('input[name="kashiwazaki_seo_related_posts_color_theme"]').prop('checked', false);
                $('input[name="kashiwazaki_seo_related_posts_color_theme"][value="' + defaultColorTheme + '"]').prop('checked', true);

                // 見出しテキストをリセット
                $('#kashiwazaki_seo_related_posts_heading_text').val(defaultHeadingText);

                // 見出しタグをリセット
                $('#kashiwazaki_seo_related_posts_heading_tag').val(defaultHeadingTag);


                // 成功メッセージ
                var $notice = $('<div class="notice notice-success is-dismissible" style="margin: 10px 0;"><p><strong>現在のデフォルト値に戻しました！</strong><br>記事を保存してください。</p></div>');
                $('#kashiwazaki-related-posts-settings').prepend($notice);
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            });

            // 同じ投稿タイプをすべて現在のデフォルト値に戻すボタン
            $('#kashiwazaki-reset-all-posts').on('click', function() {
                var postType = '<?php echo esc_js($post->post_type); ?>';
                var postTypeLabel = '<?php echo esc_js(get_post_type_object($post->post_type)->label); ?>';

                if (!confirm('「' + postTypeLabel + '」のすべての記事の関連記事設定を現在のデフォルト値に戻しますか？\n\nこの操作は取り消せません。')) {
                    return;
                }

                var $button = $(this);
                var $originalText = $button.text();

                // ボタンを無効化して処理中表示
                $button.prop('disabled', true).text('処理中...');

                $.ajax({
                    url: kashiwazaki_related_posts_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'kashiwazaki_reset_all_posts_to_defaults',
                        post_type: postType,
                        nonce: kashiwazaki_related_posts_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var $notice = $('<div class="notice notice-success is-dismissible" style="margin: 10px 0;"><p><strong>成功！</strong><br>' + response.data.message + '</p></div>');
                            $('#kashiwazaki-related-posts-settings').prepend($notice);
                            setTimeout(function() {
                                $notice.fadeOut();
                            }, 5000);
                        } else {
                            alert('エラーが発生しました: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('通信エラーが発生しました。');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text($originalText);
                    }
                });
            });

            // 関連記事取得ボタンの処理
            $('#kashiwazaki-fetch-related-posts').on('click', function() {
                var $button = $(this);
                var $status = $('#kashiwazaki-fetch-status');
                var $list = $('#kashiwazaki-related-posts-list');
                var postId = $('#post_ID').val();

                if (!postId) {
                    alert('記事を保存してから関連記事を取得してください。');
                    return;
                }

                // 現在の設定値を取得
                var maxPosts = $('#kashiwazaki_seo_related_posts_max_posts').val();
                var targetPostTypes = [];
                $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]:checked').each(function() {
                    targetPostTypes.push($(this).val());
                });


                // UI更新
                $button.prop('disabled', true).text('取得中...');
                $status.text('関連記事を取得しています...');
                $list.html('<div style="text-align: center; padding: 20px; color: #666;"><span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>処理中...</div>');

                // 長時間処理の警告タイマー
                var warningTimer = setTimeout(function() {
                    $status.html('<span style="color: orange;">⏳ 処理に時間がかかっています...</span>');
                    $list.html('<div style="text-align: center; padding: 20px; color: #ff9800;"><span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>AI分析中です。もう少しお待ちください...<br><small>最大20秒でタイムアウトします</small></div>');
                }, 8000); // 8秒後に警告表示

                var startTime = new Date().getTime();
                $.ajax({
                    url: kashiwazaki_related_posts_ajax.ajax_url,
                    type: 'POST',
                    timeout: 20000, // 20秒でタイムアウト
                    data: {
                        action: 'kashiwazaki_fetch_related_posts',
                        post_id: postId,
                        max_posts: maxPosts,
                        target_post_types: targetPostTypes,
                        nonce: kashiwazaki_related_posts_ajax.nonce
                    },
                    success: function(response) {
                        clearTimeout(warningTimer); // 警告タイマーをクリア
                        var endTime = new Date().getTime();

                        if (response.success) {
                            // 成功時の処理（常に新規生成）
                            $status.html('<span style="color: green;">✅ 新規生成完了 (' + response.data.count + '件)</span>');
                            $list.html(response.data.html);

                            // タイムスタンプとモデル情報を更新
                            var modelInfo = response.data.model ? ' | モデル: ' + response.data.model : '';
                            var timestampHtml = '<p style="color: #666; font-size: 12px; margin: 5px 0;">📅 最終取得：' + response.data.timestamp + modelInfo + '</p>';
                            if ($('#kashiwazaki-related-posts-preview p').length > 0) {
                                $('#kashiwazaki-related-posts-preview p').first().replaceWith(timestampHtml);
                            } else {
                                $('#kashiwazaki-related-posts-preview').prepend(timestampHtml);
                            }

                            setTimeout(function() {
                                $status.text('');
                            }, 3000);
                        } else {
                            // エラー時の処理
                            $status.html('<span style="color: red;">❌ エラー: ' + (response.data || '不明なエラー') + '</span>');
                            $list.html('<p style="color: #d32f2f; margin: 0; text-align: center;">関連記事の取得に失敗しました。</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        clearTimeout(warningTimer); // 警告タイマーをクリア
                        var endTime = new Date().getTime();
                        console.error('AJAXエラー - 実行時間: ' + (endTime - startTime) + 'ms, ステータス: ' + status);
                        console.error('AJAX通信エラー:', { xhr: xhr, status: status, error: error });

                        if (status === 'timeout') {
                            $status.html('<span style="color: orange;">⏱️ タイムアウト (20秒)</span>');
                            $list.html('<p style="color: #ff9800; margin: 0; text-align: center;">処理に時間がかかりすぎました。<br>ページを再読み込みして再試行してください。</p>');
                        } else {
                            $status.html('<span style="color: red;">❌ 通信エラー</span>');
                            $list.html('<p style="color: #d32f2f; margin: 0; text-align: center;">通信エラーが発生しました。<br>エラー: ' + (error || status || '不明') + '</p>');
                        }
                    },
                    complete: function() {
                        clearTimeout(warningTimer); // 警告タイマーをクリア
                        var endTime = new Date().getTime();
                        $button.prop('disabled', false).text('関連記事を取得');
                    }
                });
            });

            // キャッシュクリアボタンの処理
            $('#kashiwazaki-clear-cache').on('click', function() {
                if (!confirm('キャッシュをクリアしますか？')) {
                    return;
                }

                var $button = $(this);
                var $status = $('#kashiwazaki-fetch-status');
                var $list = $('#kashiwazaki-related-posts-list');

                $button.prop('disabled', true).text('クリア中...');
                $status.html('<span style="color: orange;">⏳ キャッシュクリア中...</span>');

                $.ajax({
                    url: kashiwazaki_related_posts_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'kashiwazaki_clear_cache',
                        post_id: $('#post_ID').val(),
                        nonce: kashiwazaki_related_posts_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<span style="color: green;">キャッシュクリア完了</span>');
                            $list.html('<p style="color: #999; margin: 0; text-align: center;">キャッシュがクリアされました。「関連記事を取得」ボタンをクリックして関連記事を表示してください。</p>');
                            $button.hide(); // キャッシュクリア後はボタンを非表示

                            // タイムスタンプを削除
                            $('#kashiwazaki-related-posts-preview p').first().remove();

                            setTimeout(function() {
                                $status.text('');
                            }, 3000);
                        } else {
                            $status.html('<span style="color: red;">❌ エラー: ' + (response.data || 'クリアに失敗') + '</span>');
                        }
                    },
                    error: function() {
                        $status.html('<span style="color: red;">❌ 通信エラー</span>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('🗑️ キャッシュクリア');
                    }
                });
            });
        });
        </script>
        <?php
    }

        /**
     * メタボックスの保存
     */
    public function save_metabox($post_id) {
        // 自動保存の場合は何もしない
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Nonceの確認
        if (!isset($_POST['kashiwazaki_seo_related_posts_nonce']) ||
            !wp_verify_nonce($_POST['kashiwazaki_seo_related_posts_nonce'], 'kashiwazaki_seo_related_posts_nonce')) {
            return;
        }

        // 権限チェック
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // カスタムフィールドの保存
        $enabled = isset($_POST['kashiwazaki_seo_related_posts_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_kashiwazaki_seo_related_posts_enabled', $enabled);

        // 詳細設定の保存（有効な場合のみ）
        if ($enabled === '1') {
            // 検索方法（必須要素として固定）
            $search_methods = array('tags', 'categories', 'directory', 'title', 'excerpt');
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_search_methods', $search_methods);

            // 最大表示記事数
            $max_posts = isset($_POST['kashiwazaki_seo_related_posts_max_posts']) ?
                         intval($_POST['kashiwazaki_seo_related_posts_max_posts']) : 5;
            $max_posts = max(1, min(20, $max_posts)); // 1-20の範囲に制限
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_max_posts', $max_posts);

            // 表示形式
            $display_method = isset($_POST['kashiwazaki_seo_related_posts_display_method']) ?
                              sanitize_text_field($_POST['kashiwazaki_seo_related_posts_display_method']) : 'list';
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_display_method', $display_method);

            // 挿入位置
                            $insert_position = isset($_POST['kashiwazaki_seo_related_posts_insert_position']) ?
                                  sanitize_text_field($_POST['kashiwazaki_seo_related_posts_insert_position']) : 'after_content';
                update_post_meta($post_id, '_kashiwazaki_seo_related_posts_insert_position', $insert_position);

                $target_post_types = isset($_POST['kashiwazaki_seo_related_posts_target_post_types']) ?
                                    array_map('sanitize_text_field', $_POST['kashiwazaki_seo_related_posts_target_post_types']) :
                                    array('post', 'page');
                update_post_meta($post_id, '_kashiwazaki_seo_related_posts_target_post_types', $target_post_types);

            // カテゴリフィルタ
            $filter_categories = isset($_POST['kashiwazaki_seo_related_posts_filter_categories']) ?
                                array_map('intval', $_POST['kashiwazaki_seo_related_posts_filter_categories']) :
                                array();
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_filter_categories', $filter_categories);

            // カラーテーマ
            $color_theme = isset($_POST['kashiwazaki_seo_related_posts_color_theme']) ?
                          sanitize_text_field($_POST['kashiwazaki_seo_related_posts_color_theme']) : 'blue';
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_color_theme', $color_theme);

            // 見出しテキスト
            $heading_text = isset($_POST['kashiwazaki_seo_related_posts_heading_text']) ?
                           sanitize_text_field($_POST['kashiwazaki_seo_related_posts_heading_text']) : '関連記事';
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_heading_text', $heading_text);

            // 見出しタグ
            $heading_tag = isset($_POST['kashiwazaki_seo_related_posts_heading_tag']) ?
                          sanitize_text_field($_POST['kashiwazaki_seo_related_posts_heading_tag']) : 'h2';
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_heading_tag', $heading_tag);

        } else {
            // 無効の場合は詳細設定をクリア
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_search_methods');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_max_posts');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_display_method');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_insert_position');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_target_post_types');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_filter_categories');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_color_theme');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_heading_text');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_heading_tag');
        }

        // 記事保存時にキャッシュをクリア（内容が変わった可能性があるため）
        delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results');
        delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp');
    }

    /**
     * AJAX: 関連記事取得処理
     */
    public function ajax_fetch_related_posts() {

        // nonce検証
        if (!wp_verify_nonce($_POST['nonce'], 'kashiwazaki_fetch_related_posts')) {
            wp_send_json_error('セキュリティエラー');
            return;
        }

        // パラメータ取得と検証
        $post_id = absint($_POST['post_id']);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {

            wp_send_json_error('権限エラー');
            return;
        }

        // AI分析対象要素は必須として固定
        $search_methods = array('tags', 'categories', 'directory', 'title', 'excerpt');
        $max_posts = absint($_POST['max_posts']);
        $target_post_types = isset($_POST['target_post_types']) ? array_map('sanitize_text_field', $_POST['target_post_types']) : array();

        try {
            // 実行時間制限を20秒に設定
            set_time_limit(20);

                        // ボタン押下時は常に新規生成

            // プラグイン設定を取得
            $plugin_options = get_option('kashiwazaki_seo_related_posts_options', array());
            $api_provider = isset($plugin_options['api_provider']) ? $plugin_options['api_provider'] : 'openrouter';

            // API選択に基づいてAPIキーを取得
            if ($api_provider === 'openrouter') {
                $api_key = isset($plugin_options['openrouter_api_key']) ? $plugin_options['openrouter_api_key'] : '';
                // 互換性のため、古いapi_keyも確認
                if (empty($api_key) && isset($plugin_options['api_key'])) {
                    $api_key = $plugin_options['api_key'];
                }
            } else {
                $api_key = isset($plugin_options['openai_api_key']) ? $plugin_options['openai_api_key'] : '';
            }

            $use_ai = !empty($api_key);



            $options = array(
                'max_posts' => $max_posts,
                'use_ai' => $use_ai,
                'post_types' => $target_post_types,
                'search_methods' => $search_methods
            );

            $related_posts = $this->related_posts->get_related_posts($post_id, $options);

            // データ整形
            $cached_results = array();

            foreach ($related_posts as $index => $related_post) {
                // より柔軟なID取得
                $related_post_id = null;
                $score = 'N/A';
                $reason = '';

                if (is_object($related_post)) {
                    // オブジェクトからID取得
                    if (isset($related_post->ID)) {
                        $related_post_id = $related_post->ID;
                    } elseif (isset($related_post->post_id)) {
                        $related_post_id = $related_post->post_id;
                    } elseif (isset($related_post->id)) {
                        $related_post_id = $related_post->id;
                    }

                    // オブジェクトからスコアと理由取得
                    if (isset($related_post->similarity_score)) {
                        $score = $related_post->similarity_score;
                    } elseif (isset($related_post->score)) {
                        $score = $related_post->score;
                    }
                    if (isset($related_post->selection_reason)) {
                        $reason = $related_post->selection_reason;
                    } elseif (isset($related_post->reason)) {
                        $reason = $related_post->reason;
                    }
                } elseif (is_array($related_post)) {
                    // 配列からID取得
                    if (isset($related_post['ID'])) {
                        $related_post_id = $related_post['ID'];
                    } elseif (isset($related_post['post_id'])) {
                        $related_post_id = $related_post['post_id'];
                    } elseif (isset($related_post['id'])) {
                        $related_post_id = $related_post['id'];
                    }

                    // 配列からスコアと理由取得
                    if (isset($related_post['score'])) {
                        $score = $related_post['score'];
                    }
                    if (isset($related_post['reason'])) {
                        $reason = $related_post['reason'];
                    }
                } elseif (is_numeric($related_post)) {
                    $related_post_id = $related_post;
                }

                // IDが取得できない場合のエラーハンドリング
                if (!$related_post_id) {
                    continue;
                }

                // より確実な方法でデータを取得
                $post_obj = get_post($related_post_id);
                if (!$post_obj) {
                    continue;
                }

                $title = $post_obj->post_title ?: '(タイトルなし)';
                $post_type = $post_obj->post_type ?: '(不明)';
                $date = date('Y-m-d', strtotime($post_obj->post_date));

                $cached_results[] = array(
                    'post_id' => $related_post_id, // フロントエンドと統一
                    'title' => $title,
                    'post_type' => $post_type,
                    'date' => $date,
                    'score' => is_numeric($score) ? round($score, 3) : $score,
                    'reason' => $reason
                );
            }

            // スコア順（降順）でソート - array_multisortを使用
            if (!empty($cached_results)) {
                $scores = array_column($cached_results, 'score');
                // 数値に変換
                $scores = array_map(function($score) {
                    return is_numeric($score) ? floatval($score) : 0;
                }, $scores);
                array_multisort($scores, SORT_DESC, SORT_NUMERIC, $cached_results);
            }

            // 使用したモデル情報を取得（OpenAIのみ）
            $used_model = isset($plugin_options['openai_model']) && !empty($plugin_options['openai_model']) ? $plugin_options['openai_model'] : 'gpt-4o-mini';

            // 新規取得結果をキャッシュとして保存
            $timestamp = time();
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results', $cached_results);
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp', $timestamp);
            update_post_meta($post_id, '_kashiwazaki_seo_related_posts_used_model', $used_model);





            // HTML生成
            $html = $this->generate_related_posts_html($cached_results);



                        // レスポンス送信
            wp_send_json_success(array(
                'html' => $html,
                'timestamp' => date('Y-m-d H:i:s', $timestamp),
                'count' => count($cached_results),
                'cached' => false, // 新規生成なので常にfalse
                'model' => $used_model
            ));

        } catch (Exception $e) {
            wp_send_json_error('関連記事の取得に失敗しました: ' . $e->getMessage());
        }
    }

        /**
     * 関連記事リストのHTML生成
     */
    private function generate_related_posts_html($cached_results) {
        if (empty($cached_results)) {
            return '<p style="color: #666; margin: 0;">関連記事が見つかりませんでした。</p>';
        }

        $html = '<ol style="margin: 0; padding-left: 20px;">';
        foreach ($cached_results as $index => $related_post) {

            $title = isset($related_post['title']) ? $related_post['title'] : '(タイトルなし)';
            $post_type = isset($related_post['post_type']) ? $related_post['post_type'] : '(不明)';
            $date = isset($related_post['date']) ? $related_post['date'] : '(日付なし)';
            $score = isset($related_post['score']) ? $related_post['score'] : 'N/A';
            $reason = isset($related_post['reason']) ? $related_post['reason'] : '';

            // post_idまたはid（古い形式）がある場合、実際の投稿データから再取得
            $target_id = null;
            if (isset($related_post['post_id']) && $related_post['post_id']) {
                $target_id = $related_post['post_id'];
            } elseif (isset($related_post['id']) && $related_post['id']) {
                $target_id = $related_post['id']; // 古い形式との互換性
            }

            if ($target_id) {
                $post_obj = get_post($target_id);
                if ($post_obj) {
                    $title = $post_obj->post_title ?: $title;
                    $post_type = $post_obj->post_type ?: $post_type;
                    $date = date('Y-m-d', strtotime($post_obj->post_date));
                }
            }

            $html .= '<li style="margin-bottom: 8px;">';
            $html .= '<strong>' . esc_html($title) . '</strong><br>';
            $html .= '<small style="color: #666;">';
            $html .= '投稿タイプ: ' . esc_html($post_type) . ' | ';
            $html .= '日付: ' . esc_html($date) . ' | ';
            $html .= 'スコア: ' . esc_html($score);
            if (!empty($reason)) {
                $html .= '<br>理由: ' . esc_html($reason);
            }
            $html .= '</small>';
            $html .= '</li>';
        }
        $html .= '</ol>';

        return $html;
    }

    /**
     * AJAX: キャッシュクリア処理
     */
    public function ajax_clear_cache() {
        // nonce検証
        if (!wp_verify_nonce($_POST['nonce'], 'kashiwazaki_fetch_related_posts')) {
            wp_send_json_error('セキュリティエラー');
            return;
        }

        // パラメータ取得と検証
        $post_id = absint($_POST['post_id']);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('権限エラー');
            return;
        }

        try {
            // キャッシュデータを削除
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_results');
            delete_post_meta($post_id, '_kashiwazaki_seo_related_posts_cached_timestamp');

            wp_send_json_success(array(
                'message' => 'キャッシュクリア完了'
            ));

        } catch (Exception $e) {
            wp_send_json_error('キャッシュクリアに失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: 同じ投稿タイプのすべての記事をデフォルト値に戻す
     */
    public function ajax_reset_all_posts_to_defaults() {
        // nonce検証
        if (!wp_verify_nonce($_POST['nonce'], 'kashiwazaki_fetch_related_posts')) {
            wp_send_json_error('セキュリティエラー');
            return;
        }

        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限エラー');
            return;
        }

        // パラメータ取得と検証
        $post_type = sanitize_text_field($_POST['post_type']);
        if (!post_type_exists($post_type)) {
            wp_send_json_error('無効な投稿タイプです');
            return;
        }

        try {
            // 対象の投稿タイプのすべての記事を取得
            $args = array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids'
            );
            $posts = get_posts($args);

            if (empty($posts)) {
                wp_send_json_success(array(
                    'message' => '対象の記事が見つかりませんでした。',
                    'count' => 0
                ));
                return;
            }

            // 削除対象のメタキー（enabledは表示ON/OFF制御なので除外）
            $meta_keys = array(
                // '_kashiwazaki_seo_related_posts_enabled', // 表示ON/OFFは維持
                '_kashiwazaki_seo_related_posts_search_methods',
                '_kashiwazaki_seo_related_posts_max_posts',
                '_kashiwazaki_seo_related_posts_display_method',
                '_kashiwazaki_seo_related_posts_insert_position',
                '_kashiwazaki_seo_related_posts_target_post_types',
                '_kashiwazaki_seo_related_posts_filter_categories',
                '_kashiwazaki_seo_related_posts_color_theme',
                '_kashiwazaki_seo_related_posts_heading_text',
                '_kashiwazaki_seo_related_posts_heading_tag',
                '_kashiwazaki_seo_related_posts_cached_results',
                '_kashiwazaki_seo_related_posts_cached_timestamp',
                '_kashiwazaki_seo_related_posts_used_model'
            );

            $reset_count = 0;
            foreach ($posts as $post_id) {
                // 各投稿のカスタムフィールドを削除
                foreach ($meta_keys as $meta_key) {
                    delete_post_meta($post_id, $meta_key);
                }
                $reset_count++;
            }

            $post_type_object = get_post_type_object($post_type);
            $post_type_label = $post_type_object ? $post_type_object->label : $post_type;

            wp_send_json_success(array(
                'message' => sprintf(
                    '「%s」の %d 件の記事を現在のデフォルト値に戻しました。',
                    $post_type_label,
                    $reset_count
                ),
                'count' => $reset_count
            ));

        } catch (Exception $e) {
            wp_send_json_error('処理中にエラーが発生しました: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: 投稿タイプのすべての記事で関連記事表示を有効化
     */
    public function ajax_enable_all_posts() {
        // nonce検証
        if (!wp_verify_nonce($_POST['nonce'], 'kashiwazaki_admin_action')) {
            wp_send_json_error('セキュリティエラー');
            return;
        }

        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限エラー');
            return;
        }

        // パラメータ取得と検証
        $post_type = sanitize_text_field($_POST['post_type']);
        if (!post_type_exists($post_type)) {
            wp_send_json_error('無効な投稿タイプです');
            return;
        }

        try {
            // 対象の投稿タイプのすべての記事を取得
            $args = array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids'
            );
            $posts = get_posts($args);

            if (empty($posts)) {
                wp_send_json_success(array(
                    'message' => '対象の記事が見つかりませんでした。',
                    'count' => 0
                ));
                return;
            }

            $enabled_count = 0;
            foreach ($posts as $post_id) {
                // 関連記事表示を有効化
                update_post_meta($post_id, '_kashiwazaki_seo_related_posts_enabled', '1');
                $enabled_count++;
            }

            $post_type_object = get_post_type_object($post_type);
            $post_type_label = $post_type_object ? $post_type_object->label : $post_type;

            wp_send_json_success(array(
                'message' => sprintf(
                    '「%s」の %d 件の記事で関連記事表示を有効化しました。',
                    $post_type_label,
                    $enabled_count
                ),
                'count' => $enabled_count
            ));

        } catch (Exception $e) {
            wp_send_json_error('処理中にエラーが発生しました: ' . $e->getMessage());
        }
    }
}
