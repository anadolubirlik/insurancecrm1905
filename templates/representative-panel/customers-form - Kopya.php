<?php
/**
 * Müşteri Ekleme/Düzenleme Formu
 * @version 2.5.0
 */

// Yetki kontrolü
if (!is_user_logged_in()) {
    return;
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$customer_id = $editing ? intval($_GET['id']) : 0;

// Form gönderildiğinde işlem yap
if (isset($_POST['save_customer']) && isset($_POST['customer_nonce']) && wp_verify_nonce($_POST['customer_nonce'], 'save_customer')) {
    
    // Temel müşteri bilgileri
    $customer_data = array(
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name' => sanitize_text_field($_POST['last_name']),
        'email' => sanitize_email($_POST['email']),
        'phone' => sanitize_text_field($_POST['phone']),
        'address' => sanitize_textarea_field($_POST['address']),
        'tc_identity' => sanitize_text_field($_POST['tc_identity']),
        'category' => sanitize_text_field($_POST['category']),
        'status' => sanitize_text_field($_POST['status']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null
    );
    
    // Kurumsal müşteri ise vergi bilgilerini ekle
    if ($customer_data['category'] === 'kurumsal') {
        $customer_data['tax_office'] = !empty($_POST['tax_office']) ? sanitize_text_field($_POST['tax_office']) : '';
        $customer_data['tax_number'] = !empty($_POST['tax_number']) ? sanitize_text_field($_POST['tax_number']) : '';
        $customer_data['company_name'] = !empty($_POST['company_name']) ? sanitize_text_field($_POST['company_name']) : '';
    }
    
    // Kişisel bilgiler
    $customer_data['birth_date'] = !empty($_POST['birth_date']) ? sanitize_text_field($_POST['birth_date']) : null;
    $customer_data['gender'] = !empty($_POST['gender']) ? sanitize_text_field($_POST['gender']) : null;
    $customer_data['occupation'] = !empty($_POST['occupation']) ? sanitize_text_field($_POST['occupation']) : null;
    
    // Kadın ve gebe ise
    if ($customer_data['gender'] === 'female') {
        $customer_data['is_pregnant'] = isset($_POST['is_pregnant']) ? 1 : 0;
        if ($customer_data['is_pregnant'] == 1) {
            $customer_data['pregnancy_week'] = !empty($_POST['pregnancy_week']) ? intval($_POST['pregnancy_week']) : null;
        }
    }
    
    // Aile bilgileri
    $customer_data['spouse_name'] = !empty($_POST['spouse_name']) ? sanitize_text_field($_POST['spouse_name']) : null;
    $customer_data['spouse_tc_identity'] = !empty($_POST['spouse_tc_identity']) ? sanitize_text_field($_POST['spouse_tc_identity']) : null;
    $customer_data['spouse_birth_date'] = !empty($_POST['spouse_birth_date']) ? sanitize_text_field($_POST['spouse_birth_date']) : null;
    $customer_data['children_count'] = !empty($_POST['children_count']) ? intval($_POST['children_count']) : 0;
    
    // Çocuk bilgileri
    $children_names = [];
    $children_birth_dates = [];
    $children_tc_identities = [];
    
    for ($i = 1; $i <= $customer_data['children_count']; $i++) {
        if (!empty($_POST['child_name_' . $i])) {
            $children_names[] = sanitize_text_field($_POST['child_name_' . $i]);
            $children_birth_dates[] = !empty($_POST['child_birth_date_' . $i]) ? sanitize_text_field($_POST['child_birth_date_' . $i]) : '';
            $children_tc_identities[] = !empty($_POST['child_tc_identity_' . $i]) ? sanitize_text_field($_POST['child_tc_identity_' . $i]) : '';
        }
    }
    
    $customer_data['children_names'] = !empty($children_names) ? implode(',', $children_names) : null;
    $customer_data['children_birth_dates'] = !empty($children_birth_dates) ? implode(',', $children_birth_dates) : null;
    $customer_data['children_tc_identities'] = !empty($children_tc_identities) ? implode(',', $children_tc_identities) : null;
    
    // Araç bilgileri
    $customer_data['has_vehicle'] = isset($_POST['has_vehicle']) ? 1 : 0;
    if ($customer_data['has_vehicle'] == 1) {
        $customer_data['vehicle_plate'] = !empty($_POST['vehicle_plate']) ? sanitize_text_field($_POST['vehicle_plate']) : null;
    }
    
    // Ev bilgileri
    $customer_data['owns_home'] = isset($_POST['owns_home']) ? 1 : 0;
    if ($customer_data['owns_home'] == 1) {
        $customer_data['has_dask_policy'] = isset($_POST['has_dask_policy']) ? 1 : 0;
        if ($customer_data['has_dask_policy'] == 1) {
            $customer_data['dask_policy_expiry'] = !empty($_POST['dask_policy_expiry']) ? sanitize_text_field($_POST['dask_policy_expiry']) : null;
        }
        
        $customer_data['has_home_policy'] = isset($_POST['has_home_policy']) ? 1 : 0;
        if ($customer_data['has_home_policy'] == 1) {
            $customer_data['home_policy_expiry'] = !empty($_POST['home_policy_expiry']) ? sanitize_text_field($_POST['home_policy_expiry']) : null;
        }
    }
    
    // Evcil hayvan bilgileri
    $customer_data['has_pet'] = isset($_POST['has_pet']) ? 1 : 0;
    if ($customer_data['has_pet'] == 1) {
        $customer_data['pet_name'] = !empty($_POST['pet_name']) ? sanitize_text_field($_POST['pet_name']) : null;
        $customer_data['pet_type'] = !empty($_POST['pet_type']) ? sanitize_text_field($_POST['pet_type']) : null;
        $customer_data['pet_age'] = !empty($_POST['pet_age']) ? sanitize_text_field($_POST['pet_age']) : null;
    }
    
    // Temsilci kontrolü - temsilciyse ve temsilci seçilmediyse kendi ID'sini ekle
    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && empty($customer_data['representative_id'])) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($current_user_rep_id) {
            $customer_data['representative_id'] = $current_user_rep_id;
        }
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    if ($editing) {
        // Yetki kontrolü
        $can_edit = true;
        if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
            $current_user_rep_id = get_current_user_rep_id();
            $customer_check = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d", $customer_id
            ));
            
            if ($customer_check->representative_id != $current_user_rep_id) {
                $can_edit = false;
                $message = 'Bu müşteriyi düzenleme yetkiniz yok.';
                $message_type = 'error';
            }
        }
        
        if ($can_edit) {
            $customer_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $customer_data, ['id' => $customer_id]);
            
            if ($result !== false) {
                $message = 'Müşteri başarıyla güncellendi.';
                $message_type = 'success';
                
                // Dosya yükleme işlemi
                if (!empty($_FILES['customer_files']) && $customer_id > 0) {
                    handle_customer_file_uploads($customer_id);
                }
                
                // Başarılı işlemden sonra yönlendirme
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=customers&updated=true";</script>';
                exit;
            } else {
                $message = 'Müşteri güncellenirken bir hata oluştu: ' . $wpdb->last_error;
                $message_type = 'error';
            }
        }
    } else {
        // Yeni müşteri ekleme
        $customer_data['created_at'] = current_time('mysql');
        $customer_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $customer_data);
        
        if ($result !== false) {
            $new_customer_id = $wpdb->insert_id;
            $message = 'Müşteri başarıyla eklendi.';
            $message_type = 'success';
            
            // Dosya yükleme işlemi
            if (!empty($_FILES['customer_files']) && $new_customer_id > 0) {
                handle_customer_file_uploads($new_customer_id);
            }
            
            // Başarılı işlemden sonra yönlendirme
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            echo '<script>window.location.href = "?view=customers&added=true";</script>';
            exit;
        } else {
            $message = 'Müşteri eklenirken bir hata oluştu: ' . $wpdb->last_error;
            $message_type = 'error';
        }
    }
}

