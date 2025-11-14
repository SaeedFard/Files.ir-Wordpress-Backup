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
    
    /**
     * تنظیمات
     */
    private $options;
    
    /**
     * مسیر ذخیره‌سازی
     */
    private $backup_dir;
    
    /**
     * مسیر ریشه وردپرس
     */
    private $wp_root;
    
    public function __construct($options = []) {
        $this->options = $options;
        $this->backup_dir = $this->get_backup_dir();
        $this->wp_root = trailingslashit(ABSPATH);
        
        // ساخت پوشه اگر وجود نداره
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }
    
    /**
     * ساخت آرشیو از فایل‌ها
     * 
     * @return string|false مسیر فایل آرشیو یا false
     */
    public function create_archive() {
        @set_time_limit(0);
        
        // چک کردن فعال بودن بکاپ فایل‌ها
        if (empty($this->options['enable_files_backup'])) {
            FDU_Logger::log('بکاپ فایل‌ها غیرفعال است');
            return false;
        }
        
        FDU_Logger::log('=== شروع بکاپ فایل‌ها ===');
        
        // دریافت فرمت آرشیو
        $format = $this->get_archive_format();
        FDU_Logger::log('فرمت آرشیو: ' . strtoupper($format));
        
        // دریافت مسیرهای شامل و حذف
        $include_paths = $this->get_include_paths();
        $exclude_patterns = $this->get_exclude_patterns();
        
        if (empty($include_paths)) {
            FDU_Logger::error('هیچ مسیری برای بکاپ انتخاب نشده');
            return false;
        }
        
        FDU_Logger::log('تعداد مسیرهای شامل: ' . count($include_paths));
        FDU_Logger::log('تعداد الگوهای حذف: ' . count($exclude_patterns));
        
        // ساخت آرشیو
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
    
    /**
     * تشخیص فرمت آرشیو
     * 
     * @return string 'zip' or 'tar.gz'
     */
    private function get_archive_format() {
        $requested = isset($this->options['archive_format']) 
            ? $this->options['archive_format'] 
            : 'zip';
        
        // اگر tar.gz درخواست شده، چک کنیم که امکانش هست یا نه
        if ($requested === 'tar.gz') {
            if ($this->can_create_tar()) {
                return 'tar.gz';
            }
            
            FDU_Logger::warning('PharData در دسترس نیست. سوییچ خودکار به ZIP');
        }
        
        // بررسی ZIP
        if (!class_exists('ZipArchive')) {
            FDU_Logger::error('ZipArchive در دسترس نیست');
            return false;
        }
        
        return 'zip';
    }
    
    /**
     * آیا می‌توان TAR.GZ ساخت؟
     * 
     * @return bool
     */
    private function can_create_tar() {
        if (!class_exists('PharData')) {
            return false;
        }
        
        // چک کردن phar.readonly
        if (ini_get('phar.readonly') == '1') {
            return false;
        }
        
        return true;
    }
    
    /**
     * دریافت مسیرهای شامل
     * 
     * @return array
     */
    private function get_include_paths() {
        $paths = [];
        
        // مسیرهای از تنظیمات
        if (!empty($this->options['include_paths'])) {
            $lines = preg_split('/[\r\n]+/', $this->options['include_paths']);
            $paths = array_filter(array_map('trim', $lines));
        }
        
        // اضافه کردن wp-config.php
        if (!empty($this->options['include_wp_config'])) {
            $paths[] = 'wp-config.php';
        }
        
        // اضافه کردن .htaccess
        if (!empty($this->options['include_htaccess'])) {
            $paths[] = '.htaccess';
        }
        
        return array_unique($paths);
    }
    
    /**
     * دریافت الگوهای حذف
     * 
     * @return array
     */
    private function get_exclude_patterns() {
        if (empty($this->options['exclude_patterns'])) {
            return [];
        }
        
        $patterns = preg_split('/[\r\n,]+/', $this->options['exclude_patterns']);
        return array_filter(array_map('trim', $patterns));
    }
    
    /**
     * ساخت آرشیو ZIP
     * 
     * @param array $include_paths
     * @param array $exclude_patterns
     * @return string|false
     */
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
    
    /**
     * ساخت آرشیو TAR.GZ
     * 
     * @param array $include_paths
     * @param array $exclude_patterns
     * @return string|false
     */
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
            // حذف فایل قدیمی اگر وجود داره
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
            
            // فشرده‌سازی
            FDU_Logger::log('فشرده‌سازی با gzip...');
            $phar->compress(Phar::GZ);
            
            unset($phar);
            
            // حذف فایل tar اصلی
            @unlink($tar_file);
            
            FDU_Logger::log("آمار: {$total_files} فایل از {$total_scanned} مورد اسکان شده");
            
            return $output_file;
            
        } catch (Exception $e) {
            FDU_Logger::error('خطای TAR.GZ: ' . $e->getMessage());
            
            // پاکسازی
            if (file_exists($tar_file)) {
                @unlink($tar_file);
            }
            
            return false;
        }
    }
    
    /**
     * اضافه کردن مسیر به ZIP
     * 
     * @param ZipArchive $zip
     * @param string $rel_path
     * @param array $exclude_patterns
     * @param int &$scanned
     * @return int تعداد فایل‌های اضافه شده
     */
    private function add_to_zip($zip, $rel_path, $exclude_patterns, &$scanned) {
        $rel_path = $this->normalize_path($rel_path);
        $full_path = $this->wp_root . $rel_path;
        
        if (!file_exists($full_path)) {
            FDU_Logger::warning("  ⚠ مسیر وجود ندارد: {$rel_path}");
            return 0;
        }
        
        $added = 0;
        
        // فایل
        if (is_file($full_path)) {
            $scanned++;
            
            if (!$this->is_excluded($rel_path, $exclude_patterns)) {
                $zip->addFile($full_path, $rel_path);
                $added++;
            }
            
            return $added;
        }
        
        // دایرکتوری
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
                    
                    // لاگ هر 1000 فایل
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
    
    /**
     * اضافه کردن مسیر به TAR
     * 
     * @param PharData $phar
     * @param string $rel_path
     * @param array $exclude_patterns
     * @param int &$scanned
     * @return int
     */
    private function add_to_tar($phar, $rel_path, $exclude_patterns, &$scanned) {
        $rel_path = $this->normalize_path($rel_path);
        $full_path = $this->wp_root . $rel_path;
        
        if (!file_exists($full_path)) {
            FDU_Logger::warning("  ⚠ مسیر وجود ندارد: {$rel_path}");
            return 0;
        }
        
        $added = 0;
        
        // فایل
        if (is_file($full_path)) {
            $scanned++;
            
            if (!$this->is_excluded($rel_path, $exclude_patterns)) {
                $phar->addFile($full_path, $rel_path);
                $added++;
            }
            
            return $added;
        }
        
        // دایرکتوری
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
                    
                    // لاگ هر 1000 فایل
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
    
    /**
     * نرمال کردن مسیر
     * 
     * @param string $path
     * @return string
     */
    private function normalize_path($path) {
        // حذف / و \ از ابتدا
        $path = ltrim($path, '/\\');
        
        // حذف .. برای امنیت
        $path = str_replace('..', '', $path);
        
        // حذف ./ و .\ از ابتدا
        $path = preg_replace('~^\.[\\/\\\\]~', '', $path);
        
        // تبدیل \ به /
        $path = str_replace('\\', '/', $path);
        
        return $path;
    }
    
    /**
     * بررسی اینکه آیا مسیر باید حذف بشه
     * 
     * @param string $path
     * @param array $patterns
     * @return bool
     */
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
            
            // تبدیل glob pattern به regex
            $regex = $this->glob_to_regex($pattern);
            
            if (preg_match($regex, $path)) {
                return true;
            }
            
            // چک کردن اینکه آیا pattern داخل مسیر هست
            if (stripos($path, trim($pattern, '/')) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * تبدیل glob pattern به regex
     * 
     * @param string $pattern
     * @return string
     */
    private function glob_to_regex($pattern) {
        $pattern = preg_quote($pattern, '~');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);
        return '~^' . $pattern . '$~i';
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
