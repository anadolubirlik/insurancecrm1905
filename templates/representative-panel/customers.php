<?php
/**
 * Frontend Müşteri Yönetim Sayfası
 * @version 2.4.1
 */

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Değişkenleri tanımla
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// Mevcut kullanıcının temsilci ID'sini al
function get_current_user_rep_id() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
}
$current_user_rep_id = get_current_user_rep_id();

// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Müşteri silme işlemi
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $customer_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_customer_' . $customer_id)) {
        // Temsilci yetkisi kontrolü
        $can_delete = true;
        if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
            $customer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $customers_table WHERE id = %d", $customer_id
            ));
            if ($customer->representative_id != $current_user_rep_id) {
                $can_delete = false;
                $notice = '<div class="ab-notice ab-error">Bu müşteriyi silme yetkiniz yok.</div>';
            }
        }
        
        if ($can_delete) {
            $wpdb->update(
                $customers_table,
                array('status' => 'pasif'),
                array('id' => $customer_id)
            );
            $notice = '<div class="ab-notice ab-success">Müşteri pasif duruma getirildi.</div>';
        }
    }
}

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// FİLTRELEME PARAMETRELERİ
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
$representative_filter = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;

// GELİŞMİŞ FİLTRELER
$gender_filter = isset($_GET['gender']) ? sanitize_text_field($_GET['gender']) : '';
$is_pregnant_filter = isset($_GET['is_pregnant']) ? '1' : '';
$has_children_filter = isset($_GET['has_children']) ? '1' : '';
$has_spouse_filter = isset($_GET['has_spouse']) ? '1' : '';
$has_vehicle_filter = isset($_GET['has_vehicle']) ? '1' : '';
$owns_home_filter = isset($_GET['owns_home']) ? '1' : '';
$has_pet_filter = isset($_GET['has_pet']) ? '1' : '';
$child_tc_filter = isset($_GET['child_tc']) ? sanitize_text_field($_GET['child_tc']) : '';
$spouse_tc_filter = isset($_GET['spouse_tc']) ? sanitize_text_field($_GET['spouse_tc']) : '';

// Sorgu oluştur
$base_query = "FROM $customers_table c 
               LEFT JOIN $representatives_table r ON c.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Temsilci yetkisi kontrolü
if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id) {
    $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}

// Arama filtresi - GELİŞTİRİLMİŞ
if (!empty($search)) {
    $base_query .= $wpdb->prepare(
        " AND (
            c.first_name LIKE %s 
            OR c.last_name LIKE %s 
            OR CONCAT(c.first_name, ' ', c.last_name) LIKE %s
            OR c.tc_identity LIKE %s 
            OR c.email LIKE %s 
            OR c.phone LIKE %s
            OR c.spouse_name LIKE %s
            OR c.spouse_tc_identity LIKE %s
            OR c.children_names LIKE %s
            OR c.children_tc_identities LIKE %s
            OR c.company_name LIKE %s
            OR c.tax_number LIKE %s
        )",
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}

// Durum ve kategori filtreleri
if (!empty($status_filter)) {
    $base_query .= $wpdb->prepare(" AND c.status = %s", $status_filter);
}

if (!empty($category_filter)) {
    $base_query .= $wpdb->prepare(" AND c.category = %s", $category_filter);
}

if ($representative_filter > 0) {
    $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $representative_filter);
}

// GELİŞMİŞ FİLTRELER UYGULAMASI
if (!empty($gender_filter)) {
    $base_query .= $wpdb->prepare(" AND c.gender = %s", $gender_filter);
}

// Gebe müşteriler filtresi
if (!empty($is_pregnant_filter)) {
    $base_query .= " AND c.is_pregnant = 1 AND c.gender = 'female'";
}

// Çocuklu müşteriler filtresi
if (!empty($has_children_filter)) {
    $base_query .= " AND (c.children_count > 0 OR c.children_names IS NOT NULL)";
}

// Eşi olan müşteriler filtresi
if (!empty($has_spouse_filter)) {
    $base_query .= " AND c.spouse_name IS NOT NULL AND c.spouse_name != ''";
}

