jQuery(document).ready(function ($) {

    $(document).on('click', '.kashiwazaki-related-post-item[data-method="ai"]', function () {
        if (typeof gtag !== 'undefined') {
            gtag('event', 'click', {
                'event_category': 'Kashiwazaki Related Posts',
                'event_label': 'AI Recommended'
            });
        }
    });

    $(document).on('click', '.kashiwazaki-related-post-item[data-method="similarity"]', function () {
        if (typeof gtag !== 'undefined') {
            gtag('event', 'click', {
                'event_category': 'Kashiwazaki Related Posts',
                'event_label': 'Similarity Based'
            });
        }
    });

    $('.kashiwazaki-related-posts').each(function () {
        var $container = $(this);
        var items = $container.find('.kashiwazaki-related-post-item');

        if (items.length > 0) {
            items.each(function (index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('fade-in');
            });
        }
    });

    $(document).on('mouseenter', '.kashiwazaki-related-post-item', function () {
        $(this).addClass('hovered');
    });

    $(document).on('mouseleave', '.kashiwazaki-related-post-item', function () {
        $(this).removeClass('hovered');
    });

    if (window.IntersectionObserver) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');

                    if (typeof gtag !== 'undefined') {
                        gtag('event', 'view_item_list', {
                            'event_category': 'Kashiwazaki Related Posts',
                            'event_label': 'Related Posts Viewed'
                        });
                    }
                }
            });
        }, {
            threshold: 0.1
        });

        $('.kashiwazaki-related-posts').each(function () {
            observer.observe(this);
        });
    }

    $(document).on('click', '.kashiwazaki-related-post-title a', function () {
        var postTitle = $(this).text();
        var currentTitle = document.title;

        if (typeof gtag !== 'undefined') {
            gtag('event', 'click', {
                'event_category': 'Kashiwazaki Related Posts',
                'event_label': 'Related Post Click',
                'custom_parameters': {
                    'current_post': currentTitle,
                    'related_post': postTitle
                }
            });
        }
    });

    // スライダー機能
    $('.kashiwazaki-related-posts-slider').each(function () {
        var $slider = $(this);
        var $track = $slider.find('.kashiwazaki-slider-track');
        var $items = $track.find('.kashiwazaki-slider-item');
        var $prevBtn = $slider.find('.kashiwazaki-slider-prev');
        var $nextBtn = $slider.find('.kashiwazaki-slider-next');

        var currentIndex = 0;
        var itemsToShow = getItemsToShow();
        var maxIndex = Math.max(0, $items.length - itemsToShow);

        function getItemsToShow() {
            var containerWidth = $slider.width();

            // 設定値が存在する場合はそれを使用、なければデフォルト値
            var config = window.kashiwazaki_slider_config || {
                items_mobile: 1,
                items_tablet: 2,
                items_desktop: 3
            };

            if (containerWidth < 768) return parseInt(config.items_mobile);
            if (containerWidth < 1024) return parseInt(config.items_tablet);
            return parseInt(config.items_desktop);
        }

        function updateSlider() {
            var containerWidth = $slider.width();
            // レスポンシブでgapを調整（CSSと同期）
            var gap = containerWidth < 768 ? 6 : 10;

            // 利用可能な幅を計算（gap分を差し引く）
            var availableWidth = containerWidth - (gap * (itemsToShow - 1));
            var itemWidth = Math.floor(availableWidth / itemsToShow);

            // アイテムの幅を設定
            $items.css('width', itemWidth + 'px');

            // 移動量を計算（アイテム幅 + gap）
            var moveDistance = itemWidth + gap;
            var translateX = -(currentIndex * moveDistance);
            $track.css('transform', 'translateX(' + translateX + 'px)');

            // ボタンの表示/非表示
            $prevBtn.toggle(currentIndex > 0);
            $nextBtn.toggle(currentIndex < maxIndex);
        }

        function goToNext() {
            if (currentIndex < maxIndex) {
                currentIndex++;
                updateSlider();
            }
        }

        function goToPrev() {
            if (currentIndex > 0) {
                currentIndex--;
                updateSlider();
            }
        }

        // ボタンクリックイベント
        $prevBtn.on('click', goToPrev);
        $nextBtn.on('click', goToNext);

        // レスポンシブ対応
        $(window).on('resize', function () {
            itemsToShow = getItemsToShow();
            maxIndex = Math.max(0, $items.length - itemsToShow);
            currentIndex = Math.min(currentIndex, maxIndex);
            updateSlider();
        });

        // タッチスワイプ対応
        var startX = 0;
        var isDragging = false;

        $slider.on('touchstart mousedown', function (e) {
            startX = e.type === 'touchstart' ? e.originalEvent.touches[0].clientX : e.clientX;
            isDragging = true;
        });

        $slider.on('touchmove mousemove', function (e) {
            if (!isDragging) return;
            e.preventDefault();
        });

        $slider.on('touchend mouseup', function (e) {
            if (!isDragging) return;
            isDragging = false;

            var endX = e.type === 'touchend' ? e.originalEvent.changedTouches[0].clientX : e.clientX;
            var diffX = startX - endX;

            if (Math.abs(diffX) > 50) { // 50px以上のスワイプで動作
                if (diffX > 0) {
                    goToNext();
                } else {
                    goToPrev();
                }
            }
        });

        // 自動スライド（オプション）
        var autoSlideInterval;
        function startAutoSlide() {
            autoSlideInterval = setInterval(function () {
                if (currentIndex >= maxIndex) {
                    currentIndex = 0;
                } else {
                    currentIndex++;
                }
                updateSlider();
            }, 5000); // 5秒ごと
        }

        function stopAutoSlide() {
            clearInterval(autoSlideInterval);
        }

        // マウスホバーで自動スライド停止
        $slider.on('mouseenter', stopAutoSlide);
        $slider.on('mouseleave', startAutoSlide);

        // 初期化
        updateSlider();

        // 記事が3つ以上ある場合のみ自動スライド開始
        if ($items.length > itemsToShow) {
            startAutoSlide();
        }

        // スライダーアイテムクリック追跡
        $items.on('click', 'a', function () {
            if (typeof gtag !== 'undefined') {
                gtag('event', 'click', {
                    'event_category': 'Kashiwazaki Related Posts',
                    'event_label': 'Slider Item Click'
                });
            }
        });
    });

});
