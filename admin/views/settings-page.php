<?php
/**
 * Settings Page - Main View
 * صفحه اصلی تنظیمات با تب‌بندی
 * 
 * @package Files_IR_Backup
 * @since 1.2.0
 */

if (!defined('ABSPATH')) exit;

$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
$page_slug = 'files-ir-wordpress-backup';
?>

<div class="wrap fdu-settings-wrap">
    <h1>
        <?php echo esc_html(get_admin_page_title()); ?>
        <small style="opacity:.6; font-size: 14px;">v1.2.0 - ساختار جدید با تب‌بندی</small>
    </h1>
    
    <!-- Navigation Tabs -->
    <nav class="nav-tab-wrapper fdu-nav-tabs">
        <?php foreach ($this->tabs as $tab_key => $tab_data): ?>
            <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=<?php echo esc_attr($tab_key); ?>" 
               class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr($tab_data['icon']); ?>"></span>
                <?php echo esc_html($tab_data['title']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    
    <!-- Tab Content -->
    <div class="fdu-tab-content">
        <?php
        // Load active tab
        $tab_file = dirname(__FILE__) . "/tabs/tab-{$active_tab}.php";
        
        if (file_exists($tab_file)) {
            include $tab_file;
        } else {
            echo '<div class="notice notice-error"><p>تب مورد نظر یافت نشد.</p></div>';
        }
        ?>
    </div>
</div>

<style>
.fdu-settings-wrap {
    margin-top: 20px;
}

.fdu-nav-tabs {
    margin: 20px 0;
    border-bottom: 1px solid #c3c4c7;
}

.fdu-nav-tabs .nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: 1px solid transparent;
    border-bottom: none;
    background: #fff;
    color: #50575e;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
}

.fdu-nav-tabs .nav-tab:hover {
    background: #f6f7f7;
    color: #135e96;
}

.fdu-nav-tabs .nav-tab-active {
    border-color: #c3c4c7;
    border-bottom-color: #f0f0f1;
    background: #f0f0f1;
    color: #1d2327;
    font-weight: 600;
}

.fdu-nav-tabs .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.fdu-tab-content {
    background: #fff;
    padding: 20px;
    border: 1px solid #c3c4c7;
    border-top: none;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.fdu-section {
    margin-bottom: 30px;
}

.fdu-section-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dcdcde;
}

.fdu-form-table th {
    width: 240px;
    padding: 15px 10px 15px 0;
}

.fdu-form-table td {
    padding: 15px 10px;
}

.fdu-help-text {
    display: block;
    margin-top: 5px;
    color: #646970;
    font-size: 13px;
}

.fdu-button-group {
    display: flex;
    gap: 10px;
    margin: 20px 0;
}

.fdu-info-box {
    background: #f0f6fc;
    border: 1px solid #c3e4fc;
    border-radius: 4px;
    padding: 15px;
    margin: 20px 0;
}

.fdu-info-box.warning {
    background: #fcf9e8;
    border-color: #f0e68c;
}

.fdu-info-box.success {
    background: #edfaef;
    border-color: #9ed9a3;
}

.fdu-code-block {
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    padding: 12px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
}
</style>
