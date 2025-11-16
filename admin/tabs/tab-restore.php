<?php
if (!defined('ABSPATH')) exit;

// دریافت لیست بکاپ‌ها
$restore_manager = new FDU_Restore_Manager(get_option('fdu_settings', []));
$local_backups = $restore_manager->get_local_backups();
$remote_backups = $restore_manager->get_remote_backups();
?>

<div class="fdu-section">
    <h2 class="fdu-section-title">
        <span class="dashicons dashicons-backup"></span>
        بازیابی از بکاپ
    </h2>
    
    <div class="fdu-info-box">
        <p><strong>⚠️ هشدار:</strong> قبل از بازیابی، یک بکاپ امنیتی از سایت فعلی گرفته می‌شود.</p>
        <p>بازیابی دیتابیس، تمام جداول فعلی را جایگزین می‌کند.</p>
        <p>بازیابی فایل‌ها، فایل‌های موجود را بازنویسی می‌کند.</p>
    </div>
</div>

<!-- بکاپ‌های محلی -->
<div class="fdu-section">
    <h3 class="fdu-section-title">
        <span class="dashicons dashicons-desktop"></span>
        بکاپ‌های محلی
    </h3>
    
    <div class="fdu-backup-tabs">
        <button class="fdu-tab-btn active" data-tab="local-db">
            دیتابیس (<?php echo count($local_backups['database']); ?>)
        </button>
        <button class="fdu-tab-btn" data-tab="local-files">
            فایل‌ها (<?php echo count($local_backups['files']); ?>)
        </button>
    </div>
    
    <!-- تب دیتابیس محلی -->
    <div class="fdu-tab-content" id="local-db">
        <?php if (empty($local_backups['database'])): ?>
            <p class="fdu-no-backups">هیچ بکاپ محلی از دیتابیس یافت نشد.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>نام فایل</th>
                        <th>حجم</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($local_backups['database'] as $backup): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($backup['filename']); ?></strong>
                            </td>
                            <td><?php echo size_format($backup['size']); ?></td>
                            <td><?php echo wp_date('Y/m/d - H:i', $backup['date']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=fdu_restore_db&file=' . urlencode($backup['filename'])),
                                    'fdu_restore'
                                )); ?>" 
                                   class="button button-primary"
                                   onclick="return confirm('آیا از بازیابی دیتابیس اطمینان دارید؟\n\nیک بکاپ امنیتی از دیتابیس فعلی گرفته می‌شود.')">
                                    <span class="dashicons dashicons-update"></span>
                                    بازیابی
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=fdu_download_backup&file=' . urlencode($backup['filename'])),
                                    'fdu_download'
                                )); ?>" 
                                   class="button">
                                    <span class="dashicons dashicons-download"></span>
                                    دانلود
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- تب فایل‌های محلی -->
    <div class="fdu-tab-content" id="local-files" style="display: none;">
        <?php if (empty($local_backups['files'])): ?>
            <p class="fdu-no-backups">هیچ بکاپ محلی از فایل‌ها یافت نشد.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>نام فایل</th>
                        <th>حجم</th>
                        <th>فرمت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($local_backups['files'] as $backup): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($backup['filename']); ?></strong>
                            </td>
                            <td><?php echo size_format($backup['size']); ?></td>
                            <td><?php echo strtoupper($backup['format']); ?></td>
                            <td><?php echo wp_date('Y/m/d - H:i', $backup['date']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=fdu_restore_files&file=' . urlencode($backup['filename'])),
                                    'fdu_restore'
                                )); ?>" 
                                   class="button button-primary"
                                   onclick="return confirm('آیا از بازیابی فایل‌ها اطمینان دارید؟\n\nفایل‌های موجود ممکن است بازنویسی شوند.')">
                                    <span class="dashicons dashicons-update"></span>
                                    بازیابی
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=fdu_download_backup&file=' . urlencode($backup['filename'])),
                                    'fdu_download'
                                )); ?>" 
                                   class="button">
                                    <span class="dashicons dashicons-download"></span>
                                    دانلود
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- بکاپ‌های Files.ir -->
<div class="fdu-section">
    <h3 class="fdu-section-title">
        <span class="dashicons dashicons-cloud"></span>
        بکاپ‌های Files.ir
    </h3>
    
    <?php if (empty(get_option('fdu_settings')['token'])): ?>
        <div class="fdu-info-box">
            <p>برای مشاهده بکاپ‌های Files.ir، ابتدا API Token را در <a href="?page=files-ir-wordpress-backup&tab=api">تنظیمات API</a> وارد کنید.</p>
        </div>
    <?php else: ?>
        <div class="fdu-backup-tabs">
            <button class="fdu-tab-btn active" data-tab="remote-db">
                دیتابیس (<?php echo count($remote_backups['database']); ?>)
            </button>
            <button class="fdu-tab-btn" data-tab="remote-files">
                فایل‌ها (<?php echo count($remote_backups['files']); ?>)
            </button>
        </div>
        
        <!-- تب دیتابیس ریموت -->
        <div class="fdu-tab-content" id="remote-db">
            <?php if (empty($remote_backups['database'])): ?>
                <p class="fdu-no-backups">هیچ بکاپی از دیتابیس در Files.ir یافت نشد.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>نام فایل</th>
                            <th>حجم</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($remote_backups['database'] as $backup): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($backup['filename']); ?></strong>
                                    <br>
                                    <small style="color: #666;">Files.ir ID: <?php echo esc_html($backup['id']); ?></small>
                                </td>
                                <td><?php echo size_format($backup['size']); ?></td>
                                <td><?php echo wp_date('Y/m/d - H:i', $backup['date']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=fdu_restore_db_remote&entry_id=' . $backup['id'] . '&filename=' . urlencode($backup['filename'])),
                                        'fdu_restore'
                                    )); ?>" 
                                       class="button button-primary"
                                       onclick="return confirm('فایل ابتدا از Files.ir دانلود و سپس بازیابی می‌شود.\n\nادامه می‌دهید؟')">
                                        <span class="dashicons dashicons-update"></span>
                                        دانلود و بازیابی
                                    </a>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=fdu_download_remote&entry_id=' . $backup['id'] . '&filename=' . urlencode($backup['filename'])),
                                        'fdu_download'
                                    )); ?>" 
                                       class="button">
                                        <span class="dashicons dashicons-download"></span>
                                        فقط دانلود
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- تب فایل‌های ریموت -->
        <div class="fdu-tab-content" id="remote-files" style="display: none;">
            <?php if (empty($remote_backups['files'])): ?>
                <p class="fdu-no-backups">هیچ بکاپی از فایل‌ها در Files.ir یافت نشد.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>نام فایل</th>
                            <th>حجم</th>
                            <th>فرمت</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($remote_backups['files'] as $backup): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($backup['filename']); ?></strong>
                                    <br>
                                    <small style="color: #666;">Files.ir ID: <?php echo esc_html($backup['id']); ?></small>
                                </td>
                                <td><?php echo size_format($backup['size']); ?></td>
                                <td><?php echo strtoupper($backup['format']); ?></td>
                                <td><?php echo wp_date('Y/m/d - H:i', $backup['date']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=fdu_restore_files_remote&entry_id=' . $backup['id'] . '&filename=' . urlencode($backup['filename'])),
                                        'fdu_restore'
                                    )); ?>" 
                                       class="button button-primary"
                                       onclick="return confirm('فایل ابتدا از Files.ir دانلود و سپس بازیابی می‌شود.\n\nادامه می‌دهید؟')">
                                        <span class="dashicons dashicons-update"></span>
                                        دانلود و بازیابی
                                    </a>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=fdu_download_remote&entry_id=' . $backup['id'] . '&filename=' . urlencode($backup['filename'])),
                                        'fdu_download'
                                    )); ?>" 
                                       class="button">
                                        <span class="dashicons dashicons-download"></span>
                                        فقط دانلود
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // تبدیل تب‌ها
    $('.fdu-tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        var container = $(this).closest('.fdu-section');
        
        // فعال کردن دکمه
        container.find('.fdu-tab-btn').removeClass('active');
        $(this).addClass('active');
        
        // نمایش محتوا
        container.find('.fdu-tab-content').hide();
        container.find('#' + tab).show();
    });
});
</script>

<style>
.fdu-backup-tabs {
    display: flex;
    gap: 10px;
    margin: 20px 0 15px 0;
    border-bottom: 1px solid #c3c4c7;
}

.fdu-tab-btn {
    padding: 10px 20px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 14px;
    color: #50575e;
    transition: all 0.2s;
}

.fdu-tab-btn:hover {
    color: #2271b1;
}

.fdu-tab-btn.active {
    color: #2271b1;
    border-bottom-color: #2271b1;
    font-weight: 600;
}

.fdu-no-backups {
    padding: 30px;
    text-align: center;
    color: #666;
    font-style: italic;
}

.wp-list-table th,
.wp-list-table td {
    padding: 12px 15px;
}

.wp-list-table .button {
    margin-left: 5px;
}

.wp-list-table .button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    margin-top: 2px;
}
</style>