// Aracı olan müşteriler filtresi
if (!empty($has_vehicle_filter)) {
    $base_query .= " AND c.has_vehicle = 1";
}

// Ev sahibi olan müşteriler filtresi
if (!empty($owns_home_filter)) {
    $base_query .= " AND c.owns_home = 1";
}

// Evcil hayvan sahibi olan müşteriler filtresi
if (!empty($has_pet_filter)) {
    $base_query .= " AND c.has_pet = 1";
}

// Çocuk TC'si ile arama
if (!empty($child_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.children_tc_identities LIKE %s", '%' . $wpdb->esc_like($child_tc_filter) . '%');
}

// Eş TC'si ile arama
if (!empty($spouse_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.spouse_tc_identity = %s", $spouse_tc_filter);
}

// Toplam müşteri sayısını al
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT c.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'c.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Müşterileri getir
$customers = $wpdb->get_results("
    SELECT c.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, u.display_name as representative_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Temsilcileri al (yönetici/yönetici rolü için)
$representatives = array();
if (current_user_can('administrator') || current_user_can('insurance_manager')) {
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        JOIN $users_table u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
}

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Filtreleme yapıldı mı kontrolü
$is_filtered = !empty($search) || 
               !empty($status_filter) || 
               !empty($category_filter) || 
               $representative_filter > 0 || 
               !empty($gender_filter) || 
               !empty($is_pregnant_filter) || 
               !empty($has_children_filter) || 
               !empty($has_spouse_filter) || 
               !empty($has_vehicle_filter) || 
               !empty($owns_home_filter) || 
               !empty($has_pet_filter) || 
               !empty($child_tc_filter) || 
               !empty($spouse_tc_filter);
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container">
    <?php echo $notice; ?>

    <!-- Müşteri Listesi -->
    <div class="ab-customers-list <?php echo !$show_list ? 'ab-hidden' : ''; ?>">
        <!-- Header -->
        <div class="ab-crm-header">
            <h1>Müşteriler</h1>
            <div class="ab-header-actions">
                <button type="button" id="ab-toggle-advanced-filter" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-filter"></i> Filtreleme
                </button>
                <a href="?view=customers&action=new" class="ab-btn ab-btn-primary">
                    <i class="fas fa-plus"></i> Yeni Müşteri
                </a>
            </div>
        </div>
        
        <!-- Gelişmiş Filtreler - JavaScript ile toggle edilir -->
        <div id="ab-filters-panel" class="ab-crm-filters" style="display: none;">
            <form method="get" action="" id="customers-filter" class="ab-filter-form">
                <input type="hidden" name="view" value="customers">
                
                <div class="ab-filter-top-row">
                    <div class="ab-filter-col">
                        <label for="ab_filter_status">Durum</label>
                        <select name="status" id="ab_filter_status" class="ab-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                            <option value="belirsiz" <?php selected($status_filter, 'belirsiz'); ?>>Belirsiz</option>
                        </select>
                    </div>
                    
                    <div class="ab-filter-col">
                        <label for="ab_filter_category">Kategori</label>
                        <select name="category" id="ab_filter_category" class="ab-select">
                            <option value="">Tüm Kategoriler</option>
                            <option value="bireysel" <?php selected($category_filter, 'bireysel'); ?>>Bireysel</option>
                            <option value="kurumsal" <?php selected($category_filter, 'kurumsal'); ?>>Kurumsal</option>
                        </select>
                    </div>
                    
                    <?php if (current_user_can('administrator') || current_user_can('insurance_manager')): ?>
                    <div class="ab-filter-col">
                        <label for="ab_filter_rep_id">Temsilci</label>
                        <select name="rep_id" id="ab_filter_rep_id" class="ab-select">
                            <option value="">Tüm Temsilciler</option>
                            <?php foreach ($representatives as $rep): ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($representative_filter, $rep->id); ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="ab-filter-col">
                        <label for="ab_filter_search">Arama</label>
                        <div class="ab-search-box">
                            <input type="text" name="s" id="ab_filter_search" value="<?php echo esc_attr($search); ?>" placeholder="Müşteri Ara...">
                            <button type="submit" class="ab-btn-search">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="ab-advanced-filters">
                    <div class="ab-filter-section">
                        <h4>Kişisel Bilgiler</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label for="ab_filter_gender">Cinsiyet</label>
                                <select name="gender" id="ab_filter_gender" class="ab-select">
                                    <option value="">Seçiniz</option>
                                    <option value="male" <?php selected($gender_filter, 'male'); ?>>Erkek</option>
                                    <option value="female" <?php selected($gender_filter, 'female'); ?>>Kadın</option>
                                </select>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="is_pregnant" id="ab_filter_is_pregnant" <?php checked($is_pregnant_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Sadece gebe müşteriler</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ab-filter-section">
                        <h4>Aile Bilgileri</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_spouse" id="ab_filter_has_spouse" <?php checked($has_spouse_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Eşi olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_children" id="ab_filter_has_children" <?php checked($has_children_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Çocuğu olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label for="ab_filter_spouse_tc">Eş TC Kimlik No</label>
                                <input type="text" name="spouse_tc" id="ab_filter_spouse_tc" class="ab-input" value="<?php echo esc_attr($spouse_tc_filter); ?>" placeholder="Eş TC Kimlik ile ara...">
                            </div>
                            
                            <div class="ab-filter-col">
                                <label for="ab_filter_child_tc">Çocuk TC Kimlik No</label>
                                <input type="text" name="child_tc" id="ab_filter_child_tc" class="ab-input" value="<?php echo esc_attr($child_tc_filter); ?>" placeholder="Çocuk TC Kimlik ile ara...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="ab-filter-section">
                        <h4>Varlık Bilgileri</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_vehicle" id="ab_filter_has_vehicle" <?php checked($has_vehicle_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Aracı olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="owns_home" id="ab_filter_owns_home" <?php checked($owns_home_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Ev sahibi olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_pet" id="ab_filter_has_pet" <?php checked($has_pet_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Evcil hayvanı olanlar</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="ab-filter-actions">
                    <button type="submit" class="ab-btn ab-btn-primary">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                    <a href="?view=customers" class="ab-btn ab-btn-secondary">
                        <i class="fas fa-times"></i> Sıfırla
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Filtreleme durumunu gösteren bilgi kutusu -->
        <?php if ($is_filtered): ?>
        <div class="ab-filter-info">
            <div class="ab-filter-info-content">
                <i class="fas fa-info-circle"></i> 
                <span>Filtreleme aktif. Toplam <?php echo $total_items; ?> müşteri bulundu.</span>
            </div>
            <a href="?view=customers" class="ab-filter-clear-btn">
                <i class="fas fa-times"></i> Filtreleri Temizle
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Tablo -->
        <?php if (!empty($customers)): ?>
        <div class="ab-crm-table-wrapper">
            <div class="ab-crm-table-info">
                <span>Toplam: <?php echo $total_items; ?> müşteri</span>
            </div>
            
            <table class="ab-crm-table">
                <thead>
                    <tr>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.first_name', 'order' => $order === 'ASC' && $orderby === 'c.first_name' ? 'DESC' : 'ASC')); ?>">
                                Ad Soyad <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>TC Kimlik</th>
                        <th>İletişim</th>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.category', 'order' => $order === 'ASC' && $orderby === 'c.category' ? 'DESC' : 'ASC')); ?>">
                                Kategori <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.status', 'order' => $order === 'ASC' && $orderby === 'c.status' ? 'DESC' : 'ASC')); ?>">
                                Durum <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <?php if (current_user_can('administrator') || current_user_can('insurance_manager')): ?>
                        <th>Temsilci</th>
                        <?php endif; ?>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.created_at', 'order' => $order === 'ASC' && $orderby === 'c.created_at' ? 'DESC' : 'ASC')); ?>">
                                Kayıt Tarihi <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th class="ab-actions-column">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <?php 
                        $row_class = '';
                        switch ($customer->status) {
                            case 'aktif': $row_class = 'status-active'; break;
                            case 'pasif': $row_class = 'status-inactive'; break;
                            case 'belirsiz': $row_class = 'status-uncertain'; break;
                        }
                        // Kurumsal müşteriler için ek class ekleyelim
                        if ($customer->category === 'kurumsal') {
                            $row_class .= ' customer-corporate';
                        }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td>
                                <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>" class="ab-customer-name">
                                    <?php echo esc_html($customer->customer_name); ?>
                                </a>
                                <?php if (!empty($customer->company_name)): ?>
                                <div class="ab-company-name"><?php echo esc_html($customer->company_name); ?></div>
                                <?php endif; ?>
                                <?php if ($customer->is_pregnant == 1): ?>
                                <span class="ab-badge ab-badge-pregnancy"><i class="fas fa-baby"></i> Gebe</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($customer->tc_identity); ?></td>
                            <td>
                                <div>
                                    <?php if (!empty($customer->email)): ?>
                                    <div class="ab-contact-info"><i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer->phone)): ?>
                                    <div class="ab-contact-info"><i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="ab-badge ab-badge-category-<?php echo $customer->category; ?>">
                                    <?php echo $customer->category === 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="ab-badge ab-badge-status-<?php echo $customer->status; ?>">
                                    <?php 
                                    switch ($customer->status) {
                                        case 'aktif': echo 'Aktif'; break;
                                        case 'pasif': echo 'Pasif'; break;
                                        case 'belirsiz': echo 'Belirsiz'; break;
                                        default: echo ucfirst($customer->status);
                                    }
                                    ?>
                                </span>
                            </td>
                            <?php if (current_user_can('administrator') || current_user_can('insurance_manager')): ?>
                            <td>
                                <?php echo !empty($customer->representative_name) ? esc_html($customer->representative_name) : '—'; ?>
                            </td>
                            <?php endif; ?>
                            <td class="ab-date-cell"><?php echo date('d.m.Y', strtotime($customer->created_at)); ?></td>
                            <td class="ab-actions-cell">
                                <div class="ab-actions">
                                    <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>" title="Görüntüle" class="ab-action-btn">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="?view=customers&action=edit&id=<?php echo $customer->id; ?>" title="Düzenle" class="ab-action-btn">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($customer->status !== 'pasif'): ?>
                                    <a href="<?php echo wp_nonce_url('?view=customers&action=delete&id=' . $customer->id, 'delete_customer_' . $customer->id); ?>" 
                                       onclick="return confirm('Bu müşteriyi pasif duruma getirmek istediğinizden emin misiniz?');" 
                                       title="Pasif Yap" class="ab-action-btn ab-action-danger">
                                        <i class="fas fa-ban"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
            <div class="ab-pagination">
                <?php
                // Sayfalama bağlantıları
                $pagination_args = array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
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
            <i class="fas fa-users"></i>
            <h3>Müşteri bulunamadı</h3>
            <p>Arama kriterlerinize uygun müşteri bulunamadı.</p>
            <a href="?view=customers" class="ab-btn">Tüm Müşterileri Göster</a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php
    // Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'view':
                if (isset($_GET['id'])) {
                    include_once('customers-view.php');
                }
                break;
            case 'new':
            case 'edit':
                include_once('customers-form.php');
                break;
        }
    }
    ?>
</div>

<style>
/* Temel Stiller - Daha kompakt ve şık tasarım */
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
}

.ab-crm-header h1 {
    font-size: 22px;
    margin: 0;
    font-weight: 600;
    color: #333;
}

.ab-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Bildirimler */
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

/* Butonlar */
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

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-btn-search {
    background: none;
    border: none;
    padding: 0 10px;
    color: #666;
    cursor: pointer;
}

/* Filtreler */
.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #eee;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-filter-top-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.ab-filter-col {
    flex: 1;
    min-width: 120px;
}