// Dosya yükleme ve silme işlemi
if (isset($_POST['delete_file']) && isset($_POST['file_nonce']) && wp_verify_nonce($_POST['file_nonce'], 'delete_file')) {
    $file_id = intval($_POST['file_id']);
    delete_customer_file($file_id, $customer_id);
}

// Müşteri bilgilerini al (düzenleme durumunda)
$customer = null;
if ($editing) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $customer_id));
    
    if (!$customer) {
        echo '<div class="ab-notice ab-error">Müşteri bulunamadı.</div>';
        return;
    }
    
    // Yetki kontrolü (temsilci sadece kendi müşterilerini düzenleyebilir)
    if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($customer->representative_id != $current_user_rep_id) {
            echo '<div class="ab-notice ab-error">Bu müşteriyi düzenleme yetkiniz yok.</div>';
            return;
        }
    }
    
    // Müşteri dosyalarını al
    $files = get_customer_files($customer_id);
}

// Temsilcileri al
$representatives = [];
if (current_user_can('administrator') || current_user_can('insurance_manager')) {
    global $wpdb;
    $reps_table = $wpdb->prefix . 'insurance_crm_representatives';
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $reps_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
}

// Admin ayarlarından meslekleri al
function get_occupation_options() {
    $settings = get_option('insurance_crm_settings', array());
    if (!isset($settings['occupation_settings']) || !isset($settings['occupation_settings']['default_occupations'])) {
        // Varsayılan meslekler
        return array('Doktor', 'Mühendis', 'Öğretmen', 'Avukat');
    }
    return $settings['occupation_settings']['default_occupations'];
}

// Ayarlardan izin verilen dosya türlerini al
function get_allowed_file_types() {
    $settings = get_option('insurance_crm_settings', array());
    if (!isset($settings['file_upload_settings']) || !isset($settings['file_upload_settings']['allowed_file_types'])) {
        // Varsayılan dosya türleri
        return array('jpg', 'jpeg', 'pdf', 'docx');
    }
    return $settings['file_upload_settings']['allowed_file_types'];
}

/**
 * Müşterinin dosyalarını getirir
 */
function get_customer_files($customer_id) {
    global $wpdb;
    $files_table = $wpdb->prefix . 'insurance_crm_customer_files';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $files_table WHERE customer_id = %d ORDER BY upload_date DESC",
        $customer_id
    ));
}

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
    
    $allowed_types = get_allowed_file_types();
    $max_file_size = 5 * 1024 * 1024; // 5MB
    $max_file_count = 5; // Maksimum dosya sayısı
    
    $file_count = count($_FILES['customer_files']['name']);
    
    // Dosya sayısını kontrol et
    if ($file_count > $max_file_count) {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">En fazla ' . $max_file_count . ' dosya yükleyebilirsiniz.</div>';
        return;
    }
    
    $upload_count = 0;
    
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
        }
    }
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
    $wpdb->delete(
        $files_table,
        array('id' => $file_id)
    );
    
    return true;
}

