<?php
/**
 * Müşteri Detay Sayfası
 * @version 3.3.0
 */

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$customer_id = intval($_GET['id']);
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";

if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id) {
    $where_clause = $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}

// Müşteri bilgilerini al
$customer = $wpdb->get_row($wpdb->prepare("
    SELECT c.*,
           r.id AS rep_id,
           u.display_name AS rep_name
    FROM $customers_table c
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE c.id = %d
    $where_clause
", $customer_id));

if (!$customer) {
    echo '<div class="ab-notice ab-error">Müşteri bulunamadı veya görüntüleme yetkiniz yok.</div>';
    return;
}

// Müşterinin poliçelerini al
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policies = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $policies_table 
    WHERE customer_id = %d
    ORDER BY end_date ASC
", $customer_id));

// Müşterinin görevlerini al
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$tasks = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $tasks_table 
    WHERE customer_id = %d
    ORDER BY due_date ASC
", $customer_id));

// Müşteri dosyalarını al
$files_table = $wpdb->prefix . 'insurance_crm_customer_files';
$files = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $files_table
    WHERE customer_id = %d
    ORDER BY upload_date DESC
", $customer_id));

// Admin panelinden izin verilen dosya türlerini al
$settings = get_option('insurance_crm_settings', array());
$allowed_file_types = !empty($settings['file_upload_settings']['allowed_file_types']) 
    ? $settings['file_upload_settings']['allowed_file_types'] 
    : array('jpg', 'jpeg', 'pdf', 'docx'); // Varsayılan türler

// Dosya türleri için MIME tiplerini tanımla
$file_type_mime_mapping = array(
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt' => 'text/plain',
    'zip' => 'application/zip'
);

// İzin verilen MIME tiplerini oluştur
$allowed_mime_types = array();
foreach ($allowed_file_types as $type) {
    if (isset($file_type_mime_mapping[$type])) {
        $allowed_mime_types[] = $file_type_mime_mapping[$type];
    }
}

// Modal için desteklenen formatlar metnini oluştur
$supported_formats_text = implode(', ', array_map('strtoupper', $allowed_file_types));

// Dosya Yükleme için accept özelliğini oluştur
$accept_attribute = '.' . implode(',.', $allowed_file_types);

// AJAX Dosya Yükleme İşlemi
if (isset($_POST['ajax_upload_files']) && wp_verify_nonce($_POST['file_upload_nonce'], 'file_upload_action')) {
    $response = array('success' => false, 'message' => '', 'files' => array());
    
    if (handle_customer_file_uploads($customer_id)) {
        $response['success'] = true;
        $response['message'] = 'Dosyalar başarıyla yüklendi.';
        
        // Yeni dosya listesini al
        $new_files = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $files_table
            WHERE customer_id = %d
            ORDER BY upload_date DESC
        ", $customer_id));
        
        // Dosya bilgilerini ekle
        foreach ($new_files as $file) {
            $response['files'][] = array(
                'id' => $file->id,
                'name' => $file->file_name,
                'type' => $file->file_type,
                'path' => $file->file_path,
                'size' => format_file_size($file->file_size),
                'date' => date('d.m.Y H:i', strtotime($file->upload_date)),
                'description' => $file->description
            );
        }
    } else {
        $response['message'] = 'Dosya yüklenirken bir hata oluştu.';
    }
    
    echo json_encode($response);
    exit;
}

// AJAX Dosya Silme İşlemi
if (isset($_POST['ajax_delete_file']) && wp_verify_nonce($_POST['file_delete_nonce'], 'file_delete_action')) {
    $response = array('success' => false, 'message' => '');
    $file_id = intval($_POST['file_id']);
    
    if (delete_customer_file($file_id, $customer_id)) {
        $response['success'] = true;
        $response['message'] = 'Dosya başarıyla silindi.';
    } else {
        $response['message'] = 'Dosya silinirken bir hata oluştu.';
    }
    
    echo json_encode($response);
    exit;
}

// Not ekleme işlemi
if (isset($_POST['add_note']) && isset($_POST['note_nonce']) && wp_verify_nonce($_POST['note_nonce'], 'add_customer_note')) {
    $note_data = array(
        'customer_id' => $customer_id,
        'note_content' => sanitize_textarea_field($_POST['note_content']),
        'note_type' => sanitize_text_field($_POST['note_type']),
        'created_by' => get_current_user_id(),
        'created_at' => current_time('mysql')
    );
    
    if ($note_data['note_type'] === 'negative' && !empty($_POST['rejection_reason'])) {
        $note_data['rejection_reason'] = sanitize_text_field($_POST['rejection_reason']);
        
        // Müşteri durumunu Pasif olarak güncelle
        $wpdb->update(
            $customers_table,
            array('status' => 'pasif'),
            array('id' => $customer_id)
        );
    }
    
    $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
    $wpdb->insert($notes_table, $note_data);
    
    // Sayfayı yenile
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&note_added=1";</script>';
}

// Normal dosya silme işlemi
if (isset($_POST['delete_file']) && isset($_POST['file_nonce']) && wp_verify_nonce($_POST['file_nonce'], 'delete_file_view')) {
    $file_id = intval($_POST['file_id']);
    
    if (delete_customer_file($file_id, $customer_id)) {
        $message = 'Dosya başarıyla silindi.';
        $message_type = 'success';
    } else {
        $message = 'Dosya bulunamadı veya silme yetkiniz yok.';
        $message_type = 'error';
    }
    
    // Sayfayı yenile
    $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&file_deleted=1";</script>';
    exit;
}

// Görüşme notlarını al
$notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
$customer_notes = $wpdb->get_results($wpdb->prepare("
    SELECT n.*, 
           u.display_name AS user_name
    FROM $notes_table n
    LEFT JOIN {$wpdb->users} u ON n.created_by = u.ID
    WHERE n.customer_id = %d
    ORDER BY n.created_at DESC
", $customer_id));

// Kullanıcının kayıtlı renk tercihlerini al
$current_user_id = get_current_user_id();
$personal_color = get_user_meta($current_user_id, 'crm_personal_color', true) ?: '#3498db';
$corporate_color = get_user_meta($current_user_id, 'crm_corporate_color', true) ?: '#4caf50';
$family_color = get_user_meta($current_user_id, 'crm_family_color', true) ?: '#ff9800';
$vehicle_color = get_user_meta($current_user_id, 'crm_vehicle_color', true) ?: '#e74c3c';
$home_color = get_user_meta($current_user_id, 'crm_home_color', true) ?: '#9c27b0';
$pet_color = '#e91e63'; // Evcil hayvan paneli için renk
$doc_color = '#607d8b'; // Dosya paneli için renk

/**
 * Müşteri dosyalarını yükler
 */
function handle_customer_file_uploads($customer_id) {
    global $wpdb;
    $files_table = $wpdb->prefix . 'insurance_crm_customer_files';
    $upload_dir = wp_upload_dir();
    $customer_dir = $upload_dir['basedir'] . '/customer_files/' . $customer_id;
    
    // Klasör yoksa oluştur
    if (!file_exists($customer_dir)) {
        wp_mkdir_p($customer_dir);
    }
    
    // Admin panelinden izin verilen dosya türlerini al
    $settings = get_option('insurance_crm_settings', array());
    $allowed_types = !empty($settings['file_upload_settings']['allowed_file_types']) 
        ? $settings['file_upload_settings']['allowed_file_types'] 
        : array('jpg', 'jpeg', 'pdf', 'docx'); // Varsayılan türler

    $max_file_size = 5 * 1024 * 1024; // 5MB
    $max_file_count = 5; // Maksimum dosya sayısı
    
    $file_count = count($_FILES['customer_files']['name']);
    
    // Dosya sayısını kontrol et
    if ($file_count > $max_file_count) {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">En fazla ' . $max_file_count . ' dosya yükleyebilirsiniz.</div>';
        return false;
    }
    
    $upload_count = 0;
    $success = false;
    
    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['customer_files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $file_name = sanitize_file_name($_FILES['customer_files']['name'][$i]);
        $file_tmp = $_FILES['customer_files']['tmp_name'][$i];
        $file_size = $_FILES['customer_files']['size'][$i];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $file_description = isset($_POST['file_descriptions'][$i]) ? sanitize_text_field($_POST['file_descriptions'][$i]) : '';
        
        // Dosya türü ve boyutu kontrolü
        if (!in_array($file_ext, $allowed_types)) {
            continue;
        }
        
        if ($file_size > $max_file_size) {
            continue;
        }
        
        // Upload sayısını kontrol et
        if ($upload_count >= $max_file_count) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-warning">Maksimum ' . $max_file_count . ' dosya sınırına ulaşıldı. Diğer dosyalar yüklenmedi.</div>';
            break;
        }
        
        // Benzersiz dosya adı oluştur
        $new_file_name = time() . '-' . $file_name;
        $file_path = $customer_dir . '/' . $new_file_name;
        $file_url = $upload_dir['baseurl'] . '/customer_files/' . $customer_id . '/' . $new_file_name;
        
        // Dosyayı taşı
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Dosya bilgilerini veritabanına kaydet
            $wpdb->insert(
                $files_table,
                array(
                    'customer_id' => $customer_id,
                    'file_name' => $file_name,
                    'file_path' => $file_url,
                    'file_type' => $file_ext,
                    'file_size' => $file_size,
                    'upload_date' => current_time('mysql'),
                    'description' => $file_description
                )
            );
            
            $upload_count++;
            $success = true;
        }
    }
    
    return $success;
}

/**
 * Müşteri dosyasını siler
 */
function delete_customer_file($file_id, $customer_id) {
    global $wpdb;
    $files_table = $wpdb->prefix . 'insurance_crm_customer_files';
    
    // Dosya bilgilerini al
    $file = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $files_table WHERE id = %d AND customer_id = %d",
        $file_id, $customer_id
    ));
    
    if (!$file) {
        return false;
    }
    
    // Dosyayı fiziksel olarak sil
    $upload_dir = wp_upload_dir();
    $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file->file_path);
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    // Veritabanından dosya kaydını sil
    $result = $wpdb->delete(
        $files_table,
        array('id' => $file_id)
    );
    
    return $result !== false;
}

