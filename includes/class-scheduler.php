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
    const PING_HOOK = 'fdu_self_ping_event';
    
    public static function init() {
        add_action('fdu_single_test_event', [__CLASS__, 'test_event']);
        add_action(self::PING_HOOK, [__CLASS__, 'self_ping']);
    }
    
    public static function schedule($options) {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::PING_HOOK);
        
        $next_time = self::calculate_next_run($options);
        
        wp_schedule_single_event($next_time, self::CRON_HOOK);
        
        // زمان‌بندی self-ping: 30 ثانیه بعد از زمان اصلی
        wp_schedule_single_event($next_time + 30, self::PING_HOOK);
        
        FDU_Logger::log(
            'زمان‌بندی بازتنظیم شد. اجرای بعدی: ' . 
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_time), 'Y-m-d H:i:s')
        );
        
        // تریگر WP-Cron برای اطمینان از اجرا
        self::trigger_cron();
    }
    
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
    
    private static function calculate_daily($now, $hour, $min) {
        $target = $now->setTime($hour, $min, 0);
        
        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }
        
        return $target->getTimestamp();
    }
    
    private static function calculate_weekly($now, $hour, $min, $options) {
        $wday = isset($options['weekday']) ? intval($options['weekday']) : 6;
        $today_w = intval($now->format('w'));
        
        $days_ahead = ($wday - $today_w + 7) % 7;
        
        $target = $now->setTime($hour, $min, 0);
        
        if ($days_ahead === 0) {
            if ($target <= $now) {
                $days_ahead = 7;
            }
        } else {
            $test_target = $target->modify("+{$days_ahead} days");
            if ($test_target <= $now) {
                $days_ahead += 7;
            }
        }
        
        $final = $target->modify("+{$days_ahead} days");
        
        return $final->getTimestamp();
    }
    
    public static function schedule_async() {
        $ts = time() + 5;
        wp_schedule_single_event($ts, self::ASYNC_HOOK);
        
        // Self-ping برای async
        wp_schedule_single_event($ts + 10, self::PING_HOOK);
        
        FDU_Logger::log(
            'اجرای پس‌زمینه زمان‌بندی شد برای ' .
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s')
        );
        
        self::trigger_cron();
    }
    
    public static function schedule_test() {
        $ts = time() + 120;
        wp_schedule_single_event($ts, 'fdu_single_test_event');
        
        // Self-ping برای تست
        wp_schedule_single_event($ts + 10, self::PING_HOOK);
        
        FDU_Logger::log(
            'تست زمان‌بندی شد برای ' .
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s')
        );
        
        self::trigger_cron();
    }
    
    public static function trigger_cron() {
        if (!function_exists('spawn_cron')) {
            require_once ABSPATH . 'wp-includes/cron.php';
        }
        
        @spawn_cron();
        
        wp_remote_get(
            site_url('wp-cron.php?doing_wp_cron=' . microtime(true)),
            ['timeout' => 0.01, 'blocking' => false, 'sslverify' => false]
        );
    }
    
    /**
     * Self-ping: درخواست به سایت برای اجرای WP-Cron
     */
    public static function self_ping() {
        FDU_Logger::log('Self-ping: تریگر WP-Cron برای اجرای رویدادهای معوق...');
        self::trigger_cron();
    }
    
    public static function test_event() {
        FDU_Logger::success('✅ fdu_single_test_event اجرا شد.');
    }
    
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
    
    public static function clear_all() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        wp_clear_scheduled_hook(self::PING_HOOK);
        wp_clear_scheduled_hook('fdu_single_test_event');
    }
}