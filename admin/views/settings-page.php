<?php
if (!defined('ABSPATH')) exit;
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
$page_slug = 'files-ir-wordpress-backup';
?>

<div class="wrap fdu-settings-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <nav class="nav-tab-wrapper fdu-nav-tabs">
        <?php foreach ($this->tabs as $tab_key => $tab_data): ?>
            <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=<?php echo esc_attr($tab_key); ?>" 
               class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr($tab_data['icon']); ?>"></span>
                <?php echo esc_html($tab_data['title']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    
    <div class="fdu-tab-content">
        <?php
        $tab_file = dirname(dirname(__FILE__)) . "/tabs/tab-{$active_tab}.php";
        if (file_exists($tab_file)) {
            include $tab_file;
        }
        ?>
    </div>
</div>
