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
    
    private $tabs = [];
    
    public function __construct() {
        $this->setup_tabs();
        $this->init_hooks();
    }
    
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
			'restore' => [
			    'title' => 'بازیابی',
			    'icon'  => 'dashicons-backup',
			],
            'advanced' => [
                'title' => 'پیشرفته',
                'icon'  => 'dashicons-admin-settings',
            ],
        ];
    }
    
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        add_filter(
            'plugin_action_links_' . plugin_basename(FDU_PLUGIN_FILE),
            [$this, 'add_settings_link']
        );
    }
    
    public function add_menu_page() {
        add_options_page(
            'Files.ir Wordpress Backup',
            'Files.ir Backup',
            'manage_options',
            $this->page_slug,
            [$this, 'render_page']
        );
    }
    
    public function register_settings() {
        register_setting(
            $this->option_group, 
            $this->option_name,
            [
                'sanitize_callback' => [$this, 'sanitize_settings']
            ]
        );
        
        $this->register_api_settings();
        $this->register_database_settings();
        $this->register_files_settings();
        $this->register_schedule_settings();
        $this->register_advanced_settings();
    }
    
    private function register_api_settings() {
        add_settings_section(
            'fdu_api_section',
            'تنظیمات Files.ir API',
            function() {
                echo '<p>توکن API خود را از <a href="https://my.files.ir/account-settings" target="_blank">تنظیمات حساب Files.ir</a> دریافت کنید.</p>';
            },
            $this->page_slug . '_api'
        );
        
        $fields = [
            ['token', 'توکن API', 'password'],
            ['dest_relative_path', 'پوشه مقصد (اختیاری)', 'text'],
            ['extra_fields', 'فیلدهای اضافه - JSON (اختیاری)', 'textarea'],
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
    
    public function sanitize_settings($input) {
        $old_settings = get_option($this->option_name, []);
        
        if (empty($input)) {
            return $old_settings;
        }
        
        $merged = array_merge($old_settings, $input);
        
        $textarea_fields = ['include_paths', 'exclude_patterns', 'extra_fields'];
        $no_trim_fields = ['token_prefix'];
        
        foreach ($merged as $key => $value) {
            if (in_array($key, $textarea_fields)) {
                $merged[$key] = sanitize_textarea_field($value);
            } elseif (in_array($key, $no_trim_fields)) {
                $merged[$key] = stripslashes($value);
            } elseif (is_string($value)) {
                $merged[$key] = sanitize_text_field($value);
            } elseif (is_array($value)) {
                $merged[$key] = array_map('sanitize_text_field', $value);
            }
        }
        
        return $merged;
    }
    
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
                if ($key === 'dest_relative_path') {
                    echo '<p class="description">مثال: wp-backups یا wp-backups/mysite (خالی = ریشه)</p>';
                }
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
                if ($key === 'extra_fields') {
                    echo '<p class="description">مثال: {"parentId": 12345}</p>';
                }
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
    
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
        
        include dirname(__FILE__) . '/views/settings-page.php';
    }
    
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