<?php
if (!defined('ABSPATH')) {
    exit;
}

// Mevcut kullanıcıyı al
$current_user = wp_get_current_user();

// Temsilci bilgilerini al
global $wpdb;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

$success_message = '';
$errors = array();

// Form gönderildi mi kontrol et
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['settings_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['settings_nonce']) || !wp_verify_nonce($_POST['settings_nonce'], 'settings_form_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // Profil verilerini al
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
    
    // Form doğrulaması
    if (empty($first_name)) {
        $errors[] = 'Ad alanı zorunludur.';
    }
    if (empty($last_name)) {
        $errors[] = 'Soyad alanı zorunludur.';
    }
    if (empty($email)) {
        $errors[] = 'E-posta alanı zorunludur.';
    } elseif (!is_email($email)) {
        $errors[] = 'Geçerli bir e-posta adresi girin.';
    }
    
    // Şifre kontrolü (doldurulduysa)
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors[] = 'Şifre en az 8 karakter olmalıdır.';
        }
        if ($password !== $password_confirm) {
            $errors[] = 'Şifreler eşleşmiyor.';
        }
    }
    
    // Hata yoksa işlemi gerçekleştir
    if (empty($errors)) {
        // WordPress kullanıcı bilgilerini güncelle
        $user_data = array(
            'ID' => $current_user->ID,
            'first_name' => $first_name,
            'last_name' => $last_name
        );
        
        // E-posta güncellemesi
        if ($email !== $current_user->user_email) {
            $user_data['user_email'] = $email;
        }
        
        // Şifre güncellemesi
        if (!empty($password)) {
            $user_data['user_pass'] = $password;
        }
        
        wp_update_user($user_data);
        
        // Temsilci tablosundaki telefon bilgisini güncelle
        $wpdb->update(
            $wpdb->prefix . 'insurance_crm_representatives',
            array('phone' => $phone),
            array('id' => $representative->id)
        );
        
        $success_message = 'Profil bilgileriniz başarıyla güncellendi.';
    }
}

// Görünüm ayarları için form işleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['appearance_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['appearance_nonce']) || !wp_verify_nonce($_POST['appearance_nonce'], 'appearance_form_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // Renk ayarlarını al
    $personal_color = isset($_POST['personal_color']) ? sanitize_hex_color($_POST['personal_color']) : '#3498db';
    $corporate_color = isset($_POST['corporate_color']) ? sanitize_hex_color($_POST['corporate_color']) : '#4caf50';
    $family_color = isset($_POST['family_color']) ? sanitize_hex_color($_POST['family_color']) : '#ff9800';
    $vehicle_color = isset($_POST['vehicle_color']) ? sanitize_hex_color($_POST['vehicle_color']) : '#e74c3c';
    $home_color = isset($_POST['home_color']) ? sanitize_hex_color($_POST['home_color']) : '#9c27b0';
    
    // Kullanıcının meta bilgilerine renk tercihlerini kaydet
    update_user_meta($current_user->ID, 'crm_personal_color', $personal_color);
    update_user_meta($current_user->ID, 'crm_corporate_color', $corporate_color);
    update_user_meta($current_user->ID, 'crm_family_color', $family_color);
    update_user_meta($current_user->ID, 'crm_vehicle_color', $vehicle_color);
    update_user_meta($current_user->ID, 'crm_home_color', $home_color);
    
    $success_message = 'Görünüm ayarlarınız başarıyla güncellendi.';
}

// Dosya formatları ayarları için form işleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['file_types_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['file_types_nonce']) || !wp_verify_nonce($_POST['file_types_nonce'], 'file_types_form_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // İzin verilen dosya formatlarını al
    $allowed_file_types = isset($_POST['allowed_file_types']) ? $_POST['allowed_file_types'] : array('jpg', 'jpeg', 'pdf', 'docx');
    
    // En az bir format seçilmiş olmalı
    if (empty($allowed_file_types)) {
        $allowed_file_types = array('jpg', 'jpeg', 'pdf', 'docx');
    }
    
    // Güvenlik kontrolü - sadece izin verilen formatları kabul et
    $valid_types = array('jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip');
    $allowed_file_types = array_intersect($allowed_file_types, $valid_types);
    
    // Ayarları kaydet
    update_option('crm_allowed_file_types', $allowed_file_types);
    
    $success_message = 'Dosya format ayarları başarıyla güncellendi.';
}

