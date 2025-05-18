<?php
/**
 * Boss Panel - Tüm Poliçeler Ekranı
 * @version 2.2.3
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

// Kullanıcı yönetici değilse yönlendir
$user = wp_get_current_user();
if (!in_array('administrator', (array)$user->roles)) {
    if (in_array('insurance_representative', (array)$user->roles)) {
        wp_safe_redirect(home_url('/temsilci-paneli/'));
    } else {
        wp_safe_redirect(home_url());
    }
    exit;
}

// Veritabanında insured_party sütununun varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . esc_html($wpdb->last_error) . '</div>';
        return;
    }
}

$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $policy_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_policy_' . $policy_id)) {
        $wpdb->delete($policies_table, array('id' => $policy_id));
        $notice = '<div class="ab-notice ab-success">Poliçe başarıyla silindi.</div>';
    }
}

$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$customer_filter = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$policy_type_filter = isset($_GET['policy_type']) ? sanitize_text_field($_GET['policy_type']) : '';
$insurance_company_filter = isset($_GET['insurance_company']) ? sanitize_text_field($_GET['insurance_company']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

$base_query = "FROM $policies_table p 
               LEFT JOIN $customers_table c ON p.customer_id = c.id
               LEFT JOIN $representatives_table r ON p.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

if (!empty($search)) {
    $base_query .= $wpdb->prepare(
        " AND (p.policy_number LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR p.insurance_company LIKE %s OR p.insured_party LIKE %s OR u.display_name LIKE %s)",
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}

if ($customer_filter > 0) {
    $base_query .= $wpdb->prepare(" AND p.customer_id = %d", $customer_filter);
}

if (!empty($policy_type_filter)) {
    $base_query .= $wpdb->prepare(" AND p.policy_type = %s", $policy_type_filter);
}

if (!empty($insurance_company_filter)) {
    $base_query .= $wpdb->prepare(" AND p.insurance_company = %s", $insurance_company_filter);
}

if (!empty($status_filter)) {
    $base_query .= $wpdb->prepare(" AND p.status = %s", $status_filter);
}

$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT p.id) " . $base_query);

$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'p.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

$policies = $wpdb->get_results("
    SELECT p.*, 
           c.first_name, c.last_name,
           u.display_name as representative_name,
           r.id as rep_id
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

$settings = get_option('insurance_crm_settings');
$insurance_companies = isset($settings['insurance_companies']) ? $settings['insurance_companies'] : array();
$policy_types = isset($settings['default_policy_types']) ? $settings['default_policy_types'] : array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer');

$customers = $wpdb->get_results("SELECT id, first_name, last_name FROM $customers_table ORDER BY first_name, last_name");

$total_pages = ceil($total_items / $per_page);

$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new' && $current_action !== 'renew');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container" id="policies-list-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    <?php echo $notice; ?>

    <div class="ab-crm-header">
        <h1><i class="fas fa-file-contract"></i> Tüm Poliçeler</h1>
    </div>
    
    <div class="ab-crm-filters">
        <form method="get" id="policies-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="policies">
            
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php selected($customer_filter, $c->id); ?>>
                                <?php echo esc_html($c->first_name . ' ' . $c->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="policy_type">Poliçe Türü</label>
                    <select name="policy_type" id="policy_type" class="ab-select">
                        <option value="">Tüm Poliçe Türleri</option>
                        <?php foreach ($policy_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php selected($policy_type_filter, $type); ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insurance_company">Sigorta Firması</label>
                    <select name="insurance_company" id="insurance_company" class="ab-select">
                        <option value="">Tüm Sigorta Firmaları</option>
                        <?php foreach ($insurance_companies as $company): ?>
                            <option value="<?php echo $company; ?>" <?php selected($insurance_company_filter, $company); ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                        <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                    </select>
                </div>
                
                <div class="ab-filter-col ab-search-box">
                    <input type="text" name="s" id="search" value="<?php echo esc_attr($search); ?>" placeholder="Poliçe Ara...">
                    <button type="submit" class="ab-btn-search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn">Filtrele</button>
                    <a href="?view=policies" class="ab-btn ab-btn-secondary">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
    
    <?php if (!empty($policies)): ?>
    <div class="ab-crm-table-wrapper">
        <div class="ab-crm-table-info">
            <span>Toplam: <?php echo $total_items; ?> poliçe</span>
        </div>
        
        <table class="ab-crm-table">
            <thead>
                <tr>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_number', 'order' => $order === 'ASC' && $orderby === 'p.policy_number' ? 'DESC' : 'ASC')); ?>">
                            Poliçe No <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Müşteri</th>
                    <th>Temsilci</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_type', 'order' => $order === 'ASC' && $orderby === 'p.policy_type' ? 'DESC' : 'ASC')); ?>">
                            Poliçe Türü <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Sigorta Firması</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.end_date', 'order' => $order === 'ASC' && $orderby === 'p.end_date' ? 'DESC' : 'ASC')); ?>">
                            Bitiş Tarihi <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Prim</th>
                    <th>Durum</th>
                    <th>Döküman</th>
                    <th>Sigorta Ettiren</th>
                    <th class="ab-actions-column">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($policies as $policy): 
                    $is_expired = strtotime($policy->end_date) < time();
                    $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60);
                    
                    $row_class = '';
                    if ($is_expired) {
                        $row_class = 'expired';
                    } elseif ($is_expiring_soon) {
                        $row_class = 'expiring-soon';
                    }
                    
                    if ($policy->policy_type === 'Kasko' || $policy->policy_type === 'Trafik') {
                        $row_class .= ' policy-vehicle';
                    } elseif ($policy->policy_type === 'Konut' || $policy->policy_type === 'DASK') {
                        $row_class .= ' policy-property';
                    } elseif ($policy->policy_type === 'Sağlık' || $policy->policy_type === 'Hayat') {
                        $row_class .= ' policy-health';
                    }
                ?>
                    <tr class="<?php echo trim($row_class); ?>">
                        <td>
                            <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" class="ab-policy-number">
                                <?php echo esc_html($policy->policy_number); ?>
                                <?php if ($is_expired): ?>
                                    <span class="ab-badge ab-badge-danger">Süresi Dolmuş</span>
                                <?php elseif ($is_expiring_soon): ?>
                                    <span class="ab-badge ab-badge-warning">Yakında Bitiyor</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>" class="ab-customer-link">
                                <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html($policy->representative_name ?: '-'); ?>
                        </td>
                        <td><?php echo esc_html($policy->policy_type); ?></td>
                        <td><?php echo esc_html($policy->insurance_company); ?></td>
                        <td class="ab-date-cell"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                        <td class="ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></td>
                        <td>
                            <span class="ab-badge ab-badge-status-<?php echo esc_attr($policy->status); ?>">
                                <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($policy->document_path)): ?>
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" title="Dökümanı Görüntüle" class="ab-btn ab-btn-sm">
                                    <i class="fas fa-file-pdf"></i> Görüntüle
                                </a>
                            <?php else: ?>
                                <span class="ab-no-document">Döküman yok</span>
                            </td>
                        <td><?php echo !empty($policy->insured_party) ? esc_html($policy->insured_party) : '-'; ?></td>
                        <td class="ab-actions-cell">
                            <div class="ab-actions">
                                <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" title="Görüntüle" class="ab-action-btn">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>" title="Düzenle" class="ab-action-btn">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?php echo wp_nonce_url('?view=policies&action=delete&id=' . $policy->id, 'delete_policy_' . $policy->id); ?>" 
                                   onclick="return confirm('Bu poliçeyi silmek istediğinizden emin misiniz?');" 
                                   title="Sil" class="ab-action-btn ab-action-danger">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
        <div class="ab-pagination">
            <?php
            $pagination_args = array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '«',
                'next_text' => '»',
                'total' => $total_pages,
                'current' => $current_page
            );
            echo paginate_links($pagination_args);
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ab-empty-state">
        <i class="fas fa-file-contract"></i>
        <h3>Poliçe bulunamadı</h3>
        <p>Arama kriterlerinize uygun poliçe bulunamadı.</p>
        <a href="?view=policies" class="ab-btn">Tüm Poliçeleri Göster</a>
    </div>
    <?php endif; ?>
</div>

<?php
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            if (isset($_GET['id'])) {
                include_once('policies-view.php');
            }
            break;
        case 'edit':
            include_once('policies-form.php');
            break;
    }
}
?>

<style>
.ab-crm-container {
    max-width: 96%;
    width: 100%;
    margin: 0 auto;
    padding: 20px;
    font-family: inherit;
    color: #333;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e5e5;
    box-sizing: border-box;
}

.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-crm-header h1 {
    font-size: 22px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-crm-header h1 i {
    color: #555;
}

.ab-notice {
    padding: 10px 15px;
    margin-bottom: 15px;
    border-left: 4px solid;
    border-radius: 3px;
    font-size: 14px;
}

.ab-success {
    background-color: #f0fff4;
    border-left-color: #38a169;
}

.ab-error {
    background-color: #fff5f5;
    border-left-color: #e53e3e;
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
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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

.ab-btn-search {
    background: none;
    border: none;
    padding: 0 10px;
    color: #666;
    cursor: pointer;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #eee;
    padding: 12px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.ab-filter-col {
    flex: 1 1 200px;
    margin-bottom: 10px;
}

.ab-filter-col label {
    display: block;
    margin-bottom: 5px;
    font-size: 13px;
    font-weight: 500;
    color: #444;
}

.ab-search-box {
    display: flex;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    background-color: #fff;
    flex: 1 1 250px;
}

.ab-search-box input {
    flex: 1;
    padding: 8px 10px;
    border: none;
    outline: none;
    font-size: 13px;
}

.ab-button-col {
    flex: 0 0 auto;
    display: flex;
    gap: 10px;
    align-items: center;
}

.ab-select {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 34px;
}

.ab-crm-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    background-color: #fff;
    border-radius: 4px;
    border: 1px solid #eee;
}

.ab-crm-table-info {
    padding: 8px 12px;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #eee;
    background-color: #f8f9fa;
}

.ab-crm-table {
    width: 100%;
    border-collapse: collapse;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.ab-crm-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #444;
}

.ab-crm-table th a {
    color: #444;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ab-crm-table th a:hover {
    color: #000;
}

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

tr.policy-vehicle td {
    background-color: #f0f8ff !important;
}

tr.policy-vehicle td:first-child {
    border-left: 3px solid #2271b1;
}

tr.policy-vehicle:hover td {
    background-color: #e6f3ff !important;
}

tr.policy-property td {
    background-color: #f0fff0 !important;
}

tr.policy-property td:first-child {
    border-left: 3px solid #4caf50;
}

tr.policy-property:hover td {
    background-color: #e6ffe6 !important;
}

tr.policy-health td {
    background-color: #fff0f5 !important;
}

tr.policy-health td:first-child {
    border-left: 3px solid #e91e63;
}

tr.policy-health:hover td {
    background-color: #ffe6f0 !important;
}

tr.expired td {
    background-color: #fff2f2 !important;
}

tr.expired td:first-child {
    border-left: 3px solid #e53935;
}

tr.expiring-soon td {
    background-color: #fffaeb !important;
}

tr.expiring-soon td:first-child {
    border-left: 3px solid #ffc107;
}

.ab-policy-number {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-policy-number:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-link:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-actions-column {
    text-align: center;
    width: 100px;
    min-width: 100px;
}

.ab-actions-cell {
    text-align: center;
}

.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.ab-badge-status-aktif {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-status-pasif {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-danger {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-warning {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-no-document {
    color: #999;
    font-style: italic;
    font-size: 12px;
}

.ab-amount {
    font-weight: 600;
    color: #0366d6;
}

.ab-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.ab-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    color: #555;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    transition: all 0.2s;
    text-decoration: none;
}

.ab-action-btn:hover {
    background-color: #eee;
    color: #333;
    text-decoration: none;
}

.ab-action-danger:hover {
    background-color: #ffe5e5;
    color: #d32f2f;
    border-color: #ffcccc;
}

.ab-action-btn i {
    font-size: 14px;
    display: inline-block;
}

.ab-empty-state {
    text-align: center;
    padding: 30px 20px;
    color: #666;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
}

.ab-empty-state i {
    font-size: 36px;
    color: #999;
    margin-bottom: 10px;
}

.ab-empty-state h3 {
    margin: 10px 0;
    font-size: 16px;
}

.ab-empty-state p {
    margin-bottom: 20px;
    font-size: 14px;
}

.ab-pagination {
    padding: 10px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-top: 1px solid #eee;
}

.ab-pagination .page-numbers {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 5px;
    margin: 0 3px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    color: #333;
    text-decoration: none;
    font-size: 13px;
}

.ab-pagination .page-numbers.current {
    background-color: #4caf50;
    color: white;
    border-color: #43a047;
}

.ab-pagination .page-numbers:hover:not(.current) {
    background-color: #f5f5f5;
}

.ab-hidden {
    display: none;
}

@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        max-width: 100%;
        margin-left: 10px;
        margin-right: 10px;
    }
    
    .ab-crm-table th:nth-child(5),
    .ab-crm-table td:nth-child(5) {
        display: none;
    }
    
    .ab-crm-table th:nth-child(8),
    .ab-crm-table td:nth-child(8) {
        display: none;
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
    }
    
    .ab-filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .ab-filter-col, .ab-search-box {
        flex: 1 1 100%;
        max-width: 100%;
    }
    
    .ab-button-col {
        justify-content: center;
    }
    
    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ab-crm-header .ab-btn {
        align-self: flex-start;
    }
    
    .ab-crm-table th,
    .ab-crm-table td {
        padding: 8px 6px;
    }
    
    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4) {
        display: none;
    }
    
    .ab-crm-table th:nth-child(7),
    .ab-crm-table td:nth-child(7) {
        display: none;
    }
    
    .ab-actions {
        flex-direction: column;
        gap: 4px;
    }
    
    .ab-action-btn {
        width: 26px;
        height: 26px;
    }
}

@media (max-width: 576px) {
    .ab-crm-container {
        margin: 0;
        border-radius: 0;
        box-shadow: none;
    }
    
    .ab-crm-table th:nth-child(9),
    .ab-crm-table td:nth-child(9) {
        display: none;
    }
}
</style>