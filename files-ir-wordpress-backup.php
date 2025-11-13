<?php
/**
 * Plugin Name: Files.ir Wordpress Backup
 * Plugin URI: https://github.com/SaeedFard
 * Description: بکاپ دیتابیس و فایل‌های وردپرس + آپلود به Files.ir
 * Version: 1.2.0
 * Author: Saeed Fard
 * Author URI: https://github.com/SaeedFard
 * License: GPLv2 or later
 * Text Domain: files-ir-wordpress-backup
 */

if (!defined('ABSPATH')) exit;

// تعریف ثابت‌های افزونه
define('FDU_VERSION', '1.2.0');
define('FDU_PLUGIN_FILE', __FILE__);
define('FDU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FDU_PLUGIN_URL', plugin_dir_url(__FILE__));

// بارگذاری کلاس‌های اصلی
require_once FDU_PLUGIN_DIR . 'includes/class-logger.php';
require_once FDU_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once FDU_PLUGIN_DIR . 'admin/class-admin.php';

// کلاس اصلی افزونه (حفظ نام برای سازگاری)
class FDU_Plugin {
    
    const OPT = 'fdu_settings';
    const CRON_HOOK = 'fdu_cron_upload_event';
    const ASYNC_HOOK = 'fdu_async_run_event';
    
    private $admin;
    
    public function __construct() {
        // Initialize scheduler
        FDU_Scheduler::init();
        
        // Initialize admin
        if (is_admin()) {
            $this->admin = new FDU_Admin();
        }
        
        // هوک‌های عمومی
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
        
        // هوک‌های admin-post برای عملیات دستی
        add_action('admin_post_fdu_run_async', [$this, 'handle_run_async']);
        add_action('admin_post_fdu_test_small', [$this, 'handle_test_small']);
        add_action('admin_post_fdu_trigger_wpcron', [$this, 'handle_trigger_wpcron']);
        add_action('admin_post_fdu_reschedule', [$this, 'handle_reschedule']);
        add_action('admin_post_fdu_single_test', [$this, 'handle_single_test']);
        add_action('admin_post_fdu_clear_log', [$this, 'handle_clear_log']);
        add_action('admin_post_fdu_delete_log', [$this, 'handle_delete_log']);
        add_action('admin_post_fdu_regen_key', [$this, 'handle_regen_key']);
        add_action('admin_post_fdu_run_bg_direct', [$this, 'handle_run_bg_direct']);
        add_action('admin_post_nopriv_fdu_worker', [$this, 'handle_worker']);
        
        // بازتنظیم خودکار زمان‌بندی هنگام تغییر تنظیمات
        add_action('updated_option', [$this, 'maybe_reschedule'], 10, 3);
    }
    
    public function on_activate() {
        $opts = $this->get_options();
        FDU_Scheduler::schedule($opts);
        FDU_Logger::log('افزونه فعال شد.');
    }
    
    public function on_deactivate() {
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
    }
    
    public function maybe_reschedule($option, $old, $value) {
        if ($option !== self::OPT) return;
        $opts = is_array($value) ? $value : $this->get_options();
        FDU_Scheduler::schedule($opts);
    }
    
    private function get_options() {
        $defaults = [
            'endpoint_url' => 'https://my.files.ir/api/v1/uploads',
            'http_method' => 'POST',
            'header_name' => 'Authorization',
            'token_prefix' => 'Bearer ',
            'token' => '',
            'multipart_field' => 'file',
            'dest_relative_path' => 'wp-backups',
            'extra_fields' => '',
            'keep_local' => 1,
            'retention' => 7,
            'enable_files_backup' => 1,
            'archive_format' => 'zip',
            'include_paths' => "wp-content/uploads\nwp-content/themes\nwp-content/plugins",
            'include_wp_config' => 0,
            'include_htaccess' => 0,
            'exclude_patterns' => "cache\ncaches\nbackups\n*.log",
            'frequency' => 'daily',
            'weekday' => 6,
            'hour' => 3,
            'minute' => 0,
            'email' => '',
            'use_mysqldump' => 1,
            'compat_mode' => 1,
            'force_manual_multipart' => 1,
            'chunk_size_mb' => 5,
            'upload_method' => 'stream',
            'bg_key' => '',
        ];
        
        $opts = get_option(self::OPT, []);
        $opts = wp_parse_args($opts, $defaults);
        
        // تولید کلید Worker اگر وجود نداره
        if (empty($opts['bg_key'])) {
            $opts['bg_key'] = wp_generate_password(32, false, false);
            update_option(self::OPT, $opts);
        }
        
        return $opts;
    }
    
    // متدهای handler برای عملیات دستی
    // (کدهای قبلی رو اینجا کپی کن)
    
    public function handle_run_async() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_run_async')) {
            wp_die('Forbidden');
        }
        FDU_Scheduler::schedule_async();
        wp_safe_redirect(wp_get_referer());
        exit;
    }
    
    // ... بقیه متدها ...
}

// اجرای افزونه
new FDU_Plugin();