<?php
if (!defined('ABSPATH')) exit;
?>
<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-cloud-upload"></span>
            اطلاعات اتصال به Files.ir
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>نکته:</strong> اطلاعات API خود را از پنل Files.ir دریافت کنید.</p>
        </div>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_api', 'fdu_api_section'); ?>
        </table>
    </div>
    
    <?php submit_button('ذخیره تنظیمات API'); ?>
</form>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-admin-tools"></span>
        تست اتصال
    </h2>
    
    <div class="fdu-button-group">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_test_small'), 'fdu_test')); ?>" 
           class="button button-secondary">
            <span class="dashicons dashicons-upload"></span>
            آپلود فایل تستی
        </a>
    </div>
</div>