.ab-filter-col label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 12px;
    color: #555;
}

.ab-search-box {
    display: flex;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    background-color: #fff;
}

.ab-search-box input {
    flex: 1;
    padding: 8px 10px;
    border: none;
    outline: none;
    width: 100%;
    font-size: 13px;
}

.ab-select {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 32px;
}

.ab-input {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 32px;
}

/* Gelişmiş filtreler için stil */
.ab-advanced-filters {
    margin-top: 10px;
    padding-top: 15px;
    border-top: 1px dashed #ddd;
}

.ab-filter-section {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ab-filter-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.ab-filter-section h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.ab-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

/* ÖZEL OLARAK ÇAKIŞMAYI ÖNLEMEK İÇİN CHECKBOX STIL */
.ab-filter-checkbox-container {
    display: flex;
    align-items: center;
    margin-top: 10px;
    cursor: pointer;
}

.ab-filter-checkbox-container input[type="checkbox"] {
    margin-right: 8px;
}

.ab-filter-checkbox-text {
    font-size: 13px;
    user-select: none;
}

/* Filtre action butonları */
.ab-filter-actions {
    margin-top: 15px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Filtreleme durumu bilgisi */
.ab-filter-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 15px;
    background-color: #e6f7ff;
    border: 1px solid #91d5ff;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 13px;
    color: #1890ff;
}

.ab-filter-info-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-filter-clear-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #1890ff;
    text-decoration: none;
    font-weight: 500;
    font-size: 12px;
    transition: color 0.2s;
}

