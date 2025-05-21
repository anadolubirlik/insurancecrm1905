<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 1.1.0
 */

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in()) {
    return;
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$task_id = $editing ? intval($_GET['id']) : 0;

// Müşteri ID'si veya Poliçe ID'si varsa form açılışında seçili gelsin
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_policy_id = isset($_GET['policy_id']) ? intval($_GET['policy_id']) : 0;

// Yenileme görevi ise parametreleri al
$task_type = isset($_GET['task_type']) ? sanitize_text_field($_GET['task_type']) : '';

// Form gönderildiğinde işlem yap
if (isset($_POST['save_task']) && isset($_POST['task_nonce']) && wp_verify_nonce($_POST['task_nonce'], 'save_task')) {
    
    // Görevi düzenleyecek kişinin yetkisi var mı?
    $can_edit = true;
    
    // Görev verileri
    $task_data = array(
        'customer_id' => intval($_POST['customer_id']),
        'policy_id' => !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null,
        'task_description' => sanitize_textarea_field($_POST['task_description']),
        'due_date' => sanitize_text_field($_POST['due_date']),
        'priority' => sanitize_text_field($_POST['priority']),
        'status' => sanitize_text_field($_POST['status']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null
    );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    // Temsilci kontrolü - temsilciyse ve temsilci seçilmediyse kendi ID'sini ekle
    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && empty($task_data['representative_id'])) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($current_user_rep_id) {
            $task_data['representative_id'] = $current_user_rep_id;
        }
    }
    
    if ($editing) {
        // Yetki kontrolü
        $is_admin = current_user_can('administrator') || current_user_can('insurance_manager');
        $current_user_rep_id = get_current_user_rep_id();
        
        if (!$is_admin) {
            $task_check = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d", $task_id
            ));
            
            if ($task_check->representative_id != $current_user_rep_id) {
                $can_edit = false;
                $message = 'Bu görevi düzenleme yetkiniz yok.';
                $message_type = 'error';
            }
        }
        
        if ($can_edit) {
            $task_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $task_data, ['id' => $task_id]);
            
            if ($result !== false) {
                $message = 'Görev başarıyla güncellendi.';
                $message_type = 'success';
                
                // Başarılı işlemden sonra yönlendirme
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=tasks&updated=true";</script>';
                exit;
            } else {
                $message = 'Görev güncellenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } else {
        // Yeni görev ekle
        $task_data['created_at'] = current_time('mysql');
        $task_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $task_data);
        
        if ($result !== false) {
            $new_task_id = $wpdb->insert_id;
            $message = 'Görev başarıyla eklendi.';
            $message_type = 'success';
            
            // Başarılı işlemden sonra yönlendirme
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            echo '<script>window.location.href = "?view=tasks&added=true";</script>';
            exit;
        } else {
            $message = 'Görev eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Görevi düzenlenecek verilerini al
$task = null;
if ($editing) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $task_id));
    
    if (!$task) {
        echo '<div class="ab-notice ab-error">Görev bulunamadı.</div>';
        return;
    }
    
    // Yetki kontrolü (temsilci sadece kendi görevlerini düzenleyebilir)
    if (!current_user_can('administrator') && !current_user_can('insurance_manager')) {
        $current_user_rep_id = get_current_user_rep_id();
        if ($task->representative_id != $current_user_rep_id) {
            echo '<div class="ab-notice ab-error">Bu görevi düzenleme yetkiniz yok.</div>';
            return;
        }
    }
}

// Görev türüne göre varsayılan değerleri ayarla
$default_task_description = '';
$default_due_date = date('Y-m-d\TH:i');
$default_priority = 'medium';

