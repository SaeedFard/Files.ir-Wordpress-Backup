/**
 * Admin JavaScript
 * مدیریت تعاملات صفحه تنظیمات
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Toggle weekday field based on frequency
        function toggleWeekdayField() {
            var frequency = $('#fdu_settings_frequency').val();
            var weekdayRow = $('#fdu_settings_weekday').closest('tr');
            
            if (frequency === 'weekly') {
                weekdayRow.fadeIn(200);
            } else {
                weekdayRow.fadeOut(200);
            }
        }
        
        // اجرای اولیه
        toggleWeekdayField();
        
        // وقتی frequency عوض می‌شه
        $('#fdu_settings_frequency').on('change', toggleWeekdayField);
        
        
        // Copy to clipboard functionality
        $('.fdu-copy-button').on('click', function(e) {
            e.preventDefault();
            var text = $(this).data('copy');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    showNotice('کپی شد!', 'success');
                });
            } else {
                // Fallback for older browsers
                var temp = $('<textarea>');
                $('body').append(temp);
                temp.val(text).select();
                document.execCommand('copy');
                temp.remove();
                showNotice('کپی شد!', 'success');
            }
        });
        
        
        // Show temporary notice
        function showNotice(message, type) {
            var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.fdu-settings-wrap h1').after(notice);
            
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
        
        
        // Confirm before regenerating worker key
        $('a[href*="fdu_regen_key"]').on('click', function(e) {
            if (!confirm('آیا مطمئن هستید؟ پس از تغییر کلید، باید اسکریپت‌های cron خود را به‌روزرسانی کنید.')) {
                e.preventDefault();
                return false;
            }
        });
        
        
        // Auto-save notice when settings changed
        var settingsChanged = false;
        $('.fdu-tab-content form input, .fdu-tab-content form select, .fdu-tab-content form textarea').on('change', function() {
            if (!settingsChanged) {
                settingsChanged = true;
                $('.fdu-tab-content form .submit').css('background', '#fff3cd');
            }
        });
        
        
        // Tab switching with hash support
        function switchTab(tabName) {
            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[href*="tab=' + tabName + '"]').addClass('nav-tab-active');
        }
        
        // Handle hash changes
        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            if (hash.startsWith('tab-')) {
                var tabName = hash.replace('tab-', '');
                switchTab(tabName);
            }
        }
        
    });
    
})(jQuery);
