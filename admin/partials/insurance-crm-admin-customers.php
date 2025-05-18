<?php
/**
 * Müşteriler yönetim sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.0
 */

if (!defined('WPINC')) {
    die;
}

// Yeni müşteri ekle veya düzenle formunu göster
if (isset($_GET['action']) && ($_GET['action'] === 'new' || $_GET['action'] === 'edit')) {
    include_once('insurance-crm-admin-customers-form.php');
    return;
}

// Müşteri detaylarını göster
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    include_once('insurance-crm-admin-customer-details.php');
    return;
}

// Müşteri silme işlemi
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $customer_id = intval($_GET['id']);
    
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_customer_' . $customer_id)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_customers';
        
        // Müşteriyi pasif olarak işaretle (tamamen silme yerine)
        $wpdb->update(
            $table_name,
            array('status' => 'pasif'),
            array('id' => $customer_id)
        );
        
        echo '<div class="notice notice-success is-dismissible"><p>Müşteri pasif duruma getirildi.</p></div>';
    }
}

// Sayfalama ve filtreleme için parametreler
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($current_page - 1) * $per_page;

// Arama filtresi
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Durum filtresi
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Kategori filtresi
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

// Temsilci filtresi
$representative_filter = isset($_GET['representative_id']) ? intval($_GET['representative_id']) : 0;

global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// Temel sorgu
$base_query = "FROM $customers_table c 
               LEFT JOIN $representatives_table r ON c.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Arama filtresi ekle
if (!empty($search)) {
    $base_query .= $wpdb->prepare(
        " AND (c.first_name LIKE %s OR c.last_name LIKE %s OR c.tc_identity LIKE %s OR c.email LIKE %s OR c.phone LIKE %s)",
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}

// Durum filtresi ekle
if (!empty($status_filter)) {
    $base_query .= $wpdb->prepare(" AND c.status = %s", $status_filter);
}

// Kategori filtresi ekle
if (!empty($category_filter)) {
    $base_query .= $wpdb->prepare(" AND c.category = %s", $category_filter);
}

// Temsilci filtresi ekle
if ($representative_filter > 0) {
    $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $representative_filter);
}

// Toplam müşteri sayısını al
$total_query = "SELECT COUNT(DISTINCT c.id) " . $base_query;
$total_items = $wpdb->get_var($total_query);

// Sıralama seçeneği
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'c.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Sayfalama için müşterileri getir
$customers_query = "SELECT c.*, u.display_name as representative_name " . $base_query . " ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";
$customers = $wpdb->get_results($customers_query);

// Temsilcileri filtre için al
$representatives = $wpdb->get_results(
    "SELECT r.id, u.display_name 
     FROM $representatives_table r
     JOIN $users_table u ON r.user_id = u.ID
     WHERE r.status = 'active'
     ORDER BY u.display_name ASC"
);

// Sayfalama için toplam sayfa sayısı
$total_pages = ceil($total_items / $per_page);

// Sayfalama bağlantıları
$page_links = paginate_links(array(
    'base' => add_query_arg('paged', '%#%'),
    'format' => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total' => $total_pages,
    'current' => $current_page
));

?>

