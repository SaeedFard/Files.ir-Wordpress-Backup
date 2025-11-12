<?php
/**
 * Plugin Name: Files.ir Wordpress Backup
 * Plugin URI: https://github.com/SaeedFard
 * Description: پایگاه‌داده + فایل‌های مهم وردپرس را زمان‌بندی‌شده خروجی می‌گیرد و به Files.ir آپلود می‌کند.
 * Version: 1.1.2
 * Author: Saeed Fard
 * Author URI: https://github.com/SaeedFard
 * License: GPLv2 or later
 * Text Domain: files-ir-wordpress-backup
 */

if (!defined('ABSPATH')) exit;

class FDU_Plugin {
    // ثابت‌ها را نگه می‌داریم تا زمان‌بندی و تنظیمات قبلی بدون مشکل ادامه یابد
    const OPT = 'fdu_settings';                 // همان کلید تنظیمات قبلی
    const CRON_HOOK = 'fdu_cron_upload_event';  // همان هوک زمان‌بندی قبلی
    const ASYNC_HOOK = 'fdu_async_run_event';   // همان رویداد پس‌زمینه قبلی

    private $admin_page_slug = 'files-ir-wordpress-backup'; // اسلاگ صفحهٔ تنظیمات جدید

    public function __construct() {
        add_action('admin_menu',               [$this,'admin_menu']);
        add_action('admin_init',               [$this,'register_settings']);
        add_action('admin_post_fdu_run_async', [$this,'handle_run_async']);
        add_action('admin_post_fdu_run_bg_direct', [$this,'handle_run_bg_direct']);
        add_action('admin_post_fdu_test_small',[$this,'handle_test_small']);
        add_action('admin_post_fdu_trigger_wpcron',[$this,'handle_trigger_wpcron']);
        add_action('admin_post_fdu_reschedule',[$this,'handle_reschedule']);
        add_action('admin_post_fdu_single_test',[$this,'handle_single_test']);
        add_action('admin_post_fdu_clear_log', [$this,'handle_clear_log']);
        add_action('admin_post_fdu_delete_log',[$this,'handle_delete_log']);
        add_action('admin_post_fdu_regen_key', [$this,'handle_regen_key']);
        add_action('admin_post_nopriv_fdu_worker', [$this,'handle_worker']); // Worker endpoint

        add_action(self::CRON_HOOK,            [$this,'cron_job']);
        add_action('fdu_single_test_event',    [$this,'single_test_event_cb']);
        add_action(self::ASYNC_HOOK,           [$this,'cron_job']);

        add_action('updated_option',           [$this,'maybe_reschedule'], 10, 3);

        register_activation_hook(__FILE__,     [$this,'on_activate']);
        register_deactivation_hook(__FILE__,   [$this,'on_deactivate']);
        // Settings link in Plugins list
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this,'add_settings_link']);

    }

    public function admin_page_url() {
        return admin_url('options-general.php?page='.$this->admin_page_slug);
    }

    public function on_activate() {
        // هیچ مهاجرتی لازم نیست چون کلید تنظیمات و هوک‌ها ثابت مانده‌اند.
        $opts = $this->get_options();
        $this->schedule_from_options($opts);
        $this->log('Plugin activated with new slug; settings and cron hooks preserved.');
    }
    public function on_deactivate() {
        // عمداً زمان‌بندی را پاک نمی‌کنیم تا در صورت فعال‌سازی مجدد ادامه یابد.
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
    }

    public function maybe_reschedule($option, $old, $value) {
        if ($option !== self::OPT) return;
        $opts = is_array($value) ? wp_parse_args($value, $this->get_options()) : $this->get_options();
        $this->schedule_from_options($opts);
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) $this->log('زمان‌بندی بازتنظیم شد. اجرای بعدی (محلی): '.get_date_from_gmt(gmdate('Y-m-d H:i:s',$next),'Y-m-d H:i').' | UTC: '.gmdate('Y-m-d H:i',$next));
    }

    private function schedule_from_options($opts) {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $first = $this->next_scheduled_timestamp($opts);
        wp_schedule_single_event($first, self::CRON_HOOK);
    }

    private function next_scheduled_timestamp($opts) {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $hour = isset($opts['hour']) ? intval($opts['hour']) : 3;
        $min  = isset($opts['minute']) ? intval($opts['minute']) : 0;
        $freq = isset($opts['frequency']) ? $opts['frequency'] : 'daily';

        if ($freq === 'weekly') {
            $wday = isset($opts['weekday']) ? intval($opts['weekday']) : 6; // پیش‌فرض: شنبه
            $today_w = intval($now->format('w'));
            $days_ahead = ($wday - $today_w + 7) % 7;
            $target = $now->setTime($hour, $min, 0);
            
            // Debug log
            $this->log('DEBUG: now='.$now->format('Y-m-d H:i:s').' (w='.$today_w.') | target='.$target->format('Y-m-d H:i:s').' | wday='.$wday.' | days_ahead='.$days_ahead);
            
            // مقایسه صحیح با همون timezone
            if ($days_ahead === 0 && $target <= $now) {
                $days_ahead = 7;
                $this->log('DEBUG: زمان گذشته است، +7 روز اضافه شد');
            }
            
            $final = $target->modify('+'.$days_ahead.' days');
            $this->log('DEBUG: final='.$final->format('Y-m-d H:i:s (l)'));
            
            return $final->getTimestamp();
        } else {
            $target = $now->setTime($hour, $min, 0);
            if ($target <= $now) $target = $target->modify('+1 day');
            return $target->getTimestamp();
        }
    }

    public static function uploads_dir() {
        $u = wp_upload_dir();
        $new = trailingslashit($u['basedir']).'files-ir-wordpress-backup';
        $old = trailingslashit($u['basedir']).'files-db-uploader';
        if (file_exists($new) || (!file_exists($old) && wp_mkdir_p($new))) return $new;
        // اگر قدیمی موجود بود و جدید نبود، فعلاً از قدیمی استفاده می‌کنیم تا چیزی نشکند
        return $old;
    }
    public static function logs_path() { return trailingslashit(self::uploads_dir()).'logs.txt'; }
    public function log($msg) { 
        $time = current_time('Y-m-d H:i:s'); // از timezone وردپرس استفاده می‌کنه
        @file_put_contents(self::logs_path(), '['.$time.'] '.$msg.PHP_EOL, FILE_APPEND); 
    }

    public function get_options() {
        $defaults = [
            'endpoint_url'        => 'https://my.files.ir/api/v1/uploads',
            'http_method'         => 'POST',
            'header_name'         => 'Authorization',
            'token_prefix'        => 'Bearer ',
            'token'               => '',
            'multipart_field'     => 'file',
            'dest_relative_path'  => 'wp-backups',
            'extra_fields'        => '',

            'keep_local'          => 1,
            'retention'           => 7,

            // Files backup
            'enable_files_backup' => 1,
            'archive_format'      => 'zip', // zip | tar.gz
            'include_paths'       => "wp-content/uploads\nwp-content/themes\nwp-content/plugins",
            'include_wp_config'   => 0,
            'include_htaccess'    => 0,
            'exclude_patterns'    => "cache\ncaches\nbackups\nbackup\nupdraft\nnode_modules\nvendor\n.git\n.svn\n.DS_Store\n*.log\n*.tmp\n*.swp",

            // Schedule
            'frequency'           => 'daily',
            'weekday'             => 6, // شنبه (پیش‌فرض هفته ایرانی)
            'hour'                => 3,
            'minute'              => 0,
            'email'               => '',
            'use_mysqldump'       => 1,

            // Compatibility
            'compat_mode'         => 1,
            'force_manual_multipart'=> 1,

            // Chunked upload
            'chunk_size_mb'       => 5,
            'upload_method'       => 'stream', // chunk | stream

            // Worker secret
            'bg_key'              => '',
        ];
        $opts = get_option(self::OPT, []);
        $opts = wp_parse_args($opts, $defaults);
        if (empty($opts['bg_key'])) {
            $opts['bg_key'] = wp_generate_password(32,false,false);
            update_option(self::OPT,$opts);
        }
        return $opts;
    }

    
    public function add_settings_link($links){
        $url = admin_url('options-general.php?page='.$this->admin_page_slug);
        array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('تنظیمات','files-ir-wordpress-backup').'</a>');
        return $links;
    }

    public function admin_menu() {
        add_options_page('Files.ir Wordpress Backup','Files.ir Wordpress Backup','manage_options',$this->admin_page_slug,[$this,'render_settings_page']);
    }

    public function register_settings() {
        register_setting('fdu_settings_group', self::OPT);
        add_settings_section('fdu_main','پیکربندی آپلود',function(){ echo '<p>آدرس و هدرهای API را مطابق مستندات Files تنظیم نمایید.</p>'; }, $this->admin_page_slug);

        $fields = [
            ['endpoint_url','آدرس Endpoint','text'],
            ['http_method','HTTP Method','select',['POST'=>'POST','PUT'=>'PUT']],
            ['header_name','نام هدر اعتبارسنجی','text'],
            ['token_prefix','پیشوند مقدار هدر','text'],
            ['token','توکن/API Key','password'],
            ['multipart_field','نام فیلد فایل (Multipart)','text'],
            ['dest_relative_path','پوشهٔ مقصد در Files (relativePath)','text'],
            ['extra_fields','فیلدهای اضافه (JSON)','textarea'],
            ['chunk_size_mb','اندازه هر قطعه آپلود (MB) - برای فایل‌های بزرگ‌تر از 50MB','number'],
            ['upload_method','روش آپلود فایل‌های بزرگ','select',['stream'=>'Stream (توصیه می‌شود)','chunk'=>'Chunked (نیاز به پشتیبانی سرور)']],

            ['keep_local','نگه‌داشتن کپی محلی','checkbox'],
            ['retention','تعداد نسخه‌های محلی','number'],

            ['enable_files_backup','بکاپ فایل‌های وردپرس','checkbox'],
            ['archive_format','فرمت آرشیو فایل‌ها','select',['zip'=>'ZIP','tar.gz'=>'TAR.GZ']],
            ['include_paths','مسیرهای شامل‌شونده (relative به ریشه وردپرس)','textarea'],
            ['include_wp_config','شامل wp-config.php','checkbox'],
            ['include_htaccess','شامل .htaccess','checkbox'],
            ['exclude_patterns','الگوهای حذف (* و ? مجاز)','textarea'],

            ['frequency','تناوب اجرا','select',['daily'=>'روزانه','weekly'=>'هفتگی']],
            ['weekday','روز اجرای هفتگی','select',['6'=>'شنبه','0'=>'یکشنبه','1'=>'دوشنبه','2'=>'سه‌شنبه','3'=>'چهارشنبه','4'=>'پنجشنبه','5'=>'جمعه']],
            ['hour','ساعت اجرا','number'],
            ['minute','دقیقه اجرا','number'],
            ['email','ایمیل اعلان (اختیاری)','text'],
            ['use_mysqldump','استفاده از mysqldump در صورت دسترسی','checkbox'],

            ['compat_mode','حالت سازگاری (ارسال حداقلی فیلدها + خاموش کردن Expect)','checkbox'],
            ['force_manual_multipart','اجبار به ارسال دستی multipart (بدون CURLFile)','checkbox'],
        ];
        foreach ($fields as $f) add_settings_field($f[0],$f[1],[$this,'render_field'],$this->admin_page_slug,'fdu_main',$f);
    }

    public function render_field($args) {
        $opts = $this->get_options();
        list($key,$label,$type) = $args;
        $id = esc_attr(self::OPT.'_'.$key);
        $name = esc_attr(self::OPT.'['.$key.']');
        $val = isset($opts[$key]) ? $opts[$key] : '';
        switch ($type) {
            case 'text':
            case 'password':
            case 'number':
                printf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />', esc_attr($type), $id, $name, esc_attr($val));
                break;
            case 'checkbox':
                printf('<input type="hidden" name="%s" value="0" />',$name);
                printf('<input type="checkbox" id="%s" name="%s" value="1" %s />', $id, $name, checked($val,1,false));
                break;
            case 'textarea':
                printf('<textarea id="%s" name="%s" rows="5" cols="60" class="large-text code">%s</textarea>', $id, $name, esc_textarea($val));
                break;
            case 'select':
                $choices = isset($args[3]) ? $args[3] : [];
                printf('<select id="%s" name="%s">', $id, $name);
                foreach ($choices as $k=>$v) printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val,$k,false), esc_html($v));
                echo '</select>';
                break;
        }
    }

    private function render_health_panel() {
        $now  = current_time('timestamp');
        $next = wp_next_scheduled(self::CRON_HOOK);
        $next_async = wp_next_scheduled(self::ASYNC_HOOK);
        $disable = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        $lock = get_transient('doing_cron');
        ?>
        <h2>بررسی سلامت WP‑Cron</h2>
        <table class="widefat striped" style="max-width:880px">
            <tbody>
                <tr><td>زمان وردپرس</td><td><?php echo wp_date('Y-m-d H:i:s', $now); ?></td></tr>
                <tr><td>نوبت بعدی رویداد روزانه/هفتگی</td><td><?php echo $next ? get_date_from_gmt(gmdate('Y-m-d H:i:s',$next),'Y-m-d H:i:s') : '<strong style="color:#d63638">ثبت نشده</strong>'; ?></td></tr>
                <tr><td>نوبت بعدی اجرای در پس‌زمینه</td><td><?php echo $next_async ? get_date_from_gmt(gmdate('Y-m-d H:i:s',$next_async),'Y-m-d H:i:s') : '—'; ?></td></tr>
                <tr><td>DISABLE_WP_CRON</td><td><?php echo $disable ? '<strong style="color:#d63638">فعال (WP‑Cron غیرفعال)</strong>' : '<span style="color:#2c7">غیرفعال</span>'; ?></td></tr>
                <tr><td>کران لاک (doing_cron)</td><td><?php echo $lock ? esc_html($lock) : '—'; ?></td></tr>
            </tbody>
        </table>
        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_trigger_wpcron'),'fdu_health')); ?>" class="button">اجرای WP‑Cron همین حالا</a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_single_test'),'fdu_health')); ?>" class="button">تست ۲ دقیقه‌ای WP‑Cron</a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_reschedule'),'fdu_health')); ?>" class="button">بازتنظیم زمان‌بندی</a>
        </p>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $last_log = @file_exists(self::logs_path()) ? esc_html(@file_get_contents(self::logs_path())) : '';
        $next = wp_next_scheduled(self::CRON_HOOK);
        $opts = $this->get_options();
        $worker_url = add_query_arg(['action'=>'fdu_worker','key'=>$opts['bg_key']], admin_url('admin-post.php'));
        ?>
        <div class="wrap">
            <h1>Files.ir Wordpress Backup <small style="opacity:.6">v1.1.2 - اصلاح باگ timezone در زمان‌بندی</small></h1>
            <form method="post" action="options.php">
                <?php settings_fields('fdu_settings_group'); do_settings_sections($this->admin_page_slug); submit_button(); ?>
            </form>

            <h2>عملیات دستی</h2>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_run_async'),'fdu_run_async')); ?>" class="button button-primary">اجرای در پس‌زمینه (۵ ثانیه دیگر، WP‑Cron)</a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_run_bg_direct'),'fdu_run_async')); ?>" class="button">اجرای پس‌زمینه — مستقیم (بدون WP‑Cron)</a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_test_small'),'fdu_test')); ?>" class="button">تست آپلود کوچک</a>
            </p>

            <h3>Worker URL</h3>
            <p class="description">این آدرس فقط با <strong>کلید مخفی</strong> کار می‌کند. آن را خصوصی نگه دارید.</p>
            <code style="display:block;padding:.5rem 1rem;background:#f6f7f7;border:1px solid #ccd0d4;max-width:100%"><?php echo esc_html($worker_url); ?></code>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_regen_key'),'fdu_regen')); ?>" class="button" onclick="return confirm('کلید Worker عوض شود؟ باید اسکریپت‌های کران خود را هم به‌روزرسانی کنید.')">تولید کلید جدید</a>
            </p>

            <h2>وضعیت زمان‌بندی</h2>
            <p><?php echo $next ? 'نوبت بعدی: '.get_date_from_gmt(gmdate('Y-m-d H:i:s',$next),'Y-m-d H:i') : 'زمان‌بندی فعال نیست.'; ?></p>

            <?php $this->render_health_panel(); ?>

            <h2>لاگ</h2>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_clear_log'),'fdu_log')); ?>" class="button">پاک‌سازی لاگ</a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fdu_delete_log'),'fdu_log')); ?>" class="button" onclick="return confirm('فایل لاگ حذف شود؟')">حذف فایل لاگ</a>
            </p>
            <textarea readonly rows="12" style="width:100%;font-family:monospace;"><?php echo $last_log; ?></textarea>
            <p class="description">مسیر لاگ: <code><?php echo esc_html(self::logs_path()); ?></code></p>
        </div>
        <?php
    }

    // Regenerate worker key
    public function handle_regen_key() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_regen')) wp_die('forbidden');
        $opts = $this->get_options();
        $opts['bg_key'] = wp_generate_password(32,false,false);
        update_option(self::OPT,$opts);
        $this->log('Worker key regenerated from admin.');
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    // Log maintenance
    public function handle_clear_log() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_log')) wp_die('forbidden');
        $p = self::logs_path();
        if (file_exists($p)) {
            @file_put_contents($p, '');
            @file_put_contents($p, '['.wp_date('Y-m-d H:i:s').'] لاگ پاک‌سازی شد.'.PHP_EOL, FILE_APPEND);
        }
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }
    public function handle_delete_log() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_log')) wp_die('forbidden');
        $p = self::logs_path();
        if (file_exists($p)) { @unlink($p); }
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    // Async via WP-Cron
    public function handle_run_async() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_run_async')) wp_die('forbidden');
        $ts = time() + 5; // UTC timestamp
        wp_schedule_single_event($ts, self::ASYNC_HOOK);
        // نمایش درست زمان: تبدیل UTC به timezone وردپرس
        $local_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s');
        $this->log('Background run scheduled for '. $local_time .' (local) | UTC: '. gmdate('Y-m-d H:i:s', $ts));
        if (!function_exists('spawn_cron')) require_once ABSPATH.'wp-includes/cron.php';
        @spawn_cron();
        wp_remote_get( site_url('wp-cron.php?doing_wp_cron='.microtime(true)), ['timeout'=>0.01, 'blocking'=>false] );
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    // Background direct worker (no WP-Cron)
    public function handle_run_bg_direct() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_run_async')) wp_die('forbidden');
        $opts = $this->get_options();
        $url = add_query_arg(['action'=>'fdu_worker','key'=>$opts['bg_key']], admin_url('admin-post.php'));
        $this->log('Direct worker requested: '.$url);
        wp_remote_get($url, ['timeout'=>0.01,'blocking'=>false,'sslverify'=>false]);
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }
    public function handle_worker() {
        $opts = $this->get_options();
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        if (empty($opts['bg_key']) || $key !== $opts['bg_key']) { status_header(403); echo 'Forbidden'; exit; }
        @ignore_user_abort(true);
        @set_time_limit(0);
        $this->log('Worker started (direct).');
        $this->cron_job();
        $this->log('Worker finished.');
        echo 'OK'; exit;
    }

    public function handle_test_small() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_test')) wp_die('forbidden');
        $opts = $this->get_options();
        $tmp = wp_tempnam('fdu.txt'); file_put_contents($tmp,'FDU ping @ '.wp_date('c').' - '.home_url());
        $gz = $tmp.'.gz'; $gzf = gzopen($gz,'wb9'); gzwrite($gzf,file_get_contents($tmp)); gzclose($gzf); unlink($tmp);
        $this->upload_file($gz,$opts,['type'=>'text/plain']); @unlink($gz);
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    public function handle_trigger_wpcron() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) wp_die('forbidden');
        if (!function_exists('spawn_cron')) require_once ABSPATH.'wp-includes/cron.php';
        $this->log('Trigger WP‑Cron manually (spawn_cron).'); @spawn_cron();
        $resp = wp_remote_get(site_url('wp-cron.php?doing_wp_cron='.microtime(true)), ['timeout'=>10,'blocking'=>true]);
        $code = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
        $this->log('Loopback (blocking): '.(is_string($code)?$code:('HTTP '.$code)));
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    public function handle_reschedule() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) wp_die('forbidden');
        $this->schedule_from_options($this->get_options());
        $this->log('Rescheduled via admin button.');
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }

    public function handle_single_test() {
        if (!current_user_can('manage_options') || !check_admin_referer('fdu_health')) wp_die('forbidden');
        $ts = time() + 120; 
        wp_schedule_single_event($ts,'fdu_single_test_event');
        $local_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s');
        $this->log('Scheduled single test for '. $local_time .' (local)');
        if (!function_exists('spawn_cron')) require_once ABSPATH.'wp-includes/cron.php';
        @spawn_cron();
        wp_remote_get( site_url('wp-cron.php?doing_wp_cron='.microtime(true)), ['timeout'=>0.01, 'blocking'=>false] );
        wp_safe_redirect(wp_get_referer() ?: $this->admin_page_url()); exit;
    }
    public function single_test_event_cb() { $this->log('✅ fdu_single_test_event fired.'); }

    public function cron_job() {
        $this->do_backup_and_upload();
        $this->schedule_from_options($this->get_options());
    }

    private function do_backup_and_upload() {
        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $opts = $this->get_options();
        $this->log('=== شروع فرایند بکاپ ===');
        $ok_all = true;

        // 1) DB
        $dump_file = $this->export_database();
        if ($dump_file) {
            $gz_path = $dump_file.'.gz';
            $gz = gzopen($gz_path,'wb9');
            if ($gz) {
                $in = fopen($dump_file,'rb');
                while (!feof($in)) gzwrite($gz, fread($in,1048576));
                fclose($in); gzclose($gz);
                $ok = $this->upload_file($gz_path, $opts);
                $ok_all = $ok_all && $ok;
            } else { $this->log('خطا در ساخت gzip.'); $ok_all = false; }
        } else { $this->log('خطا در خروجی گرفتن پایگاه‌داده.'); $ok_all = false; }

        // 2) Files (optional)
        if (intval($opts['enable_files_backup']) === 1) {
            $this->log('شروع بکاپ فایل‌ها...');
            $arch = $this->export_files_archive();
            if ($arch) {
                $this->log('آرشیو فایل‌ها ساخته شد: '.$arch.' - اندازه: '.number_format(filesize($arch)/1048576,2).'MB');
                $okf = $this->upload_file($arch, $opts, ['type'=>$this->mime_for($arch)]);
                if ($okf) {
                    $this->log('✅ آرشیو فایل‌ها با موفقیت آپلود شد.');
                } else {
                    $this->log('❌ خطا در آپلود آرشیو فایل‌ها.');
                }
                $ok_all = $ok_all && $okf;
                // پاک کردن آرشیو بعد از آپلود اگر keep_local غیرفعال باشه
                if (!$opts['keep_local']) @unlink($arch);
            } else { 
                $this->log('❌ خطا در ساخت آرشیو فایل‌ها. آرشیو ایجاد نشد.');
                $ok_all = false; 
            }
        } else {
            $this->log('بکاپ فایل‌ها غیرفعال است (enable_files_backup=0)');
        }

        // Retention
        if (!$opts['keep_local']) {
            if (!empty($dump_file)) @unlink($dump_file);
            if (!empty($gz_path))   @unlink($gz_path);
        } else { $this->apply_retention_types( intval($opts['retention']) ); }

        if ($ok_all) { $this->log('✅ همهٔ آپلودها موفق بودند.'); $this->maybe_mail('Files.ir Wordpress Backup: موفق','بکاپ دیتابیس و فایل‌ها با موفقیت آپلود شد.'); }
        else { $this->log('❌ یکی از مراحل بکاپ/آپلود ناموفق بود.'); $this->maybe_mail('Files.ir Wordpress Backup: خطا','برخی بخش‌های بکاپ/آپلود ناموفق بود. لاگ را بررسی کنید.'); }
        return $ok_all;
    }

    private function mime_for($path) {
        if (preg_match('~\.zip$~i',$path)) return 'application/zip';
        if (preg_match('~\.tar\.gz$~i',$path)) return 'application/gzip';
        return 'application/octet-stream';
    }

    private function apply_retention_types($keep=7) {
        $this->apply_retention_glob('*.sql.gz',$keep);
        $this->apply_retention_glob('*.files.zip',$keep);
        $this->apply_retention_glob('*.files.tar.gz',$keep);
    }
    private function apply_retention_glob($pattern,$keep) {
        $dir = self::uploads_dir();
        $files = glob($dir.'/'.$pattern); if (!$files) return;
        usort($files,function($a,$b){ return filemtime($b)-filemtime($a); });
        $i=0; foreach ($files as $f) { $i++; if ($i>$keep) @unlink($f); }
    }

    private function maybe_mail($subject,$body) {
        $opts = $this->get_options();
        if (!empty($opts['email'])) wp_mail($opts['email'],$subject,$body);
    }

    private function export_database() {
        @set_time_limit(0);
        global $wpdb;
        $dir = self::uploads_dir();
        $file = $dir.'/db-'.wp_date('Ymd-His').'.sql';

        $use_mysqldump = $this->get_options()['use_mysqldump'];
        if ($use_mysqldump && function_exists('shell_exec')) {
            $mysqldump = $this->find_mysqldump();
            if ($mysqldump) {
                $this->log('استفاده از mysqldump: '.$mysqldump);
                $host = DB_HOST; $port=''; $socket='';
                if (strpos($host,':')!==false) {
                    list($hostPart,$portPart) = explode(':',$host,2);
                    if (is_numeric($portPart)) $port=$portPart; else $socket=$portPart;
                    $host=$hostPart;
                }
                $cmd = escapeshellcmd($mysqldump);
                $cmd .= ' --host='.escapeshellarg($host);
                if (!empty($port))   $cmd .= ' --port='.escapeshellarg($port);
                if (!empty($socket)) $cmd .= ' --socket='.escapeshellarg($socket);
                $cmd .= ' --user='.escapeshellarg(DB_USER).' --password='.escapeshellarg(DB_PASSWORD);
                $cmd .= ' --single-transaction --quick --routines --events ';
                $cmd .= escapeshellarg(DB_NAME).' > '.escapeshellarg($file).' 2>&1';
                $out = shell_exec($cmd);
                if (file_exists($file) && filesize($file)>0) return $file;
                $this->log('mysqldump شکست خورد. سوییچ به خروجی PHP. خروجی: '.print_r($out,true));
            } else { $this->log('mysqldump یافت نشد. سوییچ به خروجی PHP.'); }
        }

        $this->log('شروع خروجی گرفتن DB با PHP');
        $fh = fopen($file,'wb'); if (!$fh) return false;
        fwrite($fh, "-- Files.ir Wordpress Backup SQL dump\n-- Site: ".home_url()."\n-- Date: ".wp_date('c')."\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET foreign_key_checks=0;\n\n");
        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            $table = esc_sql($table);
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if ($create && isset($create[1])) {
                fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n".$create[1].";\n\n");
                $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");
                if ($count>0) {
                    $limit=500;
                    for ($offset=0; $offset<$count; $offset+=$limit) {
                        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` LIMIT %d OFFSET %d",$limit,$offset), ARRAY_A);
                        foreach ($rows as $row) {
                            $cols = array_map(function($c){ return '`'.str_replace('`','``',$c).'`'; }, array_keys($row));
                            $vals = array_map(function($v){ return is_null($v)?'NULL':"'".addslashes((string)$v)."'"; }, array_values($row));
                            fwrite($fh, "INSERT INTO `$table` (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n");
                        }
                    }
                    fwrite($fh,"\n");
                }
            }
        }
        fwrite($fh,"SET foreign_key_checks=1;\n"); fclose($fh);
        return $file;
    }

    private function find_mysqldump() {
        $c = ['mysqldump','/usr/bin/mysqldump','/usr/local/bin/mysqldump',
              'C:\\xampp\\mysql\\bin\\mysqldump.exe','C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'];
        foreach ($c as $bin) {
            $cmd = (stripos(PHP_OS,'WIN')===0) ? 'where '.escapeshellarg($bin) : 'command -v '.escapeshellarg($bin);
            $path = @shell_exec($cmd); if ($path) return trim($path);
        }
        return false;
    }

    private function export_files_archive() {
        @set_time_limit(0);
        $opts = $this->get_options();
        $dir  = self::uploads_dir();
        $date = wp_date('Ymd-His');
        $fmt  = ($opts['archive_format']==='tar.gz') ? 'tar.gz' : 'zip';
        $out  = $dir.'/files-'.$date.'.files.'.$fmt;

        $this->log('شروع ساخت آرشیو فایل‌ها - فرمت: '.$fmt);

        $include_paths = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)$opts['include_paths'])));
        if (intval($opts['include_wp_config'])===1) $include_paths[] = 'wp-config.php';
        if (intval($opts['include_htaccess'])===1)  $include_paths[] = '.htaccess';
        
        if (empty($include_paths)) { 
            $this->log('❌ هیچ مسیری برای بکاپ فایل‌ها انتخاب نشده.'); 
            return false; 
        }
        
        $this->log('مسیرهای انتخاب شده برای بکاپ: '.implode(', ', $include_paths));

        $exclude = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$opts['exclude_patterns'])));
        $this->log('الگوهای حذف: '.(empty($exclude)?'ندارد':implode(', ', array_slice($exclude,0,5)).'...'));

        if ($fmt==='zip') {
            if (!class_exists('ZipArchive')) { 
                $this->log('❌ ZipArchive در دسترس نیست.'); 
                return false; 
            }
            $this->log('استفاده از ZipArchive برای ساخت آرشیو...');
            $zip = new ZipArchive();
            if ($zip->open($out, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { 
                $this->log('❌ باز کردن ZIP ناموفق: '.$out); 
                return false; 
            }
            $added=0; $total=0;
            foreach ($include_paths as $rel) {
                $this->log('در حال اضافه کردن مسیر: '.$rel);
                $added += $this->zip_add_path($zip, $rel, $exclude, $total);
            }
            $zip->close();
            $this->log("✅ آرشیو ZIP ساخته شد: $out (فایل‌ها: $added / اسکن: $total)");
            return $out;
        } else {
            if (!class_exists('PharData') || ini_get('phar.readonly')) {
                $this->log('⚠️ PharData در دسترس نیست یا phar.readonly فعال است. سوئیچ خودکار به ZIP.');
                $this->update_setting('archive_format','zip');
                return $this->export_files_archive_zip_fallback($dir,$date,$include_paths,$exclude);
            }
            $tar = $dir.'/files-'.$date.'.files.tar';
            try {
                $this->log('استفاده از PharData برای ساخت TAR.GZ...');
                if (file_exists($tar)) @unlink($tar);
                $ph = new PharData($tar);
                $added=0; $total=0;
                foreach ($include_paths as $rel) {
                    $this->log('در حال اضافه کردن مسیر: '.$rel);
                    $added += $this->tar_add_path($ph, $rel, $exclude, $total);
                }
                $ph->compress(Phar::GZ); 
                unset($ph); 
                @unlink($tar);
                $this->log("✅ آرشیو TAR.GZ ساخته شد: $dir/files-$date.files.tar.gz (فایل‌ها: $added / اسکن: $total)");
                return "$dir/files-$date.files.tar.gz";
            } catch (Exception $e) { 
                $this->log('❌ خطای TAR.GZ: '.$e->getMessage().'. سوئیچ خودکار به ZIP.'); 
                return $this->export_files_archive_zip_fallback($dir,$date,$include_paths,$exclude); 
            }
        }
    }

    private function update_setting($key,$value){
        $opts = $this->get_options();
        $opts[$key]=$value;
        update_option(self::OPT,$opts);
    }

    private function export_files_archive_zip_fallback($dir,$date,$include_paths,$exclude){
        if (!class_exists('ZipArchive')) { 
            $this->log('❌ ZipArchive هم در دسترس نیست.'); 
            return false; 
        }
        $this->log('استفاده از ZIP به عنوان fallback...');
        $out = $dir.'/files-'.$date.'.files.zip';
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { 
            $this->log('❌ باز کردن ZIP ناموفق: '.$out); 
            return false; 
        }
        $added=0; $total=0;
        foreach ($include_paths as $rel) {
            $this->log('در حال اضافه کردن مسیر: '.$rel);
            $added += $this->zip_add_path($zip, $rel, $exclude, $total);
        }
        $zip->close();
        $this->log("✅ آرشیو ZIP (fallback) ساخته شد: $out (فایل‌ها: $added / اسکن: $total)");
        return $out;
    }

    private function normalize_rel($rel){ 
        $rel = ltrim($rel,'/\\');
        // فقط .. را حذف کن (برای امنیت) ولی ./ و .\ را فقط در ابتدای مسیر حذف کن
        $rel = str_replace('..', '', $rel);
        $rel = preg_replace('~^\.[\\/\\\\]~', '', $rel); // حذف ./ یا .\ از ابتدای مسیر
        return $rel; 
    }

    private function is_excluded($relPath, $patterns) {
        $relPath = str_replace('\\','/',$relPath);
        foreach ((array)$patterns as $pat) {
            if ($pat==='') continue;
            $pat = str_replace('\\','/',$pat);
            $regex = '~^'.str_replace(['*','?'],['.*','.'],preg_quote($pat,'~')).'$~i';
            if (preg_match($regex,$relPath)) return true;
            if (stripos($relPath, trim($pat,'/'))!==false) return true;
        }
        return false;
    }

    private function zip_add_path(ZipArchive $zip, $rel, $exclude, &$scanned) {
        $base = trailingslashit(ABSPATH);
        $rel  = $this->normalize_rel($rel);
        $full = $base.$rel;
        $added = 0;
        
        // لاگ مسیر برای دیباگ
        $this->log("→ بررسی مسیر: rel='$rel' full='$full'");
        
        if (!file_exists($full)) {
            $this->log("⚠️ مسیر وجود ندارد: $full");
            return 0;
        }
        
        if (is_file($full)) {
            $this->log("  ✓ این یک فایل است: $rel");
            $scanned++;
            if (!$this->is_excluded($rel,$exclude)) { 
                $zip->addFile($full,$rel); 
                $added++; 
                $this->log("  ✓ فایل اضافه شد: $rel");
            } else {
                $this->log("  ✗ فایل حذف شد (exclude): $rel");
            }
            return $added;
        }
        
        if (!is_dir($full)) {
            $this->log("⚠️ نه فایل است نه دایرکتوری: $full");
            return 0;
        }
        
        $this->log("  📁 این یک دایرکتوری است، در حال اسکن...");
        
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), 
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                $scanned++;
                $path = str_replace($base,'',$file->getPathname());
                $path = str_replace('\\','/',$path);
                if ($this->is_excluded($path,$exclude)) continue;
                $zip->addFile($file->getPathname(), $path);
                $added++;
                
                // لاگ هر 1000 فایل
                if ($added % 1000 === 0) {
                    $this->log("  پیشرفت: $added فایل اضافه شد از دایرکتوری $rel...");
                }
            }
        } catch (Exception $e) {
            $this->log("❌ خطا در خواندن مسیر $rel: ".$e->getMessage());
        }
        
        return $added;
    }

    private function tar_add_path(PharData $ph, $rel, $exclude, &$scanned) {
        $base = trailingslashit(ABSPATH);
        $rel  = $this->normalize_rel($rel);
        $full = $base.$rel;
        $added = 0;
        
        // لاگ مسیر برای دیباگ
        $this->log("→ بررسی مسیر: rel='$rel' full='$full'");
        
        if (!file_exists($full)) {
            $this->log("⚠️ مسیر وجود ندارد: $full");
            return 0;
        }
        
        if (is_file($full)) {
            $this->log("  ✓ این یک فایل است: $rel");
            $scanned++;
            if (!$this->is_excluded($rel,$exclude)) { 
                $ph->addFile($full,$rel); 
                $added++; 
                $this->log("  ✓ فایل اضافه شد: $rel");
            } else {
                $this->log("  ✗ فایل حذف شد (exclude): $rel");
            }
            return $added;
        }
        
        if (!is_dir($full)) {
            $this->log("⚠️ نه فایل است نه دایرکتوری: $full");
            return 0;
        }
        
        $this->log("  📁 این یک دایرکتوری است، در حال اسکن...");
        
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), 
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                $scanned++;
                $path = str_replace($base,'',$file->getPathname());
                $path = str_replace('\\','/',$path);
                if ($this->is_excluded($path,$exclude)) continue;
                $ph->addFile($file->getPathname(), $path);
                $added++;
                
                // لاگ هر 1000 فایل
                if ($added % 1000 === 0) {
                    $this->log("  پیشرفت: $added فایل اضافه شد از دایرکتوری $rel...");
                }
            }
        } catch (Exception $e) {
            $this->log("❌ خطا در خواندن مسیر $rel: ".$e->getMessage());
        }
        
        return $added;
    }

    private function upload_file($file_path, $opts, $meta = []) {
        $url = trim($opts['endpoint_url']);
        if (empty($url)) { $this->log('Endpoint تنظیم نشده.'); return false; }

        $file_size = @filesize($file_path);
        $filename  = basename($file_path);
        $this->log('Upload prep: file='.$filename.' size='.(($file_size===false)?'?':number_format($file_size/1048576,2).'MB').' bytes='.$file_size);

        // اگر فایل بزرگ‌تر از 50MB باشه، از روش انتخابی استفاده می‌کنیم
        $chunk_threshold = 50 * 1024 * 1024; // 50MB
        if ($file_size > $chunk_threshold) {
            $upload_method = isset($opts['upload_method']) ? $opts['upload_method'] : 'stream';
            $this->log('فایل بزرگ است ('.number_format($file_size/1048576,2).'MB). استفاده از روش: '.$upload_method);
            
            if ($upload_method === 'chunk') {
                return $this->upload_file_chunked($file_path, $opts, $meta);
            } else {
                return $this->upload_file_stream($file_path, $opts, $meta);
            }
        }

        // برای فایل‌های کوچک، از روش قبلی استفاده می‌کنیم
        return $this->upload_file_simple($file_path, $opts, $meta);
    }

    private function upload_file_simple($file_path, $opts, $meta = []) {
        $url = trim($opts['endpoint_url']);
        $headers = [ 'Accept' => 'application/json', 'Expect' => '' ];
        $token = defined('FDU_TOKEN') ? FDU_TOKEN : (string)$opts['token'];
        if (!empty($opts['header_name']) && !empty($token)) {
            $headers[$opts['header_name']] = $opts['token_prefix'].$token;
        }

        $file_size = @filesize($file_path);
        $filename  = basename($file_path);

        $make_fields = function($minimal=false) use ($opts,$filename) {
            $fields = [];
            if (!$minimal) {
                $extra = $opts['extra_fields'];
                if (!empty($extra)) { $decoded = json_decode($extra, true); if (is_array($decoded)) foreach ($decoded as $k=>$v) $fields[$k]=(string)$v; }
                $fields += ['site'=>home_url(), 'db'=>DB_NAME, 'created_at'=>wp_date('c')];
            }
            $dest = trim((string)$opts['dest_relative_path']);
            if ($dest!=='') {
                $last = basename($dest);
                $fields['relativePath'] = (strpos($last,'.')===false) ? rtrim($dest,'/\\').'/'.$filename : $dest;
            } else {
                if (!$minimal) $fields['relativePath'] = $filename;
            }
            return $fields;
        };

        $try = function($strategy) use ($url,$opts,$headers,$file_path,$filename,$meta,$make_fields) {
            $use_curlfile = class_exists('CURLFile') && empty($opts['force_manual_multipart']);
            $minimal = ($strategy === 'minimal');
            $fields = $make_fields($minimal);
            $mime = isset($meta['type']) ? $meta['type'] : ( preg_match('~\.gz$~',$filename) ? 'application/gzip' : (preg_match('~\.zip$~',$filename)?'application/zip':'application/octet-stream') );

            $args = [
                'method'      => $opts['http_method'],
                'timeout'     => 900,
                'redirection' => 5,
                'blocking'    => true,
                'headers'     => $headers,
            ];
            if ($use_curlfile) {
                $body = $fields;
                $body[$opts['multipart_field']] = new CURLFile($file_path, $mime, $filename);
                $args['body'] = $body;
            } else {
                $boundary = wp_generate_password(24,false,false);
                $eol = "\r\n"; $body = '';
                foreach ($fields as $name=>$value) {
                    $body .= "--$boundary$eol";
                    $body .= 'Content-Disposition: form-data; name="'.$name."\"$eol$eol".$value.$eol;
                }
                $body .= "--$boundary$eol";
                $body .= 'Content-Disposition: form-data; name="'.$opts['multipart_field'].'"; filename="'.$filename."\"$eol";
                $body .= "Content-Type: $mime$eol$eol";
                $body .= file_get_contents($file_path);
                $body .= $eol."--$boundary--$eol";
                $args['headers']['Content-Type'] = 'multipart/form-data; boundary='.$boundary;
                $args['body'] = $body;
            }

            $resp = wp_remote_request($url, $args);
            return $resp;
        };

        $strategies = ['normal'];
        if (!empty($opts['compat_mode'])) $strategies[] = 'minimal';

        $last_code = 0;
        foreach ($strategies as $s) {
            $this->log("Upload attempt strategy={$s} method=".$opts['http_method']." transport=".(class_exists('CURLFile')&&!$opts['force_manual_multipart']?'CURLFile':'manual'));
            $response = $try($s);
            if (is_wp_error($response)) { $this->log('WP Error: '.$response->get_error_message()); continue; }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $this->log('HTTP Status: '.$code);
            $this->log('Response body: '.substr($body,0,2000));
            if ($code>=200 && $code<300) return true;
            $last_code=$code;
        }
        $this->log('Upload failed after strategies. Last status='.$last_code);
        return false;
    }

    private function upload_file_stream($file_path, $opts, $meta = []) {
        if (!function_exists('curl_init')) {
            $this->log('خطا: cURL در دسترس نیست. سوییچ به روش ساده...');
            return $this->upload_file_simple($file_path, $opts, $meta);
        }

        $url = trim($opts['endpoint_url']);
        $file_size = filesize($file_path);
        $filename = basename($file_path);
        $mime = isset($meta['type']) ? $meta['type'] : ( preg_match('~\.gz$~',$filename) ? 'application/gzip' : (preg_match('~\.zip$~',$filename)?'application/zip':'application/octet-stream') );
        
        $this->log("شروع Stream Upload: فایل $filename با اندازه ".number_format($file_size/1048576,2)."MB");

        // استفاده از CURLFile که خودش stream می‌کنه
        if (class_exists('CURLFile') && empty($opts['force_manual_multipart'])) {
            $this->log("استفاده از CURLFile برای stream...");
            
            // آماده‌سازی فیلدهای اضافه
            $fields = [];
            $extra = $opts['extra_fields'];
            if (!empty($extra)) {
                $decoded = json_decode($extra, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k=>$v) $fields[$k]=(string)$v;
                }
            }
            $fields += ['site'=>home_url(), 'db'=>DB_NAME, 'created_at'=>wp_date('c')];
            
            $dest = trim((string)$opts['dest_relative_path']);
            if ($dest!=='') {
                $last = basename($dest);
                $fields['relativePath'] = (strpos($last,'.')===false) ? rtrim($dest,'/\\').'/'.$filename : $dest;
            } else {
                $fields['relativePath'] = $filename;
            }

            // اضافه کردن فایل با CURLFile
            $fields[$opts['multipart_field']] = new CURLFile($file_path, $mime, $filename);

            // توکن
            $token = defined('FDU_TOKEN') ? FDU_TOKEN : (string)$opts['token'];

            // ارسال با cURL + POST
            $ch = curl_init();
            
            $last_progress = 0;
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_HTTPHEADER => [
                    $opts['header_name'].': '.$opts['token_prefix'].$token,
                    'Accept: application/json',
                    'Expect:'
                ],
                CURLOPT_TIMEOUT => 3600, // 1 ساعت
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Progress callback
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function($ch, $download_size, $downloaded, $upload_size, $uploaded) use (&$last_progress) {
                    if ($upload_size > 0 && $uploaded > 0) {
                        $percent = ($uploaded / $upload_size) * 100;
                        // لاگ فقط وقتی که 10% جلو رفته باشیم
                        if (floor($percent / 10) > floor($last_progress / 10)) {
                            $this->log("  پیشرفت آپلود: ".number_format($percent, 1)."% (".number_format($uploaded/1048576,1)."MB / ".number_format($upload_size/1048576,1)."MB)");
                            $last_progress = $percent;
                        }
                    }
                }
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                $this->log("خطای cURL: $curl_error");
                return false;
            }

            $this->log("HTTP Status: $http_code");
            $this->log("Response body: ".substr($response,0,500));

            if ($http_code >= 200 && $http_code < 300) {
                $this->log("✅ Stream Upload کامل شد با موفقیت.");
                return true;
            } else {
                $this->log("❌ خطای HTTP: کد $http_code");
                return false;
            }
        }
        
        // اگر CURLFile در دسترس نباشه، از روش ساده استفاده می‌کنیم
        $this->log("CURLFile در دسترس نیست. سوییچ به روش ساده...");
        return $this->upload_file_simple($file_path, $opts, $meta);
    }

    private function upload_file_chunked($file_path, $opts, $meta = []) {
        if (!function_exists('curl_init')) {
            $this->log('خطا: cURL در دسترس نیست. سوییچ به روش ساده...');
            return $this->upload_file_simple($file_path, $opts, $meta);
        }

        $url = trim($opts['endpoint_url']);
        $file_size = filesize($file_path);
        $filename = basename($file_path);
        $mime = isset($meta['type']) ? $meta['type'] : ( preg_match('~\.gz$~',$filename) ? 'application/gzip' : (preg_match('~\.zip$~',$filename)?'application/zip':'application/octet-stream') );
        
        // اندازه هر chunk (پیش‌فرض 5MB)
        $chunk_size_mb = isset($opts['chunk_size_mb']) && intval($opts['chunk_size_mb']) > 0 ? intval($opts['chunk_size_mb']) : 5;
        $chunk_size = $chunk_size_mb * 1024 * 1024;
        $total_chunks = ceil($file_size / $chunk_size);
        
        $this->log("شروع Chunked Upload: $total_chunks قطعه، هر قطعه ".number_format($chunk_size/1048576,2)."MB");

        // آماده‌سازی فیلدهای اضافه
        $fields = [];
        $extra = $opts['extra_fields'];
        if (!empty($extra)) {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k=>$v) $fields[$k]=(string)$v;
            }
        }
        $fields += ['site'=>home_url(), 'db'=>DB_NAME, 'created_at'=>wp_date('c')];
        
        $dest = trim((string)$opts['dest_relative_path']);
        if ($dest!=='') {
            $last = basename($dest);
            $fields['relativePath'] = (strpos($last,'.')===false) ? rtrim($dest,'/\\').'/'.$filename : $dest;
        } else {
            $fields['relativePath'] = $filename;
        }

        // توکن
        $token = defined('FDU_TOKEN') ? FDU_TOKEN : (string)$opts['token'];
        
        $fp = fopen($file_path, 'rb');
        if (!$fp) {
            $this->log('خطا: نمی‌توان فایل را باز کرد.');
            return false;
        }

        $chunk_index = 0;
        $uploaded = 0;

        while (!feof($fp)) {
            $chunk_data = fread($fp, $chunk_size);
            if ($chunk_data === false) break;
            
            $chunk_index++;
            $current_chunk_size = strlen($chunk_data);
            $uploaded += $current_chunk_size;
            
            $this->log("آپلود قطعه $chunk_index از $total_chunks (".number_format($current_chunk_size/1048576,2)."MB) - پیشرفت: ".number_format(($uploaded/$file_size)*100,1)."% ");

            // ساخت multipart برای این chunk
            $boundary = '----WebKitFormBoundary' . uniqid();
            $eol = "\r\n";
            
            $body = '';
            
            // اضافه کردن فیلدها فقط برای chunk اول
            if ($chunk_index === 1) {
                foreach ($fields as $name => $value) {
                    $body .= "--$boundary$eol";
                    $body .= "Content-Disposition: form-data; name=\"$name\"$eol$eol";
                    $body .= "$value$eol";
                }
            }
            
            // اضافه کردن metadata برای chunk
            $body .= "--$boundary$eol";
            $body .= "Content-Disposition: form-data; name=\"chunkIndex\"$eol$eol";
            $body .= "$chunk_index$eol";
            
            $body .= "--$boundary$eol";
            $body .= "Content-Disposition: form-data; name=\"totalChunks\"$eol$eol";
            $body .= "$total_chunks$eol";
            
            $body .= "--$boundary$eol";
            $body .= "Content-Disposition: form-data; name=\"originalFilename\"$eol$eol";
            $body .= "$filename$eol";
            
            // اضافه کردن خود chunk
            $body .= "--$boundary$eol";
            $body .= "Content-Disposition: form-data; name=\"".$opts['multipart_field']."\"; filename=\"$filename\"$eol";
            $body .= "Content-Type: $mime$eol$eol";
            $body .= $chunk_data . $eol;
            $body .= "--$boundary--$eol";

            // ارسال با cURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    $opts['header_name'].': '.$opts['token_prefix'].$token,
                    'Content-Type: multipart/form-data; boundary='.$boundary,
                    'Accept: application/json',
                    'Expect:'
                ],
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                $this->log("خطای cURL در قطعه $chunk_index: $curl_error");
                fclose($fp);
                return false;
            }

            if ($http_code < 200 || $http_code >= 300) {
                $this->log("خطای HTTP در قطعه $chunk_index: کد $http_code - پاسخ: ".substr($response,0,500));
                fclose($fp);
                return false;
            }

            $this->log("قطعه $chunk_index با موفقیت آپلود شد (HTTP $http_code)");
            
            // تاخیر کوتاه بین chunk‌ها برای جلوگیری از فشار به سرور
            if ($chunk_index < $total_chunks) {
                usleep(100000); // 0.1 ثانیه
            }
        }

        fclose($fp);
        $this->log("✅ Chunked Upload کامل شد: $total_chunks قطعه با موفقیت آپلود شد.");
        return true;
    }
}

new FDU_Plugin();