// Eğer poliçe yenileme görevi ise
if ($task_type === 'renewal' && !empty($selected_policy_id)) {
    global $wpdb;
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    
    $policy = $wpdb->get_row($wpdb->prepare("
        SELECT p.*, c.first_name, c.last_name 
        FROM $policies_table p
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
        WHERE p.id = %d
    ", $selected_policy_id));
    
    if ($policy) {
        $selected_customer_id = $policy->customer_id;
        $default_task_description = "Poliçe yenileme hatırlatması: {$policy->policy_number}\n\nMüşteri: {$policy->first_name} {$policy->last_name}\nPoliçe No: {$policy->policy_number}\nPoliçe Türü: {$policy->policy_type}\nSigorta Firması: {$policy->insurance_company}\nBitiş Tarihi: " . date('d.m.Y', strtotime($policy->end_date)) . "\n\nMüşteriye poliçe yenileme hakkında bilgi verilecek.";
        
        // Son tarih, poliçe bitiş tarihinden 1 hafta önce olsun
        $due_date = new DateTime($policy->end_date);
        $due_date->modify('-1 week');
        $default_due_date = $due_date->format('Y-m-d\TH:i');
        
        // Öncelik "yüksek" olsun
        $default_priority = 'high';
    }
}

// Müşterileri al
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("
    SELECT id, first_name, last_name 
    FROM $customers_table 
    WHERE status = 'aktif'
    ORDER BY first_name, last_name
");

// Poliçeleri al
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policies = $wpdb->get_results("
    SELECT id, policy_number, customer_id 
    FROM $policies_table 
    WHERE status = 'aktif'
    ORDER BY id DESC
");

// Temsilcileri al
$representatives = [];
$reps_table = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results("
    SELECT r.id, u.display_name 
    FROM $reps_table r
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE r.status = 'active'
    ORDER BY u.display_name ASC
");

// Eğer task düzenleme modundaysa veya önceden müşteri/poliçe seçildiyse
$selected_id = isset($task->customer_id) ? $task->customer_id : $selected_customer_id;
$selected_policy = isset($task->policy_id) ? $task->policy_id : $selected_policy_id;
?>

<div class="ab-task-form-container">
    <div class="ab-form-header">
        <h2>
            <?php if ($editing): ?>
                <i class="fas fa-edit"></i> Görev Düzenle
            <?php else: ?>
                <i class="fas fa-plus-circle"></i> Yeni Görev Ekle
            <?php endif; ?>
        </h2>
        <a href="?view=tasks" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>

    <?php if (isset($message)): ?>
    <div class="ab-notice ab-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" class="ab-task-form">
        <?php wp_nonce_field('save_task', 'task_nonce'); ?>
        
        <div class="ab-form-card panel-family">
            <div class="ab-form-section">
                <h3><i class="fas fa-tasks"></i> Görev Bilgileri</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group ab-full-width">
                        <label for="task_description">Görev Açıklaması <span class="required">*</span></label>
                        <textarea name="task_description" id="task_description" class="ab-textarea" rows="4" required><?php echo $editing ? esc_textarea($task->task_description) : esc_textarea($default_task_description); ?></textarea>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="customer_id">Müşteri <span class="required">*</span></label>
                        <select name="customer_id" id="customer_id" class="ab-select" required>
                            <option value="">Müşteri Seçin</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer->id; ?>" <?php selected($selected_id, $customer->id); ?>>
                                    <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="policy_id">İlgili Poliçe</label>
                        <select name="policy_id" id="policy_id" class="ab-select">
                            <option value="">Poliçe Seçin (Opsiyonel)</option>
                            <?php foreach ($policies as $policy): ?>
                                <option value="<?php echo $policy->id; ?>" 
                                    data-customer="<?php echo $policy->customer_id; ?>" 
                                    <?php selected($selected_policy, $policy->id); ?>>
                                    <?php echo esc_html($policy->policy_number); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="due_date">Son Tarih <span class="required">*</span></label>
                        <input type="datetime-local" name="due_date" id="due_date" class="ab-input" 
                               value="<?php echo $editing ? date('Y-m-d\TH:i', strtotime($task->due_date)) : $default_due_date; ?>" required>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="priority">Öncelik <span class="required">*</span></label>
                        <select name="priority" id="priority" class="ab-select" required>
                            <option value="low" <?php echo ($editing && $task->priority === 'low') || (!$editing && $default_priority === 'low') ? 'selected' : ''; ?>>Düşük</option>
                            <option value="medium" <?php echo ($editing && $task->priority === 'medium') || (!$editing && $default_priority === 'medium') ? 'selected' : ''; ?>>Orta</option>
                            <option value="high" <?php echo ($editing && $task->priority === 'high') || (!$editing && $default_priority === 'high') ? 'selected' : ''; ?>>Yüksek</option>
                        </select>
                        <div class="ab-priority-preview">
                            <span class="ab-badge ab-badge-priority-<?php echo $editing ? $task->priority : $default_priority; ?>">
                                <?php 
                                $priority = $editing ? $task->priority : $default_priority;
                                switch ($priority) {
                                    case 'low': echo 'Düşük'; break;
                                    case 'medium': echo 'Orta'; break;
                                    case 'high': echo 'Yüksek'; break;
                                    default: echo ucfirst($priority); break;
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="status">Durum <span class="required">*</span></label>
                        <select name="status" id="status" class="ab-select" required>
                            <option value="pending" <?php echo $editing && $task->status === 'pending' ? 'selected' : (!$editing ? 'selected' : ''); ?>>Beklemede</option>
                            <option value="in_progress" <?php echo $editing && $task->status === 'in_progress' ? 'selected' : ''; ?>>İşlemde</option>
                            <option value="completed" <?php echo $editing && $task->status === 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                            <option value="cancelled" <?php echo $editing && $task->status === 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                        </select>
                        <div class="ab-status-preview">
                            <span class="ab-badge ab-badge-task-<?php echo $editing ? $task->status : 'pending'; ?>">
                                <?php 
                                $status = $editing ? $task->status : 'pending';
                                switch ($status) {
                                    case 'pending': echo 'Beklemede'; break;
                                    case 'in_progress': echo 'İşlemde'; break;
                                    case 'completed': echo 'Tamamlandı'; break;
                                    case 'cancelled': echo 'İptal Edildi'; break;
                                    default: echo ucfirst($status); break;
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="representative_id">Sorumlu Temsilci</label>
                        <select name="representative_id" id="representative_id" class="ab-select">
                            <option value="">Sorumlu Temsilci Seçin (Opsiyonel)</option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo $rep->id; ?>" <?php echo $editing && $task->representative_id == $rep->id ? 'selected' : ''; ?>>
                                    <?php echo esc_html($rep->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="ab-form-actions">
                <a href="?view=tasks" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
                <button type="submit" name="save_task" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : 'Kaydet'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<style>
/* Form Stilleri */
.ab-task-form-container {
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

/* Form kartı */
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

/* Form Bölümleri */
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

/* Form Satırları */
.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 20px;
    gap: 15px;
}

.ab-form-row:last-child {
    margin-bottom: 0;
}

.ab-form-group {
    flex: 1;
    min-width: 250px;
}

.ab-form-group.ab-full-width {
    flex-basis: 100%;
    width: 100%;
}

/* Form Etiketleri */
.ab-form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #444;
    font-size: 14px;
}

.required {
    color: #e53935;
    margin-left: 3px;
}

/* Input Stilleri */
.ab-input, .ab-select, .ab-textarea {
    width: 100%;
    padding: 12px 15px;
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
    min-height: 120px;
    line-height: 1.5;
}

/* Önizleme alanları */
.ab-priority-preview, .ab-status-preview {
    margin-top: 8px;
    display: flex;
}

/* Form Actions */
.ab-form-actions {
    padding: 18px 20px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    background-color: #f9f9fa;
}

/* Geçmiş Tarih Uyarısı */
.past-date-warning {
    margin-top: 10px;
    padding: 8px 12px;
    background-color: #fff8e5;
    border: 1px solid #ffeab6;
    border-left-width: 4px;
    border-left-color: #bf8700;
    border-radius: 4px;
    font-size: 13px;
    color: #856404;
}

/* Animasyonlar */
.ab-form-group {
    opacity: 0;
    transform: translateY(10px);
    animation: fadeIn 0.3s ease forwards;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .ab-form-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-form-group {
        width: 100%;
    }
    
    .ab-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Müşteri seçildiğinde, ilgili poliçeleri filtrele
    $('#customer_id').change(function() {
        var customer_id = $(this).val();
        
        // Tüm poliçe seçeneklerini gizle
        $('#policy_id option').hide();
        $('#policy_id').val('');
        
        // Sadece "Poliçe Seçin" seçeneğini ve seçilen müşteriye ait poliçeleri göster
        $('#policy_id option:first').show();
        
        if (customer_id) {
            $('#policy_id option[data-customer="' + customer_id + '"]').show();
        }
    });
    
    // Sayfa yüklendiğinde, eğer düzenleme modundaysa ve bir müşteri seçiliyse, poliçeleri filtrele
    if ($('#customer_id').val()) {
        $('#customer_id').trigger('change');
    }
    
    // Öncelik değiştiğinde önizleme güncelle
    function updatePriorityPreview() {
        var priority = $('#priority').val();
        var priorityText = $('#priority option:selected').text();
        $('.ab-priority-preview .ab-badge')
            .removeClass('ab-badge-priority-low ab-badge-priority-medium ab-badge-priority-high')
            .addClass('ab-badge-priority-' + priority)
            .text(priorityText);
    }
    
    // Durum değiştiğinde önizleme güncelle
    function updateStatusPreview() {
        var status = $('#status').val();
        var statusText = $('#status option:selected').text();
        $('.ab-status-preview .ab-badge')
            .removeClass('ab-badge-task-pending ab-badge-task-in_progress ab-badge-task-completed ab-badge-task-cancelled')
            .addClass('ab-badge-task-' + status)
            .text(statusText);
    }
    
    // Değişiklikler olduğunda önizlemeleri güncelle
    $('#priority').change(updatePriorityPreview);
    $('#status').change(updateStatusPreview);
    
    // Son tarihin geçmiş olup olmadığını kontrol et
    function checkDueDate() {
        var dueDateInput = $('#due_date').val();
        if (dueDateInput) {
            var dueDate = new Date(dueDateInput);
            var now = new Date();
            
            if (dueDate < now) {
                $('#due_date').addClass('past-date');
                $('.past-date-warning').remove();
                $('<div class="ab-notice ab-warning past-date-warning"><i class="fas fa-exclamation-triangle"></i> Girdiğiniz son tarih geçmişte kalmış.</div>')
                    .insertAfter('#due_date');
            } else {
                $('#due_date').removeClass('past-date');
                $('.past-date-warning').remove();
            }
        }
    }
    
    $('#due_date').on('change', checkDueDate);
    
    // Sayfa yüklendiğinde kontrolleri çalıştır
    checkDueDate();
    updatePriorityPreview();
    updateStatusPreview();
    
    // Form alanları animasyonu
    $('.ab-form-group').each(function(index) {
        $(this).delay(50 * index).animate({
            'opacity': '1',
            'transform': 'translateY(0)'
        }, 300);
    });
});
</script>