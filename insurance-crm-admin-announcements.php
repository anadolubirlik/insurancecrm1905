<?php
/**
 * Genel Duyurular Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @version    1.1.1 - Modal stili güzelleştirildi, okundu işaretleme düzeltildi
 */

if (!defined('WPINC')) {
    die;
}

// Yetki kontrolü
if (!current_user_can('manage_options')) {
    $is_admin = false;
} else {
    $is_admin = true;
}

// Aksiyon kontrolü
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
$announcement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = ($action === 'edit' && $announcement_id > 0);
$adding = ($action === 'new');

// Filtreleme parametreleri
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
$is_read_filter = isset($_GET['is_read']) ? sanitize_text_field($_GET['is_read']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Form gönderildiğinde işlem yap
if (isset($_POST['submit_announcement']) && isset($_POST['announcement_nonce']) && 
    wp_verify_nonce($_POST['announcement_nonce'], 'add_edit_announcement')) {
    
    $announcement_data = array(
        'user_id' => 0, // Genel duyuru olduğu için user_id 0
        'type' => sanitize_text_field($_POST['type']),
        'category' => sanitize_text_field($_POST['category']),
        'title' => sanitize_text_field($_POST['title']),
        'message' => sanitize_textarea_field($_POST['message']),
        'related_id' => !empty($_POST['related_id']) ? intval($_POST['related_id']) : null,
        'related_type' => !empty($_POST['related_type']) ? sanitize_text_field($_POST['related_type']) : null,
        'is_read' => 0, // Yeni duyuru okunmamış olarak başlar
        'expires_at' => !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) : null,
    );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_notifications';
    
    if ($editing && isset($_POST['announcement_id'])) {
        // Mevcut duyuruyu güncelle
        $update_result = $wpdb->update(
            $table_name,
            array(
                'type' => $announcement_data['type'],
                'category' => $announcement_data['category'],
                'title' => $announcement_data['title'],
                'message' => $announcement_data['message'],
                'related_id' => $announcement_data['related_id'],
                'related_type' => $announcement_data['related_type'],
                'expires_at' => $announcement_data['expires_at'],
                'created_at' => current_time('mysql')
            ),
            array('id' => intval($_POST['announcement_id']), 'user_id' => 0)
        );
        
        if ($update_result !== false) {
            echo '<div class="notice notice-success"><p>Duyuru başarıyla güncellendi.</p></div>';
            echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-announcements&updated=1') . '";</script>';
        } else {
            echo '<div class="notice notice-error"><p>Duyuru güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
        }
    } else {
        // Yeni duyuru ekle
        $insert_result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $announcement_data['user_id'],
                'type' => $announcement_data['type'],
                'category' => $announcement_data['category'],
                'title' => $announcement_data['title'],
                'message' => $announcement_data['message'],
                'related_id' => $announcement_data['related_id'],
                'related_type' => $announcement_data['related_type'],
                'is_read' => $announcement_data['is_read'],
                'expires_at' => $announcement_data['expires_at'],
                'created_at' => current_time('mysql')
            )
        );
        
        if ($insert_result !== false) {
            // Aktif kullanıcılara e-posta bildirimi gönder
            $users = get_users(array('role__in' => array('administrator', 'editor', 'author', 'contributor', 'subscriber')));
            $subject = 'Yeni Genel Duyuru: ' . $announcement_data['title'];
            $message = "Merhaba,\n\nYeni bir genel duyuru yayınlandı:\n\n";
            $message .= "Başlık: " . $announcement_data['title'] . "\n";
            $message .= "Mesaj: " . $announcement_data['message'] . "\n\n";
            $message .= "Detayları görmek için lütfen panele giriş yapın: " . home_url('/crm-panel?view=notifications');
            
            foreach ($users as $user) {
                wp_mail($user->user_email, $subject, $message);
            }
            
            echo '<div class="notice notice-success"><p>Duyuru başarıyla eklendi ve kullanıcılara e-posta bildirimi gönderildi.</p></div>';
            echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-announcements&added=1') . '";</script>';
        } else {
            echo '<div class="notice notice-error"><p>Duyuru eklenirken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
        }
    }
}

