<?php
if (!defined('ABSPATH')) exit;
?>
<form method="post" action="options.php">
    <?php settings_fields('fdu_settings_group'); ?>
    
    <div class="fdu-section">
        <h2 class="fdu-section-title">
            <span class="dashicons dashicons-database"></span>
            روش خروجی‌گیری از دیتابیس
        </h2>
        
        <table class="form-table fdu-form-table">
            <?php do_settings_fields('files-ir-wordpress-backup_database', 'fdu_database_section'); ?>
        </table>
    </div>
    
    <?php submit_button('ذخیره تنظیمات دیتابیس'); ?>
</form>
