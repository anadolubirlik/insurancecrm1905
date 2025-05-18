<?php
if (!defined('ABSPATH')) {
    exit;
}

// Kullanıcı giriş yapmamışsa login sayfasına yönlendir
if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

// Kullanıcı yönetici değilse temsilci paneline yönlendir
$user = wp_get_current_user();
if (!in_array('administrator', (array)$user->roles)) {
    if (in_array('insurance_representative', (array)$user->roles)) {
        wp_safe_redirect(home_url('/temsilci-paneli/'));
    } else {
        wp_safe_redirect(home_url());
    }
    exit;
}

// Aktif görünümü kontrol et
$current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';

// Tüm istatistikleri hesapla
global $wpdb;
$total_customers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers");
$total_policies = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies");
$total_premium = $wpdb->get_var("SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies");

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies
     WHERE start_date BETWEEN %s AND %s",
    $current_month_start . ' 00:00:00',
    $current_month_end . ' 23:59:59'
));

// Toplam hedef rakamı (tüm müşteri temsilcilerinin hedef toplamı)
$total_monthly_target = $wpdb->get_var("SELECT COALESCE(SUM(monthly_target), 0) FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
$achievement_rate = ($total_monthly_target > 0) ? min(100, ($current_month_premium / $total_monthly_target) * 100) : 0;
$remaining_amount = max(0, $total_monthly_target - $current_month_premium);

// Son 6 ayın üretim verileri
$monthly_production_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $monthly_production_data[$month_year] = 0;
}
$actual_data = $wpdb->get_results("
    SELECT DATE_FORMAT(start_date, '%Y-%m') as month_year, COALESCE(SUM(premium_amount), 0) as total
    FROM {$wpdb->prefix}insurance_crm_policies
    WHERE start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
    GROUP BY month_year
    ORDER BY month_year ASC"
);
foreach ($actual_data as $data) {
    if (isset($monthly_production_data[$data->month_year])) {
        $monthly_production_data[$data->month_year] = (float)$data->total;
    }
}
$monthly_production = array_map(function($month_year, $total) {
    return ['month' => $month_year, 'total' => $total];
}, array_keys($monthly_production_data), array_values($monthly_production_data));

// Yaklaşan yenilemeler (tüm poliçeler için)
$upcoming_renewals = $wpdb->get_results("
    SELECT p.*, c.first_name, c.last_name
    FROM {$wpdb->prefix}insurance_crm_policies p
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
    WHERE p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY p.end_date ASC
    LIMIT 5"
);

// Süresi geçmiş poliçeler (tüm poliçeler için)
$expired_policies = $wpdb->get_results("
    SELECT p.*, c.first_name, c.last_name
    FROM {$wpdb->prefix}insurance_crm_policies p
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
    WHERE p.end_date < CURDATE()
    AND p.status != 'iptal'
    ORDER BY p.end_date DESC
    LIMIT 5"
);

// Son poliçeler (tüm poliçeler için)
$recent_policies = $wpdb->get_results("
    SELECT p.*, c.first_name, c.last_name
    FROM {$wpdb->prefix}insurance_crm_policies p
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
    ORDER BY p.created_at DESC
    LIMIT 5"
);

// Müşteri temsilcileri listesi ve performans verileri
$representatives = $wpdb->get_results("
    SELECT r.*, u.display_name
    FROM {$wpdb->prefix}insurance_crm_representatives r
    LEFT JOIN {$wpdb->prefix}users u ON r.user_id = u.ID
    WHERE r.status = 'active'"
);
foreach ($representatives as &$rep) {
    $rep->total_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies WHERE representative_id = %d",
        $rep->id
    ));
    $rep->current_month_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies
         WHERE representative_id = %d AND start_date BETWEEN %s AND %s",
        $rep->id, $current_month_start . ' 00:00:00', $current_month_end . ' 23:59:59'
    ));
    $rep->remaining_amount = max(0, $rep->monthly_target - $rep->current_month_premium);
    $rep->achievement_rate = ($rep->monthly_target > 0) ? min(100, ($rep->current_month_premium / $rep->monthly_target) * 100) : 0;
}

