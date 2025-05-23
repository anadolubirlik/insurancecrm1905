<?php
/**
 * Poliçe Ekleme/Düzenleme Formu
 * @version 1.3.1
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanında gerekli sütunların varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// insured_party sütunu kontrolü
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
}

// İptal bilgileri için sütunlar
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$cancelling = isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id']) && intval($_GET['id']) > 0;
$policy_id = $editing || $renewing || $cancelling ? intval($_GET['id']) : 0;

$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if (isset($_POST['save_policy']) && isset($_POST['policy_nonce']) && wp_verify_nonce($_POST['policy_nonce'], 'save_policy')) {
    $policy_data = array(
        'customer_id' => intval($_POST['customer_id']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null,
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'status' => sanitize_text_field($_POST['status']),
        'insured_party' => isset($_POST['same_as_insured']) && $_POST['same_as_insured'] === 'yes' ? '' : sanitize_text_field($_POST['insured_party'])
    );

    // İptal bilgilerini ekle
    if (isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes') {
        $policy_data['cancellation_date'] = sanitize_text_field($_POST['cancellation_date']);
        $policy_data['refunded_amount'] = !empty($_POST['refunded_amount']) ? floatval($_POST['refunded_amount']) : 0;
        $policy_data['status'] = 'pasif'; // İptal edilen poliçeyi pasif yap
    }

    if (!empty($_FILES['document']['name'])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('pdf', 'doc', 'docx');
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed_file_types)) {
            $file_name = 'policy-' . time() . '-' . sanitize_file_name($_FILES['document']['name']);
            $file_path = $policy_upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $policy_data['document_path'] = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $file_name;
            } else {
                $upload_error = true;
            }
        } else {
            $file_type_error = true;
        }
    }

    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && empty($policy_data['representative_id'])) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($current_user_rep_id) {
            $policy_data['representative_id'] = $current_user_rep_id;
        }
    }

    $table_name = $wpdb->prefix . 'insurance_crm_policies';

    if ($editing || $cancelling) {
        $can_edit = true;
        if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
            $current_user_rep_id = get_current_user_rep_id();
            $policy_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            if ($policy_check->representative_id != $current_user_rep_id) {
                $can_edit = false;
                $message = 'Bu poliçeyi düzenleme/iptal etme yetkiniz yok.';
                $message_type = 'error';
            }
        }

        if ($can_edit) {
            $policy_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $policy_data, ['id' => $policy_id]);

            if ($result !== false) {
                $action_text = isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes' ? 'iptal edildi' : 'güncellendi';
                $message = 'Poliçe başarıyla ' . $action_text . '.';
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=policies&updated=true";</script>';
                exit;
            } else {
                $message = 'Poliçe işlenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } else {
        $policy_data['created_at'] = current_time('mysql');
        $policy_data['updated_at'] = current_time('mysql');

        if ($renewing) {
            $old_policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            if ($old_policy) {
                $wpdb->update($table_name, array('status' => 'pasif'), array('id' => $policy_id));

                if (empty($policy_data['customer_id'])) $policy_data['customer_id'] = $old_policy->customer_id;
                if (empty($policy_data['representative_id'])) $policy_data['representative_id'] = $old_policy->representative_id;
                if (empty($policy_data['policy_type'])) $policy_data['policy_type'] = $old_policy->policy_type;
                if (empty($policy_data['insurance_company'])) $policy_data['insurance_company'] = $old_policy->insurance_company;
                if (empty($policy_data['insured_party'])) $policy_data['insured_party'] = $old_policy->insured_party;
            }
        }

        $result = $wpdb->insert($table_name, $policy_data);

        if ($result !== false) {
            $new_policy_id = $wpdb->insert_id;
            $message = 'Poliçe başarıyla eklendi.';
            $message_type = 'success';
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            echo '<script>window.location.href = "?view=policies&added=true";</script>';
            exit;
        } else {
            $message = 'Poliçe eklenirken bir hata oluştu: ' . $wpdb->last_error;
            $message_type = 'error';
        }
    }
}

$policy = null;
if ($editing || $renewing || $cancelling) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    $policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));

    if (!$policy) {
        echo '<div class="ab-notice ab-error">Poliçe bulunamadı.</div>';
        return;
    }

    if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($policy->representative_id != $current_user_rep_id) {
            echo '<div class="ab-notice ab-error">Bu poliçeyi düzenleme yetkiniz yok.</div>';
            return;
        }
    }

    if ($renewing) {
        $old_end_date = new DateTime($policy->end_date);
        $new_start_date = clone $old_end_date;
        $new_start_date->modify('+1 day');
        $new_end_date = clone $new_start_date;
        $new_end_date->modify('+1 year');

        $policy->policy_number = $policy->policy_number . '-R';
        $policy->start_date = $new_start_date->format('Y-m-d');
        $policy->end_date = $new_end_date->format('Y-m-d');
    }
}

$settings = get_option('insurance_crm_settings');
$insurance_companies = isset($settings['insurance_companies']) ? $settings['insurance_companies'] : array();
$policy_types = isset($settings['default_policy_types']) ? $settings['default_policy_types'] : array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer');

global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("SELECT id, first_name, last_name, tc_identity FROM $customers_table WHERE status = 'aktif' ORDER BY first_name, last_name");

$representatives = [];
$reps_table = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results("SELECT r.id, u.display_name, r.title FROM $reps_table r LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID WHERE r.status = 'active' ORDER BY u.display_name ASC");

// Müşteri ad soyadını al (seçili müşteri için)
$selected_customer_name = '';
if ($selected_customer_id || (isset($policy->customer_id) && $policy->customer_id)) {
    $customer = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name FROM $customers_table WHERE id = %d", $selected_customer_id ?: $policy->customer_id));
    if ($customer) {
        $selected_customer_name = esc_html($customer->first_name . ' ' . $customer->last_name);
    }
}

// İptal işlemi için ek veriler
$is_cancelled = !empty($policy->cancellation_date);
$calculated_refund = 0;

if ($cancelling && !$is_cancelled) {
    // Bugüne kadarki kullanılan gün sayısını hesapla
    $start_date = new DateTime($policy->start_date);
    $end_date = new DateTime($policy->end_date);
    $today = new DateTime();
    
    // Toplam poliçe gün sayısı
    $total_days = $start_date->diff($end_date)->days;
    
    // Bugüne kadar kullanılan gün sayısı
    $used_days = $start_date->diff($today)->days;
    
    // Eğer bugün bitiş tarihinden sonraysa, kullanılan gün sayısı toplam gün sayısına eşittir
    if ($today > $end_date) {
        $used_days = $total_days;
    }
    
    // Kullanılmayan gün sayısı
    $remaining_days = max(0, $total_days - $used_days);
    
    // İade edilecek prim hesabı (orantılı olarak)
    $calculated_refund = round(($remaining_days / $total_days) * $policy->premium_amount, 2);
}
?>

<div class="ab-policy-form-container">
    <div class="ab-form-header">
        <h2>
            <?php 
            if ($editing) {
                echo '<i class="fas fa-edit"></i> Poliçe Düzenle';
            } elseif ($renewing) {
                echo '<i class="fas fa-sync-alt"></i> Poliçe Yenile';
            } elseif ($cancelling) {
                echo '<i class="fas fa-ban"></i> Poliçe İptal';
            } else {
                echo '<i class="fas fa-plus-circle"></i> Yeni Poliçe Ekle';
            }
            ?>
        </h2>
        <a href="?view=policies" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>

    <?php if (isset($message)): ?>
    <div class="ab-notice ab-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($file_type_error)): ?>
    <div class="ab-notice ab-error">
        <i class="fas fa-exclamation-circle"></i> Dosya türü desteklenmiyor. İzin verilen dosya türleri: PDF, DOC, DOCX.
    </div>
    <?php endif; ?>
    
    <?php if (isset($upload_error)): ?>
    <div class="ab-notice ab-error">
        <i class="fas fa-exclamation-circle"></i> Dosya yüklenirken bir hata oluştu. Lütfen tekrar deneyin.
    </div>
    <?php endif; ?>
    
    <?php if ($is_cancelled && ($editing || $cancelling)): ?>
    <div class="ab-cancelled-alert">
        <i class="fas fa-ban"></i>
        <div class="ab-cancelled-alert-content">
            <h3>BU POLİÇE İPTAL EDİLMİŞTİR</h3>
            <p>İptal Tarihi: <strong><?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></strong></p>
            <p>İade Edilen Tutar: <strong><?php echo number_format($policy->refunded_amount, 2, ',', '.'); ?> ₺</strong></p>
        </div>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" class="ab-policy-form" enctype="multipart/form-data">
        <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
        
        <div class="ab-form-card panel-corporate <?php echo $is_cancelled ? 'ab-form-card-cancelled' : ''; ?>">
            <?php if ($cancelling && !$is_cancelled): ?>
            <!-- İptal İşlemi Uyarı Kutusu -->
            <div class="ab-cancel-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="ab-cancel-warning-content">
                    <h3>Poliçe İptal İşlemi</h3>
                    <p>Bu poliçeyi iptal etmek üzeresiniz. İptal işlemi sonrası poliçe pasif duruma geçecektir.</p>
                    <p>Poliçe Numarası: <strong><?php echo esc_html($policy->policy_number); ?></strong></p>
                    <p>Müşteri: <strong><?php echo esc_html($selected_customer_name); ?></strong></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="ab-form-section">
                <h3><i class="fas fa-user-check"></i> Müşteri ve Temsilci Bilgileri</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="customer_id">Müşteri <span class="required">*</span></label>
                        <select name="customer_id" id="customer_id" class="ab-select" required <?php echo ($cancelling || $is_cancelled) ? 'disabled' : ''; ?>>
                            <option value="">Müşteri Seçin</option>
                            <?php 
                            $selected_id = isset($policy->customer_id) ? $policy->customer_id : $selected_customer_id;
                            foreach ($customers as $customer): 
                            ?>
                                <option value="<?php echo $customer->id; ?>" <?php selected($selected_id, $customer->id); ?>>
                                    <?php echo esc_html($customer->first_name . ' ' . $customer->last_name . ' (' . $customer->tc_identity . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($cancelling || $is_cancelled): ?>
                            <input type="hidden" name="customer_id" value="<?php echo $policy->customer_id; ?>">
                        <?php endif; ?>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="representative_id">Müşteri Temsilcisi</label>
                        <select name="representative_id" id="representative_id" class="ab-select" <?php echo ($cancelling || $is_cancelled) ? 'disabled' : ''; ?>>
                            <option value="">Temsilci Seçin</option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo $rep->id; ?>" <?php echo isset($policy->representative_id) && $policy->representative_id == $rep->id ? 'selected' : ''; ?>>
                                    <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($cancelling || $is_cancelled): ?>
                            <input type="hidden" name="representative_id" value="<?php echo $policy->representative_id; ?>">
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!$cancelling && !$is_cancelled): ?>
                <div class="ab-form-row">
                    <div class="ab-form-group ab-full-width">
                        <label>
                            <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" <?php echo !isset($policy->insured_party) || empty($policy->insured_party) ? 'checked' : ''; ?>>
                            Sigortalı ile Sigorta Ettiren Aynı Kişi mi?
                        </label>
                    </div>
                </div>
                
                <div class="ab-form-row insured-party-row" style="<?php echo (!isset($policy->insured_party) || empty($policy->insured_party)) ? 'display: none;' : ''; ?>">
                    <div class="ab-form-group ab-full-width">
                        <label for="insured_party">Sigorta Ettiren <span class="required">*</span></label>
                        <input type="text" name="insured_party" id="insured_party" class="ab-input" value="<?php echo isset($policy->insured_party) ? esc_attr($policy->insured_party) : ''; ?>">
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="insured_party" value="<?php echo isset($policy->insured_party) ? esc_attr($policy->insured_party) : ''; ?>">
                <input type="hidden" name="same_as_insured" value="<?php echo !isset($policy->insured_party) || empty($policy->insured_party) ? 'yes' : 'no'; ?>">
                <?php endif; ?>
            </div>
            
            <div class="ab-form-section">
                <h3><i class="fas fa-file-contract"></i> Poliçe Detayları</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="policy_number">Poliçe No <span class="required">*</span></label>
                        <input type="text" name="policy_number" id="policy_number" class="ab-input"
                            value="<?php echo isset($policy->policy_number) ? esc_attr($policy->policy_number) : ''; ?>" required <?php echo ($cancelling || $is_cancelled) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="policy_type">Poliçe Türü <span class="required">*</span></label>
                        <select name="policy_type" id="policy_type" class="ab-select" required <?php echo ($cancelling || $is_cancelled) ? 'disabled' : ''; ?>>
                            <option value="">Poliçe Türü Seçin</option>
                            <?php foreach ($policy_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo isset($policy->policy_type) && $policy->policy_type == $type ? 'selected' : ''; ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($cancelling || $is_cancelled): ?>
                            <input type="hidden" name="policy_type" value="<?php echo esc_attr($policy->policy_type); ?>">
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="insurance_company">Sigorta Firması <span class="required">*</span></label>
                        <select name="insurance_company" id="insurance_company" class="ab-select" required <?php echo ($cancelling || $is_cancelled) ? 'disabled' : ''; ?>>
                            <option value="">Sigorta Firması Seçin</option>
                            <?php foreach ($insurance_companies as $company): ?>
                                <option value="<?php echo $company; ?>" <?php echo isset($policy->insurance_company) && $policy->insurance_company == $company ? 'selected' : ''; ?>>
                                    <?php echo esc_html($company); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($cancelling || $is_cancelled): ?>
                            <input type="hidden" name="insurance_company" value="<?php echo esc_attr($policy->insurance_company); ?>">
                        <?php endif; ?>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="start_date">Başlangıç Tarihi <span class="required">*</span></label>
                        <input type="date" name="start_date" id="start_date" class="ab-input ab-date-input"
                            value="<?php echo isset($policy->start_date) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" required <?php echo ($cancelling || $is_cancelled) ? 'readonly' : ''; ?>>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="end_date">Bitiş Tarihi <span class="required">*</span></label>
                        <input type="date" name="end_date" id="end_date" class="ab-input ab-date-input"
                            value="<?php echo isset($policy->end_date) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" required <?php echo ($cancelling || $is_cancelled) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="premium_amount">Prim Tutarı (₺) <span class="required">*</span></label>
                        <input type="number" name="premium_amount" id="premium_amount" class="ab-input" step="0.01" min="0"
                            value="<?php echo isset($policy->premium_amount) ? esc_attr($policy->premium_amount) : ''; ?>" required <?php echo ($cancelling || $is_cancelled) ? 'readonly' : ''; ?>>
                    </div>
                </div>
                
                <?php if (!$cancelling && !$is_cancelled): ?>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="status">Durum</label>
                        <select name="status" id="status" class="ab-select">
                            <option value="aktif" <?php echo isset($policy->status) && $policy->status === 'pasif' ? '' : 'selected'; ?>>Aktif</option>
                            <option value="pasif" <?php echo isset($policy->status) && $policy->status === 'pasif' ? 'selected' : ''; ?>>Pasif</option>
                        </select>
                        <div class="ab-preview-badge">
                            <span class="ab-badge ab-badge-status-<?php echo isset($policy->status) ? $policy->status : 'aktif'; ?>">
                                <?php echo isset($policy->status) && $policy->status === 'pasif' ? 'Pasif' : 'Aktif'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- İptal edilmiş poliçe durumu için gizli alan -->
                <input type="hidden" name="status" value="pasif">
                
                <!-- İptal Bilgileri Bölümü -->
                <div class="ab-form-section ab-cancellation-section">
                    <h3><i class="fas fa-ban"></i> İptal Bilgileri</h3>
                    
                    <?php if ($cancelling && !$is_cancelled): ?>
                    <!-- Poliçe İptal Seçeneği -->
                    <div class="ab-form-row">
                        <div class="ab-form-group ab-full-width">
                            <div class="ab-cancel-option">
                                <label>
                                    <input type="checkbox" name="is_cancelled" id="is_cancelled" value="yes" <?php echo $is_cancelled ? 'checked' : ''; ?>>
                                    <span class="ab-cancel-label">Poliçe İptal Edilsin?</span>
                                </label>
                                <?php if ($is_cancelled): ?>
                                    <div class="ab-badge ab-badge-cancelled"><i class="fas fa-ban"></i> Bu poliçe zaten iptal edilmiş</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- İptal Detayları (başlangıçta gizli) -->
                    <div id="cancellation-details" class="<?php echo !$is_cancelled ? 'ab-hidden' : ''; ?>">
                    <?php endif; ?>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="cancellation_date">İptal Tarihi <span class="required">*</span></label>
                                <input type="date" name="cancellation_date" id="cancellation_date" class="ab-input ab-date-input"
                                    value="<?php echo isset($policy->cancellation_date) ? esc_attr($policy->cancellation_date) : date('Y-m-d'); ?>" 
                                    required <?php echo $is_cancelled && $editing ? 'readonly' : ''; ?>>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="refunded_amount">İade Edilen Prim (₺)</label>
                                <input type="number" name="refunded_amount" id="refunded_amount" class="ab-input" step="0.01" min="0"
                                    value="<?php echo isset($policy->refunded_amount) ? esc_attr($policy->refunded_amount) : $calculated_refund; ?>"
                                    <?php echo $is_cancelled && $editing ? 'readonly' : ''; ?>>
                                <?php if ($cancelling && !$is_cancelled && $calculated_refund > 0): ?>
                                    <div class="ab-form-note">
                                        <i class="fas fa-info-circle"></i> Tahmini iade tutarı: <?php echo number_format($calculated_refund, 2, ',', '.'); ?> ₺
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($cancelling && !$is_cancelled): ?>
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <div class="ab-cancel-info">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        <p>İptal işlemi sonrası poliçe durumu otomatik olarak <strong>Pasif</strong> olarak ayarlanacaktır.</p>
                                        <p>İptal tarihi, poliçenin geçerlilik süresinden önce olmalıdır.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                        <?php endif; ?>
                    
                    <!-- İptal seçeneği için gizli alan (edit modunda) -->
                    <?php if ($editing && $is_cancelled): ?>
                        <input type="hidden" name="is_cancelled" value="yes">
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!$cancelling && !$is_cancelled): ?>
            <div class="ab-form-section">
                <h3><i class="fas fa-file-pdf"></i> Poliçe Dökümanı</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group ab-full-width">
                        <?php if ($editing && !empty($policy->document_path)): ?>
                            <div class="ab-current-document">
                                <div class="ab-document-header">
                                    <i class="fas fa-file-pdf"></i> Mevcut Döküman
                                </div>
                                <div class="ab-document-content">
                                    <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="ab-btn ab-btn-sm">
                                        <i class="fas fa-file-pdf"></i> Dökümanı Görüntüle
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <label for="document" class="ab-document-upload-label">
                            <i class="fas fa-cloud-upload-alt"></i> Yeni Döküman Yükle
                        </label>
                        <input type="file" name="document" id="document" class="ab-file-input" accept=".pdf,.doc,.docx">
                        <p class="ab-form-help">İzin verilen dosya türleri: PDF, DOC, DOCX. Maksimum dosya boyutu: 10MB.</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <input type="hidden" name="document_path" value="<?php echo isset($policy->document_path) ? esc_url($policy->document_path) : ''; ?>">
                
                <?php if (!empty($policy->document_path)): ?>
                <div class="ab-form-section">
                    <h3><i class="fas fa-file-pdf"></i> Poliçe Dökümanı</h3>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group ab-full-width">
                            <div class="ab-current-document">
                                <div class="ab-document-header">
                                    <i class="fas fa-file-pdf"></i> Mevcut Döküman
                                </div>
                                <div class="ab-document-content">
                                    <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="ab-btn ab-btn-sm">
                                        <i class="fas fa-file-pdf"></i> Dökümanı Görüntüle
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="ab-form-actions">
                <a href="?view=policies" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> Vazgeç
                </a>
                <?php if (!($is_cancelled && $editing)): ?>
                <button type="submit" name="save_policy" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> 
                    <?php 
                        if ($editing) {
                            echo 'Güncelle';
                        } elseif ($cancelling) {
                            echo 'İptal Kaydını Kaydet';
                        } else {
                            echo 'Kaydet';
                        }
                    ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<style>
.ab-policy-form-container {
    max-width: 1000px;
    margin: 20px auto;
    padding: 20px;
    font-family: inherit;
    color: #333;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e5e5;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    0% { opacity: 0; transform: translateY(-10px); }
    100% { opacity: 1; transform: translateY(0); }
}

.ab-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-form-header h2 {
    margin: 0;
    font-size: 24px;
    color: #333;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-form-header h2 i {
    color: #555;
}

.ab-form-card {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.ab-form-card-cancelled {
    border: 2px solid #e53935;
}

.ab-cancelled-alert {
    padding: 15px 20px;
    background-color: #feecf0;
    border: 2px solid #e53935;
    border-radius: 6px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(229, 57, 53, 0.4); }
    70% { box-shadow: 0 0 0 10px rgba(229, 57, 53, 0); }
    100% { box-shadow: 0 0 0 0 rgba(229, 57, 53, 0); }
}

.ab-cancelled-alert i {
    font-size: 24px;
    color: #e53935;
}

.ab-cancelled-alert-content h3 {
    margin: 0 0 10px 0;
    color: #e53935;
    font-size: 18px;
    font-weight: 700;
}

.ab-cancelled-alert-content p {
    margin: 5px 0;
    font-size: 14px;
}

.ab-form-card:hover {
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
}

.ab-form-section {
    margin: 0;
    padding: 20px;
    border-bottom: 1px solid #f0f0f0;
}

.ab-form-section:last-child {
    border-bottom: none;
}

.ab-cancellation-section {
    background-color: #fff8f8;
}

.ab-form-section h3 {
    margin: 5px 0 20px 0;
    font-size: 18px;
    color: #444;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-form-section h3 i {
    color: #555;
}

.ab-cancellation-section h3 {
    color: #e53935;
}

.ab-cancellation-section h3 i {
    color: #e53935;
}

.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 15px;
    gap: 10px;
}

.ab-form-row:last-child {
    margin-bottom: 0;
}

.ab-form-group {
    flex: 1 1 250px;
    min-width: 250px;
    margin-bottom: 10px;
}

.ab-form-group.ab-full-width {
    flex: 1 1 100%;
    min-width: 100%;
}

.ab-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #444;
    font-size: 14px;
}

.required {
    color: #e53935;
    margin-left: 3px;
}

.ab-input, .ab-select, .ab-textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    color: #333;
    background-color: #fff;
    transition: all 0.3s ease;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: #4caf50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
    outline: none;
}

.ab-textarea {
    resize: vertical;
    min-height: 80px;
}

.ab-form-help {
    margin-top: 5px;
    font-size: 12px;
    color: #777;
}

.ab-file-input {
    padding: 10px 0;
    width: 100%;
}

.ab-date-input {
    font-family: inherit;
}

.ab-preview-badge {
    margin-top: 6px;
}

.ab-form-actions {
    padding: 15px 20px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background-color: #f9f9fa;
}

.ab-current-document {
    margin-bottom: 15px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
}

.ab-document-header {
    background-color: #f8f9fa;
    padding: 8px 12px;
    font-weight: 500;
    color: #333;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.ab-document-header i {
    color: #e53935;
}

.ab-document-content {
    padding: 12px;
    text-align: center;
}

.ab-document-upload-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* İptal işlemi için ek stiller */
.ab-cancel-warning {
    padding: 15px 20px;
    background-color: #feecf0;
    border-left: 4px solid #e53935;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 0;
}

