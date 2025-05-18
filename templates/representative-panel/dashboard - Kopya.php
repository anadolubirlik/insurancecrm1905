<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$user = wp_get_current_user();
if (!in_array('insurance_representative', (array)$user->roles)) {
    wp_safe_redirect(home_url());
    exit;
}

$current_user = wp_get_current_user();
$current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';

global $wpdb;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id = %d",
    $representative->id
));

$this_month_start = date('Y-m-01 00:00:00');
$this_month_end = date('Y-m-t 23:59:59');
$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id = %d 
     AND created_at BETWEEN %s AND %s",
    $representative->id,
    $this_month_start,
    $this_month_end
));
$new_customers = $new_customers ?: 0;
$customer_increase_rate = $total_customers > 0 ? ($new_customers / $total_customers) * 100 : 0;

$total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d",
    $representative->id
));

$new_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s",
    $representative->id,
    $this_month_start,
    $this_month_end
));
$new_policies = $new_policies ?: 0;
$policy_increase_rate = $total_policies > 0 ? ($new_policies / $total_policies) * 100 : 0;

$total_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(premium_amount) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d",
    $representative->id
));
if ($total_premium === null) $total_premium = 0;

$new_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(premium_amount) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s",
    $representative->id,
    $this_month_start,
    $this_month_end
));
$new_premium = $new_premium ?: 0;
$premium_increase_rate = $total_premium > 0 ? ($new_premium / $total_premium) * 100 : 0;

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(premium_amount) FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s",
    $representative->id,
    $current_month_start . ' 00:00:00',
    $this_month_end . ' 23:59:59'
));

if ($current_month_premium === null) $current_month_premium = 0;

$monthly_target = $representative->monthly_target > 0 ? $representative->monthly_target : 1;
$achievement_rate = ($current_month_premium / $monthly_target) * 100;
$achievement_rate = min(100, $achievement_rate);

$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id = %d
     ORDER BY p.created_at DESC
     LIMIT 5",
    $representative->id
));

$monthly_production_data = array();
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $monthly_production_data[$month_year] = 0;
}

try {
    $actual_data = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                COALESCE(SUM(premium_amount), 0) as total
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
         GROUP BY month_year
         ORDER BY month_year ASC",
        $representative->id
    ));
    
    foreach ($actual_data as $data) {
        if (isset($monthly_production_data[$data->month_year])) {
            $monthly_production_data[$data->month_year] = (float)$data->total;
        }
    }
} catch (Exception $e) {
    error_log('Üretim verileri çekilirken hata: ' . $e->getMessage());
}

$monthly_production = array();
foreach ($monthly_production_data as $month_year => $total) {
    $monthly_production[] = array(
        'month' => $month_year,
        'total' => $total
    );
}

if ($wpdb->last_error) {
    error_log('SQL Hatası: ' . $wpdb->last_error);
}

$upcoming_renewals = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id = %d 
     AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY p.end_date ASC
     LIMIT 5",
    $representative->id
));

$expired_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id = %d 
     AND p.end_date < CURDATE()
     AND p.status != 'iptal'
     ORDER BY p.end_date DESC
     LIMIT 5",
    $representative->id
));

$notification_count = 0;
$notifications_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}insurance_crm_notifications'") === $wpdb->prefix . 'insurance_crm_notifications';

if ($notifications_table_exists) {
    $notification_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_notifications
         WHERE user_id = %d AND is_read = 0",
        $current_user->ID
    ));
    if ($notification_count === null) $notification_count = 0;
}

$upcoming_tasks_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id = %d 
     AND status = 'pending'
     AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
    $representative->id
));
if ($upcoming_tasks_count === null) $upcoming_tasks_count = 0;

$total_notification_count = $notification_count + $upcoming_tasks_count;

$upcoming_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id = %d 
     AND t.status = 'pending'
     AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY t.due_date ASC
     LIMIT 5",
    $representative->id
));

$current_month_start = date('Y-m-01');
$next_month_end = date('Y-m-t', strtotime('+1 month'));

// Düzeltildi: Yer belirticileri 3 tane (%d, %s, %s) ve parametreler eşleştirildi
$calendar_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(due_date, '%Y-%m-%d') as task_date, COUNT(*) as task_count
     FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id = %d
     AND status IN ('pending', 'in_progress')
     AND due_date BETWEEN %s AND %s
     GROUP BY DATE(due_date)
     ORDER BY due_date ASC",
    $representative->id,
    $current_month_start . ' 00:00:00',
    $next_month_end . ' 23:59:59'
));

if ($wpdb->last_error) {
    error_log('Takvim Görev Sorgusu Hatası: ' . $wpdb->last_error);
}

error_log('Çekilen takvim görevleri: ' . print_r($calendar_tasks, true));

$upcoming_tasks_list = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id = %d 
     AND t.status IN ('pending', 'in_progress')
     AND t.due_date BETWEEN %s AND %s
     ORDER BY t.due_date ASC
     LIMIT 5",
    $representative->id,
    $current_month_start . ' 00:00:00',
    $next_month_end . ' 23:59:59'
));

