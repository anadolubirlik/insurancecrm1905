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

// Kullanıcı rolü kontrol fonksiyonları
// Patron kontrolü
function is_patron($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
    
    if (empty($management_hierarchy['patron_id'])) return false;
    
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return false;
    
    return ($management_hierarchy['patron_id'] == $rep->id);
}

// Müdür kontrolü
function is_manager($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
    
    if (empty($management_hierarchy['manager_id'])) return false;
    
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return false;
    
    return ($management_hierarchy['manager_id'] == $rep->id);
}

// Ekip lideri kontrolü ve ekip üyeleri
function is_team_leader($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $teams = $settings['teams_settings']['teams'] ?? [];
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    if (!$rep) return false;
    foreach ($teams as $team) {
        if ($team['leader_id'] == $rep->id) return true;
    }
    return false;
}

// Ekip üyeleri listesi
function get_team_members($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $teams = $settings['teams_settings']['teams'] ?? [];
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    if (!$rep) return [];
    foreach ($teams as $team) {
        if ($team['leader_id'] == $rep->id) {
            $members = array_merge([$team['leader_id']], $team['members']);
            return array_unique($members);
        }
    }
    return [];
}

/**
 * Kullanıcının rolüne göre yetkisi olan temsilci ID'lerini döndüren fonksiyon
 */
function get_authorized_representatives($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    // Temsilci ID'sini al
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return [];
    
    // Patron ise tüm temsilcileri görebilir
    if (is_patron($user_id)) {
        return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
    }
    
    // Müdür ise tüm ekipleri görebilir
    if (is_manager($user_id)) {
        $all_team_members = [];
        foreach ($teams as $team) {
            $all_team_members[] = $team['leader_id']; // Ekip liderini ekle
            $all_team_members = array_merge($all_team_members, $team['members']); // Ekip üyelerini ekle
        }
        return array_unique($all_team_members);
    }
    
    // Ekip lideri ise kendi ekip üyelerini görebilir
    if (is_team_leader($user_id)) {
        foreach ($teams as $team) {
            if ($team['leader_id'] == $rep->id) {
                // Lider kendisi + ekip üyeleri
                return array_unique(array_merge([$rep->id], $team['members']));
            }
        }
    }
    
    // Normal temsilci ise sadece kendisini görebilir
    return [$rep->id];
}

/**
 * Kullanıcının rolünü döndüren yardımcı fonksiyon
 */
function get_user_role_in_hierarchy($user_id) {
    if (is_patron($user_id)) return 'patron';
    if (is_manager($user_id)) return 'manager'; 
    if (is_team_leader($user_id)) return 'team_leader';
    return 'representative';
}

/**
 * Kullanıcının silme/düzenleme yetkisi olup olmadığını kontrol eden fonksiyon
 */
function has_delete_permission($user_id) {
    return is_patron($user_id) || is_manager($user_id);
}

/**
 * Kullanıcının görev atama yetkisini kontrol eden fonksiyon
 * @param int $user_id Yetki sorgulanacak kullanıcı ID
 * @param int $target_rep_id Görev atanacak temsilci ID
 * @return bool Görev atama yetkisi
 */
function can_assign_task($user_id, $target_rep_id) {
    // Patron ve müdür herkese görev atayabilir
    if (is_patron($user_id) || is_manager($user_id)) {
        return true;
    }
    
    // Ekip lideri sadece kendi ekibindekilere görev atayabilir
    if (is_team_leader($user_id)) {
        $team_members = get_team_members($user_id);
        return in_array($target_rep_id, $team_members);
    }
    
    // Diğer temsilciler görev atayamaz
    return false;
}

// Kullanıcının rolünü belirle
$user_role = get_user_role_in_hierarchy($current_user->ID);
$authorized_rep_ids = get_authorized_representatives($current_user->ID);

// Görünüm filtresi - varsayılan olarak kendi verileri
$view_filter = isset($_GET['view_filter']) ? sanitize_text_field($_GET['view_filter']) : 'own';

// Ekip verileri için temsilci ID'leri - önce kendi verilerini göster
if ($view_filter === 'own') {
    $rep_ids = [$representative->id];
} 
// Yetkili olduğu tüm verileri göster
else if ($view_filter === 'all' && ($user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader')) {
    if ($user_role == 'patron' || $user_role == 'manager') {
        // Patron ve müdür için tüm yetkilendirilen temsilciler
        $rep_ids = $authorized_rep_ids;
        // Ekip görünümü sayfalarında özel filtre
        if (strpos($current_view, 'team_') === 0 && isset($_GET['team_id'])) {
            $selected_team_id = sanitize_text_field($_GET['team_id']);
            $settings = get_option('insurance_crm_settings', []);
            $teams = $settings['teams_settings']['teams'] ?? [];
            if (isset($teams[$selected_team_id])) {
                $team_members = array_merge([$teams[$selected_team_id]['leader_id']], $teams[$selected_team_id]['members']);
                $rep_ids = array_intersect($rep_ids, $team_members);
            }
        }
    } else if ($user_role == 'team_leader') {
        // Mevcut ekip görünümü korunur
        $team_members = get_team_members($current_user->ID);
        $rep_ids = !empty($team_members) ? $team_members : [$representative->id];
    } else {
        // Normal temsilci sadece kendisini görür
        $rep_ids = [$representative->id];
    }
}
// Belirli bir ekibe filtreleme
else if (strpos($view_filter, 'team_') === 0 && ($user_role == 'patron' || $user_role == 'manager')) {
    $team_id = str_replace('team_', '', $view_filter);
    $settings = get_option('insurance_crm_settings', []);
    $teams = $settings['teams_settings']['teams'] ?? [];
    
    if (isset($teams[$team_id])) {
        $team_members = array_merge([$teams[$team_id]['leader_id']], $teams[$team_id]['members']);
        $rep_ids = array_intersect($authorized_rep_ids, $team_members);
    } else {
        $rep_ids = [$representative->id]; // Geçersiz ekip ID'si durumunda kendi verilerini göster
    }
}
// Özel temsilci filtrelemesi
else if (strpos($view_filter, 'rep_') === 0) {
    $target_rep_id = intval(str_replace('rep_', '', $view_filter));
    
    // Hedef temsilcinin görüntüleme yetkisini kontrol et
    if (in_array($target_rep_id, $authorized_rep_ids)) {
        $rep_ids = [$target_rep_id];
    } else {
        $rep_ids = [$representative->id]; // Yetkisiz durumda kendi verilerini göster
    }
}
else {
    // Varsayılan olarak kendi verilerini göster
    $rep_ids = [$representative->id];
}

// Ekip hedefi hesaplama
$team_target = 0;
$team_policy_target = 0;
if (count($rep_ids) > 1 || $user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader') {
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
        ...$rep_ids
    ));
    foreach ($targets as $target) {
        $team_target += floatval($target->monthly_target);
        $team_policy_target += intval($target->target_policy_count);
    }
} else {
    $team_target = $representative->monthly_target;
    $team_policy_target = isset($representative->target_policy_count) ? intval($representative->target_policy_count) : 0;
}

// Üye performans verileri
$member_performance = [];
if (count($rep_ids) > 1 || $user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader') {
    foreach ($rep_ids as $rep_id) {
        $member_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $rep_id
        ));
        if ($member_data) {
            $customers = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
                 WHERE representative_id = %d",
                $rep_id
            ));
            $policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND cancellation_date IS NULL",
                $rep_id
            ));
            $premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d",
                $rep_id
            ));
            $active_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND status = 'active' AND cancellation_date IS NULL",
                $rep_id
            ));
            $canceled_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND cancellation_date IS NOT NULL",
                $rep_id
            ));
            
            // Bu ay üretim rakamları
            $this_month_start = date('Y-m-01 00:00:00');
            $this_month_end = date('Y-m-t 23:59:59');
            $this_month_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND start_date BETWEEN %s AND %s",
                $rep_id, $this_month_start, $this_month_end
            ));
            $this_month_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND start_date BETWEEN %s AND %s AND cancellation_date IS NULL",
                $rep_id, $this_month_start, $this_month_end
            ));
            
            // Hedeflere uzaklık hesaplama
            $monthly_target = floatval($member_data->monthly_target);
            $target_policy_count = intval($member_data->target_policy_count);
            
            $premium_achievement = $monthly_target > 0 ? ($this_month_premium / $monthly_target) * 100 : 0;
            $policy_achievement = $target_policy_count > 0 ? ($this_month_policies / $target_policy_count) * 100 : 0;
            
            $member_performance[] = [
                'id' => $member_data->id,
                'name' => $member_data->display_name,
                'title' => $member_data->title,
                'customers' => $customers,
                'policies' => $policies,
                'premium' => $premium,
                'active_policies' => $active_policies,
                'canceled_policies' => $canceled_policies,
                'monthly_target' => $monthly_target,
                'target_policy_count' => $target_policy_count,
                'this_month_premium' => $this_month_premium,
                'this_month_policies' => $this_month_policies,
                'premium_achievement' => $premium_achievement,
                'policy_achievement' => $policy_achievement
            ];
        }
    }
}

