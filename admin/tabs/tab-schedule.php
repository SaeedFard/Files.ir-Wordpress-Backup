<?php
if (!defined('ABSPATH')) exit;
$status = FDU_Scheduler::get_status();
?>
<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-clock"></span>
            تنظیمات زمان‌بندی
        </h2>
        
        <?php if ($status['next_scheduled_formatted']): ?>
            <div class="fdu-info-box success">
                <p><strong>✓ زمان‌بندی فعال است</strong></p>
                <p>اجرای بعدی: <strong><?php echo esc_html($status['next_scheduled_formatted']); ?></strong></p>
            </div>
        <?php endif; ?>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_schedule', 'fdu_schedule_section'); ?>
        </table>
    </div>
    
    <?php submit_button('ذخیره تنظیمات زمان‌بندی'); ?>
</form>
