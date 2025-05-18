<?php
/**
 * Poliçe Ekleme/Düzenleme Formu
 * @version 1.2.3
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanında insured_party sütununun varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$policy_id = $editing || $renewing ? intval($_GET['id']) : 0;

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

    if ($editing) {
        $can_edit = true;
        if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
            $current_user_rep_id = get_current_user_rep_id();
            $policy_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            if ($policy_check->representative_id != $current_user_rep_id) {
                $can_edit = false;
                $message = 'Bu poliçeyi düzenleme yetkiniz yok.';
                $message_type = 'error';
            }
        }

        if ($can_edit) {
            $policy_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $policy_data, ['id' => $policy_id]);

            if ($result !== false) {
                $message = 'Poliçe başarıyla güncellendi.';
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=policies&updated=true";</script>';
                exit;
            } else {
                $message = 'Poliçe güncellenirken bir hata oluştu.';
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
if ($editing || $renewing) {
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
?>

<div class="ab-policy-form-container">
    <div class="ab-form-header">
        <h2>
            <?php 
            if ($editing) {
                echo '<i class="fas fa-edit"></i> Poliçe Düzenle';
            } elseif ($renewing) {
                echo '<i class="fas fa-sync-alt"></i> Poliçe Yenile';
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
    
    <form method="post" action="" class="ab-policy-form" enctype="multipart/form-data">
        <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
        
        <div class="ab-form-card panel-corporate">
            <div class="ab-form-section">
                <h3><i class="fas fa-user-check"></i> Müşteri ve Temsilci Bilgileri</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="customer_id">Müşteri <span class="required">*</span></label>
                        <select name="customer_id" id="customer_id" class="ab-select" required>
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
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="representative_id">Müşteri Temsilcisi</label>
                        <select name="representative_id" id="representative_id" class="ab-select">
                            <option value="">Temsilci Seçin</option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo $rep->id; ?>" <?php echo isset($policy->representative_id) && $policy->representative_id == $rep->id ? 'selected' : ''; ?>>
                                    <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
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
                        <input type="text" name="insured_party" id="insured_party" class="ab-input" value="<?php echo isset($policy->insured_party) ? esc_attr($policy->insured_party) : ''; ?>" <?php echo !isset($policy->insured_party) || empty($policy->insured_party) ? '' : 'required'; ?>>
                    </div>
                </div>
            </div>
            
            <div class="ab-form-section">
                <h3><i class="fas fa-file-contract"></i> Poliçe Detayları</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="policy_number">Poliçe No <span class="required">*</span></label>
                        <input type="text" name="policy_number" id="policy_number" class="ab-input"
                            value="<?php echo isset($policy->policy_number) ? esc_attr($policy->policy_number) : ''; ?>" required>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="policy_type">Poliçe Türü <span class="required">*</span></label>
                        <select name="policy_type" id="policy_type" class="ab-select" required>
                            <option value="">Poliçe Türü Seçin</option>
                            <?php foreach ($policy_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo isset($policy->policy_type) && $policy->policy_type == $type ? 'selected' : ''; ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="insurance_company">Sigorta Firması <span class="required">*</span></label>
                        <select name="insurance_company" id="insurance_company" class="ab-select" required>
                            <option value="">Sigorta Firması Seçin</option>
                            <?php foreach ($insurance_companies as $company): ?>
                                <option value="<?php echo $company; ?>" <?php echo isset($policy->insurance_company) && $policy->insurance_company == $company ? 'selected' : ''; ?>>
                                    <?php echo esc_html($company); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="start_date">Başlangıç Tarihi <span class="required">*</span></label>
                        <input type="date" name="start_date" id="start_date" class="ab-input ab-date-input"
                            value="<?php echo isset($policy->start_date) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="end_date">Bitiş Tarihi <span class="required">*</span></label>
                        <input type="date" name="end_date" id="end_date" class="ab-input ab-date-input"
                            value="<?php echo isset($policy->end_date) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" required>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="premium_amount">Prim Tutarı (₺) <span class="required">*</span></label>
                        <input type="number" name="premium_amount" id="premium_amount" class="ab-input" step="0.01" min="0"
                            value="<?php echo isset($policy->premium_amount) ? esc_attr($policy->premium_amount) : ''; ?>" required>
                    </div>
                </div>
                
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
            </div>
            
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
            
            <div class="ab-form-actions">
                <a href="?view=policies" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
                <button type="submit" name="save_policy" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : 'Kaydet'; ?>
                </button>
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
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#start_date, #end_date').change(function() {
        var startDate = $('#start_date').val();
        var endDate = $('#end_date').val();
        
        if (startDate && endDate && new Date(endDate) < new Date(startDate)) {
            alert('Bitiş tarihi, başlangıç tarihinden önce olamaz!');
            $('#end_date').val('');
        }
    });

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

    $('#document').change(function() {
        var file = this.files[0];
        if(!file) return;
        
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

    $('#status').change(function() {
        var status = $(this).val();
        var statusText = status === 'aktif' ? 'Aktif' : 'Pasif';
        
        $('.ab-preview-badge .ab-badge')
            .removeClass('ab-badge-status-aktif ab-badge-status-pasif')
            .addClass('ab-badge-status-' + status)
            .text(statusText);
    });

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

    $('#customer_id').change(function() {
        var customerName = $(this).find('option:selected').text().split('(')[0].trim();
        if ($('#same_as_insured').is(':checked')) {
            $('#insured_party').val(customerName);
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