<?php
/**
 * Scheduler Class
 * مدیریت زمان‌بندی و WP-Cron
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Scheduler {
    
    const CRON_HOOK = 'fdu_cron_upload_event';
    const ASYNC_HOOK = 'fdu_async_run_event';
    
    /**
     * ثبت هوک‌های زمان‌بندی
     */
    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_backup']);
        add_action(self::ASYNC_HOOK, [__CLASS__, 'run_backup']);
        add_action('fdu_single_test_event', [__CLASS__, 'test_event']);
    }
    
    /**
     * زمان‌بندی بر اساس تنظیمات
     * 
     * @param array $options تنظیمات افزونه
     */
    public static function schedule($options) {
        // پاک کردن زمان‌بندی قبلی
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        // محاسبه زمان اجرای بعدی
        $next_time = self::calculate_next_run($options);
        
        // ثبت رویداد جدید
        wp_schedule_single_event($next_time, self::CRON_HOOK);
        
        FDU_Logger::log(
            'زمان‌بندی بازتنظیم شد. اجرای بعدی: ' . 
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_time), 'Y-m-d H:i:s')
        );
    }
    
    /**
     * محاسبه زمان اجرای بعدی
     * 
     * @param array $options
     * @return int Unix timestamp
     */
    public static function calculate_next_run($options) {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        
        $hour = isset($options['hour']) ? intval($options['hour']) : 3;
        $min = isset($options['minute']) ? intval($options['minute']) : 0;
        $freq = isset($options['frequency']) ? $options['frequency'] : 'daily';
        
        if ($freq === 'weekly') {
            return self::calculate_weekly($now, $hour, $min, $options);
        } else {
            return self::calculate_daily($now, $hour, $min);
        }
    }
    
    /**
     * محاسبه زمان‌بندی روزانه
     */
    private static function calculate_daily($now, $hour, $min) {
        $target = $now->setTime($hour, $min, 0);
        
        // اگر زمان هدف گذشته، به فردا می‌رویم
        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }
        
        return $target->getTimestamp();
    }
    
    /**
     * محاسبه زمان‌بندی هفتگی
     */
    private static function calculate_weekly($now, $hour, $min, $options) {
        $wday = isset($options['weekday']) ? intval($options['weekday']) : 6; // شنبه
        $today_w = intval($now->format('w'));
        
        $days_ahead = ($wday - $today_w + 7) % 7;
        
        $target = $now->setTime($hour, $min, 0);
        
        // اگر همون امروز است
        if ($days_ahead === 0) {
            // اگر ساعت گذشته، بریم هفته بعد
            if ($target <= $now) {
                $days_ahead = 7;
            }
        } else {
            // برای روزهای دیگر، چک کنیم که زمان از الان جلوتر باشه
            $test_target = $target->modify("+{$days_ahead} days");
            if ($test_target <= $now) {
                $days_ahead += 7;
            }
        }
        
        $final = $target->modify("+{$days_ahead} days");
        
        return $final->getTimestamp();
    }
    
    /**
     * زمان‌بندی پس‌زمینه (بعد از 5 ثانیه)
     */
    public static function schedule_async() {
        $ts = time() + 5;
        wp_schedule_single_event($ts, self::ASYNC_HOOK);
        
        FDU_Logger::log(
            'اجرای پس‌زمینه زمان‌بندی شد برای ' .
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s')
        );
        
        // تریگر کران
        self::trigger_cron();
    }
    
    /**
     * زمان‌بندی تست (بعد از 2 دقیقه)
     */
    public static function schedule_test() {
        $ts = time() + 120;
        wp_schedule_single_event($ts, 'fdu_single_test_event');
        
        FDU_Logger::log(
            'تست زمان‌بندی شد برای ' .
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s')
        );
        
        self::trigger_cron();
    }
    
    /**
     * تریگر دستی WP-Cron
     */
    public static function trigger_cron() {
        if (!function_exists('spawn_cron')) {
            require_once ABSPATH . 'wp-includes/cron.php';
        }
        
        @spawn_cron();
        
        // Loopback non-blocking
        wp_remote_get(
            site_url('wp-cron.php?doing_wp_cron=' . microtime(true)),
            ['timeout' => 0.01, 'blocking' => false, 'sslverify' => false]
        );
    }
    
    /**
     * اجرای بکاپ (callback برای هوک‌های کران)
     */
    public static function run_backup() {
        // این متد توسط کلاس اصلی override می‌شود
        do_action('fdu_before_backup');
        
        // اینجا کلاس Backup کار خودش رو انجام میده
        
        do_action('fdu_after_backup');
    }
    
    /**
     * رویداد تست
     */
    public static function test_event() {
        FDU_Logger::success('✅ fdu_single_test_event اجرا شد.');
    }
    
    /**
     * وضعیت زمان‌بندی فعلی
     * 
     * @return array
     */
    public static function get_status() {
        $next = wp_next_scheduled(self::CRON_HOOK);
        $next_async = wp_next_scheduled(self::ASYNC_HOOK);
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $lock = get_transient('doing_cron');
        
        return [
            'next_scheduled' => $next,
            'next_scheduled_formatted' => $next ? 
                get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i:s') : 
                null,
            'next_async' => $next_async,
            'wp_cron_disabled' => $disabled,
            'cron_lock' => $lock,
        ];
    }
    
    /**
     * پاک کردن تمام زمان‌بندی‌ها
     */
    public static function clear_all() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        wp_clear_scheduled_hook('fdu_single_test_event');
    }
}
