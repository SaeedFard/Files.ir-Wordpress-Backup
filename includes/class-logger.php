<?php
/**
 * Logger Class
 * مدیریت لاگ‌های افزونه
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Logger {
    
    /**
     * مسیر پوشه آپلودهای افزونه
     * 
     * @return string
     */
    public static function uploads_dir() {
        $u = wp_upload_dir();
        $new = trailingslashit($u['basedir']) . 'files-ir-wordpress-backup';
        $old = trailingslashit($u['basedir']) . 'files-db-uploader';
        
        // اگر پوشه جدید وجود داره یا می‌تونیم بسازیمش
        if (file_exists($new) || (!file_exists($old) && wp_mkdir_p($new))) {
            return $new;
        }
        
        // اگر پوشه قدیمی موجود بود و جدید نبود، از قدیمی استفاده می‌کنیم
        return $old;
    }
    
    /**
     * مسیر فایل لاگ
     * 
     * @return string
     */
    public static function logs_path() {
        return trailingslashit(self::uploads_dir()) . 'logs.txt';
    }
    
    /**
     * نوشتن لاگ
     * 
     * @param string $message پیام لاگ
     * @param string $level سطح لاگ (info, warning, error, success)
     */
    public static function log($message, $level = 'info') {
        $time = current_time('Y-m-d H:i:s');
        $prefix = self::get_level_prefix($level);
        $formatted = "[{$time}] {$prefix}{$message}" . PHP_EOL;
        
        @file_put_contents(
            self::logs_path(),
            $formatted,
            FILE_APPEND | LOCK_EX
        );
    }
    
    /**
     * لاگ موفقیت
     */
    public static function success($message) {
        self::log($message, 'success');
    }
    
    /**
     * لاگ خطا
     */
    public static function error($message) {
        self::log($message, 'error');
    }
    
    /**
     * لاگ هشدار
     */
    public static function warning($message) {
        self::log($message, 'warning');
    }
    
    /**
     * پاک کردن محتوای لاگ
     */
    public static function clear() {
        $path = self::logs_path();
        if (file_exists($path)) {
            @file_put_contents($path, '');
            self::log('لاگ پاک‌سازی شد.');
        }
    }
    
    /**
     * حذف فایل لاگ
     */
    public static function delete() {
        $path = self::logs_path();
        if (file_exists($path)) {
            @unlink($path);
        }
    }
    
    /**
     * خواندن لاگ
     * 
     * @param int $lines تعداد خطوط از انتها (0 = همه)
     * @return string
     */
    public static function read($lines = 0) {
        $path = self::logs_path();
        
        if (!file_exists($path)) {
            return '';
        }
        
        $content = @file_get_contents($path);
        
        if ($lines > 0) {
            $all_lines = explode(PHP_EOL, $content);
            $last_lines = array_slice($all_lines, -$lines);
            return implode(PHP_EOL, $last_lines);
        }
        
        return $content;
    }
    
    /**
     * پیشوند بر اساس سطح لاگ
     * 
     * @param string $level
     * @return string
     */
    private static function get_level_prefix($level) {
        $prefixes = [
            'success' => '✅ ',
            'error'   => '❌ ',
            'warning' => '⚠️ ',
            'info'    => ''
        ];
        
        return isset($prefixes[$level]) ? $prefixes[$level] : '';
    }
}