// Ekip performans verilerini sıralama
if (!empty($member_performance)) {
    // Premium'a göre sıralama (en yüksekten en düşüğe)
    usort($member_performance, function($a, $b) {
        return $b['premium'] <=> $a['premium'];
    });
}

// Mevcut sorguları ekip için uyarlama
$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));

$this_month_start = date('Y-m-01 00:00:00');
$this_month_end = date('Y-m-t 23:59:59');
$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND created_at BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_customers = $new_customers ?: 0;
$customer_increase_rate = $total_customers > 0 ? ($new_customers / $total_customers) * 100 : 0;

$total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND cancellation_date IS NULL",
    ...$rep_ids
));

$total_policy_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));

$new_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_policies = $new_policies ?: 0;

$this_month_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$this_month_cancelled_policies = $this_month_cancelled_policies ?: 0;

$policy_increase_rate = $total_policies > 0 ? ($new_policies / $total_policies) * 100 : 0;

$total_refunded_amount = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) 
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));
$total_refunded_amount = $total_refunded_amount ?: 0;

$total_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));
if ($total_premium === null) $total_premium = 0;

$new_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_premium = $new_premium ?: 0;
$premium_increase_rate = $total_premium > 0 ? ($new_premium / $total_premium) * 100 : 0;

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $this_month_end . ' 23:59:59'])
));

if ($current_month_premium === null) $current_month_premium = 0;

$monthly_target = $team_target > 0 ? $team_target : 1;
$achievement_rate = ($current_month_premium / $monthly_target) * 100;
$achievement_rate = min(100, $achievement_rate);

// Poliçe hedeflerine göre hesaplamalar
$current_month_policy_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) 
     FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $this_month_end . ' 23:59:59'])
));

$policy_target = $team_policy_target > 0 ? $team_policy_target : 1;
$policy_achievement_rate = ($current_month_policy_count / $policy_target) * 100;
$policy_achievement_rate = min(100, $policy_achievement_rate);

$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, r.user_id as rep_user_id, u.display_name as representative_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 5",
    ...$rep_ids
));

$monthly_production_data = array();
$monthly_refunded_data = array();
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $monthly_production_data[$month_year] = 0;
    $monthly_refunded_data[$month_year] = 0;
}

try {
    $actual_data = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0) as total,
                COALESCE(SUM(refunded_amount), 0) as refunded
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
         AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
         GROUP BY month_year
         ORDER BY month_year ASC",
        ...$rep_ids
    ));
    
    foreach ($actual_data as $data) {
        if (isset($monthly_production_data[$data->month_year])) {
            $monthly_production_data[$data->month_year] = (float)$data->total;
            $monthly_refunded_data[$data->month_year] = (float)$data->refunded;
        }
    }
} catch (Exception $e) {
    error_log('Üretim verileri çekilirken hata: ' . $e->getMessage());
}

$monthly_policy_data = array();
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $monthly_policy_data[$month_year] = 0;
}

try {
    $policy_count_data = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                COUNT(*) as policy_count
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
         AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
         AND cancellation_date IS NULL
         GROUP BY month_year
         ORDER BY month_year ASC",
        ...$rep_ids
    ));
    
    foreach ($policy_count_data as $data) {
        if (isset($monthly_policy_data[$data->month_year])) {
            $monthly_policy_data[$data->month_year] = (int)$data->policy_count;
        }
    }
} catch (Exception $e) {
    error_log('Poliçe sayım verileri çekilirken hata: ' . $e->getMessage());
}

$monthly_production = array();
foreach ($monthly_production_data as $month_year => $total) {
    $monthly_production[] = array(
        'month' => $month_year,
        'total' => $total,
        'policy_count' => $monthly_policy_data[$month_year] ?? 0
    );
}

if ($wpdb->last_error) {
    error_log('SQL Hatası: ' . $wpdb->last_error);
}

$upcoming_renewals = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, r.user_id as rep_user_id, u.display_name as representative_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date ASC
     LIMIT 5",
    ...$rep_ids
));

$expired_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, r.user_id as rep_user_id, u.display_name as representative_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date < CURDATE()
     AND p.status != 'iptal'
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date DESC
     LIMIT 5",
    ...$rep_ids
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
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND status = 'pending'
     AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
    ...$rep_ids
));
if ($upcoming_tasks_count === null) $upcoming_tasks_count = 0;

$total_notification_count = $notification_count + $upcoming_tasks_count;

$upcoming_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name, r.user_id as rep_user_id, u.display_name as assigned_to
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status = 'pending'
     AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY t.due_date ASC
     LIMIT 5",
    ...$rep_ids
));

$current_month_start = date('Y-m-01');
$next_month_end = date('Y-m-t', strtotime('+1 month'));

$calendar_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(DATE(due_date), '%Y-%m-%d') as task_date, COUNT(*) as task_count
     FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND status IN ('pending', 'in_progress')
     AND due_date BETWEEN %s AND %s
     GROUP BY DATE(due_date)",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Takvim Görev Sorgusu Hatası: ' . $wpdb->last_error);
}

$upcoming_tasks_list = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name, r.user_id as rep_user_id, u.display_name as assigned_to
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status IN ('pending', 'in_progress')
     AND t.due_date BETWEEN %s AND %s
     ORDER BY t.due_date ASC
     LIMIT 10",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Yaklaşan Görevler Sorgusu Hatası: ' . $wpdb->last_error);
}

// Patron ve müdür için özel veri - tüm ekipler
$all_teams = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    foreach ($teams as $team_id => $team) {
        $leader_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $team['leader_id']
        ));
        
        if ($leader_data) {
            // Ekip üyelerinin sayısı
            $member_count = count($team['members']);
            
            // Ekip toplam primi ve poliçe sayısı hesaplama
            $team_ids = array_merge([$team['leader_id']], $team['members']);
            $team_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")",
                ...$team_ids
            ));
            
            $team_policy_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                 AND cancellation_date IS NULL",
                ...$team_ids
            ));
            
            // Ekip hedefleri toplam
            $team_monthly_target = $leader_data->monthly_target;
            $team_policy_target = $leader_data->target_policy_count;
            
            foreach ($team['members'] as $member_id) {
                $member_target = $wpdb->get_row($wpdb->prepare(
                    "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                    $member_id
                ));
                if ($member_target) {
                    $team_monthly_target += $member_target->monthly_target;
                    $team_policy_target += $member_target->target_policy_count;
                }
            }
            
            // Bu ayki ekip performansı
            $this_month_team_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                 AND start_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$this_month_start, $this_month_end])
            ));
            
            $this_month_team_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                 AND start_date BETWEEN %s AND %s
                 AND cancellation_date IS NULL",
                ...array_merge($team_ids, [$this_month_start, $this_month_end])
            ));
            
            // Hedeflere uzaklık hesaplama
            $team_premium_achievement = $team_monthly_target > 0 ? ($this_month_team_premium / $team_monthly_target) * 100 : 0;
            $team_policy_achievement = $team_policy_target > 0 ? ($this_month_team_policies / $team_policy_target) * 100 : 0;
            
            $all_teams[] = [
                'id' => $team_id,
                'name' => $team['name'],
                'leader_id' => $team['leader_id'],
                'leader_name' => $leader_data->display_name,
                'leader_title' => $leader_data->title,
                'member_count' => $member_count,
                'total_premium' => $team_premium,
                'total_policies' => $team_policy_count,
                'monthly_target' => $team_monthly_target,
                'policy_target' => $team_policy_target,
                'this_month_premium' => $this_month_team_premium,
                'this_month_policies' => $this_month_team_policies,
                'premium_achievement' => $team_premium_achievement,
                'policy_achievement' => $team_policy_achievement
            ];
        }
    }
    
    // Ekipleri toplam prim miktarına göre sırala (en yüksekten en düşüğe)
    usort($all_teams, function($a, $b) {
        return $b['total_premium'] <=> $a['total_premium'];
    });
}