if ($wpdb->last_error) {
    error_log('Yaklaşan Görevler Sorgusu Hatası: ' . $wpdb->last_error);
}

error_log('Yaklaşan görevler listesi: ' . print_r($upcoming_tasks_list, true));

$search_results = array();
if ($current_view == 'search' && isset($_GET['keyword']) && !empty(trim($_GET['keyword']))) {
    $keyword = sanitize_text_field($_GET['keyword']);
    $search_query = "
        SELECT c.*, p.policy_number, CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name
        FROM {$wpdb->prefix}insurance_crm_customers c
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON c.id = p.customer_id
        WHERE c.representative_id = %d
        AND (
            CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) LIKE %s
            OR TRIM(c.tc_identity) LIKE %s
            OR TRIM(c.children_tc_identities) LIKE %s
            OR TRIM(p.policy_number) LIKE %s
        )
        GROUP BY c.id
        ORDER BY c.first_name ASC
        LIMIT 20
    ";
    
    $search_results = $wpdb->get_results($wpdb->prepare(
        $search_query,
        $representative->id,
        '%' . $wpdb->esc_like($keyword) . '%',
        '%' . $wpdb->esc_like($keyword) . '%',
        '%' . $wpdb->esc_like($keyword) . '%',
        '%' . $wpdb->esc_like($keyword) . '%'
    ));

    if ($wpdb->last_error) {
        error_log('Arama Sorgusu Hatası: ' . $wpdb->last_error);
    }
}

function generate_panel_url($view, $action = '', $id = '', $additional_params = array()) {
    $base_url = get_permalink();
    $query_args = array();
    
    if ($view !== 'dashboard') {
        $query_args['view'] = $view;
    }
    
    if (!empty($action)) {
        $query_args['action'] = $action;
    }
    
    if (!empty($id)) {
        $query_args['id'] = $id;
    }
    
    if (!empty($additional_params) && is_array($additional_params)) {
        $query_args = array_merge($query_args, $additional_params);
    }
    
    if (empty($query_args)) {
        return $base_url;
    }
    
    return add_query_arg($query_args, $base_url);
}

add_action('wp_enqueue_scripts', 'insurance_crm_rep_panel_scripts');
function insurance_crm_rep_panel_scripts() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true);
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <?php wp_head(); ?>
</head>

