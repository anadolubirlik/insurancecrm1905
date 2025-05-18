<?php
if (!defined('ABSPATH')) {
    exit;
}

// Oturum kontrolü
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

// Müşteri temsilcisinin ID'sini al
function get_current_user_rep_id() {
    global $wpdb;
    $user_id = get_current_user_id();
    $reps_table = $wpdb->prefix . 'insurance_crm_representatives';
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $reps_table WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    return $rep ? $rep->id : null;
}

$representative_id = get_current_user_rep_id();
if (!$representative_id) {
    echo '<div class="ab-notice ab-error">Müşteri temsilcisi bulunamadı.</div>';
    return;
}

// Veritabanı bağlantısı ve yardımcı fonksiyonlar
global $wpdb;

// Müşteri Portföy Raporu
function get_customer_portfolio_data($representative_id, $start_date = '', $end_date = '', $status = '', $category = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    $query = $wpdb->prepare(
        "SELECT first_name, last_name, category, status, created_at 
         FROM $table_name 
         WHERE representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND created_at >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND created_at <= %s", $end_date);
    if ($status) $query .= $wpdb->prepare(" AND status = %s", $status);
    if ($category) $query .= $wpdb->prepare(" AND category = %s", $category);
    return $wpdb->get_results($query);
}

// Müşteri Portföy Özet Verileri
function get_customer_portfolio_summary($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    // Bu ayki yeni müşteriler
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $new_customers_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name 
         WHERE representative_id = %d AND created_at >= %s AND created_at <= %s",
        $representative_id, $month_start, $month_end
    );
    $new_customers = $wpdb->get_var($new_customers_query);
    
    // Toplam müşteri, aktif/pasif/belirsiz sayıları
    $total_query = $wpdb->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'pasif' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN status = 'belirsiz' THEN 1 ELSE 0 END) as uncertain
         FROM $table_name 
         WHERE representative_id = %d",
        $representative_id
    );
    if ($start_date) $total_query .= $wpdb->prepare(" AND created_at >= %s", $start_date);
    if ($end_date) $total_query .= $wpdb->prepare(" AND created_at <= %s", $end_date);
    $summary = $wpdb->get_row($total_query);
    
    return array(
        'new_customers' => $new_customers,
        'total' => $summary->total,
        'active' => $summary->active,
        'inactive' => $summary->inactive,
        'uncertain' => $summary->uncertain
    );
}

// Poliçe Yenileme Raporu
function get_policy_renewal_data($representative_id, $start_date = '', $end_date = '', $policy_type = '', $orderby = 'end_date', $order = 'ASC') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // Sıralama için güvenli sütunlar
    $allowed_orderby = array('end_date', 'customer_name');
    $orderby = in_array($orderby, $allowed_orderby) ? $orderby : 'end_date';
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    
    // Sıralama sütununu belirle
    if ($orderby === 'customer_name') {
        $order_column = "CONCAT(c.first_name, ' ', c.last_name)";
    } else {
        $order_column = "p.end_date";
    }
    
    $query = $wpdb->prepare(
        "SELECT p.id, p.policy_number, p.policy_type, p.end_date, p.status, 
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                EXISTS (
                    SELECT 1 FROM $table_name p2 
                    WHERE p2.customer_id = p.customer_id 
                    AND p2.policy_type = p.policy_type 
                    AND p2.start_date > p.end_date
                ) as renewed
         FROM $table_name p 
         LEFT JOIN $customers_table c ON p.customer_id = c.id 
         WHERE p.representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND p.end_date >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND p.end_date <= %s", $end_date);
    if ($policy_type) $query .= $wpdb->prepare(" AND p.policy_type = %s", $policy_type);
    
    $query .= " ORDER BY $order_column $order";
    
    return $wpdb->get_results($query);
}

// Poliçe Yenileme Özet Verileri
function get_policy_renewal_summary($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    
    // Bu ay yenilenecek poliçeler
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $upcoming_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name 
         WHERE representative_id = %d AND end_date >= %s AND end_date <= %s",
        $representative_id, $month_start, $month_end
    );
    $upcoming_policies = $wpdb->get_var($upcoming_query);
    
    // Yenilenen ve yenilenmeyen poliçeler
    $renewal_query = $wpdb->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM $table_name p2 
                WHERE p2.customer_id = p.customer_id 
                AND p2.policy_type = p.policy_type 
                AND p2.start_date > p.end_date
            ) THEN 1 ELSE 0 END) as renewed
         FROM $table_name p 
         WHERE representative_id = %d",
        $representative_id
    );
    if ($start_date) $renewal_query .= $wpdb->prepare(" AND p.end_date >= %s", $start_date);
    if ($end_date) $renewal_query .= $wpdb->prepare(" AND p.end_date <= %s", $end_date);
    $summary = $wpdb->get_row($renewal_query);
    
    return array(
        'upcoming' => $upcoming_policies,
        'total' => $summary->total,
        'renewed' => $summary->renewed,
        'not_renewed' => $summary->total - $summary->renewed
    );
}

// Satış Performans Raporu (Poliçelerden türetilmiş)
function get_sales_performance_data($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    
    // Tarih aralığını kontrol et ve formatı doğrula
    if (!$start_date) {
        $start_date = '1970-01-01'; // Eğer başlangıç tarihi yoksa, tüm verileri kapsayacak şekilde
    }
    if (!$end_date) {
        $end_date = date('Y-m-d'); // Eğer bitiş tarihi yoksa, bugüne kadar
    }
    
    // Aylık satış verileri
    $query = $wpdb->prepare(
        "SELECT 
            DATE_FORMAT(start_date, '%Y-%m') as period,
            COUNT(*) as sales_count,
            SUM(premium_amount) as total_premium,
            SUM(CASE WHEN customer_id IN (
                SELECT customer_id FROM $table_name p2 
                WHERE p2.representative_id = %d 
                AND p2.start_date < p.start_date 
                GROUP BY customer_id
            ) THEN 0 ELSE 1 END) as new_customers
         FROM $table_name p
         WHERE representative_id = %d
         AND start_date >= %s
         AND start_date <= %s
         GROUP BY DATE_FORMAT(start_date, '%Y-%m')",
        $representative_id, $representative_id, $start_date, $end_date
    );
    $results = $wpdb->get_results($query);
    
    // Hedef ve oranlama
    $target_sales = 50; // Varsayılan hedef: 50 poliçe satışı
    $total_sales = array_sum(array_column($results, 'sales_count'));
    $achievement_rate = $total_sales > 0 ? round(($total_sales / $target_sales) * 100, 2) : 0;
    
    return array(
        'data' => $results,
        'total_sales' => $total_sales,
        'target_sales' => $target_sales,
        'achievement_rate' => $achievement_rate
    );
}

