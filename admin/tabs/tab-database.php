<?php
/**
 * Database Backup Settings Tab
 * تنظیمات بکاپ دیتابیس
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;
?>

<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-database"></span>
            روش خروجی‌گیری از دیتابیس
        </h2>
        
        <div class="fdu-info-box">
            <p><strong>نکته:</strong> افزونه به صورت خودکار از دیتابیس شما بکاپ می‌گیرد.</p>
            <p>دو روش برای خروجی‌گیری وجود دارد:</p>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>mysqldump:</strong> سریع‌تر و کارآمدتر (نیاز به دسترسی به shell)</li>
                <li><strong>PHP Export:</strong> کندتر ولی همیشه در دسترس</li>
            </ul>
        </div>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_database', 'fdu_database_section'); ?>
        </table>
        
        <div class="fdu-info-box warning">
            <p><strong>⚠️ نکته مهم:</strong></p>
            <p>اگر mysqldump در سرور شما در دسترس نباشد، افزونه به صورت خودکار از روش PHP استفاده می‌کند.</p>
        </div>
    </div>
    
    <?php submit_button('ذخیره تنظیمات دیتابیس'); ?>
</form>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-info"></span>
        اطلاعات دیتابیس فعلی
    </h2>
    
    <table class="widefat striped">
        <tbody>
            <tr>
                <td style="width: 200px;"><strong>نام دیتابیس</strong></td>
                <td><code><?php echo esc_html(DB_NAME); ?></code></td>
            </tr>
            <tr>
                <td><strong>هاست</strong></td>
                <td><code><?php echo esc_html(DB_HOST); ?></code></td>
            </tr>
            <tr>
                <td><strong>Charset</strong></td>
                <td><code><?php echo esc_html(DB_CHARSET ?: 'utf8mb4'); ?></code></td>
            </tr>
            <tr>
                <td><strong>Table Prefix</strong></td>
                <td><code><?php global $wpdb; echo esc_html($wpdb->prefix); ?></code></td>
            </tr>
            <tr>
                <td><strong>تعداد جداول</strong></td>
                <td>
                    <?php
                    global $wpdb;
                    $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
                    echo esc_html(count($tables));
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>mysqldump در دسترس؟</strong></td>
                <td>
                    <?php
                    $mysqldump_paths = [
                        'mysqldump',
                        '/usr/bin/mysqldump',
                        '/usr/local/bin/mysqldump',
                        'C:\\xampp\\mysql\\bin\\mysqldump.exe'
                    ];
                    
                    $found = false;
                    foreach ($mysqldump_paths as $path) {
                        $cmd = (stripos(PHP_OS, 'WIN') === 0) ? 
                            'where ' . escapeshellarg($path) : 
                            'command -v ' . escapeshellarg($path);
                        
                        $result = @shell_exec($cmd);
                        if ($result) {
                            $found = trim($result);
                            break;
                        }
                    }
                    
                    if ($found): ?>
                        <span style="color: #2c7;">✓</span> بله - <code><?php echo esc_html($found); ?></code>
                    <?php else: ?>
                        <span style="color: #d63638;">✗</span> خیر (از روش PHP استفاده خواهد شد)
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="fdu-section" style="margin-top: 30px;">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-admin-tools"></span>
        راهنما
    </h2>
    
    <h3>مقایسه روش‌های بکاپ:</h3>
    
    <table class="widefat">
        <thead>
            <tr>
                <th style="width: 150px;">ویژگی</th>
                <th>mysqldump</th>
                <th>PHP Export</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>سرعت</strong></td>
                <td><span style="color: #2c7;">✓</span> خیلی سریع</td>
                <td><span style="color: #d63638;">✗</span> کندتر</td>
            </tr>
            <tr>
                <td><strong>مصرف حافظه</strong></td>
                <td><span style="color: #2c7;">✓</span> کم</td>
                <td><span style="color: #d63638;">✗</span> بیشتر</td>
            </tr>
            <tr>
                <td><strong>نیازمندی‌ها</strong></td>
                <td>دسترسی به shell</td>
                <td>فقط PHP</td>
            </tr>
            <tr>
                <td><strong>قابلیت اطمینان</strong></td>
                <td><span style="color: #2c7;">✓</span> بالا</td>
                <td><span style="color: #2c7;">✓</span> متوسط</td>
            </tr>
            <tr>
                <td><strong>پشتیبانی Routines/Events</strong></td>
                <td><span style="color: #2c7;">✓</span> بله</td>
                <td><span style="color: #d63638;">✗</span> خیر</td>
            </tr>
        </tbody>
    </table>
    
    <div class="fdu-info-box" style="margin-top: 20px;">
        <p><strong>💡 توصیه:</strong></p>
        <ul style="margin: 10px 0 0 20px;">
            <li>اگر mysqldump در دسترس هست، حتماً استفاده کنید (سریع‌تر و قابل اعتمادتر)</li>
            <li>برای دیتابیس‌های بزرگ (بیش از 100MB)، حتماً از mysqldump استفاده کنید</li>
            <li>اگر خطای timeout می‌گیرید، از Worker URL در تب "پیشرفته" استفاده کنید</li>
        </ul>
    </div>
</div>