// Dosya türüne göre ikon belirleme
function get_file_icon($file_type) {
    switch ($file_type) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'docx':
        case 'doc':
            return 'fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
            return 'fa-file-image';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'zip':
            return 'fa-file-archive';
        case 'txt':
            return 'fa-file-alt';
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

// İzin verilen dosya tiplerini alma ve formatı düzenleme
function get_allowed_file_types_text() {
    $allowed_types = get_allowed_file_types();
    $formatted_types = [];
    
    foreach ($allowed_types as $type) {
        $formatted_types[] = strtoupper($type);
    }
    
    return implode(', ', $formatted_types);
}
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-customer-form-container">
    <!-- Geri dön butonu -->
    <div class="ab-form-header">
        <div class="ab-header-left">
            <h2><i class="fas fa-user-edit"></i> <?php echo $editing ? 'Müşteri Düzenle' : 'Yeni Müşteri Ekle'; ?></h2>
            <div class="ab-breadcrumbs">
                <a href="?view=customers">Müşteriler</a> <i class="fas fa-chevron-right"></i> 
                <span><?php echo $editing ? 'Düzenle: ' . esc_html($customer->first_name . ' ' . $customer->last_name) : 'Yeni Müşteri'; ?></span>
            </div>
        </div>
        <a href="?view=customers" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>

    <?php if (isset($message)): ?>
    <div class="ab-notice ab-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" class="ab-customer-form" enctype="multipart/form-data">
        <?php wp_nonce_field('save_customer', 'customer_nonce'); ?>
        
        <div class="ab-form-content">
            <!-- TEMEL BİLGİLER BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-id-card"></i> Temel Bilgiler</h3>
                </div>
                <div class="ab-section-content">
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="ab-input"
                                value="<?php echo $editing ? esc_attr($customer->first_name) : ''; ?>" required>
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="ab-input"
                                value="<?php echo $editing ? esc_attr($customer->last_name) : ''; ?>" required>
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="tc_identity">TC Kimlik No <span class="required">*</span></label>
                            <input type="text" name="tc_identity" id="tc_identity" class="ab-input"
                                value="<?php echo $editing ? esc_attr($customer->tc_identity) : ''; ?>"
                                pattern="\d{11}" title="TC Kimlik No 11 haneli olmalıdır" required>
                            <div class="ab-form-help">11 haneli TC Kimlik Numarasını giriniz.</div>
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="email">E-posta</label>
                            <input type="email" name="email" id="email" class="ab-input"
                                value="<?php echo $editing ? esc_attr($customer->email) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="phone">Telefon <span class="required">*</span></label>
                            <input type="tel" name="phone" id="phone" class="ab-input"
                                value="<?php echo $editing ? esc_attr($customer->phone) : ''; ?>" required>
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="category">Kategori <span class="required">*</span></label>
                            <select name="category" id="category" class="ab-select" required>
                                <option value="bireysel" <?php echo $editing && $customer->category === 'bireysel' ? 'selected' : ''; ?>>Bireysel</option>
                                <option value="kurumsal" <?php echo $editing && $customer->category === 'kurumsal' ? 'selected' : ''; ?>>Kurumsal</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Kurumsal Müşteri Alanları -->
                    <div id="corporate-fields" class="ab-form-row" style="display: <?php echo $editing && $customer->category === 'kurumsal' ? 'flex' : 'none'; ?>;">
                        <div class="ab-form-group">
                            <label for="company_name">Şirket Adı <span class="required corporate-required">*</span></label>
                            <input type="text" name="company_name" id="company_name" class="ab-input"
                                value="<?php echo $editing && isset($customer->company_name) ? esc_attr($customer->company_name) : ''; ?>">
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="tax_office">Vergi Dairesi <span class="required corporate-required">*</span></label>
                            <input type="text" name="tax_office" id="tax_office" class="ab-input"
                                value="<?php echo $editing && isset($customer->tax_office) ? esc_attr($customer->tax_office) : ''; ?>">
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="tax_number">Vergi Kimlik Numarası <span class="required corporate-required">*</span></label>
                            <input type="text" name="tax_number" id="tax_number" class="ab-input"
                                value="<?php echo $editing && isset($customer->tax_number) ? esc_attr($customer->tax_number) : ''; ?>"
                                pattern="\d{10}" title="Vergi Kimlik Numarası 10 haneli olmalıdır">
                            <div class="ab-form-help">10 haneli Vergi Kimlik Numarasını giriniz.</div>
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="status">Durum <span class="required">*</span></label>
                            <select name="status" id="status" class="ab-select" required>
                                <option value="aktif" <?php echo $editing && $customer->status === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                <option value="pasif" <?php echo $editing && $customer->status === 'pasif' ? 'selected' : ''; ?>>Pasif</option>
                                <option value="belirsiz" <?php echo $editing && $customer->status === 'belirsiz' ? 'selected' : ''; ?>>Belirsiz</option>
                            </select>
                        </div>
                        
                        <?php if (current_user_can('administrator') || current_user_can('insurance_manager')): ?>
                        <div class="ab-form-group">
                            <label for="representative_id">Müşteri Temsilcisi</label>
                            <select name="representative_id" id="representative_id" class="ab-select">
                                <option value="">Temsilci Seçin</option>
                                <?php foreach ($representatives as $rep): ?>
                                    <option value="<?php echo $rep->id; ?>" <?php echo $editing && $customer->representative_id == $rep->id ? 'selected' : ''; ?>>
                                        <?php echo esc_html($rep->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group ab-full-width">
                            <label for="address">Adres</label>
                            <textarea name="address" id="address" class="ab-textarea" rows="3"><?php echo $editing ? esc_textarea($customer->address) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KİŞİSEL BİLGİLER BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-user-circle"></i> Kişisel Bilgiler</h3>
                </div>
                <div class="ab-section-content">
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="birth_date">Doğum Tarihi</label>
                            <input type="date" name="birth_date" id="birth_date" class="ab-input"
                                value="<?php echo $editing && !empty($customer->birth_date) ? esc_attr($customer->birth_date) : ''; ?>">
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="gender">Cinsiyet</label>
                            <select name="gender" id="gender" class="ab-select">
                                <option value="">Seçiniz</option>
                                <option value="male" <?php echo $editing && $customer->gender === 'male' ? 'selected' : ''; ?>>Erkek</option>
                                <option value="female" <?php echo $editing && $customer->gender === 'female' ? 'selected' : ''; ?>>Kadın</option>
                            </select>
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="occupation"><i class="fas fa-briefcase"></i> Meslek</label>
                            <select name="occupation" id="occupation" class="ab-select">
                                <option value="">Seçiniz</option>
                                <?php
                                $occupations = get_occupation_options();
                                foreach ($occupations as $occupation): ?>
                                    <option value="<?php echo esc_attr($occupation); ?>" <?php selected($editing && $customer->occupation === $occupation, true); ?>>
                                        <?php echo esc_html($occupation); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="ab-form-row pregnancy-row" style="display:<?php echo (!$editing || $customer->gender !== 'female') ? 'none' : 'flex'; ?>;">
                        <div class="ab-form-group">
                            <label class="ab-checkbox-label">
                                <input type="checkbox" name="is_pregnant" id="is_pregnant"
                                    <?php echo $editing && !empty($customer->is_pregnant) ? 'checked' : ''; ?>>
                                <span>Gebe</span>
                            </label>
                        </div>
                        
                        <div class="ab-form-group pregnancy-week-container" style="display:<?php echo (!$editing || empty($customer->is_pregnant)) ? 'none' : 'block'; ?>;">
                            <label for="pregnancy_week">Gebelik Haftası</label>
                            <input type="number" name="pregnancy_week" id="pregnancy_week" class="ab-input" min="1" max="42"
                                value="<?php echo $editing && !empty($customer->pregnancy_week) ? esc_attr($customer->pregnancy_week) : ''; ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- AİLE BİLGİLERİ BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-user-friends"></i> Aile Bilgileri</h3>
                </div>
                <div class="ab-section-content">
                    <!-- Eş Bilgileri -->
                    <div class="ab-subsection">
                        <h4><i class="fas fa-user-plus"></i> Eş Bilgileri</h4>
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="spouse_name">Eş Adı</label>
                                <input type="text" name="spouse_name" id="spouse_name" class="ab-input"
                                    value="<?php echo $editing && !empty($customer->spouse_name) ? esc_attr($customer->spouse_name) : ''; ?>">
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="spouse_tc_identity">Eş TC Kimlik No</label>
                                <input type="text" name="spouse_tc_identity" id="spouse_tc_identity" class="ab-input"
                                    value="<?php echo $editing && !empty($customer->spouse_tc_identity) ? esc_attr($customer->spouse_tc_identity) : ''; ?>"
                                    pattern="\d{11}" title="TC Kimlik No 11 haneli olmalıdır">
                                <div class="ab-form-help">11 haneli TC Kimlik Numarasını giriniz.</div>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="spouse_birth_date">Eş Doğum Tarihi</label>
                                <input type="date" name="spouse_birth_date" id="spouse_birth_date" class="ab-input"
                                    value="<?php echo $editing && !empty($customer->spouse_birth_date) ? esc_attr($customer->spouse_birth_date) : ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Çocuk Bilgileri -->
                    <div class="ab-subsection">
                        <h4><i class="fas fa-child"></i> Çocuk Bilgileri</h4>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="children_count">Çocuk Sayısı</label>
                                <div class="ab-input-with-buttons">
                                    <button type="button" class="ab-counter-btn ab-counter-minus">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <input type="number" name="children_count" id="children_count" class="ab-input ab-counter-input" min="0" max="10"
                                    value="<?php echo $editing && isset($customer->children_count) ? esc_attr($customer->children_count) : '0'; ?>">
                                    <button type="button" class="ab-counter-btn ab-counter-plus">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="children-container">
                            <?php if ($editing && !empty($customer->children_names)): ?>
                                <?php 
                                $children_names = explode(',', $customer->children_names);
                                $children_birth_dates = !empty($customer->children_birth_dates) ? explode(',', $customer->children_birth_dates) : [];
                                $children_tc_identities = !empty($customer->children_tc_identities) ? explode(',', $customer->children_tc_identities) : [];
                                
                                for ($i = 0; $i < count($children_names); $i++): 
                                    $child_name = trim($children_names[$i]);
                                    $child_birth_date = isset($children_birth_dates[$i]) ? trim($children_birth_dates[$i]) : '';
                                    $child_tc_identity = isset($children_tc_identities[$i]) ? trim($children_tc_identities[$i]) : '';
                                ?>
                                <div class="ab-child-card">
                                    <div class="ab-child-card-header">
                                        <h5>Çocuk #<?php echo $i+1; ?></h5>
                                    </div>
                                    <div class="ab-child-card-content">
                                        <div class="ab-form-row">
                                            <div class="ab-form-group">
                                                <label><i class="fas fa-child"></i> Çocuk Adı</label>
                                                <input type="text" name="child_name_<?php echo $i+1; ?>" class="ab-input"
                                                    value="<?php echo esc_attr($child_name); ?>">
                                            </div>
                                            
                                            <div class="ab-form-group">
                                                <label><i class="fas fa-id-card"></i> TC Kimlik No</label>
                                                <input type="text" name="child_tc_identity_<?php echo $i+1; ?>" class="ab-input"
                                                    value="<?php echo esc_attr($child_tc_identity); ?>"
                                                    pattern="\d{11}" title="TC Kimlik No 11 haneli olmalıdır">
                                            </div>
                                            
                                            <div class="ab-form-group">
                                                <label><i class="fas fa-calendar-day"></i> Doğum Tarihi</label>
                                                <input type="date" name="child_birth_date_<?php echo $i+1; ?>" class="ab-input"
                                                    value="<?php echo esc_attr($child_birth_date); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- VARLIK BİLGİLERİ BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-home"></i> Varlık Bilgileri</h3>
                </div>
                <div class="ab-section-content">
                    <!-- Ev Bilgileri -->
                    <div class="ab-card-row">
                        <div class="ab-card">
                            <div class="ab-card-header">
                                <h4><i class="fas fa-home"></i> Ev Bilgileri</h4>
                            </div>
                            <div class="ab-card-body">
                                <div class="ab-form-row">
                                    <div class="ab-form-group">
                                        <label class="ab-switch-container">
                                            <span class="ab-switch-label">Ev kendisine ait</span>
                                            <label class="ab-switch">
                                                <input type="checkbox" name="owns_home" id="owns_home"
                                                    <?php echo $editing && !empty($customer->owns_home) ? 'checked' : ''; ?>>
                                                <span class="ab-switch-slider"></span>
                                            </label>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="home-fields" style="display:<?php echo (!$editing || empty($customer->owns_home)) ? 'none' : 'block'; ?>;">
                                    <div class="ab-form-row">
                                        <div class="ab-form-group">
                                            <label class="ab-switch-container">
                                                <span class="ab-switch-label">DASK Poliçesi var</span>
                                                <label class="ab-switch">
                                                    <input type="checkbox" name="has_dask_policy" id="has_dask_policy"
                                                        <?php echo $editing && !empty($customer->has_dask_policy) ? 'checked' : ''; ?>>
                                                    <span class="ab-switch-slider"></span>
                                                </label>
                                            </label>
                                        </div>
                                        
                                        <div class="dask-expiry-container" style="display:<?php echo (!$editing || empty($customer->has_dask_policy)) ? 'none' : 'block'; ?>;">
                                            <div class="ab-form-group">
                                                <label for="dask_policy_expiry"><i class="fas fa-calendar-alt"></i> DASK Poliçe Vadesi</label>
                                                <input type="date" name="dask_policy_expiry" id="dask_policy_expiry" class="ab-input"
                                                    value="<?php echo $editing && !empty($customer->dask_policy_expiry) ? esc_attr($customer->dask_policy_expiry) : ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="ab-form-row">
                                        <div class="ab-form-group">
                                            <label class="ab-switch-container">
                                                <span class="ab-switch-label">Konut Poliçesi var</span>
                                                <label class="ab-switch">
                                                    <input type="checkbox" name="has_home_policy" id="has_home_policy"
                                                        <?php echo $editing && !empty($customer->has_home_policy) ? 'checked' : ''; ?>>
                                                    <span class="ab-switch-slider"></span>
                                                </label>
                                            </label>
                                        </div>
                                        
                                        <div class="home-expiry-container" style="display:<?php echo (!$editing || empty($customer->has_home_policy)) ? 'none' : 'block'; ?>;">
                                            <div class="ab-form-group">
                                                <label for="home_policy_expiry"><i class="fas fa-calendar-alt"></i> Konut Poliçe Vadesi</label>
                                                <input type="date" name="home_policy_expiry" id="home_policy_expiry" class="ab-input"
                                                    value="<?php echo $editing && !empty($customer->home_policy_expiry) ? esc_attr($customer->home_policy_expiry) : ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Araç Bilgileri -->
                        <div class="ab-card">
                            <div class="ab-card-header">
                                <h4><i class="fas fa-car"></i> Araç Bilgileri</h4>
                            </div>
                            <div class="ab-card-body">
                                <div class="ab-form-row">
                                    <div class="ab-form-group">
                                        <label class="ab-switch-container">
                                            <span class="ab-switch-label">Aracı var</span>
                                            <label class="ab-switch">
                                                <input type="checkbox" name="has_vehicle" id="has_vehicle"
                                                    <?php echo $editing && !empty($customer->has_vehicle) ? 'checked' : ''; ?>>
                                                <span class="ab-switch-slider"></span>
                                            </label>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="vehicle-fields" style="display:<?php echo (!$editing || empty($customer->has_vehicle)) ? 'none' : 'block'; ?>;">
                                    <div class="ab-form-row">
                                        <div class="ab-form-group">
                                            <label for="vehicle_plate"><i class="fas fa-car"></i> Araç Plakası</label>
                                            <input type="text" name="vehicle_plate" id="vehicle_plate" class="ab-input"
                                                value="<?php echo $editing && !empty($customer->vehicle_plate) ? esc_attr($customer->vehicle_plate) : ''; ?>"
                                                placeholder="12XX345">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- EVCİL HAYVAN BİLGİLERİ BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-paw"></i> Evcil Hayvan Bilgileri</h3>
                </div>
                <div class="ab-section-content">
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label class="ab-switch-container">
                                <span class="ab-switch-label">Evcil hayvanı var</span>
                                <label class="ab-switch">
                                    <input type="checkbox" name="has_pet" id="has_pet"
                                        <?php echo $editing && !empty($customer->has_pet) ? 'checked' : ''; ?>>
                                    <span class="ab-switch-slider"></span>
                                </label>
                            </label>
                        </div>
                    </div>
                    
                    <div class="pet-fields" style="display:<?php echo (!$editing || empty($customer->has_pet)) ? 'none' : 'block'; ?>;">
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="pet_name"><i class="fas fa-paw"></i> Evcil Hayvan Adı</label>
                                <input type="text" name="pet_name" id="pet_name" class="ab-input"
                                    value="<?php echo $editing && !empty($customer->pet_name) ? esc_attr($customer->pet_name) : ''; ?>">
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="pet_type"><i class="fas fa-paw"></i> Evcil Hayvan Cinsi</label>
                                <select name="pet_type" id="pet_type" class="ab-select">
                                    <option value="">Seçiniz</option>
                                    <option value="Kedi" <?php echo $editing && $customer->pet_type === 'Kedi' ? 'selected' : ''; ?>>Kedi</option>
                                    <option value="Köpek" <?php echo $editing && $customer->pet_type === 'Köpek' ? 'selected' : ''; ?>>Köpek</option>
                                    <option value="Kuş" <?php echo $editing && $customer->pet_type === 'Kuş' ? 'selected' : ''; ?>>Kuş</option>
                                    <option value="Balık" <?php echo $editing && $customer->pet_type === 'Balık' ? 'selected' : ''; ?>>Balık</option>
                                    <option value="Diğer" <?php echo $editing && $customer->pet_type === 'Diğer' ? 'selected' : ''; ?>>Diğer</option>
                                </select>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="pet_age"><i class="fas fa-birthday-cake"></i> Evcil Hayvan Yaşı</label>
                                <input type="text" name="pet_age" id="pet_age" class="ab-input"
                                    value="<?php echo $editing && !empty($customer->pet_age) ? esc_attr($customer->pet_age) : ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- DOSYA ARŞİVİ BÖLÜMÜ -->
            <div class="ab-section-wrapper">
                <div class="ab-section-header">
                    <h3><i class="fas fa-file-archive"></i> Dosya Arşivi</h3>
                </div>
                <div class="ab-section-content">
                    <div class="ab-file-info-alert">
                        <div class="ab-file-info-alert-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="ab-file-info-alert-content">
                            <p>İzin verilen dosya formatları: <strong><?php echo get_allowed_file_types_text(); ?></strong></p>
                            <p>Maksimum dosya boyutu: <strong>5MB</strong></p>
                            <p>Bir seferde en fazla <strong>5 dosya</strong> yükleyebilirsiniz</p>
                        </div>
                    </div>
                    
                    <?php if ($editing && isset($files) && !empty($files)): ?>
                    <div class="ab-existing-files">
                        <h4><i class="fas fa-file-alt"></i> Mevcut Dosyalar</h4>
                        <div class="ab-files-grid">
                            <?php foreach ($files as $file): ?>
                                <div class="ab-file-card">
                                    <div class="ab-file-card-icon">
                                        <i class="fas <?php echo get_file_icon($file->file_type); ?>"></i>
                                    </div>
                                    <div class="ab-file-card-details">
                                        <div class="ab-file-card-name"><?php echo esc_html($file->file_name); ?></div>
                                        <div class="ab-file-card-meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('d.m.Y', strtotime($file->upload_date)); ?></span>
                                            <span><i class="fas fa-weight"></i> <?php echo format_file_size($file->file_size); ?></span>
                                        </div>
                                        <?php if (!empty($file->description)): ?>
                                        <div class="ab-file-card-desc"><?php echo esc_html($file->description); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="ab-file-card-actions">
                                            <?php if ($file->file_type == 'jpg' || $file->file_type == 'jpeg' || $file->file_type == 'png'): ?>
                                                <a href="<?php echo esc_url($file->file_path); ?>" target="_blank" class="ab-btn ab-btn-sm ab-btn-preview" title="Önizleme">
                                                    <i class="fas fa-eye"></i> Görüntüle
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo esc_url($file->file_path); ?>" target="_blank" class="ab-btn ab-btn-sm ab-btn-download" title="İndir">
                                                    <i class="fas fa-download"></i> İndir
                                                </a>
                                            <?php endif; ?>
                                            
                                            <form method="post" action="" class="ab-file-delete-form">
                                                <?php wp_nonce_field('delete_file', 'file_nonce'); ?>
                                                <input type="hidden" name="file_id" value="<?php echo $file->id; ?>">
                                                <button type="submit" name="delete_file" class="ab-btn ab-btn-sm ab-btn-danger" 
                                                        onclick="return confirm('Bu dosyayı silmek istediğinizden emin misiniz?')">
                                                    <i class="fas fa-trash"></i> Sil
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="ab-file-upload-section">
                        <h4><i class="fas fa-cloud-upload-alt"></i> Yeni Dosyalar Ekle</h4>
                        <div class="ab-file-upload-container">
                            <div class="ab-file-upload-area" id="file-upload-area">
                                <input type="file" name="customer_files[]" id="customer_files" class="ab-file-upload" multiple
                                       accept="<?php echo '.'.implode(',.', get_allowed_file_types()); ?>">
                                <div class="ab-file-upload-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <div class="ab-file-upload-text">
                                    <span class="ab-file-upload-title">Dosyaları buraya sürükleyin</span>
                                    <span class="ab-file-upload-subtitle">veya bir dosya seçmek için tıklayın</span>
                                </div>
                            </div>
                            
                            <div class="ab-file-preview-container">
                                <div id="file-count-warning" class="ab-file-warning" style="display:none;">
                                    <i class="fas fa-exclamation-triangle"></i> En fazla 5 dosya yükleyebilirsiniz.
                                </div>
                                <div id="selected-files-container" class="ab-files-grid ab-selected-files"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="ab-form-actions">
            <div class="ab-form-actions-left">
                <a href="?view=customers" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
            </div>
            <div class="ab-form-actions-right">
                <button type="submit" name="save_customer" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Müşteri Bilgilerini Güncelle' : 'Müşteriyi Kaydet'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<style>
/* Form Stilleri - Modern ve Kullanıcı Dostu Tasarım */
.ab-customer-form-container {
    max-width: 100%;
    margin: 20px 0;
    font-family: inherit;
    color: #333;
    font-size: 14px;
}

/* Form Header */
.ab-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}

.ab-header-left {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-form-header h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #333;
}

.ab-breadcrumbs {
    font-size: 13px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ab-breadcrumbs a {
    color: #2271b1;
    text-decoration: none;
}

.ab-breadcrumbs a:hover {
    text-decoration: underline;
}

.ab-breadcrumbs i {
    font-size: 10px;
    color: #999;
}

/* Bildirimler */
.ab-notice {
    padding: 12px 15px;
    margin-bottom: 20px;
    border-left: 4px solid;
    border-radius: 4px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    background-color: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.ab-success {
    background-color: #f0fff4;
    border-left-color: #38a169;
}

.ab-error {
    background-color: #fff5f5;
    border-left-color: #e53e3e;
}

.ab-warning {
    background-color: #fffde7;
    border-left-color: #ffc107;
}

/* Ana İçerik */
.ab-form-content {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

/* Bölüm Kutuları */
.ab-section-wrapper {
    background-color: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e0e0e0;
}

.ab-section-header {
    background-color: #f8f9fa;
    padding: 15px 20px;
    border-bottom: 1px solid #e0e0e0;
}

.ab-section-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-section-header h3 i {
    color: #4caf50;
}

.ab-section-content {
    padding: 20px;
}

/* Alt Bölüm Başlıkları */
.ab-subsection {
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
}

.ab-subsection:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.ab-subsection h4 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 600;
    color: #444;
    display: flex;
    align-items: center;
    gap: 6px;
}

.ab-subsection h4 i {
    color: #666;
    font-size: 14px;
}

/* Kartlar */
.ab-card-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.ab-card {
    background-color: #f9f9f9;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #eee;
}

.ab-card-header {
    background-color: #f3f4f6;
    padding: 12px 15px;
    border-bottom: 1px solid #e0e0e0;
}

.ab-card-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 500;
    color: #444;
    display: flex;
    align-items: center;
    gap: 6px;
}

.ab-card-header h4 i {
    color: #555;
    font-size: 14px;
}

.ab-card-body {
    padding: 15px;
}

/* Çocuk Kartları */
.ab-child-card {
    background-color: #f9f9f9;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #eee;
    margin-bottom: 15px;
}

.ab-child-card:last-child {
    margin-bottom: 0;
}

.ab-child-card-header {
    background-color: #f3f4f6;
    padding: 10px 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ab-child-card-header h5 {
    margin: 0;
    font-size: 15px;
    margin: 0;
    font-size: 15px;
    font-weight: 500;
    color: #444;
}

.ab-child-card-content {
    padding: 15px;
}

/* Form Satırları */
.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 15px;
    gap: 15px;
}

.ab-form-row:last-child {
    margin-bottom: 0;
}

.ab-form-group {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.ab-form-group.ab-full-width {
    flex-basis: 100%;
    width: 100%;
}

/* Form Etiketleri */
.ab-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: #444;
    font-size: 13px;
}

.ab-form-group label i {
    color: #666;
    margin-right: 4px;
}

.required {
    color: #e53935;
    margin-left: 2px;
}

/* Input Stilleri */
.ab-input, .ab-select, .ab-textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    color: #333;
    transition: all 0.2s ease;
    background-color: #fff;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}

.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: #4caf50;
    outline: none;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-input:hover, .ab-select:hover, .ab-textarea:hover {
    border-color: #bbb;
}

.ab-textarea {
    resize: vertical;
    min-height: 80px;
}

.ab-form-help {
    margin-top: 5px;
    font-size: 12px;
    color: #666;
}

/* Sayı Input'u İçin Artırma/Azaltma Butonları */
.ab-input-with-buttons {
    display: flex;
    align-items: stretch;
}

.ab-counter-input {
    text-align: center;
    border-radius: 0;
    border-left: none;
    border-right: none;
    width: 60px;
    padding: 8px 0;
    -moz-appearance: textfield;
}

.ab-counter-input::-webkit-outer-spin-button,
.ab-counter-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.ab-counter-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    border: 1px solid #ddd;
    background-color: #f7f7f7;
    cursor: pointer;
    font-size: 12px;
    color: #555;
    transition: all 0.2s;
    padding: 0;
}

.ab-counter-minus {
    border-radius: 4px 0 0 4px;
}

.ab-counter-plus {
    border-radius: 0 4px 4px 0;
}

.ab-counter-btn:hover {
    background-color: #eaeaea;
}

/* Switch Toggle */
.ab-switch-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
    cursor: pointer;
}