<body class="insurance-crm-page">
    <div class="insurance-crm-sidenav">
        <div class="sidenav-header">
            <div class="sidenav-logo">
                <?php 
                $company_settings = get_option('insurance_crm_settings');
                $logo_url = !empty($company_settings['company_logo']) ? $company_settings['company_logo'] : plugins_url('/assets/images/insurance-logo.png', dirname(dirname(__FILE__)));
                ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
            </div>
            <h3>Sigorta CRM</h3>
        </div>
        
        <div class="sidenav-user">
            <div class="user-avatar">
                <?php echo get_avatar($current_user->ID, 64); ?>
            </div>
            <div class="user-info">
                <h4><?php echo esc_html($current_user->display_name); ?></h4>
                <span><?php echo esc_html($representative->title); ?></span>
            </div>
        </div>
        
        <nav class="sidenav-menu">
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="<?php echo $current_view == 'dashboard' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo generate_panel_url('customers'); ?>" class="<?php echo $current_view == 'customers' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-groups"></i>
                <span>Müşteriler</span>
            </a>
            <a href="<?php echo generate_panel_url('policies'); ?>" class="<?php echo $current_view == 'policies' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-portfolio"></i>
                <span>Poliçeler</span>
            </a>
            <a href="<?php echo generate_panel_url('tasks'); ?>" class="<?php echo $current_view == 'tasks' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-calendar-alt"></i>
                <span>Görevler</span>
            </a>
            <a href="<?php echo generate_panel_url('reports'); ?>" class="<?php echo $current_view == 'reports' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-chart-area"></i>
                <span>Raporlar</span>
            </a>
            <a href="<?php echo generate_panel_url('settings'); ?>" class="<?php echo $current_view == 'settings' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-admin-generic"></i>
                <span>Ayarlar</span>
            </a>
        </nav>
        
        <div class="sidenav-footer">
            <a href="<?php echo wp_logout_url(home_url('/temsilci-girisi')); ?>" class="logout-button">
                <i class="dashicons dashicons-exit"></i>
                <span>Çıkış Yap</span>
            </a>
        </div>
    </div>

    <div class="insurance-crm-main">
        <header class="main-header">
            <div class="header-left">
                <button id="sidenav-toggle">
                    <i class="dashicons dashicons-menu"></i>
                </button>
                <h2>
                    <?php 
                    switch($current_view) {
                        case 'customers':
                            echo 'Müşteriler';
                            break;
                        case 'policies':
                            echo 'Poliçeler';
                            break;
                        case 'tasks':
                            echo 'Görevler';
                            break;
                        case 'reports':
                            echo 'Raporlar';
                            break;
                        case 'settings':
                            echo 'Ayarlar';
                            break;
                        case 'search':
                            echo 'Arama Sonuçları';
                            break;
                        default:
                            echo 'Dashboard';
                    }
                    ?>
                </h2>
            </div>
            
            <div class="header-right">
                <div class="search-box">
                    <form action="<?php echo generate_panel_url('search'); ?>" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Ad, TC No, Çocuk Tc No.." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                    </form>
                </div>
                
                <div class="notification-bell">
                    <a href="#" id="notifications-toggle">
                        <i class="dashicons dashicons-bell"></i>
                        <?php if ($total_notification_count > 0): ?>
                        <span class="notification-badge"><?php echo $total_notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="notifications-dropdown">
                        <div class="notifications-header">
                            <h3>Bildirimler</h3>
                            <a href="#" class="mark-all-read">Tümünü okundu işaretle</a>
                        </div>
                        
                        <div class="notifications-list">
                            <?php if ($notifications_table_exists && $notification_count > 0): ?>
                                <?php 
                                $notifications = $wpdb->get_results($wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}insurance_crm_notifications
                                     WHERE user_id = %d AND is_read = 0
                                     ORDER BY created_at DESC
                                     LIMIT 5",
                                    $current_user->ID
                                ));
                                ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item unread">
                                        <i class="dashicons dashicons-warning"></i>
                                        <div class="notification-content">
                                            <p><?php echo esc_html($notification->message); ?></p>
                                            <span class="notification-time">
                                                <?php echo date_i18n('d.m.Y H:i', strtotime($notification->created_at)); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($upcoming_tasks)): ?>
                                <?php foreach ($upcoming_tasks as $task): ?>
                                    <div class="notification-item unread">
                                        <i class="dashicons dashicons-calendar-alt"></i>
                                        <div class="notification-content">
                                            <p>
                                                Görev: <?php echo esc_html($task->task_description); ?>
                                                (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                            </p>
                                            <span class="notification-time">
                                                Son Tarih: <?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notification-item">
                                    <i class="dashicons dashicons-yes-alt"></i>
                                    <div class="notification-content">
                                        <p>Yaklaşan görev bulunmuyor.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notifications-footer">
                            <a href="<?php echo generate_panel_url('notifications'); ?>">Tüm bildirimleri gör</a>
                        </div>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <button class="quick-add-btn" id="quick-add-toggle">
                        <i class="dashicons dashicons-plus-alt"></i>
                        <span>Hızlı Ekle</span>
                    </button>
                    
                    <div class="quick-add-dropdown">
                        <a href="<?php echo generate_panel_url('customers', 'new'); ?>" class="add-customer">
                            <i class="dashicons dashicons-groups"></i>
                            <span>Yeni Müşteri</span>
                        </a>
                        <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="add-policy">
                            <i class="dashicons dashicons-portfolio"></i>
                            <span>Yeni Poliçe</span>
                        </a>
                        <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="add-task">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Yeni Görev</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($current_view == 'dashboard'): ?>
        <div class="main-content">
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label">Toplam Müşteri</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label">Toplam Poliçe</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Toplam Üretim</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Bu Ay Üretim</div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card">
                        <div class="card-header">
                            <h3>Aylık Üretim Performansı</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="productionChart"></canvas>
                            </div>
                            <div class="production-table" style="margin-top: 20px;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Ay-Yıl</th>
                                            <th>Hedef (₺)</th>
                                            <th>Üretilen (₺)</th>
                                            <th>Gerçekleşme Oranı (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_production_data as $month_year => $total): ?>
                                            <?php 
                                            $dateParts = explode('-', $month_year);
                                            $year = $dateParts[0];
                                            $month = (int)$dateParts[1];
                                            $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 
                                                       'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                            $month_name = $months[$month - 1] . ' ' . $year;
                                            $achievement_rate = $monthly_target > 0 ? ($total / $monthly_target) * 100 : 0;
                                            $achievement_rate = min(100, $achievement_rate);
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($month_name); ?></td>
                                                <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
                    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

                    <div class="dashboard-card calendar-card">
                        <div class="card-header">
                            <h3>Görev Takvimi</h3>
                            <div class="card-actions">
                                <a href="?view=tasks" class="text-button">Tüm Görevler</a>
                                <a href="?view=tasks&action=new" class="card-option" title="Yeni Görev">
                                    <i class="dashicons dashicons-plus-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                            <?php if (!empty($upcoming_tasks_list)): ?>
                                <div style="margin-top: 20px;">
                                    <h4>Yaklaşan Görevler</h4>
                                    <ul class="task-list">
                                        <?php foreach ($upcoming_tasks_list as $task): ?>
                                            <li class="task-item">
                                                <strong><?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?></strong>
                                                <?php echo esc_html($task->task_description); ?>
                                                <?php if ($task->first_name || $task->last_name): ?>
                                                    (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                                <?php endif; ?>
                                                <a href="?view=tasks&due_date=<?php echo date('Y-m-d', strtotime($task->due_date)); ?>" class="task-link">Göreve Git</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; margin-top: 20px;">
                                    <p>Bu ay veya gelecek ay için görev bulunmamaktadır.</p>
                                    <a href="?view=tasks&action=new" class="button button-primary">Yeni Görev Ekle</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        console.log('FullCalendar yükleniyor...');
                        if (typeof FullCalendar === 'undefined') {
                            console.error('FullCalendar kütüphanesi yüklenmedi. CDN veya yerel dosya yollarını kontrol edin.');
                            return;
                        }

                        const calendarEl = document.getElementById('calendar');
                        if (!calendarEl) {
                            console.error('Takvim elementi (#calendar) bulunamadı.');
                            return;
                        }

                        const tasks = <?php echo json_encode($calendar_tasks ?: []); ?>;
                        console.log('Görevler:', tasks);

                        if (!Array.isArray(tasks)) {
                            console.error('Görevler bir dizi değil:', tasks);
                            return;
                        }

                        const calendar = new FullCalendar.Calendar(calendarEl, {
                            initialView: 'dayGridMonth',
                            headerToolbar: {
                                left: 'prev,next',
                                center: 'title',
                                right: ''
                            },
                            locale: 'tr',
                            weekends: true, // Haftasonlarını göster
                            events: tasks.map(task => ({
                                title: `${task.task_count} Göreviniz Var`,
                                start: task.task_date,
                                url: `?view=tasks&due_date=${task.task_date}`
                            })),
                            dayCellContent: function(info) {
                                const dateStr = info.date.toISOString().split('T')[0];
                                let taskCount = 0;

                                tasks.forEach(task => {
                                    const cleanTaskDate = task.task_date.trim(); // Tarih sonunda : varsa temizle
                                    if (cleanTaskDate === dateStr) {
                                        taskCount = parseInt(task.task_count);
                                    }
                                });

                                return {
                                    html: `
                                        <div class="fc-daygrid-day-frame">
                                            <div class="fc-daygrid-day-top">
                                                <a href="?view=tasks&due_date=${dateStr}" class="fc-daygrid-day-number">${info.dayNumberText}</a>
                                            </div>
                                            <div class="fc-daygrid-day-events">
                                                ${taskCount > 0 ? `<a href="?view=tasks&due_date=${dateStr}" class="fc-task-count">${taskCount} Göreviniz Var</a>` : ''}
                                            </div>
                                        </div>
                                    `
                                };
                            },
                            dateClick: function(info) {
                                const dateStr = info.dateStr;
                                window.location.href = `?view=tasks&due_date=${dateStr}`;
                            }
                        });

                        calendar.render();
                        console.log('Takvim render edildi.');
                    });
                    </script>
                </div>
                
                <div class="lower-section">
                    <div class="dashboard-card renewals-card">
                        <div class="card-header">
                            <h3>Yaklaşan Yenilemeler</h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'renewals')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($upcoming_renewals)): ?>
                                <table class="data-table renewals-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş</th>
                                            <th>Tutar</th>
                                            <th>Durum</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_renewals as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_remaining = $now->diff($end_date)->days;
                                            $urgency_class = '';
                                            if ($days_remaining <= 5) {
                                                $urgency_class = 'urgent';
                                            } elseif ($days_remaining <= 15) {
                                                $urgency_class = 'soon';
                                            }
                                        ?>
                                        <tr class="<?php echo $urgency_class; ?>">
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-calendar-alt"></i>
                                    </div>
                                    <h4>Yaklaşan yenileme bulunmuyor</h4>
                                    <p>Önümüzdeki 30 gün içinde yenilenecek poliçe yok.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card expired-policies-card">
                        <div class="card-header">
                            <h3>Süresi Geçmiş Poliçeler</h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'expired')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($expired_policies)): ?>
                                <table class="data-table expired-policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş</th>
                                            <th>Tutar</th>
                                            <th>Durum</th>
                                            <th>Gecikme</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expired_policies as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_overdue = $end_date->diff($now)->days;
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td class="days-overdue">
                                                <?php echo $days_overdue; ?> gün
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Süresi geçmiş poliçe bulunmuyor</h4>
                                    <p>Tüm poliçeleriniz güncel.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card recent-policies-card">
                        <div class="card-header">
                            <h3>Son Eklenen Poliçeler</h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies'); ?>" class="text-button">Tümünü Gör</a>
                                <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="card-option" title="Yeni Poliçe">
                                    <i class="dashicons dashicons-plus-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_policies)): ?>
                                <table class="data-table policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş</th>
                                            <th>Tutar</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_policies as $policy): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="policy-link">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="user-info-cell">
                                                    <div class="user-avatar-mini">
                                                        <?php 
                                                        $initial = strtoupper(substr($policy->first_name, 0, 1));
                                                        echo $initial;
                                                        ?>
                                                    </div>
                                                    <span>
                                                        <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                            <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                        </a>
                                                    </span>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="Daha Fazla">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>">Yenile</a>
                                                            <a href="<?php echo generate_panel_url('policies', 'duplicate', $policy->id); ?>">Kopyala</a>
                                                            <a href="<?php echo generate_panel_url('policies', 'cancel', $policy->id); ?>" class="text-danger">İptal Et</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Henüz poliçe eklenmemiş</h4>
                                    <p>Sisteme poliçe ekleyerek müşterilerinizi takip edin.</p>
                                    <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="button button-primary">
                                        Yeni Poliçe Ekle
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($current_view == 'search'): ?>
            <div class="dashboard-card">
                <h3 class="card-title"><?php esc_html_e('Arama Sonuçları', 'insurance-crm'); ?>
                    <a href="?view=all" class="see-all"><?php esc_html_e('Tümünü Gör', 'insurance-crm'); ?></a>
                </h3>
                <?php if (!empty($search_results)): ?>
                    <table class="data-table search-results-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Ad Soyad', 'insurance-crm'); ?></th>
                                <th><?php esc_html_e('TC Kimlik', 'insurance-crm'); ?></th>
                                <th><?php esc_html_e('Çocuk Ad Soyad', 'insurance-crm'); ?></th>
                                <th><?php esc_html_e('Çocuk TC Kimlik', 'insurance-crm'); ?></th>
                                <th><?php esc_html_e('Poliçe No', 'insurance-crm'); ?></th>
                                <th><?php esc_html_e('İşlemler', 'insurance-crm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $customer): ?>
                                <tr>
                                    <td>
                                        <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="ab-customer-name">
                                            <?php echo esc_html($customer->customer_name); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($customer->tc_identity); ?></td>
                                    <td><?php echo esc_html($customer->children_names ?: '-'); ?></td>
                                    <td><?php echo esc_html($customer->children_tc_identities ?: '-'); ?></td>
                                    <td><?php echo esc_html($customer->policy_number ?: '-'); ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="<?php esc_attr_e('Görüntüle', 'insurance-crm'); ?>">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </a>
                                            <div class="table-action-dropdown-wrapper">
                                                <a href="#" class="table-action table-action-more"><span class="dashicons dashicons-ellipsis"></span></a>
                                                <div class="table-action-dropdown">
                                                    <a href="?view=customers&action=delete&id=<?php echo esc_attr($customer->id); ?>"><?php esc_html_e('Sil', 'insurance-crm'); ?></a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><span class="dashicons dashicons-search"></span></div>
                        <h4><?php esc_html_e('Sonuç Bulunamadı', 'insurance-crm'); ?></h4>
                        <p><?php esc_html_e('Aradığınız kritere uygun bir sonuç bulunamadı.', 'insurance-crm'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($current_view == 'customers'): ?>
            <?php include_once(dirname(__FILE__) . '/customers.php'); ?>
        <?php elseif ($current_view == 'policies'): ?>
            <?php include_once(dirname(__FILE__) . '/policies.php'); ?>
        <?php elseif ($current_view == 'tasks'): ?>
            <?php include_once(dirname(__FILE__) . '/tasks.php'); ?>
        <?php elseif ($current_view == 'reports'): ?>
            <?php include_once(dirname(__FILE__) . '/reports.php'); ?>
        <?php elseif ($current_view == 'settings'): ?>
            <?php include_once(dirname(__FILE__) . '/settings.php'); ?>
        <?php endif; ?>

        <style>
            .insurance-crm-page * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body.insurance-crm-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                background-color: #f5f7fa;
                color: #333;
                margin: 0;
                padding: 0;
                min-height: 100vh;
            }
            
            .insurance-crm-sidenav {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 260px;
                background: #1e293b;
                color: #fff;
                display: flex;
                flex-direction: column;
                z-index: 1000;
                transition: all 0.3s ease;
            }
            
            .sidenav-header {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .sidenav-logo {
                width: 40px;
                height: 40px;
                margin-right: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidenav-logo img {
                max-width: 100%;
                max-height: 100%;
            }
            
            .sidenav-header h3 {
                font-weight: 600;
                font-size: 18px;
                color: #fff;
            }
            
            .sidenav-user {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 12px;
            }
            
            .user-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .user-info h4 {
                font-size: 14px;
                font-weight: 600;
                color: #fff;
                margin: 0;
            }
            
            .user-info span {
                font-size: 12px;
                color: rgba(255,255,255,0.7);
            }
            
            .sidenav-menu {
                flex: 1;
                padding: 20px 0;
                overflow-y: auto;
            }
            
            .sidenav-menu a {
                display: flex;
                align-items: center;
                padding: 12px 20px;
                color: rgba(255,255,255,0.7);
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .sidenav-menu a:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .sidenav-menu a.active {
                background: rgba(0,115,170,0.8);
                color: #fff;
                border-right: 3px solid #fff;
            }
            
            .sidenav-menu a .dashicons {
                margin-right: 12px;
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            
            .sidenav-footer {
                padding: 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            
            .logout-button {
                display: flex;
                align-items: center;
                color: rgba(255,255,255,0.7);
                padding: 10px;
                border-radius: 4px;
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .logout-button:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .logout-button .dashicons {
                margin-right: 8px;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            
            .insurance-crm-main {
                margin-left: 260px;
                min-height: 100vh;
                background: #f5f7fa;
                transition: all 0.3s ease;
            }
            
            .main-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 30px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: sticky;
                top: 0;
                z-index: 900;
            }
            
            .header-left {
                display: flex;
                align-items: center;
            }
            
            #sidenav-toggle {
                background: none;
                border: none;
                color: #555;
                font-size: 20px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 5px;
                margin-right: 15px;
            }
            
            .header-left h2 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .header-right {
                display: flex;
                align-items: center;
            }
            
            .search-box {
                position: relative;
                margin-right: 20px;
            }
            
            .search-box input {
                padding: 8px 15px 8px 35px;
                border: 1px solid #e0e0e0;
                border-radius: 20px;
                width: 250px;
                font-size: 14px;
                transition: all 0.3s;
            }
            
            .search-box input:focus {
                width: 300px;
                border-color: #0073aa;
                box-shadow: 0 0 0 2px rgba(0,115,170,0.2);
                outline: none;
            }
            
            .search-box .dashicons {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #666;
            }
            
            .notification-bell {
                position: relative;
                margin-right: 20px;
            }
            
            .notification-bell a {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 20px;
                color: #555;
                transition: all 0.2s;
            }
            
            .notification-bell a:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .notification-badge {
                position: absolute;
                top: 5px;
                right: 5px;
                background: #dc3545;
                color: #fff;
                border-radius: 10px;
                min-width: 18px;
                height: 18px;
                font-size: 11px;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
            }
            
            .notifications-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 320px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            
            .notifications-dropdown.show {
                display: block;
            }
            
            .notifications-header {
                padding: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #eee;
            }
            
            .notifications-header h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
            }
            
            .mark-all-read {
                font-size: 12px;
                color: #0073aa;
                text-decoration: none;
            }
            
            .notifications-list {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .notification-item {
                display: flex;
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
                transition: background 0.2s;
            }
            
            .notification-item:hover {
                background: #f9f9f9;
            }
            
            .notification-item.unread {
                background: #f0f7ff;
            }
            
            .notification-item .dashicons {
                margin-right: 12px;
                font-size: 20px;
                color: #0073aa;
            }
            
            .notification-content {
                flex: 1;
            }
            
            .notification-content p {
                margin: 0 0 5px;
                font-size: 14px;
                color: #333;
            }
            
            .notification-time {
                font-size: 12px;
                color: #777;
            }
            
            .notifications-footer {
                padding: 12px;
                text-align: center;
                border-top: 1px solid #eee;
            }
            
            .notifications-footer a {
                color: #0073aa;
                text-decoration: none;
                font-size: 14px;
            }
            
            .quick-actions {
                position: relative;
            }
            
            .quick-add-btn {
                display: flex;
                align-items: center;
                background: #0073aa;
                color: #fff;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .quick-add-btn:hover {
                background: #005a87;
            }
            
            .quick-add-btn .dashicons {
                margin-right: 5px;
            }
            
            .quick-add-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 200px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            
            .quick-add-dropdown.show {
                display: block;
            }
            
            .quick-add-dropdown a {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                color: #333;
                text-decoration: none;
                transition: background 0.2s;
            }
            
            .quick-add-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .quick-add-dropdown a .dashicons {
                margin-right: 10px;
                color: #0073aa;
            }
            
            .main-content {
                padding: 30px;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-box {
                background: white;
                border-radius: 10px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .stat-box:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            }
            
            .stat-icon {
                margin-bottom: 15px;
                width: 50px;
                height: 50px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .stat-icon .dashicons {
                font-size: 24px;
                color: white;
            }
            
            .customers-box .stat-icon {
                background: linear-gradient(135deg, #4e54c8, #8f94fb);
            }
            
            .policies-box .stat-icon {
                background: linear-gradient(135deg, #11998e, #38ef7d);
            }
            
            .production-box .stat-icon {
                background: linear-gradient(135deg, #F37335, #FDC830);
            }
            
            .target-box .stat-icon {
                background: linear-gradient(135deg, #536976, #292E49);
            }
            
            .stat-details {
                margin-bottom: 15px;
            }
            
            .stat-value {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-label {
                font-size: 14px;
                color: #666;
            }
            
            .stat-change {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                font-size: 13px;
                margin-top: auto;
            }
            
            .stat-new {
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-rate.positive {
                display: flex;
                align-items: center;
                color: #28a745;
            }
            
            .stat-rate .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 2px;
            }
            
            .stat-target {
                margin-top: auto;
            }
            
            .target-text {
                font-size: 13px;
                color: #666;
                margin-bottom: 5px;
            }
            
            .target-progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
            }
            
            .target-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
                transition: width 1s ease-in-out;
            }
            
            .dashboard-grid {
                display: flex;
                flex-direction: column;
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .upper-section {
                display: flex;
                flex-direction: row;
                gap: 20px;
                align-items: stretch;
                justify-content: space-between;
            }
            
            .dashboard-grid .upper-section .dashboard-card.chart-card {
                width: 65%;
                flex-shrink: 0;
            }
            
            .dashboard-grid .upper-section .dashboard-card.calendar-card {
                width: 35%;
                flex-shrink: 0;
            }
            
            .lower-section {
                display: flex;
                flex-direction: column;
                gap: 20px;
                width: 100%;
            }
            
            .dashboard-grid .lower-section .dashboard-card {
                width: 100%;
            }
            
            .dashboard-card {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .card-header h3 {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .card-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .card-option {
                background: none;
                border: none;
                color: #666;
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .card-option:hover {
                background: #f5f5f5;
                color: #333;
            }
            
            .text-button {
                font-size: 13px;
                color: #0073aa;
                text-decoration: none;
                transition: color 0.2s;
            }
            
            .text-button:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .chart-container {
                height: 300px;
            }
            
            #calendar {
                width: 100%;
                height: 500px;
                margin: 0 auto;
                visibility: visible;
                font-size: 12px;
            }
            
            .fc {
                visibility: visible !important;
            }
            
            .fc-scroller {
                overflow-y: hidden !important;
            }
            
            .fc-daygrid-day {
                position: relative;
                height: 30px;
                width: 30px;
            }
            
            .fc-day-sat, .fc-day-sun {
                display: table-cell !important; /* Haftasonlarını görünür yap */
            }
            
            .fc-daygrid-day-frame {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
            }
            
            .fc-daygrid-day-top {
                margin-bottom: 2px;
            }
            
            .fc-daygrid-day-number {
                color: #333;
                text-decoration: none;
                font-size: 10px;
            }
            
            .fc-daygrid-day-events {
                text-align: center;
            }
            
            .fc-task-count {
                background: #0073aa;
                color: #fff;
                border-radius: 10px;
                padding: 1px 4px;
                font-size: 9px;
                display: inline-block;
                text-decoration: none;
            }
            
            .fc-task-count:hover {
                background: #005a87;
            }
            
            .fc-header-toolbar {
                font-size: 12px;
            }
            
            .fc-button {
                padding: 2px 5px;
                font-size: 10px;
            }
            
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .data-table th {
                color: #666;
                font-weight: 500;
                font-size: 13px;
                text-align: left;
                padding: 12px 15px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table td {
                padding: 12px 15px;
                font-size: 14px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table tr:last-child td {
                border-bottom: none;
            }
            
            .data-table a {
                color: #0073aa;
                text-decoration: none;
            }
            
            .data-table a:hover {
                text-decoration: underline;
            }
            
            .days-remaining {
                font-weight: 500;
            }
            
            .urgent .days-remaining {
                color: #dc3545;
            }
            
            .soon .days-remaining {
                color: #fd7e14;
            }
            
            .action-button {
                display: inline-flex;
                align-items: center;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 5px 10px;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .action-button:hover {
                background: #e9ecef;
            }
            
            .action-button .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 5px;
            }
            
            .renew-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .renew-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .days-overdue {
                font-weight:
color: #dc3545;
            }
            
            .user-info-cell {
                display: flex;
                align-items: center;
            }
            
            .user-avatar-mini {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                background: #0073aa;
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 600;
                margin-right: 8px;
                flex-shrink: 0;
            }
            
            .status-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .status-active, .status-aktif {
                background: #d1e7dd;
                color: #198754;
            }
            
            .status-pending, .status-bekliyor {
                background: #fff3cd;
                color: #856404;
            }
            
            .status-cancelled, .status-iptal {
                background: #f8d7da;
                color: #dc3545;
            }
            
            .amount-cell {
                font-weight: 500;
                color: #333;
            }
            
            .table-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .table-action {
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #666;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .table-action:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .table-action-dropdown-wrapper {
                position: relative;
            }
            
            .table-action-more {
                cursor: pointer;
            }
            
            .table-action-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                background: #fff;
                border-radius: 6px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.15);
                z-index: 1000;
                min-width: 120px;
                display: none;
            }
            
            .table-action-dropdown.show {
                display: block;
            }
            
            .table-action-dropdown a {
                display: block;
                padding: 8px 15px;
                color: #333;
                text-decoration: none;
                font-size: 13px;
            }
            
            .table-action-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .table-action-dropdown a.text-danger {
                color: #dc3545;
            }
            
            .table-action-dropdown a.text-danger:hover {
                background: #f8d7da;
            }
            
            .empty-state {
                text-align: center;
                padding: 30px;
            }
            
            .empty-state .empty-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: #f0f7ff;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }
            
            .empty-state .empty-icon .dashicons {
                font-size: 30px;
                color: #0073aa;
            }
            
            .empty-state h4 {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 10px;
                color: #333;
            }
            
            .empty-state p {
                font-size: 14px;
                color: #666;
                margin-bottom: 20px;
            }
            
            .task-list {
                list-style: none;
                padding: 0;
                margin: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 14px;
                color: #444;
            }
            
            .task-item {
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                line-height: 1.5;
                transition: background 0.2s ease;
            }
            
            .task-item:hover {
                background: #f9f9f9;
            }
            
            .task-item:last-child {
                border-bottom: none;
            }
            
            .task-item strong {
                font-weight: 600;
                color: #333;
            }
            
            .task-link {
                color: #0073aa;
                text-decoration: none;
                font-size: 12px;
                font-weight: 500;
                transition: color 0.2s ease;
            }
            
            .task-link:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .card-body h4 {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin-bottom: 15px;
            }
            
            .search-results-table th,
            .search-results-table td {
                padding: 12px 15px;
            }
            
            .search-results-table .status-badge {
                min-width: 80px;
                text-align: center;
            }
            
            @media (max-width: 1200px) {
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .dashboard-grid .upper-section {
                    flex-direction: column;
                    gap: 20px;
                }
                
                .dashboard-grid .upper-section .dashboard-card.chart-card,
                .dashboard-grid .upper-section .dashboard-card.calendar-card {
                    width: 100%;
                    max-width: none;
                }
            }
            
            @media (max-width: 960px) {
                .insurance-crm-sidenav {
                    transform: translateX(-100%);
                }
                
                .insurance-crm-main {
                    margin-left: 0;
                }
                
                .insurance-crm-sidenav.show {
                    transform: translateX(0);
                }
                
                .search-box input {
                    width: 200px;
                }
                
                .search-box input:focus {
                    width: 250px;
                }
                
                #calendar .fc-daygrid-day {
                    font-size: 10px;
                }
                
                .fc-task-count {
                    font-size: 8px;
                    padding: 0px 3px;
                }
                
                .fc-daygrid-day-number {
                    font-size: 8px;
                }
            }
            
            @media (max-width: 600px) {
                .stats-grid {
                    grid-template-columns: 1fr;
                }
                
                .main-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }
                
                .header-right {
                    width: 100%;
                    justify-content: space-between;
                }
                
                .search-box {
                    flex: 1;
                    margin-right: 0;
                }
                
                .search-box input {
                    width: 100%;
                }
                
                .search-box input:focus {
                    width: 100%;
                }
                
                .main-content {
                    padding: 20px;
                }
                
                .data-table th,
                .data-table td {
                    font-size: 13px;
                    padding: 10px;
                }
                
                #calendar .fc-daygrid-day-number {
                    font-size: 7px;
                }
                
                .fc-task-count {
                    font-size: 7px;
                    padding: 0px 2px;
                }
            }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // SideNav Toggle
            const sidenav = document.querySelector('.insurance-crm-sidenav');
            const sidenavToggle = document.querySelector('#sidenav-toggle');
            sidenavToggle.addEventListener('click', function() {
                sidenav.classList.toggle('show');
            });
            
            // Quick Add Dropdown
            const quickAddBtn = document.querySelector('#quick-add-toggle');
            const quickAddDropdown = document.querySelector('.quick-add-dropdown');
            if (quickAddBtn && quickAddDropdown) {
                quickAddBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    quickAddDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!quickAddBtn.contains(e.target) && !quickAddDropdown.contains(e.target)) {
                        quickAddDropdown.classList.remove('show');
                    }
                });
            }
            
            // Notifications Dropdown
            const notificationsToggle = document.querySelector('#notifications-toggle');
            const notificationsDropdown = document.querySelector('.notifications-dropdown');
            if (notificationsToggle && notificationsDropdown) {
                notificationsToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    notificationsDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!notificationsToggle.contains(e.target) && !notificationsDropdown.contains(e.target)) {
                        notificationsDropdown.classList.remove('show');
                    }
                });
            }
            
            // Table Action Dropdowns
            const actionMoreButtons = document.querySelectorAll('.table-action-more');
            actionMoreButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown) {
                        dropdown.classList.toggle('show');
                    }
                });
            });
            
            document.addEventListener('click', function(e) {
                actionMoreButtons.forEach(button => {
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown && !button.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            });
            
            // Production Chart
            const productionChartCanvas = document.querySelector('#productionChart');
            if (productionChartCanvas) {
                const monthlyProduction = <?php echo json_encode($monthly_production ?: []); ?>;
                console.log('Aylık Üretim:', monthlyProduction); // Debug için
                
                if (!Array.isArray(monthlyProduction)) {
                    console.error('Aylık üretim verisi bir dizi değil:', monthlyProduction);
                    return;
                }
                
                const labels = monthlyProduction.map(item => {
                    const [year, month] = item.month.split('-');
                    const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 
                                  'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                    return months[parseInt(month) - 1] + ' ' + year;
                });
                
                const data = monthlyProduction.map(item => item.total);
                
                new Chart(productionChartCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Aylık Üretim (₺)',
                            data: data,
                            backgroundColor: 'rgba(0,115,170,0.6)',
                            borderColor: 'rgba(0,115,170,1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₺' + value.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₺' + context.parsed.y.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Mark All Notifications as Read
            const markAllReadLink = document.querySelector('.mark-all-read');
            if (markAllReadLink) {
                markAllReadLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Tüm bildirimleri okundu olarak işaretlemek istediğinize emin misiniz?')) {
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=mark_all_notifications_read'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.querySelectorAll('.notification-item.unread').forEach(item => {
                                    item.classList.remove('unread');
                                });
                                const badge = document.querySelector('.notification-badge');
                                if (badge) badge.remove();
                                alert('Tüm bildirimler okundu olarak işaretlendi.');
                            } else {
                                alert('Hata: ' + (data.data.message || 'İşlem başarısız.'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Bir hata oluştu.');
                        });
                    }
                });
            }
            
            // Arama Formu Submit Kontrolü
            const searchForm = document.querySelector('.search-box form');
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const keywordInput = searchForm.querySelector('input[name="keyword"]');
                    if (!keywordInput.value.trim()) {
                        e.preventDefault();
                        alert('Lütfen bir arama kriteri girin.');
                    }
                });
            }
        });
        </script>

        <?php wp_footer(); ?>
    </body>
</html>