<?php
/**
 * Folder Manager Class
 * مدیریت پوشه مقصد در Files.ir (ساخت/پیدا کردن parentId)
 *
 * @package Files_IR_Backup
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

class FDU_Folder_Manager {

    /**
     * Plugin options
     */
    private $options;

    /**
     * Files.ir API base URL
     */
    private $api_base = 'https://my.files.ir/api/v1';

    /**
     * Cache key for parent folder ID
     */
    const CACHE_KEY = 'parent_folder_id';

    public function __construct($options = []) {
        $this->options = $options;
    }

    /**
     * Ensure parent folder exists, return its ID.
     * Creates the folder hierarchy if missing.
     * Caches the result in plugin options.
     *
     * @return int|false parent_folder_id or false on failure
     */
    public function ensure_parent_folder() {
        $path = isset($this->options['parent_folder_path'])
            ? trim($this->options['parent_folder_path'], '/\\ ')
            : '';

        // No path means root upload
        if ($path === '') {
            FDU_Logger::log('No parent folder path configured. Uploads will go to root.');
            return null;
        }

        // Check cache first
        $cached_id = isset($this->options['parent_folder_id'])
            ? intval($this->options['parent_folder_id'])
            : 0;

        if ($cached_id > 0) {
            // Verify cached folder still exists
            if ($this->verify_folder_exists($cached_id)) {
                FDU_Logger::log("Using cached parent folder ID: {$cached_id}");
                return $cached_id;
            }

            FDU_Logger::warning("Cached folder {$cached_id} no longer exists. Re-creating...");
        }

        // Build folder hierarchy from path (e.g. "wp-backups/site1")
        $segments = array_filter(explode('/', str_replace('\\', '/', $path)));
        $parent_id = null;

        foreach ($segments as $segment) {
            $folder_id = $this->find_or_create_folder($segment, $parent_id);

            if (!$folder_id) {
                FDU_Logger::error("Failed to create folder segment: {$segment}");
                return false;
            }

            $parent_id = $folder_id;
        }

        // Cache the final folder ID
        $this->cache_folder_id($parent_id);

        FDU_Logger::success("✓ Parent folder ready (ID: {$parent_id})");

        return $parent_id;
    }

    /**
     * Find folder by name under given parent, or create it.
     *
     * @param string $name
     * @param int|null $parent_id
     * @return int|false folder ID
     */
    public function find_or_create_folder($name, $parent_id = null) {
        $existing = $this->find_folder_by_name($name, $parent_id);

        if ($existing) {
            FDU_Logger::log("Found existing folder '{$name}' (ID: {$existing})");
            return $existing;
        }

        return $this->create_folder($name, $parent_id);
    }

    /**
     * Search for a folder by name under given parent.
     *
     * @param string $name
     * @param int|null $parent_id
     * @return int|false folder ID or false if not found
     */
    public function find_folder_by_name($name, $parent_id = null) {
        $params = [
            'type'    => 'folder',
            'query'   => $name,
            'perPage' => 50,
        ];

        if ($parent_id !== null) {
            $params['parentIds[]'] = $parent_id;
        }

        $url = add_query_arg($params, $this->api_base . '/drive/file-entries');

        $response = wp_remote_get($url, [
            'headers' => $this->auth_headers(),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            FDU_Logger::error('Folder search error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            FDU_Logger::error("Folder search HTTP {$code}");
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Response can be either an array directly or wrapped in 'data'
        $entries = isset($data['data']) ? $data['data'] : $data;

        if (!is_array($entries)) {
            return false;
        }

        // Match exactly by name (case-sensitive) and verify parent
        foreach ($entries as $entry) {
            if (!isset($entry['name']) || $entry['name'] !== $name) {
                continue;
            }

            // Verify parent matches (null/0 means root)
            $entry_parent = isset($entry['parent_id']) ? intval($entry['parent_id']) : null;

            if ($parent_id === null && ($entry_parent === null || $entry_parent === 0)) {
                return intval($entry['id']);
            }

            if ($parent_id !== null && $entry_parent === intval($parent_id)) {
                return intval($entry['id']);
            }
        }

        return false;
    }

    /**
     * Create a new folder.
     *
     * @param string $name
     * @param int|null $parent_id
     * @return int|false new folder ID
     */
    public function create_folder($name, $parent_id = null) {
        $payload = ['name' => $name];

        if ($parent_id !== null) {
            $payload['parentId'] = $parent_id;
        }

        FDU_Logger::log("Creating folder: '{$name}'" . ($parent_id ? " under {$parent_id}" : ' at root'));

        $response = wp_remote_post($this->api_base . '/folders', [
            'headers' => array_merge($this->auth_headers(), [
                'Content-Type' => 'application/json',
            ]),
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            FDU_Logger::error('Folder creation error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200 && $code !== 201) {
            FDU_Logger::error("Folder creation HTTP {$code}: " . substr($body, 0, 300));
            return false;
        }

        $data = json_decode($body, true);

        // Response structure: { status: success, folder: { id, ... } }
        if (isset($data['folder']['id'])) {
            $id = intval($data['folder']['id']);
            FDU_Logger::success("✓ Created folder '{$name}' (ID: {$id})");
            return $id;
        }

        FDU_Logger::error('Folder creation: unexpected response: ' . substr($body, 0, 300));
        return false;
    }

    /**
     * Verify that a folder ID still exists (lightweight check).
     *
     * @param int $folder_id
     * @return bool
     */
    public function verify_folder_exists($folder_id) {
        // Lightweight check via list endpoint with ID filter
        $url = add_query_arg([
            'parentIds[]' => $folder_id,
            'perPage'     => 1,
        ], $this->api_base . '/drive/file-entries');

        $response = wp_remote_get($url, [
            'headers' => $this->auth_headers(),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            // Network error — assume folder exists, don't invalidate cache
            return true;
        }

        $code = wp_remote_retrieve_response_code($response);

        // 200 = folder exists (even if empty); 404/422 = doesn't exist
        return ($code === 200);
    }

    /**
     * Reset cached folder ID (forces re-creation on next backup).
     */
    public function reset_cache() {
        $opts = get_option('fdu_settings', []);
        $opts['parent_folder_id'] = 0;
        update_option('fdu_settings', $opts);
        FDU_Logger::log('Parent folder cache reset.');
    }

    /**
     * Cache the resolved folder ID in plugin options.
     *
     * @param int $folder_id
     */
    private function cache_folder_id($folder_id) {
        $opts = get_option('fdu_settings', []);
        $opts['parent_folder_id'] = intval($folder_id);
        update_option('fdu_settings', $opts);
    }

    /**
     * Build authentication headers.
     *
     * @return array
     */
    private function auth_headers() {
        $token  = isset($this->options['token']) ? $this->options['token'] : '';
        $prefix = isset($this->options['token_prefix']) ? $this->options['token_prefix'] : 'Bearer ';

        return [
            'Authorization' => $prefix . $token,
            'Accept'        => 'application/json',
        ];
    }
}
