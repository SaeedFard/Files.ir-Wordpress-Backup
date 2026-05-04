<?php
/**
 * Files Backup Class
 * ساخت آرشیو از فایل‌های وردپرس
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Backup_Files {
    
    private $options;
    private $backup_dir;
    private $wp_root;
    
    public function __construct($options = []) {
        $this->options = $options;
        $this->backup_dir = $this->get_backup_dir();
        $this->wp_root = trailingslashit(ABSPATH);
        
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }
    
    public function create_archive() {
        @set_time_limit(0);
        
        if (empty($this->options['enable_files_backup'])) {
            FDU_Logger::log('بکاپ فایل‌ها غیرفعال است');
            return false;
        }
        
        FDU_Logger::log('=== شروع بکاپ فایل‌ها ===');
        
        $format = $this->get_archive_format();
        FDU_Logger::log('فرمت آرشیو: ' . strtoupper($format));
        
        $include_paths = $this->get_include_paths();
        $exclude_patterns = $this->get_exclude_patterns();
        
        if (empty($include_paths)) {
            FDU_Logger::error('هیچ مسیری برای بکاپ انتخاب نشده');
            return false;
        }
        
        FDU_Logger::log('تعداد مسیرهای شامل: ' . count($include_paths));
        FDU_Logger::log('تعداد الگوهای حذف: ' . count($exclude_patterns));
        
        if ($format === 'tar.gz') {
            $archive = $this->create_tar_archive($include_paths, $exclude_patterns);
        } else {
            $archive = $this->create_zip_archive($include_paths, $exclude_patterns);
        }
        
        if ($archive && file_exists($archive)) {
            $size_mb = filesize($archive) / 1048576;
            FDU_Logger::success('✅ آرشیو فایل‌ها ساخته شد: ' . number_format($size_mb, 2) . ' MB');
            return $archive;
        }
        
        FDU_Logger::error('❌ خطا در ساخت آرشیو فایل‌ها');
        return false;
    }
    
    private function get_archive_format() {
        $requested = isset($this->options['archive_format']) 
            ? $this->options['archive_format'] 
            : 'zip';
        
        if ($requested === 'tar.gz') {
            if ($this->can_create_tar()) {
                return 'tar.gz';
            }
            
            FDU_Logger::warning('PharData در دسترس نیست. سوییچ خودکار به ZIP');
        }
        
        if (!class_exists('ZipArchive')) {
            FDU_Logger::error('ZipArchive در دسترس نیست');
            return false;
        }
        
        return 'zip';
    }
    
    private function can_create_tar() {
        if (!class_exists('PharData')) {
            return false;
        }
        
        if (ini_get('phar.readonly') == '1') {
            return false;
        }
        
        return true;
    }
    
    private function get_include_paths() {
        $paths = [];
        
        if (!empty($this->options['include_paths'])) {
            // Split با newline، comma، یا فاصله‌های متعدد
            $lines = preg_split('/[\r\n,]+/', trim($this->options['include_paths']), -1, PREG_SPLIT_NO_EMPTY);
            
            // پاکسازی و trim هر خط
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $paths[] = $line;
                }
            }
        }
        
        if (!empty($this->options['include_wp_config'])) {
            $paths[] = 'wp-config.php';
        }
        
        if (!empty($this->options['include_htaccess'])) {
            $paths[] = '.htaccess';
        }
        
        return array_unique($paths);
    }
    
    private function get_exclude_patterns() {
        if (empty($this->options['exclude_patterns'])) {
            return [];
        }
        
        $patterns = preg_split('/[\r\n,]+/', $this->options['exclude_patterns']);
        return array_filter(array_map('trim', $patterns));
    }
    
    private function create_zip_archive($include_paths, $exclude_patterns) {
        if (!class_exists('ZipArchive')) {
            FDU_Logger::error('ZipArchive در دسترس نیست');
            return false;
        }
        
        $timestamp = wp_date('Ymd-His');
        $output_file = trailingslashit($this->backup_dir) . 'files-' . $timestamp . '.files.zip';
        
        FDU_Logger::log('ساخت آرشیو ZIP: ' . basename($output_file));
        
        $zip = new ZipArchive();
        
        if ($zip->open($output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            FDU_Logger::error('خطا در باز کردن فایل ZIP');
            return false;
        }
        
        $total_files = 0;
        $total_scanned = 0;
        
        foreach ($include_paths as $rel_path) {
            FDU_Logger::log('→ اضافه کردن: ' . $rel_path);
            
            $result = $this->add_to_zip($zip, $rel_path, $exclude_patterns, $total_scanned);
            $total_files += $result;
            
            if ($result > 0) {
                FDU_Logger::log("  ✓ {$result} فایل اضافه شد");
            }
        }
        
        $zip->close();
        
        FDU_Logger::log("آمار: {$total_files} فایل از {$total_scanned} مورد اسکان شده");
        
        return $output_file;
    }
    
    private function create_tar_archive($include_paths, $exclude_patterns) {
        if (!$this->can_create_tar()) {
            FDU_Logger::error('نمی‌توان TAR.GZ ساخت');
            return false;
        }
        
        $timestamp = wp_date('Ymd-His');
        $tar_file = trailingslashit($this->backup_dir) . 'files-' . $timestamp . '.files.tar';
        $output_file = $tar_file . '.gz';
        
        FDU_Logger::log('ساخت آرشیو TAR.GZ: ' . basename($output_file));
        
        try {
            if (file_exists($tar_file)) {
                @unlink($tar_file);
            }
            
            $phar = new PharData($tar_file);
            
            $total_files = 0;
            $total_scanned = 0;
            
            foreach ($include_paths as $rel_path) {
                FDU_Logger::log('→ اضافه کردن: ' . $rel_path);
                
                $result = $this->add_to_tar($phar, $rel_path, $exclude_patterns, $total_scanned);
                $total_files += $result;
                
                if ($result > 0) {
                    FDU_Logger::log("  ✓ {$result} فایل اضافه شد");
                }
            }
            
            FDU_Logger::log('فشرده‌سازی با gzip...');
            $phar->compress(Phar::GZ);
            
            unset($phar);
            
            @unlink($tar_file);
            
            FDU_Logger::log("آمار: {$total_files} فایل از {$total_scanned} مورد اسکان شده");
            
            return $output_file;
            
        } catch (Exception $e) {
            FDU_Logger::error('خطای TAR.GZ: ' . $e->getMessage());
            
            if (file_exists($tar_file)) {
                @unlink($tar_file);
            }
            
            return false;
        }
    }
    
    private function add_to_zip($zip, $rel_path, $exclude_patterns, &$scanned) {
        $rel_path = $this->normalize_path($rel_path);
        $full_path = $this->wp_root . $rel_path;
        
        if (!file_exists($full_path)) {
            FDU_Logger::warning("  ⚠ مسیر وجود ندارد: {$rel_path}");
            return 0;
        }
        
        $added = 0;
        
        if (is_file($full_path)) {
            $scanned++;
            
            if (!$this->is_excluded($rel_path, $exclude_patterns)) {
                $zip->addFile($full_path, $rel_path);
                $added++;
            }
            
            return $added;
        }
        
        if (is_dir($full_path)) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full_path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    $scanned++;
                    
                    $file_path = $file->getPathname();
                    $relative = str_replace($this->wp_root, '', $file_path);
                    $relative = str_replace('\\', '/', $relative);
                    
                    if ($this->is_excluded($relative, $exclude_patterns)) {
                        continue;
                    }
                    
                    $zip->addFile($file_path, $relative);
                    $added++;
                    
                    if ($added % 1000 === 0) {
                        FDU_Logger::log("  ... {$added} فایل اضافه شد");
                    }
                }
                
            } catch (Exception $e) {
                FDU_Logger::error("  خطا در خواندن: {$rel_path} - " . $e->getMessage());
            }
        }
        
        return $added;
    }
    
    private function add_to_tar($phar, $rel_path, $exclude_patterns, &$scanned) {
        $rel_path = $this->normalize_path($rel_path);
        $full_path = $this->wp_root . $rel_path;
        
        if (!file_exists($full_path)) {
            FDU_Logger::warning("  ⚠ مسیر وجود ندارد: {$rel_path}");
            return 0;
        }
        
        $added = 0;
        
        if (is_file($full_path)) {
            $scanned++;
            
            if (!$this->is_excluded($rel_path, $exclude_patterns)) {
                $phar->addFile($full_path, $rel_path);
                $added++;
            }
            
            return $added;
        }
        
        if (is_dir($full_path)) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full_path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    $scanned++;
                    
                    $file_path = $file->getPathname();
                    $relative = str_replace($this->wp_root, '', $file_path);
                    $relative = str_replace('\\', '/', $relative);
                    
                    if ($this->is_excluded($relative, $exclude_patterns)) {
                        continue;
                    }
                    
                    $phar->addFile($file_path, $relative);
                    $added++;
                    
                    if ($added % 1000 === 0) {
                        FDU_Logger::log("  ... {$added} فایل اضافه شد");
                    }
                }
                
            } catch (Exception $e) {
                FDU_Logger::error("  خطا در خواندن: {$rel_path} - " . $e->getMessage());
            }
        }
        
        return $added;
    }
    
    private function normalize_path($path) {
        $path = ltrim($path, '/\\');
        $path = str_replace('..', '', $path);
        $path = preg_replace('~^\.[\\/\\\\]~', '', $path);
        $path = str_replace('\\', '/', $path);
        
        return $path;
    }
    
    private function is_excluded($path, $patterns) {
        if (empty($patterns)) {
            return false;
        }
        
        $path = str_replace('\\', '/', $path);
        
        foreach ($patterns as $pattern) {
            if (empty($pattern)) {
                continue;
            }
            
            $pattern = str_replace('\\', '/', trim($pattern));
            
            $regex = $this->glob_to_regex($pattern);
            
            if (preg_match($regex, $path)) {
                return true;
            }
            
            if (stripos($path, trim($pattern, '/')) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function glob_to_regex($pattern) {
        $pattern = preg_quote($pattern, '~');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);
        return '~^' . $pattern . '$~i';
    }
    
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