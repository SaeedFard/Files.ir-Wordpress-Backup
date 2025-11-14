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
require_once FDU_PLUGIN_DIR . 'includes/class-backup-database.php';
require_once FDU_PLUGIN_DIR . 'includes/class-backup-files.php';
require_once FDU_PLUGIN_DIR . 'includes/class-uploader.php';

// بارگذاری کلاس Admin فقط در بخش مدیریت
if (is_admin()) {
    require_once FDU_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * کلاس اصلی افزونه
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */
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
    
    /**
     * ثبت هوک‌ها
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
        
        // هوک‌های admin-post برای عملیات دستی
        add_action('admin_post_fdu_run_async', [$this, 'handle_run_async']);
        add_action('admin_post_fdu_run_bg_direct', [$this, 'handle_run_bg_direct']);
        add_action('admin_post_fdu_test_small', [$this, 'handle_test_small']);
        add_action('admin_post_fdu_trigger_wpcron', [$this, 'handle_trigger_wpcron']);
        add_action('admin_post_fdu_reschedule', [$this, 'handle_reschedule']);
        add_action('admin_post_fdu_single_test', [$this, 'handle_single_test']);
        add_action('admin_post_fdu_clear_log', [$this, 'handle_clear_log']);
        add_action('admin_post_fdu_delete_log', [$this, 'handle_delete_log']);
        add_action('admin_post_fdu_regen_key', [$this, 'handle_regen_key']);
        add_action('admin_post_nopriv_fdu_worker', [$this, 'handle_worker']);
        
        // بازتنظیم خودکار زمان‌بندی هنگام تغییر تنظیمات
        add_action('updated_option', [$this, 'maybe_reschedule'], 10, 3);
        
        // هوک worker برای اجرای بکاپ
        add_action('fdu_worker_backup', [$this, 'run_backup_job']);
    }
    
    /**
     * فعال‌سازی افزونه
     */
    public function on_activate() {
        $opts = $this->get_options();
        FDU_Scheduler::schedule($opts);
        FDU_Logger::log('افزونه فعال شد (v' . FDU_VERSION . ')');
    }
    
    /**
     * غیرفعال‌سازی افزونه
     */
    public function on_deactivate() {
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        FDU_Logger::log('افزونه غیرفعال شد.');
    }
    
    /**
     * بازتنظیم زمان‌بندی هنگام تغییر تنظیمات
     */
    public function maybe_reschedule($option, $old, $value) {
        if ($option !== self::OPT) return;
        
        $opts = is_array($value) ? $value : $this->get_options();
        FDU_Scheduler::schedule($opts);
    }
    
    /**
     * دریافت تنظیمات
     * 
     * @return array
     */
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
    
    // ========================================
    // Handler Methods
    // ========================================
    
    /**
     * اجرای بکاپ در پس‌زمینه (با WP-Cron)
     */
    public function handle_run_async() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_run_async')) {
            wp_die('Forbidden');
        }
        
        FDU_Scheduler::schedule_async();
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * اجرای مستقیم بدون WP-Cron
     */
    public function handle_run_bg_direct() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_run_async')) {
            wp_die('Forbidden');
        }
        
        $opts = $this->get_options();
        $url = add_query_arg(
            ['action' => 'fdu_worker', 'key' => $opts['bg_key']], 
            admin_url('admin-post.php')
        );
        
        FDU_Logger::log('Direct worker requested: ' . $url);
        
        wp_remote_get($url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false
        ]);
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * Worker endpoint (برای cron job سرور)
     */
    public function handle_worker() {
        $opts = $this->get_options();
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        // بررسی کلید امنیتی
        if (empty($opts['bg_key']) || $key !== $opts['bg_key']) {
            status_header(403);
            die('Forbidden');
        }
        
        @ignore_user_abort(true);
        @set_time_limit(0);
        
        FDU_Logger::log('=== Worker started (direct call) ===');
        
        // اجرای بکاپ
        $this->run_backup_job();
        
        FDU_Logger::log('=== Worker finished ===');
        
        status_header(200);
        die('OK');
    }
    
    /**
     * تست آپلود فایل کوچک
     */
    public function handle_test_small() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_test')) {
            wp_die('Forbidden');
        }
        
        $opts = $this->get_options();
        
        FDU_Logger::log('=== شروع تست آپلود ===');
        
        // ساخت فایل تستی
        $tmp = wp_tempnam('fdu-test.txt');
        file_put_contents($tmp, 'Files.ir WordPress Backup Test File' . PHP_EOL . 
                               'Timestamp: ' . wp_date('c') . PHP_EOL . 
                               'Site: ' . home_url());
        
        // فشرده‌سازی
        $gz = $tmp . '.gz';
        $gzf = gzopen($gz, 'wb9');
        if ($gzf) {
            gzwrite($gzf, file_get_contents($tmp));
            gzclose($gzf);
            unlink($tmp);
            
            FDU_Logger::log('فایل تست ساخته شد: ' . basename($gz) . ' (' . filesize($gz) . ' bytes)');
            
            // آپلود
            $uploader = new FDU_Uploader($opts);
            $result = $uploader->upload($gz, ['type' => 'test']);
            
            if ($result) {
                FDU_Logger::success('✅ تست آپلود موفق بود');
            } else {
                FDU_Logger::error('❌ تست آپلود ناموفق بود');
            }
            
            @unlink($gz);
        } else {
            FDU_Logger::error('خطا در ساخت فایل فشرده');
        }
        
        FDU_Logger::log('=== پایان تست آپلود ===');
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=api'));
        exit;
    }
    
    /**
     * تریگر دستی WP-Cron
     */
    public function handle_trigger_wpcron() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) {
            wp_die('Forbidden');
        }
        
        FDU_Logger::log('تریگر دستی WP-Cron...');
        FDU_Scheduler::trigger_cron();
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * بازتنظیم زمان‌بندی
     */
    public function handle_reschedule() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) {
            wp_die('Forbidden');
        }
        
        FDU_Scheduler::schedule($this->get_options());
        FDU_Logger::log('زمان‌بندی بازتنظیم شد از طریق دکمه ادمین.');
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * تست 2 دقیقه‌ای WP-Cron
     */
    public function handle_single_test() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) {
            wp_die('Forbidden');
        }
        
        FDU_Scheduler::schedule_test();
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * پاک‌سازی لاگ
     */
    public function handle_clear_log() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_log')) {
            wp_die('Forbidden');
        }
        
        FDU_Logger::clear();
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * حذف فایل لاگ
     */
    public function handle_delete_log() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_log')) {
            wp_die('Forbidden');
        }
        
        FDU_Logger::delete();
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * تولید کلید جدید Worker
     */
    public function handle_regen_key() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_regen')) {
            wp_die('Forbidden');
        }
        
        $opts = $this->get_options();
        $old_key = substr($opts['bg_key'], 0, 8) . '...';
        
        $opts['bg_key'] = wp_generate_password(32, false, false);
        update_option(self::OPT, $opts);
        
        $new_key = substr($opts['bg_key'], 0, 8) . '...';
        FDU_Logger::log("کلید Worker تغییر کرد: {$old_key} → {$new_key}");
        
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=files-ir-wordpress-backup&tab=advanced'));
        exit;
    }
    
    /**
     * اجرای Job بکاپ
     */
    public function run_backup_job() {
        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        
        $opts = $this->get_options();
        
        FDU_Logger::log('========================================');
        FDU_Logger::log('شروع فرایند بکاپ کامل');
        FDU_Logger::log('========================================');
        
        $success = true;
        $files_to_upload = [];
        
        // 1. بکاپ دیتابیس
        FDU_Logger::log('');
        $db_backup = new FDU_Backup_Database($opts);
        $sql_file = $db_backup->export();
        
        if ($sql_file) {
            $gz_file = $db_backup->compress($sql_file);
            
            if ($gz_file) {
                $files_to_upload[] = $gz_file;
            } else {
                $success = false;
            }
        } else {
            $success = false;
        }
        
        // 2. بکاپ فایل‌ها (اختیاری)
        if (!empty($opts['enable_files_backup'])) {
            FDU_Logger::log('');
            $files_backup = new FDU_Backup_Files($opts);
            $archive = $files_backup->create_archive();
            
            if ($archive) {
                $files_to_upload[] = $archive;
            } else {
                $success = false;
            }
        }
        
        // 3. آپلود فایل‌ها
        if (!empty($files_to_upload)) {
            FDU_Logger::log('');
            FDU_Logger::log('شروع آپلود فایل‌ها به Files.ir...');
            
            $uploader = new FDU_Uploader($opts);
            
            foreach ($files_to_upload as $file) {
                FDU_Logger::log('');
                
                $result = $uploader->upload($file);
                
                if (!$result) {
                    $success = false;
                }
                
                // حذف فایل اگر keep_local غیرفعال باشه
                if (empty($opts['keep_local'])) {
                    @unlink($file);
                    FDU_Logger::log('فایل محلی حذف شد: ' . basename($file));
                }
            }
        }
        
        // 4. Retention - نگهداری نسخه‌های محلی
        if (!empty($opts['keep_local'])) {
            $this->apply_retention($opts['retention']);
        }
        
        // 5. ایمیل اعلان
        if (!empty($opts['email'])) {
            $this->send_notification($opts['email'], $success);
        }
        
        // 6. بازتنظیم زمان‌بندی برای اجرای بعدی
        FDU_Scheduler::schedule($opts);
        
        FDU_Logger::log('');
        FDU_Logger::log('========================================');
        
        if ($success) {
            FDU_Logger::success('✅ فرایند بکاپ با موفقیت انجام شد');
        } else {
            FDU_Logger::error('❌ برخی بخش‌های بکاپ ناموفق بودند');
        }
        
        FDU_Logger::log('========================================');
        
        return $success;
    }
    
    /**
     * اعمال سیاست retention
     */
    private function apply_retention($keep = 7) {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'files-ir-wordpress-backup';
        
        // بکاپ دیتابیس
        $this->apply_retention_pattern($backup_dir, '*.sql.gz', $keep);
        
        // بکاپ فایل‌ها - ZIP
        $this->apply_retention_pattern($backup_dir, '*.files.zip', $keep);
        
        // بکاپ فایل‌ها - TAR.GZ
        $this->apply_retention_pattern($backup_dir, '*.files.tar.gz', $keep);
    }
    
    /**
     * اعمال retention برای یک pattern خاص
     */
    private function apply_retention_pattern($dir, $pattern, $keep) {
        $files = glob($dir . '/' . $pattern);
        
        if (empty($files) || count($files) <= $keep) {
            return;
        }
        
        // مرتب‌سازی بر اساس زمان (جدیدترین اول)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // حذف فایل‌های قدیمی
        $deleted = 0;
        for ($i = $keep; $i < count($files); $i++) {
            if (@unlink($files[$i])) {
                $deleted++;
            }
        }
        
        if ($deleted > 0) {
            FDU_Logger::log("Retention: {$deleted} فایل قدیمی حذف شد ({$pattern})");
        }
    }
    
    /**
     * ارسال ایمیل اعلان
     */
    private function send_notification($email, $success) {
        $subject = 'Files.ir WordPress Backup: ' . ($success ? 'موفق' : 'خطا');
        
        $message = "سایت: " . home_url() . "\n";
        $message .= "زمان: " . wp_date('Y-m-d H:i:s') . "\n";
        $message .= "وضعیت: " . ($success ? 'موفق ✅' : 'ناموفق ❌') . "\n\n";
        
        if (!$success) {
            $message .= "لطفاً لاگ‌ها را بررسی کنید.\n";
        }
        
        wp_mail($email, $subject, $message);
    }
}

// اجرای افزونه
new FDU_Plugin();
