jQuery(document).ready(function ($) {

    // APIキーテストボタンの処理
    $('button[name="test_api"]').on('click', function (e) {
        var $button = $(this);
        var originalText = $button.text();
        var apiKey = $('#api_key_main').val().trim();

        if (!apiKey) {
            alert('APIキーを入力してください。');
            e.preventDefault();
            return false;
        }

        $button.text('テスト中...').prop('disabled', true);

        setTimeout(function () {
            $button.text(originalText).prop('disabled', false);
        }, 3000);
    });

    // AI設定の表示/非表示制御
    $('input[name="ai_enabled"]').on('change', function () {
        var isEnabled = $(this).is(':checked');
        var $modelRow = $('select[name="model"]').closest('tr');
        var $thresholdRow = $('input[name="ai_threshold"]').closest('tr');

        if (isEnabled) {
            $modelRow.show();
            $thresholdRow.show();
        } else {
            $modelRow.hide();
            $thresholdRow.hide();
        }
    });

    $('input[name="ai_enabled"]').trigger('change');



    // 投稿タイプ選択ボタン
    $('#select-all-post-types').on('click', function () {
        $('input[name="metabox_post_types[]"]').prop('checked', true);
    });

    $('#deselect-all-post-types').on('click', function () {
        $('input[name="metabox_post_types[]"]').prop('checked', false);
    });

    $('#select-default-post-types').on('click', function () {
        $('input[name="metabox_post_types[]"]').prop('checked', false);
        $('#metabox_post_type_post, #metabox_post_type_page').prop('checked', true);
    });

    // 対象投稿タイプ選択ボタン (管理画面)
    $('#select-all-target-types').on('click', function () {
        $('input[name="target_post_types[]"]').prop('checked', true);
    });

    $('#deselect-all-target-types').on('click', function () {
        $('input[name="target_post_types[]"]').prop('checked', false);
    });

    $('#select-default-target-types').on('click', function () {
        $('input[name="target_post_types[]"]').prop('checked', false);
        $('#target_post_type_post, #target_post_type_page').prop('checked', true);
    });

    // 対象投稿タイプ選択ボタン (メタボックス)
    $('#metabox-select-all-target-types').on('click', function () {
        $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').prop('checked', true);
    });

    $('#metabox-deselect-all-target-types').on('click', function () {
        $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').prop('checked', false);
    });

    $('#metabox-select-default-target-types').on('click', function () {
        $('input[name="kashiwazaki_seo_related_posts_target_post_types[]"]').prop('checked', false);
        $('#metabox_target_post_type_post, #metabox_target_post_type_page').prop('checked', true);
    });

    // モデル復活確認
    $('.restore-model-form').on('submit', function () {
        var modelName = $(this).find('input[name="restore_model"]').val();
        return confirm('モデル「' + modelName + '」を復活させますか？');
    });

    // AJAX設定（将来の拡張用）
    if (typeof kashiwazaki_related_posts_ajax !== 'undefined') {
        window.kashiwazkiRelatedPostsAjax = {
            url: kashiwazaki_related_posts_ajax.ajax_url,
            nonce: kashiwazaki_related_posts_ajax.nonce,

            checkApiSettings: function (callback) {
                $.post(this.url, {
                    action: 'check_api_settings',
                    nonce: this.nonce
                }, function (response) {
                    if (callback) callback(response);
                });
            }
        };
    }

    // 通知の自動非表示
    $('.notice').on('click', '.notice-dismiss', function () {
        $(this).closest('.notice').fadeOut();
    });

    // フォーム送信時のローディング表示
    $('form').on('submit', function () {
        var $submitButton = $(this).find('input[type="submit"], button[type="submit"]');
        var originalValue = $submitButton.val() || $submitButton.text();

        if ($submitButton.attr('name') === 'test_api') {
            return true; // APIテストボタンは別処理
        }

        $submitButton.val('保存中...').text('保存中...').prop('disabled', true);

        setTimeout(function () {
            $submitButton.val(originalValue).text(originalValue).prop('disabled', false);
        }, 2000);
    });

    // 設定セクションの折りたたみ（将来の拡張用）
    $('.settings-section').each(function () {
        var $section = $(this);
        var $title = $section.find('h2');

        if ($title.length) {
            $title.css('cursor', 'pointer').on('click', function () {
                var $content = $section.find('.form-table');
                $content.slideToggle();

                if ($content.is(':visible')) {
                    $title.removeClass('collapsed');
                } else {
                    $title.addClass('collapsed');
                }
            });
        }
    });

});