// Chart.js kütüphanesini ekle
add_action('wp_enqueue_scripts', 'insurance_crm_boss_panel_scripts');
function insurance_crm_boss_panel_scripts() {
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
            <h3>Sigorta CRM - Patron Paneli</h3>
        </div>
        
        <div class="sidenav-user">
            <div class="user-avatar">
                <?php echo get_avatar($user->ID, 64); ?>
            </div>
            <div class="user-info">
                <h4><?php echo esc_html($user->display_name); ?></h4>
                <span>Yönetici</span>
            </div>
        </div>
        
        <nav class="sidenav-menu">
            <a href="?view=dashboard" class="<?php echo $current_view == 'dashboard' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="?view=policies" class="<?php echo $current_view == 'policies' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-portfolio"></i>
                <span>Tüm Poliçeler</span>
            </a>
            <a href="?view=reports" class="<?php echo $current_view == 'reports' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-chart-area"></i>
                <span>Raporlar</span>
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
                        case 'policies':
                            echo 'Tüm Poliçeler';
                            break;
                        case 'reports':
                            echo 'Raporlar';
                            break;
                        default:
                            echo 'Dashboard';
                    }
                    ?>
                </h2>
            </div>
            <div class="header-right">
                <div class="search-box">
                    <form action="?view=search" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Poliçe No, Müşteri Adı.." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                    </form>
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
                        <i class="dashicons dashicons-arrow-up-alt"></i>
                        <span>3.5%</span>
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
                        <i class="dashicons dashicons-arrow-up-alt"></i>
                        <span>5.2%</span>
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
                        <i class="dashicons dashicons-arrow-up-alt"></i>
                        <span>8.3%</span>
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
                        <div class="target-text">Hedef: ₺<?php echo number_format($total_monthly_target, 2, ',', '.'); ?></div>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Temsilciler Tablosu -->
            <div class="dashboard-card representatives-card">
                <div class="card-header">
                    <h3>Temsilciler Performansı</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($representatives)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ad Soyad</th>
                                    <th>Aylık Üretim (₺)</th>
                                    <th>Toplam Üretim (₺)</th>
                                    <th>Hedef (₺)</th>
                                    <th>Kalan (₺)</th>
                                    <th>Gerçekleşme (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($representatives as $rep): ?>
                                <tr>
                                    <td><?php echo esc_html($rep->display_name); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep->current_month_premium, 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep->total_premium, 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep->monthly_target, 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep->remaining_amount, 2, ',', '.'); ?></td>
                                    <td><?php echo number_format($rep->achievement_rate, 2, ',', '.'); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="dashicons dashicons-groups"></i></div>
                            <h4>Hiçbir temsilci bulunmuyor</h4>
                            <p>Sistemde aktif temsilci kaydı yok. Temsilciler ekleyerek başlayabilirsiniz!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="dashboard-card chart-card">
                    <div class="card-header">
                        <h3>Aylık Üretim Performansı (Tüm Temsilciler)</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="productionChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card renewals-card">
                    <div class="card-header">
                        <h3>Yaklaşan Yenilemeler (Tüm Temsilciler)</h3>
                        <div class="card-actions">
                            <a href="?view=policies&filter=renewals" class="text-button">Tümünü Gör</a>
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
                                        <th>Bitiş</th>
                                        <th>Kalan</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_renewals as $policy): 
                                        $end_date = new DateTime($policy->end_date);
                                        $now = new DateTime();
                                        $days_remaining = $now->diff($end_date)->days;
                                        $urgency_class = ($days_remaining <= 5) ? 'urgent' : (($days_remaining <= 15) ? 'soon' : '');
                                    ?>
                                    <tr class="<?php echo $urgency_class; ?>">
                                        <td><a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>"><?php echo esc_html($policy->policy_number); ?></a></td>
                                        <td><a href="?view=customers&action=edit&id=<?php echo $policy->customer_id; ?>"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></a></td>
                                        <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                        <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                        <td class="days-remaining"><?php echo $days_remaining; ?> gün</td>
                                        <td><a href="?view=policies&action=renew&id=<?php echo $policy->id; ?>" class="action-button renew-button"><i class="dashicons dashicons-update"></i><span>Yenile</span></a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="dashicons dashicons-calendar-alt"></i></div>
                                <h4>Yaklaşan yenileme yok</h4>
                                <p>Önümüzdeki 30 gün içinde yenilenecek poliçe bulunmuyor. Rahatlayın!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="dashboard-card expired-policies-card">
                    <div class="card-header">
                        <h3>Süresi Geçmiş Poliçeler (Tüm Temsilciler)</h3>
                        <div class="card-actions">
                            <a href="?view=policies&filter=expired" class="text-button">Tümünü Gör</a>
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
                                        <th>Bitiş</th>
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
                                        <td><a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>"><?php echo esc_html($policy->policy_number); ?></a></td>
                                        <td><a href="?view=customers&action=edit&id=<?php echo $policy->customer_id; ?>"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></a></td>
                                        <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                        <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                        <td class="days-overdue"><?php echo $days_overdue; ?> gün</td>
                                        <td><a href="?view=policies&action=renew&id=<?php echo $policy->id; ?>" class="action-button renew-button"><i class="dashicons dashicons-update"></i><span>Yenile</span></a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="dashicons dashicons-portfolio"></i></div>
                                <h4>Süresi geçmiş poliçe yok</h4>
                                <p>Tüm poliçeleriniz güncel. Harika bir iş!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card full-width">
                <div class="card-header">
                    <h3>Son Eklenen Poliçeler (Tüm Temsilciler)</h3>
                    <div class="card-actions">
                        <a href="?view=policies" class="text-button">Tümünü Gör</a>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_policies as $policy): ?>
                                <tr>
                                    <td><a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>"><?php echo esc_html($policy->policy_number); ?></a></td>
                                    <td><a href="?view=customers&action=edit&id=<?php echo $policy->customer_id; ?>"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></a></td>
                                    <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                    <td><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                    <td><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                    <td><span class="status-badge status-<?php echo esc_attr($policy->status); ?>"><?php echo esc_html(ucfirst($policy->status)); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="dashicons dashicons-portfolio"></i></div>
                            <h4>Henüz poliçe eklenmemiş</h4>
                            <p>Sisteme poliçe eklenmemiş. Temsilcilerinizi harekete geçirin!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <style>
            .insurance-crm-page * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body.insurance-crm-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                background: linear-gradient(135deg, #f5f7fa 0%, #e0e7ff 100%);
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
                background: linear-gradient(180deg, #1e293b 0%, #2d3748 100%);
                color: #fff;
                display: flex;
                flex-direction: column;
                z-index: 1000;
                transition: all 0.3s ease;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
            }
            
            .sidenav-header {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .sidenav-logo {
                width: 40px;
                height: 40px;
                margin-right: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 50%;
                overflow: hidden;
            }
            
            .sidenav-logo img {
                max-width: 100%;
                max-height: 100%;
            }
            
            .sidenav-header h3 {
                font-weight: 600;
                font-size: 18px;
                color: #fff;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            }
            
            .sidenav-user {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(0, 0, 0, 0.1);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 12px;
                border: 2px solid #fff;
                box-shadow: 0 0 5px rgba(255, 255, 255, 0.3);
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
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            }
            
            .user-info span {
                font-size: 12px;
                color: rgba(255, 255, 255, 0.7);
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
                color: rgba(255, 255, 255, 0.7);
                text-decoration: none;
                transition: all 0.2s ease;
                border-left: 4px solid transparent;
            }
            
            .sidenav-menu a:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #fff;
                border-left-color: #0073aa;
            }
            
            .sidenav-menu a.active {
                background: rgba(0, 115, 170, 0.2);
                color: #fff;
                border-left-color: #0073aa;
                font-weight: 500;
            }
            
            .sidenav-menu a .dashicons {
                margin-right: 12px;
                font-size: 18px;
                width: 18px;
                height: 18px;
                color: #0073aa;
            }
            
            .sidenav-footer {
                padding: 20px;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .logout-button {
                display: flex;
                align-items: center;
                color: rgba(255, 255, 255, 0.7);
                padding: 10px;
                border-radius: 4px;
                text-decoration: none;
                transition: all 0.2s ease;
                background: rgba(255, 255, 255, 0.05);
            }
            
            .logout-button:hover {
                background: rgba(255, 255, 255, 0.2);
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
                transition: all 0.3s ease;
            }
            
            .main-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 30px;
                background: linear-gradient(90deg, #fff 0%, #f0f4f8 100%);
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
                padding: 5px;
                margin-right: 15px;
                transition: color 0.2s;
            }
            
            #sidenav-toggle:hover {
                color: #0073aa;
            }
            
            .header-left h2 {
                font-size: 20px;
                font-weight: 600;
                color: #333;
                margin: 0;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
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
                padding: 10px 15px 10px 40px;
                border: 1px solid #e0e0e0;
                border-radius: 25px;
                width: 250px;
                font-size: 14px;
                background: #fff;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                transition: all 0.3s ease;
            }
            
            .search-box input:focus {
                width: 300px;
                border-color: #0073aa;
                box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.2);
                outline: none;
            }
            
            .search-box .dashicons {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #666;
                transition: color 0.2s;
            }
            
            .search-box input:focus + .dashicons {
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
                background: #fff;
                border-radius: 15px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
                overflow: hidden;
            }
            
            .stat-box:hover {
                transform: translateY(-5px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            }
            
            .stat-icon {
                margin-bottom: 15px;
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .stat-icon .dashicons {
                font-size: 28px;
                color: #fff;
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
                text-align: center;
            }
            
            .stat-value {
                font-size: 28px;
                font-weight: 700;
                color: #333;
                margin-bottom: 5px;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .stat-label {
                font-size: 14px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .stat-change {
                display: flex;
                align-items: center;
                font-size: 13px;
                margin-top: auto;
                justify-content: center;
            }
            
            .stat-change.positive {
                color: #28a745;
            }
            
            .stat-change.negative {
                color: #dc3545;
            }
            
            .stat-change .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 2px;
            }
            
            .stat-target {
                margin-top: auto;
                text-align: center;
            }
            
            .target-text {
                font-size: 13px;
                color: #666;
                margin-bottom: 5px;
                font-weight: 500;
            }
            
            .target-progress-mini {
                height: 6px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
            }
            
            .target-bar {
                height: 100%;
                background: linear-gradient(90deg, #4e54c8, #8f94fb);
                border-radius: 3px;
                transition: width 1.5s ease-in-out;
                box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .dashboard-grid {
                display: grid;
                grid-template-columns: 2fr 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .dashboard-card {
                background: #fff;
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .dashboard-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            }
            
            .representatives-card {
                margin-bottom: 20px;
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                background: linear-gradient(90deg, #f8f9fa 0%, #e9ecef 100%);
                border-bottom: 1px solid #dee2e6;
            }
            
            .card-header h3 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
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
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .card-option:hover {
                background: #e9ecef;
                color: #0073aa;
            }
            
            .text-button {
                font-size: 13px;
                color: #0073aa;
                text-decoration: none;
                transition: color 0.2s;
                padding: 5px 10px;
                border-radius: 5px;
            }
            
            .text-button:hover {
                color: #005a87;
                background: #e9f0f7;
                text-decoration: none;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .chart-container {
                height: 350px;
                position: relative;
                background: #fff;
                border-radius: 10px;
                padding: 10px;
                box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.05);
            }
            
            .data-table {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
            }
            
            .data-table th {
                color: #333;
                font-weight: 600;
                font-size: 14px;
                text-align: left;
                padding: 12px 15px;
                background: #f8f9fa;
                border-bottom: 2px solid #dee2e6;
            }
            
            .data-table td {
                padding: 12px 15px;
                font-size: 14px;
                border-bottom: 1px solid #eee;
                transition: background 0.2s;
            }
            
            .data-table tr:hover td {
                background: #f8f9fa;
            }
            
            .data-table a {
                color: #0073aa;
                text-decoration: none;
                transition: color 0.2s;
            }
            
            .data-table a:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .days-remaining {
                font-weight: 500;
                color: #28a745;
            }
            
            .urgent .days-remaining {
                color: #dc3545;
            }
            
            .soon .days-remaining {
                color: #fd7e14;
            }
            
            .days-overdue {
                font-weight: 500;
                color: #dc3545;
            }
            
            .amount-cell {
                font-weight: 500;
                color: #333;
            }
            
            .status-badge {
                padding: 5px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
                display: inline-block;
                text-align: center;
            }
            
            .status-active, .status-aktif { background: #d1e7dd; color: #198754; }
            .status-pending, .status-bekliyor { background: #fff3cd; color: #856404; }
            .status-cancelled, .status-iptal { background: #f8d7da; color: #dc3545; }
            
            .action-button {
                display: inline-flex;
                align-items: center;
                background: #e9ecef;
                border: 1px solid #dee2e6;
                border-radius: 6px;
                padding: 6px 12px;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
                color: #333;
            }
            
            .action-button:hover {
                background: #0073aa;
                color: #fff;
                border-color: #0073aa;
            }
            
            .action-button .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 5px;
            }
            
            .renew-button {
                background: rgba(0, 115, 170, 0.1);
                border-color: #0073aa;
                color: #0073aa;
            }
            
            .renew-button:hover {
                background: #0073aa;
                color: #fff;
            }
            
            .full-width {
                grid-column: 1 / -1;
            }
            
            .empty-state {
                text-align: center;
                padding: 40px;
                background: #fff;
                border-radius: 15px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                animation: fadeIn 1s ease-in-out;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            .empty-state .empty-icon {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: linear-gradient(135deg, #e0e7ff, #f5f7fa);
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }
            
            .empty-state .empty-icon .dashicons {
                font-size: 40px;
                color: #0073aa;
            }
            
            .empty-state h4 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin-bottom: 10px;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .empty-state p {
                font-size: 14px;
                color: #666;
                margin-bottom: 20px;
            }
            
            @media (max-width: 1200px) {
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .dashboard-grid {
                    grid-template-columns: 1fr;
                }
                
                .dashboard-grid > .dashboard-card {
                    grid-column: span 1 !important;
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
            }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const sidenav = document.querySelector('.insurance-crm-sidenav');
                const sidenavToggle = document.querySelector('#sidenav-toggle');
                sidenavToggle.addEventListener('click', function() {
                    sidenav.classList.toggle('show');
                });

                const productionChartCanvas = document.querySelector('#productionChart');
                if (productionChartCanvas) {
                    const monthlyProduction = <?php echo json_encode($monthly_production); ?>;
                    const labels = monthlyProduction.map(item => {
                        const [year, month] = item.month.split('-');
                        const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
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
                                backgroundColor: 'rgba(0, 115, 170, 0.8)',
                                borderColor: 'rgba(0, 115, 170, 1)',
                                borderWidth: 1,
                                borderRadius: 5,
                                barThickness: 20
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
                                        },
                                        color: '#666',
                                        font: {
                                            size: 12
                                        }
                                    },
                                    grid: {
                                        color: '#e9ecef'
                                    }
                                },
                                x: {
                                    ticks: {
                                        color: '#333'
                                    },
                                    grid: {
                                        display: false
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    labels: {
                                        color: '#333',
                                        font: {
                                            size: 14
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return '₺' + context.parsed.y.toLocaleString('tr-TR');
                                        }
                                    },
                                    backgroundColor: '#fff',
                                    titleColor: '#333',
                                    bodyColor: '#666',
                                    borderColor: '#0073aa',
                                    borderWidth: 1
                                }
                            },
                            animation: {
                                duration: 2000,
                                easing: 'easeInOutQuad'
                            }
                        }
                    });
                }
            });
        </script>

        <?php wp_footer(); ?>
    </body>
</html>