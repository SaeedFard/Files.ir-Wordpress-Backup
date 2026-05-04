<?php
if (!defined('ABSPATH')) exit;
$options = get_option('fdu_settings', []);
$worker_url = add_query_arg(
    ['action' => 'fdu_worker', 'key' => $options['bg_key'] ?? ''],
    admin_url('admin-post.php')
);
?>

<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-admin-generic"></span>
        Worker URL
    </h2>
    
    <table class="form-table">
        <tr>
            <th>Worker URL:</th>
            <td>
                <div class="fdu-code-block"><?php echo esc_html($worker_url); ?></div>
                <div class="fdu-button-group" style="margin-top: 10px;">
                    <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('<?php echo esc_js($worker_url); ?>')">
                        کپی URL
                    </button>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_regen_key'), 'fdu_regen')); ?>" 
                       class="button"
                       onclick="return confirm('کلید Worker عوض شود؟')">
                        تولید کلید جدید
                    </a>
                </div>
            </td>
        </tr>
    </table>
</div>

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
    </div>
    
    <?php submit_button('ذخیره تنظیمات پیشرفته'); ?>
</form>

<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-controls-play"></span>
        عملیات دستی
    </h2>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_run_async'), 'fdu_run_async')); ?>" 
           class="button button-primary">
            اجرای بکاپ در پس‌زمینه
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_test_small'), 'fdu_test')); ?>" 
           class="button">
            تست آپلود
        </a>
    </div>
</div>

<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-media-text"></span>
        لاگ‌ها
    </h2>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_clear_log'), 'fdu_log')); ?>" 
           class="button">پاک‌سازی لاگ</a>
    </div>
    
    <textarea readonly rows="15" style="width: 100%; font-family: monospace; margin-top: 10px;"><?php 
        echo esc_textarea(FDU_Logger::read());
    ?></textarea>
</div>
