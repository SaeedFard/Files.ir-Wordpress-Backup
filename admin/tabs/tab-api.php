<?php
/**
 * API Settings Tab
 * تنظیمات اتصال به Files.ir API
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;
?>

<form method="post" action="options.php">
    <?php
    settings_fields('fdu_settings_group');
    ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-cloud-upload"></span>
            اطلاعات اتصال به Files.ir
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>نکته:</strong> اطلاعات API خود را از پنل Files.ir دریافت کنید.</p>
            <p>مستندات API: <a href="https://files.ir/api-docs" target="_blank">https://files.ir/api-docs</a></p>
        </div>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_api', 'fdu_api_section'); ?>
        </table>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-location"></span>
            مقصد آپلود
        </h2>
        
        <div class="fdu-info-box warning">
            <p><strong>هدف‌گذاری پوشه:</strong></p>
            <ul style="margin: 10px 0 0 20px;">
                <li>از فیلد <code>relativePath</code> برای مشخص کردن مسیر پوشه مقصد استفاده کنید</li>
                <li>یا از <code>parentId</code> در فیلد "فیلدهای اضافه" استفاده کنید</li>
                <li>مثال: <code>{"parentId": 11848}</code></li>
            </ul>
        </div>
        
        <p class="description">
            فایل‌های بکاپ به مسیر مشخص شده در Files.ir آپلود خواهند شد.
        </p>
    </div>
    
    <?php submit_button('ذخیره تنظیمات API'); ?>
</form>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-admin-tools"></span>
        تست اتصال
    </h2>
    
    <p>قبل از شروع بکاپ، می‌توانید اتصال به API را تست کنید:</p>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_test_small'), 'fdu_test')); ?>" 
           class="button button-secondary">
            <span class="dashicons dashicons-upload" style="margin-top: 3px;"></span>
            آپلود فایل تستی
        </a>
    </div>
    
    <p class="description">
        یک فایل کوچک با محتوای تستی به Files.ir آپلود می‌شود تا اتصال بررسی شود.
    </p>
</div>
