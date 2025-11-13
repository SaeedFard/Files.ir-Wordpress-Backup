<?php
/**
 * Schedule Settings Tab
 * تنظیمات زمان‌بندی بکاپ
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

$status = FDU_Scheduler::get_status();
?>

<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-clock"></span>
            تنظیمات زمان‌بندی خودکار
        </h2>
        
        <?php if ($status['next_scheduled_formatted']): ?>
            <div class="fdu-info-box success">
                <p><strong>✓ زمان‌بندی فعال است</strong></p>
                <p>اجرای بعدی: <strong><?php echo esc_html($status['next_scheduled_formatted']); ?></strong></p>
            </div>
        <?php else: ?>
            <div class="fdu-info-box warning">
                <p><strong>⚠ زمان‌بندی فعال نیست</strong></p>
                <p>بعد از ذخیره تنظیمات، زمان‌بندی خودکار فعال خواهد شد.</p>
            </div>
        <?php endif; ?>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_schedule', 'fdu_schedule_section'); ?>
        </table>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-email"></span>
            اعلان‌ها
        </h2>
        
        <p class="description">در صورت تمایل، یک ایمیل برای دریافت گزارش بکاپ وارد کنید.</p>
    </div>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-download"></span>
            نگهداری نسخه‌های محلی
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>نکته:</strong> اگر فعال باشد، فایل‌های بکاپ علاوه بر آپلود به Files.ir، روی سرور شما هم نگهداری می‌شوند.</p>
            <p>تعداد نسخه‌های قدیمی‌تر از حد مشخص شده، به صورت خودکار حذف خواهند شد.</p>
        </div>
    </div>
    
    <?php submit_button('ذخیره تنظیمات زمان‌بندی'); ?>
</form>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-info"></span>
        راهنمای زمان‌بندی
    </h2>
    
    <table class="widefat">
        <thead>
            <tr>
                <th>نوع</th>
                <th>توضیحات</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>روزانه</strong></td>
                <td>بکاپ هر روز در ساعت مشخص شده اجرا می‌شود</td>
            </tr>
            <tr>
                <td><strong>هفتگی</strong></td>
                <td>بکاپ فقط در روز و ساعت مشخص شده از هفته اجرا می‌شود</td>
            </tr>
        </tbody>
    </table>
    
    <div class="fdu-info-box warning" style="margin-top: 15px;">
        <p><strong>⚠ نکته مهم درباره WP-Cron:</strong></p>
        <ul style="margin: 10px 0 0 20px;">
            <li>WP-Cron وردپرس به بازدید سایت وابسته است</li>
            <li>اگر سایت شما ترافیک کمی دارد، ممکن است زمان‌بندی دقیق اجرا نشود</li>
            <li>برای اجرای دقیق و مستقل، از <strong>Worker URL</strong> در تب پیشرفته استفاده کنید</li>
        </ul>
    </div>
</div>