.ab-switch-label {
    font-weight: 500;
    font-size: 14px;
    color: #444;
}

.ab-switch {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 24px;
}

.ab-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.ab-switch-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    border-radius: 24px;
    transition: .3s;
}

.ab-switch-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    border-radius: 50%;
    transition: .3s;
}

input:checked + .ab-switch-slider {
    background-color: #4caf50;
}

input:focus + .ab-switch-slider {
    box-shadow: 0 0 1px #4caf50;
}

input:checked + .ab-switch-slider:before {
    transform: translateX(22px);
}

/* Checkbox ve Radio */
.ab-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 8px 0;
}

.ab-checkbox-label input[type="checkbox"] {
    margin: 0;
    width: 16px;
    height: 16px;
}

.ab-checkbox-text {
    font-size: 14px;
    user-select: none;
}

/* Dosya Arşivi Bölümü */
.ab-file-upload-section {
    margin-top: 15px;
}

.ab-file-info-alert {
    display: flex;
    padding: 12px 15px;
    background-color: #f0f4f8;
    border-left: 4px solid #3498db;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-file-info-alert-icon {
    font-size: 24px;
    color: #3498db;
    margin-right: 15px;
}

.ab-file-info-alert-content {
    flex: 1;
}

.ab-file-info-alert-content p {
    margin: 5px 0;
    font-size: 13px;
    color: #444;
}

