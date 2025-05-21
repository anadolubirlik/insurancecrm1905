<?php
/**
 * Frontend Görev Yönetim Sayfası
 * @version 2.1.0
 */

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Değişkenleri tanımla
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
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

// Yönetici yetkisini kontrol et
$is_admin = current_user_can('administrator') || current_user_can('insurance_manager');

// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Görev silme işlemi
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_task_' . $task_id)) {
        if (!$is_admin) {
            $notice = '<div class="ab-notice ab-error">Görev silme yetkisine sahip değilsiniz. Sadece yöneticiler görev silebilir.</div>';
        } else {
            $delete_result = $wpdb->delete($tasks_table, array('id' => $task_id), array('%d'));
            
            if ($delete_result !== false) {
                $notice = '<div class="ab-notice ab-success">Görev başarıyla silindi.</div>';
            } else {
                $notice = '<div class="ab-notice ab-error">Görev silinirken bir hata oluştu.</div>';
            }
        }
    }
}

// Görev tamamlama işlemi
if (isset($_GET['action']) && $_GET['action'] === 'complete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'complete_task_' . $task_id)) {
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tasks_table WHERE id = %d", $task_id));
        
        // Temsilci yetkisi kontrolü
        $can_complete = true;
        if (!$is_admin && $task->representative_id != $current_user_rep_id) {
            $can_complete = false;
            $notice = '<div class="ab-notice ab-error">Bu görevi tamamlama yetkiniz yok.</div>';
        }
        
        if ($can_complete) {
            $update_result = $wpdb->update(
                $tasks_table,
                array('status' => 'completed', 'updated_at' => current_time('mysql')),
                array('id' => $task_id)
            );
            
            if ($update_result !== false) {
                $notice = '<div class="ab-notice ab-success">Görev başarıyla tamamlandı olarak işaretlendi.</div>';
            } else {
                $notice = '<div class="ab-notice ab-error">Görev güncellenirken bir hata oluştu.</div>';
            }
        }
    }
}

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// Filtre parametrelerini al ve sanitize et
$filters = array(
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0,
    'priority' => isset($_GET['priority']) ? sanitize_text_field($_GET['priority']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'task_description' => isset($_GET['task_description']) ? sanitize_text_field($_GET['task_description']) : '',
    'due_date' => isset($_GET['due_date']) ? sanitize_text_field($_GET['due_date']) : '', // Tarih filtresi
);

// Sorgu oluştur
$base_query = "FROM $tasks_table t 
               LEFT JOIN $customers_table c ON t.customer_id = c.id
               LEFT JOIN $policies_table p ON t.policy_id = p.id
               LEFT JOIN $representatives_table r ON t.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Temsilci yetkisi kontrolü
if (!$is_admin && $current_user_rep_id) {
    $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
}

// Filtreleri ekle
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND t.customer_id = %d", $filters['customer_id']);
}

if (!empty($filters['priority'])) {
    $base_query .= $wpdb->prepare(" AND t.priority = %s", $filters['priority']);
}

if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND t.status = %s", $filters['status']);
}

if (!empty($filters['task_description'])) {
    $base_query .= $wpdb->prepare(
        " AND (t.task_description LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR p.policy_number LIKE %s)",
        '%' . $wpdb->esc_like($filters['task_description']) . '%',
        '%' . $wpdb->esc_like($filters['task_description']) . '%',
        '%' . $wpdb->esc_like($filters['task_description']) . '%',
        '%' . $wpdb->esc_like($filters['task_description']) . '%'
    );
}

// Tarih filtresi ekle
if (!empty($filters['due_date'])) {
    $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $filters['due_date']);
}

// Toplam görev sayısını al
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT t.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 't.due_date';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'ASC';

