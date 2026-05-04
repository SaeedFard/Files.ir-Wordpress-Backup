<?php
if (!defined('ABSPATH')) exit;
$opts = get_option('fdu_settings', []);
$parent_id = isset($opts['parent_folder_id']) ? intval($opts['parent_folder_id']) : 0;
$parent_path = isset($opts['parent_folder_path']) ? $opts['parent_folder_path'] : 'wp-backups';
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
        <span class="dashicons dashicons-portfolio"></span>
        پوشه مقصد در Files.ir
    </h2>
    
    <div class="fdu-info-box">
        <p>پلاگین به‌صورت خودکار یک پوشه با نام <code><?php echo esc_html($parent_path); ?></code> در Files.ir می‌سازد و بکاپ‌ها را داخل آن آپلود می‌کند.</p>
        <?php if ($parent_id > 0): ?>
            <p>📁 پوشه مقصد ساخته شده است (ID: <code><?php echo esc_html($parent_id); ?></code>)</p>
        <?php else: ?>
            <p>📂 پوشه هنوز ساخته نشده است. در اولین بکاپ ساخته می‌شود.</p>
        <?php endif; ?>
        <p>برای تغییر مسیر، گزینه «بازنشانی پوشه» را بزنید و مسیر جدید را در تب «پیشرفته» وارد کنید.</p>
    </div>
    
    <?php if ($parent_id > 0): ?>
        <div class="fdu-button-group">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_reset_folder'), 'fdu_reset_folder')); ?>" 
               class="button"
               onclick="return confirm('کش پوشه مقصد پاک شود؟ در بکاپ بعدی پوشه مجدداً پیدا یا ساخته می‌شود.')">
                <span class="dashicons dashicons-update"></span>
                بازنشانی کش پوشه
            </a>
        </div>
    <?php endif; ?>
</div>

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
