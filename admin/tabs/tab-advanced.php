<?php
/**
 * Advanced Settings Tab
 * تنظیمات پیشرفته، Worker URL، و لاگ‌ها
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

$options = get_option('fdu_settings', []);
$worker_url = add_query_arg(
    ['action' => 'fdu_worker', 'key' => $options['bg_key'] ?? ''],
    admin_url('admin-post.php')
);
?>

<!-- Worker URL Section -->
<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-admin-generic"></span>
        Worker URL (اجرای مستقیم)
    </h2>
    
    <div class="fdu-info-box">
        <p><strong>استفاده از Worker URL:</strong></p>
        <ul style="margin: 10px 0 0 20px;">
            <li>برای اجرای دقیق و مستقل از ترافیک سایت</li>
            <li>قابل استفاده در Cron Job سرور</li>
            <li>نیاز به کلید مخفی برای امنیت</li>
        </ul>
    </div>
    
    <table class="form-table">
        <tr>
            <th>Worker URL:</th>
            <td>
                <div class="fdu-code-block" style="margin-bottom: 10px;">
                    <?php echo esc_html($worker_url); ?>
                </div>
                <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('<?php echo esc_js($worker_url); ?>')">
                    <span class="dashicons dashicons-clipboard"></span>
                    کپی URL
                </button>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_regen_key'), 'fdu_regen')); ?>" 
                   class="button"
                   onclick="return confirm('کلید Worker عوض شود؟ باید اسکریپت‌های cron خود را هم به‌روزرسانی کنید.')">
                    <span class="dashicons dashicons-update"></span>
                    تولید کلید جدید
                </a>
            </td>
        </tr>
        <tr>
            <th>مثال Cron Job:</th>
            <td>
                <code style="display: block; background: #f6f7f7; padding: 10px; border-radius: 4px;">
                    0 3 * * * curl -s "<?php echo esc_url($worker_url); ?>" > /dev/null 2>&1
                </code>
                <span class="description">اجرای روزانه در ساعت 3 صبح</span>
            </td>
        </tr>
    </table>
</div>

<!-- Upload Settings -->
<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-upload"></span>
            تنظیمات آپلود
        </h2>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_advanced', 'fdu_advanced_section'); ?>
        </table>
        
        <div class="fdu-info-box warning">
            <p><strong>نکات مهم:</strong></p>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>Stream:</strong> روش پیشنهادی برای فایل‌های بزرگ (بهینه‌تر و سریع‌تر)</li>
                <li><strong>Chunked:</strong> اگر سرور محدودیت اندازه فایل دارد</li>
                <li><strong>حالت سازگاری:</strong> برای سرورهای حساس به هدرهای HTTP</li>
            </ul>
        </div>
    </div>
    
    <?php submit_button('ذخیره تنظیمات پیشرفته'); ?>
</form>

<!-- Manual Actions -->
<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-controls-play"></span>
        عملیات دستی
    </h2>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_run_async'), 'fdu_run_async')); ?>" 
           class="button button-primary">
            <span class="dashicons dashicons-backup"></span>
            اجرای بکاپ در پس‌زمینه (WP-Cron)
        </a>
        
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_run_bg_direct'), 'fdu_run_async')); ?>" 
           class="button button-secondary">
            <span class="dashicons dashicons-controls-forward"></span>
            اجرای مستقیم (بدون WP-Cron)
        </a>
        
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_test_small'), 'fdu_test')); ?>" 
           class="button">
            <span class="dashicons dashicons-yes-alt"></span>
            تست آپلود کوچک
        </a>
    </div>
</div>

<!-- Health Check -->
<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-heart"></span>
        بررسی سلامت WP-Cron
    </h2>
    
    <?php
    $status = FDU_Scheduler::get_status();
    $now = current_time('timestamp');
    ?>
    
    <table class="widefat striped">
        <tbody>
            <tr>
                <td style="width: 300px;"><strong>زمان وردپرس</strong></td>
                <td><?php echo wp_date('Y-m-d H:i:s', $now); ?></td>
            </tr>
            <tr>
                <td><strong>نوبت بعدی زمان‌بندی</strong></td>
                <td>
                    <?php if ($status['next_scheduled_formatted']): ?>
                        <span style="color: #2c7;">✓</span> <?php echo esc_html($status['next_scheduled_formatted']); ?>
                    <?php else: ?>
                        <span style="color: #d63638;">✗</span> <strong>ثبت نشده</strong>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>DISABLE_WP_CRON</strong></td>
                <td>
                    <?php if ($status['wp_cron_disabled']): ?>
                        <span style="color: #d63638;">✗</span> فعال (WP-Cron غیرفعال)
                    <?php else: ?>
                        <span style="color: #2c7;">✓</span> غیرفعال
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>کران لاک</strong></td>
                <td><?php echo $status['cron_lock'] ? esc_html($status['cron_lock']) : '—'; ?></td>
            </tr>
        </tbody>
    </table>
    
    <div class="fdu-button-group" style="margin-top: 15px;">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_trigger_wpcron'), 'fdu_health')); ?>" 
           class="button">
            اجرای WP-Cron همین حالا
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_single_test'), 'fdu_health')); ?>" 
           class="button">
            تست ۲ دقیقه‌ای WP-Cron
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_reschedule'), 'fdu_health')); ?>" 
           class="button">
            بازتنظیم زمان‌بندی
        </a>
    </div>
</div>

<!-- Logs Section -->
<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-media-text"></span>
        لاگ‌ها
    </h2>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_clear_log'), 'fdu_log')); ?>" 
           class="button">
            <span class="dashicons dashicons-trash"></span>
            پاک‌سازی لاگ
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_delete_log'), 'fdu_log')); ?>" 
           class="button" 
           onclick="return confirm('فایل لاگ حذف شود؟')">
            <span class="dashicons dashicons-dismiss"></span>
            حذف فایل لاگ
        </a>
    </div>
    
    <textarea readonly rows="15" style="width: 100%; font-family: monospace; margin-top: 10px; padding: 10px; background: #f6f7f7; border: 1px solid #c3c4c7;"><?php 
        echo esc_textarea(FDU_Logger::read());
    ?></textarea>
    
    <p class="description">مسیر لاگ: <code><?php echo esc_html(FDU_Logger::logs_path()); ?></code></p>
</div>
