<?php
/**
 * Database Backup Class
 * خروجی‌گیری از دیتابیس وردپرس
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Backup_Database {
    
    /**
     * تنظیمات
     */
    private $options;
    
    /**
     * مسیر ذخیره‌سازی
     */
    private $backup_dir;
    
    public function __construct($options = []) {
        $this->options = $options;
        $this->backup_dir = $this->get_backup_dir();
        
        // ساخت پوشه اگر وجود نداره
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }
    
    /**
     * خروجی گرفتن از دیتابیس
     * 
     * @return string|false مسیر فایل SQL یا false در صورت خطا
     */
    public function export() {
        @set_time_limit(0);
        
        FDU_Logger::log('=== شروع بکاپ دیتابیس ===');
        
        $sql_file = $this->generate_filename();
        
        // تلاش برای استفاده از mysqldump
        if ($this->should_use_mysqldump()) {
            $mysqldump_path = $this->find_mysqldump();
            
            if ($mysqldump_path) {
                FDU_Logger::log('استفاده از mysqldump: ' . $mysqldump_path);
                
                if ($this->export_with_mysqldump($sql_file, $mysqldump_path)) {
                    FDU_Logger::success('✅ بکاپ دیتابیس با mysqldump موفق بود');
                    return $sql_file;
                }
                
                FDU_Logger::warning('mysqldump شکست خورد. سوییچ به PHP export...');
            } else {
                FDU_Logger::warning('mysqldump یافت نشد. استفاده از PHP export...');
            }
        }
        
        // استفاده از PHP export
        if ($this->export_with_php($sql_file)) {
            FDU_Logger::success('✅ بکاپ دیتابیس با PHP export موفق بود');
            return $sql_file;
        }
        
        FDU_Logger::error('❌ خطا در خروجی گرفتن از دیتابیس');
        return false;
    }
    
    /**
     * فشرده‌سازی فایل SQL با gzip
     * 
     * @param string $sql_file مسیر فایل SQL
     * @return string|false مسیر فایل gz یا false
     */
    public function compress($sql_file) {
        if (!file_exists($sql_file)) {
            FDU_Logger::error('فایل SQL برای فشرده‌سازی یافت نشد: ' . $sql_file);
            return false;
        }
        
        $gz_file = $sql_file . '.gz';
        
        FDU_Logger::log('شروع فشرده‌سازی SQL...');
        
        $gz = @gzopen($gz_file, 'wb9');
        if (!$gz) {
            FDU_Logger::error('خطا در ساخت فایل gzip');
            return false;
        }
        
        $fp = @fopen($sql_file, 'rb');
        if (!$fp) {
            gzclose($gz);
            FDU_Logger::error('خطا در خواندن فایل SQL');
            return false;
        }
        
        // خواندن و نوشتن به صورت chunk
        while (!feof($fp)) {
            $chunk = fread($fp, 1048576); // 1MB chunks
            gzwrite($gz, $chunk);
        }
        
        fclose($fp);
        gzclose($gz);
        
        // حذف فایل SQL اصلی
        @unlink($sql_file);
        
        $size_mb = filesize($gz_file) / 1048576;
        FDU_Logger::success('✅ فشرده‌سازی موفق: ' . number_format($size_mb, 2) . ' MB');
        
        return $gz_file;
    }
    
    /**
     * تولید نام فایل برای بکاپ
     * 
     * @return string
     */
    private function generate_filename() {
        $timestamp = wp_date('Ymd-His');
        return trailingslashit($this->backup_dir) . 'db-' . $timestamp . '.sql';
    }
    
    /**
     * دریافت مسیر پوشه بکاپ
     * 
     * @return string
     */
    private function get_backup_dir() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        // اول پوشه جدید رو چک می‌کنیم
        $new_dir = trailingslashit($base_dir) . 'files-ir-wordpress-backup';
        $old_dir = trailingslashit($base_dir) . 'files-db-uploader';
        
        // اگر پوشه جدید موجود هست یا قدیمی نیست
        if (file_exists($new_dir) || !file_exists($old_dir)) {
            return $new_dir;
        }
        
        // استفاده از پوشه قدیمی برای backward compatibility
        return $old_dir;
    }
    
    /**
     * آیا باید از mysqldump استفاده کنیم؟
     * 
     * @return bool
     */
    private function should_use_mysqldump() {
        return !empty($this->options['use_mysqldump']) && 
               function_exists('shell_exec');
    }
    
    /**
     * پیدا کردن مسیر mysqldump
     * 
     * @return string|false
     */
    private function find_mysqldump() {
        $possible_paths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp\\bin\\mysql\\mysql5.7.31\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
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
     * خروجی گرفتن با mysqldump
     * 
     * @param string $output_file
     * @param string $mysqldump_path
     * @return bool
     */
    private function export_with_mysqldump($output_file, $mysqldump_path) {
        $host = DB_HOST;
        $port = '';
        $socket = '';
        
        // جدا کردن host و port/socket
        if (strpos($host, ':') !== false) {
            list($host_part, $port_part) = explode(':', $host, 2);
            
            if (is_numeric($port_part)) {
                $port = $port_part;
            } else {
                $socket = $port_part;
            }
            
            $host = $host_part;
        }
        
        // ساخت دستور
        $cmd = escapeshellcmd($mysqldump_path);
        $cmd .= ' --host=' . escapeshellarg($host);
        
        if (!empty($port)) {
            $cmd .= ' --port=' . escapeshellarg($port);
        }
        
        if (!empty($socket)) {
            $cmd .= ' --socket=' . escapeshellarg($socket);
        }
        
        $cmd .= ' --user=' . escapeshellarg(DB_USER);
        $cmd .= ' --password=' . escapeshellarg(DB_PASSWORD);
        $cmd .= ' --single-transaction';
        $cmd .= ' --quick';
        $cmd .= ' --lock-tables=false';
        $cmd .= ' --routines';
        $cmd .= ' --events';
        
        // اضافه کردن charset
        if (defined('DB_CHARSET') && DB_CHARSET) {
            $cmd .= ' --default-character-set=' . escapeshellarg(DB_CHARSET);
        }
        
        $cmd .= ' ' . escapeshellarg(DB_NAME);
        $cmd .= ' > ' . escapeshellarg($output_file);
        $cmd .= ' 2>&1';
        
        // اجرای دستور
        $output = @shell_exec($cmd);
        
        // بررسی موفقیت
        if (file_exists($output_file) && filesize($output_file) > 0) {
            $size_mb = filesize($output_file) / 1048576;
            FDU_Logger::log('mysqldump موفق: ' . number_format($size_mb, 2) . ' MB');
            return true;
        }
        
        // لاگ خطا
        if ($output) {
            FDU_Logger::error('خطای mysqldump: ' . substr($output, 0, 500));
        }
        
        return false;
    }
    
    /**
     * خروجی گرفتن با PHP
     * 
     * @param string $output_file
     * @return bool
     */
    private function export_with_php($output_file) {
        global $wpdb;
        
        FDU_Logger::log('شروع PHP export...');
        
        $fp = @fopen($output_file, 'wb');
        if (!$fp) {
            FDU_Logger::error('نمی‌توان فایل خروجی ساخت: ' . $output_file);
            return false;
        }
        
        // نوشتن header
        $this->write_header($fp);
        
        // دریافت لیست جداول
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        
        if (empty($tables)) {
            fclose($fp);
            FDU_Logger::error('هیچ جدولی یافت نشد');
            return false;
        }
        
        FDU_Logger::log('تعداد جداول: ' . count($tables));
        
        // خروجی هر جدول
        foreach ($tables as $table_row) {
            $table = $table_row[0];
            
            if (!$this->export_table($fp, $table)) {
                fclose($fp);
                return false;
            }
        }
        
        // نوشتن footer
        fwrite($fp, "\n-- Export completed at " . wp_date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "SET foreign_key_checks = 1;\n");
        
        fclose($fp);
        
        $size_mb = filesize($output_file) / 1048576;
        FDU_Logger::log('PHP export موفق: ' . number_format($size_mb, 2) . ' MB');
        
        return true;
    }
    
    /**
     * نوشتن header فایل SQL
     * 
     * @param resource $fp
     */
    private function write_header($fp) {
        $header = "-- Files.ir WordPress Backup\n";
        $header .= "-- Site: " . home_url() . "\n";
        $header .= "-- Database: " . DB_NAME . "\n";
        $header .= "-- Date: " . wp_date('Y-m-d H:i:s') . "\n";
        $header .= "-- Generator: Files.ir WordPress Backup v" . FDU_VERSION . "\n";
        $header .= "\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET time_zone = \"+00:00\";\n";
        $header .= "SET foreign_key_checks = 0;\n";
        
        if (defined('DB_CHARSET') && DB_CHARSET) {
            $header .= "SET NAMES '" . DB_CHARSET . "';\n";
        }
        
        $header .= "\n";
        
        fwrite($fp, $header);
    }
    
    /**
     * خروجی یک جدول
     * 
     * @param resource $fp
     * @param string $table
     * @return bool
     */
    private function export_table($fp, $table) {
        global $wpdb;
        
        // ساختار جدول
        $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        
        if (!$create || !isset($create[1])) {
            FDU_Logger::error('خطا در دریافت ساختار جدول: ' . $table);
            return false;
        }
        
        fwrite($fp, "\n--\n-- Table: `{$table}`\n--\n\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fp, $create[1] . ";\n\n");
        
        // شمارش ردیف‌ها
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`"
        ));
        
        if ($count === 0) {
            FDU_Logger::log("  جدول `{$table}` خالی است");
            return true;
        }
        
        FDU_Logger::log("  خروجی جدول `{$table}` ({$count} ردیف)...");
        
        // خروجی داده‌ها به صورت batch
        $limit = 500;
        $offset = 0;
        
        while ($offset < $count) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            
            if (empty($rows)) {
                break;
            }
            
            foreach ($rows as $row) {
                $this->write_insert_query($fp, $table, $row);
            }
            
            $offset += $limit;
            
            // لاگ پیشرفت هر 5000 ردیف
            if ($offset % 5000 === 0) {
                FDU_Logger::log("    پیشرفت: {$offset}/{$count} ردیف");
            }
        }
        
        return true;
    }
    
    /**
     * نوشتن INSERT query
     * 
     * @param resource $fp
     * @param string $table
     * @param array $row
     */
    private function write_insert_query($fp, $table, $row) {
        global $wpdb;
        
        $columns = array_keys($row);
        $values = array_values($row);
        
        // Escape column names
        $columns = array_map(function($col) {
            return '`' . str_replace('`', '``', $col) . '`';
        }, $columns);
        
        // Escape values
        $values = array_map(function($val) use ($wpdb) {
            if (is_null($val)) {
                return 'NULL';
            }
            return "'" . $wpdb->_real_escape($val) . "'";
        }, $values);
        
        $query = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s);\n",
            $table,
            implode(', ', $columns),
            implode(', ', $values)
        );
        
        fwrite($fp, $query);
    }
}