// Silme işlemi
if ($action === 'delete' && $announcement_id > 0) {
    if (!$is_admin) {
        echo '<div class="notice notice-error"><p>Duyuru silme yetkisine sahip değilsiniz. Sadece yöneticiler duyuru silebilir.</p></div>';
    } else {
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_announcement_' . $announcement_id)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'insurance_crm_notifications';
            
            $delete_result = $wpdb->delete($table_name, array('id' => $announcement_id, 'user_id' => 0), array('%d', '%d'));
            
            if ($delete_result !== false) {
                echo '<div class="notice notice-success"><p>Duyuru başarıyla silindi.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Duyuru silinirken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Geçersiz silme işlemi.</p></div>';
        }
    }
}

// İşlem mesajları
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    echo '<div class="notice notice-success"><p>Duyuru başarıyla güncellendi.</p></div>';
}

if (isset($_GET['added']) && $_GET['added'] === '1') {
    echo '<div class="notice notice-success"><p>Yeni duyuru başarıyla eklendi.</p></div>';
}

// Düzenlenecek duyurunun verilerini al
$edit_announcement = null;
if ($editing) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_notifications';
    
    $edit_announcement = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d AND user_id = 0",
        $announcement_id
    ));
    
    if (!$edit_announcement) {
        echo '<div class="notice notice-error"><p>Düzenlenmek istenen duyuru bulunamadı.</p></div>';
        $editing = false;
    }
}

// Müşterileri al (related_id için kullanılabilir)
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("SELECT id, CONCAT(first_name, ' ', last_name) as customer_name FROM $customers_table WHERE status = 'aktif' ORDER BY first_name, last_name");

// Poliçeleri al (related_id için kullanılabilir)
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policies = $wpdb->get_results("SELECT id, policy_number FROM $policies_table WHERE status = 'aktif' ORDER BY id DESC");

// Duyuruları listele
$announcements_table = $wpdb->prefix . 'insurance_crm_notifications';
$query = "
    SELECT n.*,
           c.first_name, c.last_name,
           p.policy_number,
           nr.read_at
    FROM $announcements_table n
    LEFT JOIN $customers_table c ON n.related_id = c.id AND n.related_type = 'customer'
    LEFT JOIN $policies_table p ON n.related_id = p.id AND n.related_type = 'policy'
    LEFT JOIN {$wpdb->prefix}insurance_crm_notification_reads nr ON n.id = nr.notification_id
    WHERE n.user_id = 0
";

// Filtreleme
$where_conditions = array();
$where_values = array();

if (!empty($type_filter)) {
    $where_conditions[] = "n.type = %s";
    $where_values[] = $type_filter;
}

if (!empty($category_filter)) {
    $where_conditions[] = "n.category = %s";
    $where_values[] = $category_filter;
}

if ($is_read_filter !== '') {
    if ($is_read_filter === '1') {
        $where_conditions[] = "nr.read_at IS NOT NULL";
    } else {
        $where_conditions[] = "nr.read_at IS NULL";
    }
}