$search_results = array();
if ($current_view == 'search' && isset($_GET['keyword']) && !empty(trim($_GET['keyword']))) {
    $keyword = sanitize_text_field($_GET['keyword']);
    $search_query = "
        SELECT c.*, p.policy_number, CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name,
               r.user_id as rep_user_id, u.display_name as representative_name
        FROM {$wpdb->prefix}insurance_crm_customers c
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON c.id = p.customer_id
        LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE c.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
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
        ...array_merge($rep_ids, [
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%'
        ])
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

// Görev atama yetkisi ek kontrol
function can_access_task_creation($user_id) {
    return is_patron($user_id) || is_manager($user_id) || is_team_leader($user_id);
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
                <?php if ($user_role == 'patron'): ?>
                    <span class="user-role patron-role">Patron</span>
                <?php elseif ($user_role == 'manager'): ?>
                    <span class="user-role manager-role">Müdür</span>
                <?php elseif ($user_role == 'team_leader'): ?>
                    <span class="user-role leader-role">Ekip Lideri</span>
                <?php endif; ?>
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
            
            <?php if (is_patron($current_user->ID)): ?>
            <!-- Patron İçin Özel Menü -->
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('organization'); ?>" class="<?php echo $current_view == 'organization' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-networking"></i>
                    <span>Organizasyon Yönetimi</span>
                </a>
                <div class="submenu-items">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="<?php echo $current_view == 'all_teams' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Ekipler</span>
                    </a>
                    <a href="<?php echo generate_panel_url('all_representatives'); ?>" class="<?php echo $current_view == 'all_representatives' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-businessperson"></i>
                        <span>Tüm Temsilciler</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives'); ?>" class="<?php echo $current_view == 'admin_panel' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Yönetim Paneli</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_manager($current_user->ID)): ?>
            <!-- Müdür İçin Özel Menü -->
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('manager_dashboard'); ?>" class="<?php echo $current_view == 'manager_dashboard' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-businessman"></i>
                    <span>Müdür Paneli</span>
                </a>
                <div class="submenu-items">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="<?php echo $current_view == 'all_teams' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Ekipler</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_leaders'); ?>" class="<?php echo $current_view == 'team_leaders' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-businessperson"></i>
                        <span>Ekip Liderleri</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_team_leader($current_user->ID)): ?>
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('team'); ?>" class="<?php echo $current_view == 'team' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Ekip Performansı</span>
                </a>
                <div class="submenu-items">
                    <a href="<?php echo generate_panel_url('team_policies'); ?>" class="<?php echo $current_view == 'team_policies' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-portfolio"></i>
                        <span>Ekip Poliçeleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_customers'); ?>" class="<?php echo $current_view == 'team_customers' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Ekip Müşterileri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_tasks'); ?>" class="<?php echo $current_view == 'team_tasks' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-calendar-alt"></i>
                        <span>Ekip Görevleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_reports'); ?>" class="<?php echo $current_view == 'team_reports' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-chart-area"></i>
                        <span>Ekip Raporları</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
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
                        case 'team':
                            echo 'Ekip Performansı';
                            break;
                        case 'team_policies':
                            echo 'Ekip Poliçeleri';
                            break;
                        case 'team_customers':
                            echo 'Ekip Müşterileri';
                            break;
                        case 'team_tasks':
                            echo 'Ekip Görevleri';
                            break;
                        case 'team_reports':
                            echo 'Ekip Raporları';
                            break;
                        case 'organization':
                            echo 'Organizasyon Yönetimi';
                            break;
                        case 'all_teams':
                            echo 'Tüm Ekipler';
                            break;
                        case 'all_representatives':
                            echo 'Tüm Temsilciler';
                            break;
                        case 'manager_dashboard':
                            echo 'Müdür Paneli';
                            break;
                        case 'team_leaders':
                            echo 'Ekip Liderleri';
                            break;
                        default:
                            echo ($user_role == 'patron') ? 'Patron Dashboard' : 
                                (($user_role == 'manager') ? 'Müdür Dashboard' : 
                                 (($user_role == 'team_leader') ? 'Ekip Lideri Dashboard' : 'Dashboard'));
                    }
                    ?>
                </h2>
                
                <?php if ($user_role != 'representative' && ($current_view == 'dashboard' || strpos($current_view, 'team_') === 0)): ?>
                <div class="view-filter">
                    <form id="view-filter-form">
                        <select name="view_filter" id="view-filter-select">
                            <option value="own" <?php selected($view_filter, 'own'); ?>>Kendi Verilerim</option>
                            
                            <?php if ($user_role == 'patron' || $user_role == 'manager'): ?>
                                <option value="all" <?php selected($view_filter, 'all'); ?>>Tüm Veriler</option>
                                
                                <?php foreach ($all_teams as $team): ?>
                                <option value="team_<?php echo $team['id']; ?>" <?php selected($view_filter, "team_{$team['id']}"); ?>>
                                    <?php echo $team['name']; ?> Ekibi
                                </option>
                                <?php endforeach; ?>
                            <?php elseif ($user_role == 'team_leader'): ?>
                                <option value="all" <?php selected($view_filter, 'all'); ?>>Tüm Ekip</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="filter-button">Filtrele</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="header-right">
                <div class="search-box">
                    <form action="<?php echo generate_panel_url('search'); ?>" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Ad, TC No, Çocuk Tc No.." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                        <input type="hidden" name="view_filter" value="<?php echo esc_attr($view_filter); ?>">
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
                                                <?php if ($task->first_name || $task->last_name): ?>
                                                    (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                                <?php endif; ?>
                                                <?php if ($view_filter === 'all' && $task->rep_user_id != $current_user->ID): ?>
                                                    <span class="assigned-user">[<?php echo esc_html($task->assigned_to); ?>]</span>
                                                <?php endif; ?>
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
                        <?php if (can_access_task_creation($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="add-task">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Yeni Görev</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($current_view == 'dashboard' || $current_view == 'team'): ?>
        <div class="main-content">
            <!-- Veri filtreleme seçenekleri -->
            <div class="filter-controls">
                <?php if ($user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader'): ?>
                <div class="filter-info">
                    <?php if ($view_filter === 'own'): ?>
                        <div class="filter-badge"><i class="dashicons dashicons-admin-users"></i> Kendi Verileriniz</div>
                    <?php elseif ($view_filter === 'all'): ?>
                        <div class="filter-badge"><i class="dashicons dashicons-groups"></i> 
                            <?php echo $user_role == 'team_leader' ? 'Tüm Ekip Verileri' : 'Tüm Veriler'; ?>
                        </div>
                    <?php elseif (strpos($view_filter, 'team_') === 0): ?>
                        <?php 
                        $team_id = str_replace('team_', '', $view_filter);
                        $team_name = '';
                        
		if (!empty($all_teams) && (is_array($all_teams) || is_object($all_teams))) {
	    	foreach ($all_teams as $team) {
		
                            if ($team['id'] == $team_id) {
                                $team_name = $team['name'];
                                break;
                            }
                        }
                        ?>
                        <div class="filter-badge"><i class="dashicons dashicons-groups"></i> <?php echo esc_html($team_name . ' Ekibi Verileri'); ?></div>
                    <?php elseif (strpos($view_filter, 'rep_') === 0): ?>
                        <?php 
                        $rep_id = str_replace('rep_', '', $view_filter);
                        $rep_name = '';
                        foreach ($member_performance as $member) {
                            if ($member['id'] == $rep_id) {
                                $rep_name = $member['name'];
                                break;
                            }
                        }
                        ?>
                        <div class="filter-badge"><i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($rep_name . ' Verileri'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($user_role == 'patron'): ?>
            <!-- PATRON DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Organizasyon Genel Bakış</h3>
                <p class="dashboard-subtitle">
                    <?php echo $view_filter === 'own' ? 'Kendi performans metrikleriniz' : 
                          ($view_filter === 'all' ? 'Tüm organizasyon için performans metrikleri ve genel bakış' : 
                           'Seçili verilere göre performans metrikleri'); ?>
                </p>
            </div>
            
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
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($view_filter === 'all' || strpos($view_filter, 'team_') === 0): ?>
            <!-- PATRON - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Eğer belirli bir ekip seçilmişse sadece o ekibi göster
                            $displayed_teams = $all_teams;
                            if (strpos($view_filter, 'team_') === 0) {
                                $selected_team_id = str_replace('team_', '', $view_filter);
                                $displayed_teams = array_filter($all_teams, function($team) use ($selected_team_id) {
                                    return $team['id'] == $selected_team_id;
                                });
                            }
                            
                            foreach ($displayed_teams as $team): 
                            ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $team['this_month_policies']; ?> / <?php echo $team['policy_target']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'team_' . $team['id'])); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button button-primary">Yönetim Paneline Git</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- PATRON - Temsilci Performans Tablosu -->
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($member_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci Adı</th>
                                <th>Unvan</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo esc_html($member['title']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="table-action" title="Tam Profil">
                                            <i class="dashicons dashicons-admin-users"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($user_role == 'manager'): ?>
            <!-- MÜDÜR DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Ekipler Yönetimi</h3>
                <p class="dashboard-subtitle">
                    <?php echo $view_filter === 'own' ? 'Kendi performans metrikleriniz' : 
                          ($view_filter === 'all' ? 'Tüm ekipler ve temsilciler için performans metrikleri' : 
                           'Seçili ekip verileri'); ?>
                </p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Müşteri' : 'Tüm Ekipler Toplam Müşteri'; ?></div>
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Poliçe' : 'Tüm Ekipler Toplam Poliçe'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Üretim' : 'Tüm Ekipler Toplam Üretim'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Bu Ay Üretim' : 'Tüm Ekipler Bu Ay Üretim'; ?></div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($view_filter === 'all' || strpos($view_filter, 'team_') === 0): ?>
            <!-- MÜDÜR - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Eğer belirli bir ekip seçilmişse sadece o ekibi göster
                            $displayed_teams = $all_teams;
                            if (strpos($view_filter, 'team_') === 0) {
                                $selected_team_id = str_replace('team_', '', $view_filter);
                                $displayed_teams = array_filter($all_teams, function($team) use ($selected_team_id) {
                                    return $team['id'] == $selected_team_id;
                                });
                            }
                            
                            foreach ($displayed_teams as $team): 
                            ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $team['this_month_policies']; ?> / <?php echo $team['policy_target']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'team_' . $team['id'])); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- MÜDÜR - Temsilci Performans Tablosu -->
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($member_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci Adı</th>
                                <th>Unvan</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo esc_html($member['title']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="table-action" title="Tam Profil">
                                            <i class="dashicons dashicons-admin-users"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($current_view == 'team' && !is_team_leader($current_user->ID)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Yetkisiz Erişim</h4>
                <p>Ekip performansı sayfasını görüntülemek için ekip lideri olmalısınız.</p>
            </div>
            <?php elseif ($current_view == 'team' && empty($team_members)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Ekibinizde Üye Bulunmuyor</h4>
                <p>Ekibinize üye eklemek için yönetici ile iletişime geçin.</p>
            </div>
            <?php else: ?>
            <!-- NORMAL DASHBOARD VEYA EKİP LİDERİ DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>
                    <?php 
                    if ($user_role == 'team_leader') {
                        echo $view_filter === 'own' ? 'Performans Göstergeleri' : 'Ekip Performans Göstergeleri';
                    } else {
                        echo 'Performans Göstergeleri';
                    }
                    ?>
                </h3>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Müşteri' : 'Toplam Müşteri'; ?></div>
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Poliçe' : 'Toplam Poliçe'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Üretim' : 'Toplam Üretim'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Bu Ay Üretim' : 'Bu Ay Üretim'; ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (($current_view == 'team' || $view_filter === 'all') && !empty($member_performance) && $user_role == 'team_leader'): ?>
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Üye Performansı</h3>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Üye Adı</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card">
                        <div class="card-header">
                            <h3>
                                <?php 
                                if ($current_view == 'team' || $view_filter === 'all') {
                                    echo $user_role == 'team_leader' ? 'Ekip Aylık Üretim Performansı' : 'Aylık Üretim Performansı';
                                } else {
                                    echo 'Aylık Üretim Performansı';
                                }
                                ?>
                            </h3>
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
                                            <th>İade Edilen (₺)</th>
                                            <th>Poliçe Adedi</th>
                                            <th>Prim Gerç. Oranı (%)</th>
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
                                            $refunded_amount = isset($monthly_refunded_data[$month_year]) ? $monthly_refunded_data[$month_year] : 0;
                                            $policy_count = isset($monthly_policy_data[$month_year]) ? $monthly_policy_data[$month_year] : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($month_name); ?></td>
                                                <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                <td class="refund-info">₺<?php echo number_format($refunded_amount, 2, ',', '.'); ?></td>
                                                <td><?php echo number_format($policy_count); ?> Adet</td>
                                                <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    if (!$representative) {
                        echo '<div class="ab-notice ab-error">Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.</div>';
                    } else {
                    ?>
                        <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
                        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

                        <div class="dashboard-card calendar-card">
                            <div class="card-header">
                                <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Görev Takvimi' : 'Görev Takvimi'; ?></h3>
                                <div class="card-actions">
                                    <a href="?view=tasks" class="text-button">Tüm Görevler</a>
                                    <?php if (can_access_task_creation($current_user->ID)): ?>
                                    <a href="?view=tasks&action=new" class="card-option" title="Yeni Görev">
                                        <i class="dashicons dashicons-plus-alt"></i>
                                    </a>
                                    <?php endif; ?>
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
                                                    <strong><?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?>:</strong>
                                                    <?php echo esc_html($task->task_description); ?>
                                                    <?php if ($task->first_name || $task->last_name): ?>
                                                        (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                                    <?php endif; ?>
                                                    <?php if ($view_filter === 'all' && $task->rep_user_id != $current_user->ID): ?>
                                                        <span class="assigned-user">[<?php echo esc_html($task->assigned_to); ?>]</span>
                                                    <?php endif; ?>
                                                    <a href="?view=tasks&due_date=<?php echo date('Y-m-d', strtotime($task->due_date)); ?>" class="task-link">Göreve Git</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <div style="text-align: center; margin-top: 20px;">
                                        <p>Bu ay veya gelecek ay için görev bulunmamaktadır.</p>
                                        <?php if (can_access_task_creation($current_user->ID)): ?>
                                        <a href="?view=tasks&action=new" class="button button-primary">Yeni Görev Ekle</a>
                                        <?php endif; ?>
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
                            if (calendarEl) {
                                console.log('Takvim elementi bulundu:', calendarEl);
                                const calendar = new FullCalendar.Calendar(calendarEl, {
                                    initialView: 'dayGridMonth',
                                    headerToolbar: {
                                        left: 'prev,next',
                                        center: 'title',
                                        right: ''
                                    },
                                    events: [],
                                    dayCellContent: function(info) {
                                        const dateStr = info.date.toISOString().split('T')[0];
                                        let taskCount = 0;
                                        console.log('Takvim tarihi:', dateStr);
                                        <?php foreach ($calendar_tasks as $task): ?>
                                            console.log('Görev tarihi:', '<?php echo $task->task_date; ?>');
                                            if ('<?php echo $task->task_date; ?>' === dateStr) {
                                                taskCount = <?php echo $task->task_count; ?>;
                                            }
                                        <?php endforeach; ?>
                                        console.log('Görev sayısı:', taskCount);
                                        return {
                                            html: `
                                                <div class="fc-daygrid-day-frame">
                                                    <div class="fc-daygrid-day-top">
                                                        <a href="#" class="fc-daygrid-day-number" data-date="${dateStr}">${info.dayNumberText}</a>
                                                    </div>
                                                    <div class="fc-daygrid-day-events">
                                                        ${taskCount > 0 ? `<a href="?view=tasks&due_date=${dateStr}" class="fc-task-count">Görev: ${taskCount}</a>` : ''}
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
                            } else {
                                console.error('Takvim elementi (#calendar) bulunamadı.');
                            }
                        });
                        </script>
                    <?php } ?>
                </div>
                
                <div class="lower-section">
                    <div class="dashboard-card renewals-card">
                        <div class="card-header">
                            <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Yaklaşan Yenilemeler' : 'Yaklaşan Yenilemeler'; ?></h3>
                            <div class="card-actions">
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
                                                <?php if ($task->first_name || $task->last_name): ?>
                                                    (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                                <?php endif; ?>
                                                <?php if ($view_filter === 'all' && $task->rep_user_id != $current_user->ID): ?>
                                                    <span class="assigned-user">[<?php echo esc_html($task->assigned_to); ?>]</span>
                                                <?php endif; ?>
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
                        <?php if (can_access_task_creation($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="add-task">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Yeni Görev</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($current_view == 'dashboard' || $current_view == 'team'): ?>
        <div class="main-content">
            <!-- Veri filtreleme seçenekleri -->
            <div class="filter-controls">
                <?php if ($user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader'): ?>
                <div class="filter-info">
                    <?php if ($view_filter === 'own'): ?>
                        <div class="filter-badge"><i class="dashicons dashicons-admin-users"></i> Kendi Verileriniz</div>
                    <?php elseif ($view_filter === 'all'): ?>
                        <div class="filter-badge"><i class="dashicons dashicons-groups"></i> 
                            <?php echo $user_role == 'team_leader' ? 'Tüm Ekip Verileri' : 'Tüm Veriler'; ?>
                        </div>
                    <?php elseif (strpos($view_filter, 'team_') === 0): ?>
                        <?php 
                        $team_id = str_replace('team_', '', $view_filter);
                        $team_name = '';
                        foreach ($all_teams as $team) {
                            if ($team['id'] == $team_id) {
                                $team_name = $team['name'];
                                break;
                            }
                        }
                        ?>
                        <div class="filter-badge"><i class="dashicons dashicons-groups"></i> <?php echo esc_html($team_name . ' Ekibi Verileri'); ?></div>
                    <?php elseif (strpos($view_filter, 'rep_') === 0): ?>
                        <?php 
                        $rep_id = str_replace('rep_', '', $view_filter);
                        $rep_name = '';
                        foreach ($member_performance as $member) {
                            if ($member['id'] == $rep_id) {
                                $rep_name = $member['name'];
                                break;
                            }
                        }
                        ?>
                        <div class="filter-badge"><i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($rep_name . ' Verileri'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($user_role == 'patron'): ?>
            <!-- PATRON DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Organizasyon Genel Bakış</h3>
                <p class="dashboard-subtitle">
                    <?php echo $view_filter === 'own' ? 'Kendi performans metrikleriniz' : 
                          ($view_filter === 'all' ? 'Tüm organizasyon için performans metrikleri ve genel bakış' : 
                           'Seçili verilere göre performans metrikleri'); ?>
                </p>
            </div>
            
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
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($view_filter === 'all' || strpos($view_filter, 'team_') === 0): ?>
            <!-- PATRON - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Eğer belirli bir ekip seçilmişse sadece o ekibi göster
                            $displayed_teams = $all_teams;
                            if (strpos($view_filter, 'team_') === 0) {
                                $selected_team_id = str_replace('team_', '', $view_filter);
                                $displayed_teams = array_filter($all_teams, function($team) use ($selected_team_id) {
                                    return $team['id'] == $selected_team_id;
                                });
                            }
                            
                            foreach ($displayed_teams as $team): 
                            ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $team['this_month_policies']; ?> / <?php echo $team['policy_target']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'team_' . $team['id'])); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button button-primary">Yönetim Paneline Git</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- PATRON - Temsilci Performans Tablosu -->
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($member_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci Adı</th>
                                <th>Unvan</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo esc_html($member['title']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="table-action" title="Tam Profil">
                                            <i class="dashicons dashicons-admin-users"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($user_role == 'manager'): ?>
            <!-- MÜDÜR DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Ekipler Yönetimi</h3>
                <p class="dashboard-subtitle">
                    <?php echo $view_filter === 'own' ? 'Kendi performans metrikleriniz' : 
                          ($view_filter === 'all' ? 'Tüm ekipler ve temsilciler için performans metrikleri' : 
                           'Seçili ekip verileri'); ?>
                </p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Müşteri' : 'Tüm Ekipler Toplam Müşteri'; ?></div>
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Poliçe' : 'Tüm Ekipler Toplam Poliçe'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Toplam Üretim' : 'Tüm Ekipler Toplam Üretim'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="stat-label"><?php echo $view_filter === 'own' ? 'Bu Ay Üretim' : 'Tüm Ekipler Bu Ay Üretim'; ?></div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($view_filter === 'all' || strpos($view_filter, 'team_') === 0): ?>
            <!-- MÜDÜR - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Eğer belirli bir ekip seçilmişse sadece o ekibi göster
                            $displayed_teams = $all_teams;
                            if (strpos($view_filter, 'team_') === 0) {
                                $selected_team_id = str_replace('team_', '', $view_filter);
                                $displayed_teams = array_filter($all_teams, function($team) use ($selected_team_id) {
                                    return $team['id'] == $selected_team_id;
                                });
                            }
                            
                            foreach ($displayed_teams as $team): 
                            ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $team['this_month_policies']; ?> / <?php echo $team['policy_target']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'team_' . $team['id'])); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- MÜDÜR - Temsilci Performans Tablosu -->
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($member_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci Adı</th>
                                <th>Unvan</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo esc_html($member['title']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="table-action" title="Tam Profil">
                                            <i class="dashicons dashicons-admin-users"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($current_view == 'team' && !is_team_leader($current_user->ID)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Yetkisiz Erişim</h4>
                <p>Ekip performansı sayfasını görüntülemek için ekip lideri olmalısınız.</p>
            </div>
            <?php elseif ($current_view == 'team' && empty($team_members)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Ekibinizde Üye Bulunmuyor</h4>
                <p>Ekibinize üye eklemek için yönetici ile iletişime geçin.</p>
            </div>
            <?php else: ?>
            <!-- NORMAL DASHBOARD VEYA EKİP LİDERİ DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>
                    <?php 
                    if ($user_role == 'team_leader') {
                        echo $view_filter === 'own' ? 'Performans Göstergeleri' : 'Ekip Performans Göstergeleri';
                    } else {
                        echo 'Performans Göstergeleri';
                    }
                    ?>
                </h3>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Müşteri' : 'Toplam Müşteri'; ?></div>
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Poliçe' : 'Toplam Poliçe'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Toplam Üretim' : 'Toplam Üretim'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
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
                        <div class="stat-label"><?php echo $current_view == 'team' || $view_filter === 'all' ? 'Ekip Bu Ay Üretim' : 'Bu Ay Üretim'; ?></div>
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
            
            <!-- Poliçe Hedef Durumu -->
            <div class="dashboard-card policy-target-card">
                <div class="card-header">
                    <h3>Poliçe Hedef Durumu</h3>
                </div>
                <div class="card-body">
                    <div class="target-stats-row">
                        <div class="target-stat-box">
                            <div class="target-stat-label">Aylık Poliçe Hedefi</div>
                            <div class="target-stat-value"><?php echo number_format($policy_target); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Bu Ay Yazılan Poliçeler</div>
                            <div class="target-stat-value"><?php echo number_format($current_month_policy_count); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Kalan</div>
                            <div class="target-stat-value"><?php echo number_format(max(0, $policy_target - $current_month_policy_count)); ?> Adet</div>
                        </div>
                        <div class="target-stat-box">
                            <div class="target-stat-label">Gerçekleşme Oranı</div>
                            <div class="target-stat-value"><?php echo number_format($policy_achievement_rate, 1); ?>%</div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (($current_view == 'team' || $view_filter === 'all') && !empty($member_performance) && $user_role == 'team_leader'): ?>
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Üye Performansı</h3>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Üye Adı</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Prim Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>Poliçe Hedefi</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                </td>
                                <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                            <i class="dashicons dashicons-visibility"></i>
                                        </a>
                                        <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                            <i class="dashicons dashicons-paperclip"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card">
                        <div class="card-header">
                            <h3>
                                <?php 
                                if ($current_view == 'team' || $view_filter === 'all') {
                                    echo $user_role == 'team_leader' ? 'Ekip Aylık Üretim Performansı' : 'Aylık Üretim Performansı';
                                } else {
                                    echo 'Aylık Üretim Performansı';
                                }
                                ?>
                            </h3>
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
                                            <th>İade Edilen (₺)</th>
                                            <th>Poliçe Adedi</th>
                                            <th>Prim Gerç. Oranı (%)</th>
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
                                            $refunded_amount = isset($monthly_refunded_data[$month_year]) ? $monthly_refunded_data[$month_year] : 0;
                                            $policy_count = isset($monthly_policy_data[$month_year]) ? $monthly_policy_data[$month_year] : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($month_name); ?></td>
                                                <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                <td class="refund-info">₺<?php echo number_format($refunded_amount, 2, ',', '.'); ?></td>
                                                <td><?php echo number_format($policy_count); ?> Adet</td>
                                                <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    if (!$representative) {
                        echo '<div class="ab-notice ab-error">Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.</div>';
                    } else {
                    ?>
                        <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
                        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

                        <div class="dashboard-card calendar-card">
                            <div class="card-header">
                                <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Görev Takvimi' : 'Görev Takvimi'; ?></h3>
                                <div class="card-actions">
                                    <a href="?view=tasks" class="text-button">Tüm Görevler</a>
                                    <?php if (can_access_task_creation($current_user->ID)): ?>
                                    <a href="?view=tasks&action=new" class="card-option" title="Yeni Görev">
                                        <i class="dashicons dashicons-plus-alt"></i>
                                    </a>
                                    <?php endif; ?>
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
                                                    <strong><?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?>:</strong>
                                                    <?php echo esc_html($task->task_description); ?>
                                                    <?php if ($task->first_name || $task->last_name): ?>
                                                        (<?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>)
                                                    <?php endif; ?>
                                                    <?php if ($view_filter === 'all' && $task->rep_user_id != $current_user->ID): ?>
                                                        <span class="assigned-user">[<?php echo esc_html($task->assigned_to); ?>]</span>
                                                    <?php endif; ?>
                                                    <a href="?view=tasks&due_date=<?php echo date('Y-m-d', strtotime($task->due_date)); ?>" class="task-link">Göreve Git</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <div style="text-align: center; margin-top: 20px;">
                                        <p>Bu ay veya gelecek ay için görev bulunmamaktadır.</p>
                                        <?php if (can_access_task_creation($current_user->ID)): ?>
                                        <a href="?view=tasks&action=new" class="button button-primary">Yeni Görev Ekle</a>
                                        <?php endif; ?>
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
                            if (calendarEl) {
                                console.log('Takvim elementi bulundu:', calendarEl);
                                const calendar = new FullCalendar.Calendar(calendarEl, {
                                    initialView: 'dayGridMonth',
                                    headerToolbar: {
                                        left: 'prev,next',
                                        center: 'title',
                                        right: ''
                                    },
                                    events: [],
                                    dayCellContent: function(info) {
                                        const dateStr = info.date.toISOString().split('T')[0];
                                        let taskCount = 0;
                                        console.log('Takvim tarihi:', dateStr);
                                        <?php foreach ($calendar_tasks as $task): ?>
                                            console.log('Görev tarihi:', '<?php echo $task->task_date; ?>');
                                            if ('<?php echo $task->task_date; ?>' === dateStr) {
                                                taskCount = <?php echo $task->task_count; ?>;
                                            }
                                        <?php endforeach; ?>
                                        console.log('Görev sayısı:', taskCount);
                                        return {
                                            html: `
                                                <div class="fc-daygrid-day-frame">
                                                    <div class="fc-daygrid-day-top">
                                                        <a href="#" class="fc-daygrid-day-number" data-date="${dateStr}">${info.dayNumberText}</a>
                                                    </div>
                                                    <div class="fc-daygrid-day-events">
                                                        ${taskCount > 0 ? `<a href="?view=tasks&due_date=${dateStr}" class="fc-task-count">Görev: ${taskCount}</a>` : ''}
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
                            } else {
                                console.error('Takvim elementi (#calendar) bulunamadı.');
                            }
                        });
                        </script>
                    <?php } ?>
                </div>
                
                <div class="lower-section">
                    <div class="dashboard-card renewals-card">
                        <div class="card-header">
                            <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Yaklaşan Yenilemeler' : 'Yaklaşan Yenilemeler'; ?></h3>
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
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
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
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
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
                            <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Süresi Geçmiş Poliçeler' : 'Süresi Geçmiş Poliçeler'; ?></h3>
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
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th class="hide-mobile">Gecikme</th>
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
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td class="days-overdue hide-mobile">
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
                            <h3><?php echo ($current_view == 'team' || $view_filter === 'all') ? 'Ekip Son Eklenen Poliçeler' : 'Son Eklenen Poliçeler'; ?></h3>
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
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
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
                                                        <?php if ($view_filter === 'all' && $policy->rep_user_id != $current_user->ID): ?>
                                                            <span class="policy-owner">[<?php echo esc_html($policy->representative_name); ?>]</span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
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
                                                            <?php if (has_delete_permission($current_user->ID)): ?>
                                                            <a href="<?php echo generate_panel_url('policies', 'cancel', $policy->id); ?>" class="text-danger">İptal Et</a>
                                                            <?php endif; ?>
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
            <?php endif; ?>
        </div>
        <?php elseif ($current_view == 'search'): ?>
            <div class="main-content">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Arama Sonuçları</h3>
                        <div class="card-actions">
                            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="text-button">Dashboard'a Dön</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="filter-controls">
                            <?php if ($user_role == 'patron' || $user_role == 'manager' || $user_role == 'team_leader'): ?>
                            <div class="filter-info">
                                <?php if ($view_filter === 'own'): ?>
                                    <div class="filter-badge"><i class="dashicons dashicons-admin-users"></i> Kendi Verileriniz</div>
                                <?php elseif ($view_filter === 'all'): ?>
                                    <div class="filter-badge"><i class="dashicons dashicons-groups"></i> 
                                        <?php echo $user_role == 'team_leader' ? 'Tüm Ekip Verileri' : 'Tüm Veriler'; ?>
                                    </div>
                                <?php elseif (strpos($view_filter, 'team_') === 0): ?>
                                    <?php 
                                    $team_id = str_replace('team_', '', $view_filter);
                                    $team_name = '';
                                    foreach ($all_teams as $team) {
                                        if ($team['id'] == $team_id) {
                                            $team_name = $team['name'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="filter-badge"><i class="dashicons dashicons-groups"></i> <?php echo esc_html($team_name . ' Ekibi Verileri'); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($search_results)): ?>
                            <table class="data-table search-results-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Poliçe No', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Temsilci', 'insurance-crm'); ?></th>
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
                                            <td><?php echo esc_html($customer->representative_name ?: '-'); ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="<?php esc_attr_e('Görüntüle', 'insurance-crm'); ?>">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="?view=customers&action=edit&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="<?php esc_attr_e('Düzenle', 'insurance-crm'); ?>">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <?php if (has_delete_permission($current_user->ID)): ?>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="<?php esc_attr_e('Daha Fazla', 'insurance-crm'); ?>">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <a href="?view=customers&action=delete&id=<?php echo esc_attr($customer->id); ?>"><?php esc_html_e('Sil', 'insurance-crm'); ?></a>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="dashicons dashicons-search"></i></div>
                                <h4><?php esc_html_e('Sonuç Bulunamadı', 'insurance-crm'); ?></h4>
                                <p><?php esc_html_e('Aradığınız kritere uygun bir sonuç bulunamadı.', 'insurance-crm'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($current_view == 'all_teams' || $current_view == 'all_representatives' || $current_view == 'organization' || $current_view == 'manager_dashboard' || $current_view == 'team_leaders'): ?>
            <!-- YENİ YÖNETİM SEKME İÇERİKLERİ -->
            <div class="main-content">
                <?php if ($current_view == 'organization'): ?>
                <div class="dashboard-card hierarchy-card">
                    <div class="card-header">
                        <h3>Organizasyon Yapısı</h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        // Organizasyon şeması için veri hazırlama
                        $settings = get_option('insurance_crm_settings', []);
                        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
                        
                        $patron_data = null;
                        $manager_data = null;
                        
                        if (!empty($management_hierarchy['patron_id'])) {
                            $patron_data = $wpdb->get_row($wpdb->prepare(
                                "SELECT r.*, u.display_name 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r 
                                 JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.id = %d",
                                $management_hierarchy['patron_id']
                            ));
                        }
                        
                        if (!empty($management_hierarchy['manager_id'])) {
                            $manager_data = $wpdb->get_row($wpdb->prepare(
                                "SELECT r.*, u.display_name 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r 
                                 JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.id = %d",
                                $management_hierarchy['manager_id']
                            ));
                        }
                        ?>
                        
                        <div class="org-chart">
                            <div class="org-level patron-level">
                                <div class="org-box patron-box">
                                    <div class="org-title">Patron</div>
                                    <div class="org-name">
                                        <?php echo $patron_data ? esc_html($patron_data->display_name) : '(Tanımlanmadı)'; ?>
                                    </div>
                                    <?php if ($patron_data): ?>
                                    <div class="org-subtitle"><?php echo esc_html($patron_data->title); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="org-connector"></div>
                            
                            <div class="org-level manager-level">
                                <div class="org-box manager-box">
                                    <div class="org-title">Müdür</div>
                                    <div class="org-name">
                                        <?php echo $manager_data ? esc_html($manager_data->display_name) : '(Tanımlanmadı)'; ?>
                                    </div>
                                    <?php if ($manager_data): ?>
                                    <div class="org-subtitle"><?php echo esc_html($manager_data->title); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="org-connector"></div>
                            
                            <div class="org-level team-leaders-level">
                                <?php if (!empty($all_teams)): ?>
                                    <?php foreach ($all_teams as $team): ?>
                                    <div class="org-box team-leader-box">
                                        <div class="org-title">Ekip Lideri</div>
                                        <div class="org-name"><?php echo esc_html($team['leader_name']); ?></div>
                                        <div class="org-subtitle"><?php echo esc_html($team['leader_title']); ?></div>
                                        <div class="org-team-name"><?php echo esc_html($team['name']); ?> Ekibi</div>
                                        <div class="org-team-count"><?php echo $team['member_count']; ?> Üye</div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div class="org-box empty-box">
                                    <div class="org-title">Henüz Ekip Tanımlanmamış</div>
                                    <p>Ekip yapılandırması için yönetim paneline gidin.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="org-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" class="button button-primary">
                                Yönetim Hiyerarşisini Düzenle
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button">
                                Ekipleri Düzenle
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'all_teams'): ?>
                <div class="dashboard-card teams-list-card">
                    <div class="card-header">
                        <h3>Tüm Ekipler</h3>
                        <?php if ($user_role == 'patron'): ?>
                        <div class="card-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=new_team'); ?>" class="button button-primary">
                                Yeni Ekip Oluştur
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_teams)): ?>
                        <table class="data-table teams-table">
                            <thead>
                                <tr>
                                    <th>Ekip Adı</th>
                                    <th>Ekip Lideri</th>
                                    <th>Üye Sayısı</th>
                                    <th>Toplam Prim (₺)</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Gerçekleşme Oranı</th>
                                    <th>Poliçe Hedefi</th>
                                    <th>Gerçekleşme</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_teams as $team): ?>
                                <tr>
                                    <td><?php echo esc_html($team['name']); ?></td>
                                    <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                    <td><?php echo $team['member_count']; ?> üye</td>
                                    <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($team['premium_achievement'], 1); ?>%</div>
                                    </td>
                                    <td><?php echo $team['this_month_policies']; ?> / <?php echo $team['policy_target']; ?> Adet</td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $team['policy_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($team['policy_achievement'], 1); ?>%</div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'team_' . $team['id'])); ?>" class="table-action" title="Görüntüle">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <?php if ($user_role == 'patron'): ?>
                                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=edit_team&team_id=' . $team['id']); ?>" class="table-action" title="Düzenle">
                                                <i class="dashicons dashicons-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="dashicons dashicons-groups"></i>
                            </div>
                            <h4>Henüz ekip tanımlanmamış</h4>
                            <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button button-primary">Yönetim Paneline Git</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'all_representatives'): ?>
                <div class="dashboard-card all-reps-card">
                    <div class="card-header">
                        <h3>Tüm Temsilciler</h3>
                        <?php if ($user_role == 'patron'): ?>
                        <div class="card-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=new'); ?>" class="button button-primary">
                                Yeni Temsilci Ekle
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($member_performance)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Temsilci Adı</th>
                                    <th>Unvan</th>
                                    <th>Rol</th>
                                    <th>Müşteri Sayısı</th>
                                    <th>Poliçe Sayısı</th>
                                    <th>Toplam Prim (₺)</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Gerçekleşme</th>
                                    <th>Poliçe Hedefi</th>
                                    <th>Gerçekleşme</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Patron ve Müdür bilgilerini al
                                $settings = get_option('insurance_crm_settings', []);
                                $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
                                $patron_id = !empty($management_hierarchy['patron_id']) ? $management_hierarchy['patron_id'] : 0;
                                $manager_id = !empty($management_hierarchy['manager_id']) ? $management_hierarchy['manager_id'] : 0;
                                
                                // Ekip liderleri listesi
                                $team_leader_ids = array();
                                foreach ($all_teams as $team) {
                                    $team_leader_ids[] = $team['leader_id'];
                                }
                                
                                foreach ($member_performance as $member): 
                                    // Rol belirleme
                                    $role = '';
                                    $role_class = '';
                                    
                                    if ($member['id'] == $patron_id) {
                                        $role = 'Patron';
                                        $role_class = 'patron-role';
                                    } elseif ($member['id'] == $manager_id) {
                                        $role = 'Müdür';
                                        $role_class = 'manager-role';
                                    } elseif (in_array($member['id'], $team_leader_ids)) {
                                        $role = 'Ekip Lideri';
                                        $role_class = 'leader-role';
                                    } else {
                                        $role = 'Temsilci';
                                        $role_class = 'rep-role';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($member['name']); ?></td>
                                    <td><?php echo esc_html($member['title']); ?></td>
                                    <td><span class="role-badge <?php echo $role_class; ?>"><?php echo $role; ?></span></td>
                                    <td><?php echo number_format($member['customers']); ?></td>
                                    <td><?php echo number_format($member['policies']); ?></td>
                                    <td>₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                    <td>₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($member['premium_achievement'], 1); ?>%</div>
                                    </td>
                                    <td><?php echo $member['this_month_policies']; ?> / <?php echo $member['target_policy_count']; ?> Adet</td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $member['policy_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($member['policy_achievement'], 1); ?>%</div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $member['id'])); ?>" class="table-action" title="Detaylar">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <?php if ($user_role == 'patron' || $user_role == 'manager'): ?>
                                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=edit&id=' . $member['id']); ?>" class="table-action" title="Düzenle">
                                                <i class="dashicons dashicons-edit"></i>
                                            </a>
                                            <?php if (can_assign_task($current_user->ID, $member['id']) && $current_user->ID != $member['id']): ?>
                                            <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $member['id']]); ?>" class="table-action" title="Görev Ata">
                                                <i class="dashicons dashicons-paperclip"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'manager_dashboard' || $current_view == 'team_leaders'): ?>
                <!-- Müdür için ekip liderlerini gösterme -->
                <div class="dashboard-card team-leaders-card">
                    <div class="card-header">
                        <h3>Ekip Liderleri</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        // Ekip liderleri listesi ve performansları
                        $team_leaders = array();
                        foreach ($all_teams as $team) {
                            foreach ($member_performance as $member) {
                                if ($member['id'] == $team['leader_id']) {
                                    $team_leaders[] = array(
                                        'id' => $member['id'],
                                        'name' => $member['name'],
                                        'title' => $member['title'],
                                        'team_name' => $team['name'],
                                        'team_size' => $team['member_count'],
                                        'customers' => $member['customers'],
                                        'policies' => $member['policies'],
                                        'premium' => $member['premium'],
                                        'monthly_target' => $member['monthly_target'],
                                        'target_policy_count' => $member['target_policy_count'],
                                        'this_month_premium' => $member['this_month_premium'],
                                        'this_month_policies' => $member['this_month_policies'],
                                        'premium_achievement' => $member['premium_achievement'],
                                        'policy_achievement' => $member['policy_achievement']
                                    );
                                    break;
                                }
                            }
                        }
                        
                        if (!empty($team_leaders)):
                        ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ekip Lideri</th>
                                    <th>Unvan</th>
                                    <th>Ekip Adı</th>
                                    <th>Ekip Boyutu</th>
                                    <th>Müşteri Sayısı</th>
                                    <th>Poliçe Sayısı</th>
                                    <th>Toplam Prim (₺)</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Gerçekleşme</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($team_leaders as $leader): ?>
                                <tr>
                                    <td><?php echo esc_html($leader['name']); ?></td>
                                    <td><?php echo esc_html($leader['title']); ?></td>
                                    <td><?php echo esc_html($leader['team_name']); ?></td>
                                    <td><?php echo $leader['team_size']; ?> üye</td>
                                    <td><?php echo number_format($leader['customers']); ?></td>
                                    <td><?php echo number_format($leader['policies']); ?></td>
                                    <td>₺<?php echo number_format($leader['premium'], 2, ',', '.'); ?></td>
                                    <td>₺<?php echo number_format($leader['monthly_target'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $leader['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($leader['premium_achievement'], 1); ?>%</div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo generate_panel_url('dashboard', '', '', array('view_filter' => 'rep_' . $leader['id'])); ?>" class="table-action" title="Detaylar">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <?php if (can_assign_task($current_user->ID, $leader['id']) && $current_user->ID != $leader['id']): ?>
                                            <a href="<?php echo generate_panel_url('tasks', 'new', '', ['assign_to' => $leader['id']]); ?>" class="table-action" title="Görev Ata">
                                                <i class="dashicons dashicons-paperclip"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="dashicons dashicons-businessperson"></i>
                            </div>
                            <h4>Henüz ekip lideri tanımlanmamış</h4>
                            <p>Ekip yapılandırması için yönetim paneline gidin.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php elseif ($current_view == 'customers' || $current_view == 'team_customers'): ?>
            <?php include_once(dirname(__FILE__) . '/customers.php'); ?>
        <?php elseif ($current_view == 'policies' || $current_view == 'team_policies'): ?>
            <?php include_once(dirname(__FILE__) . '/policies.php'); ?>
        <?php elseif ($current_view == 'tasks' || $current_view == 'team_tasks'): ?>
            <?php include_once(dirname(__FILE__) . '/tasks.php'); ?>
        <?php elseif ($current_view == 'reports' || $current_view == 'team_reports'): ?>
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
            
            .user-role {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600;
                margin-top: 4px;
            }
            
            .patron-role {
                background: #4a148c;
                color: #fff;
            }
            
            .manager-role {
                background: #0d47a1;
                color: #fff;
            }
            
            .leader-role {
                background: #1b5e20;
                color: #fff;
            }
            
            .rep-role {
                background: #424242;
                color: #fff;
            }
            
            .role-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
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
            
            .sidenav-submenu {
                padding: 0;
            }
            
            .sidenav-submenu > a {
                font-weight: 600;
            }
            
            .submenu-items {
                padding-left: 20px;
                background: rgba(0,0,0,0.1);
            }
            
            .submenu-items a {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .submenu-items a .dashicons {
                margin-right: 10px;
                font-size: 16px;
                width: 16px;
                height: 16px;
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
            
            .view-filter {
                margin-left: 20px;
                display: flex;
            }
            
            .view-filter-select {
                padding: 5px 10px;
                border-radius: 4px;
                border: 1px solid #ddd;
                font-size: 14px;
            }
            
            .filter-button {
                padding: 5px 10px;
                background: #0073aa;
                color: white;
                border: none;
                border-radius: 4px;
                margin-left: 5px;
                cursor: pointer;
            }
            
            .filter-controls {
                margin-bottom: 20px;
                display: flex;
                align-items: center;
            }
            
            .filter-badge {
                display: inline-block;
                padding: 5px 12px;
                background: #f0f7ff;
                border: 1px solid #d0e1ff;
                border-radius: 20px;
                color: #0073aa;
                font-size: 14px;
                margin-right: 10px;
            }
            
            .filter-badge .dashicons {
                margin-right: 5px;
                font-size: 14px;
                width: 14px;
                height: 14px;
                vertical-align: middle;
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
            
            .assigned-user, .policy-owner {
                font-size: 12px;
                color: #666;
                font-style: italic;
                margin-left: 5px;
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
            
            /* Dashboard Header Styles */
            .dashboard-header {
                margin-bottom: 20px;
            }
            
            .dashboard-header h3 {
                font-size: 24px;
                font-weight: 600;
                color: #333;
                margin-bottom: 5px;
            }
            
            .dashboard-subtitle {
                font-size: 16px;
                color: #666;
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
            
            .refund-info {
                font-size: 12px;
                color: #dc3545;
                margin-top: 5px;
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
            
            .target-stats-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
            }
            
            .target-stat-box {
                background: #f9f9f9;
                border-radius: 6px;
                padding: 15px;
                text-align: center;
            }
            
            .target-stat-label {
                font-size: 14px;
                color: #666;
                margin-bottom: 8px;
            }
            
            .target-stat-value {
                font-size: 20px;
                font-weight: 600;
                color: #333;
            }
            
            .target-progress {
                height: 6px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-top: 10px;
            }
            
            .member-performance-card,
            .team-performance-card {
                margin-bottom: 20px;
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
            
            /* Organization Chart Styles */
            .org-chart {
                display: flex;
                flex-direction: column;
                align-items: center;
                margin: 20px 0;
            }
            
            .org-level {
                display: flex;
                justify-content: center;
                gap: 30px;
                width: 100%;
                margin-bottom: 10px;
            }
            
            .team-leaders-level {
                flex-wrap: wrap;
            }
            
            .org-box {
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                width: 200px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            
            .patron-box {
                background-color: #f0f7ff;
                border: 2px solid #4a89dc;
            }
            
            .manager-box {
                background-color: #fff5f0;
                border: 2px solid #e8864a;
            }
            
            .team-leader-box {
                background-color: #f0f8f0;
                border: 2px solid #5cb85c;
                margin-bottom: 15px;
            }
            
            .empty-box {
                background-color: #f9f9f9;
                border: 2px dashed #ccc;
            }
            
            .org-title {
                font-weight: bold;
                font-size: 16px;
                margin-bottom: 5px;
                color: #444;
            }
            
            .org-name {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            
            .org-subtitle {
                font-style: italic;
                color: #666;
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .org-team-name {
                margin-top: 10px;
                font-weight: bold;
                color: #5cb85c;
            }
            
            .org-team-count {
                font-size: 12px;
                color: #777;
            }
            
            .org-connector {
                width: 2px;
                height: 30px;
                background-color: #999;
                margin: 5px 0;
            }
            
            .org-actions {
                display: flex;
                justify-content: center;
                gap: 15px;
                margin-top: 20px;
            }
            
            /* Progress Bar Styles */
            .progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 3px;
            }
            
            .progress-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
            }
            
            .progress-text {
                font-size: 12px;
                color: #666;
                text-align: right;
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
            
            .view-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .view-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .days-overdue {
                font-weight: 500;
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
                
                .target-stats-row {
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
                
                .org-level.team-leaders-level {
                    flex-direction: column;
                    align-items: center;
                }
                
                .team-leader-box {
                    margin-bottom: 15px;
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
                
                /* Mobil görünüm için tablo düzenlemeleri */
                .renewals-table th.hide-mobile,
                .renewals-table td.hide-mobile,
                .expired-policies-table th.hide-mobile,
                .expired-policies-table td.hide-mobile,
                .policies-table th.hide-mobile,
                .policies-table td.hide-mobile {
                    display: none;
                }
                
                .renewals-table th,
                .renewals-table td,
                .expired-policies-table th,
                .expired-policies-table td,
                .policies-table th,
                .policies-table td {
                    padding: 8px;
                    font-size: 12px;
                }
                
                .renewals-table td,
                .expired-policies-table td,
                .policies-table td {
                    display: table-cell;
                    vertical-align: middle;
                }
                
                .action-button {
                    padding: 4px 8px;
                    font-size: 12px;
                }
                
                .action-button .dashicons {
                    font-size: 12px;
                    width: 12px;
                    height: 12px;
                    margin-right: 4px;
                }
                
                .table-actions {
                    gap: 6px;
                }
                
                .table-action {
                    width: 24px;
                    height: 24px;
                }
                
                .sidenav-submenu .submenu-items {
                    padding-left: 10px;
                }
                
                .submenu-items a {
                    font-size: 13px;
                    padding: 8px 15px;
                }
                
                .org-box {
                    width: 180px;
                    padding: 15px;
                }
                
                .target-stats-row {
                    grid-template-columns: 1fr;
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
                    padding: 20px 15px;
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
                
                .org-box {
                    width: 150px;
                    padding: 10px;
                }
                
                .org-name {
                    font-size: 16px;
                }
                
                .org-title {
                    font-size: 14px;
                }
                
                .view-filter {
                    margin-left: 0;
                    margin-top: 10px;
                    width: 100%;
                }
                
                .view-filter form {
                    width: 100%;
                    display: flex;
                }
                
                .view-filter-select {
                    flex-grow: 1;
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
            
            // View Filter Form Submission
            const viewFilterForm = document.getElementById('view-filter-form');
            if (viewFilterForm) {
                viewFilterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const viewFilter = document.getElementById('view-filter-select').value;
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('view_filter', viewFilter);
                    window.location.href = currentUrl.toString();
                });
            }
            
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
                const monthlyProduction = <?php echo json_encode($monthly_production); ?>;
                
                const labels = monthlyProduction.map(item => {
                    const [year, month] = item.month.split('-');
                    const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 
                                  'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                    return months[parseInt(month) - 1] + ' ' + year;
                });
                
                const premiumData = monthlyProduction.map(item => item.total);
                const policyCountData = monthlyProduction.map(item => item.policy_count);
                
                new Chart(productionChartCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Aylık Üretim (₺)',
                                data: premiumData,
                                backgroundColor: 'rgba(0,115,170,0.6)',
                                borderColor: 'rgba(0,115,170,1)',
                                borderWidth: 1,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Poliçe Adedi',
                                data: policyCountData,
                                backgroundColor: 'rgba(255,160,0,0.6)',
                                borderColor: 'rgba(255,160,0,1)',
                                borderWidth: 1,
                                type: 'line',
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Üretim (₺)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return '₺' + value.toLocaleString('tr-TR');
                                    }
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Poliçe Adedi'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (context.datasetIndex === 0) {
                                            return 'Üretim: ₺' + context.parsed.y.toLocaleString('tr-TR');
                                        } else {
                                            return 'Poliçe: ' + context.parsed.y + ' Adet';
                                        }
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
        
  <?php endif; ?>
        <?php endif; ?>
    <?php wp_footer(); ?>
    </body>
</html>