.ab-existing-files {
    margin-bottom: 30px;
}

.ab-existing-files h4 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    font-weight: 500;
    color: #444;
    display: flex;
    align-items: center;
    gap: 6px;
}

.ab-file-upload-area {
    border: 2px dashed #ddd;
    padding: 30px 20px;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background-color: #fafafa;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 150px;
}

.ab-file-upload-area:hover {
    border-color: #4caf50;
    background-color: #f0f8f1;
}

.ab-file-upload-icon {
    font-size: 40px;
    color: #999;
    margin-bottom: 15px;
}

.ab-file-upload-area:hover .ab-file-upload-icon {
    color: #4caf50;
}

.ab-file-upload-text {
    display: flex;
    flex-direction: column;
    color: #555;
}

.ab-file-upload-title {
    font-size: 16px;
    font-weight: 500;
    margin-bottom: 5px;
}

.ab-file-upload-subtitle {
    font-size: 13px;
    color: #888;
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
    margin-top: 20px;
}

.ab-file-warning {
    margin-bottom: 15px;
    padding: 10px 15px;
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

/* Dosya Kartları */
.ab-files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
}

.ab-file-card {
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 15px;
    background-color: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    gap: 15px;
    transition: all 0.2s ease;
}

.ab-file-card:hover {
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
    border-color: #ddd;
}