// Görevleri getir
$tasks = $wpdb->get_results("
    SELECT t.*, 
           c.first_name, c.last_name,
           p.policy_number,
           u.display_name AS rep_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Müşterileri al
$customers = $wpdb->get_results("
    SELECT id, first_name, last_name 
    FROM $customers_table
    WHERE status = 'aktif'
    ORDER BY first_name, last_name
");

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Belirli bir gün için görev var mı kontrolü (dashboard'dan geldiğimizde)
$has_tasks_for_date = false;
$selected_date_formatted = '';
$no_tasks_message = '';

if (!empty($filters['due_date'])) {
    $selected_date = new DateTime($filters['due_date']);
    $selected_date_formatted = $selected_date->format('d.m.Y');
    $has_tasks_for_date = !empty($tasks);
    
    if (!$has_tasks_for_date) {
        $no_tasks_message = '<div class="ab-empty-state">
            <i class="fas fa-calendar-day"></i>
            <h3>' . $selected_date_formatted . ' tarihi için görev bulunamadı</h3>
            <p>Bu tarih için henüz görev ataması yapılmamış.</p>
            <a href="?view=tasks&action=new&due_date=' . $filters['due_date'] . '" class="ab-btn ab-btn-primary">
                <i class="fas fa-plus"></i> Yeni Görev Ekle
            </a>
        </div>';
    }
}
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container" id="tasks-list-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    <?php echo $notice; ?>

    <!-- Header -->
    <div class="ab-crm-header">
        <h1><i class="fas fa-tasks"></i> Görevler <?php echo !empty($filters['due_date']) ? '- <span class="selected-date">' . $selected_date_formatted . '</span>' : ''; ?></h1>
        <a href="?view=tasks&action=new<?php echo !empty($filters['due_date']) ? '&due_date=' . $filters['due_date'] : ''; ?>" class="ab-btn ab-btn-primary">
            <i class="fas fa-plus"></i> Yeni Görev
        </a>
    </div>
    
    <!-- Filtreler -->
    <div class="ab-filter-toggle-container">
        <button type="button" id="toggle-tasks-filters" class="ab-btn ab-toggle-filters">
            <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
        </button>
        
        <?php 
        $active_filter_count = 0;
        foreach ($filters as $key => $value) {
            if (!empty($value)) $active_filter_count++;
        }
        ?>
        
        <?php if ($active_filter_count > 0): ?>
        <div class="ab-active-filters">
            <span><?php echo $active_filter_count; ?> aktif filtre</span>
            <a href="?view=tasks" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="ab-crm-filters <?php echo ($active_filter_count == 0) ? 'ab-filters-hidden' : ''; ?>" id="tasks-filters-container">
        <form method="get" id="tasks-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="tasks">
            
            <div class="ab-form-row">
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" <?php selected($filters['customer_id'], $customer->id); ?>>
                                <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="priority">Öncelik</label>
                    <select name="priority" id="priority" class="ab-select">
                        <option value="">Tüm Öncelikler</option>
                        <option value="low" <?php selected($filters['priority'], 'low'); ?>>Düşük</option>
                        <option value="medium" <?php selected($filters['priority'], 'medium'); ?>>Orta</option>
                        <option value="high" <?php selected($filters['priority'], 'high'); ?>>Yüksek</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Beklemede</option>
                        <option value="in_progress" <?php selected($filters['status'], 'in_progress'); ?>>İşlemde</option>
                        <option value="completed" <?php selected($filters['status'], 'completed'); ?>>Tamamlandı</option>
                        <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>İptal Edildi</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="due_date">Son Tarih</label>
                    <input type="date" name="due_date" id="due_date" class="ab-input" value="<?php echo esc_attr($filters['due_date']); ?>">
                </div>
                
                <div class="ab-filter-col ab-search-col">
                    <label for="task_description">Görev Açıklaması</label>
                    <div class="ab-search-box">
                        <input type="text" name="task_description" id="task_description" value="<?php echo esc_attr($filters['task_description']); ?>" placeholder="Görev Ara...">
                        <button type="submit" class="ab-btn-search">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn">Filtrele</button>
                    <a href="?view=tasks" class="ab-btn ab-btn-secondary">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Belirli bir gün için görev yoksa mesaj göster -->
    <?php if (!empty($no_tasks_message)): ?>
        <?php echo $no_tasks_message; ?>
    <?php elseif (!empty($tasks)): ?>
    <!-- Tablo -->
    <div class="ab-crm-table-wrapper">
        <div class="ab-crm-table-info">
            <?php if (!empty($filters['due_date'])): ?>
                <span><?php echo $selected_date_formatted; ?> tarihinde toplam <?php echo $total_items; ?> görev</span>
            <?php else: ?>
                <span>Toplam: <?php echo $total_items; ?> görev</span>
            <?php endif; ?>
        </div>
        
        <table class="ab-crm-table">
            <thead>
                <tr>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 't.task_description', 'order' => $order === 'ASC' && $orderby === 't.task_description' ? 'DESC' : 'ASC')); ?>">
                            Görev <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Müşteri</th>
                    <th>Poliçe</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 't.due_date', 'order' => $order === 'ASC' && $orderby === 't.due_date' ? 'DESC' : 'ASC')); ?>">
                            Son Tarih <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 't.priority', 'order' => $order === 'ASC' && $orderby === 't.priority' ? 'DESC' : 'ASC')); ?>">
                            Öncelik <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 't.status', 'order' => $order === 'ASC' && $orderby === 't.status' ? 'DESC' : 'ASC')); ?>">
                            Durum <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Temsilci</th>
                    <th class="ab-actions-column">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): 
                    $is_overdue = strtotime($task->due_date) < time() && $task->status !== 'completed';
                    
                    // Durumu ve önceliğine göre satır stilini belirle
                    $row_class = '';
                    if ($is_overdue) {
                        $row_class = 'overdue';
                    }
                    
                    // Durum sınıfını da ekleyelim
                    switch ($task->status) {
                        case 'completed':
                            $row_class .= ' task-completed';
                            break;
                        case 'in_progress':
                            $row_class .= ' task-in-progress';
                            break;
                        case 'cancelled':
                            $row_class .= ' task-cancelled';
                            break;
                        default:
                            $row_class .= ' task-pending';
                    }
                    
                    // Önceliğe göre ek sınıf ekle
                    $row_class .= ' priority-' . $task->priority;
                ?>
                    <tr class="<?php echo trim($row_class); ?>">
                        <td>
                            <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" class="ab-task-description">
                                <?php echo esc_html($task->task_description); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="ab-badge ab-badge-danger">Gecikmiş!</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!empty($task->customer_id)): ?>
                            <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="ab-customer-link">
                                <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                            </a>
                            <?php else: ?>
                                <span class="ab-no-value">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="ab-policy-link">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                            <?php else: ?>
                                <span class="ab-no-value">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ab-date-cell">
                            <span class="ab-due-date <?php echo $is_overdue ? 'overdue' : ''; ?>">
                                <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?>
                            </span>
                        </td>
                        <td>
                            <span class="ab-badge ab-badge-priority-<?php echo esc_attr($task->priority); ?>">
                                <?php 
                                switch ($task->priority) {
                                    case 'low': echo 'Düşük'; break;
                                    case 'medium': echo 'Orta'; break;
                                    case 'high': echo 'Yüksek'; break;
                                    default: echo ucfirst($task->priority); break;
                                }
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="ab-badge ab-badge-task-<?php echo esc_attr($task->status); ?>">
                                <?php 
                                switch ($task->status) {
                                    case 'pending': echo 'Beklemede'; break;
                                    case 'in_progress': echo 'İşlemde'; break;
                                    case 'completed': echo 'Tamamlandı'; break;
                                    case 'cancelled': echo 'İptal Edildi'; break;
                                    default: echo ucfirst($task->status); break;
                                }
                                ?>
                            </span>
                        </td>
                        <td><?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="ab-no-value">—</span>'; ?></td>
                        <td class="ab-actions-cell">
                            <div class="ab-actions">
                                <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" title="Görüntüle" class="ab-action-btn">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" title="Düzenle" class="ab-action-btn">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <?php if ($task->status !== 'completed' && ($is_admin || $task->representative_id == $current_user_rep_id)): ?>
                                    <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task->id, 'complete_task_' . $task->id); ?>" 
                                       title="Tamamla" class="ab-action-btn ab-action-complete"
                                       onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                                        <i class="fas fa-check"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($is_admin): ?>
                                    <a href="<?php echo wp_nonce_url('?view=tasks&action=delete&id=' . $task->id, 'delete_task_' . $task->id); ?>" 
                                       class="ab-action-btn ab-action-danger" title="Sil"
                                       onclick="return confirm('Bu görevi silmek istediğinizden emin misiniz?');">
                                        <i class="fas fa-trash"></i>
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
    <?php elseif (empty($no_tasks_message)): ?>
    <div class="ab-empty-state">
        <i class="fas fa-tasks"></i>
        <h3>Görev bulunamadı</h3>
        <p>Arama kriterlerinize uygun görev bulunamadı.</p>
        <a href="?view=tasks" class="ab-btn">Tüm Görevleri Göster</a>
    </div>
    <?php endif; ?>
