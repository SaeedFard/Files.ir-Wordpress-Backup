<?php
/**
 * Uploader Class
 * آپلود فایل‌ها به Files.ir
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Uploader {
    
    /**
     * تنظیمات
     */
    private $options;
    
    /**
     * حد آستانه برای استفاده از روش‌های پیشرفته (50MB)
     */
    const LARGE_FILE_THRESHOLD = 52428800; // 50 * 1024 * 1024
    
    public function __construct($options = []) {
        $this->options = $options;
    }
    
    /**
     * آپلود فایل به Files.ir
     * 
     * @param string $file_path مسیر فایل برای آپلود
     * @param array $metadata متادیتای اضافی
     * @return bool
     */
    public function upload($file_path, $metadata = []) {
        if (!file_exists($file_path)) {
            FDU_Logger::error('فایل برای آپلود یافت نشد: ' . $file_path);
            return false;
        }
        
        $file_size = @filesize($file_path);
        $filename = basename($file_path);
        
        FDU_Logger::log('=== شروع آپلود ===');
        FDU_Logger::log('فایل: ' . $filename);
        FDU_Logger::log('حجم: ' . $this->format_bytes($file_size));
        
        // بررسی تنظیمات API
        if (!$this->validate_settings()) {
            return false;
        }
        
        // انتخاب روش آپلود
        $method = $this->determine_upload_method($file_size);
        FDU_Logger::log('روش آپلود: ' . $method);
        
        // آپلود
        $result = false;
        
        switch ($method) {
            case 'stream':
                $result = $this->upload_stream($file_path, $metadata);
                break;
                
            case 'chunked':
                $result = $this->upload_chunked($file_path, $metadata);
                break;
                
            default:
                $result = $this->upload_simple($file_path, $metadata);
                break;
        }
        
        if ($result) {
            FDU_Logger::success('✅ آپلود موفق بود');
        } else {
            FDU_Logger::error('❌ آپلود ناموفق بود');
        }
        
        return $result;
    }
    
    /**
     * بررسی اعتبار تنظیمات
     * 
     * @return bool
     */
    private function validate_settings() {
        if (empty($this->options['endpoint_url'])) {
            FDU_Logger::error('Endpoint URL تنظیم نشده است');
            return false;
        }
        
        if (empty($this->options['token'])) {
            FDU_Logger::error('API Token تنظیم نشده است');
            return false;
        }
        
        return true;
    }
    
    /**
     * تعیین روش آپلود بر اساس حجم فایل
     * 
     * @param int $file_size
     * @return string 'simple', 'stream', or 'chunked'
     */
    private function determine_upload_method($file_size) {
        // فایل‌های کوچک
        if ($file_size < self::LARGE_FILE_THRESHOLD) {
            return 'simple';
        }
        
        // فایل‌های بزرگ
        $method = isset($this->options['upload_method']) 
            ? $this->options['upload_method'] 
            : 'stream';
        
        // بررسی دسترسی به cURL برای stream/chunked
        if (!function_exists('curl_init')) {
            FDU_Logger::warning('cURL در دسترس نیست. سوییچ به روش ساده...');
            return 'simple';
        }
        
        return $method;
    }
    
    /**
     * آپلود ساده (برای فایل‌های کوچک)
     * 
     * @param string $file_path
     * @param array $metadata
     * @return bool
     */
    private function upload_simple($file_path, $metadata) {
        $filename = basename($file_path);
        $mime = $this->get_mime_type($file_path);
        
        // ساخت فیلدها
        $fields = $this->prepare_fields($filename, $metadata);
        
        // سعی با روش‌های مختلف
        $strategies = ['normal'];
        
        if (!empty($this->options['compat_mode'])) {
            $strategies[] = 'minimal';
        }
        
        foreach ($strategies as $strategy) {
            FDU_Logger::log("تلاش با strategy={$strategy}");
            
            if ($this->send_simple_request($file_path, $filename, $mime, $fields, $strategy)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ارسال درخواست ساده
     * 
     * @param string $file_path
     * @param string $filename
     * @param string $mime
     * @param array $fields
     * @param string $strategy
     * @return bool
     */
    private function send_simple_request($file_path, $filename, $mime, $fields, $strategy) {
        $use_curlfile = class_exists('CURLFile') && 
                        empty($this->options['force_manual_multipart']);
        
        // حذف فیلدهای اضافی در حالت minimal
        if ($strategy === 'minimal') {
            $fields = array_intersect_key($fields, array_flip(['relativePath']));
        }
        
        $headers = $this->prepare_headers();
        
        // آماده‌سازی body
        if ($use_curlfile) {
            $body = $fields;
            $body[$this->options['multipart_field']] = new CURLFile($file_path, $mime, $filename);
            
            $args = [
                'method' => $this->options['http_method'],
                'timeout' => 900,
                'headers' => $headers,
                'body' => $body,
            ];
        } else {
            // ساخت manual multipart
            $boundary = wp_generate_password(24, false, false);
            $body = $this->build_multipart_body($fields, $file_path, $filename, $mime, $boundary);
            
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
            
            $args = [
                'method' => $this->options['http_method'],
                'timeout' => 900,
                'headers' => $headers,
                'body' => $body,
            ];
        }
        
        // ارسال درخواست
        $response = wp_remote_request($this->options['endpoint_url'], $args);
        
        return $this->handle_response($response);
    }
    
    /**
     * آپلود با stream (برای فایل‌های بزرگ)
     * 
     * @param string $file_path
     * @param array $metadata
     * @return bool
     */
    private function upload_stream($file_path, $metadata) {
        if (!class_exists('CURLFile')) {
            FDU_Logger::warning('CURLFile در دسترس نیست');
            return $this->upload_simple($file_path, $metadata);
        }
        
        $filename = basename($file_path);
        $mime = $this->get_mime_type($file_path);
        $file_size = filesize($file_path);
        
        // ساخت فیلدها
        $fields = $this->prepare_fields($filename, $metadata);
        $fields[$this->options['multipart_field']] = new CURLFile($file_path, $mime, $filename);
        
        // آماده‌سازی هدرها
        $token = $this->options['token'];
        $auth_header = $this->options['header_name'] . ': ' . 
                      $this->options['token_prefix'] . $token;
        
        $headers = [
            $auth_header,
            'Accept: application/json',
            'Expect:',
        ];
        
        // ارسال با cURL
        $ch = curl_init();
        
        $last_progress = 0;
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->options['endpoint_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($ch, $dl_size, $dl, $ul_size, $ul) use (&$last_progress) {
                if ($ul_size > 0 && $ul > 0) {
                    $percent = ($ul / $ul_size) * 100;
                    
                    // لاگ هر 10%
                    if (floor($percent / 10) > floor($last_progress / 10)) {
                        FDU_Logger::log(sprintf(
                            "  پیشرفت: %.1f%% (%s / %s)",
                            $percent,
                            $this->format_bytes($ul),
                            $this->format_bytes($ul_size)
                        ));
                        $last_progress = $percent;
                    }
                }
            }
        ]);
        
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            FDU_Logger::error('خطای cURL: ' . $error);
            return false;
        }
        
        FDU_Logger::log('HTTP Status: ' . $http_code);
        
        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }
        
        FDU_Logger::error('خطا: ' . substr($response_body, 0, 500));
        return false;
    }
    
    /**
     * آپلود با chunked (برای فایل‌های خیلی بزرگ)
     * 
     * @param string $file_path
     * @param array $metadata
     * @return bool
     */
    private function upload_chunked($file_path, $metadata) {
        $filename = basename($file_path);
        $mime = $this->get_mime_type($file_path);
        $file_size = filesize($file_path);
        
        // اندازه هر chunk
        $chunk_size_mb = isset($this->options['chunk_size_mb']) && 
                         intval($this->options['chunk_size_mb']) > 0
            ? intval($this->options['chunk_size_mb'])
            : 5;
        
        $chunk_size = $chunk_size_mb * 1024 * 1024;
        $total_chunks = ceil($file_size / $chunk_size);
        
        FDU_Logger::log("Chunked Upload: {$total_chunks} قطعه × {$chunk_size_mb}MB");
        
        // ساخت فیلدهای اصلی
        $base_fields = $this->prepare_fields($filename, $metadata);
        
        // باز کردن فایل
        $fp = @fopen($file_path, 'rb');
        if (!$fp) {
            FDU_Logger::error('خطا در باز کردن فایل');
            return false;
        }
        
        $chunk_index = 0;
        $uploaded_bytes = 0;
        
        while (!feof($fp)) {
            $chunk_data = fread($fp, $chunk_size);
            if ($chunk_data === false) {
                break;
            }
            
            $chunk_index++;
            $current_chunk_size = strlen($chunk_data);
            $uploaded_bytes += $current_chunk_size;
            
            FDU_Logger::log(sprintf(
                "آپلود قطعه %d/%d (%s) - پیشرفت: %.1f%%",
                $chunk_index,
                $total_chunks,
                $this->format_bytes($current_chunk_size),
                ($uploaded_bytes / $file_size) * 100
            ));
            
            // ارسال chunk
            if (!$this->send_chunk($chunk_index, $total_chunks, $filename, $chunk_data, $mime, $base_fields)) {
                fclose($fp);
                FDU_Logger::error("خطا در آپلود قطعه {$chunk_index}");
                return false;
            }
            
            // تاخیر کوتاه بین chunks
            if ($chunk_index < $total_chunks) {
                usleep(100000); // 0.1 second
            }
        }
        
        fclose($fp);
        
        FDU_Logger::success("تمام {$total_chunks} قطعه با موفقیت آپلود شد");
        return true;
    }
    
    /**
     * ارسال یک chunk
     * 
     * @param int $chunk_index
     * @param int $total_chunks
     * @param string $filename
     * @param string $chunk_data
     * @param string $mime
     * @param array $base_fields
     * @return bool
     */
    private function send_chunk($chunk_index, $total_chunks, $filename, $chunk_data, $mime, $base_fields) {
        $boundary = '----WebKitFormBoundary' . uniqid();
        $eol = "\r\n";
        
        $body = '';
        
        // فیلدهای اصلی فقط در chunk اول
        if ($chunk_index === 1) {
            foreach ($base_fields as $name => $value) {
                $body .= "--{$boundary}{$eol}";
                $body .= "Content-Disposition: form-data; name=\"{$name}\"{$eol}{$eol}";
                $body .= "{$value}{$eol}";
            }
        }
        
        // متادیتای chunk
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"chunkIndex\"{$eol}{$eol}";
        $body .= "{$chunk_index}{$eol}";
        
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"totalChunks\"{$eol}{$eol}";
        $body .= "{$total_chunks}{$eol}";
        
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"originalFilename\"{$eol}{$eol}";
        $body .= "{$filename}{$eol}";
        
        // خود chunk
        $field_name = $this->options['multipart_field'];
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"{$field_name}\"; filename=\"{$filename}\"{$eol}";
        $body .= "Content-Type: {$mime}{$eol}{$eol}";
        $body .= $chunk_data . $eol;
        $body .= "--{$boundary}--{$eol}";
        
        // ارسال با cURL
        $token = $this->options['token'];
        $auth_header = $this->options['header_name'] . ': ' . 
                      $this->options['token_prefix'] . $token;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->options['endpoint_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                $auth_header,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Accept: application/json',
                'Expect:',
            ],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            FDU_Logger::error("خطای cURL در قطعه {$chunk_index}: {$error}");
            return false;
        }
        
        if ($http_code < 200 || $http_code >= 300) {
            FDU_Logger::error("خطای HTTP در قطعه {$chunk_index}: {$http_code}");
            FDU_Logger::log('پاسخ: ' . substr($response, 0, 300));
            return false;
        }
        
        return true;
    }
    
    /**
     * آماده‌سازی فیلدهای اضافی
     * 
     * @param string $filename
     * @param array $metadata
     * @return array
     */
    private function prepare_fields($filename, $metadata = []) {
        $fields = [];
        
        // فیلدهای پایه
        $fields['site'] = home_url();
        $fields['db'] = DB_NAME;
        $fields['created_at'] = wp_date('c');
        
        // متادیتای سفارشی
        if (!empty($metadata)) {
            $fields = array_merge($fields, $metadata);
        }
        
        // فیلدهای اضافی از تنظیمات
        if (!empty($this->options['extra_fields'])) {
            $extra = json_decode($this->options['extra_fields'], true);
            if (is_array($extra)) {
                foreach ($extra as $key => $value) {
                    $fields[$key] = (string) $value;
                }
            }
        }
        
        // مسیر مقصد
        $dest = trim($this->options['dest_relative_path']);
        if (!empty($dest)) {
            // اگر dest فولدر باشه، filename رو اضافه می‌کنیم
            $last_part = basename($dest);
            
            if (strpos($last_part, '.') === false) {
                // احتمالاً فولدر است
                $fields['relativePath'] = rtrim($dest, '/\\') . '/' . $filename;
            } else {
                // احتمالاً مسیر کامل فایل است
                $fields['relativePath'] = $dest;
            }
        } else {
            $fields['relativePath'] = $filename;
        }
        
        return $fields;
    }
    
    /**
     * آماده‌سازی هدرها
     * 
     * @return array
     */
    private function prepare_headers() {
        $headers = [
            'Accept' => 'application/json',
            'Expect' => '',
        ];
        
        // هدر Authentication
        if (!empty($this->options['header_name']) && !empty($this->options['token'])) {
            $headers[$this->options['header_name']] = 
                $this->options['token_prefix'] . $this->options['token'];
        }
        
        return $headers;
    }
    
    /**
     * ساخت multipart body دستی
     * 
     * @param array $fields
     * @param string $file_path
     * @param string $filename
     * @param string $mime
     * @param string $boundary
     * @return string
     */
    private function build_multipart_body($fields, $file_path, $filename, $mime, $boundary) {
        $eol = "\r\n";
        $body = '';
        
        // فیلدهای معمولی
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"{$eol}{$eol}";
            $body .= "{$value}{$eol}";
        }
        
        // فایل
        $field_name = $this->options['multipart_field'];
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"{$field_name}\"; filename=\"{$filename}\"{$eol}";
        $body .= "Content-Type: {$mime}{$eol}{$eol}";
        $body .= file_get_contents($file_path);
        $body .= $eol . "--{$boundary}--{$eol}";
        
        return $body;
    }
    
    /**
     * پردازش پاسخ سرور
     * 
     * @param array|WP_Error $response
     * @return bool
     */
    private function handle_response($response) {
        if (is_wp_error($response)) {
            FDU_Logger::error('خطای WP: ' . $response->get_error_message());
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        FDU_Logger::log('HTTP Status: ' . $http_code);
        
        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }
        
        // لاگ خطا
        FDU_Logger::error('خطا در آپلود: HTTP ' . $http_code);
        FDU_Logger::log('پاسخ: ' . substr($body, 0, 500));
        
        return false;
    }
    
    /**
     * تشخیص MIME type فایل
     * 
     * @param string $file_path
     * @return string
     */
    private function get_mime_type($file_path) {
        $filename = basename($file_path);
        
        if (preg_match('~\.sql\.gz$~i', $filename)) {
            return 'application/gzip';
        }
        
        if (preg_match('~\.zip$~i', $filename)) {
            return 'application/zip';
        }
        
        if (preg_match('~\.tar\.gz$~i', $filename)) {
            return 'application/gzip';
        }
        
        if (preg_match('~\.tar$~i', $filename)) {
            return 'application/x-tar';
        }
        
        // استفاده از mime_content_type اگر موجود باشه
        if (function_exists('mime_content_type')) {
            return mime_content_type($file_path);
        }
        
        return 'application/octet-stream';
    }
    
    /**
     * فرمت کردن حجم به واحد قابل خواندن
     * 
     * @param int $bytes
     * @return string
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' bytes';
    }
}
