<?php
/**
 * Frontend Poliçe Yönetim Sayfası
 * @version 2.2.6
 */

include_once(dirname(__FILE__) . '/template-colors.php');

// Kullanıcı oturum kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Veritabanı tablolarını tanımlama
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// insured_party sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Mevcut kullanıcı temsilcisi ID'sini alma
function get_current_user_rep_id() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'", $current_user_id));
}
$current_user_rep_id = get_current_user_rep_id();

// Bildirim mesajı
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Silme işlemi
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $policy_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_policy_' . $policy_id)) {
        $can_delete = true;
        if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
            $policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));
            if ($policy->representative_id != $current_user_rep_id) {
                $can_delete = false;
                $notice = '<div class="ab-notice ab-error">Bu poliçeyi silme yetkiniz yok.</div>';
            }
        }
        
        if ($can_delete) {
            $wpdb->delete($policies_table, array('id' => $policy_id));
            $notice = '<div class="ab-notice ab-success">Poliçe başarıyla silindi.</div>';
        }
    }
}

// Filtreleme için GET parametrelerini al ve sanitize et
$filters = array(
    'policy_number' => isset($_GET['policy_number']) ? sanitize_text_field($_GET['policy_number']) : '',
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : '',
    'policy_type' => isset($_GET['policy_type']) ? sanitize_text_field($_GET['policy_type']) : '',
    'insurance_company' => isset($_GET['insurance_company']) ? sanitize_text_field($_GET['insurance_company']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'insured_party' => isset($_GET['insured_party']) ? sanitize_text_field($_GET['insured_party']) : '',
);

// Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// Temel sorguyu oluştur
$base_query = "FROM $policies_table p 
               LEFT JOIN $customers_table c ON p.customer_id = c.id
               LEFT JOIN $representatives_table r ON p.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Yetki kontrolü
if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id) {
    $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
}