if ($status_filter === 'active') {
    $where_conditions[] = "(n.expires_at IS NULL OR n.expires_at >= %s)";
    $where_values[] = current_time('mysql');
} elseif ($status_filter === 'expired') {
    $where_conditions[] = "n.expires_at IS NOT NULL AND n.expires_at < %s";
    $where_values[] = current_time('mysql');
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY n.created_at DESC";

if (!empty($where_values)) {
    $prepared_query = $wpdb->prepare($query, $where_values);
    $announcements = $wpdb->get_results($prepared_query);
} else {
    $announcements = $wpdb->get_results($query);
}

// Okunma durumunu belirle
if (!empty($announcements)) {
    foreach ($announcements as $announcement) {
        $announcement->is_read = !is_null($announcement->read_at) ? 1 : 0;
    }
} else {
    $announcements = [];
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Genel Duyurular</h1>
    <a href="<?php echo admin_url('admin.php?page=insurance-crm-announcements&action=new'); ?>" class="page-title-action">Yeni Ekle</a>
    
    <hr class="wp-header-end">

    <?php if (!$editing && !$adding): ?>
    <!-- DUYURULAR LİSTESİ -->
    <div class="insurance-crm-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="insurance-crm-announcements">
            
            <div class="filter-row">
                <div class="filter-item">
                    <label for="type">Tür:</label>
                    <select name="type" id="type">
                        <option value="">Tüm Türler</option>
                        <option value="general" <?php selected($type_filter, 'general'); ?>>Genel</option>
                        <option value="update" <?php selected($type_filter, 'update'); ?>>Güncelleme</option>
                        <option value="alert" <?php selected($type_filter, 'alert'); ?>>Uyarı</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label for="category">Kategori:</label>
                    <select name="category" id="category">
                        <option value="">Tüm Kategoriler</option>
                        <option value="system" <?php selected($category_filter, 'system'); ?>>Sistem</option>
                        <option value="campaign" <?php selected($category_filter, 'campaign'); ?>>Kampanya</option>
                        <option value="warning" <?php selected($category_filter, 'warning'); ?>>Uyarı</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label for="is_read">Okunma Durumu:</label>
                    <select name="is_read" id="is_read">
                        <option value="">Tüm Durumlar</option>
                        <option value="1" <?php selected($is_read_filter, '1'); ?>>Okundu</option>
                        <option value="0" <?php selected($is_read_filter, '0'); ?>>Okunmadı</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label for="status">Geçerlilik Durumu:</label>
                    <select name="status" id="status">
                        <option value="">Tüm Durumlar</option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>>Aktif</option>
                        <option value="expired" <?php selected($status_filter, 'expired'); ?>>Süresi Dolmuş</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <input type="submit" class="button" value="Filtrele">
                    <?php if (!empty($type_filter) || !empty($category_filter) || $is_read_filter !== '' || $status_filter !== ''): ?>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-announcements'); ?>" class="button">Filtreleri Temizle</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    
    <div class="insurance-crm-table-container">
        <table class="wp-list-table widefat fixed striped announcements">
            <thead>
                <tr>
                    <th width="3%">ID</th>
                    <th width="15%">Başlık</th>
                    <th width="20%">Mesaj</th>
                    <th width="10%">Tür</th>
                    <th width="10%">Kategori</th>
                    <th width="12%">İlgili Nesne</th>
                    <th width="10%">Oluşturulma Tarihi</th>
                    <th width="10%">Geçerlilik Süresi</th>
                    <th width="10%">Okunma Durumu</th>
                    <th width="12%">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($announcements)): ?>
                    <tr>
                        <td colspan="10">Hiç duyuru bulunamadı.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement): ?>
                    <tr class="<?php echo $announcement->is_read ? '' : 'unread'; ?>" data-announcement-id="<?php echo $announcement->id; ?>">
                        <td><?php echo esc_html($announcement->id); ?></td>
                        <td>
                            <a href="#" class="announcement-link">
                                <?php echo esc_html($announcement->title); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($announcement->message); ?></td>
                        <td>
                            <span class="announcement-type type-<?php echo esc_attr($announcement->type); ?>">
                                <?php 
                                    switch ($announcement->type) {
                                        case 'general': echo 'Genel'; break;
                                        case 'update': echo 'Güncelleme'; break;
                                        case 'alert': echo 'Uyarı'; break;
                                        default: echo esc_html($announcement->type); break;
                                    }
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="announcement-category category-<?php echo esc_attr($announcement->category); ?>">
                                <?php 
                                    switch ($announcement->category) {
                                        case 'system': echo 'Sistem'; break;
                                        case 'campaign': echo 'Kampanya'; break;
                                        case 'warning': echo 'Uyarı'; break;
                                        default: echo esc_html($announcement->category ?: '—'); break;
                                    }
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            if ($announcement->related_type === 'customer' && $announcement->first_name) {
                                echo esc_html($announcement->first_name . ' ' . $announcement->last_name);
                            } elseif ($announcement->related_type === 'policy' && $announcement->policy_number) {
                                echo esc_html($announcement->policy_number);
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td><?php echo date_i18n('d.m.Y H:i', strtotime($announcement->created_at)); ?></td>
                        <td>
                            <?php 
                            if ($announcement->expires_at) {
                                $expires_at = strtotime($announcement->expires_at);
                                $now = current_time('timestamp');
                                if ($expires_at < $now) {
                                    echo '<span style="color: #e53935;">Süresi Doldu (' . date_i18n('d.m.Y H:i', $expires_at) . ')</span>';
                                } else {
                                    echo date_i18n('d.m.Y H:i', $expires_at);
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="announcement-status status-<?php echo $announcement->is_read ? 'read' : 'unread'; ?>">
                                <?php echo $announcement->is_read ? 'Okundu' : 'Okunmadı'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=insurance-crm-announcements&action=edit&id=' . $announcement->id)); ?>" 
                               class="button button-small">
                                Düzenle
                            </a>
                            
                            <?php if ($is_admin): ?>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=insurance-crm-announcements&action=delete&id=' . $announcement->id), 'delete_announcement_' . $announcement->id)); ?>" 
                               class="button button-small delete-announcement" 
                               onclick="return confirm('Bu duyuruyu silmek istediğinizden emin misiniz?');">
                                Sil
                            </a>
                            <?php else: ?>
                            <button class="button button-small" disabled title="Sadece yöneticiler silme yetkisine sahiptir">Sil</button>
                            <?php endif; ?>
                            <?php if (!$announcement->is_read): ?>
                                <a href="#" class="button button-small mark-read-action" 
                                   data-announcement-id="<?php echo $announcement->id; ?>" 
                                   data-nonce="<?php echo wp_create_nonce('mark_notification_read'); ?>" 
                                   title="Okundu İşaretle">
                                    Okundu
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if ($editing || $adding): ?>
    <!-- DUYURU DÜZENLEME / EKLEME FORMU -->
    <div class="insurance-crm-form-container">
        <h2><?php echo $editing ? 'Duyuru Düzenle' : 'Yeni Duyuru Ekle'; ?></h2>
        <form method="post" action="" class="insurance-crm-form">
            <?php wp_nonce_field('add_edit_announcement', 'announcement_nonce'); ?>
            
            <?php if ($editing): ?>
                <input type="hidden" name="announcement_id" value="<?php echo esc_attr($announcement_id); ?>">
            <?php endif; ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="type">Tür <span class="required">*</span></label></th>
                    <td>
                        <select name="type" id="type" class="regular-text" required>
                            <option value="general" <?php echo $editing && $edit_announcement->type == 'general' ? 'selected' : ''; ?>>Genel</option>
                            <option value="update" <?php echo $editing && $edit_announcement->type == 'update' ? 'selected' : ''; ?>>Güncelleme</option>
                            <option value="alert" <?php echo $editing && $edit_announcement->type == 'alert' ? 'selected' : ''; ?>>Uyarı</option>
                        </select>
                        <div class="type-preview">
                            <span class="announcement-type"></span>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="category">Kategori</label></th>
                    <td>
                        <select name="category" id="category" class="regular-text">
                            <option value="">Kategori Seçin (Opsiyonel)</option>
                            <option value="system" <?php echo $editing && $edit_announcement->category == 'system' ? 'selected' : ''; ?>>Sistem</option>
                            <option value="campaign" <?php echo $editing && $edit_announcement->category == 'campaign' ? 'selected' : ''; ?>>Kampanya</option>
                            <option value="warning" <?php echo $editing && $edit_announcement->category == 'warning' ? 'selected' : ''; ?>>Uyarı</option>
                        </select>
                        <div class="category-preview">
                            <span class="announcement-category"></span>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="title">Başlık <span class="required">*</span></label></th>
                    <td>
                        <input type="text" name="title" id="title" class="regular-text" 
                               value="<?php echo $editing ? esc_attr($edit_announcement->title) : ''; ?>" required>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="message">Mesaj <span class="required">*</span></label></th>
                    <td>
                        <textarea name="message" id="message" class="large-text" rows="4" required><?php echo $editing ? esc_textarea($edit_announcement->message) : ''; ?></textarea>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="related_type">İlgili Nesne Türü</label></th>
                    <td>
                        <select name="related_type" id="related_type" class="regular-text">
                            <option value="">İlgili Nesne Yok</option>
                            <option value="customer" <?php echo $editing && $edit_announcement->related_type == 'customer' ? 'selected' : ''; ?>>Müşteri</option>
                            <option value="policy" <?php echo $editing && $edit_announcement->related_type == 'policy' ? 'selected' : ''; ?>>Poliçe</option>
                        </select>
                    </td>
                </tr>
                
                <tr class="related-id-row" style="display: none;">
                    <th><label for="related_id">İlgili Nesne</label></th>
                    <td>
                        <select name="related_id" id="related_id" class="regular-text">
                            <option value="">Seçin</option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="expires_at">Geçerlilik Süresi</label></th>
                    <td>
                        <input type="datetime-local" name="expires_at" id="expires_at" class="regular-text" 
                               value="<?php echo $editing && $edit_announcement->expires_at ? date('Y-m-d\TH:i', strtotime($edit_announcement->expires_at)) : ''; ?>">
                        <p class="description">Duyurunun geçerli olacağı son tarihi ve saati belirtin. Boş bırakılırsa, süresiz olarak aktif kalır.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_announcement" class="button button-primary" 
                       value="<?php echo $editing ? 'Duyuruyu Güncelle' : 'Duyuru Ekle'; ?>">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-announcements'); ?>" class="button">İptal</a>
            </p>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Bildirim Önizleme Modal -->
<div id="announcement-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="modal-close">&times;</span>
        <h2 id="modal-title"></h2>
        <div class="modal-body">
            <p><strong>Tür:</strong> <span id="modal-type"></span></p>
            <p><strong>Kategori:</strong> <span id="modal-category"></span></p>
            <p><strong>Mesaj:</strong> <span id="modal-message"></span></p>
            <p><strong>İlgili Nesne:</strong> <span id="modal-related"></span></p>
            <p><strong>Tarih:</strong> <span id="modal-date"></span></p>
            <p><strong>Durum:</strong> <span id="modal-status"></span></p>
        </div>
        <div class="modal-actions">
            <button id="modal-mark-read" class="button button-primary" style="display: none;" data-nonce="<?php echo wp_create_nonce('mark_notification_read'); ?>">Okundu İşaretle</button>
            <button class="button modal-close-btn">Kapat</button>
        </div>
    </div>
</div>

<style>
/* Tablo konteyner stili */
.insurance-crm-table-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-top: 20px;
    margin-bottom: 20px;
    overflow-x: auto;
}

/* Form konteyner stili */
.insurance-crm-form-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-top: 20px;
}

/* Filtre stili */
.insurance-crm-filters {
    background: #fff;
    padding: 15px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 15px;
}

.filter-item {
    min-width: 150px;
}

.filter-item label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

/* Tür etiketleri */
.announcement-type {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 3px;
    font-weight: 500;
    font-size: 12px;
    text-align: center;
    min-width: 80px;
}

.type-general {
    background-color: #e9f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.type-update {
    background-color: #e3f2fd;
    color: #1976d2;
    border: 1px solid #bbdefb;
}

.type-alert {
    background-color: #ffeaed;
    color: #e53935;
    border: 1px solid #ef9a9a;
}

/* Kategori etiketleri */
.announcement-category {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 3px;
    font-weight: 500;
    font-size: 12px;
    text-align: center;
    min-width: 80px;
}

.category-system {
    background-color: #e3f2fd;
    color: #1976d2;
    border: 1px solid #bbdefb;
}

.category-campaign {
    background-color: #e9f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.category-warning {
    background-color: #ffeaed;
    color: #e53935;
    border: 1px solid #ef9a9a;
}

/* Durum etiketleri */
.announcement-status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 3px;
    font-weight: 500;
    font-size: 12px;
    text-align: center;
    min-width: 80px;
}

