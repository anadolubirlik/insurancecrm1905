<?php
/**
 * Müşteri Detay Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @version    1.0.6
 */

if (!defined('WPINC')) {
    die;
}

// Müşteri ID kontrolü
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customer_id) {
    echo '<div class="notice notice-error"><p>Geçersiz müşteri ID\'si.</p></div>';
    return;
}

// Müşteri bilgilerini al
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customer = $wpdb->get_row($wpdb->prepare("
    SELECT c.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           r.id AS rep_id,
           u.display_name AS rep_name
    FROM $customers_table c
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE c.id = %d
", $customer_id));

if (!$customer) {
    echo '<div class="notice notice-error"><p>Müşteri bulunamadı.</p></div>';
    return;
}

// Müşterinin poliçelerini al
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policies = $wpdb->get_results($wpdb->prepare("
    SELECT p.*,
           r.id AS rep_id,
           u.display_name AS rep_name
    FROM $policies_table p
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE p.customer_id = %d
    ORDER BY p.end_date ASC
", $customer_id));

// Müşterinin görevlerini al
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$tasks = $wpdb->get_results($wpdb->prepare("
    SELECT t.*,
           p.policy_number,
           r.id AS rep_id,
           u.display_name AS rep_name
    FROM $tasks_table t
    LEFT JOIN $policies_table p ON t.policy_id = p.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE t.customer_id = %d
    ORDER BY t.due_date ASC
", $customer_id));

// Müşteri görüşme notlarını al
$notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
$customer_notes = $wpdb->get_results($wpdb->prepare("
    SELECT n.*, 
           u.display_name AS user_name
    FROM $notes_table n
    LEFT JOIN {$wpdb->users} u ON n.created_by = u.ID
    WHERE n.customer_id = %d
    ORDER BY n.created_at DESC
", $customer_id));

// Bugünün tarihi
$today = date('Y-m-d H:i:s');

// Not ekleme işlemi
if (isset($_POST['add_customer_note']) && isset($_POST['note_nonce']) && 
    wp_verify_nonce($_POST['note_nonce'], 'add_customer_note')) {
    
    $note_data = array(
        'customer_id' => $customer_id,
        'note_content' => sanitize_textarea_field($_POST['note_content']),
        'note_type' => sanitize_text_field($_POST['note_type']),
        'created_by' => get_current_user_id(),
        'created_at' => current_time('mysql')
    );
    
    // Eğer olumsuz not ise sebep alanını da ekle
    if ($note_data['note_type'] === 'negative') {
        $note_data['rejection_reason'] = sanitize_text_field($_POST['rejection_reason']);
        
        // Müşteri durumunu Pasif olarak güncelle
        $wpdb->update(
            $customers_table,
            array('status' => 'pasif'),
            array('id' => $customer_id)
        );
    }
    // Olumlu not ise müşteriyi aktif yap
    else if ($note_data['note_type'] === 'positive') {
        $wpdb->update(
            $customers_table,
            array('status' => 'aktif'),
            array('id' => $customer_id)
        );
    }
    
    $insert_result = $wpdb->insert(
        $notes_table,
        $note_data
    );
    
    if ($insert_result !== false) {
        echo '<div class="notice notice-success"><p>Görüşme notu başarıyla eklendi.</p></div>';
        echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-customers&action=view&id=' . $customer_id . '&note_added=1') . '";</script>';
    } else {
        echo '<div class="notice notice-error"><p>Görüşme notu eklenirken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
    }
}

// İşlem mesajları
if (isset($_GET['note_added']) && $_GET['note_added'] === '1') {
    echo '<div class="notice notice-success"><p>Görüşme notu başarıyla eklendi.</p></div>';
}

// Görünüm tercihini kaydet
$view_preference = 'modern';
if(isset($_GET['view'])) {
    $view_preference = sanitize_text_field($_GET['view']);
}

// Kullanıcı görünüm tercihleri
$user_id = get_current_user_id();
if(isset($_GET['set_view'])) {
    $set_view = sanitize_text_field($_GET['set_view']);
    if($set_view === 'modern' || $set_view === 'classic') {
        update_user_meta($user_id, 'insurance_crm_view_preference', $set_view);
        $view_preference = $set_view;
    }
}
else {
    $saved_preference = get_user_meta($user_id, 'insurance_crm_view_preference', true);
    if(!empty($saved_preference)) {
        $view_preference = $saved_preference;
    }
}

?>

<div class="wrap insurance-crm-customer-details">
    <!-- Müşteri Başlık Bilgileri -->
    <div class="customer-header">
        <h1 class="wp-heading-inline">
            <i class="dashicons dashicons-admin-users"></i>
            <?php echo esc_html($customer->customer_name); ?>
            <span class="customer-status status-<?php echo esc_attr($customer->status); ?>">
                <?php echo $customer->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
            </span>
        </h1>
        
        <div class="customer-meta">
            <span class="customer-category category-<?php echo esc_attr($customer->category); ?>">
                <?php echo $customer->category === 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
            </span>
            <span class="customer-ref">ID: <?php echo esc_html($customer_id); ?></span>
            <span class="customer-rep">
                <i class="dashicons dashicons-businessman"></i> 
                <?php echo isset($customer->rep_name) ? esc_html($customer->rep_name) : 'Atanmamış'; ?>
            </span>
        </div>
    </div>
    
    <!-- Müşteri Eylemleri -->
    <div class="customer-actions">
        <div class="action-buttons">
            <a href="<?php echo admin_url('admin.php?page=insurance-crm-customers&action=edit&id=' . $customer_id); ?>" class="button button-primary">
                <i class="dashicons dashicons-edit"></i> Düzenle
            </a>
            <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=new&customer=' . $customer_id); ?>" class="button">
                <i class="dashicons dashicons-calendar-alt"></i> Yeni Görev
            </a>
            <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=new&customer=' . $customer_id); ?>" class="button">
                <i class="dashicons dashicons-shield"></i> Yeni Poliçe
            </a>
            <a href="<?php echo admin_url('admin.php?page=insurance-crm-customers'); ?>" class="button">
                <i class="dashicons dashicons-arrow-left-alt"></i> Müşteri Listesi
            </a>
        </div>
        
        <!-- Görünüm Seçim Düğmeleri -->
        <div class="view-toggle">
            <span>Görünüm:</span>
            <div class="toggle-buttons">
                <a href="<?php echo add_query_arg('set_view', 'modern', remove_query_arg('note_added')); ?>" 
                   class="toggle-button <?php echo $view_preference === 'modern' ? 'active' : ''; ?>" 
                   data-view="modern">
                    <i class="dashicons dashicons-grid-view"></i> Kutucuk
                </a>
                <a href="<?php echo add_query_arg('set_view', 'classic', remove_query_arg('note_added')); ?>" 
                   class="toggle-button <?php echo $view_preference === 'classic' ? 'active' : ''; ?>" 
                   data-view="classic">
                    <i class="dashicons dashicons-list-view"></i> Klasik
                </a>
            </div>
        </div>
    </div>
    
    <!-- MODERN GÖRÜNÜM -->
    <div id="modern-view" class="customer-view" <?php echo $view_preference === 'classic' ? 'style="display:none;"' : ''; ?>>
        <!-- Ana İçerik Grid -->
        <div class="customer-grid">
            <!-- KİŞİSEL BİLGİLER -->
            <div class="card personal-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-admin-users"></i> Kişisel Bilgiler</h2>
                </div>
                
                <div class="card-content">
                    <div class="customer-info-grid">
                        <div class="info-item">
                            <div class="label">Ad Soyad</div>
                            <div class="value"><?php echo esc_html($customer->customer_name); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">TC Kimlik No</div>
                            <div class="value"><?php echo esc_html($customer->tc_identity); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">E-posta</div>
                            <div class="value">
                                <a href="mailto:<?php echo esc_attr($customer->email); ?>">
                                    <i class="dashicons dashicons-email"></i>
                                    <?php echo esc_html($customer->email); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Telefon</div>
                            <div class="value">
                                <a href="tel:<?php echo esc_attr($customer->phone); ?>">
                                    <i class="dashicons dashicons-phone"></i>
                                    <?php echo esc_html($customer->phone); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="info-item wide">
                            <div class="label">Adres</div>
                            <div class="value address"><?php echo nl2br(esc_html($customer->address)); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Doğum Tarihi</div>
                            <div class="value">
                                <?php echo !empty($customer->birth_date) ? date('d.m.Y', strtotime($customer->birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Cinsiyet</div>
                            <div class="value">
                                <?php 
                                if (!empty($customer->gender)) {
                                    echo $customer->gender == 'male' ? 'Erkek' : 'Kadın';
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Meslek</div>
                            <div class="value">
                                <?php echo !empty($customer->occupation) ? esc_html($customer->occupation) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Kayıt Tarihi</div>
                            <div class="value"><?php echo date('d.m.Y H:i', strtotime($customer->created_at)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- AİLE BİLGİLERİ -->
            <div class="card family-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-groups"></i> Aile Bilgileri</h2>
                </div>
                
                <div class="card-content">
                    <div class="family-info-grid">
                        <div class="info-item">
                            <div class="label">Eş</div>
                            <div class="value">
                                <?php echo !empty($customer->spouse_name) ? esc_html($customer->spouse_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Eşin Doğum Tarihi</div>
                            <div class="value">
                                <?php echo !empty($customer->spouse_birth_date) ? date('d.m.Y', strtotime($customer->spouse_birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Çocuk Sayısı</div>
                            <div class="value">
                                <?php echo isset($customer->children_count) && $customer->children_count > 0 ? $customer->children_count : '<span class="no-value">0</span>'; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($customer->children_names) || !empty($customer->children_birth_dates)): ?>
                        <div class="info-item wide">
                            <div class="label">Çocuklar</div>
                            <div class="value">
                                <?php
                                $children_names = !empty($customer->children_names) ? explode(',', $customer->children_names) : [];
                                $children_birth_dates = !empty($customer->children_birth_dates) ? explode(',', $customer->children_birth_dates) : [];
                                
                                if (!empty($children_names)) {
                                    echo '<ul class="children-list">';
                                    for ($i = 0; $i < count($children_names); $i++) {
                                        echo '<li>' . esc_html(trim($children_names[$i]));
                                        if (isset($children_birth_dates[$i]) && !empty(trim($children_birth_dates[$i]))) {
                                            echo ' - Doğum: ' . date('d.m.Y', strtotime(trim($children_birth_dates[$i])));
                                        }
                                        echo '</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- VARLIK BİLGİLERİ - EV -->
            <div class="card asset-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-admin-home"></i> Ev Bilgileri</h2>
                </div>
                
                <div class="card-content">
                    <div class="asset-info">
                        <div class="info-item">
                            <div class="label">Evi Kendisine mi Ait?</div>
                            <div class="value">
                                <?php 
                                if (isset($customer->owns_home)) {
                                    echo $customer->owns_home == 1 ? 'Evet' : 'Hayır';
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php if (isset($customer->owns_home) && $customer->owns_home == 1): ?>
                        <div class="info-item">
                            <div class="label">DASK Poliçesi</div>
                            <div class="value">
                                <?php 
                                if (isset($customer->has_dask_policy)) {
                                    if ($customer->has_dask_policy == 1) {
                                        echo '<span class="status-positive">Var</span>';
                                        if (!empty($customer->dask_policy_expiry)) {
                                            echo ' (Vade: ' . date('d.m.Y', strtotime($customer->dask_policy_expiry)) . ')';
                                        }
                                    } else {
                                        echo '<span class="status-negative">Yok</span>';
                                    }
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="label">Konut Poliçesi</div>
                            <div class="value">
                                <?php 
                                if (isset($customer->has_home_policy)) {
                                    if ($customer->has_home_policy == 1) {
                                        echo '<span class="status-positive">Var</span>';
                                        if (!empty($customer->home_policy_expiry)) {
                                            echo ' (Vade: ' . date('d.m.Y', strtotime($customer->home_policy_expiry)) . ')';
                                        }
                                    } else {
                                        echo '<span class="status-negative">Yok</span>';
                                    }
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- VARLIK BİLGİLERİ - ARAÇ -->
            <div class="card asset-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-car"></i> Araç Bilgileri</h2>
                </div>
                
                <div class="card-content">
                    <div class="asset-info">
                        <div class="info-item">
                            <div class="label">Aracı Var mı?</div>
                            <div class="value">
                                <?php 
                                if (isset($customer->has_vehicle)) {
                                    echo $customer->has_vehicle == 1 ? 'Evet' : 'Hayır';
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php if (isset($customer->has_vehicle) && $customer->has_vehicle == 1): ?>
                        <div class="info-item">
                            <div class="label">Araç Plakası</div>
                            <div class="value">
                                <?php echo !empty($customer->vehicle_plate) ? esc_html($customer->vehicle_plate) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- VARLIK BİLGİLERİ - EVCİL HAYVAN -->
            <div class="card asset-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-buddicons-activity"></i> Evcil Hayvan</h2>
                </div>
                
                <div class="card-content">
                    <div class="asset-info">
                        <div class="info-item">
                            <div class="label">Evcil Hayvanı Var mı?</div>
                            <div class="value">
                                <?php 
                                if (isset($customer->has_pet)) {
                                    echo $customer->has_pet == 1 ? 'Evet' : 'Hayır';
                                } else {
                                    echo '<span class="no-value">Belirtilmemiş</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php if (isset($customer->has_pet) && $customer->has_pet == 1): ?>
                        <div class="info-item">
                            <div class="label">Evcil Hayvan Bilgisi</div>
                            <div class="value">
                                <?php
                                $pet_info = [];
                                if (!empty($customer->pet_name)) {
                                    $pet_info[] = 'Adı: ' . esc_html($customer->pet_name);
                                }
                                if (!empty($customer->pet_type)) {
                                    $pet_info[] = 'Cinsi: ' . esc_html($customer->pet_type);
                                }
                                if (!empty($customer->pet_age)) {
                                    $pet_info[] = 'Yaşı: ' . esc_html($customer->pet_age);
                                }
                                
                                echo !empty($pet_info) ? implode(', ', $pet_info) : '<span class="no-value">Detay belirtilmemiş</span>';
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- POLİÇELER SEKSİYONU -->
            <div class="card wide-card policy-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-shield"></i> Poliçeler</h2>
                    <div class="card-header-actions">
                        <span class="badge"><?php echo count($policies); ?> poliçe</span>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=new&customer=' . $customer_id); ?>" class="button button-small">
                            <i class="dashicons dashicons-plus-alt"></i> Yeni Poliçe
                        </a>
                    </div>
                </div>
                
                <div class="card-content">
                    <?php if (empty($policies)): ?>
                        <div class="empty-message">
                            <i class="dashicons dashicons-shield"></i>
                            <p>Henüz poliçe bulunmuyor.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-container policy-table">
                        <table class="wp-list-table widefat fixed striped policies">
                            <thead>
                                <tr>
                                    <th class="column-policy">Poliçe No/Tür</th>
                                    <th class="column-dates">Tarih Aralığı</th>
                                    <th class="column-premium">Prim</th>
                                    <th class="column-status">Durum</th>
                                    <th class="column-actions">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($policies as $policy):
                                    $is_expired = strtotime($policy->end_date) < time();
                                    $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60); // 30 gün
                                    $row_class = $is_expired ? 'expired' : ($is_expiring_soon ? 'expiring-soon' : '');
                                ?>
                                    <tr class="<?php echo esc_attr($row_class); ?>">
                                        <td class="column-policy">
                                            <div class="policy-number">
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </div>
                                            <div class="policy-type">
                                                <?php echo esc_html($policy->policy_type); ?>
                                            </div>
                                            <?php if ($is_expired): ?>
                                                <div class="expiry-badge expired-badge">Süresi Dolmuş</div>
                                            <?php elseif ($is_expiring_soon): ?>
                                                <div class="expiry-badge expiring-soon-badge">Yakında Bitiyor</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-dates">
                                            <div class="date-range">
                                                <div class="start-date"><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></div>
                                                <div class="end-date"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></div>
                                            </div>
                                        </td>
                                        <td class="column-premium">
                                            <div class="premium-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> ₺</div>
                                        </td>
                                        <td class="column-status">
                                            <span class="policy-status status-<?php echo esc_attr($policy->status); ?>">
                                                <?php echo esc_html($policy->status); ?>
                                            </span>
                                        </td>
                                        <td class="column-actions">
                                            <div class="row-actions">
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . $policy->id); ?>" class="button button-small" title="Düzenle">
                                                    <i class="dashicons dashicons-edit"></i>
                                                </a>
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=renew&id=' . $policy->id); ?>" class="button button-small" title="Yenile">
                                                    <i class="dashicons dashicons-update"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- GÖREVLER SEKSİYONU -->
            <div class="card wide-card tasks-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-calendar-alt"></i> Görevler</h2>
                    <div class="card-header-actions">
                        <span class="badge"><?php echo count($tasks); ?> görev</span>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=new&customer=' . $customer_id); ?>" class="button button-small">
                            <i class="dashicons dashicons-plus-alt"></i> Yeni Görev
                        </a>
                    </div>
                </div>
                
                <div class="card-content">
                    <?php if (empty($tasks)): ?>
                        <div class="empty-message">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <p>Henüz görev bulunmuyor.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-container tasks-table">
                        <table class="wp-list-table widefat fixed striped tasks">
                            <thead>
                                <tr>
                                    <th class="column-desc">Görev Açıklaması</th>
                                    <th class="column-date">Son Tarih</th>
                                    <th class="column-priority">Öncelik</th>
                                    <th class="column-status">Durum</th>
                                    <th class="column-actions">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task):
                                    $is_overdue = strtotime($task->due_date) < strtotime($today) && $task->status != 'completed';
                                ?>
                                    <tr <?php echo $is_overdue ? 'class="overdue-task"' : ''; ?>>
                                        <td class="column-desc">
                                            <div class="task-description">
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=edit&id=' . $task->id); ?>">
                                                    <?php echo esc_html($task->task_description); ?>
                                                </a>
                                                <?php if ($is_overdue): ?>
                                                    <span class="overdue-badge">Gecikmiş!</span>
                                                <?php endif; ?>
                                                <?php if ($task->policy_number): ?>
                                                    <div class="task-meta">Poliçe: <?php echo esc_html($task->policy_number); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="column-date">
                                            <?php echo date('d.m.Y', strtotime($task->due_date)); ?>
                                        </td>
                                        <td class="column-priority">
                                            <span class="task-priority priority-<?php echo esc_attr($task->priority); ?>">
                                                <?php 
                                                    switch ($task->priority) {
                                                        case 'low': echo 'Düşük'; break;
                                                        case 'medium': echo 'Orta'; break;
                                                        case 'high': echo 'Yüksek'; break;
                                                        default: echo $task->priority; break;
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="column-status">
                                            <span class="task-status status-<?php echo esc_attr($task->status); ?>">
                                                <?php 
                                                    switch ($task->status) {
                                                        case 'pending': echo 'Beklemede'; break;
                                                        case 'in_progress': echo 'İşlemde'; break;
                                                        case 'completed': echo 'Tamamlandı'; break;
                                                        case 'cancelled': echo 'İptal Edildi'; break;
                                                        default: echo $task->status; break;
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="column-actions">
                                            <div class="row-actions">
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=edit&id=' . $task->id); ?>" class="button button-small" title="Düzenle">
                                                    <i class="dashicons dashicons-edit"></i>
                                                </a>
                                                <?php if ($task->status != 'completed'): ?>
                                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=complete&id=' . $task->id); ?>" class="button button-small button-complete" title="Tamamla">
                                                    <i class="dashicons dashicons-yes"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- GÖRÜŞME NOTLARI -->
            <div class="card wide-card notes-card">
                <div class="card-header">
                    <h2><i class="dashicons dashicons-format-chat"></i> Görüşme Notları</h2>
                    <span class="badge"><?php echo count($customer_notes); ?> not</span>
                </div>
                
                <div class="card-content">
                    <!-- Not Ekleme Formu -->
                    <div class="add-note-container">
                        <div class="add-note-toggle">
                            <button type="button" class="button" id="toggle-note-form">
                                <i class="dashicons dashicons-plus-alt"></i> Yeni Görüşme Notu Ekle
                            </button>
                        </div>
                        
                        <div class="add-note-form-container" style="display:none;">
                            <h3><i class="dashicons dashicons-format-chat"></i> Yeni Görüşme Notu Ekle</h3>
                            
                            <form method="post" action="" class="add-note-form">
                                <?php wp_nonce_field('add_customer_note', 'note_nonce'); ?>
                                
                                <div class="form-row">
                                    <label for="note_content">Görüşme Notu</label>
                                    <textarea name="note_content" id="note_content" rows="4" required placeholder="Görüşme detaylarını buraya girin..."></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-row">
                                        <label for="note_type">Görüşme Sonucu</label>
                                        <select name="note_type" id="note_type" required>
                                            <option value="">Seçiniz</option>
                                            <option value="positive">Olumlu</option>
                                            <option value="neutral">Durumu Belirsiz</option>
                                            <option value="negative">Olumsuz</option>
                                        </select>
                                    </div>
                                    
                                    <div id="rejection_reason_container" class="form-row hidden">
                                        <label for="rejection_reason">Olumsuz Olma Nedeni</label>
                                        <select name="rejection_reason" id="rejection_reason">
                                            <option value="">Seçiniz</option>
                                            <option value="price">Fiyat</option>
                                            <option value="wrong_application">Yanlış Başvuru</option>
                                            <option value="existing_policy">Mevcut Poliçesi Var</option>
                                            <option value="other">Diğer</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-buttons">
                                    <button type="button" id="cancel-note" class="button">
                                        <i class="dashicons dashicons-no-alt"></i> İptal
                                    </button>
                                    <button type="submit" name="add_customer_note" class="button button-primary">
                                        <i class="dashicons dashicons-plus-alt"></i> Not Ekle
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Notlar Listesi -->
                    <div class="notes-list">
                        <?php if (empty($customer_notes)): ?>
                            <div class="empty-message">
                                <i class="dashicons dashicons-format-chat"></i>
                                <p>Henüz görüşme notu bulunmuyor.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($customer_notes as $note): ?>
                                <div class="note-item note-type-<?php echo esc_attr($note->note_type); ?>">
                                    <div class="note-header">
                                        <div class="note-meta">
                                            <span class="note-author"><i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($note->user_name); ?></span>
                                            <span class="note-date"><i class="dashicons dashicons-calendar-alt"></i> <?php echo date('d.m.Y H:i', strtotime($note->created_at)); ?></span>
                                        </div>
                                        <span class="note-type-badge <?php echo esc_attr($note->note_type); ?>">
                                            <?php 
                                                switch ($note->note_type) {
                                                    case 'positive': echo '<i class="dashicons dashicons-yes-alt"></i> Olumlu'; break;
                                                    case 'neutral': echo '<i class="dashicons dashicons-minus"></i> Belirsiz'; break;
                                                    case 'negative': echo '<i class="dashicons dashicons-no-alt"></i> Olumsuz'; break;
                                                    default: echo ucfirst($note->note_type); break;
                                                }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="note-content">
                                        <?php echo nl2br(esc_html($note->note_content)); ?>
                                    </div>
                                    <?php if (isset($note->note_type) && $note->note_type === 'negative' && !empty($note->rejection_reason)): ?>
                                        <div class="note-reason">
                                            <strong>Sebep:</strong> 
                                            <?php 
                                                switch ($note->rejection_reason) {
                                                    case 'price': echo 'Fiyat'; break;
                                                    case 'wrong_application': echo 'Yanlış Başvuru'; break;
                                                    case 'existing_policy': echo 'Mevcut Poliçesi Var'; break;
                                                    case 'other': echo 'Diğer'; break;
                                                    default: echo ucfirst($note->rejection_reason); break;
                                                }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- KLASİK GÖRÜNÜM -->
    <div id="classic-view" class="customer-view" <?php echo $view_preference === 'modern' ? 'style="display:none;"' : ''; ?>>
        <div class="classic-container">
            <div class="classic-tabs">
                <div class="tab <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'customer-info' ? 'active' : ''; ?>" data-tab="customer-info">Müşteri Bilgileri</div>
                <div class="tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'customer-family' ? 'active' : ''; ?>" data-tab="customer-family">Aile Bilgileri</div>
                <div class="tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'customer-assets' ? 'active' : ''; ?>" data-tab="customer-assets">Varlık Bilgileri</div>
            </div>
            
            <div class="classic-tab-content <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'customer-info' ? 'active' : ''; ?>" id="customer-info-content">
                <h3>Kişisel Bilgiler</h3>
                <table class="classic-info-table">
                    <tr>
                        <th>Ad Soyad:</th>
                        <td><?php echo esc_html($customer->customer_name); ?></td>
                        <th>TC Kimlik No:</th>
                        <td><?php echo esc_html($customer->tc_identity); ?></td>
                    </tr>
                    <tr>
                        <th>E-posta:</th>
                        <td><?php echo esc_html($customer->email); ?></td>
                        <th>Telefon:</th>
                        <td><?php echo esc_html($customer->phone); ?></td>
                    </tr>
                    <tr>
                        <th>Adres:</th>
                        <td colspan="3"><?php echo nl2br(esc_html($customer->address)); ?></td>
                    </tr>
                    <tr>
                        <th>Doğum Tarihi:</th>
                        <td><?php echo !empty($customer->birth_date) ? date('d.m.Y', strtotime($customer->birth_date)) : 'Belirtilmemiş'; ?></td>
                        <th>Cinsiyet:</th>
                        <td><?php echo !empty($customer->gender) ? ($customer->gender == 'male' ? 'Erkek' : 'Kadın') : 'Belirtilmemiş'; ?></td>
                    </tr>
                    <tr>
                        <th>Meslek:</th>
                        <td><?php echo !empty($customer->occupation) ? esc_html($customer->occupation) : 'Belirtilmemiş'; ?></td>
                        <th>Kayıt Tarihi:</th>
                        <td><?php echo date('d.m.Y H:i', strtotime($customer->created_at)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="classic-tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'customer-family' ? 'active' : ''; ?>" id="customer-family-content">
                <h3>Aile Bilgileri</h3>
                <table class="classic-info-table">
                    <tr>
                        <th>Eş:</th>
                        <td><?php echo !empty($customer->spouse_name) ? esc_html($customer->spouse_name) : 'Belirtilmemiş'; ?></td>
                        <th>Eşin Doğum Tarihi:</th>
                        <td><?php echo !empty($customer->spouse_birth_date) ? date('d.m.Y', strtotime($customer->spouse_birth_date)) : 'Belirtilmemiş'; ?></td>
                    </tr>
                    <tr>
                        <th>Çocuk Sayısı:</th>
                        <td colspan="3"><?php echo isset($customer->children_count) && $customer->children_count > 0 ? $customer->children_count : '0'; ?></td>
                    </tr>
                    <?php if (!empty($customer->children_names) || !empty($customer->children_birth_dates)): ?>
                    <tr>
                        <th>Çocuklar:</th>
                        <td colspan="3">
                            <?php
                            $children_names = !empty($customer->children_names) ? explode(',', $customer->children_names) : [];
                            $children_birth_dates = !empty($customer->children_birth_dates) ? explode(',', $customer->children_birth_dates) : [];
                            
                            if (!empty($children_names)) {
                                echo '<ul class="children-list classic">';
                                for ($i = 0; $i < count($children_names); $i++) {
                                    echo '<li>' . esc_html(trim($children_names[$i]));
                                    if (isset($children_birth_dates[$i]) && !empty(trim($children_birth_dates[$i]))) {
                                        echo ' - Doğum: ' . date('d.m.Y', strtotime(trim($children_birth_dates[$i])));
                                    }
                                    echo '</li>';
                                }
                                echo '</ul>';
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <div class="classic-tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'customer-assets' ? 'active' : ''; ?>" id="customer-assets-content">
                <h3>Varlık Bilgileri</h3>
                
                <h4>Ev Bilgileri</h4>
                <table class="classic-info-table">
                    <tr>
                        <th>Evi Kendisine mi Ait?</th>
                        <td>
                            <?php 
                            if (isset($customer->owns_home)) {
                                echo $customer->owns_home == 1 ? 'Evet' : 'Hayır';
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if (isset($customer->owns_home) && $customer->owns_home == 1): ?>
                    <tr>
                        <th>DASK Poliçesi:</th>
                        <td>
                            <?php 
                            if (isset($customer->has_dask_policy)) {
                                if ($customer->has_dask_policy == 1) {
                                    echo 'Var';
                                    if (!empty($customer->dask_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->dask_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo 'Yok';
                                }
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Konut Poliçesi:</th>
                        <td>
                            <?php 
                            if (isset($customer->has_home_policy)) {
                                if ($customer->has_home_policy == 1) {
                                    echo 'Var';
                                    if (!empty($customer->home_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->home_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo 'Yok';
                                }
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <h4>Araç Bilgileri</h4>
                <table class="classic-info-table">
                    <tr>
                        <th>Aracı Var mı?</th>
                        <td>
                            <?php 
                            if (isset($customer->has_vehicle)) {
                                echo $customer->has_vehicle == 1 ? 'Evet' : 'Hayır';
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if (isset($customer->has_vehicle) && $customer->has_vehicle == 1): ?>
                    <tr>
                        <th>Araç Plakası:</th>
                        <td><?php echo !empty($customer->vehicle_plate) ? esc_html($customer->vehicle_plate) : 'Belirtilmemiş'; ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <h4>Evcil Hayvan Bilgileri</h4>
                <table class="classic-info-table">
                    <tr>
                        <th>Evcil Hayvanı Var mı?</th>
                        <td>
                            <?php 
                            if (isset($customer->has_pet)) {
                                echo $customer->has_pet == 1 ? 'Evet' : 'Hayır';
                            } else {
                                echo 'Belirtilmemiş';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if (isset($customer->has_pet) && $customer->has_pet == 1): ?>
                    <tr>
                        <th>Evcil Hayvan Bilgisi:</th>
                        <td>
                            <?php
                            $pet_info = [];
                            if (!empty($customer->pet_name)) {
                                $pet_info[] = 'Adı: ' . esc_html($customer->pet_name);
                            }
                            if (!empty($customer->pet_type)) {
                                $pet_info[] = 'Cinsi: ' . esc_html($customer->pet_type);
                            }
                            if (!empty($customer->pet_age)) {
                                $pet_info[] = 'Yaşı: ' . esc_html($customer->pet_age);
                            }
                            
                            echo !empty($pet_info) ? implode(', ', $pet_info) : 'Detay belirtilmemiş';
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <!-- POLİÇELER (KLASİK GÖRÜNÜM) -->
        <div class="classic-section">
            <div class="section-header">
                <div class="section-title">
                    <i class="dashicons dashicons-shield"></i> 
                    <h3>Poliçeler</h3> 
                    <span class="badge"><?php echo count($policies); ?> poliçe</span>
                </div>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=new&customer=' . $customer_id); ?>" class="button">
                    <i class="dashicons dashicons-plus-alt"></i> Yeni Poliçe Ekle
                </a>
            </div>
            
            <?php if (empty($policies)): ?>
                <div class="empty-message">Henüz poliçe bulunmuyor.</div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped classic-table policies">
                    <thead>
                        <tr>
                            <th>Poliçe No</th>
                            <th>Tür</th>
                            <th>Başlangıç</th>
                            <th>Bitiş</th>
                            <th>Prim</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($policies as $policy):
                            $is_expired = strtotime($policy->end_date) < time();
                            $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60); // 30 gün
                            $row_class = $is_expired ? 'expired' : ($is_expiring_soon ? 'expiring-soon' : '');
                        ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . $policy->id); ?>">
                                        <?php echo esc_html($policy->policy_number); ?>
                                    </a>
                                    <?php if ($is_expired): ?>
                                        <span class="expired-badge">Süresi Dolmuş</span>
                                    <?php elseif ($is_expiring_soon): ?>
                                        <span class="expiring-soon-badge">Yakında Bitiyor</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($policy->policy_type); ?></td>
                                <td><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></td>
                                <td><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                                <td class="amount"><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> ₺</td>
                                <td>
                                    <span class="policy-status status-<?php echo esc_attr($policy->status); ?>">
                                        <?php echo esc_html($policy->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="classic-actions">
                                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . $policy->id); ?>" class="button button-small">
                                            <i class="dashicons dashicons-edit"></i> Düzenle
                                        </a>
                                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=renew&id=' . $policy->id); ?>" class="button button-small">
                                            <i class="dashicons dashicons-update"></i> Yenile
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- GÖREVLER (KLASİK GÖRÜNÜM) -->
        <div class="classic-section">
            <div class="section-header">
                <div class="section-title">
                    <i class="dashicons dashicons-calendar-alt"></i> 
                    <h3>Görevler</h3>
                    <span class="badge"><?php echo count($tasks); ?> görev</span>
                </div>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=new&customer=' . $customer_id); ?>" class="button">
                    <i class="dashicons dashicons-plus-alt"></i> Yeni Görev Ekle
                </a>
            </div>
            
            <?php if (empty($tasks)): ?>
                <div class="empty-message">Henüz görev bulunmuyor.</div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped classic-table tasks">
                    <thead>
                        <tr>
                            <th class="column-desc">Görev Açıklaması</th>
                            <th>Son Tarih</th>
                            <th>Öncelik</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task):
                            $is_overdue = strtotime($task->due_date) < strtotime($today) && $task->status != 'completed';
                        ?>
                            <tr <?php echo $is_overdue ? 'class="overdue-task"' : ''; ?>>
                                <td class="column-desc">
                                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=edit&id=' . $task->id); ?>">
                                        <?php echo esc_html($task->task_description); ?>
                                    </a>
                                    <?php if ($is_overdue): ?>
                                        <span class="overdue-badge">Gecikmiş!</span>
                                    <?php endif; ?>
                                    <?php if ($task->policy_number): ?>
                                        <div class="task-meta">Poliçe: <?php echo esc_html($task->policy_number); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($task->due_date)); ?></td>
                                <td>
                                    <span class="task-priority priority-<?php echo esc_attr($task->priority); ?>">
                                        <?php 
                                            switch ($task->priority) {
                                                case 'low': echo 'Düşük'; break;
                                                case 'medium': echo 'Orta'; break;
                                                case 'high': echo 'Yüksek'; break;
                                                default: echo $task->priority; break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="task-status status-<?php echo esc_attr($task->status); ?>">
                                        <?php 
                                            switch ($task->status) {
                                                case 'pending': echo 'Beklemede'; break;
                                                case 'in_progress': echo 'İşlemde'; break;
                                                case 'completed': echo 'Tamamlandı'; break;
                                                case 'cancelled': echo 'İptal Edildi'; break;
                                                default: echo $task->status; break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="classic-actions">
                                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=edit&id=' . $task->id); ?>" class="button button-small">
                                            <i class="dashicons dashicons-edit"></i> Düzenle
                                        </a>
                                        <?php if ($task->status != 'completed'): ?>
                                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-tasks&action=complete&id=' . $task->id); ?>" class="button button-small button-complete">
                                            <i class="dashicons dashicons-yes"></i> Tamamla
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- GÖRÜŞME NOTLARI (KLASİK GÖRÜNÜM) -->
        <div class="classic-section">
            <div class="section-header">
                <div class="section-title">
                    <i class="dashicons dashicons-format-chat"></i> 
                    <h3>Görüşme Notları</h3>
                    <span class="badge"><?php echo count($customer_notes); ?> not</span>
                </div>
                <button type="button" class="button" id="classic-toggle-note-form">
                    <i class="dashicons dashicons-plus-alt"></i> Yeni Not Ekle
                </button>
            </div>
            
            <!-- Not Ekleme Formu (Klasik) -->
            <div class="classic-note-form-container" style="display:none;">
                <form method="post" action="" class="classic-note-form">
                    <?php wp_nonce_field('add_customer_note', 'note_nonce'); ?>
                    
                    <div class="form-row">
                        <label for="classic_note_content">Görüşme Notu</label>
                        <textarea name="note_content" id="classic_note_content" rows="4" required placeholder="Görüşme detaylarını buraya girin..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-row">
                            <label for="classic_note_type">Görüşme Sonucu</label>
                            <select name="note_type" id="classic_note_type" required>
                                <option value="">Seçiniz</option>
                                <option value="positive">Olumlu</option>
                                <option value="neutral">Durumu Belirsiz</option>
                                <option value="negative">Olumsuz</option>
                            </select>
                        </div>
                        
                        <div id="classic_rejection_reason_container" class="form-row hidden">
                            <label for="classic_rejection_reason">Olumsuz Olma Nedeni</label>
                            <select name="rejection_reason" id="classic_rejection_reason">
                                <option value="">Seçiniz</option>
                                <option value="price">Fiyat</option>
                                <option value="wrong_application">Yanlış Başvuru</option>
                                <option value="existing_policy">Mevcut Poliçesi Var</option>
                                <option value="other">Diğer</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="button" id="classic-cancel-note" class="button">
                            <i class="dashicons dashicons-no-alt"></i> İptal
                        </button>
                        <button type="submit" name="add_customer_note" class="button button-primary">
                            <i class="dashicons dashicons-plus-alt"></i> Not Ekle
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Notlar Listesi (Klasik Görünüm) -->
            <?php if (empty($customer_notes)): ?>
                <div class="empty-message">Henüz görüşme notu bulunmuyor.</div>
            <?php else: ?>
                <div class="classic-notes-list">
                    <?php foreach ($customer_notes as $note): ?>
                        <div class="classic-note-item note-type-<?php echo esc_attr($note->note_type); ?>">
                            <div class="note-header">
                                <div class="note-info">
                                    <span class="note-author">
                                        <i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($note->user_name); ?>
                                    </span>
                                    <span class="note-date">
                                        <i class="dashicons dashicons-calendar-alt"></i> <?php echo date('d.m.Y H:i', strtotime($note->created_at)); ?>
                                    </span>
                                    <span class="note-type-badge <?php echo esc_attr($note->note_type); ?>">
                                        <?php 
                                            switch ($note->note_type) {
                                                case 'positive': echo '<i class="dashicons dashicons-yes-alt"></i> Olumlu'; break;
                                                case 'neutral': echo '<i class="dashicons dashicons-minus"></i> Belirsiz'; break;
                                                case 'negative': echo '<i class="dashicons dashicons-no-alt"></i> Olumsuz'; break;
                                                default: echo ucfirst($note->note_type); break;
                                            }
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="note-content">
                                <?php echo nl2br(esc_html($note->note_content)); ?>
                            </div>
                            <?php if (isset($note->note_type) && $note->note_type === 'negative' && !empty($note->rejection_reason)): ?>
                                <div class="note-reason">
                                    <strong>Sebep:</strong> 
                                    <?php 
                                        switch ($note->rejection_reason) {
                                            case 'price': echo 'Fiyat'; break;
                                            case 'wrong_application': echo 'Yanlış Başvuru'; break;
                                            case 'existing_policy': echo 'Mevcut Poliçesi Var'; break;
                                            case 'other': echo 'Diğer'; break;
                                            default: echo ucfirst($note->rejection_reason); break;
                                        }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>

/* Genel Stiller */
.insurance-crm-customer-details {
    margin: 20px 0;
    color: #333;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

/* Müşteri Başlığı */
.customer-header {
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.wp-heading-inline {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.wp-heading-inline .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.customer-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 5px;
    font-size: 13px;
    color: #555;
}

.customer-ref {
    color: #666;
}

.customer-rep {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #0073aa;
}

/* Müşteri Eylemleri */
.customer-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 25px;
    gap: 15px;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.customer-actions .button {
    display: flex;
    align-items: center;
    gap: 5px;
}

.customer-actions .dashicons {
    margin-top: 3px;
}

/* Görünüm Değiştirme */
.view-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f9f9f9;
    padding: 5px 10px;
    border-radius: 4px;
}

.toggle-buttons {
    display: flex;
}

.toggle-button {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border: 1px solid #ddd;
    background: #f1f1f1;
    text-decoration: none;
    color: #555;
    font-size: 13px;
}

.toggle-button:first-child {
    border-radius: 3px 0 0 3px;
}

.toggle-button:last-child {
    border-radius: 0 3px 3px 0;
}

.toggle-button.active {
    background: #2271b1;
    color: #fff;
    border-color: #135e96;
}

.toggle-button:hover:not(.active) {
    background: #e5e5e5;
}

.toggle-button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* MODERN GÖRÜNÜM STILLERI */
/* Ana Grid Düzeni */
.customer-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    transition: box-shadow 0.3s ease;
    height: fit-content;
}

.card:hover {
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
}

.wide-card {
    grid-column: span 3;
}

@media (max-width: 1200px) {
    .customer-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .wide-card {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    .customer-grid {
        grid-template-columns: 1fr;
    }
    .wide-card {
        grid-column: span 1;
    }
}

/* Kart Başlığı */
.card-header {
    background-color: #f9f9f9;
    border-bottom: 1px solid #eee;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #23282d;
}

.card-header .dashicons {
    color: #555;
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.card-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-content {
    padding: 16px;
}

/* Etiketler */
.badge {
    background-color: #f0f0f1;
    color: #3c434a;
    font-size: 12px;
    font-weight: 500;
    border-radius: 20px;
    padding: 2px 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.customer-status, .customer-category {
    font-size: 12px;
    font-weight: 500;
    border-radius: 20px;
    padding: 3px 10px;
    margin-left: 10px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.status-aktif {
    background-color: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.status-pasif {
    background-color: #f5f5f5;
    color: #757575;
    border: 1px solid #e0e0e0;
}

.category-bireysel {
    background-color: #e0f0ff;
    color: #0a366c;
    border: 1px solid #b3d1ff;
}

.category-kurumsal {
    background-color: #daf0e8;
    color: #0a3636;
    border: 1px solid #a6e9d5;
}

.status-positive, .status-completed, .priority-low {
    background-color: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.status-negative, .priority-high {
    background-color: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
}

.status-neutral, .status-pending, .status-in_progress, .priority-medium {
    background-color: #fff8e1;
    color: #f57c00;
    border: 1px solid #ffecb3;
}

/* Bilgi Grid */
.customer-info-grid, .family-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.asset-info {
    display: grid;
    gap: 15px;
}

.info-item {
    margin-bottom: 10px;
}

.info-item.wide {
    grid-column: span 2;
}

.info-item .label {
    font-weight: 600;
    color: #666;
    font-size: 13px;
    margin-bottom: 6px;
}

.info-item .value {
    font-size: 14px;
    line-height: 1.5;
}

.info-item .value a {
    color: #2271b1;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.info-item .value a:hover {
    text-decoration: underline;
}

.info-item .value.address {
    line-height: 1.6;
    max-width: 100%;
}

.no-value {
    color: #999;
    font-style: italic;
}

/* Çocuk Listesi */
.children-list {
    margin: 0;
    padding-left: 20px;
}

.children-list li {
    margin-bottom: 5px;
}

/* Tablo Stilleri */
.table-container {
    overflow-x: auto;
    margin-bottom: 15px;
}

.widefat {
    border-collapse: collapse;
    width: 100%;
    border: none;
    border-spacing: 0;
}

.widefat th {
    text-align: left;
    padding: 10px;
    background-color: #f8f9fa;
    font-weight: 600;
    color: #3c434a;
    border-bottom: 1px solid #e0e0e0;
    white-space: nowrap;
}

.widefat td {
    padding: 10px;
    border-bottom: 1px solid #f0f0f1;
    vertical-align: middle;
}

.widefat tr:last-child td {
    border-bottom: none;
}

.widefat tr:hover td {
    background-color: #f9f9f9;
}

/* Poliçe Tablosu */
.policy-table .column-policy {
    width: 25%;
}

.policy-table .column-dates {
    width: 25%;
}

.policy-table .column-premium {
    width: 15%;
}

.policy-table .column-status {
    width: 15%;
}

.policy-table .column-actions {
    width: 20%;
    text-align: right;
}

.policy-number {
    font-weight: 600;
}

.policy-type {
    font-size: 12px;
    color: #666;
    margin-top: 3px;
}

.date-range {
    display: flex;
    flex-direction: column;
}

.start-date {
    color: #555;
}

.end-date {
    font-weight: 600;
    color: #333;
}

.premium-amount {
    font-weight: 600;
    color: #0073aa;
}

.policy-status {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    min-width: 70px;
    justify-content: center;
}

/* Görev Tablosu */
.tasks-table .column-desc {
    width: 40%;
}

.tasks-table .column-date {
    width: 15%;
}

.tasks-table .column-priority {
    width: 15%;
}

.tasks-table .column-status {
    width: 15%;
}

.tasks-table .column-actions {
    width: 15%;
    text-align: right;
}

.task-description {
    display: flex;
    flex-direction: column;
}

.task-meta {
    font-size: 12px;
    color: #666;
    margin-top: 3px;
}

.task-status, .task-priority {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    min-width: 70px;
}

/* Durum Göstergeleri ve Uyarılar */
.expiry-badge, .expired-badge, .expiring-soon-badge, .overdue-badge {
    display: inline-block;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-top: 5px;
}

.expired-badge, .overdue-badge {
    background-color: #ffebee;
    color: #c62828;
}

.expiring-soon-badge {
    background-color: #fff8e1;
    color: #f57c00;
}

.expired td, .overdue-task td {
    background-color: #fff6f6 !important;
}

.expiring-soon td {
    background-color: #fffde7 !important;
}

/* Satır İşlemleri */
.row-actions {
    display: flex;
    gap: 5px;
    justify-content: flex-end;
}

.button-small {
    padding: 0;
    min-height: 28px;
    min-width: 28px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.button-complete {
    background-color: #e8f5e9;
    border-color: #c8e6c9;
}

.button-complete:hover {
    background-color: #c8e6c9;
    border-color: #a5d6a7;
}

/* Not Ekleme Alanı */
.add-note-container {
    margin-bottom: 20px;
}

.add-note-toggle {
    margin-bottom: 15px;
}

.add-note-form-container {
    background-color: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #eee;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.add-note-form-container h3 {
    font-size: 15px;
    font-weight: 600;
    margin-top: 0;
    margin-bottom: 15px;
    color: #23282d;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-row {
    margin-bottom: 15px;
}

.form-group {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.form-group .form-row {
    flex: 1;
    min-width: 200px;
}

.add-note-form label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #444;
}

.add-note-form textarea,
.add-note-form select {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
}

.hidden {
    display: none;
}

/* Notlar Listesi */
.notes-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 15px;
}

@media (max-width: 768px) {
    .notes-list {
        grid-template-columns: 1fr;
    }
}

.note-item {
    background-color: #fff;
    border-radius: 8px;
    border: 1px solid #eee;
    padding: 15px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.note-item.note-type-positive {
    border-left: 4px solid #4CAF50;
}

.note-item.note-type-neutral {
    border-left: 4px solid #2196F3;
}

.note-item.note-type-negative {
    border-left: 4px solid #F44336;
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}

.note-meta {
    font-size: 13px;
    color: #666;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.note-author {
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.note-date {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    color: #888;
}

.note-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.note-type-badge.positive {
    background-color: #e8f5e9;
    color: #2e7d32;
}

.note-type-badge.neutral {
    background-color: #e3f2fd;
    color: #1565c0;
}

.note-type-badge.negative {
    background-color: #ffebee;
    color: #c62828;
}

.note-content {
    margin-bottom: 10px;
    line-height: 1.5;
    font-size: 14px;
}

.note-reason {
    font-size: 12px;
    color: #666;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed #eee;
}

/* Boş Mesaj Stili */
.empty-message {
    text-align: center;
    padding: 25px;
    color: #666;
    background-color: #f9f9f9;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.empty-message .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #999;
}

.empty-message p {
    margin: 0;
    font-size: 14px;
}

/* KLASİK GÖRÜNÜM STILLERI */
/* Klasik Sekmeler */
.classic-container {
    background-color: #fff;
    border: 1px solid #e5e5e5;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    margin-bottom: 20px;
}

.classic-tabs {
    display: flex;
    border-bottom: 1px solid #e5e5e5;
    background-color: #f9f9f9;
}

.classic-tabs .tab {
    padding: 10px 15px;
    cursor: pointer;
    font-weight: 600;
    border-right: 1px solid #e5e5e5;
    transition: background-color 0.2s;
}

.classic-tabs .tab:hover {
    background-color: #f0f0f0;
}

.classic-tabs .tab.active {
    background-color: #fff;
    border-bottom: 2px solid #2271b1;
    margin-bottom: -1px;
}

.classic-tab-content {
    padding: 20px;
    display: none;
}

.classic-tab-content.active {
    display: block;
}

.classic-tab-content h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    margin-bottom: 15px;
}

.classic-tab-content h4 {
    margin-top: 20px;
    margin-bottom: 10px;
    font-size: 14px;
    color: #23282d;
}

/* Klasik Tablolar */
.classic-info-table {
    width: 100%;
    border-collapse: collapse;
}

.classic-info-table th,
.classic-info-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #f0f0f1;
}

.classic-info-table th {
    width: 20%;
    font-weight: 600;
    color: #23282d;
    vertical-align: top;
}

/* Klasik Seksiyon */
.classic-section {
    background-color: #fff;
    border: 1px solid #e5e5e5;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    margin-bottom: 20px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border-bottom: 1px solid #e5e5e5;
    background-color: #f9f9f9;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
}

.section-title .dashicons {
    color: #555;
}

/* Klasik Tablo */
.classic-table {
    margin: 0;
}

.classic-table th {
    background-color: #f8f9fa;
}

/* Klasik Notlar */
.classic-note-form-container {
    margin: 15px;
    padding: 15px;
    background-color: #f9f9f9;
    border: 1px solid #e5e5e5;
}

.classic-notes-list {
    padding: 15px;
}

.classic-note-item {
    padding: 15px;
    border: 1px solid #e5e5e5;
    margin-bottom: 15px;
    border-left-width: 4px;
    background-color: #fff;
}

.classic-note-item.note-type-positive {
    border-left-color: #4CAF50;
}

.classic-note-item.note-type-neutral {
    border-left-color: #2196F3;
}

.classic-note-item.note-type-negative {
    border-left-color: #F44336;
}

.classic-actions {
    display: flex;
    gap: 5px;
}

/* Genel */
hr {
    margin: 20px 0;
    border: 0;
    height: 1px;
    background-color: #eee;
}

/* İkon Düzenlemeleri */
.dashicons {
    vertical-align: middle;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Not türü değiştiğinde, olumsuz olma sebebi göster/gizle (Modern)
    $('#note_type').on('change', function() {
        if ($(this).val() === 'negative') {
            $('#rejection_reason_container').removeClass('hidden').show();
            $('#rejection_reason').prop('required', true);
        } else {
            $('#rejection_reason_container').addClass('hidden').hide();
            $('#rejection_reason').prop('required', false);
        }
    });
    
    // Not türü değiştiğinde, olumsuz olma sebebi göster/gizle (Klasik)
    $('#classic_note_type').on('change', function() {
        if ($(this).val() === 'negative') {
            $('#classic_rejection_reason_container').removeClass('hidden').show();
            $('#classic_rejection_reason').prop('required', true);
        } else {
            $('#classic_rejection_reason_container').addClass('hidden').hide();
            $('#classic_rejection_reason').prop('required', false);
        }
    });
    
    // Modern görünüm - Not formunu aç/kapa
    $('#toggle-note-form').on('click', function() {
        $('.add-note-form-container').slideToggle();
    });
    
    // Modern görünüm - Not iptal
    $('#cancel-note').on('click', function() {
        $('.add-note-form-container').slideUp();
    });
    
    // Klasik görünüm - Not formunu aç/kapa
    $('#classic-toggle-note-form').on('click', function() {
        $('.classic-note-form-container').slideToggle();
    });
    
    // Klasik görünüm - Not iptal
    $('#classic-cancel-note').on('click', function() {
        $('.classic-note-form-container').slideUp();
    });
    
    // Klasik görünüm - Sekme değiştirme
    $('.classic-tabs .tab').on('click', function() {
        const tabId = $(this).data('tab');
        
        // Sekmeler ve içerikler için aktif durumunu güncelle
        $('.classic-tabs .tab').removeClass('active');
        $('.classic-tab-content').removeClass('active');
        
        $(this).addClass('active');
        $('#' + tabId + '-content').addClass('active');
        
        // URL'yi güncelle (sekme durumunu kaydet)
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', currentUrl);
    });
    
    // Görünüm geçişleri - artık bu görünümler üzerinden yönetiliyor
    // $('.toggle-button').on('click', function() {
    //     const viewType = $(this).data('view');
    //     
    //     $('.toggle-button').removeClass('active');
    //     $(this).addClass('active');
    //     
    //     $('.customer-view').hide();
    //     $('#' + viewType + '-view').show();
    // });
    
    // Tamamla butonu için onay
    $('.button-complete').on('click', function(e) {
        if (!confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php
// Bu kodları insurance-crm-admin-customer-details.php dosyasının en sonuna ekleyin
?>

<!-- Alt kısımdaki Müşteri Detayları bölümünü gizle -->
<style type="text/css">
/* Eski müşteri detay bölümlerini gizle */
body.wp-admin.insurance-crm_page_insurance-crm-customers h2#müşteri-detayları,
body.wp-admin.insurance-crm_page_insurance-crm-customers div.nav-tab-wrapper:not(.classic-tabs),
body.wp-admin.insurance-crm_page_insurance-crm-customers #tab-content-müşteri-bilgileri,
body.wp-admin.insurance-crm_page_insurance-crm-customers #tab-content-müşteri-detayları,
body.wp-admin.insurance-crm_page_insurance-crm-customers h2:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers h3:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers div.postbox#customer_details,
body.wp-admin.insurance-crm_page_insurance-crm-customers div.postbox h2.hndle:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers table:has(th:contains("TC Kimlik No")):has(td:contains("13696870823")),
body.wp-admin.insurance-crm_page_insurance-crm-customers #müşteri-detayları,
div.metabox-holder + h2:contains("Müşteri Detayları"),
div.metabox-holder + h2 ~ div,
div.metabox-holder + h2 ~ table {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
}
</style>


<?php
// Eski detay tablosunu ve başlığını engelle - aşağıdaki kod sayfa yüklendikten sonra çalışacak
?>

<!-- Alt kısımdaki Müşteri Detayları bölümünü gizle -->
<style type="text/css">
/* Eski müşteri detay bölümlerini gizle */
body.wp-admin.insurance-crm_page_insurance-crm-customers h2#müşteri-detayları,
body.wp-admin.insurance-crm_page_insurance-crm-customers div.nav-tab-wrapper:not(.classic-tabs),
body.wp-admin.insurance-crm_page_insurance-crm-customers #tab-content-müşteri-bilgileri,
body.wp-admin.insurance-crm_page_insurance-crm-customers #tab-content-müşteri-detayları,
body.wp-admin.insurance-crm_page_insurance-crm-customers h2:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers h3:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers div.postbox#customer_details,
body.wp-admin.insurance-crm_page_insurance-crm-customers div.postbox h2.hndle:contains("Müşteri Detayları"),
body.wp-admin.insurance-crm_page_insurance-crm-customers table:has(th:contains("TC Kimlik No")):has(td:contains("13696870823")),
body.wp-admin.insurance-crm_page_insurance-crm-customers #müşteri-detayları,
h2:contains("Müşteri Detayları"),
div.metabox-holder + h2:contains("Müşteri Detayları"),
div.metabox-holder + h2 ~ div,
div.metabox-holder + h2 ~ table {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Sayfa yüklendikten sonra eski tabloları ve başlığı kaldır
    function removeOldDetails() {
        // Alt kısımdaki Müşteri Detayları başlığını ve takip eden içeriği tamamen kaldır
        $('h2, h1, h3, h4, div').each(function() {
            // En basit yaklaşım: Tam olarak "Müşteri Detayları" içeren başlığı bul
            if ($(this).text().trim() === "Müşteri Detayları") {
                $(this).nextAll().remove(); // Bu elemandan sonraki tüm kardeş elementleri kaldır
                $(this).remove(); // Başlığın kendisini kaldır
            }
        });
        
        // İkinci kontrol - Tab tabanlı eski detay panellerini kaldır
        $('.nav-tab-wrapper:not(.classic-tabs)').each(function() {
            if ($(this).find('.nav-tab:contains("Müşteri Bilgileri")').length > 0) {
                $(this).nextAll().remove();
                $(this).remove();
            }
        });
        
        // Üçüncü kontrol - Tab içeriklerini kaldır
        $('#tab-content-müşteri-bilgileri, #tab-content-müşteri-detayları').remove();
        
        // Dördüncü kontrol - Metabox konteynerini kaldır
        $('.metabox-holder').next('h2, table').remove();
    }
    
    // Sayfa yüklenir yüklenmez çalıştır
    removeOldDetails();
    
    // 500ms sonra tekrar çalıştır (bazen içerik gecikmeli yüklenebilir)
    setTimeout(removeOldDetails, 500);
    
    // 1 saniye sonra tekrar çalıştır
    setTimeout(removeOldDetails, 1000);
    
    // 2 saniye sonra tekrar çalıştır (AJAX yüklemeleri için daha güvenli)
    setTimeout(removeOldDetails, 2000);
    
    // DOM değişikliklerini izle
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            // Her DOM değişikliğinde kontrol et
            if (mutation.addedNodes.length) {
                removeOldDetails();
            }
        });
    });
    
    // Tüm dokümanı izle - özellikle yeni eklenen node'lara dikkat et
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true
    });
});
</script>


<script>
jQuery(document).ready(function($) {
    // DOM tamamen yüklendiğinde çalışır
    setTimeout(function() {
        // Ana container stilini düzenle
        $('.customer-grid').css({
            'display': 'flex',
            'flex-wrap': 'wrap',
            'gap': '20px',
            'justify-content': 'space-between'
        });
        
        // Üst sıra kutucukları
        $('.personal-card, .family-card, .asset-card').css({
            'width': 'calc(33.333% - 14px)',
            'margin-bottom': '20px',
            'flex-grow': '0',
            'flex-shrink': '0',
            'min-height': '250px'
        });
        

// Poliçeler tam genişlikte
$('.policy-card').css({
    'width': '100%',
    'margin-bottom': '20px',
    'flex-grow': '0',
    'flex-shrink': '0'
});

// Görevler ve Görüşme notları yan yana
$('.tasks-card, .notes-card').css({
    'width': 'calc(50% - 10px)',
    'margin-bottom': '20px',
    'flex-grow': '0',
    'flex-shrink': '0'
});

// Görevler ve Görüşme notları kutularını bir div içine al ve yan yana yerleştir
if ($('.tasks-notes-container').length === 0) {
    // Görevler ve Görüşme notları kutularını kapsayan bir div oluştur
    const tasksNotesContainer = $('<div class="tasks-notes-container"></div>').css({
        'display': 'flex',
        'justify-content': 'space-between',
        'gap': '20px',
        'width': '100%'
    });
    
    // Kutucukları mevcut yerlerinden al
    const tasksCard = $('.tasks-card').detach();
    const notesCard = $('.notes-card').detach();
    
    // Kutucukları kapsayıcıya ekle
    tasksNotesContainer.append(tasksCard).append(notesCard);
    
    // Kapsayıcıyı poliçe kutusundan sonra ekle
    $('.policy-card').after(tasksNotesContainer);
}


        
        // Poliçeler ve görevler tabloları için daha iyi görünüm
        $('.policy-card .table-container, .tasks-card .table-container').css({
            'max-height': 'none',
            'overflow-y': 'visible'
        });
        
        // Notlar için yan yana görünüm
        $('.notes-card .notes-list').css({
            'display': 'flex',
            'flex-wrap': 'wrap',
            'gap': '15px'
        });
        
        $('.notes-card .note-item').css({
            'width': 'calc(50% - 10px)',
            'margin': '0',
            'box-sizing': 'border-box'
        });
        
        // Responsive düzen ayarları
        if ($(window).width() <= 1200) {
            $('.personal-card, .family-card, .asset-card').css({
                'width': 'calc(50% - 10px)'
            });
        }
        
        if ($(window).width() <= 768) {
            $('.personal-card, .family-card, .asset-card, .notes-card .note-item').css({
                'width': '100%'
            });
        }
    }, 1000); // DOM tamamen yüklendiğinden emin olmak için 1 saniye bekle
});
</script>

<style>
/* Tablo genişlik ve alan düzeltmeleri */
.insurance-crm-customer-details .card-content {
    padding: 16px !important;
    overflow: visible !important;
}

.insurance-crm-customer-details .table-container {
    overflow-x: auto !important;
    max-height: none !important;
}

.insurance-crm-customer-details .widefat {
    width: 100% !important;
    table-layout: fixed !important;
}

/* Poliçe tablosunun sütun genişlikleri */
.insurance-crm-customer-details .policy-table .column-policy {
    width: 25% !important;
}

.insurance-crm-customer-details .policy-table .column-dates {
    width: 25% !important;
}

.insurance-crm-customer-details .policy-table .column-premium {
    width: 15% !important; 
}

.insurance-crm-customer-details .policy-table .column-status {
    width: 15% !important;
}

.insurance-crm-customer-details .policy-table .column-actions {
    width: 20% !important;
    text-align: right !important;
}

/* Görev tablosunun sütun genişlikleri */
.insurance-crm-customer-details .tasks-table .column-desc {
    width: 40% !important;
}

.insurance-crm-customer-details .tasks-table .column-date {
    width: 15% !important;
}

.insurance-crm-customer-details .tasks-table .column-priority {
    width: 15% !important;
}

.insurance-crm-customer-details .tasks-table .column-status {
    width: 15% !important;
}

.insurance-crm-customer-details .tasks-table .column-actions {
    width: 15% !important;
    text-align: right !important;
}

/* Notların yan yana görünümü için */
@media (max-width: 768px) {
    .insurance-crm-customer-details .notes-card .note-item {
        width: 100% !important;
    }
}

/* Kutucuklar arasındaki yükseklik farkını düzelt */
.insurance-crm-customer-details .card {
    height: auto !important;
    display: flex !important;
    flex-direction: column !important;
}

.insurance-crm-customer-details .card-content {
    flex: 1 !important;
}


/* Görevler ve Notlar kutuları için yan yana düzen */
.tasks-notes-container {
    display: flex !important;
    width: 100% !important;
    gap: 20px !important;
    margin-bottom: 20px !important;
}

/* Mobil görünüm için düzenleme */
@media (max-width: 768px) {
    .tasks-notes-container {
        flex-direction: column !important;
    }
    
    .insurance-crm-customer-details .tasks-card,
    .insurance-crm-customer-details .notes-card {
        width: 100% !important;
    }
}
</style>

<!-- Son düzeltme - Görevler ve Görüşme Notları yan yana -->
<script>
jQuery(document).ready(function($) {
    // DOM tamamen yüklendikten sonra ve biraz daha fazla bekleyerek çalışsın
    setTimeout(function() {
        console.log("Kutucukları düzenleme fonksiyonu çalıştı");
        
        // Görevler ve Görüşme Notları kutularını seçelim
        var tasksCard = $('.card.tasks-card');
        var notesCard = $('.card.notes-card');
        
        // Bunları kapsayacak bir div oluşturalım
        var container = $('<div class="tasks-notes-row"></div>');
        
        // Kartları DOM'dan kaldırıp, container'a ekleyelim, sonra DOM'a geri ekleyelim
        tasksCard.detach();
        notesCard.detach();
        
        container.append(tasksCard).append(notesCard);
        
        // Container'ı poliçe kutusundan hemen sonra ekleyelim
        $('.card.policy-card').after(container);
    }, 2000); // 2 saniye bekle - diğer tüm scriptlerden sonra çalışacak
});
</script>

<style type="text/css">
/* Yeni eklenen yan yana konteynırı için stiller */
.tasks-notes-row {
    display: flex !important;
    width: 100% !important;
    gap: 20px !important;
    margin-bottom: 20px !important;
    flex-wrap: wrap !important;
}

/* Kutular için genişlik ayarları */
.tasks-notes-row .card.tasks-card,
.tasks-notes-row .card.notes-card {
    flex: 1 !important;
    min-width: 45% !important;
    width: calc(50% - 10px) !important;
    margin: 0 !important;
}

/* Mobil görünüm için */
@media (max-width: 768px) {
    .tasks-notes-row {
        flex-direction: column !important;
    }
    
    .tasks-notes-row .card.tasks-card,
    .tasks-notes-row .card.notes-card {
        width: 100% !important;
        margin-bottom: 20px !important;
    }
}



/* Görüşme notları düzenini optimize et */
.notes-card .notes-list {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 15px !important;
}

/* Her not için genişlik ayarı */
.notes-card .note-item {
    flex: 0 0 calc(33.333% - 10px) !important; /* Her satırda 3 not */
    margin: 0 !important;
    box-sizing: border-box !important;
    min-width: 200px !important; /* Minimum genişlik */
}

/* Tablet ekranlar için */
@media (max-width: 1200px) {
    .notes-card .note-item {
        flex: 0 0 calc(50% - 10px) !important; /* Her satırda 2 not */
    }
}

/* Mobil ekranlar için */
@media (max-width: 768px) {
    .notes-card .note-item {
        flex: 0 0 100% !important; /* Her satırda 1 not */
    }
}


/* CSS CSS CSS CSS CSS */
/* Görevler ve Görüşme Notları kutu genişliklerini düzelten CSS */
.tasks-notes-row {
    display: flex !important;
    width: 100% !important;
    max-width: 100% !important;
    gap: 20px !important;
    margin: 0 !important;
    padding: 0 !important;
    justify-content: space-between !important;
}

/* Kutular için genişlik ayarları - sağ margin sorununu çözer */
.tasks-notes-row .card {
    width: calc(50% - 10px) !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    flex: 1 1 calc(50% - 10px) !important;
}

/* Görüşme notlarının 3lü düzenini optimize et */
.notes-card .notes-list {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    justify-content: space-between !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Her not için genişlik ayarı - 3lü görünüm */
.notes-card .note-item {
    width: calc(33.333% - 7px) !important; 
    margin: 0 !important;
    padding: 10px !important;
    box-sizing: border-box !important;
}

/* Kapsayıcı sayfadaki diğer elementlerle hizalanması için */
.insurance-crm-customer-details .wrap,
.insurance-crm-customer-details .customer-grid,
.tasks-notes-row {
    max-width: 100% !important;
    margin-right: 0 !important;
    padding-right: 0 !important;
}

/* Tablet ekranlar için */
@media (max-width: 1200px) {
    .notes-card .note-item {
        width: calc(50% - 5px) !important;
    }
}

/* Mobil ekranlar için */
@media (max-width: 768px) {
    .tasks-notes-row {
        flex-direction: column !important;
    }
    
    .tasks-notes-row .card {
        width: 100% !important;
        margin-bottom: 20px !important;
    }
    
    .notes-card .note-item {
        width: 100% !important;
    }
}



</style>