// Filtreleme kriterlerini sorguya ekle
if (!empty($filters['policy_number'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_number LIKE %s", '%' . $wpdb->esc_like($filters['policy_number']) . '%');
}
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND p.customer_id = %d", $filters['customer_id']);
}
if (!empty($filters['policy_type'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_type = %s", $filters['policy_type']);
}
if (!empty($filters['insurance_company'])) {
    $base_query .= $wpdb->prepare(" AND p.insurance_company = %s", $filters['insurance_company']);
}
if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND p.status = %s", $filters['status']);
}
if (!empty($filters['insured_party'])) {
    $base_query .= $wpdb->prepare(" AND p.insured_party LIKE %s", '%' . $wpdb->esc_like($filters['insured_party']) . '%');
}

// Toplam kayıt sayısını al
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT p.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'p.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Filtrelenmiş poliçe listesini al
$policies = $wpdb->get_results("
    SELECT p.*, 
           c.first_name, c.last_name,
           u.display_name as representative_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Diğer gerekli veriler
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
        <h1><i class="fas fa-file-contract"></i> Poliçeler</h1>
        <a href="?view=policies&action=new" class="ab-btn ab-btn-primary">
            <i class="fas fa-plus"></i> Yeni Poliçe
        </a>
    </div>
    
    <!-- Yeni filtreleme butonu ve gizlenmiş filtreler -->
    <div class="ab-filter-toggle-container">
        <button type="button" id="toggle-filters-btn" class="ab-btn ab-toggle-filters">
            <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
        </button>
        
        <!-- Sayıların gösterildiği durum göstergesi -->
        <?php
        $active_filter_count = 0;
        foreach ($filters as $key => $value) {
            if (!empty($value)) $active_filter_count++;
        }
        if ($active_filter_count > 0):
        ?>
        <div class="ab-active-filters">
            <span><?php echo $active_filter_count; ?> aktif filtre</span>
            <a href="?view=policies" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Filtre Formu -->
    <div id="policies-filters-container" class="ab-crm-filters <?php echo $active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>">
        <form method="get" id="policies-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="policies">
            <?php wp_nonce_field('policies_filter_nonce', 'policies_filter_nonce'); ?>
            
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="policy_number">Poliçe No</label>
                    <input type="text" name="policy_number" id="policy_number" value="<?php echo esc_attr($filters['policy_number']); ?>" placeholder="Poliçe No Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php echo $filters['customer_id'] == $c->id ? 'selected' : ''; ?>>
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
                            <option value="<?php echo $type; ?>" <?php echo $filters['policy_type'] == $type ? 'selected' : ''; ?>>
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
                            <option value="<?php echo $company; ?>" <?php echo $filters['insurance_company'] == $company ? 'selected' : ''; ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="aktif" <?php echo $filters['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="pasif" <?php echo $filters['status'] == 'pasif' ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insured_party">Sigorta Ettiren</label>
                    <input type="text" name="insured_party" id="insured_party" value="<?php echo esc_attr($filters['insured_party']); ?>" placeholder="Sigorta Ettiren Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn ab-btn-filter">Filtrele</button>
                    <a href="?view=policies" class="ab-btn ab-btn-reset">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Varsayılan Poliçe Listesi -->
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
                            <?php endif; ?>
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
        case 'new':
        case 'edit':
        case 'renew':
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

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-filter-form {
    width: 100%;
}

.ab-filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    align-items: end;
}

.ab-filter-col {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.ab-filter-col label {
    font-size: 14px;
    font-weight: 500;
    color: #444;
    margin-bottom: 8px;
    line-height: 1.4;
}

.ab-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-select:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-filter-col input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-filter-col input[type="text"]:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-button-col {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.ab-btn-filter {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-filter:hover {
    background-color: #3d9140;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-reset {
    background-color: #f8f9fa;
    border-color: #d1d5db;
    color: #666;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-reset:hover {
    background-color: #e5e7eb;
    color: #444;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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

/* Responsive Tasarım */
@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }

    .ab-filter-row {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        max-width: 100%;
        margin-left: 10px;
        margin-right: 10px;
    }
    
    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4) {
        display: none;
    }
    
    .ab-crm-table th:nth-child(7),
    .ab-crm-table td:nth-child(7) {
        display: none;
    }

    .ab-filter-row {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
    }
    
    .ab-filter-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .ab-filter-col {
        margin-bottom: 0;
    }
    
    .ab-filter-col label {
        margin-bottom: 6px;
    }
    
    .ab-select, .ab-filter-col input[type="text"] {
        padding: 9px 10px;
        font-size: 14px;
        height: 38px;
    }
    
    .ab-button-col {
        justify-content: center;
        gap: 10px;
    }
    
    .ab-btn-filter, .ab-btn-reset {
        padding: 9px 18px;
        flex: 1;
        text-align: center;
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
    
    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none;
    }
    
    .ab-crm-table th:nth-child(6),
    .ab-crm-table td:nth-child(6) {
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
    
    .ab-crm-table th:nth-child(8),
    .ab-crm-table td:nth-child(8) {
        display: none;
    }

    .ab-crm-filters {
        padding: 15px;
    }

    .ab-btn-filter, .ab-btn-reset {
        padding: 8px 16px;
        font-size: 13px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filtreleme toggle kontrolü
    const toggleFiltersBtn = document.getElementById('toggle-filters-btn');
    const filtersContainer = document.getElementById('policies-filters-container');
    
    if (toggleFiltersBtn && filtersContainer) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersContainer.classList.toggle('ab-filters-hidden');
            toggleFiltersBtn.classList.toggle('active');
        });
    }

    // Filtre Formu Submit Kontrolü
    const filterForm = document.querySelector('#policies-filter');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            const inputs = filterForm.querySelectorAll('input, select');
            let hasValue = false;
            inputs.forEach(input => {
                if (input.value.trim() && input.name !== 'view' && input.name !== 'policies_filter_nonce') {
                    hasValue = true;
                }
            });
            if (!hasValue) {
                e.preventDefault();
                alert('Lütfen en az bir filtre kriteri girin.');
            }
        });
    }
});
</script>