.ab-filter-clear-btn:hover {
    text-decoration: underline;
    color: #096dd9;
}

/* Tablo */
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

.ab-customer-name {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-name:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-company-name {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
}

/* Kurumsal müşteri satırı için stil */
tr.customer-corporate td {
    background-color: #f8f4ff !important; /* Açık mor arka plan */
}

tr.customer-corporate td:first-child {
    border-left: 3px solid #8e44ad; /* Sadece ilk hücrede sol kenarda mor çizgi */
}

tr.customer-corporate:hover td {
    background-color: #f0e8ff !important; /* Hover durumunda daha koyu mor */
}

/* İşlemler kolonu */
.ab-actions-column {
    text-align: center;
    width: 100px;
    min-width: 100px;
}

.ab-actions-cell {
    text-align: center;
}

/* İletişim bilgileri */
.ab-contact-info {
    font-size: 12px;
    margin-bottom: 3px;
    color: #666;
}

.ab-contact-info:last-child {
    margin-bottom: 0;
}

.ab-contact-info i {
    width: 14px;
    text-align: center;
    margin-right: 5px;
    color: #888;
}

/* Tarih hücresi */
.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

/* Durum ve Kategori Renklendimeleri */
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

.ab-badge-status-belirsiz {
    background-color: #fff8c5;
    color: #b08800;
}

.ab-badge-category-bireysel {
    background-color: #e1effe;
    color: #1e429f;
}

.ab-badge-category-kurumsal {
    background-color: #e6f6eb;
    color: #166534;
}

.ab-badge-pregnancy {
    background-color: #fce7f3;
    color: #be185d;
    margin-top: 4px;
    font-size: 10px;
    padding: 2px 6px;
}

/* İşlem Butonları */
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

/* İkon stilleri - görünürlük düzeltmesi */
.ab-action-btn i {
    font-size: 14px;
    display: inline-block;
}

/* Boş Durum Gösterimi */
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

/* Sayfalama */
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

/* Gizleme için stil */
.ab-hidden {
    display: none;
}

/* Geri dön butonu - customer-view.php ve customer-form.php için */
.ab-back-button {
    margin-bottom: 15px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    padding: 6px 12px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #444;
    text-decoration: none;
    transition: all 0.2s;
}

.ab-back-button:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
}