.status-read {
    background-color: #e9f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.status-unread {
    background-color: #fff9e6;
    color: #f9a825;
    border: 1px solid #ffe082;
}

/* Önizleme alanları */
.type-preview, .category-preview {
    display: inline-block;
    margin-left: 10px;
}

/* Gerekli alan işareti */
.required {
    color: #dc3232;
}

/* Modal Stilleri */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.6);
    animation: fadeIn 0.3s ease-in-out;
}

.modal-content {
    background-color: #fff;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    position: relative;
    animation: slideIn 0.3s ease-in-out;
}

.modal-close {
    position: absolute;
    top: 15px;
    right: 15px;
    color: #666;
    font-size: 20px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.2s ease;
}

.modal-close:hover {
    color: #000;
}

.modal-content h2 {
    font-size: 20px;
    margin: 0 0 15px;
    color: #333;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-body p {
    margin: 8px 0;
    font-size: 14px;
    color: #444;
}

.modal-body p strong {
    display: inline-block;
    width: 120px;
    font-weight: 600;
    color: #555;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.modal-actions .button {
    padding: 8px 16px;
    font-size: 14px;
    border-radius: 4px;
    transition: background-color 0.2s ease, transform 0.1s ease;
}

.modal-actions .button-primary {
    background-color: #0073aa;
    border-color: #006799;
    color: #fff;
}