// Müşteri Etkileşim Raporu
function get_customer_interaction_data($representative_id, $start_date = '', $end_date = '', $interaction_type = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_interactions';
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $query = $wpdb->prepare(
        "SELECT i.interaction_date, i.type, i.notes, 
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name 
         FROM $table_name i 
         LEFT JOIN $customers_table c ON i.customer_id = c.id 
         WHERE i.representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND i.interaction_date >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND i.interaction_date <= %s", $end_date);
    if ($interaction_type) $query .= $wpdb->prepare(" AND i.type = %s", $interaction_type);
    return $wpdb->get_results($query);
}

// Şikayet ve Memnuniyet Raporu
function get_complaints_data($representative_id, $start_date = '', $end_date = '', $status = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_complaints';
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $query = $wpdb->prepare(
        "SELECT co.complaint_date, co.subject, co.status, 
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                IF(co.status = 'Çözüldü', 
                   DATEDIFF(NOW(), co.complaint_date), 
                   NULL) as resolution_days
         FROM $table_name co 
         LEFT JOIN $customers_table c ON co.customer_id = c.id 
         WHERE co.representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND co.complaint_date >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND co.complaint_date <= %s", $end_date);
    if ($status) $query .= $wpdb->prepare(" AND co.status = %s", $status);
    return $wpdb->get_results($query);
}

// Şikayet Özet Verileri
function get_complaints_summary($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_complaints';
    
    $query = $wpdb->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Çözüldü' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'Bekliyor' THEN 1 ELSE 0 END) as pending,
            AVG(CASE WHEN status = 'Çözüldü' THEN DATEDIFF(NOW(), complaint_date) END) as avg_resolution_days
         FROM $table_name 
         WHERE representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND complaint_date >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND complaint_date <= %s", $end_date);
    $summary = $wpdb->get_row($query);
    
    return array(
        'total' => $summary->total,
        'resolved' => $summary->resolved,
        'pending' => $summary->pending,
        'avg_resolution_days' => $summary->avg_resolution_days ? round($summary->avg_resolution_days, 1) : 0
    );
}

// Potansiyel Müşteriler Raporu
function get_leads_data($representative_id, $start_date = '', $end_date = '', $interest = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_leads';
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $query = $wpdb->prepare(
        "SELECT l.lead_name, l.contact_info, l.interest, l.last_interaction,
                DATEDIFF(NOW(), l.last_interaction) as days_since_interaction,
                EXISTS (
                    SELECT 1 FROM $customers_table c 
                    WHERE c.email = l.contact_info 
                    AND c.representative_id = %d
                ) as converted
         FROM $table_name l 
         WHERE l.representative_id = %d",
        $representative_id, $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND l.last_interaction >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND l.last_interaction <= %s", $end_date);
    if ($interest) $query .= $wpdb->prepare(" AND l.interest = %s", $interest);
    return $wpdb->get_results($query);
}

// Potansiyel Müşteriler Özet Verileri
function get_leads_summary($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_leads';
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    $query = $wpdb->prepare(
        "SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM $customers_table c 
                WHERE c.email = l.contact_info 
                AND c.representative_id = %d
            ) THEN 1 ELSE 0 END) as converted_leads
         FROM $table_name l 
         WHERE l.representative_id = %d",
        $representative_id, $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND l.last_interaction >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND l.last_interaction <= %s", $end_date);
    $summary = $wpdb->get_row($query);
    
    $conversion_rate = $summary->total_leads > 0 ? round(($summary->converted_leads / $summary->total_leads) * 100, 2) : 0;
    
    return array(
        'total_leads' => $summary->total_leads,
        'converted_leads' => $summary->converted_leads,
        'conversion_rate' => $conversion_rate
    );
}

// İyileştirilmiş dashboard için ekstra: Poliçe türlerine göre toplam prim gelirleri
function get_policy_type_premium_data($representative_id, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    
    $query = $wpdb->prepare(
        "SELECT 
            policy_type,
            COUNT(*) as count,
            SUM(premium_amount) as total_premium
         FROM $table_name
         WHERE representative_id = %d",
        $representative_id
    );
    if ($start_date) $query .= $wpdb->prepare(" AND start_date >= %s", $start_date);
    if ($end_date) $query .= $wpdb->prepare(" AND start_date <= %s", $end_date);
    $query .= " GROUP BY policy_type ORDER BY total_premium DESC";
    
    return $wpdb->get_results($query);
}

// Aktif sekme ve filtre parametrelerini al
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'portfolio';

// Poliçe Yenileme için sıralama parametreleri
$renewals_orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'end_date';
$renewals_order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';

// Her sekme için ayrı filtre dizileri
$filters = array(
    'portfolio' => array(
        'start_date' => isset($_GET['portfolio_start_date']) ? sanitize_text_field($_GET['portfolio_start_date']) : '',
        'end_date' => isset($_GET['portfolio_end_date']) ? sanitize_text_field($_GET['portfolio_end_date']) : '',
        'status' => isset($_GET['portfolio_status']) ? sanitize_text_field($_GET['portfolio_status']) : '',
        'category' => isset($_GET['portfolio_category']) ? sanitize_text_field($_GET['portfolio_category']) : '',
    ),
    'renewals' => array(
        'start_date' => isset($_GET['renewals_start_date']) ? sanitize_text_field($_GET['renewals_start_date']) : '',
        'end_date' => isset($_GET['renewals_end_date']) ? sanitize_text_field($_GET['renewals_end_date']) : '',
        'policy_type' => isset($_GET['renewals_policy_type']) ? sanitize_text_field($_GET['renewals_policy_type']) : '',
    ),
    'sales' => array(
        'start_date' => isset($_GET['sales_start_date']) ? sanitize_text_field($_GET['sales_start_date']) : '',
        'end_date' => isset($_GET['sales_end_date']) ? sanitize_text_field($_GET['sales_end_date']) : '',
    ),
    'interactions' => array(
        'start_date' => isset($_GET['interactions_start_date']) ? sanitize_text_field($_GET['interactions_start_date']) : '',
        'end_date' => isset($_GET['interactions_end_date']) ? sanitize_text_field($_GET['interactions_end_date']) : '',
        'interaction_type' => isset($_GET['interactions_interaction_type']) ? sanitize_text_field($_GET['interactions_interaction_type']) : '',
    ),
    'complaints' => array(
        'start_date' => isset($_GET['complaints_start_date']) ? sanitize_text_field($_GET['complaints_start_date']) : '',
        'end_date' => isset($_GET['complaints_end_date']) ? sanitize_text_field($_GET['complaints_end_date']) : '',
        'status' => isset($_GET['complaints_status']) ? sanitize_text_field($_GET['complaints_status']) : '',
    ),
    'leads' => array(
        'start_date' => isset($_GET['leads_start_date']) ? sanitize_text_field($_GET['leads_start_date']) : '',
        'end_date' => isset($_GET['leads_end_date']) ? sanitize_text_field($_GET['leads_end_date']) : '',
        'interest' => isset($_GET['leads_interest']) ? sanitize_text_field($_GET['leads_interest']) : '',
    ),
    'dashboard' => array(
        'period' => isset($_GET['dashboard_period']) ? sanitize_text_field($_GET['dashboard_period']) : 'this_month',
    ),
);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="main-content">
    <div class="report-tabs">
        <div class="report-tabs-nav">
            <a href="?view=reports&tab=dashboard" class="report-tab <?php echo $current_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="?view=reports&tab=portfolio" class="report-tab <?php echo $current_tab === 'portfolio' ? 'active' : ''; ?>" data-tab="portfolio"><i class="fas fa-users"></i> Müşteri Portföyü</a>
            <a href="?view=reports&tab=renewals" class="report-tab <?php echo $current_tab === 'renewals' ? 'active' : ''; ?>" data-tab="renewals"><i class="fas fa-sync-alt"></i> Poliçe Yenileme</a>
            <a href="?view=reports&tab=sales" class="report-tab <?php echo $current_tab === 'sales' ? 'active' : ''; ?>" data-tab="sales"><i class="fas fa-chart-line"></i> Satış Performansı</a>
            <a href="?view=reports&tab=interactions" class="report-tab <?php echo $current_tab === 'interactions' ? 'active' : ''; ?>" data-tab="interactions"><i class="fas fa-comments"></i> Müşteri Etkileşimleri</a>
            <a href="?view=reports&tab=complaints" class="report-tab <?php echo $current_tab === 'complaints' ? 'active' : ''; ?>" data-tab="complaints"><i class="fas fa-exclamation-circle"></i> Şikayetler</a>
            <a href="?view=reports&tab=leads" class="report-tab <?php echo $current_tab === 'leads' ? 'active' : ''; ?>" data-tab="leads"><i class="fas fa-user-plus"></i> Potansiyel Müşteriler</a>
        </div>
        
        <!-- Dashboard -->
        <div id="dashboard" class="report-tab-content <?php echo $current_tab === 'dashboard' ? 'active' : ''; ?>">
            <h3><i class="fas fa-tachometer-alt"></i> Dashboard</h3>
            <p class="report-description">Performansınızı tek bakışta görebileceğiniz özet gösterge paneli.</p>
            
            <?php
            // Dashboard için verileri hazırla
            $dashboard_period = $filters['dashboard']['period'];
            $today = date('Y-m-d');
            
            switch ($dashboard_period) {
                case 'this_week':
                    $start_date = date('Y-m-d', strtotime('monday this week'));
                    $end_date = $today;
                    $period_text = 'Bu Hafta';
                    break;
                case 'last_week':
                    $start_date = date('Y-m-d', strtotime('monday last week'));
                    $end_date = date('Y-m-d', strtotime('sunday last week'));
                    $period_text = 'Geçen Hafta';
                    break;
                case 'last_month':
                    $start_date = date('Y-m-01', strtotime('first day of last month'));
                    $end_date = date('Y-m-t', strtotime('last day of last month'));
                    $period_text = 'Geçen Ay';
                    break;
                case 'this_year':
                    $start_date = date('Y-01-01');
                    $end_date = $today;
                    $period_text = 'Bu Yıl';
                    break;
                default: // this_month
                    $start_date = date('Y-m-01');
                    $end_date = $today;
                    $period_text = 'Bu Ay';
                    break;
            }
            
            // Özet verileri al
            $portfolio_summary = get_customer_portfolio_summary($representative_id, $start_date, $end_date);
            $renewal_summary = get_policy_renewal_summary($representative_id, $start_date, $end_date);
            $sales_data = get_sales_performance_data($representative_id, $start_date, $end_date);
            $leads_summary = get_leads_summary($representative_id, $start_date, $end_date);
            $policy_type_data = get_policy_type_premium_data($representative_id, $start_date, $end_date);
            ?>
            
            <!-- Dönem seçici -->
            <div class="dashboard-period-selector">
                <div class="period-label">
                    <span>Dönem: </span>
                    <strong><?php echo $period_text; ?></strong>
                </div>
                <form method="get" class="period-form">
                    <input type="hidden" name="view" value="reports">
                    <input type="hidden" name="tab" value="dashboard">
                    <select name="dashboard_period" id="dashboard_period" class="period-select" onchange="this.form.submit()">
                        <option value="this_month" <?php selected($dashboard_period, 'this_month'); ?>>Bu Ay</option>
                        <option value="this_week" <?php selected($dashboard_period, 'this_week'); ?>>Bu Hafta</option>
                        <option value="last_week" <?php selected($dashboard_period, 'last_week'); ?>>Geçen Hafta</option>
                        <option value="last_month" <?php selected($dashboard_period, 'last_month'); ?>>Geçen Ay</option>
                        <option value="this_year" <?php selected($dashboard_period, 'this_year'); ?>>Bu Yıl</option>
                    </select>
                </form>
            </div>
            
            <!-- Dashboard kartları -->
            <div class="dashboard-cards">
                <div class="dashboard-card customers-card">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-content">
                        <h4>Müşteriler</h4>
                        <div class="card-value"><?php echo $portfolio_summary['total']; ?></div>
                        <div class="card-details">
                            <span class="detail-item"><i class="fas fa-circle active-status"></i> <?php echo $portfolio_summary['active']; ?> Aktif</span>
                            <span class="detail-item"><i class="fas fa-circle inactive-status"></i> <?php echo $portfolio_summary['inactive']; ?> Pasif</span>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card sales-card">
                    <div class="card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="card-content">
                        <h4>Satışlar</h4>
                        <div class="card-value"><?php echo $sales_data['total_sales']; ?> Poliçe</div>
                        <div class="card-details">
                            <div class="progress-bar">
                                <div class="progress" style="width: <?php echo min(100, $sales_data['achievement_rate']); ?>%"></div>
                                <span class="progress-text"><?php echo $sales_data['achievement_rate']; ?>% Başarı</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card renewals-card">
                    <div class="card-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div class="card-content">
                        <h4>Yenilemeler</h4>
                        <div class="card-value"><?php echo $renewal_summary['upcoming']; ?> Poliçe Bu Ay</div>
                        <div class="card-details">
                            <span class="detail-item"><i class="fas fa-check-circle"></i> <?php echo $renewal_summary['renewed']; ?> Yenilenen</span>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card leads-card">
                    <div class="card-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="card-content">
                        <h4>Potansiyel Müşteriler</h4>
                        <div class="card-value"><?php echo $leads_summary['total_leads']; ?> Toplam</div>
                        <div class="card-details">
                            <span class="detail-item"><i class="fas fa-exchange-alt"></i> <?php echo $leads_summary['conversion_rate']; ?>% Dönüşüm</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Poliçe Türlerine Göre Dağılım -->
            <div class="dashboard-section">
                <h4><i class="fas fa-chart-pie"></i> Poliçe Türlerine Göre Dağılım</h4>
                <div class="policy-distribution">
                    <?php if ($policy_type_data): ?>
                        <table class="policy-type-table">
                            <thead>
                                <tr>
                                    <th>Poliçe Türü</th>
                                    <th>Adet</th>
                                    <th>Toplam Prim (₺)</th>
                                    <th>Dağılım</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_premium = 0;
                                foreach ($policy_type_data as $data) {
                                    $total_premium += $data->total_premium;
                                }
                                
                                foreach ($policy_type_data as $data): 
                                    $percentage = $total_premium > 0 ? round(($data->total_premium / $total_premium) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($data->policy_type); ?></td>
                                        <td><?php echo esc_html($data->count); ?></td>
                                        <td><?php echo number_format($data->total_premium, 2, ',', '.'); ?> ₺</td>
                                        <td>
                                            <div class="bar-chart">
                                                <div class="bar" style="width: <?php echo $percentage; ?>%"></div>
                                                <span><?php echo $percentage; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>Toplam</th>
                                    <th><?php echo array_sum(array_column($policy_type_data, 'count')); ?></th>
                                    <th><?php echo number_format($total_premium, 2, ',', '.'); ?> ₺</th>
                                    <th>100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    <?php else: ?>
                        <p>Veri bulunamadı.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dashboard-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportDashboardBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportDashboardOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Müşteri Portföy Raporu -->
        <div id="portfolio" class="report-tab-content <?php echo $current_tab === 'portfolio' ? 'active' : ''; ?>">
            <h3><i class="fas fa-users"></i> Müşteri Portföy Raporu</h3>
            <?php
            $portfolio_summary = get_customer_portfolio_summary(
                $representative_id,
                $filters['portfolio']['start_date'],
                $filters['portfolio']['end_date']
            );
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Bu Ay Yeni Müşteriler</h4>
                    <p><?php echo $portfolio_summary['new_customers']; ?> yeni müşteri</p>
                </div>
                <div class="summary-card">
                    <h4>Toplam Müşteriler</h4>
                    <p><?php echo $portfolio_summary['total']; ?> müşteri</p>
                </div>
                <div class="summary-card">
                    <h4>Aktif / Pasif / Belirsiz</h4>
                    <p><?php echo $portfolio_summary['active']; ?> / <?php echo $portfolio_summary['inactive']; ?> / <?php echo $portfolio_summary['uncertain']; ?></p>
                </div>
            </div>
            
            <!-- Filtreleme toggle -->
            <?php
            $portfolio_active_filter_count = 0;
            foreach ($filters['portfolio'] as $value) {
                if (!empty($value)) $portfolio_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-portfolio-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($portfolio_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $portfolio_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=portfolio" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $portfolio_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="portfolio-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="portfolio">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="portfolio_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="portfolio_start_date" id="portfolio_start_date" value="<?php echo esc_attr($filters['portfolio']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="portfolio_end_date">Bitiş Tarihi</label>
                        <input type="date" name="portfolio_end_date" id="portfolio_end_date" value="<?php echo esc_attr($filters['portfolio']['end_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="portfolio_status">Durum</label>
                        <select name="portfolio_status" id="portfolio_status" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="aktif" <?php echo $filters['portfolio']['status'] === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="pasif" <?php echo $filters['portfolio']['status'] === 'pasif' ? 'selected' : ''; ?>>Pasif</option>
                            <option value="belirsiz" <?php echo $filters['portfolio']['status'] === 'belirsiz' ? 'selected' : ''; ?>>Belirsiz</option>
                        </select>
                    </div>
                    <div class="ab-form-group">
                        <label for="portfolio_category">Kategori</label>
                        <select name="portfolio_category" id="portfolio_category" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="bireysel" <?php echo $filters['portfolio']['category'] === 'bireysel' ? 'selected' : ''; ?>>Bireysel</option>
                            <option value="kurumsal" <?php echo $filters['portfolio']['category'] === 'kurumsal' ? 'selected' : ''; ?>>Kurumsal</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="portfolio-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=portfolio" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Ad Soyad</th>
                        <th>Kategori</th>
                        <th>Durum</th>
                        <th>Kayıt Tarihi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $portfolio_data = get_customer_portfolio_data(
                        $representative_id,
                        $filters['portfolio']['start_date'],
                        $filters['portfolio']['end_date'],
                        $filters['portfolio']['status'],
                        $filters['portfolio']['category']
                    );
                    if ($portfolio_data) {
                        foreach ($portfolio_data as $data) {
                            echo '<tr>';
                            echo '<td>' . esc_html($data->first_name . ' ' . $data->last_name) . '</td>';
                            echo '<td>' . esc_html(ucfirst($data->category)) . '</td>';
                            echo '<td>' . esc_html(ucfirst($data->status)) . '</td>';
                            echo '<td>' . esc_html(date('d.m.Y', strtotime($data->created_at))) . '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="4">Veri bulunamadı.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportPortfolioBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportPortfolioOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Poliçe Yenileme Raporu -->
        <div id="renewals" class="report-tab-content <?php echo $current_tab === 'renewals' ? 'active' : ''; ?>">
            <h3><i class="fas fa-sync-alt"></i> Poliçe Yenileme Raporu</h3>
            <p class="report-description">Bu rapor, poliçe bitiş tarihlerine göre yenileme ihtiyacı olan poliçeleri listeler. Yenileme durumu, aynı müşteri ve poliçe türü için daha yeni bir poliçe olup olmadığına bakılarak belirlenir.</p>
            <?php
            $renewal_summary = get_policy_renewal_summary(
                $representative_id,
                $filters['renewals']['start_date'],
                $filters['renewals']['end_date']
            );
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Bu Ay Yenilenecek</h4>
                    <p><?php echo $renewal_summary['upcoming']; ?> poliçe</p>
                </div>
                <div class="summary-card">
                    <h4>Toplam Poliçe</h4>
                    <p><?php echo $renewal_summary['total']; ?> poliçe</p>
                </div>
                <div class="summary-card">
                    <h4>Yenilenen / Yenilenmeyen</h4>
                    <p><?php echo $renewal_summary['renewed']; ?> / <?php echo $renewal_summary['not_renewed']; ?></p>
                </div>
            </div>
            
            <!-- Filtreleme toggle -->
            <?php
            $renewals_active_filter_count = 0;
            foreach ($filters['renewals'] as $value) {
                if (!empty($value)) $renewals_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-renewals-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($renewals_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $renewals_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=renewals" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $renewals_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="renewals-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="renewals">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="renewals_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="renewals_start_date" id="renewals_start_date" value="<?php echo esc_attr($filters['renewals']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="renewals_end_date">Bitiş Tarihi</label>
                        <input type="date" name="renewals_end_date" id="renewals_end_date" value="<?php echo esc_attr($filters['renewals']['end_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="renewals_policy_type">Poliçe Türü</label>
                        <select name="renewals_policy_type" id="renewals_policy_type" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="Kasko" <?php echo $filters['renewals']['policy_type'] === 'Kasko' ? 'selected' : ''; ?>>Kasko</option>
                            <option value="Sağlık" <?php echo $filters['renewals']['policy_type'] === 'Sağlık' ? 'selected' : ''; ?>>Sağlık</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="renewals-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=renewals" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Müşteri Adı
                            <a href="<?php echo add_query_arg(array('orderby' => 'customer_name', 'order' => $renewals_orderby === 'customer_name' && $renewals_order === 'ASC' ? 'DESC' : 'ASC')); ?>" class="sort-icon">
                                <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>Poliçe No</th>
                        <th>Poliçe Türü</th>
                        <th>Bitiş Tarihi
                            <a href="<?php echo add_query_arg(array('orderby' => 'end_date', 'order' => $renewals_orderby === 'end_date' && $renewals_order === 'ASC' ? 'DESC' : 'ASC')); ?>" class="sort-icon">
                                <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>Durum</th>
                        <th>Yenileme Durumu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $renewal_data = get_policy_renewal_data(
                        $representative_id,
                        $filters['renewals']['start_date'],
                        $filters['renewals']['end_date'],
                        $filters['renewals']['policy_type'],
                        $renewals_orderby,
                        $renewals_order
                    );
                    if ($renewal_data) {
                        foreach ($renewal_data as $data) {
                            $renewal_status_class = $data->renewed ? 'renewed' : 'not-renewed';
                            echo '<tr class="' . $renewal_status_class . '">';
                            echo '<td>' . esc_html($data->customer_name) . '</td>';
                            echo '<td>' . esc_html($data->policy_number) . '</td>';
                            echo '<td>' . esc_html($data->policy_type) . '</td>';
                            echo '<td>' . esc_html(date('d.m.Y', strtotime($data->end_date))) . '</td>';
                            echo '<td>' . esc_html($data->status) . '</td>';
                            echo '<td><span class="renewal-status ' . $renewal_status_class . '">' . ($data->renewed ? '<i class="fas fa-check-circle"></i> Yenilendi' : '<i class="fas fa-times-circle"></i> Yenilenmedi') . '</span></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6">Veri bulunamadı.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportRenewalsBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportRenewalsOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Satış Performans Raporu -->
        <div id="sales" class="report-tab-content <?php echo $current_tab === 'sales' ? 'active' : ''; ?>">
            <h3><i class="fas fa-chart-line"></i> Satış Performans Raporu</h3>
            <p class="report-description">Bu rapor, poliçe satışlarınızı aylık bazda gösterir. Satışlar, poliçe tablosundan türetilmiştir. Hedef ve gerçekleşen satış oranlaması da eklenmiştir.</p>
            <?php
            $sales_data = get_sales_performance_data(
                $representative_id,
                $filters['sales']['start_date'],
                $filters['sales']['end_date']
            );
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Toplam Satış</h4>
                    <p><?php echo $sales_data['total_sales']; ?> poliçe</p>
                </div>
                <div class="summary-card">
                    <h4>Hedef</h4>
                    <p><?php echo $sales_data['target_sales']; ?> poliçe</p>
                </div>
                <div class="summary-card achievement-card <?php echo $sales_data['achievement_rate'] >= 100 ? 'target-met' : 'target-not-met'; ?>">
                    <h4>Başarı Oranı</h4>
                    <p><?php echo $sales_data['achievement_rate']; ?>%</p>
                    <div class="progress-bar">
                        <div class="progress" style="width: <?php echo min(100, $sales_data['achievement_rate']); ?>%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Filtreleme toggle -->
            <?php
            $sales_active_filter_count = 0;
            foreach ($filters['sales'] as $value) {
                if (!empty($value)) $sales_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-sales-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($sales_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $sales_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=sales" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $sales_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="sales-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="sales">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="sales_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="sales_start_date" id="sales_start_date" value="<?php echo esc_attr($filters['sales']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="sales_end_date">Bitiş Tarihi</label>
                        <input type="date" name="sales_end_date" id="sales_end_date" value="<?php echo esc_attr($filters['sales']['end_date']); ?>" class="ab-input">
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="sales-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=sales" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <!-- Grafik ve Tablo Geçiş Butonları -->
            <div class="view-toggle">
                <button class="ab-btn view-btn active" data-view="table"><i class="fas fa-table"></i> Tablo Görünümü</button>
                <button class="ab-btn view-btn" data-view="chart"><i class="fas fa-chart-bar"></i> Grafik Görünümü</button>
            </div>
            
            <div class="view-content table-view active">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Dönem</th>
                            <th>Satış Sayısı</th>
                            <th>Toplam Prim (₺)</th>
                            <th>Yeni Müşteri</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($sales_data['data']) {
                            foreach ($sales_data['data'] as $data) {
                                echo '<tr>';
                                echo '<td>' . esc_html($data->period) . '</td>';
                                echo '<td>' . esc_html($data->sales_count) . '</td>';
                                echo '<td>' . esc_html(number_format($data->total_premium, 2, ',', '.')) . ' ₺</td>';
                                echo '<td>' . esc_html($data->new_customers) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4">Veri bulunamadı. Tarih aralığını kontrol edin.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <div class="view-content chart-view">
                <div class="chart-container">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportSalesBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportSalesOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Müşteri Etkileşim Raporu -->
        <div id="interactions" class="report-tab-content <?php echo $current_tab === 'interactions' ? 'active' : ''; ?>">
            <h3><i class="fas fa-comments"></i> Müşteri Etkileşim Raporu</h3>
            <p class="report-description">Bu rapor, müşterilerle yapılan telefon, e-posta veya yüz yüze etkileşimleri listeler.</p>
            
            <!-- Filtreleme toggle -->
            <?php
            $interactions_active_filter_count = 0;
            foreach ($filters['interactions'] as $value) {
                if (!empty($value)) $interactions_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-interactions-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($interactions_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $interactions_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=interactions" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $interactions_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="interactions-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="interactions">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="interactions_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="interactions_start_date" id="interactions_start_date" value="<?php echo esc_attr($filters['interactions']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="interactions_end_date">Bitiş Tarihi</label>
                        <input type="date" name="interactions_end_date" id="interactions_end_date" value="<?php echo esc_attr($filters['interactions']['end_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="interactions_interaction_type">Etkileşim Türü</label>
                        <select name="interactions_interaction_type" id="interactions_interaction_type" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="Telefon" <?php echo $filters['interactions']['interaction_type'] === 'Telefon' ? 'selected' : ''; ?>>Telefon</option>
                            <option value="E-posta" <?php echo $filters['interactions']['interaction_type'] === 'E-posta' ? 'selected' : ''; ?>>E-posta</option>
                            <option value="Yüz Yüze" <?php echo $filters['interactions']['interaction_type'] === 'Yüz Yüze' ? 'selected' : ''; ?>>Yüz Yüze</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="interactions-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=interactions" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Müşteri Adı</th>
                        <th>Etkileşim Tarihi</th>
                        <th>Tür</th>
                        <th>Notlar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $interaction_data = get_customer_interaction_data(
                        $representative_id,
                        $filters['interactions']['start_date'],
                        $filters['interactions']['end_date'],
                        $filters['interactions']['interaction_type']
                    );
                    if ($interaction_data) {
                        foreach ($interaction_data as $data) {
                            echo '<tr>';
                            echo '<td>' . esc_html($data->customer_name) . '</td>';
                            echo '<td>' . esc_html(date('d.m.Y H:i', strtotime($data->interaction_date))) . '</td>';
                            echo '<td><span class="interaction-type type-' . strtolower(str_replace(' ', '-', $data->type)) . '">' . esc_html($data->type) . '</span></td>';
                            echo '<td>' . esc_html($data->notes) . '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="4">Veri bulunamadı.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportInteractionsBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportInteractionsOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Şikayet ve Memnuniyet Raporu -->
        <div id="complaints" class="report-tab-content <?php echo $current_tab === 'complaints' ? 'active' : ''; ?>">
            <h3><i class="fas fa-exclamation-circle"></i> Şikayet ve Memnuniyet Raporu</h3>
            <p class="report-description">Bu rapor, müşterilerden gelen şikayetleri ve çözüm durumlarını gösterir. Çözülen şikayetlerin ortalama çözüm süresi de hesaplanmıştır.</p>
            <?php
            $complaints_summary = get_complaints_summary(
                $representative_id,
                $filters['complaints']['start_date'],
                $filters['complaints']['end_date']
            );
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Toplam Şikayet</h4>
                    <p><?php echo $complaints_summary['total']; ?> şikayet</p>
                </div>
                <div class="summary-card">
                    <h4>Çözülen / Bekleyen</h4>
                    <p><?php echo $complaints_summary['resolved']; ?> / <?php echo $complaints_summary['pending']; ?></p>
                </div>
                <div class="summary-card">
                    <h4>Ort. Çözüm Süresi</h4>
                    <p><?php echo $complaints_summary['avg_resolution_days']; ?> gün</p>
                </div>
            </div>
            
            <!-- Filtreleme toggle -->
            <?php
            $complaints_active_filter_count = 0;
            foreach ($filters['complaints'] as $value) {
                if (!empty($value)) $complaints_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-complaints-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($complaints_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $complaints_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=complaints" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $complaints_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="complaints-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="complaints">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="complaints_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="complaints_start_date" id="complaints_start_date" value="<?php echo esc_attr($filters['complaints']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="complaints_end_date">Bitiş Tarihi</label>
                        <input type="date" name="complaints_end_date" id="complaints_end_date" value="<?php echo esc_attr($filters['complaints']['end_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="complaints_status">Durum</label>
                        <select name="complaints_status" id="complaints_status" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="Çözüldü" <?php echo $filters['complaints']['status'] === 'Çözüldü' ? 'selected' : ''; ?>>Çözüldü</option>
                            <option value="Bekliyor" <?php echo $filters['complaints']['status'] === 'Bekliyor' ? 'selected' : ''; ?>>Bekliyor</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="complaints-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=complaints" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Müşteri Adı</th>
                        <th>Şikayet Tarihi</th>
                        <th>Konu</th>
                        <th>Durum</th>
                        <th>Çözüm Süresi (Gün)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $complaints_data = get_complaints_data(
                        $representative_id,
                        $filters['complaints']['start_date'],
                        $filters['complaints']['end_date'],
                        $filters['complaints']['status']
                    );
                    if ($complaints_data) {
                        foreach ($complaints_data as $data) {
                            $status_class = $data->status === 'Çözüldü' ? 'resolved' : 'pending';
                            echo '<tr class="complaint-' . $status_class . '">';
                            echo '<td>' . esc_html($data->customer_name) . '</td>';
                            echo '<td>' . esc_html(date('d.m.Y H:i', strtotime($data->complaint_date))) . '</td>';
                            echo '<td>' . esc_html($data->subject) . '</td>';
                            echo '<td><span class="complaint-status status-' . strtolower($status_class) . '">' . esc_html($data->status) . '</span></td>';
                            echo '<td>' . ($data->resolution_days ? esc_html($data->resolution_days) : '-') . '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="5">Veri bulunamadı.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportComplaintsBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportComplaintsOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
        
        <!-- Potansiyel Müşteriler Raporu -->
        <div id="leads" class="report-tab-content <?php echo $current_tab === 'leads' ? 'active' : ''; ?>">
            <h3><i class="fas fa-user-plus"></i> Potansiyel Müşteriler Raporu</h3>
            <p class="report-description">Bu rapor, müşteri temsilcisi tarafından manuel olarak eklenen veya bir CRM sistemine entegre bir kaynaktan (örneğin, web formu) otomatik olarak çekilen potansiyel müşteri verilerini listeler.</p>
            <?php
            $leads_summary = get_leads_summary(
                $representative_id,
                $filters['leads']['start_date'],
                $filters['leads']['end_date']
            );
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Toplam Potansiyel Müşteri</h4>
                    <p><?php echo $leads_summary['total_leads']; ?> potansiyel</p>
                </div>
                <div class="summary-card">
                    <h4>Dönüşen Müşteriler</h4>
                    <p><?php echo $leads_summary['converted_leads']; ?> müşteri</p>
                </div>
                <div class="summary-card">
                    <h4>Dönüşüm Oranı</h4>
                    <p><?php echo $leads_summary['conversion_rate']; ?>%</p>
                </div>
            </div>
            
            <!-- Filtreleme toggle -->
            <?php
            $leads_active_filter_count = 0;
            foreach ($filters['leads'] as $value) {
                if (!empty($value)) $leads_active_filter_count++;
            }
            ?>
            <div class="ab-filter-toggle-container">
                <button type="button" id="toggle-leads-filters" class="ab-btn ab-toggle-filters">
                    <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
                </button>
                
                <?php if ($leads_active_filter_count > 0): ?>
                <div class="ab-active-filters">
                    <span><?php echo $leads_active_filter_count; ?> aktif filtre</span>
                    <a href="?view=reports&tab=leads" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="get" class="report-filters ab-form-section <?php echo $leads_active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>" id="leads-filter-form">
                <?php wp_nonce_field('report_filter_nonce', 'report_filter_nonce'); ?>
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="tab" value="leads">
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="leads_start_date">Başlangıç Tarihi</label>
                        <input type="date" name="leads_start_date" id="leads_start_date" value="<?php echo esc_attr($filters['leads']['start_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="leads_end_date">Bitiş Tarihi</label>
                        <input type="date" name="leads_end_date" id="leads_end_date" value="<?php echo esc_attr($filters['leads']['end_date']); ?>" class="ab-input">
                    </div>
                    <div class="ab-form-group">
                        <label for="leads_interest">İlgi Alanı</label>
                        <select name="leads_interest" id="leads_interest" class="ab-select">
                            <option value="">Tümü</option>
                            <option value="Kasko" <?php echo $filters['leads']['interest'] === 'Kasko' ? 'selected' : ''; ?>>Kasko</option>
                            <option value="Sağlık" <?php echo $filters['leads']['interest'] === 'Sağlık' ? 'selected' : ''; ?>>Sağlık</option>
                            <option value="Konut" <?php echo $filters['leads']['interest'] === 'Konut' ? 'selected' : ''; ?>>Konut</option>
                            <option value="DASK" <?php echo $filters['leads']['interest'] === 'DASK' ? 'selected' : ''; ?>>DASK</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div id="leads-filter-error" class="ab-notice ab-error" style="display: none; margin-bottom: 10px;">
                        Lütfen tarih seçin.
                    </div>
                    <div class="ab-form-group ab-button-group">
                        <button type="submit" class="ab-btn ab-btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                        <a href="?view=reports&tab=leads" class="ab-btn ab-btn-secondary">Sıfırla</a>
                    </div>
                </div>
            </form>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Potansiyel Müşteri</th>
                        <th>İletişim Bilgisi</th>
                        <th>İlgi Alanı</th>
                        <th>Son Etkileşim</th>
                        <th>Geçen Süre (Gün)</th>
                        <th>Dönüşüm Durumu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $leads_data = get_leads_data(
                        $representative_id,
                        $filters['leads']['start_date'],
                        $filters['leads']['end_date'],
                        $filters['leads']['interest']
                    );
                    if ($leads_data) {
                        foreach ($leads_data as $data) {
                            $conversion_class = $data->converted ? 'converted' : 'not-converted';
                            $days_class = '';
                            if ($data->days_since_interaction > 30) {
                                $days_class = 'stale-lead';
                            } elseif ($data->days_since_interaction > 14) {
                                $days_class = 'aging-lead';
                            }
                            
                            echo '<tr class="' . $conversion_class . ' ' . $days_class . '">';
                            echo '<td>' . esc_html($data->lead_name) . '</td>';
                            echo '<td>' . esc_html($data->contact_info) . '</td>';
                            echo '<td>' . esc_html($data->interest) . '</td>';
                            echo '<td>' . esc_html(date('d.m.Y H:i', strtotime($data->last_interaction))) . '</td>';
                            echo '<td>' . esc_html($data->days_since_interaction) . '</td>';
                            echo '<td><span class="conversion-status ' . $conversion_class . '">' . ($data->converted ? '<i class="fas fa-check-circle"></i> Dönüştü' : '<i class="fas fa-hourglass-half"></i> Dönüşmedi') . '</span></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6">Veri bulunamadı.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <div class="report-actions">
                <a href="javascript:void(0);" class="ab-btn ab-btn-primary export-button" id="exportLeadsBtn">
                    <i class="fas fa-file-export"></i> Raporu İndir
                </a>
                <div class="export-dropdown" id="exportLeadsOptions">
                    <a href="#" class="export-option" data-format="pdf"><i class="fas fa-file-pdf"></i> PDF olarak indir</a>
                    <a href="#" class="export-option" data-format="excel"><i class="fas fa-file-excel"></i> Excel olarak indir</a>
                    <a href="#" class="export-option" data-format="print"><i class="fas fa-print"></i> Yazdır</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.report-tabs {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
    margin-top: 20px;
    box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05);
}

.report-tabs-nav {
    display: flex;
    overflow-x: auto;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
}

.report-tab {
    padding: 10px 18px;
    font-weight: 500;
    color: #333;
    text-decoration: none;
    position: relative;
    white-space: nowrap;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}

.report-tab i {
    color: #666;
    font-size: 14px;
}

.report-tab:hover {
    background-color: #f0f0f0;
}

.report-tab.active {
    color: #4caf50;
    border-bottom: 2px solid #4caf50;
    background-color: #f0f8f1;
}

.report-tab.active i {
    color: #4caf50;
}

.report-tab-content {
    display: none;
    padding: 15px;
}

.report-tab-content.active {
    display: block;
}

.report-tab-content h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    color: #333;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.report-tab-content h3 i {
    color: #4caf50;
}

.report-description {
    margin-bottom: 15px;
    font-size: 14px;
    color: #666;
}

.summary-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.summary-card {
    background-color: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 15px;
    flex: 1;
    min-width: 200px;
    text-align: center;
}

.summary-card h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.summary-card p {
    margin: 0;
    font-size: 16px;
    font-weight: 500;
    color: #4caf50;
}

/* Filtre Toggle - Yeni Eklenen CSS */
.ab-filter-toggle-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.ab-toggle-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    font-weight: 500;
    transition: all 0.2s;
}

.ab-toggle-filters:hover, .ab-toggle-filters.active {
    background-color: #e9ecef;
    border-color: #ccc;
}

.ab-toggle-filters.active i.fa-chevron-down {
    transform: rotate(180deg);
}

.ab-filters-hidden {
    display: none;
}

.ab-active-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #555;
    padding: 5px 10px;
    background-color: #f0f8ff;
    border-radius: 4px;
    border: 1px solid #cce5ff;
}

.ab-clear-filters {
    color: #007bff;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 3px;
}

.ab-clear-filters:hover {
    text-decoration: underline;
}

.report-filters {
    margin-bottom: 15px;
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 15px;
    border-radius: 6px;
}

.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: center;
}

.ab-form-group {
    flex: 1;
    min-width: 200px;
}

.ab-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
    font-size: 13px;
}

.ab-form-group input, .ab-form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
    color: #333;
    background-color: #fff;
}

.ab-button-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
}

.report-table th, .report-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #e0e0e0;
    font-size: 13px;
}

.report-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.report-table th a.sort-icon {
    color: #444;
    text-decoration: none;
    margin-left: 5px;
}

.report-table th a.sort-icon:hover {
    color: #000;
}

.report-table tbody tr:hover {
    background-color: #f9f9f9;
}

/* Yenileme Durumu Stilleri */
.renewal-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.renewal-status.renewed {
    background-color: #e6ffed;
    color: #22863a;
}

.renewal-status.not-renewed {
    background-color: #ffeef0;
    color: #cb2431;
}

tr.renewed td {
    background-color: #f0fff4 !important;
}

tr.not-renewed td {
    background-color: #fff5f5 !important;
}

/* Etkileşim Türü Stilleri */
.interaction-type {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    background-color: #f1f8ff;
    color: #0366d6;
}

.interaction-type.type-telefon {
    background-color: #e6f7ff;
    color: #0366d6;
}

.interaction-type.type-e-posta {
    background-color: #fffde7;
    color: #856404;
}

.interaction-type.type-yüz-yüze {
    background-color: #e6ffed;
    color: #22863a;
}

/* Şikayet Durumu Stilleri */
.complaint-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.complaint-status.status-resolved {
    background-color: #e6ffed;
    color: #22863a;
}

.complaint-status.status-pending {
    background-color: #fffde7;
    color: #856404;
}

tr.complaint-resolved td {
    background-color: #f0fff4 !important;
}

tr.complaint-pending td {
    background-color: #fffbeb !important;
}

/* Dönüşüm Durumu Stilleri */
.conversion-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.conversion-status.converted {
    background-color: #e6ffed;
    color: #22863a;
}

.conversion-status.not-converted {
    background-color: #f6f8fa;
    color: #666;
}

tr.converted td {
    background-color: #f0fff4 !important;
}

tr.stale-lead td {
    background-color: #fff5f5 !important;
}

tr.aging-lead td {
    background-color: #fffde7 !important;
}

/* Dashboard Stilleri */
.dashboard-period-selector {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 15px;
    align-items: center;
    gap: 10px;
}

.period-label {
    font-size: 14px;
    color: #666;
}

.period-label strong {
    color: #333;
}

.period-form {
    display: flex;
    align-items: center;
    margin: 0;
}

.period-select {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    color: #333;
    font-size: 13px;
}

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.dashboard-card {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.card-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background-color: #f0f8ff;
    color: #0366d6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.customers-card .card-icon {
    background-color: #e6f7ff;
    color: #0366d6;
}

.sales-card .card-icon {
    background-color: #e6ffed;
    color: #22863a;
}

.renewals-card .card-icon {
    background-color: #fff8e5;
    color: #bf8700;
}

.leads-card .card-icon {
    background-color: #ffeef0;
    color: #cb2431;
}

.card-content {
    flex: 1;
}

.card-content h4 {
    margin: 0 0 5px;
    font-size: 14px;
    color: #666;
    font-weight: 500;
}

.card-value {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
}

.card-details {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 13px;
    color: #666;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.detail-item i.active-status {
    color: #22863a;
    font-size: 10px;
}

.detail-item i.inactive-status {
    color: #cb2431;
    font-size: 10px;
}

.progress-bar {
    height: 6px;
    background-color: #f0f0f0;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 5px;
    position: relative;
}

.progress {
    height: 100%;
    background-color: #4caf50;
    border-radius: 3px;
}

.progress-text {
    position: absolute;
    top: -18px;
    right: 0;
    font-size: 12px;
    font-weight: 500;
    color: #4caf50;
}

.achievement-card.target-met .card-value,
.achievement-card.target-met .progress-text {
    color: #22863a;
}

.achievement-card.target-not-met .card-value,
.achievement-card.target-not-met .progress-text {
    color: #cb2431;
}

.dashboard-section {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.dashboard-section h4 {
    font-size: 16px;
    font-weight: 500;
    color: #333;
    margin-top: 0;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.dashboard-section h4 i {
    color: #4caf50;
}

.policy-distribution {
    width: 100%;
}

.policy-type-table {
    width: 100%;
    border-collapse: collapse;
}

.policy-type-table th, 
.policy-type-table td {
    padding: 10px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.policy-type-table th {
    background-color: #f8f9fa;
    color: #333;
    font-weight: 600;
}

.policy-type-table tfoot th {
    border-top: 2px solid #ddd;
    font-weight: 600;
}

.bar-chart {
    width: 100%;
    height: 12px;
    background-color: #f0f0f0;
    border-radius: 6px;
    position: relative;
    overflow: hidden;
}

.bar {
    height: 100%;
    background-color: #4caf50;
    border-radius: 6px;
}

.bar-chart span {
    position: absolute;
    right: 5px;
    top: -16px;
    font-size: 12px;
    font-weight: 500;
    color: #666;
}

/* Grafik ve Tablo Görünüm Geçiş Butonları */
.view-toggle {
    display: flex;
    justify-content: center;
    margin-bottom: 15px;
    gap: 10px;
}

.view-btn {
    padding: 5px 12px;
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    color: #666;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
}

.view-btn.active {
    background-color: #e9ecef;
    color: #333;
    font-weight: 500;
    box-shadow: inset 0 2px 3px rgba(0,0,0,0.05);
}

.view-content {
    display: none;
}

.view-content.active {
    display: block;
}

.chart-container {
    height: 400px;
    margin-bottom: 20px;
}

/* Dışa Aktarma Butonları */
.report-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    position: relative;
}

.export-button {
    background-color: #4caf50;
    color: white;
    border-color: #43a047;
}

.export-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    z-index: 100;
    min-width: 180px;
    display: none;
    margin-top: 5px;
}

.export-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    color: #333;
    text-decoration: none;
    font-size: 13px;
    border-bottom: 1px solid #eee;
}

.export-option:last-child {
    border-bottom: none;
}

.export-option:hover {
    background-color: #f5f5f5;
}

.export-option i.fa-file-pdf {
    color: #e53935;
}

.export-option i.fa-file-excel {
    color: #4caf50;
}

.export-option i.fa-print {
    color: #607d8b;
}

/* Dashboard Actions */
.dashboard-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    position: relative;
}

.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    transition: all 0.2s;
    font-weight: 500;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
}

.ab-btn-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #3d9140;
    color: white;
}

.ab-btn-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
}

.ab-btn-secondary:hover {
    background-color: #e5e7eb;
}

.ab-notice.ab-error {
    padding: 8px 12px;
    margin-bottom: 10px;
    border-left: 4px solid #e53e3e;
    background-color: #fff5f5;
    color: #e53e3e;
    font-size: 13px;
    border-radius: 3px;
}

@media (max-width: 768px) {
    .report-tabs-nav {
        flex-wrap: wrap;
    }
    
    .report-tab {
        flex-grow: 1;
        text-align: center;
        justify-content: center;
        padding: 8px 12px;
        font-size: 12px;
    }
    
    .report-tab-content {
        padding: 12px;
    }
    
    .ab-form-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-form-group {
        width: 100%;
        min-width: 100%;
    }
    
    .ab-button-group {
        justify-content: center;
        width: 100%;
    }
    
    .report-table th, .report-table td {
        font-size: 12px;
        padding: 8px 10px;
    }
    
    .summary-card {
        min-width: 100%;
    }
    
    .dashboard-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 576px) {
    .report-tab-content {
        padding: 10px;
    }
    
    .report-table th, .report-table td {
        font-size: 11px;
        padding: 6px 8px;
    }
    
    .ab-filter-toggle-container {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // URL'den aktif sekme parametresini al
    function getParameterByName(name) {
        var url = window.location.href;
        name = name.replace(/[\[\]]/g, '\\$&');
        var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
            results = regex.exec(url);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, ' '));
    }

    // Sayfada aktif sekme durumunu koru
    var currentTab = getParameterByName('tab') || 'dashboard';
    $('.report-tab').removeClass('active');
    $('.report-tab-content').removeClass('active').hide();
    
    $('.report-tab[data-tab="' + currentTab + '"]').addClass('active');
    $('#' + currentTab).addClass('active').show();
    
    // Sekme değiştirme
    $('.report-tab').click(function(e) {
        e.preventDefault();
        var target = $(this).data('tab');
        
        $('.report-tab').removeClass('active');
        $(this).addClass('active');
        
        $('.report-tab-content').removeClass('active').hide();
        $('#' + target).addClass('active').show();

        // URL'yi güncelle ama sayfa yenileme olmadan
        history.pushState(null, null, '?view=reports&tab=' + target);
    });

    // Filtreleme toggle butonları
    $('.ab-toggle-filters').click(function() {
        var formId = $(this).attr('id').replace('toggle-', '') + '-form';
        $('#' + formId).slideToggle(200);
        $(this).toggleClass('active');
    });

    // Her form için ayrı submit kontrolü
    $('.report-filters').on('submit', function(e) {
        var formId = $(this).attr('id');
        var startDate = $('#' + formId + ' [name$="_start_date"]').val();
        var endDate = $('#' + formId + ' [name$="_end_date"]').val();
        
        if (formId !== 'dashboard-filter-form') { // Dashboard'da tarih kontrolü yapmıyoruz
            // Tarih kontrolü: Başlangıç ve bitiş tarihi bir arada olmalı veya ikisi de boş olmalı
            if ((startDate && !endDate) || (!startDate && endDate)) {
                e.preventDefault();
                $('#' + formId + '-filter-error').show();
                setTimeout(function() {
                    $('#' + formId + '-filter-error').fadeOut();
                }, 3000);
                return false;
            }
        }

        // Form gönderildiğinde sekme durumunu koru
        var currentTab = getParameterByName('tab') || 'portfolio';
        $(this).find('input[name="tab"]').val(currentTab);
    });

    // Grafik ve Tablo görünümü geçişleri
    $('.view-btn').click(function() {
        var view = $(this).data('view');
        $('.view-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.view-content').removeClass('active').hide();
        $('.' + view + '-view').addClass('active').show();
        
        // Grafik görünümünde grafiği yükle
        if (view === 'chart' && typeof renderChart === 'function') {
            renderChart();
        }
    });

    // Dışa aktarma butonları ve dropdown menüleri
    $('.export-button').click(function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Diğer açık dropdown'ları kapat
        $('.export-dropdown').hide();
        
        // Bu butonun dropdown'ını göster/gizle
        var dropdownId = $(this).attr('id').replace('Btn', 'Options');
        $('#' + dropdownId).toggle();
    });
    
    // Dışa aktar seçenekleri tıklamaları
    $('.export-option').click(function(e) {
        e.preventDefault();
        
        var format = $(this).data('format');
        var tab = getParameterByName('tab') || 'portfolio';
        
        // Dışa aktarma fonksiyonunu çağır
        exportReport(tab, format);
        
        // Dropdown'ı kapat
        $(this).closest('.export-dropdown').hide();
    });
    
    // Sayfa dışına tıklanınca dropdown'ları kapat
    $(document).click(function(e) {
        if (!$(e.target).closest('.export-button, .export-dropdown').length) {
            $('.export-dropdown').hide();
        }
    });

    // Sayfanın yenilenmesi durumunda sekme durumunu koru
    $(window).on('popstate', function() {
        var currentTab = getParameterByName('tab') || 'portfolio';
        $('.report-tab').removeClass('active');
        $('.report-tab-content').removeClass('active').hide();
        
        $('.report-tab[data-tab="' + currentTab + '"]').addClass('active');
        $('#' + currentTab).addClass('active').show();
    });
    
    // Grafik verilerini yükleme ve çizme fonksiyonu
    function renderChart() {
        // Burada grafik çizimi için gerekli kodlar
        // (Chart.js gibi bir kütüphane ile entegre edilmelidir)
        console.log('Grafik çiziliyor...');
    }
    
    // Raporu dışa aktarma fonksiyonu
    function exportReport(tab, format) {
        console.log('Rapor dışa aktarılıyor: ' + tab + ' - ' + format);
        
        // Gerçek bir dışa aktarma işlemi için AJAX çağrısı yapılabilir
        // Örneğin: ajax_url ile WordPress admin-ajax.php'ye istek gönderilebilir
        
        // Yazdırma işlemi için basit bir uygulama:
        if (format === 'print') {
            window.print();
        }
    }
});
</script>