<div class="wrap">
    <h1 class="wp-heading-inline">Müşteriler</h1>
    <a href="?page=insurance-crm-customers&action=new" class="page-title-action">Yeni Müşteri</a>
    
    <hr class="wp-header-end">
    
    <form id="customers-filter" method="get">
        <input type="hidden" name="page" value="insurance-crm-customers">
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="status">
                    <option value="">Tüm Durumlar</option>
                    <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                    <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                    <option value="belirsiz" <?php selected($status_filter, 'belirsiz'); ?>>Durumu Belirsiz</option>
                </select>
                
                <select name="category">
                    <option value="">Tüm Kategoriler</option>
                    <option value="bireysel" <?php selected($category_filter, 'bireysel'); ?>>Bireysel</option>
                    <option value="kurumsal" <?php selected($category_filter, 'kurumsal'); ?>>Kurumsal</option>
                </select>
                
                <select name="representative_id">
                    <option value="">Tüm Temsilciler</option>
                    <?php foreach ($representatives as $rep): ?>
                        <option value="<?php echo $rep->id; ?>" <?php selected($representative_filter, $rep->id); ?>><?php echo $rep->display_name; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="submit" name="filter_action" id="customer-query-submit" class="button" value="Filtrele">
            </div>
            
            <div class="alignright">
                <p class="search-box">
                    <label class="screen-reader-text" for="customer-search-input">Müşteri ara:</label>
                    <input type="search" id="customer-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Ad, Soyad, TC, E-posta...">
                    <input type="submit" id="search-submit" class="button" value="Ara">
                </p>
            </div>
            
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> öğe</span>
                <span class="pagination-links"><?php echo $page_links; ?></span>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-name">
                        <a href="<?php echo add_query_arg(array('orderby' => 'c.first_name', 'order' => $order === 'ASC' && $orderby === 'c.first_name' ? 'DESC' : 'ASC')); ?>">
                            <span>Ad Soyad</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-tc">
                        <span>TC Kimlik No</span>
                    </th>
                    <th scope="col" class="manage-column column-contact">
                        <span>İletişim</span>
                    </th>
                    <th scope="col" class="manage-column column-category">
                        <a href="<?php echo add_query_arg(array('orderby' => 'c.category', 'order' => $order === 'ASC' && $orderby === 'c.category' ? 'DESC' : 'ASC')); ?>">
                            <span>Kategori</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-status">
                        <a href="<?php echo add_query_arg(array('orderby' => 'c.status', 'order' => $order === 'ASC' && $orderby === 'c.status' ? 'DESC' : 'ASC')); ?>">
                            <span>Durum</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-representative">
                        <span>Temsilci</span>
                    </th>
                    <th scope="col" class="manage-column column-date">
                        <a href="<?php echo add_query_arg(array('orderby' => 'c.created_at', 'order' => $order === 'ASC' && $orderby === 'c.created_at' ? 'DESC' : 'ASC')); ?>">
                            <span>Kayıt Tarihi</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-actions">
                        <span>İşlemler</span>
                    </th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (!empty($customers)): ?>
                    <?php foreach ($customers as $customer): ?>
                        <?php 
                        $row_class = '';
                        switch ($customer->status) {
                            case 'aktif':
                                $row_class = 'status-active';
                                break;
                            case 'pasif':
                                $row_class = 'status-inactive';
                                break;
                            case 'belirsiz':
                                $row_class = 'status-uncertain';
                                break;
                        }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td class="column-name">
                                <strong>
                                    <a href="?page=insurance-crm-customers&action=view&id=<?php echo $customer->id; ?>" class="row-title">
                                        <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                                    </a>
                                </strong>
                            </td>
                            <td class="column-tc"><?php echo esc_html($customer->tc_identity); ?></td>
                            <td class="column-contact">
                                <span class="dashicons dashicons-email-alt"></span> <?php echo esc_html($customer->email); ?><br>
                                <span class="dashicons dashicons-phone"></span> <?php echo esc_html($customer->phone); ?>
                            </td>
                            <td class="column-category">
                                <?php 
                                echo $customer->category == 'bireysel' ? 'Bireysel' : 'Kurumsal';
                                ?>
                            </td>
                            <td class="column-status">
                                <?php 
                                $status_class = '';
                                $status_text = '';
                                
                                switch ($customer->status) {
                                    case 'aktif':
                                        $status_class = 'status-badge status-active';
                                        $status_text = 'Aktif';
                                        break;
                                    case 'pasif':
                                        $status_class = 'status-badge status-inactive';
                                        $status_text = 'Pasif';
                                        break;
                                    case 'belirsiz':
                                        $status_class = 'status-badge status-uncertain';
                                        $status_text = 'Belirsiz';
                                        break;
                                    default:
                                        $status_text = ucfirst($customer->status);
                                }
                                ?>
                                <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td class="column-representative">
                                <?php echo !empty($customer->representative_name) ? esc_html($customer->representative_name) : '—'; ?>
                            </td>
                            <td class="column-date">
                                <?php echo date('d.m.Y', strtotime($customer->created_at)); ?>
                            </td>
                            <td class="column-actions">
                                <a href="?page=insurance-crm-customers&action=view&id=<?php echo $customer->id; ?>" class="button button-small" title="Görüntüle">
                                    <span class="dashicons dashicons-visibility"></span>
                                </a>
                                <a href="?page=insurance-crm-customers&action=edit&id=<?php echo $customer->id; ?>" class="button button-small" title="Düzenle">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                                <?php if ($customer->status !== 'pasif'): ?>
                                <a href="<?php echo wp_nonce_url('?page=insurance-crm-customers&action=delete&id=' . $customer->id, 'delete_customer_' . $customer->id); ?>" 
                                   class="button button-small" 
                                   onclick="return confirm('Bu müşteriyi pasif duruma getirmek istediğinizden emin misiniz?');" 
                                   title="Pasif Yap">
                                    <span class="dashicons dashicons-marker"></span>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">Hiçbir müşteri bulunamadı.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom">
            <div class="alignleft actions">
                <select name="bulk-action2">
                    <option value="-1">Toplu İşlemler</option>
                    <option value="activate">Aktif Yap</option>
                    <option value="deactivate">Pasif Yap</option>
                </select>
                <input type="submit" name="doaction2" id="doaction2" class="button action" value="Uygula">
            </div>
            
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> öğe</span>
                <span class="pagination-links"><?php echo $page_links; ?></span>
            </div>
        </div>
    </form>
</div>

<style>
.status-badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}
.status-active {
    background-color: #ecf7ed;
    color: #46b450;
}
.status-inactive {
    background-color: #f7ecec;
    color: #dc3232;
}
.status-uncertain {
    background-color: #fff8e5;
    color: #ffb900;
}

tr.status-inactive {
    opacity: 0.7;
}

.column-actions .dashicons {
    margin-top: 3px;
}
</style>