// Kullanıcının kayıtlı renk tercihlerini al
$personal_color = get_user_meta($current_user->ID, 'crm_personal_color', true) ?: '#3498db';
$corporate_color = get_user_meta($current_user->ID, 'crm_corporate_color', true) ?: '#4caf50';
$family_color = get_user_meta($current_user->ID, 'crm_family_color', true) ?: '#ff9800';
$vehicle_color = get_user_meta($current_user->ID, 'crm_vehicle_color', true) ?: '#e74c3c';
$home_color = get_user_meta($current_user->ID, 'crm_home_color', true) ?: '#9c27b0';
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="main-content settings-page">
    <div class="content-header">
        <h1 class="content-title"><i class="fas fa-cog"></i> Ayarlar</h1>
    </div>
    
    <?php if (!empty($success_message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($success_message); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach($errors as $error): ?>
                <p><?php echo esc_html($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div class="settings-container">
        <div class="settings-tabs">
            <a href="#profile" class="tab-link active">
                <i class="fas fa-user"></i> Profil Bilgileri
            </a>
            <a href="#appearance" class="tab-link">
                <i class="fas fa-palette"></i> Görünüm Ayarları
            </a>
            <a href="#file-types" class="tab-link">
                <i class="fas fa-file"></i> Dosya Formatları
            </a>
            <a href="#notifications" class="tab-link">
                <i class="fas fa-bell"></i> Bildirim Ayarları
            </a>
        </div>
        
        <div class="settings-content">
            <div id="profile" class="tab-content active">
                <form method="post" id="profile-form">
                    <?php wp_nonce_field('settings_form_nonce', 'settings_nonce'); ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr($current_user->first_name); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr($current_user->last_name); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-posta <span class="required">*</span></label>
                            <input type="email" name="email" id="email" value="<?php echo esc_attr($current_user->user_email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="text" name="phone" id="phone" value="<?php echo esc_attr($representative->phone); ?>">
                        </div>
                        
                        <div class="form-group col-span-2">
                            <h3><i class="fas fa-key"></i> Şifre Değiştir</h3>
                            <p class="form-tip">Şifrenizi değiştirmek istemiyorsanız bu alanları boş bırakın.</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Yeni Şifre</label>
                            <input type="password" name="password" id="password" autocomplete="new-password">
                        </div>
                        
                        <div class="form-group">
                            <label for="password_confirm">Şifreyi Tekrar Girin</label>
                            <input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="settings_submit" class="button button-primary">
                            <i class="fas fa-save"></i> Değişiklikleri Kaydet
                        </button>
                    </div>
                </form>
            </div>
            
            <div id="appearance" class="tab-content">
                <div class="appearance-container">
                    <h3><i class="fas fa-paint-brush"></i> Panel Renkleri</h3>
                    <p class="form-tip">Müşteri detay sayfasındaki panellerin renk ayarlarını özelleştirebilirsiniz.</p>
                    
                    <form method="post" id="appearance-form">
                        <?php wp_nonce_field('appearance_form_nonce', 'appearance_nonce'); ?>
                        
                        <div class="panel-color-grid">
                            <div class="color-preview-card">
                                <div class="color-preview" style="background-color: <?php echo esc_attr($personal_color); ?>"></div>
                                <div class="color-info">
                                    <label for="personal_color">Kişisel Bilgiler</label>
                                    <input type="color" name="personal_color" id="personal_color" value="<?php echo esc_attr($personal_color); ?>">
                                </div>
                            </div>
                            
                            <div class="color-preview-card">
                                <div class="color-preview" style="background-color: <?php echo esc_attr($corporate_color); ?>"></div>
                                <div class="color-info">
                                    <label for="corporate_color">Firma Bilgileri</label>
                                    <input type="color" name="corporate_color" id="corporate_color" value="<?php echo esc_attr($corporate_color); ?>">
                                </div>
                            </div>
                            
                            <div class="color-preview-card">
                                <div class="color-preview" style="background-color: <?php echo esc_attr($family_color); ?>"></div>
                                <div class="color-info">
                                    <label for="family_color">Aile Bilgileri</label>
                                    <input type="color" name="family_color" id="family_color" value="<?php echo esc_attr($family_color); ?>">
                                </div>
                            </div>
                            
                            <div class="color-preview-card">
                                <div class="color-preview" style="background-color: <?php echo esc_attr($vehicle_color); ?>"></div>
                                <div class="color-info">
                                    <label for="vehicle_color">Araç Bilgileri</label>
                                    <input type="color" name="vehicle_color" id="vehicle_color" value="<?php echo esc_attr($vehicle_color); ?>">
                                </div>
                            </div>
                            
                            <div class="color-preview-card">
                                <div class="color-preview" style="background-color: <?php echo esc_attr($home_color); ?>"></div>
                                <div class="color-info">
                                    <label for="home_color">Ev Bilgileri</label>
                                    <input type="color" name="home_color" id="home_color" value="<?php echo esc_attr($home_color); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="panel-preview-section">
                            <h4>Önizleme</h4>
                            <div class="panel-preview-grid">
                                <div class="panel-preview personal-panel">
                                    <div class="panel-preview-header">Kişisel Bilgiler</div>
                                    <div class="panel-preview-body">
                                        Ad Soyad, Telefon, E-posta...
                                    </div>
                                </div>
                                
                                <div class="panel-preview corporate-panel">
                                    <div class="panel-preview-header">Firma Bilgileri</div>
                                    <div class="panel-preview-body">
                                        Firma Adı, Vergi No, Vergi Dairesi...
                                    </div>
                                </div>
                                
                                <div class="panel-preview family-panel">
                                    <div class="panel-preview-header">Aile Bilgileri</div>
                                    <div class="panel-preview-body">
                                        Eş, Çocuklar...
                                    </div>
                                </div>
                                
                                <div class="panel-preview vehicle-panel">
                                    <div class="panel-preview-header">Araç Bilgileri</div>
                                    <div class="panel-preview-body">
                                        Plaka, Model...
                                    </div>
                                </div>
                                
                                <div class="panel-preview home-panel">
                                    <div class="panel-preview-header">Ev Bilgileri</div>
                                    <div class="panel-preview-body">
                                        Adres, DASK...
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" id="reset-colors" class="button button-secondary">
                                <i class="fas fa-undo"></i> Varsayılan Renklere Dön
                            </button>
                            <button type="submit" name="appearance_submit" class="button button-primary">
                                <i class="fas fa-save"></i> Renkleri Kaydet
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Dosya Formatları Ayarları Bölümü -->
            <div id="file-types" class="tab-content">
                <div class="form-section">
                    <h3><i class="fas fa-file"></i> Dosya Format Ayarları</h3>
                    <p class="form-tip">Müşteri evrak arşivine yüklenebilecek dosya formatlarını belirleyin.</p>
                    
                    <form method="post" id="file-types-form">
                        <?php wp_nonce_field('file_types_form_nonce', 'file_types_nonce'); ?>
                        
                        <div class="form-group">
                            <label>İzin Verilen Dosya Formatları</label>
                            <div class="file-types-container">
                                <?php
                                $allowed_file_types = get_option('crm_allowed_file_types', array('jpg', 'jpeg', 'pdf', 'docx'));
                                $all_file_types = array(
                                    'jpg' => 'JPEG Resim Dosyaları (.jpg)',
                                    'jpeg' => 'JPEG Resim Dosyaları (.jpeg)',
                                    'png' => 'PNG Resim Dosyaları (.png)',
                                    'pdf' => 'PDF Dökümanları (.pdf)',
                                    'doc' => 'Word Dökümanları (.doc)',
                                    'docx' => 'Word Dökümanları (.docx)',
                                    'xls' => 'Excel Tabloları (.xls)',
                                    'xlsx' => 'Excel Tabloları (.xlsx)',
                                    'txt' => 'Metin Dosyaları (.txt)',
                                    'zip' => 'ZIP Arşiv Dosyaları (.zip)'
                                );
                                
                                foreach ($all_file_types as $type => $label):
                                ?>
                                <div class="file-type-checkbox">
                                    <label>
                                        <input type="checkbox" name="allowed_file_types[]" value="<?php echo $type; ?>" 
                                            <?php echo in_array($type, $allowed_file_types) ? 'checked' : ''; ?>>
                                        <?php echo $label; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-tip">Seçili formatlar dışındaki dosyalar sistem tarafından reddedilecektir.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="file_types_submit" class="button button-primary">
                                <i class="fas fa-save"></i> Formatları Kaydet
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="notifications" class="tab-content">
                <div class="coming-soon">
                    <div class="coming-soon-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3>Çok Yakında</h3>
                    <p>Bildirim ayarları yakında kullanıma açılacaktır.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Genel stiller */
.settings-page {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.content-header {
    display: flex;
    align-items: center;
    margin-bottom: 25px;
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
}

.content-title {
    font-size: 24px;
    font-weight: 600;
    color: #333;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.content-title i {
    color: #4caf50;
}

.settings-container {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-top: 20px;
}

/* Sekmeler */
.settings-tabs {
    display: flex;
    border-bottom: 1px solid #dee2e6;
    background-color: #f8f9fa;
    overflow-x: auto;
    scrollbar-width: thin;
}

.tab-link {
    padding: 15px 20px;
    border-bottom: 2px solid transparent;
    color: #6c757d;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-link i {
    font-size: 16px;
}

.tab-link:hover {
    color: #333;
    background-color: #f1f1f1;
}

.tab-link.active {
    color: #4caf50;
    border-bottom-color: #4caf50;
    background-color: #f0f8f1;
}

.tab-content {
    display: none;
    padding: 25px;
}

.tab-content.active {
    display: block;
}

/* Form stilleri */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.form-group {
    position: relative;
}

.form-group.col-span-2 {
    grid-column: span 2;
}

.form-group h3 {
    margin: 0 0 15px;
    font-size: 18px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-group h3 i {
    color: #4caf50;
}

.form-tip {
    color: #6c757d;
    font-size: 14px;
    margin-bottom: 15px;
    margin-top: -5px;
}

.required {
    color: #e53935;
}

label {
    display: block;
    font-weight: 500;
    margin-bottom: 8px;
    color: #333;
}

input[type="text"],
input[type="email"],
input[type="password"],
select,
textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    line-height: 1.5;
    color: #333;
    transition: border-color 0.15s ease-in-out;
    background-color: #fff;
}

input[type="text"]:focus,
input[type="email"]:focus,
input[type="password"]:focus,
select:focus,
textarea:focus {
    border-color: #4caf50;
    outline: 0;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    background-color: #f5f5f5;
    color: #333;
    text-decoration: none;
    transition: all 0.2s;
}

.button:hover {
    background-color: #e9e9e9;
}

.button-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.button-primary:hover {
    background-color: #388e3c;
}

.button-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
    color: #333;
}

.button-secondary:hover {
    background-color: #e9ecef;
}

/* Renk ayarları */
.appearance-container {
    max-width: 100%;
}

.appearance-container h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 18px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.appearance-container h3 i {
    color: #4caf50;
}

.panel-color-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.color-preview-card {
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    transition: transform 0.2s;
}

.color-preview-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.color-preview {
    height: 80px;
    width: 100%;
}

.color-info {
    padding: 15px;
    background-color: #fff;
}

.color-info label {
    margin-bottom: 10px;
    display: block;
    font-weight: 500;
}

.color-info input[type="color"] {
    width: 100%;
    height: 35px;
    padding: 0;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
}

/* Panel önizleme */
.panel-preview-section {
    background-color: #f8f9fa;
    border: 1px solid #eee;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 25px;
}

.panel-preview-section h4 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    color: #555;
}

.panel-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
}

.panel-preview {
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.panel-preview-header {
    padding: 10px 12px;
    background-color: #f8f9fa;
    font-weight: 500;
    border-bottom: 1px solid #eee;
}

.panel-preview-body {
    padding: 12px;
    font-size: 13px;
    color: #666;
}

/* Coming Soon */
.coming-soon {
    text-align: center;
    padding: 40px 20px;
}

.coming-soon-icon {
    width: 60px;
    height: 60px;
    background: #f0f7ff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.coming-soon-icon i {
    font-size: 28px;
    color: #4caf50;
}

.coming-soon h3 {
    margin: 0 0 10px;
    font-size: 18px;
    color: #333;
}

.coming-soon p {
    color: #6c757d;
    margin: 0;
}

/* Notice messages */
.notice {
    padding: 12px 15px;
    margin: 15px 0;
    border-left: 4px solid;
    border-radius: 3px;
    position: relative;
}

.notice-success {
    background-color: #f0fff4;
    border-color: #4caf50;
}

.notice-error {
    background-color: #fff5f5;
    border-color: #e53935;
}

.notice p {
    margin: 5px 0;
}

/* Dosya formatları için stil */
.file-types-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-bottom: 15px;
    padding: 15px;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    border-radius: 4px;
}

.file-type-checkbox {
    display: flex;
    align-items: center;
}

.file-type-checkbox label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: normal;
}

