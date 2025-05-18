<?php
// Doğrudan erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'dashboard';
$user = wp_get_current_user();
$user_info = get_userdata($user->ID);

global $wpdb;
$table_name = $wpdb->prefix . 'insurance_crm_representatives';
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_name WHERE user_id = %d",
    $user->ID
));
?>

<div class="insurance-crm-sidebar">
    <div class="sidebar-header">
        <div class="user-profile">
            <div class="profile-image">
                <?php echo get_avatar($user->ID, 80); ?>
            </div>
            <div class="profile-info">
                <h3><?php echo esc_html($user_info->display_name); ?></h3>
                <p><?php echo isset($representative->title) ? esc_html($representative->title) : 'Müşteri Temsilcisi'; ?></p>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-menu">
        <ul>
            <li class="<?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'dashboard', get_permalink()); ?>">
                    <i class="dashicons dashicons-dashboard"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="<?php echo ($current_page === 'customers') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'customers', get_permalink()); ?>">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Müşteriler</span>
                </a>
            </li>
            <li class="<?php echo ($current_page === 'policies') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'policies', get_permalink()); ?>">
                    <i class="dashicons dashicons-media-document"></i>
                    <span>Poliçeler</span>
                </a>
            </li>
            <li class="<?php echo ($current_page === 'tasks') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'tasks', get_permalink()); ?>">
                    <i class="dashicons dashicons-clipboard"></i>
                    <span>Görevler</span>
                </a>
            </li>
            <li class="<?php echo ($current_page === 'reports') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'reports', get_permalink()); ?>">
                    <i class="dashicons dashicons-chart-bar"></i>
                    <span>Raporlar</span>
                </a>
            </li>
            <li class="<?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                <a href="<?php echo add_query_arg('page', 'settings', get_permalink()); ?>">
                    <i class="dashicons dashicons-admin-generic"></i>
                    <span>Ayarlar</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <a href="<?php echo wp_logout_url(home_url('/temsilci-girisi/')); ?>" class="logout-btn">
            <i class="dashicons dashicons-exit"></i> Çıkış Yap
        </a>
    </div>
</div>