.modal-actions .button-primary:hover {
    background-color: #005d82;
    transform: translateY(-1px);
}

.modal-actions .modal-close-btn {
    background-color: #f1f1f1;
    border-color: #ccc;
    color: #333;
}

.modal-actions .modal-close-btn:hover {
    background-color: #e0e0e0;
    transform: translateY(-1px);
}

.announcement-link {
    cursor: pointer;
    color: #2271b1;
    text-decoration: none;
}

.announcement-link:hover {
    text-decoration: underline;
    color: #135e96;
}

/* Animasyonlar */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive düzenlemeler */
@media screen and (max-width: 782px) {
    .filter-row {
        flex-direction: column;
    }
    .filter-item {
        width: 100%;
    }

    .modal-content {
        width: 95%;
        margin: 15% auto;
        padding: 15px;
    }

    .modal-content h2 {
        font-size: 18px;
    }

    .modal-body p {
        font-size: 13px;
    }

    .modal-body p strong {
        width: 100px;
    }

    .modal-actions {
        flex-direction: column;
        gap: 8px;
    }

    .modal-actions .button {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Tür değiştiğinde önizleme güncelle
    function updateTypePreview() {
        var type = $('#type').val();
        var typeText = $('#type option:selected').text();
        $('.type-preview .announcement-type')
            .removeClass('type-general type-update type-alert')
            .addClass('type-' + type)
            .text(typeText);
    }
    
    // Kategori değiştiğinde önizleme güncelle
    function updateCategoryPreview() {
        var category = $('#category').val();
        var categoryText = $('#category option:selected').text();
        $('.category-preview .announcement-category')
            .removeClass('category-system category-campaign category-warning')
            .addClass(category ? 'category-' + category : '')
            .text(category ? categoryText : '—');
    }
    
    // İlgili nesne türü değiştiğinde ilgili nesne seçimini göster/gizle
    $('#related_type').change(function() {
        var relatedType = $(this).val();
        var relatedIdSelect = $('#related_id');
        relatedIdSelect.empty();
        
        if (relatedType === '') {
            $('.related-id-row').hide();
        } else {
            $('.related-id-row').show();
            
            if (relatedType === 'customer') {
                // Müşterileri yükle
                relatedIdSelect.append('<option value="">Müşteri Seçin</option>');
                <?php foreach ($customers as $customer): ?>
                    relatedIdSelect.append('<option value="<?php echo esc_attr($customer->id); ?>" <?php echo $editing && $edit_announcement->related_type == 'customer' && $edit_announcement->related_id == $customer->id ? 'selected' : ''; ?>><?php echo esc_js($customer->customer_name); ?></option>');
                <?php endforeach; ?>
            } else if (relatedType === 'policy') {
                // Poliçeleri yükle
                relatedIdSelect.append('<option value="">Poliçe Seçin</option>');
                <?php foreach ($policies as $policy): ?>
                    relatedIdSelect.append('<option value="<?php echo esc_attr($policy->id); ?>" <?php echo $editing && $edit_announcement->related_type == 'policy' && $edit_announcement->related_id == $policy->id ? 'selected' : ''; ?>><?php echo esc_js($policy->policy_number); ?></option>');
                <?php endforeach; ?>
            }
        }
    });
    
    // Sayfa yüklendiğinde türü, kategoriyi ve ilgili nesneyi başlat
    updateTypePreview();
    updateCategoryPreview();
    $('#related_type').trigger('change');
    
    // Tür ve kategori değiştiğinde önizlemeyi güncelle
    $('#type').change(updateTypePreview);
    $('#category').change(updateCategoryPreview);
    
    // Duyuru formu doğrulama
    $('.insurance-crm-form').on('submit', function(e) {
        var type = $('#type').val();
        var title = $('#title').val();
        var message = $('#message').val();
        
        if (!type || !title || !message) {
            e.preventDefault();
            alert('Lütfen zorunlu alanları doldurun!');
            return false;
        }
        
        return true;
    });

    // Modal açma/kapama
    const modal = document.getElementById('announcement-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalType = document.getElementById('modal-type');
    const modalCategory = document.getElementById('modal-category');
    const modalMessage = document.getElementById('modal-message');
    const modalRelated = document.getElementById('modal-related');
    const modalDate = document.getElementById('modal-date');
    const modalStatus = document.getElementById('modal-status');
    const modalMarkReadBtn = document.getElementById('modal-mark-read');
    const closeButtons = document.querySelectorAll('.modal-close, .modal-close-btn');

    // Duyuru satırlarına tıklama olayı ekle
    const announcementLinks = document.querySelectorAll('.announcement-link');
    announcementLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            const announcementId = row.dataset.announcementId;

            // Satırdaki bilgileri al
            const title = this.textContent;
            const message = row.cells[2].textContent;
            const type = row.cells[3].querySelector('.announcement-type').textContent;
            const category = row.cells[4].querySelector('.announcement-category').textContent;
            const related = row.cells[5].textContent;
            const date = row.cells[6].textContent;
            const status = row.cells[8].querySelector('.announcement-status').textContent;
            const isRead = row.classList.contains('unread') ? false : true;

            // Modal içeriğini doldur
            modalTitle.textContent = title;
            modalType.textContent = type;
            modalCategory.textContent = category;
            modalMessage.textContent = message;
            modalRelated.textContent = related;
            modalDate.textContent = date;
            modalStatus.textContent = status;

            // Okundu işaretleme butonunu göster/gizle
            if (!isRead) {
                modalMarkReadBtn.style.display = 'inline-flex';
                modalMarkReadBtn.dataset.announcementId = announcementId;
            } else {
                modalMarkReadBtn.style.display = 'none';
            }

            // Modal'ı aç
            modal.style.display = 'block';
        });
    });

    // Modal kapatma
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    });

    // Modal dışında bir yere tıklandığında kapatma
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });

    // Modal'dan Okundu İşaretle
    if (modalMarkReadBtn) {
        modalMarkReadBtn.addEventListener('click', function() {
            const announcementId = this.dataset.announcementId;
            const nonce = this.dataset.nonce;

            console.log('AJAX isteği gönderiliyor: action=mark_notification_read, announcement_id=' + announcementId + ', nonce=' + nonce);

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=mark_notification_read&notification_id=${announcementId}&nonce=${nonce}`
            })
            .then(response => {
                console.log('AJAX yanıtı alındı:', response);
                return response.json();
            })
            .then(data => {
                console.log('AJAX veri:', data);
                if (data.success) {
                    const row = document.querySelector(`tr[data-announcement-id="${announcementId}"]`);
                    row.classList.remove('unread');
                    const statusBadge = row.querySelector('.announcement-status');
                    if (statusBadge) {
                        statusBadge.textContent = 'Okundu';
                        statusBadge.classList.remove('status-unread');
                        statusBadge.classList.add('status-read');
                    }
                    const actionBtn = row.querySelector('.mark-read-action');
                    if (actionBtn) actionBtn.remove();
                    modalMarkReadBtn.style.display = 'none';
                    modalStatus.textContent = 'Okundu';

                    // Bildirim sayısını güncelle
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        let count = parseInt(badge.textContent) - 1;
                        if (count <= 0) {
                            badge.remove();
                        } else {
                            badge.textContent = count;
                        }
                    }
                    alert('Duyuru okundu olarak işaretlendi.');
                } else {
                    alert('Hata: ' + (data.data.message || 'İşlem başarısız.'));
                }
            })
            .catch(error => {
                console.error('AJAX hatası:', error);
                alert('Bir hata oluştu: ' + error.message);
            });
        });
    }

    // Tek bir bildirimi okundu olarak işaretleme (tablodan)
    const markReadButtons = document.querySelectorAll('.mark-read-action');
    markReadButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const announcementId = this.getAttribute('data-announcement-id');
            const nonce = this.getAttribute('data-nonce');
            
            console.log('AJAX isteği gönderiliyor: action=mark_notification_read, announcement_id=' + announcementId + ', nonce=' + nonce);
            
            if (confirm('Bu duyuruyu okundu olarak işaretlemek istediğinize emin misiniz?')) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=mark_notification_read&notification_id=${announcementId}&nonce=${nonce}`
                })
                .then(response => {
                    console.log('AJAX yanıtı alındı:', response);
                    return response.json();
                })
                .then(data => {
                    console.log('AJAX veri:', data);
                    if (data.success) {
                        const notificationItem = button.closest('tr');
                        notificationItem.classList.remove('unread');
                        const statusBadge = notificationItem.querySelector('.announcement-status');
                        if (statusBadge) {
                            statusBadge.textContent = 'Okundu';
                            statusBadge.classList.remove('status-unread');
                            statusBadge.classList.add('status-read');
                        }
                        button.remove();
                        // Bildirim sayısını güncelle
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            let count = parseInt(badge.textContent) - 1;
                            if (count <= 0) {
                                badge.remove();
                            } else {
                                badge.textContent = count;
                            }
                        }
                        alert('Duyuru okundu olarak işaretlendi.');
                    } else {
                        alert('Hata: ' + (data.data.message || 'İşlem başarısız.'));
                    }
                })
                .catch(error => {
                    console.error('AJAX hatası:', error);
                    alert('Bir hata oluştu: ' + error.message);
                });
            }
        });
    });
});
</script>