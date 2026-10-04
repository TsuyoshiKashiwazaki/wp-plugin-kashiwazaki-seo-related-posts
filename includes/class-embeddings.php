<?php

if (!defined('ABSPATH')) exit;

/**
 * OpenAI Embeddings による記事ベクトルの生成・保存・類似検索
 *
 * - ベクトルは OpenAI が返す base64（float32 リトルエンディアン）のまま投稿メタに保存する
 * - 使える embedding モデルは OpenAI の GET /v1/models から自動取得する
 */
class KashiwazakiSEORelatedPosts_Embeddings {

    const META_KEY = '_kashiwazaki_seo_related_posts_embedding';
    const MODELS_OPTION = 'kashiwazaki_seo_related_posts_embedding_models';
    const BACKFILL_HOOK = 'kashiwazaki_seo_related_posts_embed_missing';
    const BACKOFF_TRANSIENT = 'kashiwazaki_seo_related_posts_api_backoff';
    const DEFAULT_MODEL = 'text-embedding-3-small';

    // 1 入力 8192 トークンの上限に対して安全側の文字数（日本語は 1 文字が 1〜2 トークン程度。超えたら短くして作り直す）
    const MAX_SOURCE_CHARS = 3000;
    // 1 回の API 呼び出しで送る記事数（1 リクエスト合計 300,000 トークン以内に十分収まる）
    const BATCH_SIZE = 20;
    // バックグラウンド処理 1 回あたりの最大記事数
    const BACKFILL_PER_RUN = 60;
    // 類似検索で比較する記事数の上限
    const MAX_COMPARE = 3000;
    // モデル一覧の再取得間隔（成功時 / 失敗時）
    const MODELS_TTL = DAY_IN_SECONDS;
    const MODELS_RETRY = HOUR_IN_SECONDS;

    private $api;

    public function __construct($api) {
        $this->api = $api;

        add_action('save_post', array($this, 'on_save_post'), 20, 2);
        add_action(self::BACKFILL_HOOK, array($this, 'run_backfill'));
        add_action('wp_ajax_kashiwazaki_embedding_backfill', array($this, 'ajax_backfill'));
        add_action('wp_ajax_kashiwazaki_refresh_embedding_models', array($this, 'ajax_refresh_models'));
    }

    /* ------------------------------------------------------------------
     * 設定
     * ------------------------------------------------------------------ */