.ab-cancel-warning i {
    font-size: 24px;
    color: #e53935;
}

.ab-cancel-warning-content h3 {
    margin: 0 0 10px 0;
    color: #e53935;
    font-size: 18px;
}

.ab-cancel-warning-content p {
    margin: 5px 0;
    font-size: 14px;
}

.ab-cancel-option {
    padding: 15px;
    background-color: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ab-cancel-label {
    font-size: 16px;
    font-weight: 500;
    margin-left: 5px;
}

.ab-cancel-info {
    padding: 12px;
    background-color: #fff8e5;
    border: 1px solid #ffc107;
    border-radius: 4px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.ab-cancel-info i {
    color: #ffc107;
    font-size: 18px;
    margin-top: 2px;
}

.ab-cancel-info p {
    margin: 5px 0;
    font-size: 13px;
}

.ab-badge-cancelled {
    background-color: #f3e5f5;
    color: #9c27b0;
}

.ab-form-note {
    margin-top: 5px;
    font-size: 12px;
    color: #2196f3;
    display: flex;
    align-items: center;
    gap: 4px;
}

.ab-hidden {
    display: none;
}

/* Daha fazla ekran boyutu için optimizasyon */
@media (max-width: 992px) {
    .ab-form-row {
        flex-direction: column;
        gap: 8px;
    }
    
    .ab-form-group {
        flex: 1 1 100%;
        min-width: 100%;
    }
}

@media (max-width: 768px) {
    .ab-policy-form-container {
        padding: 15px;
    }
    
    .ab-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .ab-form-section {
        padding: 15px;
    }
    
    .ab-form-group {
        margin-bottom: 8px;
    }
    
    .ab-input, .ab-select {
        padding: 7px 9px;
        font-size: 13px;
    }
}

@media (max-width: 576px) {
    .ab-policy-form-container {
        margin: 10px;
        padding: 10px;
    }
    
    .ab-form-header h2 {
        font-size: 20px;
    }
    
    .ab-form-section h3 {
        font-size: 16px;
    }
    
    .ab-cancel-warning {
        padding: 10px;
        flex-direction: column;
    }
    
    .ab-cancelled-alert {
        padding: 10px;
        flex-direction: column;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Tarih doğrulama
    $('#start_date, #end_date').change(function() {
        var startDate = $('#start_date').val();
        var endDate = $('#end_date').val();
        
        if (startDate && endDate && new Date(endDate) < new Date(startDate)) {
            alert('Bitiş tarihi, başlangıç tarihinden önce olamaz!');
            $('#end_date').val('');
        }
    });

    // Başlangıç tarihine göre bitiş tarihi ayarla
    $('#start_date').change(function() {
        var startDate = $(this).val();
        if (startDate) {
            var start = new Date(startDate);
            var end = new Date(start);
            end.setFullYear(end.getFullYear() + 1);
            var endFormatted = end.toISOString().split('T')[0];
            $('#end_date').val(endFormatted);
        }
    });

    // Dosya yükleme kontrolü
    $('#document').change(function() {
        var file = this.files[0];
        if (!file) return;
        
        var fileSize = file.size / 1024 / 1024;
        var fileType = file.name.split('.').pop().toLowerCase();
        var allowedTypes = ['pdf', 'doc', 'docx'];
        
        if ($.inArray(fileType, allowedTypes) === -1) {
            alert('İzin verilmeyen dosya türü. Lütfen PDF, DOC veya DOCX formatında bir dosya seçin.');
            $(this).val('');
        }
        
        if (fileSize > 10) {
            alert('Dosya boyutu çok büyük. Maksimum dosya boyutu 10MB olmalıdır.');
            $(this).val('');
        }
    });

    // Poliçe durumu değişikliği
    $('#status').change(function() {
        var status = $(this).val();
        var statusText = status === 'aktif' ? 'Aktif' : 'Pasif';
        
        $('.ab-preview-badge .ab-badge')
            .removeClass('ab-badge-status-aktif ab-badge-status-pasif')
            .addClass('ab-badge-status-' + status)
            .text(statusText);
    });

    // Sigortalı aynı kişi mi kontrolü
    $('#same_as_insured').change(function() {
        var isSame = $(this).is(':checked');
        var customerName = $('#customer_id option:selected').text().split('(')[0].trim();
        var insuredPartyRow = $('.insured-party-row');

        if (isSame) {
            $('#insured_party').val(customerName).removeAttr('required');
            insuredPartyRow.hide();
        } else {
            $('#insured_party').val('').attr('required', 'required');
            insuredPartyRow.show();
        }
    });

    // Müşteri değişikliği kontrolü
    $('#customer_id').change(function() {
        var customerName = $(this).find('option:selected').text().split('(')[0].trim();
        if ($('#same_as_insured').is(':checked')) {
            $('#insured_party').val(customerName);
        }
    });

    // İptal işlemi için detayları göster/gizle
    $('#is_cancelled').change(function() {
        if ($(this).is(':checked')) {
            $('#cancellation-details').removeClass('ab-hidden');
            $('#cancellation_date').attr('required', 'required');
        } else {
            $('#cancellation-details').addClass('ab-hidden');
            $('#cancellation_date').removeAttr('required');
        }
    });
    
    // İptal tarihi kontrolü
    $('#cancellation_date').change(function() {
        var cancellationDate = new Date($(this).val());
        var startDate = new Date($('#start_date').val());
        var endDate = new Date($('#end_date').val());
        var today = new Date();
        
        if (cancellationDate < startDate) {
            alert('İptal tarihi, poliçe başlangıç tarihinden önce olamaz!');
            $(this).val(today.toISOString().split('T')[0]);
            return;
        }
        
        if (cancellationDate > endDate) {
            alert('İptal tarihi, poliçe bitiş tarihinden sonra olamaz!');
            $(this).val(today.toISOString().split('T')[0]);
            return;
        }
        
        // İade edilecek prim tutarını hesapla
        if ($('#refunded_amount').length) {
            var totalDays = Math.round((endDate - startDate) / (1000 * 60 * 60 * 24));
            var usedDays = Math.round((cancellationDate - startDate) / (1000 * 60 * 60 * 24));
            var remainingDays = totalDays - usedDays;
            
            if (remainingDays > 0) {
                var premiumAmount = parseFloat($('#premium_amount').val());
                var refundAmount = (remainingDays / totalDays) * premiumAmount;
                $('#refunded_amount').val(refundAmount.toFixed(2));
            } else {
                $('#refunded_amount').val('0.00');
            }
        }
    });

    // Animasyonu sadeleştirerek üst üste binmeyi önleyelim
    $('.ab-form-group').each(function(index) {
        $(this).css({
            'opacity': '0'
        }).delay(50 * index).animate({
            'opacity': '1'
        }, 200);
    });
});
</script>