// Dosya türüne göre ikon belirleme
function get_file_icon($file_type) {
    switch ($file_type) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
            return 'fa-file-image';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'txt':
            return 'fa-file-alt';
        case 'zip':
            return 'fa-file-archive';
        default:
            return 'fa-file';
    }
}

// Dosya boyutu formatını düzenleme
function format_file_size($size) {
    if ($size < 1024) {
        return $size . ' B';
    } elseif ($size < 1048576) {
        return round($size / 1024, 2) . ' KB';
    } else {
        return round($size / 1048576, 2) . ' MB';
    }
}
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-customer-details">

    <!-- Geri dön butonu -->
    <a href="?view=customers" class="ab-back-button">
        <i class="fas fa-arrow-left"></i> Müşterilere Dön
    </a>
    <!-- Müşteri Başlık Bilgisi -->
    <div class="ab-customer-header">
        <div class="ab-customer-title">
            <h1><i class="fas fa-user"></i> <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?></h1>
            <div class="ab-customer-meta">
                <span class="ab-badge ab-badge-category-<?php echo $customer->category; ?>">
                    <?php echo $customer->category == 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
                </span>
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
                <span>
                    <i class="fas fa-user-tie"></i>
                    <?php echo !empty($customer->rep_name) ? esc_html($customer->rep_name) : 'Atanmamış'; ?>
                </span>
            </div>
        </div>
        <div class="ab-customer-actions">
            <a href="?view=customers&action=edit&id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-primary">
                <i class="fas fa-edit"></i> Düzenle
            </a>
            <a href="?view=tasks&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn">
                <i class="fas fa-tasks"></i> Yeni Görev
            </a>
            <a href="?view=policies&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn">
                <i class="fas fa-file-contract"></i> Yeni Poliçe
            </a>
            <a href="?view=customers" class="ab-btn ab-btn-secondary">
                <i class="fas fa-arrow-left"></i> Listeye Dön
            </a>
        </div>
    </div>
    
    <?php if (isset($_SESSION['crm_notice'])): ?>
        <?php echo $_SESSION['crm_notice']; ?>
        <?php unset($_SESSION['crm_notice']); ?>
    <?php endif; ?>

    <div id="ajax-response-container"></div>
    
    <!-- Müşteri Bilgileri -->
    <div class="ab-panels">
        <div class="ab-panel ab-panel-personal" style="--panel-color: <?php echo esc_attr($personal_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-user-circle"></i> Kişisel Bilgiler</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Ad Soyad</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?></div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">TC Kimlik No</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->tc_identity); ?></div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">E-posta</div>
                        <div class="ab-info-value">
                            <a href="mailto:<?php echo esc_attr($customer->email); ?>">
                                <i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?>
                            </a>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Telefon</div>
                        <div class="ab-info-value">
                            <a href="tel:<?php echo esc_attr($customer->phone); ?>">
                                <i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?>
                            </a>
                        </div>
                    </div>
                    <div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Adres</div>
                        <div class="ab-info-value"><?php echo nl2br(esc_html($customer->address)); ?></div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Doğum Tarihi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->birth_date) ? date('d.m.Y', strtotime($customer->birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>

<div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Cinsiyet</div>
                        <div class="ab-info-value"><?php echo nl2br(esc_html($customer->gender)); ?></div>
                    </div>




                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Meslek</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->occupation) ? esc_html($customer->occupation) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Kayıt Tarihi</div>
                        <div class="ab-info-value"><?php echo date('d.m.Y', strtotime($customer->created_at)); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($customer->category === 'kurumsal'): ?>
        <!-- Kurumsal Müşteri için Firma Bilgileri -->
        <div class="ab-panel ab-panel-corporate" style="--panel-color: <?php echo esc_attr($corporate_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-building"></i> Firma Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Firma Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->company_name) ? esc_html($customer->company_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Vergi Dairesi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->tax_office) ? esc_html($customer->tax_office) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Vergi Kimlik Numarası</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->tax_number) ? esc_html($customer->tax_number) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="ab-panel ab-panel-family" style="--panel-color: <?php echo esc_attr($family_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-users"></i> Aile Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eş Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_name) ? esc_html($customer->spouse_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eş TC Kimlik No</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_tc_identity) ? esc_html($customer->spouse_tc_identity) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eşin Doğum Tarihi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_birth_date) ? date('d.m.Y', strtotime($customer->spouse_birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Çocuk Sayısı</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->children_count) && $customer->children_count > 0 ? $customer->children_count : '0'; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($customer->children_names)): ?>
                    <div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Çocuklar</div>
                        <div class="ab-info-value">
                            <?php
                            $children_names = explode(',', $customer->children_names);
                            $children_birth_dates = !empty($customer->children_birth_dates) ? explode(',', $customer->children_birth_dates) : [];
                            $children_tc_identities = !empty($customer->children_tc_identities) ? explode(',', $customer->children_tc_identities) : [];
                            
                            echo '<ul class="ab-children-list">';
                            for ($i = 0; $i < count($children_names); $i++) {
                                echo '<li>' . esc_html(trim($children_names[$i]));
                                
                                if (isset($children_tc_identities[$i]) && !empty(trim($children_tc_identities[$i]))) {
                                    echo ' - TC: ' . esc_html(trim($children_tc_identities[$i]));
                                }
                                
                                if (isset($children_birth_dates[$i]) && !empty(trim($children_birth_dates[$i]))) {
                                    echo ' - Doğum: ' . date('d.m.Y', strtotime(trim($children_birth_dates[$i])));
                                }
                                
                                echo '</li>';
                            }
                            echo '</ul>';
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ab-panel ab-panel-vehicle" style="--panel-color: <?php echo esc_attr($vehicle_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-car"></i> Araç Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Aracı Var mı?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->has_vehicle) && $customer->has_vehicle == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->has_vehicle) && $customer->has_vehicle == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Araç Plakası</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->vehicle_plate) ? esc_html($customer->vehicle_plate) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ab-panel ab-panel-home" style="--panel-color: <?php echo esc_attr($home_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-home"></i> Ev Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evi Kendisine mi Ait?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->owns_home) && $customer->owns_home == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->owns_home) && $customer->owns_home == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">DASK Poliçesi</div>
                        <div class="ab-info-value">
                            <?php 
                            if (isset($customer->has_dask_policy)) {
                                if ($customer->has_dask_policy == 1) {
                                    echo '<span class="ab-positive">Var</span>';
                                    if (!empty($customer->dask_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->dask_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo '<span class="ab-negative">Yok</span>';
                                }
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Konut Poliçesi</div>
                        <div class="ab-info-value">
                            <?php 
                            if (isset($customer->has_home_policy)) {
                                if ($customer->has_home_policy == 1) {
                                    echo '<span class="ab-positive">Var</span>';
                                    if (!empty($customer->home_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->home_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo '<span class="ab-negative">Yok</span>';
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
        
        <!-- Evcil Hayvan Bilgileri Paneli -->
        <div class="ab-panel ab-panel-pet" style="--panel-color: <?php echo esc_attr($pet_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-paw"></i> Evcil Hayvan Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvanı Var mı?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->has_pet) && $customer->has_pet == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->has_pet) && $customer->has_pet == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_name) ? esc_html($customer->pet_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Cinsi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_type) ? esc_html($customer->pet_type) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Yaşı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_age) ? esc_html($customer->pet_age) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Dosya Arşivi Paneli -->
        <div class="ab-panel ab-full-panel ab-panel-documents" style="--panel-color: <?php echo esc_attr($doc_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-archive"></i> Dosya Arşivi</h3>
                <div class="ab-panel-actions">
                    <button type="button" class="ab-btn ab-btn-sm" id="open-file-upload-modal">
                        <i class="fas fa-plus"></i> Yeni Dosya Ekle
                    </button>
                </div>
            </div>
            <div class="ab-panel-body">
                <div id="files-container">
                <?php if (empty($files)): ?>
                <div class="ab-empty-state">
                    <p><i class="fas fa-file-upload"></i><br>Henüz yüklenmiş dosya bulunmuyor.</p>
                    <button type="button" class="ab-btn open-file-upload-modal">
                        <i class="fas fa-plus"></i> Dosya Yükle
                    </button>
                </div>
                <?php else: ?>
                <div class="ab-files-gallery">
                    <?php foreach ($files as $file): ?>
                    <div class="ab-file-card" data-file-id="<?php echo $file->id; ?>">
                        <div class="ab-file-card-header">
                            <div class="ab-file-type-icon">
                                <i class="fas <?php echo get_file_icon($file->file_type); ?>"></i>
                            </div>
                            <div class="ab-file-meta">
                                <div class="ab-file-name"><?php echo esc_html($file->file_name); ?></div>
                                <div class="ab-file-info">
                                    <span><i class="fas fa-calendar-alt"></i> <?php echo date('d.m.Y', strtotime($file->upload_date)); ?></span>
                                    <span><i class="fas fa-weight"></i> <?php echo format_file_size($file->file_size); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($file->file_type == 'jpg' || $file->file_type == 'jpeg' || $file->file_type == 'png'): ?>
                        <div class="ab-file-preview">
                            <img src="<?php echo esc_url($file->file_path); ?>" alt="<?php echo esc_attr($file->file_name); ?>">
                        </div>
                        <?php else: ?>
                        <div class="ab-file-icon-large">
                            <i class="fas <?php echo get_file_icon($file->file_type); ?>"></i>
                            <span>.<?php echo esc_html($file->file_type); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($file->description)): ?>
                        <div class="ab-file-description">
                            <p><?php echo esc_html($file->description); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="ab-file-card-actions">
                            <a href="<?php echo esc_url($file->file_path); ?>" target="_blank" class="ab-btn ab-btn-sm ab-btn-primary">
                                <i class="fas <?php echo ($file->file_type === 'jpg' || $file->file_type === 'jpeg' || $file->file_type === 'png') ? 'fa-eye' : 'fa-download'; ?>"></i>
                                <?php echo ($file->file_type === 'jpg' || $file->file_type === 'jpeg' || $file->file_type === 'png') ? 'Görüntüle' : 'İndir'; ?>
                            </a>
                            <button type="button" class="ab-btn ab-btn-sm ab-btn-danger delete-file" data-file-id="<?php echo $file->id; ?>">
                                <i class="fas fa-trash"></i> Sil
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Poliçeler Paneli -->
        <div class="ab-panel ab-full-panel">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-contract"></i> Poliçeler</h3>
                <div class="ab-panel-actions">
                    <a href="?view=policies&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-sm">
                        <i class="fas fa-plus"></i> Yeni Poliçe
                    </a>
                </div>
            </div>
            <div class="ab-panel-body">
                <?php if (empty($policies)): ?>
                <div class="ab-empty-state">
                    <p>Henüz poliçe bulunmuyor.</p>
                </div>
                <?php else: ?>
                <div class="ab-table-container">
                    <table class="ab-crm-table">
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
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>">
                                            <?php echo esc_html($policy->policy_number); ?>
                                        </a>
                                        <?php if ($is_expired): ?>
                                            <span class="ab-badge ab-badge-expired">Süresi Dolmuş</span>
                                        <?php elseif ($is_expiring_soon): ?>
                                            <span class="ab-badge ab-badge-expiring">Yakında Bitiyor</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($policy->policy_type); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                                    <td class="ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> ₺</td>
                                    <td>
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr($policy->status); ?>">
                                            <?php echo esc_html($policy->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ab-actions">
                                            <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>" title="Düzenle" class="ab-action-btn">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?view=policies&action=renew&id=<?php echo $policy->id; ?>" title="Yenile" class="ab-action-btn">
                                                <i class="fas fa-sync-alt"></i>
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
        
        <!-- Görevler Paneli -->
        <div class="ab-panel ab-full-panel">
            <div class="ab-panel-header">
                <h3><i class="fas fa-tasks"></i> Görevler</h3>
                <div class="ab-panel-actions">
                    <a href="?view=tasks&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-sm">
                        <i class="fas fa-plus"></i> Yeni Görev
                    </a>
                </div>
            </div>
            <div class="ab-panel-body">
                <?php if (empty($tasks)): ?>
                <div class="ab-empty-state">
                    <p>Henüz görev bulunmuyor.</p>
                </div>
                <?php else: ?>
                <div class="ab-table-container">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th>Görev Açıklaması</th>
                                <th>Son Tarih</th>
                                <th>Öncelik</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $is_overdue = strtotime($task->due_date) < time() && $task->status != 'completed';
                                $row_class = $is_overdue ? 'overdue' : '';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>">
                                            <?php echo esc_html($task->task_description); ?>
                                        </a>
                                        <?php if ($is_overdue): ?>
                                            <span class="ab-badge ab-badge-overdue">Gecikmiş</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($task->due_date)); ?></td>
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
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr($task->status); ?>">
                                            <?php 
                                                switch ($task->status) {
                                                    case 'pending': echo 'Beklemede'; break;
                                                    case 'in_progress': echo 'İşlemde'; break;
                                                    case 'completed': echo 'Tamamlandı'; break;
                                                    case 'cancelled': echo 'İptal'; break;
                                                    default: echo ucfirst($task->status); break;
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ab-actions">
                                            <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" title="Düzenle" class="ab-action-btn">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($task->status != 'completed'): ?>
                                            <a href="?view=tasks&action=complete&id=<?php echo $task->id; ?>" title="Tamamla" class="ab-action-btn">
                                                <i class="fas fa-check"></i>
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
        
        <!-- Görüşme Notları Paneli -->
        <div class="ab-panel ab-full-panel">
            <div class="ab-panel-header">
                <h3><i class="fas fa-comments"></i> Görüşme Notları</h3>
                <div class="ab-panel-actions">
                    <button type="button" class="ab-btn ab-btn-sm" id="toggle-note-form">
                        <i class="fas fa-plus"></i> Yeni Not Ekle
                    </button>
                </div>
            </div>
            <div class="ab-panel-body">
                <!-- Not Ekleme Formu -->
                <div class="ab-add-note-form" style="display:none;">
                    <form method="post" action="">
                        <?php wp_nonce_field('add_customer_note', 'note_nonce'); ?>
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="note_content">Not İçeriği</label>
                                <textarea name="note_content" id="note_content" rows="4" required></textarea>
                            </div>
                        </div>
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="note_type">Görüşme Sonucu</label>
                                <select name="note_type" id="note_type" required>
                                    <option value="">Seçiniz</option>
                                    <option value="positive">Olumlu</option>
                                    <option value="neutral">Durumu Belirsiz</option>
                                    <option value="negative">Olumsuz</option>
                                </select>
                            </div>
                            <div class="ab-form-group" id="rejection_reason_container" style="display:none;">
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
                        <div class="ab-form-actions">
                            <button type="button" id="cancel-note" class="ab-btn ab-btn-secondary">
                                <i class="fas fa-times"></i> İptal
                            </button>
                            <button type="submit" name="add_note" class="ab-btn ab-btn-primary">
                                <i class="fas fa-plus"></i> Not Ekle
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Notlar Listesi -->
                <div class="ab-notes-list">
                    <?php if (empty($customer_notes)): ?>
                    <div class="ab-empty-state">
                        <p>Henüz görüşme notu bulunmuyor.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($customer_notes as $note): ?>
                        <div class="ab-note ab-note-<?php echo esc_attr($note->note_type); ?>">
                            <div class="ab-note-header">
                                <div class="ab-note-meta">
                                    <span class="ab-note-author"><i class="fas fa-user"></i> <?php echo esc_html($note->user_name); ?></span>
                                    <span class="ab-note-date"><i class="fas fa-calendar"></i> <?php echo date('d.m.Y H:i', strtotime($note->created_at)); ?></span>
                                </div>
                                <span class="ab-badge ab-badge-note-<?php echo esc_attr($note->note_type); ?>">
                                    <?php 
                                    switch ($note->note_type) {
                                        case 'positive': echo '<i class="fas fa-check"></i> Olumlu'; break;
                                        case 'neutral': echo '<i class="fas fa-minus"></i> Belirsiz'; break;
                                        case 'negative': echo '<i class="fas fa-times"></i> Olumsuz'; break;
                                        default: echo ucfirst($note->note_type); break;
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="ab-note-content">
                                <?php echo nl2br(esc_html($note->note_content)); ?>
                            </div>
                            <?php if (!empty($note->rejection_reason)): ?>
                            <div class="ab-note-reason">
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
    
<!-- Dosya Yükleme Modal -->
<div id="file-upload-modal" class="ab-modal">
    <div class="ab-modal-content">
        <div class="ab-modal-header">
            <h3><i class="fas fa-cloud-upload-alt"></i> Dosya Yükle</h3>
            <button type="button" class="ab-modal-close">&times;</button>
        </div>
        <div class="ab-modal-body">
            <form id="file-upload-form" enctype="multipart/form-data">
                <?php wp_nonce_field('file_upload_action', 'file_upload_nonce'); ?>
                <input type="hidden" name="ajax_upload_files" value="1">
                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                
                <div class="ab-file-upload-container">
                    <div class="ab-file-upload-area" id="file-upload-area-modal">
                        <div class="ab-file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="ab-file-upload-text">
                            Dosya yüklemek için tıklayın veya sürükleyin
                            <div class="ab-file-upload-info"><?php echo esc_html($supported_formats_text); ?> formatları desteklenir (Maks. 5MB, maksimum 5 dosya)</div>
                        </div>
                        <input type="file" name="customer_files[]" id="customer_files_modal" class="ab-file-upload" multiple
                            accept="<?php echo esc_attr($accept_attribute); ?>">
                    </div>
                    
                    <div class="ab-file-preview-container">
                        <div id="file-count-warning-modal" class="ab-file-warning" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> En fazla 5 dosya yükleyebilirsiniz.
                        </div>
                        <div class="ab-selected-files" id="selected-files-container-modal"></div>
                    </div>
                </div>
                
                <div class="ab-progress-container" style="display:none;">
                    <div class="ab-progress-bar">
                        <div class="ab-progress-fill"></div>
                    </div>
                    <div class="ab-progress-text">Yükleniyor... 0%</div>
                </div>
            </form>
        </div>
        <div class="ab-modal-footer">
            <button type="button" class="ab-btn ab-btn-secondary" id="close-upload-modal-btn">
                <i class="fas fa-times"></i> Kapat
            </button>
            <button type="button" class="ab-btn ab-btn-primary" id="upload-files-btn">
                <i class="fas fa-upload"></i> Yükle
            </button>
        </div>
    </div>
</div>

<!-- Dosya Silme Onay Modal -->
<div id="file-delete-confirm-modal" class="ab-modal">
    <div class="ab-modal-content">
        <div class="ab-modal-header">
            <h3><i class="fas fa-trash"></i> Dosya Sil</h3>
            <button type="button" class="ab-modal-close">&times;</button>
        </div>
        <div class="ab-modal-body">
            <p>Bu dosyayı silmek istediğinizden emin misiniz?</p>
            <p>Bu işlem geri alınamaz.</p>
            <form id="file-delete-form">
                <?php wp_nonce_field('file_delete_action', 'file_delete_nonce'); ?>
                <input type="hidden" name="ajax_delete_file" value="1">
                <input type="hidden" name="file_id" id="delete_file_id" value="">
            </form>
        </div>
        <div class="ab-modal-footer">
            <button type="button" class="ab-btn ab-btn-secondary ab-modal-close-btn">
                <i class="fas fa-times"></i> İptal
            </button>
            <button type="button" class="ab-btn ab-btn-danger" id="confirm-delete-btn">
                <i class="fas fa-trash"></i> Sil
            </button>
        </div>
    </div>
</div>
</div>


<style>
/* Temel Stiller */
.ab-customer-details {
    margin-top: 20px;
    font-family: inherit;
    color: #333;
}

/* Geri dön butonu */
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

.ab-customer-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ab-customer-title h1 {
    font-size: 24px;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-customer-title h1 i {
    color: #4caf50;
}

.ab-customer-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.ab-customer-meta i {
    color: #666;
    margin-right: 3px;
}

.ab-customer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Panel Stilleri */
.ab-panels {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.ab-panel {
    background-color: #fff;
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05);
    border-left: 3px solid var(--panel-color, #ddd);
}

/* Panel tiplerine göre renk şemaları, CSS değişkeni (--panel-color) kullanılır */
.ab-panel-personal {
    background-color: rgba(var(--panel-color-rgb, 52, 152, 219), 0.02);
}
.ab-panel-personal .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 52, 152, 219), 0.05);
}
.ab-panel-personal .ab-panel-header h3 i {
    color: var(--panel-color, #3498db);
}

.ab-panel-corporate {
    background-color: rgba(var(--panel-color-rgb, 76, 175, 80), 0.02);
}
.ab-panel-corporate .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 76, 175, 80), 0.05);
}
.ab-panel-corporate .ab-panel-header h3 i {
    color: var(--panel-color, #4caf50);
}

.ab-panel-family {
    background-color: rgba(var(--panel-color-rgb, 255, 152, 0), 0.02);
}
.ab-panel-family .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 255, 152, 0), 0.05);
}
.ab-panel-family .ab-panel-header h3 i {
    color: var(--panel-color, #ff9800);
}

.ab-panel-vehicle {
    background-color: rgba(var(--panel-color-rgb, 231, 76, 60), 0.02);
}
.ab-panel-vehicle .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 231, 76, 60), 0.05);
}
.ab-panel-vehicle .ab-panel-header h3 i {
    color: var(--panel-color, #e74c3c);
}

.ab-panel-home {
    background-color: rgba(var(--panel-color-rgb, 156, 39, 176), 0.02);
}
.ab-panel-home .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 156, 39, 176), 0.05);
}
.ab-panel-home .ab-panel-header h3 i {
    color: var(--panel-color, #9c27b0);
}

/* Evcil Hayvan panel stili */
.ab-panel-pet {
    background-color: rgba(var(--panel-color-rgb, 233, 30, 99), 0.02);
}
.ab-panel-pet .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 233, 30, 99), 0.05);
}
.ab-panel-pet .ab-panel-header h3 i {
    color: var(--panel-color, #e91e63);
}

/* Dosya Arşivi panel stili */
.ab-panel-documents {
    background-color: rgba(var(--panel-color-rgb, 96, 125, 139), 0.02);
}
.ab-panel-documents .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 96, 125, 139), 0.05);
}
.ab-panel-documents .ab-panel-header h3 i {
    color: var(--panel-color, #607d8b);
}

.ab-full-panel {
    grid-column: 1 / -1;
}

.ab-panel-header {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ab-panel-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-panel-actions {
    display: flex;
    gap: 5px;
}

.ab-panel-body {
    padding: 15px;
}

/* Bilgi Grid */
.ab-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.ab-info-item {
    margin-bottom: 5px;
}

.ab-full-width {
    grid-column: 1 / -1;
}

.ab-info-label {
    font-weight: 600;
    font-size: 13px;
    color: #666;
    margin-bottom: 5px;
}

.ab-info-value {
    font-size: 14px;
}

.ab-info-value a {
    color: #2271b1;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.ab-info-value a:hover {
    text-decoration: underline;
    color: #135e96;
}

.no-value {
    color: #999;
    font-style: italic;
}

/* Badge stilleri */
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

.ab-badge i {
    margin-right: 3px;
    font-size: 10px;
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

/* Notlar Stilleri */
.ab-notes-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.ab-note {
    border: 1px solid #eee;
    border-radius: 4px;
    padding: 15px;
    position: relative;
    background-color: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-note-positive {
    border-left: 4px solid #4caf50;
}

.ab-note-negative {
    border-left: 4px solid #f44336;
}

.ab-note-neutral {
    border-left: 4px solid #ff9800;
}

.ab-note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.ab-note-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 13px;
    color: #666;
}

.ab-note-meta i {
    margin-right: 3px;
}

.ab-note-content {
    margin-bottom: 10px;
    line-height: 1.5;
}

.ab-note-reason {
    font-size: 12px;
    color: #666;
    padding-top: 8px;
    border-top: 1px dashed #eee;
}

/* Badge Stilleri */
.ab-badge-note-positive {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-note-negative {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-note-neutral {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-high {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-priority-medium {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-low {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-expired {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-expiring {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-overdue {
    background-color: #ffeef0;
    color: #cb2431;
}

/* Çocuk listesi */
.ab-children-list {
    margin: 0;
    padding-left: 20px;
}

.ab-children-list li {
    margin-bottom: 5px;
}

/* Form stilleri */
.ab-add-note-form {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
    border: 1px solid #eee;
}

.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.ab-form-group {
    flex: 1;
    min-width: 200px;
}

.ab-form-group.ab-full-width {
    flex-basis: 100%;
    width: 100%;
}

.ab-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 13px;
}

.ab-form-group select,
.ab-form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.ab-form-group textarea {
    min-height: 80px;
    resize: vertical;
}

.ab-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
}

/* Tablo stilleri */
.ab-table-container {
    overflow-x: auto;
    margin-bottom: 15px;
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

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

tr.expired td {
    background-color: #fff8f8;
}

tr.expiring-soon td {
    background-color: #fffbf0;
}

tr.overdue td {
    background-color: #fff8f8;
}

.ab-amount {
    font-weight: 600;
    color: #0366d6;
}

/* Pozitif/Negatif değerler */
.ab-positive {
    color: #22863a;
    font-weight: 500;
}

.ab-negative {
    color: #cb2431;
    font-weight: 500;
}

/* Boş durum gösterimi */
.ab-empty-state {
    text-align: center;
    padding: 20px;
    color: #666;
    font-style: italic;
}

.ab-empty-state p {
    margin-bottom: 15px;
}

.ab-empty-state i {
    font-size: 32px;
    color: #ddd;
    margin-bottom: 10px;
}

/* Buton stilleri */
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

.ab-btn-danger {
    background-color: #f44336;
    border-color: #e53935;
    color: white;
}

.ab-btn-danger:hover {
    background-color: #d32f2f;
    color: white;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
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

/* Dosya Arşivi Stilleri */
.ab-files-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
}

.ab-file-card {
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    background-color: #fff;
    display: flex;
    flex-direction: column;
}

.ab-file-card-header {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ab-file-meta {
    flex: 1;
    min-width: 0; /* Önemli: metin taşmasını önlemek için */
}

.ab-file-type-icon {
    font-size: 20px;
    color: #666;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    border-radius: 4px;
}

.ab-file-type-icon .fa-file-pdf { color: #f44336; }
.ab-file-type-icon .fa-file-word { color: #2196f3; }
.ab-file-type-icon .fa-file-image { color: #4caf50; }
.ab-file-type-icon .fa-file-excel { color: #28a745; }
.ab-file-type-icon .fa-file-alt { color: #6c757d; }
.ab-file-type-icon .fa-file-archive { color: #ff9800; }

.ab-file-name {
    font-weight: 500;
    margin-bottom: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
    color: #333;
}

.ab-file-info {
    font-size: 11px;
    color: #666;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.ab-file-info i {
    color: #999;
    font-size: 10px;
    margin-right: 2px;
}

.ab-file-preview {
    height: 180px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    padding: 10px;
}

.ab-file-preview img {
    max-width: 100%;
    max-height: 180px;
    object-fit: contain;
}

.ab-file-icon-large {
    height: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    color: #666;
}

.ab-file-icon-large i {
    font-size: 64px;
    margin-bottom: 10px;
}

.ab-file-icon-large .fa-file-pdf { color: #f44336; }
.ab-file-icon-large .fa-file-word { color: #2196f3; }
.ab-file-icon-large .fa-file-image { color: #4caf50; }
.ab-file-icon-large .fa-file-excel { color: #28a745; }
.ab-file-icon-large .fa-file-alt { color: #6c757d; }
.ab-file-icon-large .fa-file-archive { color: #ff9800; }

.ab-file-icon-large span {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
}

.ab-file-description {
    padding: 10px 12px;
    font-size: 13px;
    color: #666;
    border-top: 1px solid #f0f0f0;
    background-color: #fafafa;
}

.ab-file-description p {
    margin: 0;
    font-style: italic;
}

.ab-file-card-actions {
    padding: 8px 12px;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: auto;
    border-top: 1px solid #f0f0f0;
    background-color: #f8f9fa;
}

.ab-file-delete-form {
    margin: 0;
}

/* Modal Stilleri */
.ab-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    overflow-y: auto;
    padding: 20px;
    box-sizing: border-box;
}

.ab-modal-content {
    position: relative;
    background-color: #fff;
    margin: 30px auto;
    max-width: 600px;
    border-radius: 6px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
    animation: modalFadeIn 0.3s;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.ab-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ab-modal-header h3 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-modal-header h3 i {
    color: #4caf50;
}

.ab-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    line-height: 1;
    padding: 0;
    cursor: pointer;
    color: #999;
}

.ab-modal-close:hover {
    color: #333;
}

.ab-modal-body {
    padding: 20px;
}

.ab-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* File Upload Area için Modal Stilleri */
.ab-file-upload-area {
    border: 2px dashed #ddd;
    padding: 30px 20px;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background-color: #fafafa;
    position: relative;
}

.ab-file-upload-area:hover, .ab-file-upload-area.ab-drag-over {
    border-color: #4caf50;
    background-color: #f0f8f1;
}

.ab-file-upload-icon {
    font-size: 36px;
    color: #999;
    margin-bottom: 10px;
}

.ab-file-upload-area:hover .ab-file-upload-icon {
    color: #4caf50;
}

.ab-file-upload-text {
    font-size: 15px;
    font-weight: 500;
    color: #555;
}

.ab-file-upload-info {
    font-size: 12px;
    color: #888;
    margin-top: 5px;
}

.ab-file-upload {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    opacity: 0;
    cursor: pointer;
}

.ab-file-upload-container {
    position: relative;
    margin-bottom: 15px;
}

.ab-file-preview-container {
    margin-top: 15px;
}

.ab-file-warning {
    margin-bottom: 15px;
    padding: 10px;
    background-color: #fffde7;
    border-left: 3px solid #ffc107;
    color: #856404;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 4px;
}

.ab-file-warning i {
    color: #ffc107;
}

.ab-selected-files {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}

.ab-file-item-preview {
    position: relative;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.ab-file-name-preview {
    font-weight: 500;
    margin-bottom: 8px;
    word-break: break-all;
    color: #333;
}

.ab-file-size-preview {
    font-size: 11px;
    color: #777;
    margin-bottom: 8px;
}

.ab-file-desc-input {
    margin-top: 10px;
}

.ab-file-desc-input input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
}

.ab-file-remove {
    position: absolute;
    top: 8px;
    right: 8px;
    background-color: #f44336;
    color: white;
    border: none;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 10px;
    transition: all 0.2s;
}

.ab-file-remove:hover {
    background-color: #d32f2f;
}

.ab-file-icon-preview {
    font-size: 24px;
    margin-right: 10px;
    color: #666;
}

.ab-file-icon-pdf { color: #f44336; }
.ab-file-icon-word { color: #2196f3; }
.ab-file-icon-image { color: #4caf50; }
.ab-file-icon-excel { color: #28a745; }
.ab-file-icon-alt { color: #6c757d; }
.ab-file-icon-archive { color: #ff9800; }

.ab-file-icon-preview i {
    margin-bottom: 10px;
}

/* İlerleme Çubuğu */
.ab-progress-container {
    margin-top: 20px;
}

.ab-progress-bar {
    height: 8px;
    background-color: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.ab-progress-fill {
    height: 100%;
    background-color: #4caf50;
    width: 0;
    transition: width 0.3s;
}

.ab-progress-text {
    font-size: 12px;
    color: #666;
    text-align: center;
}

/* Ajax Cevap Konteyneri */
#ajax-response-container {
    margin-bottom: 20px;
}

/* Lightbox */
.ab-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.85);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.ab-lightbox-content {
    position: relative;
    max-width: 90%;
    max-height: 90%;
    border-radius: 6px;
    overflow: hidden;
    background-color: #fff;
    padding: 5px;
}

.ab-lightbox-content img {
    max-width: 100%;
    max-height: calc(90vh - 60px);
    display: block;
    object-fit: contain;
}

.ab-lightbox-caption {
    padding: 10px;
    text-align: center;
    color: #333;
    font-weight: 500;
    font-size: 14px;
    background-color: #f8f9fa;
    border-top: 1px solid #eee;
}

.ab-lightbox-close {
    position: absolute;
    top: 0;
    right: 0;
    font-size: 24px;
    color: white;
    cursor: pointer;
    width: 32px;
    height: 32px;
    background-color: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 10px;
}

.ab-lightbox-close:hover {
    background-color: rgba(0, 0, 0, 0.8);
}

/* Mobil uyumluluk */
@media (max-width: 992px) {
    .ab-panels {
        grid-template-columns: 1fr;
    }
    
    .ab-panel {
        width: 100%;
    }
    
    .ab-files-gallery {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
    
    .ab-modal-content {
        max-width: 95%;
        margin: 10px auto;
    }
}

@media (max-width: 768px) {
    .ab-customer-header {
        flex-direction: column;
    }

    .ab-customer-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .ab-info-grid {
        grid-template-columns: 1fr;
    }

    .ab-form-row {
        flex-direction: column;
        gap: 10px;
    }

    .ab-form-group {
        width: 100%;
    }

    .ab-notes-list {
        grid-template-columns: 1fr;
    }

    .ab-crm-table {
        font-size: 12px;
    }

    .ab-crm-table th,
    .ab-crm-table td {
        padding: 8px 6px;
    }
    
    .ab-files-gallery {
        grid-template-columns: 1fr;
    }
    
    .ab-selected-files {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 576px) {
    .ab-customer-title h1 {
        font-size: 20px;
    }

    .ab-btn {
        padding: 6px 10px;
        font-size: 12px;
    }

    .ab-action-btn {
        width: 26px;
        height: 26px;
    }
    
    .ab-file-card-actions {
        flex-direction: column;
    }
    
    .ab-file-card-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-modal-header, .ab-modal-body, .ab-modal-footer {
        padding: 12px;
    }
    
    .ab-modal-footer {
        flex-direction: column;
    }
    
    .ab-modal-footer .ab-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Not ekleme formu aç/kapat
    $('#toggle-note-form').on('click', function() {
        $('.ab-add-note-form').slideToggle();
    });

    $('#cancel-note').on('click', function() {
        $('.ab-add-note-form').slideUp();
        $('#note_content').val('');
        $('#note_type').val('');
        $('#rejection_reason').val('');
    });

    // Not türü değiştiğinde, olumsuz olma sebebi göster/gizle
    $('#note_type').on('change', function() {
        if ($(this).val() === 'negative') {
            $('#rejection_reason_container').slideDown();
            $('#rejection_reason').prop('required', true);
        } else {
            $('#rejection_reason_container').slideUp();
            $('#rejection_reason').prop('required', false);
        }
    });
    
    // Panel renklerini CSS değişkenlerine dönüştür
    function hexToRgb(hex) {
        var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? parseInt(result[1], 16) + ',' + parseInt(result[2], 16) + ',' + parseInt(result[3], 16) : null;
    }
    
    // Panel renklerini uygula
    $('.ab-panel-personal').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($personal_color); ?>'));
    $('.ab-panel-corporate').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($corporate_color); ?>'));
    $('.ab-panel-family').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($family_color); ?>'));
    $('.ab-panel-vehicle').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($vehicle_color); ?>'));
    $('.ab-panel-home').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($home_color); ?>'));
    $('.ab-panel-pet').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($pet_color); ?>'));
    $('.ab-panel-documents').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($doc_color); ?>'));
    
    // Resim önizlemeleri için lightbox
    $(document).on('click', '.ab-file-preview img', function() {
        var imgSrc = $(this).attr('src');
        var imgTitle = $(this).attr('alt');
        
        $('body').append('<div class="ab-lightbox"><div class="ab-lightbox-content"><img src="' + imgSrc + 
                        '" alt="' + imgTitle + '"><div class="ab-lightbox-caption">' + imgTitle + 
                        '</div><div class="ab-lightbox-close">&times;</div></div></div>');
        
        $('.ab-lightbox').fadeIn(300);
    });
    
    // Lightbox kapat
    $(document).on('click', '.ab-lightbox-close, .ab-lightbox', function(e) {
        if (e.target === this) {
            $('.ab-lightbox').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });
    
    // Modal Açma Kapama İşlemleri
    function openModal(modalId) {
        $('#' + modalId).fadeIn(300);
        $('body').addClass('modal-open');
    }
    
    function closeModal(modalId) {
        $('#' + modalId).fadeOut(300);
        $('body').removeClass('modal-open');
    }
    
    // Dosya Yükleme Modal
    $('#open-file-upload-modal, .open-file-upload-modal').on('click', function() {
        openModal('file-upload-modal');
    });
    
    $('.ab-modal-close, .ab-modal-close-btn').on('click', function() {
        closeModal($(this).closest('.ab-modal').attr('id'));
    });
    
    // Kapat butonu için olay
    $('#close-upload-modal-btn').on('click', function() {
        closeModal('file-upload-modal');
        window.location.reload();
    });
    
    // ESC tuşu ile modalı kapat
    $(document).keydown(function(e) {
        if (e.keyCode === 27) { // ESC
            $('.ab-modal').fadeOut(300);
            $('body').removeClass('modal-open');
        }
    });
    
    // Modal dışına tıklayınca kapat
    $('.ab-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal($(this).attr('id'));
        }
    });
    
    // Dosya yükleme alanı sürükle bırak - Modal içinde
    var fileUploadAreaModal = document.getElementById('file-upload-area-modal');
    var fileInputModal = document.getElementById('customer_files_modal');
    
    if (fileUploadAreaModal && fileInputModal) {
        fileUploadAreaModal.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInputModal.click();
        });
        
        fileUploadAreaModal.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.add('ab-drag-over');
        });
        
        fileUploadAreaModal.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.remove('ab-drag-over');
        });
        
        fileUploadAreaModal.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.remove('ab-drag-over');
            
            var files = e.dataTransfer.files;
            
            // Dosya sayısı kontrolü
            if (files.length > 5) {
                showFileCountWarningModal();
                // Sadece ilk 5 dosyayı al
                var maxFiles = [];
                for (var i = 0; i < 5; i++) {
                    maxFiles.push(files[i]);
                }
                
                // FileList kopyalanamaz, o yüzden Data Transfer kullanarak yeni bir dosya listesi oluştur
                const dataTransfer = new DataTransfer();
                maxFiles.forEach(file => dataTransfer.items.add(file));
                fileInputModal.files = dataTransfer.files;
            } else {
                fileInputModal.files = files;
            }
            
            updateFilePreviewModal();
        });
        
        // Dosya seçildiğinde önizleme göster
        fileInputModal.addEventListener('change', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Dosya sayısı kontrolü
            if (this.files.length > 5) {
                showFileCountWarningModal();
                
                // Sadece ilk 5 dosyayı al
                const dataTransfer = new DataTransfer();
                for (var i = 0; i < 5; i++) {
                    dataTransfer.items.add(this.files[i]);
                }
                this.files = dataTransfer.files;
            } else {
                hideFileCountWarningModal();
            }
            
            updateFilePreviewModal();
        });
    }
    
    function showFileCountWarningModal() {
        $('#file-count-warning-modal').slideDown();
    }
    
    function hideFileCountWarningModal() {
        $('#file-count-warning-modal').slideUp();
    }
    
    function updateFilePreviewModal() {
        var filesContainer = document.getElementById('selected-files-container-modal');
        filesContainer.innerHTML = '';
        
        var files = document.getElementById('customer_files_modal').files;
        var allowedTypes = <?php echo json_encode($allowed_mime_types); ?>;
        var allowedExtensions = <?php echo json_encode($allowed_file_types); ?>;
        var maxSize = 5 * 1024 * 1024; // 5MB
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var fileSize = formatFileSize(file.size);
            var fileType = file.type;
            var fileExt = getFileExtFromType(fileType);
            var isValidType = allowedTypes.includes(fileType);
            var isValidSize = file.size <= maxSize;
            
            var itemDiv = document.createElement('div');
            itemDiv.className = 'ab-file-item-preview' + (!isValidType || !isValidSize ? ' ab-file-invalid' : '');
            
            var iconClass = 'fa-file';
            if (fileType === 'application/pdf') iconClass = 'fa-file-pdf ab-file-icon-pdf';
            else if (fileType === 'application/msword' || fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') iconClass = 'fa-file-word ab-file-icon-word';
            else if (fileType === 'application/vnd.ms-excel' || fileType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') iconClass = 'fa-file-excel ab-file-icon-excel';
            else if (fileType === 'text/plain') iconClass = 'fa-file-alt ab-file-icon-alt';
            else if (fileType === 'application/zip') iconClass = 'fa-file-archive ab-file-icon-archive';
            else if (fileType.startsWith('image/')) iconClass = 'fa-file-image ab-file-icon-image';
            
            var content = '<div class="ab-file-icon-preview"><i class="fas ' + iconClass + '"></i></div>';
            content += '<div class="ab-file-name-preview">' + file.name + '</div>';
            content += '<div class="ab-file-size-preview">' + fileSize + '</div>';
            
            if (!isValidType) {
                content += '<div class="ab-file-error"><i class="fas fa-exclamation-triangle"></i> Geçersiz dosya formatı. Sadece ' + allowedExtensions.map(ext => ext.toUpperCase()).join(', ') + ' dosyaları yüklenebilir.</div>';
            } else if (!isValidSize) {
                content += '<div class="ab-file-error"><i class="fas fa-exclamation-triangle"></i> Dosya boyutu çok büyük. Maksimum 5MB olmalıdır.</div>';
            } else {
                content += '<div class="ab-file-desc-input">';
                content += '<input type="text" name="file_descriptions[]" placeholder="Dosya açıklaması (isteğe bağlı)" class="ab-input">';
                content += '</div>';
            }
            
            var removeBtn = document.createElement('button');
            removeBtn.className = 'ab-file-remove';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.dataset.index = i;
            removeBtn.addEventListener('click', function(e) {
                removeSelectedFileModal(parseInt(this.dataset.index));
            });
            
            itemDiv.innerHTML = content;
            itemDiv.appendChild(removeBtn);
            
            filesContainer.appendChild(itemDiv);
        }
    }
    
    function removeSelectedFileModal(index) {
        const dt = new DataTransfer();
        const files = document.getElementById('customer_files_modal').files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== index) dt.items.add(files[i]);
        }
        
        document.getElementById('customer_files_modal').files = dt.files;
        hideFileCountWarningModal();
        updateFilePreviewModal();
    }
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        else if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
        else return (bytes / 1048576).toFixed(2) + ' MB';
    }
    
    function getFileExtFromType(type) {
        switch (type) {
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'application/pdf':
                return 'pdf';
            case 'application/msword':
                return 'doc';
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return 'docx';
            case 'application/vnd.ms-excel':
                return 'xls';
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                return 'xlsx';
            case 'text/plain':
                return 'txt';
            case 'application/zip':
                return 'zip';
            default:
                return '';
        }
    }
    
    // AJAX Dosya Yükleme
    $('#upload-files-btn').on('click', function() {
        var fileInput = document.getElementById('customer_files_modal');
        var files = fileInput.files;
        
        if (files.length === 0) {
            showResponse('Lütfen yüklenecek dosyaları seçin.', 'error');
            return;
        }
        
        var formData = new FormData($('#file-upload-form')[0]);
        
        // İlerleme çubuğunu göster
        $('.ab-progress-container').show();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        $('.ab-progress-fill').css('width', percent + '%');
                        $('.ab-progress-text').text('Yükleniyor... ' + percent + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                try {
                    var data = JSON.parse(response);
                    
                    if (data.success) {
                        showResponse('Yükleme Tamamlandı.', 'success');
                        updateFilesGallery(data.files);
                        
                        // Formu sıfırla
                        $('#file-upload-form')[0].reset();
                        $('#selected-files-container-modal').empty();
                    } else {
                        showResponse(data.message, 'error');
                    }
                } catch (e) {
                    showResponse('Bir hata oluştu.', 'error');
                }
                
                // İlerleme çubuğunu sıfırla ve gizle
                $('.ab-progress-fill').css('width', '0%');
                $('.ab-progress-text').text('Yükleniyor... 0%');
                $('.ab-progress-container').hide();
            },
            error: function() {
                showResponse('Sunucu hatası. Lütfen daha sonra tekrar deneyin.', 'error');
                
                // İlerleme çubuğunu sıfırla ve gizle
                $('.ab-progress-fill').css('width', '0%');
                $('.ab-progress-text').text('Yükleniyor... 0%');
                $('.ab-progress-container').hide();
            }
        });
    });
    
    // Dosya Silme İşlemi
    $(document).on('click', '.delete-file', function() {
        var fileId = $(this).data('file-id');
        $('#delete_file_id').val(fileId);
        openModal('file-delete-confirm-modal');
    });
    
    $('#confirm-delete-btn').on('click', function() {
        var formData = new FormData($('#file-delete-form')[0]);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                try {
                    var data = JSON.parse(response);
                    
                    if (data.success) {
                        showResponse(data.message, 'success');
                        removeFileFromGallery($('#delete_file_id').val());
                        
                        // Modal'ı kapat ve sayfayı yenile
                        setTimeout(function() {
                            closeModal('file-delete-confirm-modal');
                            window.location.reload();
                        }, 1000);
                    } else {
                        showResponse(data.message, 'error');
                    }
                } catch (e) {
                    showResponse('Bir hata oluştu.', 'error');
                }
            },
            error: function() {
                showResponse('Sunucu hatası. Lütfen daha sonra tekrar deneyin.', 'error');
            }
        });
    });
    
    function showResponse(message, type) {
        $('#ajax-response-container').html('<div class="ab-notice ab-' + type + '">' + message + '</div>');
        
        setTimeout(function() {
            $('#ajax-response-container .ab-notice').fadeOut(500);
        }, 5000);
    }
    
    function updateFilesGallery(files) {
        var container = $('#files-container');
        
        if (files.length > 0) {
            // Boş durum mesajını kaldır
            container.find('.ab-empty-state').remove();
            
            // Dosya galerisi yoksa oluştur
            if (container.find('.ab-files-gallery').length === 0) {
                container.append('<div class="ab-files-gallery"></div>');
            }
            
            var gallery = container.find('.ab-files-gallery');
            
            // Dosyaları ekle
            files.forEach(function(file) {
                var fileCard = createFileCard(file);
                gallery.prepend(fileCard); // Yeni dosyaları başa ekle
            });
        }
    }
    
    function createFileCard(file) {
        var fileCard = $('<div class="ab-file-card" data-file-id="' + file.id + '"></div>');
        
        var header = $('<div class="ab-file-card-header"></div>');
        var typeIcon = $('<div class="ab-file-type-icon"><i class="fas ' + getIconClassForType(file.type) + '"></i></div>');
        var meta = $('<div class="ab-file-meta"></div>');
        meta.append('<div class="ab-file-name">' + file.name + '</div>');
        meta.append('<div class="ab-file-info"><span><i class="fas fa-calendar-alt"></i> ' + file.date + '</span><span><i class="fas fa-weight"></i> ' + file.size + '</span></div>');
        header.append(typeIcon).append(meta);
        fileCard.append(header);
        
        if (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png') {
            fileCard.append('<div class="ab-file-preview"><img src="' + file.path + '" alt="' + file.name + '"></div>');
        } else {
            fileCard.append('<div class="ab-file-icon-large"><i class="fas ' + getIconClassForType(file.type) + '"></i><span>.' + file.type + '</span></div>');
        }
        
        if (file.description) {
            fileCard.append('<div class="ab-file-description"><p>' + file.description + '</p></div>');
        }
        
        var actions = $('<div class="ab-file-card-actions"></div>');
        actions.append('<a href="' + file.path + '" target="_blank" class="ab-btn ab-btn-sm ab-btn-primary"><i class="fas ' + (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png' ? 'fa-eye' : 'fa-download') + '"></i>' + (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png' ? 'Görüntüle' : 'İndir') + '</a>');
        actions.append('<button type="button" class="ab-btn ab-btn-sm ab-btn-danger delete-file" data-file-id="' + file.id + '"><i class="fas fa-trash"></i> Sil</button>');
        
        fileCard.append(actions);
        
        return fileCard;
    }
    
    function getIconClassForType(type) {
        switch (type) {
            case 'pdf':
                return 'fa-file-pdf';
            case 'doc':
            case 'docx':
                return 'fa-file-word';
            case 'jpg':
            case 'jpeg':
            case 'png':
                return 'fa-file-image';
            case 'xls':
            case 'xlsx':
                return 'fa-file-excel';
            case 'txt':
                return 'fa-file-alt';
            case 'zip':
                return 'fa-file-archive';
            default:
                return 'fa-file';
        }
    }
    
    function removeFileFromGallery(fileId) {
        var fileCard = $('.ab-file-card[data-file-id="' + fileId + '"]');
        fileCard.fadeOut(300, function() {
            $(this).remove();
            
            // Eğer daha dosya kalmadıysa boş durum mesajı göster
            if ($('.ab-files-gallery').children().length === 0) {
                $('.ab-files-gallery').remove();
                $('#files-container').html(`
                    <div class="ab-empty-state">
                        <p><i class="fas fa-file-upload"></i><br>Henüz yüklenmiş dosya bulunmuyor.</p>
                        <button type="button" class="ab-btn open-file-upload-modal">
                            <i class="fas fa-plus"></i> Dosya Yükle
                        </button>
                    </div>
                `);
                
                // Dosya yükleme butonu tıklama olayını tekrar ekle
                $('.open-file-upload-modal').on('click', function() {
                    openModal('file-upload-modal');
                });
            }
        });
    }
});
</script>