    /**
     * 現在選ばれている embedding モデル
     */
    public function get_model() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $model = isset($options['embedding_model']) && is_string($options['embedding_model']) ? $options['embedding_model'] : '';
        return self::is_valid_model_id($model) ? $model : self::DEFAULT_MODEL;
    }

    /**
     * モデル ID として使える文字列か（API に渡す値の形式チェック）
     */
    public static function is_valid_model_id($model) {
        return is_string($model) && $model !== '' && strlen($model) <= 100 && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/', $model);
    }

    /**
     * embedding で関連記事を選ぶ設定になっているか
     */
    public function is_enabled() {
        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        $mode = isset($options['selection_mode']) ? $options['selection_mode'] : 'embedding';
        return in_array($mode, array('embedding', 'embedding_gpt'), true) && kashiwazaki_seo_related_posts_get_api_key() !== '';
    }

    /**
     * API の一時停止中か（連続失敗時にページ表示を遅くしないため）
     */
    public function is_backing_off() {
        return (bool) get_transient(self::BACKOFF_TRANSIENT);
    }

    private function start_backoff() {
        set_transient(self::BACKOFF_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
    }

    /* ------------------------------------------------------------------
     * モデル一覧（OpenAI から自動取得）
     * ------------------------------------------------------------------ */

    /**
     * 使える embedding モデルの一覧
     *
     * @param bool $force_refresh true なら保存済みの一覧を使わず OpenAI に問い合わせる
     * @return array {models: array<array{id:string,created:int,shutdown_date:?string}>, fetched_at:int, error:string}
     */
    public function get_available_models($force_refresh = false) {
        $stored = get_option(self::MODELS_OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $models = isset($stored['models']) && is_array($stored['models']) ? $stored['models'] : array();
        $chat_models = isset($stored['chat_models']) && is_array($stored['chat_models']) ? $stored['chat_models'] : array();
        $fetched_at = isset($stored['fetched_at']) ? (int) $stored['fetched_at'] : 0;
        $attempted_at = isset($stored['attempted_at']) ? (int) $stored['attempted_at'] : 0;
        $error = isset($stored['error']) ? (string) $stored['error'] : '';

        $api_key = kashiwazaki_seo_related_posts_get_api_key();
        $stale = empty($models) ? (time() - $attempted_at >= self::MODELS_RETRY) : (time() - $fetched_at >= self::MODELS_TTL && time() - $attempted_at >= self::MODELS_RETRY);

        if ($api_key !== '' && ($force_refresh || $stale)) {
            $result = $this->api->list_models($api_key);
            $attempted_at = time();

            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                $models = $this->filter_embedding_models($result);
                $chat_models = $this->filter_chat_models($result);
                $fetched_at = $attempted_at;
                $error = empty($models) ? 'OpenAI のモデル一覧に embedding モデルが見つかりませんでした' : '';
            }

            update_option(self::MODELS_OPTION, array(
                'models' => $models,
                'chat_models' => $chat_models,
                'fetched_at' => $fetched_at,
                'attempted_at' => $attempted_at,
                'error' => $error,
            ), false);
        }

        if (empty($models)) {
            // 取得できないときの既定（公式ドキュメント記載のモデル）
            $models = array(
                array('id' => 'text-embedding-3-small', 'created' => 0, 'shutdown_date' => null),
                array('id' => 'text-embedding-3-large', 'created' => 0, 'shutdown_date' => null),
            );
        }

        if (empty($chat_models)) {
            // 取得できないときの既定（chat completions で使える既知のモデル）
            $chat_models = array(
                array('id' => 'gpt-4o-mini', 'created' => 0, 'shutdown_date' => null),
                array('id' => 'gpt-4o', 'created' => 0, 'shutdown_date' => null),
            );
        }

        return array(
            'models' => $models,
            'chat_models' => $chat_models,
            'fetched_at' => $fetched_at,
            'error' => $error,
        );
    }

    /**
     * GET /v1/models の結果から、関連記事の選定 (chat completions) に使えそうな GPT モデルを取り出す（新しい順）。
     * 一覧には用途の情報が無いため名前で絞る。実際に使えるかは保存時に API を 1 回呼んで確かめる
     */
    private function filter_chat_models($list) {
        $exclude = array('embedding', 'audio', 'realtime', 'tts', 'transcribe', 'image', 'search', 'moderation', 'instruct', 'dall-e', 'whisper', 'codex', 'computer-use', 'deep-research', 'sora');
        $models = array();
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['id']) || !self::is_valid_model_id($item['id'])) {
                continue;
            }
            $id = $item['id'];
            if (!preg_match('/^(gpt-|chatgpt-|o[0-9])/i', $id)) {
                continue;
            }
            foreach ($exclude as $word) {
                if (stripos($id, $word) !== false) {
                    continue 2;
                }
            }
            $models[] = array(
                'id' => $id,
                'created' => isset($item['created']) ? (int) $item['created'] : 0,
                'shutdown_date' => isset($item['shutdown_date']) && is_string($item['shutdown_date']) ? $item['shutdown_date'] : null,
            );
        }

        usort($models, function ($a, $b) {
            if ($a['created'] === $b['created']) {
                return strcmp($a['id'], $b['id']);
            }
            return ($a['created'] > $b['created']) ? -1 : 1;
        });

        return $models;
    }

    /**
     * GPT モデルの ID が一覧に含まれるか
     */
    public function is_known_chat_model($model) {
        $available = $this->get_available_models();
        foreach ($available['chat_models'] as $item) {
            if ($item['id'] === $model) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET /v1/models の結果から embedding モデルだけを取り出す（新しい順）
     */
    private function filter_embedding_models($list) {
        $models = array();
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['id']) || !self::is_valid_model_id($item['id'])) {
                continue;
            }
            if (stripos($item['id'], 'embedding') === false) {
                continue;
            }
            $models[] = array(
                'id' => $item['id'],
                'created' => isset($item['created']) ? (int) $item['created'] : 0,
                'shutdown_date' => isset($item['shutdown_date']) && is_string($item['shutdown_date']) ? $item['shutdown_date'] : null,
            );
        }

        usort($models, function ($a, $b) {
            if ($a['created'] === $b['created']) {
                return strcmp($a['id'], $b['id']);
            }
            return ($a['created'] > $b['created']) ? -1 : 1;
        });

        return $models;
    }

    /**
     * モデル ID が一覧に含まれるか
     */
    public function is_known_model($model) {
        $available = $this->get_available_models();
        foreach ($available['models'] as $item) {
            if ($item['id'] === $model) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * 対象記事
     * ------------------------------------------------------------------ */

    /**
     * ベクトルを作る対象の投稿タイプ（公開投稿タイプ。添付ファイルは除く）
     */
    public function get_embeddable_post_types() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        return array_values($types);
    }

    /**
     * ベクトル化する文章（タイトル・タクソノミー名・抜粋・本文）
     */
    public function build_source_text($post) {
        $post = get_post($post);
        if (!$post) {
            return '';
        }

        $parts = array();
        $parts[] = wp_strip_all_tags($post->post_title);

        $term_names = array();
        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if (empty($taxonomy->public)) {
                continue;
            }
            $terms = get_the_terms($post->ID, $taxonomy->name);
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    $term_names[] = $term->name;
                }
            }
        }
        if (!empty($term_names)) {
            $parts[] = implode(', ', array_unique($term_names));
        }

        if (!empty($post->post_excerpt)) {
            $parts[] = wp_strip_all_tags($post->post_excerpt);
        }

        $content = strip_shortcodes($post->post_content);
        $content = wp_strip_all_tags($content, true);
        $parts[] = $content;

        $text = html_entity_decode(implode("\n", $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{3000}]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        $text = trim($text);

        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, self::MAX_SOURCE_CHARS, 'UTF-8');
        } else {
            $text = substr($text, 0, self::MAX_SOURCE_CHARS * 3);
        }

        return $text;
    }

    private function source_hash($model, $text) {
        return md5($model . "\n" . $text);
    }

    /**
     * 保存済みベクトルが今のモデル・本文と一致しているか
     */
    private function is_current($meta, $model, $hash) {
        return is_array($meta)
            && isset($meta['model'], $meta['hash'], $meta['vector'])
            && $meta['model'] === $model
            && $meta['hash'] === $hash;
    }

    /**
     * 同じモデル・同じ本文で、入力が原因の失敗として記録済みか（本文が変わるまで再試行しない）
     */
    private function is_failed($meta, $model, $hash) {
        return is_array($meta)
            && !empty($meta['failed'])
            && isset($meta['model'], $meta['hash'])
            && $meta['model'] === $model
            && $meta['hash'] === $hash;
    }

    /**
     * 入力が原因でベクトルを作れなかった記事に印を付ける
     */
    private function mark_failed($post_id, $model, $hash, $message) {
        update_post_meta($post_id, self::META_KEY, array(
            'model' => $model,
            'hash' => $hash,
            'failed' => true,
            'error' => function_exists('mb_substr') ? mb_substr((string) $message, 0, 200, 'UTF-8') : substr((string) $message, 0, 200),
            'created' => time(),
        ));
    }

    /* ------------------------------------------------------------------
     * ベクトルの生成
     * ------------------------------------------------------------------ */

    /**
     * 指定した記事のベクトルを（必要な分だけ）作る
     *
     * @return array{created:int, skipped:int, failed:int, error:string}
     */
    public function embed_posts(array $post_ids) {
        $summary = array('created' => 0, 'skipped' => 0, 'failed' => 0, 'error' => '');

        $api_key = kashiwazaki_seo_related_posts_get_api_key();
        if ($api_key === '') {
            $summary['error'] = 'OpenAI APIキーが設定されていません';
            $summary['failed'] = count($post_ids);
            return $summary;
        }

        $model = $this->get_model();
        $embeddable_types = $this->get_embeddable_post_types();
        $pending = array();

        foreach (array_unique(array_map('absint', $post_ids)) as $post_id) {
            $post = $post_id ? get_post($post_id) : null;
            if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, $embeddable_types, true)) {
                $summary['skipped']++;
                continue;
            }

            $text = $this->build_source_text($post);
            if ($text === '') {
                $summary['skipped']++;
                continue;
            }

            $hash = $this->source_hash($model, $text);
            $meta = get_post_meta($post_id, self::META_KEY, true);
            if ($this->is_current($meta, $model, $hash) || $this->is_failed($meta, $model, $hash)) {
                $summary['skipped']++;
                continue;
            }

            $pending[] = array('post_id' => $post_id, 'text' => $text, 'hash' => $hash);
        }

        foreach (array_chunk($pending, self::BATCH_SIZE) as $chunk) {
            $result = $this->api->create_embeddings($api_key, $model, wp_list_pluck($chunk, 'text'));

            // 入力が長すぎる等で弾かれたときは 1 件ずつ、必要なら文章を半分にして作り直す
            if (is_wp_error($result) && $this->is_input_error($result)) {
                $result = array();
                foreach ($chunk as $index => $item) {
                    $single = $this->api->create_embeddings($api_key, $model, array($item['text']));
                    if (is_wp_error($single) && $this->is_input_error($single)) {
                        $half = function_exists('mb_substr') ? mb_substr($item['text'], 0, (int) (self::MAX_SOURCE_CHARS / 2), 'UTF-8') : substr($item['text'], 0, (int) (strlen($item['text']) / 2));
                        $single = $this->api->create_embeddings($api_key, $model, array($half));
                    }
                    if (is_wp_error($single)) {
                        if (!$this->is_input_error($single)) {
                            $result = $single;
                            break;
                        }
                        // 短くしても弾かれる記事は印を付け、本文が変わるまで再試行しない（バックグラウンド処理が同じ記事で止まらないように）
                        $this->mark_failed($item['post_id'], $model, $item['hash'], $single->get_error_message());
                        $summary['failed']++;
                        $result[$index] = null;
                        continue;
                    }
                    $result[$index] = isset($single[0]) ? $single[0] : '';
                }
            }

            if (is_wp_error($result)) {
                $summary['failed'] += count($chunk);
                $summary['error'] = $result->get_error_message();
                $this->start_backoff();
                break;
            }

            foreach ($chunk as $index => $item) {
                if (array_key_exists($index, $result) && $result[$index] === null) {
                    continue; // 失敗の印を付けて数えた記事
                }
                if (!isset($result[$index]) || !is_string($result[$index]) || $result[$index] === '') {
                    $summary['failed']++;
                    continue;
                }
                $binary = base64_decode($result[$index], true);
                $dims = ($binary !== false && strlen($binary) % 4 === 0) ? (int) (strlen($binary) / 4) : 0;
                if ($dims < 1) {
                    $summary['failed']++;
                    continue;
                }
                update_post_meta($item['post_id'], self::META_KEY, array(
                    'model' => $model,
                    'hash' => $item['hash'],
                    'dims' => $dims,
                    'vector' => $result[$index],
                    'created' => time(),
                ));
                $summary['created']++;
            }
        }

        return $summary;
    }

    /**
     * 入力内容が原因の失敗（HTTP 400）か。認証・通信・混雑による失敗とは分けて扱う
     */
    private function is_input_error($error) {
        $data = $error->get_error_data();
        return is_array($data) && isset($data['status']) && (int) $data['status'] === 400;
    }

    /**
     * 記事の保存時: 公開記事ならバックグラウンドでベクトルを作る
     */
    public function on_save_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, $this->get_embeddable_post_types(), true)) {
            return;
        }
        if (!$this->is_enabled()) {
            return;
        }
        $this->schedule_backfill();
    }

    /**
     * 未作成・古いベクトルの作成をバックグラウンドで予約する
     */
    public function schedule_backfill($delay = 5) {
        if (!wp_next_scheduled(self::BACKFILL_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::BACKFILL_HOOK);
        }
    }

    /**
     * WP-Cron: 未作成・古いベクトルを作る（残りがあれば次回を予約）
     */
    public function run_backfill() {
        if (!$this->is_enabled() || $this->is_backing_off()) {
            return;
        }

        $ids = $this->find_posts_needing_embedding(self::BACKFILL_PER_RUN);
        if (empty($ids)) {
            return;
        }

        $summary = $this->embed_posts($ids);
        if ($summary['error'] === '' && count($ids) >= self::BACKFILL_PER_RUN) {
            $this->schedule_backfill(30);
        }
    }

    /**
     * ベクトルが無い・モデルが違う・本文が変わった公開記事を探す
     */
    public function find_posts_needing_embedding($limit) {
        $model = $this->get_model();
        $needed = array();
        $paged = 1;

        do {
            $query = new WP_Query(array(
                'post_type' => $this->get_embeddable_post_types(),
                'post_status' => 'publish',
                'posts_per_page' => 200,
                'paged' => $paged,
                'orderby' => 'ID',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_term_cache' => false,
                'ignore_sticky_posts' => true,
                'suppress_filters' => true,
            ));
            if (empty($query->posts)) {
                break;
            }

            foreach ($query->posts as $post) {
                // 本文が空の記事・入力が原因で作れなかった記事は対象にしない（同じ記事を取り続けて処理が進まなくなるのを防ぐ）
                $text = $this->build_source_text($post);
                if ($text === '') {
                    continue;
                }
                $hash = $this->source_hash($model, $text);
                $meta = get_post_meta($post->ID, self::META_KEY, true);
                if ($this->is_current($meta, $model, $hash) || $this->is_failed($meta, $model, $hash)) {
                    continue;
                }
                $needed[] = $post->ID;
                if (count($needed) >= $limit) {
                    break 2;
                }
            }
            $paged++;
        } while (count($query->posts) === 200);

        return $needed;
    }

    /**
     * 作成状況（今のモデルで作成済みの数 / 対象の公開記事数）
     */
    public function get_status() {
        global $wpdb;

        $types = $this->get_embeddable_post_types();
        if (empty($types)) {
            return array('total' => 0, 'embedded' => 0, 'model' => $this->get_model());
        }

        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)",
            $types
        ));

        $model = $this->get_model();
        $like = '%' . $wpdb->esc_like(serialize('model') . serialize($model)) . '%';
        $embedded = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND pm.meta_value LIKE %s
             AND p.post_status = 'publish' AND p.post_type IN ($placeholders)",
            array_merge(array(self::META_KEY, $like, '%' . $wpdb->esc_like(serialize('vector')) . '%'), $types)
        ));

        return array('total' => $total, 'embedded' => $embedded, 'model' => $model);
    }

    /* ------------------------------------------------------------------
     * 類似検索
     * ------------------------------------------------------------------ */

    /**
     * base64 のベクトルを float の配列に戻す
     */
    private function decode_vector($meta) {
        if (!is_array($meta) || !isset($meta['vector']) || !is_string($meta['vector'])) {
            return null;
        }
        $binary = base64_decode($meta['vector'], true);
        if ($binary === false || $binary === '' || strlen($binary) % 4 !== 0) {
            return null;
        }
        // OpenAI の base64 は float32 リトルエンディアン
        $values = unpack('g*', $binary);
        return $values ? array_values($values) : null;
    }

    /**
     * 記事のベクトルを取得する（無い・古い場合、管理画面・WP-Cron・WP-CLI ではその場で 1 件だけ作る）
     * 公開ページの表示中は OpenAI を呼ばない（表示を待たせない）。バックグラウンドの作成を予約して null を返す
     */
    private function get_or_create_vector($post_id) {
        $model = $this->get_model();
        $text = $this->build_source_text($post_id);
        if ($text === '') {
            return null;
        }
        $hash = $this->source_hash($model, $text);
        $meta = get_post_meta($post_id, self::META_KEY, true);

        if (!$this->is_current($meta, $model, $hash)) {
            $can_create_now = is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
            if (!$can_create_now || $this->is_backing_off() || $this->is_failed($meta, $model, $hash)) {
                if (!$this->is_failed($meta, $model, $hash)) {
                    $this->schedule_backfill();
                }
                return null;
            }
            $summary = $this->embed_posts(array($post_id));
            if ($summary['created'] < 1) {
                return null;
            }
            $meta = get_post_meta($post_id, self::META_KEY, true);
        }

        return $this->decode_vector($meta);
    }

    /**
     * 意味の近い記事を探す
     *
     * @param int   $post_id 基準の記事
     * @param array $options post_types / filter_categories / exclude_ids
     * @param int   $limit   返す件数
     * @return array|WP_Error {results: array<array{post_id:int, score:float}>, missing:int}
     */
    public function find_similar_posts($post_id, $options, $limit) {
        $source = $this->get_or_create_vector($post_id);
        if (empty($source)) {
            return new WP_Error('embedding_unavailable', '基準記事のベクトルを用意できませんでした');
        }

        $source_norm = $this->norm($source);
        if ($source_norm <= 0) {
            return new WP_Error('embedding_invalid', '基準記事のベクトルが不正です');
        }

        $post_types = isset($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : array('post');
        // ベクトルを作る対象の投稿タイプに限る（それ以外は文字の一致で補う。未作成として数え続けないため）
        $post_types = array_values(array_intersect($post_types, $this->get_embeddable_post_types()));
        if (empty($post_types)) {
            return array('results' => array(), 'missing' => 0);
        }

        $exclude = array((int) $post_id);
        if (!empty($options['exclude_ids']) && is_array($options['exclude_ids'])) {
            $exclude = array_merge($exclude, array_map('intval', $options['exclude_ids']));
        }

        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_COMPARE,
            'post__not_in' => $exclude,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        );
        if (!empty($options['filter_categories'])) {
            $args['category__in'] = array_map('intval', (array) $options['filter_categories']);
        }

        $query = new WP_Query($args);
        $candidate_ids = array_map('intval', $query->posts);
        if (empty($candidate_ids)) {
            return array('results' => array(), 'missing' => 0);
        }

        update_meta_cache('post', $candidate_ids);

        $model = $this->get_model();
        $dims = count($source);
        $results = array();
        $missing = 0;

        foreach ($candidate_ids as $candidate_id) {
            $meta = get_post_meta($candidate_id, self::META_KEY, true);
            if (is_array($meta) && !empty($meta['failed']) && isset($meta['model']) && $meta['model'] === $model) {
                continue; // 入力が原因で作れなかった記事（未作成としては数えない）
            }
            if (!is_array($meta) || !isset($meta['model']) || $meta['model'] !== $model) {
                $missing++;
                continue;
            }
            $vector = $this->decode_vector($meta);
            if (!$vector || count($vector) !== $dims) {
                $missing++;
                continue;
            }

            $dot = 0.0;
            $norm = 0.0;
            for ($i = 0; $i < $dims; $i++) {
                $dot += $source[$i] * $vector[$i];
                $norm += $vector[$i] * $vector[$i];
            }
            if ($norm <= 0) {
                continue;
            }

            $results[] = array(
                'post_id' => $candidate_id,
                'score' => $dot / ($source_norm * sqrt($norm)),
            );
        }

        usort($results, function ($a, $b) {
            if ($a['score'] == $b['score']) {
                return 0;
            }
            return ($a['score'] > $b['score']) ? -1 : 1;
        });

        if ($missing > 0) {
            $this->schedule_backfill();
        }

        return array(
            'results' => array_slice($results, 0, max(0, (int) $limit)),
            'missing' => $missing,
        );
    }

    /**
     * サイトのタイムゾーンで日時を表示用に整える
     */
    public static function format_time($timestamp) {
        if (!$timestamp) {
            return '';
        }
        return function_exists('wp_date') ? wp_date('Y-m-d H:i', $timestamp) : date_i18n('Y-m-d H:i', $timestamp + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
    }

    private function norm($vector) {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        return sqrt($sum);
    }

    /* ------------------------------------------------------------------
     * 管理画面 AJAX
     * ------------------------------------------------------------------ */

    /**
     * 未作成の記事をまとめて作る（設定画面から 1 回ずつ呼ぶ）
     */
    public function ajax_backfill() {
        check_ajax_referer('kashiwazaki_embedding_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません'), 403);
        }
        if (kashiwazaki_seo_related_posts_get_api_key() === '') {
            wp_send_json_error(array('message' => 'OpenAI APIキーが設定されていません'));
        }

        delete_transient(self::BACKOFF_TRANSIENT);
        $ids = $this->find_posts_needing_embedding(self::BATCH_SIZE);
        $summary = empty($ids) ? array('created' => 0, 'skipped' => 0, 'failed' => 0, 'error' => '') : $this->embed_posts($ids);

        if ($summary['error'] !== '') {
            wp_send_json_error(array('message' => $summary['error'], 'status' => $this->get_status()));
        }

        $status = $this->get_status();
        wp_send_json_success(array(
            'created' => $summary['created'],
            'done' => empty($ids) || ($summary['created'] === 0 && $summary['failed'] === 0),
            'status' => $status,
        ));
    }

    /**
     * OpenAI からモデル一覧を取り直す
     */
    public function ajax_refresh_models() {
        check_ajax_referer('kashiwazaki_embedding_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません'), 403);
        }
        if (kashiwazaki_seo_related_posts_get_api_key() === '') {
            wp_send_json_error(array('message' => 'OpenAI APIキーが設定されていません'));
        }

        $available = $this->get_available_models(true);
        if ($available['error'] !== '') {
            wp_send_json_error(array('message' => $available['error']));
        }

        $options = get_option('kashiwazaki_seo_related_posts_options', array());
        wp_send_json_success(array(
            'models' => $available['models'],
            'chat_models' => $available['chat_models'],
            'current_chat' => (is_array($options) && isset($options['openai_model'])) ? $options['openai_model'] : 'gpt-4o-mini',
            'fetched_at' => self::format_time($available['fetched_at']),
            'current' => $this->get_model(),
        ));
    }
}
