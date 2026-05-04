<?php
/**
 * Uploader V2 Class
 * Files.ir new upload API client (session-based, supports s3-multipart for large files)
 *
 * Flow:
 *   1. POST /uploads-new/init -> server returns uploadSessionId + uploadMode
 *   2. Dispatch by uploadMode:
 *      - 'single'        : POST /uploads-new/{sessionId}/file (multipart)
 *      - 'tus'           : create TUS upload, PATCH chunks with Upload-Offset
 *      - 's3-single'     : PUT to signed S3 URL
 *      - 's3-multipart'  : sign N parts, PUT each, collect ETags
 *   3. POST /uploads-new/{sessionId}/complete -> final fileEntry
 *
 * @package Files_IR_Backup
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Uploader_V2 {

    /**
     * Plugin options
     */
    private $options;

    /**
     * Files.ir API base URL
     */
    private $api_base = 'https://my.files.ir/api/v1';

    /**
     * TUS protocol version (used in tus mode)
     */
    const TUS_VERSION = '1.0.0';

    /**
     * TUS chunk size (bytes) — 8 MB matches reference example
     */
    const TUS_CHUNK_SIZE = 8388608;

    public function __construct($options = []) {
        $this->options = $options;
    }

    /**
     * Upload a file using the new API.
     * Public surface compatible with FDU_Uploader::upload().
     *
     * @param string $file_path
     * @param array  $metadata (optional, reserved)
     * @return bool
     */
    public function upload($file_path, $metadata = []) {
        if (!file_exists($file_path)) {
            FDU_Logger::error('Upload file not found: ' . $file_path);
            return false;
        }

        if (!$this->validate_settings()) {
            return false;
        }

        $file_size = @filesize($file_path);
        $filename  = basename($file_path);

        FDU_Logger::log('=== Starting upload (new API) ===');
        FDU_Logger::log('File: ' . $filename);
        FDU_Logger::log('Size: ' . $this->format_bytes($file_size));

        // Step 1: init session
        $session = $this->init_session($filename, $file_size);

        if (!$session) {
            return false;
        }

        $session_id = isset($session['uploadSessionId']) ? $session['uploadSessionId'] : '';
        $mode       = isset($session['uploadMode']) ? $session['uploadMode'] : '';

        FDU_Logger::log("Session ID: {$session_id}");
        FDU_Logger::log("Upload mode (chosen by server): {$mode}");

        // Step 2: dispatch by mode
        $result = false;

        try {
            switch ($mode) {
                case 'single':
                    $result = $this->upload_single_api($session, $file_path);
                    break;

                case 's3-single':
                    $result = $this->upload_s3_single($session, $file_path);
                    break;

                case 's3-multipart':
                    $result = $this->upload_s3_multipart($session, $file_path, $file_size);
                    break;

                case 'tus':
                    $result = $this->upload_tus($session, $file_path, $file_size);
                    break;

                default:
                    FDU_Logger::error("Unsupported upload mode returned by API: {$mode}");
                    $this->abort_session($session_id);
                    return false;
            }
        } catch (Exception $e) {
            FDU_Logger::error('Upload exception: ' . $e->getMessage());
            $this->abort_session($session_id);
            return false;
        }

        if ($result) {
            FDU_Logger::success('✅ Upload completed successfully');
        } else {
            FDU_Logger::error('❌ Upload failed');
            $this->abort_session($session_id);
        }

        return $result;
    }

    // ========================================
    // Step 1: Init session
    // ========================================

    /**
     * Init upload session.
     *
     * @param string $filename
     * @param int    $size
     * @return array|false response body on success
     */
    private function init_session($filename, $size) {
        $payload = [
            'filename' => $filename,
            'size'     => $size,
        ];

        // parentId is required for placement in a specific folder
        $parent_id = isset($this->options['parent_folder_id'])
            ? intval($this->options['parent_folder_id'])
            : 0;

        if ($parent_id > 0) {
            $payload['parentId'] = $parent_id;
        }

        FDU_Logger::log('POST /uploads-new/init');

        $response = $this->json_request('POST', $this->api_base . '/uploads-new/init', $payload, [201]);

        if (!$response) {
            FDU_Logger::error('Session init failed');
            return false;
        }

        if (!isset($response['body']['uploadSessionId'], $response['body']['uploadMode'])) {
            FDU_Logger::error('Init response missing required fields');
            return false;
        }

        return $response['body'];
    }

    // ========================================
    // Mode: single (small file, non-S3, via API)
    // ========================================

    private function upload_single_api($session, $file_path) {
        $session_id = $session['uploadSessionId'];
        $filename   = basename($file_path);
        $url        = $this->api_base . "/uploads-new/{$session_id}/file";

        FDU_Logger::log("POST /uploads-new/{$session_id}/file (mode=single)");

        if (!class_exists('CURLFile')) {
            FDU_Logger::error('CURLFile not available');
            return false;
        }

        $ch = curl_init();
        $headers = $this->auth_headers_array();
        $headers[] = 'Expect:';

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => [
                'file' => new CURLFile($file_path, $this->get_mime_type($file_path), $filename),
            ],
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            FDU_Logger::error('cURL error: ' . $error);
            return false;
        }

        if ($code < 200 || $code >= 300) {
            FDU_Logger::error("HTTP {$code}: " . substr($body, 0, 300));
            return false;
        }

        // The single endpoint typically completes the upload by itself.
        // No separate complete call needed for this mode.
        FDU_Logger::log("Single upload OK (HTTP {$code})");

        return true;
    }

    // ========================================
    // Mode: s3-single (PUT to signed S3 URL)
    // ========================================

    private function upload_s3_single($session, $file_path) {
        $session_id = $session['uploadSessionId'];
        $next       = isset($session['next']) ? $session['next'] : [];

        $url     = isset($next['url']) ? $next['url'] : '';
        $headers = isset($next['headers']) ? $next['headers'] : [];

        if (empty($url)) {
            FDU_Logger::error('s3-single: missing signed URL in init response');
            return false;
        }

        $size = filesize($file_path);

        FDU_Logger::log("PUT signed S3 URL (mode=s3-single, {$this->format_bytes($size)})");

        $put_response = $this->put_stream($url, $file_path, 0, $size, $this->format_headers_for_curl($headers));

        if (!$put_response) {
            FDU_Logger::error('s3-single PUT failed');
            return false;
        }

        // Step 3: complete
        $complete_url = isset($next['completeUrl'])
            ? $next['completeUrl']
            : "/uploads-new/{$session_id}/complete";

        return $this->complete_session($session_id, [], $complete_url);
    }

    // ========================================
    // Mode: s3-multipart (the important one for large backups)
    // ========================================

    private function upload_s3_multipart($session, $file_path, $file_size) {
        $session_id = $session['uploadSessionId'];

        $part_size = isset($session['partSize']) ? intval($session['partSize']) : 0;

        if ($part_size <= 0) {
            FDU_Logger::error('s3-multipart: invalid partSize in init response');
            return false;
        }

        $part_count   = (int) ceil($file_size / $part_size);
        $part_numbers = range(1, $part_count);

        FDU_Logger::log(sprintf(
            's3-multipart: %d parts × %s = %s',
            $part_count,
            $this->format_bytes($part_size),
            $this->format_bytes($file_size)
        ));

        // Step 2a: sign parts
        FDU_Logger::log("POST /uploads-new/{$session_id}/parts/sign");

        $sign_response = $this->json_request(
            'POST',
            $this->api_base . "/uploads-new/{$session_id}/parts/sign",
            ['partNumbers' => $part_numbers],
            [200]
        );

        if (!$sign_response || !isset($sign_response['body']['urls'])) {
            FDU_Logger::error('Failed to get signed URLs');
            return false;
        }

        // Build map: partNumber -> url
        $urls_by_part = [];
        foreach ($sign_response['body']['urls'] as $item) {
            if (isset($item['partNumber'], $item['url'])) {
                $urls_by_part[intval($item['partNumber'])] = (string) $item['url'];
            }
        }

        if (count($urls_by_part) !== $part_count) {
            FDU_Logger::error("Got " . count($urls_by_part) . " URLs but expected {$part_count}");
            return false;
        }

        // Step 2b: PUT each part to its signed URL, collect ETags
        $parts = [];
        $uploaded_bytes = 0;

        foreach ($part_numbers as $part_num) {
            if (!isset($urls_by_part[$part_num])) {
                FDU_Logger::error("Missing signed URL for part {$part_num}");
                return false;
            }

            $offset = ($part_num - 1) * $part_size;
            $bytes  = min($part_size, $file_size - $offset);

            FDU_Logger::log(sprintf(
                "PUT S3 part %d/%d (offset=%s, bytes=%s)",
                $part_num,
                $part_count,
                $this->format_bytes($offset),
                $this->format_bytes($bytes)
            ));

            $put_response = $this->put_stream(
                $urls_by_part[$part_num],
                $file_path,
                $offset,
                $bytes,
                [] // S3 signed URLs don't need extra headers
            );

            if (!$put_response) {
                FDU_Logger::error("Part {$part_num} upload failed");
                return false;
            }

            $etag = $this->extract_etag($put_response['headers']);

            if (empty($etag)) {
                FDU_Logger::error("S3 did not return ETag for part {$part_num}");
                return false;
            }

            $parts[] = [
                'PartNumber' => $part_num,
                'ETag'       => $etag,
            ];

            $uploaded_bytes += $bytes;

            // Progress every 10%
            $percent = ($uploaded_bytes / $file_size) * 100;
            FDU_Logger::log(sprintf("  Progress: %.1f%%", $percent));
        }

        // Step 3: complete
        FDU_Logger::log("POST /uploads-new/{$session_id}/complete");

        return $this->complete_session($session_id, ['parts' => $parts]);
    }

    // ========================================
    // Mode: tus (resumable, non-S3)
    // ========================================

    private function upload_tus($session, $file_path, $file_size) {
        $session_id = $session['uploadSessionId'];
        $next       = isset($session['next']) ? $session['next'] : [];

        $tus_url  = isset($next['url']) ? $next['url'] : '';
        $metadata = isset($next['metadata']) ? $next['metadata'] : [];

        if (empty($tus_url)) {
            FDU_Logger::error('tus: missing endpoint URL in init response');
            return false;
        }

        FDU_Logger::log("POST TUS create: {$tus_url}");

        // Create TUS upload resource
        $create_headers = array_merge($this->auth_headers_array(), [
            'Tus-Resumable: ' . self::TUS_VERSION,
            'Upload-Length: ' . $file_size,
            'Upload-Metadata: ' . $this->encode_tus_metadata($metadata),
            'Content-Length: 0',
            'Expect:',
        ]);

        $create_response = $this->raw_request('POST', $tus_url, [
            'headers' => $create_headers,
            'body'    => '',
        ]);

        if (!$create_response || $create_response['code'] !== 201) {
            FDU_Logger::error('TUS create failed');
            return false;
        }

        // Get upload URL from Location header
        $upload_url = $this->extract_header($create_response['headers'], 'location');

        if (empty($upload_url)) {
            FDU_Logger::error('TUS create: no Location header returned');
            return false;
        }

        $upload_url = $this->resolve_url($upload_url);

        // PATCH chunks
        $offset       = 0;
        $chunk_num    = 1;
        $chunk_count  = (int) ceil($file_size / self::TUS_CHUNK_SIZE);

        while ($offset < $file_size) {
            $bytes = min(self::TUS_CHUNK_SIZE, $file_size - $offset);

            FDU_Logger::log(sprintf(
                "PATCH TUS chunk %d/%d (offset=%s, bytes=%s)",
                $chunk_num,
                $chunk_count,
                $this->format_bytes($offset),
                $this->format_bytes($bytes)
            ));

            $patch_headers = array_merge($this->auth_headers_array(), [
                'Tus-Resumable: ' . self::TUS_VERSION,
                'Upload-Offset: ' . $offset,
                'Content-Type: application/offset+octet-stream',
                'Content-Length: ' . $bytes,
                'Expect:',
            ]);

            $patch_response = $this->put_stream($upload_url, $file_path, $offset, $bytes, $patch_headers, 'PATCH');

            if (!$patch_response || $patch_response['code'] !== 204) {
                FDU_Logger::error("TUS PATCH failed at chunk {$chunk_num}");
                return false;
            }

            $new_offset = $this->extract_header($patch_response['headers'], 'upload-offset');

            if ($new_offset === null || intval($new_offset) <= $offset) {
                FDU_Logger::error('TUS returned invalid Upload-Offset');
                return false;
            }

            $offset    = intval($new_offset);
            $chunk_num++;
        }

        // Step 3: complete
        $upload_key = basename(parse_url($upload_url, PHP_URL_PATH));
        $complete_url = isset($next['completeUrl'])
            ? $next['completeUrl']
            : "/uploads-new/{$session_id}/complete";

        return $this->complete_session($session_id, ['uploadKey' => $upload_key], $complete_url);
    }

    // ========================================
    // Step 3: Complete session
    // ========================================

    /**
     * @param string $session_id
     * @param array  $payload
     * @param string|null $custom_url (relative or absolute)
     * @return bool
     */
    private function complete_session($session_id, $payload = [], $custom_url = null) {
        $url = $custom_url
            ? $this->resolve_url($custom_url)
            : $this->api_base . "/uploads-new/{$session_id}/complete";

        $response = $this->json_request('POST', $url, $payload, [200, 201]);

        if (!$response) {
            FDU_Logger::error('Complete request failed');
            return false;
        }

        FDU_Logger::log('✓ Complete OK');

        return true;
    }

    /**
     * Abort session (best-effort cleanup on error).
     *
     * @param string $session_id
     */
    private function abort_session($session_id) {
        if (empty($session_id)) {
            return;
        }

        FDU_Logger::log("DELETE /uploads-new/{$session_id} (cleanup)");

        $response = wp_remote_request($this->api_base . "/uploads-new/{$session_id}", [
            'method'  => 'DELETE',
            'headers' => $this->auth_headers_assoc(),
            'timeout' => 15,
        ]);

        // Best-effort, ignore failures
    }

    // ========================================
    // Settings validation
    // ========================================

    private function validate_settings() {
        if (empty($this->options['token'])) {
            FDU_Logger::error('API token not configured');
            return false;
        }

        if (!function_exists('curl_init')) {
            FDU_Logger::error('cURL extension not available');
            return false;
        }

        return true;
    }

    // ========================================
    // HTTP helpers
    // ========================================

    /**
     * JSON request with response decoding.
     *
     * @param string $method
     * @param string $url     absolute URL
     * @param array  $payload
     * @param array  $expected_codes
     * @return array|false ['code' => int, 'headers' => array, 'body' => array]
     */
    private function json_request($method, $url, $payload, $expected_codes = [200]) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $headers = array_merge($this->auth_headers_array(), [
            'Content-Type: application/json',
            'Expect:',
        ]);

        $response = $this->raw_request($method, $url, [
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (!$response) {
            return false;
        }

        if (!in_array($response['code'], $expected_codes, true)) {
            FDU_Logger::error("Expected " . implode('/', $expected_codes) . ", got HTTP {$response['code']}: " . substr($response['body'], 0, 300));
            return false;
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            FDU_Logger::error('Expected JSON response, got: ' . substr($response['body'], 0, 200));
            return false;
        }

        return [
            'code'    => $response['code'],
            'headers' => $response['headers'],
            'body'    => $decoded,
        ];
    }

    /**
     * Raw cURL request (no streaming).
     *
     * @param string $method
     * @param string $url
     * @param array  $opts ['headers' => array, 'body' => string]
     * @return array|false ['code' => int, 'headers' => array, 'body' => string]
     */
    private function raw_request($method, $url, $opts = []) {
        $ch = curl_init();

        $resp_headers = [];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$resp_headers) {
                $length = strlen($line);
                $line   = trim($line);
                if ($line !== '' && strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $resp_headers[strtolower(trim($name))][] = trim($value);
                }
                return $length;
            },
        ]);

        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }

        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            FDU_Logger::error("cURL failed: {$error}");
            return false;
        }

        return [
            'code'    => $code,
            'headers' => $resp_headers,
            'body'    => $body,
        ];
    }

    /**
     * Stream a byte range of a file via PUT (or PATCH for TUS).
     *
     * @param string $url
     * @param string $file_path
     * @param int    $offset
     * @param int    $bytes
     * @param array  $headers
     * @param string $method
     * @return array|false ['code' => int, 'headers' => array, 'body' => string]
     */
    private function put_stream($url, $file_path, $offset, $bytes, $headers = [], $method = 'PUT') {
        $fp = @fopen($file_path, 'rb');

        if (!$fp) {
            FDU_Logger::error('Cannot open file for streaming');
            return false;
        }

        if (@fseek($fp, $offset) !== 0) {
            fclose($fp);
            FDU_Logger::error("fseek to {$offset} failed");
            return false;
        }

        $ch = curl_init();
        $resp_headers = [];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => $bytes,
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$resp_headers) {
                $length = strlen($line);
                $line   = trim($line);
                if ($line !== '' && strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $resp_headers[strtolower(trim($name))][] = trim($value);
                }
                return $length;
            },
        ]);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($body === false) {
            FDU_Logger::error("cURL stream failed: {$error}");
            return false;
        }

        if ($code < 200 || $code >= 300) {
            FDU_Logger::error("Stream HTTP {$code}: " . substr($body, 0, 200));
            return false;
        }

        return [
            'code'    => $code,
            'headers' => $resp_headers,
            'body'    => $body,
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Extract ETag from response headers (case-insensitive, strips quotes).
     *
     * @param array $headers
     * @return string
     */
    private function extract_etag($headers) {
        $etag = $this->extract_header($headers, 'etag');

        if (empty($etag)) {
            return '';
        }

        // S3 ETags are quoted; the complete call accepts them with or without
        // quotes, but we return as-is from the response for safety.
        return $etag;
    }

    /**
     * Get first value of a response header (case-insensitive).
     *
     * @param array  $headers
     * @param string $name
     * @return string|null
     */
    private function extract_header($headers, $name) {
        $name = strtolower($name);

        if (isset($headers[$name][0])) {
            return $headers[$name][0];
        }

        return null;
    }

    /**
     * Encode TUS metadata: "key1 base64,key2 base64".
     *
     * @param array $metadata
     * @return string
     */
    private function encode_tus_metadata($metadata) {
        $chunks = [];

        foreach ($metadata as $key => $value) {
            $chunks[] = $key . ' ' . base64_encode((string) $value);
        }

        return implode(',', $chunks);
    }

    /**
     * Resolve a possibly-relative URL against the API base.
     *
     * @param string $url
     * @return string
     */
    private function resolve_url($url) {
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }

        if (strpos($url, '/') === 0) {
            $parts = wp_parse_url($this->api_base);
            $port  = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $parts['scheme'] . '://' . $parts['host'] . $port . $url;
        }

        return rtrim($this->api_base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Build auth headers as cURL array.
     *
     * @return array
     */
    private function auth_headers_array() {
        $token  = $this->options['token'];
        $prefix = isset($this->options['token_prefix']) ? $this->options['token_prefix'] : 'Bearer ';

        return [
            'Authorization: ' . $prefix . $token,
            'Accept: application/json',
        ];
    }

    /**
     * Build auth headers as associative array (for wp_remote_*).
     *
     * @return array
     */
    private function auth_headers_assoc() {
        $token  = $this->options['token'];
        $prefix = isset($this->options['token_prefix']) ? $this->options['token_prefix'] : 'Bearer ';

        return [
            'Authorization' => $prefix . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Convert headers from associative {name: value} to cURL array.
     *
     * @param array $headers
     * @return array
     */
    private function format_headers_for_curl($headers) {
        $out = [];

        if (!is_array($headers)) {
            return $out;
        }

        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }

        return $out;
    }

    /**
     * Detect MIME type for upload.
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

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($file_path);
            if ($detected) {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Format bytes for human-readable display.
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