/* Mobil Uyumluluk - Geliştirilmiş */
@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }
    
    .ab-filter-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        max-width: 100%;
        margin-left: 10px;
        margin-right: 10px;
    }
    
    .ab-crm-table th:nth-child(2),
    .ab-crm-table td:nth-child(2) {
        display: none; /* TC Kimlik kolonunu gizle */
    }
    
    .ab-filter-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
    }
    
    .ab-filter-col {
        width: 100%;
    }
    
    .ab-filter-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ab-header-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .ab-header-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-crm-table th,
    .ab-crm-table td {
        padding: 8px 6px;
    }
    
    /* Bazı kolonları küçük ekranlarda gizle */
    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none; /* İletişim kolonunu gizle */
    }
    
    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4) {
        display: none; /* Kategori kolonunu gizle */
    }
    
    .ab-actions {
        flex-direction: column;
        gap: 4px;
    }
    
    .ab-action-btn {
        width: 26px;
        height: 26px;
    }
    
    .ab-filter-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .ab-filter-actions {
        flex-direction: column;
    }

    .ab-filter-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .ab-crm-container {
        margin: 0;
        border-radius: 0;
        box-shadow: none;
    }
    
    .ab-crm-table th:nth-child(6),
    .ab-crm-table td:nth-child(6) {
        display: none; /* Kayıt tarihi kolonunu gizle */
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Gelişmiş filtreleme göster/gizle işlemleri
    $('#ab-toggle-advanced-filter').click(function() {
        $('#ab-filters-panel').slideToggle(300);
    });

    // Filtreleme aktifse filtreleme panelini otomatik aç
    <?php if ($is_filtered): ?>
    $('#ab-filters-panel').show();
    <?php endif; ?>

    // Form gönderildiğinde sadece dolu alanların gönderilmesi
    $('#customers-filter').submit(function() {
        // Gelişmiş filtrelerde, boş veya seçilmemiş alanları kaldır
        $(this).find(':input').each(function() {
            // Checkbox için kontrol
            if (this.type === 'checkbox' && !this.checked) {
                $(this).prop('disabled', true);
            }
            // Select ve text inputlar için kontrol
            else if ((this.type === 'select-one' || this.type === 'text') && !$(this).val()) {
                $(this).prop('disabled', true);
            }
        });
        return true;
    });
    
    // Gebe checkbox kontrolü - Sadece kadın seçildiğinde aktif olsun
    $('#ab_filter_gender').change(function() {
        if ($(this).val() === 'female') {
            $('#ab_filter_is_pregnant').prop('disabled', false);
            $('#ab_filter_is_pregnant').parent().removeClass('disabled');
        } else {
            $('#ab_filter_is_pregnant').prop('checked', false);
            $('#ab_filter_is_pregnant').prop('disabled', true);
            $('#ab_filter_is_pregnant').parent().addClass('disabled');
        }
    });
    
    // Sayfa yüklendiğinde cinsiyet seçimine göre gebe checkbox durumu kontrolü
    if ($('#ab_filter_gender').val() !== 'female') {
        $('#ab_filter_is_pregnant').prop('disabled', true);
        $('#ab_filter_is_pregnant').parent().addClass('disabled');
    }
    
    // Sayfalama ve sıralama linklerinde tüm filtrelerin kalması için
    $('.ab-pagination .page-numbers, .ab-crm-table th a').each(function() {
        var href = $(this).attr('href');
        if (href) {
            // Mevcut URL'deki tüm parametreleri al
            var currentUrlParams = new URLSearchParams(window.location.search);
            var targetUrlParams = new URLSearchParams(href);
            
            // Hedef URL'de paged veya order parametresi varsa koru
            var hasPagedParam = targetUrlParams.has('paged');
            var hasOrderParams = targetUrlParams.has('orderby') || targetUrlParams.has('order');
            
            // Mevcut URL'deki tüm parametreleri yeni URL'ye ekle (paged ve order hariç)
            currentUrlParams.forEach(function(value, key) {
                // paged veya order/orderby parametrelerini hedef URL'den içeriyorsa ekle
                if ((key !== 'paged' || !hasPagedParam) && 
                    ((key !== 'orderby' && key !== 'order') || !hasOrderParams)) {
                    targetUrlParams.set(key, value);
                }
            });
            
            $(this).attr('href', '?' + targetUrlParams.toString());
        }
    });
});
</script>