.file-type-checkbox input[type="checkbox"] {
    margin: 0;
}

/* Responsive tasarım */
@media (max-width: 992px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.col-span-2 {
        grid-column: auto;
    }
    
    .panel-color-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}

@media (max-width: 768px) {
    .settings-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    
    .tab-link {
        padding: 12px 15px;
        font-size: 13px;
    }
    
    .tab-content {
        padding: 15px;
    }
    
    .panel-preview-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-actions button {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .content-title {
        font-size: 20px;
    }
    
    .tab-link i {
        font-size: 14px;
    }
    
    .color-preview-card {
        min-width: 100%;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Sekme değiştirme
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        
        // Aktif sekme linkini değiştir
        $('.tab-link').removeClass('active');
        $(this).addClass('active');
        
        // İçeriği değiştir
        var target = $(this).attr('href').substring(1);
        $('.tab-content').removeClass('active');
        $('#' + target).addClass('active');
    });
    
    // Panel önizlemesi için renkleri güncelle
    function updatePanelPreviews() {
        var personalColor = $('#personal_color').val();
        var corporateColor = $('#corporate_color').val();
        var familyColor = $('#family_color').val();
        var vehicleColor = $('#vehicle_color').val();
        var homeColor = $('#home_color').val();
        
        $('.personal-panel').css({
            'border-left': '3px solid ' + personalColor,
            'background-color': adjustColorOpacity(personalColor, 0.05)
        });
        $('.personal-panel .panel-preview-header').css({
            'background-color': adjustColorOpacity(personalColor, 0.1),
        });
        
        $('.corporate-panel').css({
            'border-left': '3px solid ' + corporateColor,
            'background-color': adjustColorOpacity(corporateColor, 0.05)
        });
        $('.corporate-panel .panel-preview-header').css({
            'background-color': adjustColorOpacity(corporateColor, 0.1),
        });
        
        $('.family-panel').css({
            'border-left': '3px solid ' + familyColor,
            'background-color': adjustColorOpacity(familyColor, 0.05)
        });
        $('.family-panel .panel-preview-header').css({
            'background-color': adjustColorOpacity(familyColor, 0.1),
        });
        
        $('.vehicle-panel').css({
            'border-left': '3px solid ' + vehicleColor,
            'background-color': adjustColorOpacity(vehicleColor, 0.05)
        });
        $('.vehicle-panel .panel-preview-header').css({
            'background-color': adjustColorOpacity(vehicleColor, 0.1),
        });
        
        $('.home-panel').css({
            'border-left': '3px solid ' + homeColor,
            'background-color': adjustColorOpacity(homeColor, 0.05)
        });
        $('.home-panel .panel-preview-header').css({
            'background-color': adjustColorOpacity(homeColor, 0.1),
        });
    }
    
    // Renk değiştiğinde önizlemeyi güncelle
    $('input[type="color"]').on('input', function() {
        updatePanelPreviews();
    });
    
    // Varsayılan renklere dön
    $('#reset-colors').on('click', function(e) {
        e.preventDefault();
        $('#personal_color').val('#3498db');
        $('#corporate_color').val('#4caf50');
        $('#family_color').val('#ff9800');
        $('#vehicle_color').val('#e74c3c');
        $('#home_color').val('#9c27b0');
        updatePanelPreviews();
    });
    
    // Renk opaklığını ayarla (açık arka plan için)
    function adjustColorOpacity(hex, opacity) {
        // Hex kodunu RGB'ye dönüştür
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        
        // RGB renk kodunu background-color için döndür
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + opacity + ')';
    }
    
    // Sayfa yüklendiğinde önizlemeyi hemen güncelle
    updatePanelPreviews();
});
</script>