.ab-file-card-icon {
    font-size: 28px;
    color: #666;
    padding-top: 5px;
}

.ab-file-card-icon i.fa-file-pdf { color: #f44336; }
.ab-file-card-icon i.fa-file-word { color: #2196f3; }
.ab-file-card-icon i.fa-file-image { color: #4caf50; }
.ab-file-card-icon i.fa-file-excel { color: #4caf50; }
.ab-file-card-icon i.fa-file-archive { color: #ff9800; }
.ab-file-card-icon i.fa-file-alt { color: #607d8b; }

.ab-file-card-details {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.ab-file-card-name {
    font-weight: 500;
    margin-bottom: 5px;
    color: #333;
    word-break: break-all;
    font-size: 14px;
}

.ab-file-card-meta {
    font-size: 12px;
    color: #777;
    display: flex;
    gap: 10px;
    margin-bottom: 8px;
}

.ab-file-card-meta i {
    color: #999;
    margin-right: 2px;
}

.ab-file-card-desc {
    font-size: 13px;
    color: #666;
    margin-top: 5px;
    font-style: italic;
    margin-bottom: 8px;
}

.ab-file-card-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
}

.ab-file-delete-form {
    margin: 0;
    display: inline-block;
}

/* Yeni Seçilen Dosya Kartları */
.ab-file-item-preview {
    position: relative;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px;
    background-color: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ab-file-name-preview {
    font-weight: 500;
    margin-bottom: 5px;
    word-break: break-all;
    color: #333;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-file-size-preview {
    font-size: 12px;
    color: #777;
    margin-bottom: 8px;
}

.ab-file-desc-input {
    margin-top: 5px;
}

.ab-file-error {
    color: #e53935;
    font-size: 12px;
    margin-top: 5px;
    display: flex;
    align-items: flex-start;
    gap: 4px;
}

.ab-file-error i {
    color: #e53935;
    margin-top: 2px;
}

.ab-file-remove {
    position: absolute;
    top: 10px;
    right: 10px;
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
    transform: scale(1.1);
}

.ab-file-icon-pdf { color: #f44336; }
.ab-file-icon-word { color: #2196f3; }
.ab-file-icon-image { color: #4caf50; }
.ab-file-icon-excel { color: #4caf50; }
.ab-file-icon-archive { color: #ff9800; }

/* Form Actions */
.ab-form-actions {
    display: flex;
    justify-content: space-between;
    padding-top: 20px;
    margin-top: 30px;
    border-top: 1px solid #e0e0e0;
}

.ab-form-actions-left,
.ab-form-actions-right {
    display: flex;
    gap: 10px;
}

/* Butonlar */
.ab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 18px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    line-height: 1.4;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
    border-color: #ccc;
}

.ab-btn-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #3d9140;
    color: white;
    border-color: #357a38;
}

.ab-btn-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
}

.ab-btn-secondary:hover {
    background-color: #e9ecef;
    border-color: #ccc;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-btn-preview {
    background-color: #2196f3;
    border-color: #1976d2;
    color: white;
}

.ab-btn-preview:hover {
    background-color: #1976d2;
    color: white;
}

.ab-btn-download {
    background-color: #795548;
    border-color: #6d4c41;
    color: white;
}

.ab-btn-download:hover {
    background-color: #6d4c41;
    color: white;
}

.ab-btn-danger {
    background-color: #f44336;
    border-color: #e53935;
    color: white;
}

.ab-btn-danger:hover {
    background-color: #e53935;
    color: white;
}

/* Mobil Uyumluluk */
@media (max-width: 992px) {
    .ab-files-grid, .ab-selected-files {
        grid-template-columns: repeat(auto-fill, minmax(100%, 1fr));
    }
    
    .ab-card-row {
        grid-template-columns: 1fr;
    }
    
    .ab-file-card {
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-file-card-icon {
        text-align: center;
    }
    
    .ab-file-card-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .ab-file-card-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .ab-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .ab-form-header .ab-btn {
        align-self: flex-start;
    }
    
    .ab-form-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-form-group {
        width: 100%;
    }
    
    .ab-form-actions {
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-form-actions-left, .ab-form-actions-right {
        width: 100%;
    }
    
    .ab-form-actions-left .ab-btn, .ab-form-actions-right .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-file-info-alert {
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-file-info-alert-icon {
        margin-right: 0;
        text-align: center;
    }
}

@media (max-width: 576px) {
    .ab-form-header h2 {
        font-size: 20px;
    }
    
    .ab-section-header {
        padding: 12px 15px;
    }
    
    .ab-section-content {
        padding: 15px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Kategori değiştiğinde kurumsal alanları göster/gizle
    $('#category').change(function() {
        if ($(this).val() === 'kurumsal') {
            $('#corporate-fields').slideDown();
            $('#company_name, #tax_office, #tax_number').prop('required', true);
        } else {
            $('#corporate-fields').slideUp();
            $('#company_name, #tax_office, #tax_number').prop('required', false);
        }
    });
    
    // Cinsiyet değiştiğinde gebelik alanını göster/gizle
    $('#gender').change(function() {
        if ($(this).val() === 'female') {
            $('.pregnancy-row').slideDown();
        } else {
            $('.pregnancy-row').slideUp();
            $('#is_pregnant').prop('checked', false);
            $('#pregnancy_week').val('');
            $('.pregnancy-week-container').hide();
        }
    });
    
    // Gebelik seçildiğinde hafta alanını göster/gizle
    $('#is_pregnant').change(function() {
        if ($(this).is(':checked')) {
            $('.pregnancy-week-container').slideDown();
        } else {
            $('.pregnancy-week-container').slideUp();
            $('#pregnancy_week').val('');
        }
    });
    
    // Ev sahibi değiştiğinde poliçe alanlarını göster/gizle
    $('#owns_home').change(function() {
        if ($(this).is(':checked')) {
            $('.home-fields').slideDown();
        } else {
            $('.home-fields').slideUp();
            $('#has_dask_policy, #has_home_policy').prop('checked', false);
            $('#dask_policy_expiry, #home_policy_expiry').val('');
            $('.dask-expiry-container, .home-expiry-container').hide();
        }
    });
    
    // DASK poliçesi var/yok değiştiğinde vade alanını göster/gizle
    $('#has_dask_policy').change(function() {
        if ($(this).is(':checked')) {
            $('.dask-expiry-container').slideDown();
        } else {
            $('.dask-expiry-container').slideUp();
            $('#dask_policy_expiry').val('');
        }
    });
    
    // Konut poliçesi var/yok değiştiğinde vade alanını göster/gizle
    $('#has_home_policy').change(function() {
        if ($(this).is(':checked')) {
            $('.home-expiry-container').slideDown();
        } else {
            $('.home-expiry-container').slideUp();
            $('#home_policy_expiry').val('');
        }
    });
    
    // Araç var/yok değiştiğinde plaka alanını göster/gizle
    $('#has_vehicle').change(function() {
        if ($(this).is(':checked')) {
            $('.vehicle-fields').slideDown();
        } else {
            $('.vehicle-fields').slideUp();
            $('#vehicle_plate').val('');
        }
    });
    
    // Evcil hayvan var/yok değiştiğinde ilgili alanları göster/gizle
    $('#has_pet').change(function() {
        if ($(this).is(':checked')) {
            $('.pet-fields').slideDown();
        } else {
            $('.pet-fields').slideUp();
            $('#pet_name, #pet_age').val('');
            $('#pet_type').val('');
        }
    });
    
    // Çocuk sayısı değiştiğinde çocuk alanlarını güncelle
    // Artırma/azaltma butonları
    $('.ab-counter-minus').click(function() {
        var input = $(this).siblings('.ab-counter-input');
        var currentVal = parseInt(input.val()) || 0;
        if (currentVal > 0) {
            input.val(currentVal - 1).trigger('change');
        }
    });
    
    $('.ab-counter-plus').click(function() {
        var input = $(this).siblings('.ab-counter-input');
        var currentVal = parseInt(input.val()) || 0;
        if (currentVal < 10) {
            input.val(currentVal + 1).trigger('change');
        }
    });
    
    $('#children_count').change(function() {
        updateChildrenFields();
    });
    
    function updateChildrenFields() {
        var count = parseInt($('#children_count').val()) || 0;
        var container = $('#children-container');
        
        // Mevcut alanları temizle
        container.empty();
        
        // Seçilen sayıda çocuk alanı ekle
        for (var i = 1; i <= count; i++) {
            var card = $('<div class="ab-child-card"></div>');
            var header = $('<div class="ab-child-card-header"><h5>Çocuk #' + i + '</h5></div>');
            var content = $('<div class="ab-child-card-content"></div>');
            
            var row = $('<div class="ab-form-row"></div>');
            
            var nameGroup = $('<div class="ab-form-group"></div>');
            nameGroup.append('<label><i class="fas fa-child"></i> Çocuk Adı</label>');
            nameGroup.append('<input type="text" name="child_name_' + i + '" class="ab-input">');
            
            var tcGroup = $('<div class="ab-form-group"></div>');
            tcGroup.append('<label><i class="fas fa-id-card"></i> TC Kimlik No</label>');
            tcGroup.append('<input type="text" name="child_tc_identity_' + i + '" class="ab-input" pattern="\\d{11}" title="TC Kimlik No 11 haneli olmalıdır">');
            
            var birthGroup = $('<div class="ab-form-group"></div>');
            birthGroup.append('<label><i class="fas fa-calendar-day"></i> Doğum Tarihi</label>');
            birthGroup.append('<input type="date" name="child_birth_date_' + i + '" class="ab-input">');
            
            row.append(nameGroup).append(tcGroup).append(birthGroup);
            content.append(row);
            
            card.append(header).append(content);
            container.append(card);
        }
    }
    
    // TC Kimlik No doğrulama fonksiyonu
    function validateTcIdentity(input) {
        var value = input.value;
        if(value && value.length !== 11) {
            input.setCustomValidity('TC Kimlik No 11 haneli olmalıdır');
        } else {
            input.setCustomValidity('');
        }
    }
    
    // TC Kimlik No alanlarına doğrulama ekle
    $('#tc_identity, #spouse_tc_identity').on('input', function() {
        validateTcIdentity(this);
    });
    
    // Form gönderilirken çocuk TC Kimlik No alanlarını doğrula
    $('form.ab-customer-form').submit(function() {
        $('input[name^="child_tc_identity_"]').each(function() {
            validateTcIdentity(this);
        });
    });
    
    // Dosya yükleme alanı sürükle bırak
    var fileUploadArea = document.getElementById('file-upload-area');
    var fileInput = document.getElementById('customer_files');
    
    // Sürükle bırak olaylarını izle
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        fileUploadArea.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
        }, false);
    });
    
    ['dragenter', 'dragover'].forEach(eventName => {
        fileUploadArea.addEventListener(eventName, function() {
            fileUploadArea.classList.add('ab-drag-active');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        fileUploadArea.addEventListener(eventName, function() {
            fileUploadArea.classList.remove('ab-drag-active');
        }, false);
    });
    
    // Dosya bırakıldığında
    fileUploadArea.addEventListener('drop', function(e) {
        var files = e.dataTransfer.files;
        
        // Dosya sayısı kontrolü
        if (files.length > 5) {
            showFileCountWarning();
            // Sadece ilk 5 dosyayı al
            var maxFiles = [];
            for (var i = 0; i < 5; i++) {
                maxFiles.push(files[i]);
            }
            
            // FileList kopyalanamaz, o yüzden Data Transfer kullanarak yeni bir dosya listesi oluştur
            const dataTransfer = new DataTransfer();
            maxFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        } else {
            fileInput.files = files;
        }
        
        updateFilePreview();
    });
    
    // Normal dosya seçimi
    fileUploadArea.addEventListener('click', function() {
        fileInput.click();
    });
    
    // Dosya seçildiğinde önizleme göster
    fileInput.addEventListener('change', function() {
        // Dosya sayısı kontrolü
        if (this.files.length > 5) {
            showFileCountWarning();
            
            // Sadece ilk 5 dosyayı al
            const dataTransfer = new DataTransfer();
            for (var i = 0; i < 5; i++) {
                dataTransfer.items.add(this.files[i]);
            }
            this.files = dataTransfer.files;
        } else {
            hideFileCountWarning();
        }
        
        updateFilePreview();
    });
    
    function showFileCountWarning() {
        $('#file-count-warning').slideDown();
    }
    
    function hideFileCountWarning() {
        $('#file-count-warning').slideUp();
    }
    
    // Dosya önizlemeleri güncelleme
    function updateFilePreview() {
        var filesContainer = document.getElementById('selected-files-container');
        filesContainer.innerHTML = '';
        
        var files = fileInput.files;
        // PHP'den alınan izin verilen dosya tiplerini parse et
        var allowedTypesString = fileInput.getAttribute('accept'); // .jpg,.jpeg,.pdf,...
        var allowedTypes = allowedTypesString.split(',').map(function(item) {
            return item.trim().toLowerCase().substring(1); // "jpg", "jpeg", "pdf", ...
        });
        var maxSize = 5 * 1024 * 1024; // 5MB
        
        if (files.length === 0) {
            return;
        }
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var fileSize = formatFileSize(file.size);
            var fileExt = getFileExt(file.name);
            var isValidType = allowedTypes.includes(fileExt);
            var isValidSize = file.size <= maxSize;
            
            var itemDiv = document.createElement('div');
            itemDiv.className = 'ab-file-item-preview' + (!isValidType || !isValidSize ? ' ab-file-invalid' : '');
            
            var iconClass = 'fa-file';
            if (fileExt === 'pdf') iconClass = 'fa-file-pdf ab-file-icon-pdf';
            else if (fileExt === 'doc' || fileExt === 'docx') iconClass = 'fa-file-word ab-file-icon-word';
            else if (fileExt === 'jpg' || fileExt === 'jpeg' || fileExt === 'png') iconClass = 'fa-file-image ab-file-icon-image';
            else if (fileExt === 'xls' || fileExt === 'xlsx') iconClass = 'fa-file-excel ab-file-icon-excel';
            else if (fileExt === 'zip') iconClass = 'fa-file-archive ab-file-icon-archive';
            
            var nameDiv = document.createElement('div');
            nameDiv.className = 'ab-file-name-preview';
            nameDiv.innerHTML = '<i class="fas ' + iconClass + '"></i> ' + file.name;
            
            var sizeDiv = document.createElement('div');
            sizeDiv.className = 'ab-file-size-preview';
            sizeDiv.textContent = fileSize;
            
            itemDiv.appendChild(nameDiv);
            itemDiv.appendChild(sizeDiv);
            
            // Hata ve açıklama alanları
            if (!isValidType) {
                var errorDiv = document.createElement('div');
                errorDiv.className = 'ab-file-error';
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Geçersiz dosya formatı.';
                itemDiv.appendChild(errorDiv);
            } else if (!isValidSize) {
                var errorDiv = document.createElement('div');
                errorDiv.className = 'ab-file-error';
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Dosya boyutu çok büyük (max 5MB).';
                itemDiv.appendChild(errorDiv);
            } else {
                var descDiv = document.createElement('div');
                descDiv.className = 'ab-file-desc-input';
                descDiv.innerHTML = '<input type="text" name="file_descriptions[]" placeholder="Dosya açıklaması (isteğe bağlı)" class="ab-input">';
                itemDiv.appendChild(descDiv);
            }
            
            // Dosyayı kaldırma butonu
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'ab-file-remove';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.dataset.index = i;
            removeBtn.addEventListener('click', function() {
                removeSelectedFile(parseInt(this.dataset.index));
            });
            
            itemDiv.appendChild(removeBtn);
            filesContainer.appendChild(itemDiv);
        }
    }
    
    function removeSelectedFile(index) {
        const dt = new DataTransfer();
        const files = fileInput.files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== index) dt.items.add(files[i]);
        }
        
        fileInput.files = dt.files;
        hideFileCountWarning();
        updateFilePreview();
    }
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        else if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
        else return (bytes / 1048576).toFixed(2) + ' MB';
    }
    
    function getFileExt(filename) {
        return filename.split('.').pop().toLowerCase();
    }
    
    // Sayfa yüklendiğinde başlangıç ayarlarını yap
    function initializeForm() {
        // Çocuk alanlarını güncelle (düzenleme sayfasında, fakat çocuk kutucukları henüz oluşturulmadıysa)
        if ($('#children_count').val() > 0 && $('#children-container').children().length === 0) {
            updateChildrenFields();
        }
    }
    
    // Sayfa yüklendiğinde başlangıç ayarlarını çağır
    initializeForm();
});
</script>
