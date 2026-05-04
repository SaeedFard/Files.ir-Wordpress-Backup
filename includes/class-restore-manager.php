<?php
/**
 * Restore Manager Class
 * مدیریت بازیابی بکاپ‌ها
 * 
 * @package Files_IR_Backup
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Restore_Manager {
    
    private $options;
    private $backup_dir;
    
    public function __construct($options = []) {
        $this->options = $options;
        $this->backup_dir = $this->get_backup_dir();
    }
    
    /**
     * دریافت لیست بکاپ‌های محلی
     * 
     * @return array
     */
    public function get_local_backups() {
        $backups = [
            'database' => [],
            'files' => []
        ];
        
        if (!file_exists($this->backup_dir)) {
            return $backups;
        }
        
        // بکاپ‌های دیتابیس
        $db_files = glob($this->backup_dir . '/db-*.sql.gz');
        foreach ($db_files as $file) {
            $backups['database'][] = [
                'path' => $file,
                'filename' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file),
                'type' => 'database',
                'location' => 'local'
            ];
        }
        
        // بکاپ‌های فایل (ZIP)
        $zip_files = glob($this->backup_dir . '/files-*.files.zip');
        foreach ($zip_files as $file) {
            $backups['files'][] = [
                'path' => $file,
                'filename' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file),
                'type' => 'files',
                'format' => 'zip',
                'location' => 'local'
            ];
        }
        
        // بکاپ‌های فایل (TAR.GZ)
        $tar_files = glob($this->backup_dir . '/files-*.files.tar.gz');
        foreach ($tar_files as $file) {
            $backups['files'][] = [
                'path' => $file,
                'filename' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file),
                'type' => 'files',
                'format' => 'tar.gz',
                'location' => 'local'
            ];
        }
        
        // مرتب‌سازی بر اساس تاریخ (جدیدترین اول)
        usort($backups['database'], fn($a, $b) => $b['date'] - $a['date']);
        usort($backups['files'], fn($a, $b) => $b['date'] - $a['date']);
        
        return $backups;
    }
    
    /**
     * دریافت لیست بکاپ‌ها از Files.ir
     * 
     * @return array
     */
    public function get_remote_backups() {
        if (empty($this->options['token'])) {
            return ['database' => [], 'files' => []];
        }
        
        $backups = [
            'database' => [],
            'files' => []
        ];
        
        // دریافت لیست فایل‌ها
        $entries = $this->fetch_files_ir_entries();
        
        if (empty($entries)) {
            return $backups;
        }
        
        foreach ($entries as $entry) {
            $filename = $entry['name'];
            
            // شناسایی نوع بکاپ
            if (preg_match('/^db-\d{8}-\d{6}\.sql\.gz$/', $filename)) {
                $backups['database'][] = [
                    'id' => $entry['id'],
                    'filename' => $filename,
                    'size' => $entry['file_size'] ?? 0,
                    'date' => strtotime($entry['created_at'] ?? 'now'),
                    'type' => 'database',
                    'location' => 'remote',
                    'hash' => $entry['hash'] ?? ''
                ];
            }
            elseif (preg_match('/^files-\d{8}-\d{6}\.files\.(zip|tar\.gz)$/', $filename, $m)) {
                $backups['files'][] = [
                    'id' => $entry['id'],
                    'filename' => $filename,
                    'size' => $entry['file_size'] ?? 0,
                    'date' => strtotime($entry['created_at'] ?? 'now'),
                    'type' => 'files',
                    'format' => $m[1],
                    'location' => 'remote',
                    'hash' => $entry['hash'] ?? ''
                ];
            }
        }
        
        // مرتب‌سازی
        usort($backups['database'], fn($a, $b) => $b['date'] - $a['date']);
        usort($backups['files'], fn($a, $b) => $b['date'] - $a['date']);
        
        return $backups;
    }
    
    /**
     * دانلود فایل بکاپ از Files.ir
     * 
     * @param int $entry_id
     * @param string $filename
     * @return string|false مسیر فایل دانلود شده یا false
     */
    public function download_from_files_ir($entry_id, $filename) {
        FDU_Logger::log("=== دانلود بکاپ از Files.ir ===");
        FDU_Logger::log("Entry ID: {$entry_id}");
        FDU_Logger::log("Filename: {$filename}");
        
        $download_url = "https://my.files.ir/api/v1/file-entries/{$entry_id}";
        $local_path = $this->backup_dir . '/' . $filename;
        
        // آماده‌سازی هدرها
        $headers = [
            'Authorization' => $this->options['token_prefix'] . $this->options['token']
        ];
        
        // دانلود فایل
        $response = wp_remote_get($download_url, [
            'headers' => $headers,
            'timeout' => 600,
            'stream' => true,
            'filename' => $local_path
        ]);
        
        if (is_wp_error($response)) {
            FDU_Logger::error('خطا در دانلود: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            FDU_Logger::error("خطای HTTP: {$code}");
            return false;
        }
        
        if (!file_exists($local_path) || filesize($local_path) === 0) {
            FDU_Logger::error('فایل دانلود نشد یا خالی است');
            return false;
        }
        
        $size_mb = filesize($local_path) / 1048576;
        FDU_Logger::success("✅ دانلود موفق: " . number_format($size_mb, 2) . " MB");
        
        return $local_path;
    }
    
    /**
     * بازیابی دیتابیس از فایل SQL
     * 
     * @param string $sql_gz_file
     * @return bool
     */
    public function restore_database($sql_gz_file) {
        global $wpdb;
        
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        
        FDU_Logger::log("=== شروع بازیابی دیتابیس ===");
        FDU_Logger::log("فایل: " . basename($sql_gz_file));
        
        if (!file_exists($sql_gz_file)) {
            FDU_Logger::error('فایل بکاپ یافت نشد');
            return false;
        }
        
        // استخراج فایل SQL
        $sql_file = $this->extract_sql_gz($sql_gz_file);
        
        if (!$sql_file) {
            return false;
        }
        
        // بکاپ امنیتی از دیتابیس فعلی
        $safety_backup = $this->create_safety_backup();
        
        if (!$safety_backup) {
            FDU_Logger::warning('⚠️ نتوانستیم بکاپ امنیتی بگیریم. ادامه می‌دهیم...');
        }
        
        // تلاش برای استفاده از mysql CLI
        if ($this->should_use_mysql_cli()) {
            if ($this->restore_with_mysql_cli($sql_file)) {
                @unlink($sql_file);
                FDU_Logger::success('✅ بازیابی دیتابیس موفق بود (mysql CLI)');
                return true;
            }
            
            FDU_Logger::warning('mysql CLI شکست خورد. سوییچ به PHP import...');
        }
        
        // بازیابی با PHP
        $result = $this->restore_with_php($sql_file);
        
        @unlink($sql_file);
        
        if ($result) {
            FDU_Logger::success('✅ بازیابی دیتابیس موفق بود (PHP)');
        } else {
            FDU_Logger::error('❌ خطا در بازیابی دیتابیس');
            
            // اگر بکاپ امنیتی داریم، پیشنهاد بازگردانی
            if ($safety_backup) {
                FDU_Logger::log('💾 بکاپ امنیتی در: ' . basename($safety_backup));
            }
        }
        
        return $result;
    }
    
    /**
     * بازیابی فایل‌ها از آرشیو
     * 
     * @param string $archive_file
     * @return bool
     */
    public function restore_files($archive_file) {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        
        FDU_Logger::log("=== شروع بازیابی فایل‌ها ===");
        FDU_Logger::log("فایل: " . basename($archive_file));
        
        if (!file_exists($archive_file)) {
            FDU_Logger::error('فایل آرشیو یافت نشد');
            return false;
        }
        
        // تشخیص فرمت
        if (preg_match('/\.zip$/i', $archive_file)) {
            return $this->restore_from_zip($archive_file);
        }
        elseif (preg_match('/\.tar\.gz$/i', $archive_file)) {
            return $this->restore_from_tar_gz($archive_file);
        }
        
        FDU_Logger::error('فرمت آرشیو پشتیبانی نمی‌شود');
        return false;
    }
    
    /**
     * استخراج فایل .sql.gz
     * 
     * @param string $gz_file
     * @return string|false
     */
    private function extract_sql_gz($gz_file) {
        $sql_file = str_replace('.gz', '', $gz_file);
        
        FDU_Logger::log('استخراج فایل SQL از gzip...');
        
        $gz = @gzopen($gz_file, 'rb');
        if (!$gz) {
            FDU_Logger::error('خطا در باز کردن فایل gzip');
            return false;
        }
        
        $fp = @fopen($sql_file, 'wb');
        if (!$fp) {
            gzclose($gz);
            FDU_Logger::error('خطا در ساخت فایل SQL');
            return false;
        }
        
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1048576);
            fwrite($fp, $chunk);
        }
        
        gzclose($gz);
        fclose($fp);
        
        FDU_Logger::log('✓ استخراج موفق: ' . basename($sql_file));
        
        return $sql_file;
    }
    
    /**
     * ساخت بکاپ امنیتی قبل از restore
     * 
     * @return string|false
     */
    private function create_safety_backup() {
        FDU_Logger::log('ساخت بکاپ امنیتی از دیتابیس فعلی...');
        
        $db_backup = new FDU_Backup_Database($this->options);
        $sql_file = $db_backup->export();
        
        if (!$sql_file) {
            return false;
        }
        
        $gz_file = $db_backup->compress($sql_file);
        
        if ($gz_file) {
            // تغییر نام به safety-backup
            $new_name = dirname($gz_file) . '/safety-backup-' . wp_date('Ymd-His') . '.sql.gz';
            @rename($gz_file, $new_name);
            
            FDU_Logger::log('✓ بکاپ امنیتی ذخیره شد: ' . basename($new_name));
            return $new_name;
        }
        
        return false;
    }
    
    /**
     * بازیابی با mysql CLI
     * 
     * @param string $sql_file
     * @return bool
     */
    private function restore_with_mysql_cli($sql_file) {
        $mysql_path = $this->find_mysql_cli();
        
        if (!$mysql_path) {
            return false;
        }
        
        FDU_Logger::log('استفاده از mysql CLI: ' . $mysql_path);
        
        $host = DB_HOST;
        $port = '';
        $socket = '';
        
        if (strpos($host, ':') !== false) {
            list($host_part, $port_part) = explode(':', $host, 2);
            
            if (is_numeric($port_part)) {
                $port = $port_part;
            } else {
                $socket = $port_part;
            }
            
            $host = $host_part;
        }
        
        $cmd = escapeshellcmd($mysql_path);
        $cmd .= ' --host=' . escapeshellarg($host);
        
        if (!empty($port)) {
            $cmd .= ' --port=' . escapeshellarg($port);
        }
        
        if (!empty($socket)) {
            $cmd .= ' --socket=' . escapeshellarg($socket);
        }
        
        $cmd .= ' --user=' . escapeshellarg(DB_USER);
        $cmd .= ' --password=' . escapeshellarg(DB_PASSWORD);
        $cmd .= ' ' . escapeshellarg(DB_NAME);
        $cmd .= ' < ' . escapeshellarg($sql_file);
        $cmd .= ' 2>&1';
        
        $output = @shell_exec($cmd);
        
        if (empty($output) || stripos($output, 'error') === false) {
            return true;
        }
        
        FDU_Logger::error('خطای mysql: ' . substr($output, 0, 300));
        return false;
    }
    
    /**
     * بازیابی با PHP
     * 
     * @param string $sql_file
     * @return bool
     */
    private function restore_with_php($sql_file) {
        global $wpdb;
        
        FDU_Logger::log('استفاده از PHP برای import...');
        
        $fp = @fopen($sql_file, 'r');
        if (!$fp) {
            FDU_Logger::error('خطا در خواندن فایل SQL');
            return false;
        }
        
        $query = '';
        $line_num = 0;
        $success = true;
        
        while (!feof($fp)) {
            $line = fgets($fp);
            $line_num++;
            
            // نادیده گرفتن کامنت‌ها و خطوط خالی
            if (empty(trim($line)) || 
                strpos($line, '--') === 0 || 
                strpos($line, '/*') === 0) {
                continue;
            }
            
            $query .= $line;
            
            // اگر به انتهای query رسیدیم
            if (preg_match('~;[\s]*$~', $line)) {
                $result = $wpdb->query($query);
                
                if ($result === false && !empty($wpdb->last_error)) {
                    FDU_Logger::error("خطا در خط {$line_num}: " . $wpdb->last_error);
                    $success = false;
                    // ادامه می‌دهیم، نمی‌ایستیم
                }
                
                $query = '';
                
                // لاگ پیشرفت
                if ($line_num % 1000 === 0) {
                    FDU_Logger::log("  پیشرفت: {$line_num} خط پردازش شد");
                }
            }
        }
        
        fclose($fp);
        
        FDU_Logger::log("کل {$line_num} خط پردازش شد");
        
        return $success;
    }
    
    /**
     * بازیابی از ZIP
     * 
     * @param string $zip_file
     * @return bool
     */
    private function restore_from_zip($zip_file) {
        if (!class_exists('ZipArchive')) {
            FDU_Logger::error('ZipArchive در دسترس نیست');
            return false;
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($zip_file) !== true) {
            FDU_Logger::error('خطا در باز کردن فایل ZIP');
            return false;
        }
        
        $wp_root = trailingslashit(ABSPATH);
        $extracted = 0;
        
        FDU_Logger::log("تعداد فایل‌ها در آرشیو: " . $zip->numFiles);
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // نادیده گرفتن پوشه‌ها
            if (substr($filename, -1) === '/') {
                continue;
            }
            
            $target = $wp_root . $filename;
            $target_dir = dirname($target);
            
            // ساخت پوشه در صورت نیاز
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }
            
            // استخراج فایل
            if ($zip->extractTo($wp_root, $filename)) {
                $extracted++;
                
                if ($extracted % 100 === 0) {
                    FDU_Logger::log("  پیشرفت: {$extracted} فایل استخراج شد");
                }
            }
        }
        
        $zip->close();
        
        FDU_Logger::success("✅ {$extracted} فایل بازیابی شد");
        
        return true;
    }
    
    /**
     * بازیابی از TAR.GZ
     * 
     * @param string $tar_gz_file
     * @return bool
     */
    private function restore_from_tar_gz($tar_gz_file) {
        if (!class_exists('PharData')) {
            FDU_Logger::error('PharData در دسترس نیست');
            return false;
        }
        
        try {
            $phar = new PharData($tar_gz_file);
            $wp_root = trailingslashit(ABSPATH);
            
            FDU_Logger::log('استخراج آرشیو TAR.GZ...');
            
            $phar->extractTo($wp_root, null, true);
            
            FDU_Logger::success('✅ فایل‌ها بازیابی شدند');
            
            return true;
            
        } catch (Exception $e) {
            FDU_Logger::error('خطا در استخراج TAR.GZ: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * جستجو برای mysql CLI
     * 
     * @return string|false
     */
    private function find_mysql_cli() {
        $possible_paths = [
            'mysql',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\wamp\\bin\\mysql\\mysql5.7.31\\bin\\mysql.exe',
        ];
        
        foreach ($possible_paths as $path) {
            $cmd = (stripos(PHP_OS, 'WIN') === 0) 
                ? 'where ' . escapeshellarg($path)
                : 'command -v ' . escapeshellarg($path);
            
            $result = @shell_exec($cmd);
            
            if ($result && trim($result)) {
                return trim($result);
            }
        }
        
        return false;
    }
    
    /**
     * آیا باید از mysql CLI استفاده کنیم؟
     * 
     * @return bool
     */
    private function should_use_mysql_cli() {
        return function_exists('shell_exec');
    }
    
    /**
     * دریافت لیست فایل‌ها از Files.ir
     * فقط فایل‌های داخل پوشه‌ی parent_folder_id (در صورت تنظیم) رو برمی‌گردونه
     * 
     * @return array
     */
    private function fetch_files_ir_entries() {
        if (empty($this->options['token'])) {
            return [];
        }
        
        $api_url = 'https://my.files.ir/api/v1/drive/file-entries';
        
        $params = [
            'perPage' => 100,
        ];
        
        // اگه پوشه‌ی مقصد تنظیم شده باشه، فقط داخل اون پوشه رو لیست بگیر
        $parent_id = isset($this->options['parent_folder_id']) 
            ? intval($this->options['parent_folder_id']) 
            : 0;
        
        if ($parent_id > 0) {
            // parentIds is an array parameter on the API
            $params['parentIds[]'] = $parent_id;
        }
        
        $url = add_query_arg($params, $api_url);
        
        FDU_Logger::log('Fetching backup list: ' . $url);
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $this->options['token_prefix'] . $this->options['token'],
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            FDU_Logger::error('خطا در دریافت لیست: ' . $response->get_error_message());
            return [];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            FDU_Logger::error("خطای API: HTTP {$code}");
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            FDU_Logger::error('پاسخ نامعتبر از API');
            return [];
        }
        
        // پاسخ ممکنه به یکی از این ساختارها باشه:
        //   1) آرایه‌ی مستقیم: [{...}, {...}]
        //   2) wrapped: { data: [{...}], ... }
        //   3) wrapped paginator: { fileEntries: { data: [{...}] } }
        $entries = null;
        
        if (isset($data['data']) && is_array($data['data'])) {
            $entries = $data['data'];
        } elseif (isset($data['fileEntries']['data']) && is_array($data['fileEntries']['data'])) {
            $entries = $data['fileEntries']['data'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            // direct array of entries
            $entries = $data;
        }
        
        if (!is_array($entries)) {
            FDU_Logger::warning('ساختار پاسخ ناشناخته است');
            return [];
        }
        
        FDU_Logger::log('Total entries returned: ' . count($entries));
        
        // فیلتر کردن برای فایل‌های بکاپ بر اساس نام
        $backups = array_filter($entries, function($entry) {
            $name = isset($entry['name']) ? $entry['name'] : '';
            return preg_match('/^(db-|files-)\d{8}-\d{6}/', $name);
        });
        
        FDU_Logger::log('Backup files matched: ' . count($backups));
        
        return array_values($backups);
    }
    
    /**
     * دریافت مسیر پوشه بکاپ
     * 
     * @return string
     */
    private function get_backup_dir() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        $new_dir = trailingslashit($base_dir) . 'files-ir-wordpress-backup';
        $old_dir = trailingslashit($base_dir) . 'files-db-uploader';
        
        if (file_exists($new_dir) || !file_exists($old_dir)) {
            return $new_dir;
        }
        
        return $old_dir;
    }
}
