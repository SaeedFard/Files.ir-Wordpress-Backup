<?php
/**
 * Admin Class
 * مدیریت صفحه تنظیمات با تب‌بندی
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Admin {
    
    private $page_slug = 'files-ir-wordpress-backup';
    private $option_group = 'fdu_settings_group';
    private $option_name = 'fdu_settings';
    
    /**
     * تب‌های موجود
     */
    private $tabs = [];
    
    public function __construct() {
        $this->setup_tabs();
        $this->init_hooks();
    }
    
    /**
     * تنظیم تب‌ها
     */
    private function setup_tabs() {
        $this->tabs = [
            'api' => [
                'title' => 'تنظیمات API',
                'icon'  => 'dashicons-cloud-upload',
            ],
            'database' => [
                'title' => 'بکاپ دیتابیس',
                'icon'  => 'dashicons-database',
            ],
            'files' => [
                'title' => 'بکاپ فایل‌ها',
                'icon'  => 'dashicons-portfolio',
            ],
            'schedule' => [
                'title' => 'زمان‌بندی',
                'icon'  => 'dashicons-clock',
            ],
            'advanced' => [
                'title' => 'پیشرفته',
                'icon'  => 'dashicons-admin-settings',
            ],
        ];
    }
    
    /**
     * ثبت هوک‌ها
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Settings link in plugins list
        add_filter(
            'plugin_action_links_' . plugin_basename(FDU_PLUGIN_FILE),
            [$this, 'add_settings_link']
        );
    }
    
    /**
     * افزودن صفحه به منو
     */
    public function add_menu_page() {
        add_options_page(
            'Files.ir Wordpress Backup',
            'Files.ir Backup',
            'manage_options',
            $this->page_slug,
            [$this, 'render_page']
        );
    }
    
    /**
     * ثبت تنظیمات
     */
    public function register_settings() {
        register_setting($this->option_group, $this->option_name);
        
        // API Tab
        $this->register_api_settings();
        
        // Database Tab
        $this->register_database_settings();
        
        // Files Tab
        $this->register_files_settings();
        
        // Schedule Tab
        $this->register_schedule_settings();
        
        // Advanced Tab
        $this->register_advanced_settings();
    }
    
    /**
     * تنظیمات API
     */
    private function register_api_settings() {
        add_settings_section(
            'fdu_api_section',
            'تنظیمات Files.ir API',
            function() {
                echo '<p>اطلاعات API خود را از پنل Files.ir وارد کنید.</p>';
            },
            $this->page_slug . '_api'
        );
        
        $fields = [
            ['endpoint_url', 'آدرس Endpoint', 'text'],
            ['http_method', 'HTTP Method', 'select', ['POST'=>'POST', 'PUT'=>'PUT']],
            ['header_name', 'نام هدر اعتبارسنجی', 'text'],
            ['token_prefix', 'پیشوند مقدار هدر', 'text'],
            ['token', 'توکن/API Key', 'password'],
            ['multipart_field', 'نام فیلد فایل (Multipart)', 'text'],
            ['dest_relative_path', 'پوشه مقصد (relativePath)', 'text'],
            ['extra_fields', 'فیلدهای اضافه (JSON)', 'textarea'],
        ];
        
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [$this, 'render_field'],
                $this->page_slug . '_api',
                'fdu_api_section',
                $field
            );
        }
    }
    
    /**
     * تنظیمات دیتابیس
     */
    private function register_database_settings() {
        add_settings_section(
            'fdu_database_section',
            'تنظیمات بکاپ دیتابیس',
            function() {
                echo '<p>تنظیم نحوه خروجی‌گیری از پایگاه‌داده.</p>';
            },
            $this->page_slug . '_database'
        );
        
        $fields = [
            ['use_mysqldump', 'استفاده از mysqldump در صورت دسترسی', 'checkbox'],
        ];
        
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [$this, 'render_field'],
                $this->page_slug . '_database',
                'fdu_database_section',
                $field
            );
        }
    }
    
    /**
     * تنظیمات فایل‌ها
     */
    private function register_files_settings() {
        add_settings_section(
            'fdu_files_section',
            'تنظیمات بکاپ فایل‌ها',
            function() {
                echo '<p>مشخص کنید چه فایل‌هایی بکاپ شوند.</p>';
            },
            $this->page_slug . '_files'
        );
        
        $fields = [
            ['enable_files_backup', 'فعال‌سازی بکاپ فایل‌ها', 'checkbox'],
            ['archive_format', 'فرمت آرشیو', 'select', ['zip'=>'ZIP', 'tar.gz'=>'TAR.GZ']],
            ['include_paths', 'مسیرهای شامل‌شونده', 'textarea'],
            ['include_wp_config', 'شامل wp-config.php', 'checkbox'],
            ['include_htaccess', 'شامل .htaccess', 'checkbox'],
            ['exclude_patterns', 'الگوهای حذف', 'textarea'],
        ];
        
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [$this, 'render_field'],
                $this->page_slug . '_files',
                'fdu_files_section',
                $field
            );
        }
    }
    
    /**
     * تنظیمات زمان‌بندی
     */
    private function register_schedule_settings() {
        add_settings_section(
            'fdu_schedule_section',
            'تنظیمات زمان‌بندی',
            function() {
                echo '<p>تعیین زمان اجرای خودکار بکاپ.</p>';
            },
            $this->page_slug . '_schedule'
        );
        
        $fields = [
            ['frequency', 'تناوب اجرا', 'select', ['daily'=>'روزانه', 'weekly'=>'هفتگی']],
            ['weekday', 'روز هفته', 'select', ['6'=>'شنبه','0'=>'یکشنبه','1'=>'دوشنبه','2'=>'سه‌شنبه','3'=>'چهارشنبه','4'=>'پنجشنبه','5'=>'جمعه']],
            ['hour', 'ساعت اجرا', 'number'],
            ['minute', 'دقیقه اجرا', 'number'],
            ['email', 'ایمیل اعلان (اختیاری)', 'text'],
            ['keep_local', 'نگه‌داشتن کپی محلی', 'checkbox'],
            ['retention', 'تعداد نسخه‌های محلی', 'number'],
        ];
        
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [$this, 'render_field'],
                $this->page_slug . '_schedule',
                'fdu_schedule_section',
                $field
            );
        }
    }
    
    /**
     * تنظیمات پیشرفته
     */
    private function register_advanced_settings() {
        add_settings_section(
            'fdu_advanced_section',
            'تنظیمات پیشرفته',
            function() {
                echo '<p>تنظیمات پیشرفته و بهینه‌سازی.</p>';
            },
            $this->page_slug . '_advanced'
        );
        
        $fields = [
            ['upload_method', 'روش آپلود', 'select', ['stream'=>'Stream (توصیه)','chunk'=>'Chunked']],
            ['chunk_size_mb', 'اندازه قطعه (MB)', 'number'],
            ['compat_mode', 'حالت سازگاری', 'checkbox'],
            ['force_manual_multipart', 'اجبار به multipart دستی', 'checkbox'],
        ];
        
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [$this, 'render_field'],
                $this->page_slug . '_advanced',
                'fdu_advanced_section',
                $field
            );
        }
    }
    
    /**
     * رندر فیلد
     */
    public function render_field($args) {
        $options = get_option($this->option_name, []);
        list($key, $label, $type) = $args;
        $choices = isset($args[3]) ? $args[3] : [];
        
        $id = esc_attr($this->option_name . '_' . $key);
        $name = esc_attr($this->option_name . '[' . $key . ']');
        $value = isset($options[$key]) ? $options[$key] : '';
        
        switch ($type) {
            case 'text':
            case 'password':
            case 'number':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />',
                    esc_attr($type),
                    $id,
                    $name,
                    esc_attr($value)
                );
                break;
                
            case 'checkbox':
                printf('<input type="hidden" name="%s" value="0" />', $name);
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s />',
                    $id,
                    $name,
                    checked($value, 1, false)
                );
                break;
                
            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="5" class="large-text code">%s</textarea>',
                    $id,
                    $name,
                    esc_textarea($value)
                );
                break;
                
            case 'select':
                printf('<select id="%s" name="%s">', $id, $name);
                foreach ($choices as $k => $v) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($k),
                        selected($value, $k, false),
                        esc_html($v)
                    );
                }
                echo '</select>';
                break;
        }
    }
    
    /**
     * رندر صفحه تنظیمات
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
        
        // Load view
        include dirname(__FILE__) . '/views/settings-page.php';
    }
    
    /**
     * لود CSS و JS
     */
    public function enqueue_assets($hook) {
        if ('settings_page_' . $this->page_slug !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'fdu-admin-css',
            plugins_url('assets/css/admin.css', FDU_PLUGIN_FILE),
            [],
            '1.2.0'
        );
        
        wp_enqueue_script(
            'fdu-admin-js',
            plugins_url('assets/js/admin.js', FDU_PLUGIN_FILE),
            ['jquery'],
            '1.2.0',
            true
        );
    }
    
    /**
     * افزودن لینک تنظیمات به لیست افزونه‌ها
     */
    public function add_settings_link($links) {
        $url = admin_url('options-general.php?page=' . $this->page_slug);
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . 
            esc_html__('تنظیمات', 'files-ir-wordpress-backup') . 
            '</a>'
        );
        return $links;
    }
}