</div>

<?php
// Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            if (isset($_GET['id'])) {
                include_once('task-view.php');
            }
            break;
        case 'new':
        case 'edit':
            include_once('task-form.php');
            break;
    }
}
?>

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

.selected-date {
    font-weight: 500;
    color: #4caf50;
    padding: 3px 8px;
    background-color: #f0fff4;
    border-radius: 4px;
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

/* Filtreleme Toggle - Yeni Eklenen CSS */
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

/* Filtreler */
.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #eee;
    padding: 12px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.ab-filter-col {
    flex: 1;
    min-width: 120px;
}

.ab-filter-col label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #444;
}

.ab-search-col {
    flex: 2;
    min-width: 200px;
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

.ab-select, .ab-input {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 32px;
}

.ab-button-col {
    display: flex;
    gap: 8px;
    align-items: center;
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

/* Görev durumuna göre satır stilleri */
tr.task-pending td {
    background-color: #ffffff !important;
}

tr.task-in-progress td {
    background-color: #f8f9ff !important; /* Açık mavi */
}

tr.task-in-progress td:first-child {
    border-left: 3px solid #2196f3; /* Mavi kenar */
}

tr.task-completed td {
    background-color: #f0fff0 !important; /* Açık yeşil */
}

tr.task-completed td:first-child {
    border-left: 3px solid #4caf50; /* Yeşil kenar */
}

tr.task-cancelled td {
    background-color: #f5f5f5 !important; /* Gri */
}

tr.task-cancelled td:first-child {
    border-left: 3px solid #9e9e9e; /* Gri kenar */
}

/* Önceliğe göre vurgulama */
tr.priority-high td:first-child {
    border-left-width: 5px !important; /* Daha kalın kenar yüksek öncelik için */
}

/* Gecikmiş görevler */
tr.overdue td {
    background-color: #fff2f2 !important; /* Pembe arka plan */
}

tr.overdue td:first-child {
    border-left: 3px solid #e53935 !important; /* Kırmızı kenar */
}

.ab-task-description {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-task-description:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link, .ab-policy-link {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-link:hover, .ab-policy-link:hover {
    text-decoration: underline;
    color: #135e96;
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

/* Tarih hücresi */
.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

/* Son Tarih Stili */
.ab-due-date {
    white-space: nowrap;
}

.ab-due-date.overdue {
    color: #cb2431;
    font-weight: 500;
}

/* Boş değer gösterimi */
.ab-no-value {
    color: #999;
    font-style: italic;
    font-size: 12px;
}

/* Badge ve Durum Stilleri */
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

/* Öncelik badge'leri */
.ab-badge-priority-low {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-priority-medium {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-high {
    background-color: #ffeef0;
    color: #cb2431;
}

/* Durum badge'leri */
.ab-badge-task-pending {
    background-color: #f1f8ff;
    color: #0366d6;
}

.ab-badge-task-in_progress {
    background-color: #fff8e5; 
    color: #bf8700;
}

.ab-badge-task-completed {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-task-cancelled {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-danger {
    background-color: #ffeef0;
    color: #cb2431;
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

.ab-action-complete:hover {
    background-color: #e6ffed;
    color: #22863a;
    border-color: #c3e6cb;
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

/* Geri dön butonu - task-view.php ve task-form.php için */
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

/* Animasyonlar */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Mobil Uyumluluk - Geliştirilmiş */
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
    
    /* Bazı kolonları gizle */
    .ab-crm-table th:nth-child(2),
    .ab-crm-table td:nth-child(2) {
        display: none; /* Müşteri kolonunu gizle */
    }
    
    .ab-crm-table th:nth-child(7),
    .ab-crm-table td:nth-child(7) {
        display: none; /* Temsilci kolonunu gizle */
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
    
    .ab-filter-col {
        width: 100%;
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
    
    /* Daha fazla kolonu küçük ekranlarda gizle */
    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none; /* Poliçe kolonunu gizle */
    }
    
    .ab-crm-table th:nth-child(5),
    .ab-crm-table td:nth-child(5) {
        display: none; /* Öncelik kolonunu gizle */
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
    
    .ab-crm-table th:nth-child(6),
    .ab-crm-table td:nth-child(6) {
        display: none; /* Durum kolonunu gizle */
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filtreleme toggle kontrolü
    const toggleFiltersBtn = document.getElementById('toggle-tasks-filters');
    const filtersContainer = document.getElementById('tasks-filters-container');
    
    if (toggleFiltersBtn && filtersContainer) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersContainer.classList.toggle('ab-filters-hidden');
            toggleFiltersBtn.classList.toggle('active');
        });
    }

    // Filtre Formu Submit Kontrolü
    const filterForm = document.querySelector('#tasks-filter');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            const inputs = filterForm.querySelectorAll('input, select');
            let hasValue = false;
            inputs.forEach(input => {
                if (input.value.trim() && input.name !== 'view') {
                    hasValue = true;
                }
            });
            if (!hasValue) {
                e.preventDefault();
                alert('Lütfen en az bir filtre kriteri girin.');
            }
        });
    }
    
    // Tarih seçiminde tarihi form alanına otomatik ekle
    const urlParams = new URLSearchParams(window.location.search);
    const dueDateParam = urlParams.get('due_date');
    const dueDateInput = document.getElementById('due_date');
    
    if (dueDateParam && dueDateInput && !dueDateInput.value) {
        dueDateInput.value = dueDateParam;
    }
    
    // Başlıktaki tarih formatını güzelleştir
    const selectedDateSpan = document.querySelector('.selected-date');
    if (selectedDateSpan) {
        try {
            const parts = selectedDateSpan.innerText.split('-');
            if (parts.length === 3) {
                const formattedDate = `${parts[2]}.${parts[1]}.${parts[0]}`;
                selectedDateSpan.innerText = formattedDate;
            }
        } catch (e) {
            console.error('Tarih formatı düzenlenirken hata oluştu:', e);
        }
    }
});
</script>


