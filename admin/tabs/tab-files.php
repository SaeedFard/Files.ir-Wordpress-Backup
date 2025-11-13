<?php
/**
 * Files Backup Settings Tab
 * تنظیمات بکاپ فایل‌های وردپرس
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

$options = get_option('fdu_settings', []);
?>

<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-portfolio"></span>
            فعال‌سازی بکاپ فایل‌ها
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>توضیح:</strong> بکاپ از فایل‌های مهم وردپرس شامل uploads، themes، plugins و...</p>
            <p>اگر فعال باشد، یک فایل آرشیو (ZIP یا TAR.GZ) از مسیرهای انتخابی ساخته و به Files.ir آپلود می‌شود.</p>
        </div>
        
        <table class="form-table fdu-form-table">
            <tr>
                <th scope="row">
                    <label for="fdu_settings_enable_files_backup">فعال‌سازی</label>
                </th>
                <td>
                    <?php
                    $enabled = isset($options['enable_files_backup']) ? intval($options['enable_files_backup']) : 1;
                    ?>
                    <input type="hidden" name="fdu_settings[enable_files_backup]" value="0" />
                    <input type="checkbox" 
                           id="fdu_settings_enable_files_backup" 
                           name="fdu_settings[enable_files_backup]" 
                           value="1" 
                           <?php checked($enabled, 1); ?> />
                    <label for="fdu_settings_enable_files_backup">فعال کردن بکاپ فایل‌ها</label>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-archive"></span>
            فرمت آرشیو
        </h2>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_files', 'fdu_files_section'); ?>
        </table>
        
        <div class="fdu-info-box warning">
            <p><strong>نکات مهم:</strong></p>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>ZIP:</strong> سازگار با همه سیستم‌ها، نیاز به افزونه PHP Zip</li>
                <li><strong>TAR.GZ:</strong> فشرده‌سازی بهتر، نیاز به PharData و <code>phar.readonly = Off</code></li>
                <li>در صورت عدم دسترسی به TAR.GZ، به صورت خودکار به ZIP سوئیچ می‌شود</li>
            </ul>
        </div>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-category"></span>
            انتخاب فایل‌ها و پوشه‌ها
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>نحوه استفاده:</strong></p>
            <ul style="margin: 10px 0 0 20px;">
                <li>هر مسیر را در یک خط جداگانه وارد کنید</li>
                <li>مسیرها باید نسبت به ریشه وردپرس باشند</li>
                <li>مثال: <code>wp-content/uploads</code></li>
            </ul>
        </div>
        
        <p class="description">
            <strong>پیش‌فرض:</strong><br>
            • wp-content/uploads<br>
            • wp-content/themes<br>
            • wp-content/plugins
        </p>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-dismiss"></span>
            حذف فایل‌های غیرضروری
        </h2>
        
        <div class="fdu-info-box warning">
            <p><strong>الگوهای پشتیبانی شده:</strong></p>
            <ul style="margin: 10px 0 0 20px;">
                <li><code>*</code> - هر تعداد کاراکتر</li>
                <li><code>?</code> - یک کاراکتر</li>
                <li>مثال: <code>cache</code>, <code>*.log</code>, <code>node_modules</code></li>
            </ul>
        </div>
        
        <p class="description">
            فایل‌ها و پوشه‌هایی که مطابق با الگوهای زیر باشند، از بکاپ حذف می‌شوند:
        </p>
        
        <div style="margin: 15px 0;">
            <strong>پیشنهادی برای حذف:</strong>
            <ul style="margin: 10px 0 0 20px; column-count: 2;">
                <li>cache / caches</li>
                <li>node_modules</li>
                <li>vendor</li>
                <li>.git / .svn</li>
                <li>*.log</li>
                <li>*.tmp</li>
                <li>.DS_Store</li>
            </ul>
        </div>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-admin-generic"></span>
            فایل‌های خاص
        </h2>
        
        <p class="description">می‌توانید فایل‌های تنظیمات مهم وردپرس را هم به بکاپ اضافه کنید:</p>
    </div>
    
    <?php submit_button('ذخیره تنظیمات بکاپ فایل‌ها'); ?>
</form>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-info"></span>
        توضیحات تکمیلی
    </h2>
    
    <div class="fdu-info-box">
        <p><strong>💡 نکته:</strong> اگر سایت شما حجم فایل زیادی دارد، توصیه می‌شود:</p>
        <ul style="margin: 10px 0 0 20px;">
            <li>فقط پوشه‌های ضروری را انتخاب کنید</li>
            <li>از الگوهای حذف برای فایل‌های بزرگ استفاده کنید</li>
            <li>در تب "پیشرفته" روش آپلود را روی <strong>Stream</strong> تنظیم کنید</li>
            <li>از Worker URL برای اجرای بکاپ بدون timeout استفاده کنید</li>
        </ul>
    </div>
    
    <h3 style="margin-top: 20px;">نمونه مسیرهای رایج:</h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>مسیر</th>
                <th>توضیحات</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>wp-content/uploads</code></td>
                <td>فایل‌های آپلودی (تصاویر، ویدیوها، ...)</td>
            </tr>
            <tr>
                <td><code>wp-content/themes</code></td>
                <td>قالب‌های سایت</td>
            </tr>
            <tr>
                <td><code>wp-content/plugins</code></td>
                <td>افزونه‌های سایت</td>
            </tr>
            <tr>
                <td><code>wp-config.php</code></td>
                <td>فایل تنظیمات اصلی وردپرس</td>
            </tr>
            <tr>
                <td><code>.htaccess</code></td>
                <td>تنظیمات سرور آپاچی</td>
            </tr>
        </tbody>
    </table>
</div>
