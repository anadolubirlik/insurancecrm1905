<?php
/**
 * Görev Detay Sayfası
 * @version 1.1.0
 */

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$task_id = intval($_GET['id']);
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';

// Yönetici kontrolü
$is_admin = current_user_can('administrator') || current_user_can('insurance_manager');

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";

if (!$is_admin && $current_user_rep_id) {
    $where_clause = $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
}

// Görev bilgilerini al
$task = $wpdb->get_row($wpdb->prepare("
    SELECT t.*,
           c.first_name, c.last_name,
           p.policy_number,
           u.display_name AS rep_name
    FROM $tasks_table t
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON t.policy_id = p.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE t.id = %d
    $where_clause
", $task_id));

if (!$task) {
    echo '<div class="ab-notice ab-error">Görev bulunamadı veya görüntüleme yetkiniz yok.</div>';
    return;
}

// Görev son tarih kontrolü
$current_date = date('Y-m-d H:i:s');
$is_overdue = strtotime($task->due_date) < strtotime($current_date) && $task->status !== 'completed';

// Görev içeriğini doğru şekilde biçimlendir
$task_description_formatted = nl2br(esc_html($task->task_description));
?>

<div class="ab-task-details">
    <!-- Görev Başlığı -->
    <div class="ab-task-header">
        <div class="ab-task-title">
            <h1><i class="fas fa-clipboard-list"></i> <?php echo esc_html($task->task_description); ?></h1>
            <div class="ab-task-meta">
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
                
                <span class="ab-badge ab-badge-priority-<?php echo esc_attr($task->priority); ?>">
                    <?php 
                    switch ($task->priority) {
                        case 'low': echo 'Düşük Öncelik'; break;
                        case 'medium': echo 'Orta Öncelik'; break;
                        case 'high': echo 'Yüksek Öncelik'; break;
                        default: echo ucfirst($task->priority) . ' Öncelik'; break;
                    }
                    ?>
                </span>
                
                <?php if ($is_overdue): ?>
                <span class="ab-badge ab-badge-danger">Gecikmiş!</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="ab-task-actions">
            <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="ab-btn ab-btn-primary">
                <i class="fas fa-edit"></i> Düzenle
            </a>
            
            <?php if ($task->status !== 'completed' && ($is_admin || $task->representative_id == $current_user_rep_id)): ?>
            <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="ab-btn ab-btn-success"
               onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                <i class="fas fa-check"></i> Tamamla
            </a>
            <?php endif; ?>
            
            <a href="?view=tasks" class="ab-btn ab-btn-secondary">
                <i class="fas fa-arrow-left"></i> Listeye Dön
            </a>
        </div>
    </div>
    
    <!-- Görev Bilgileri -->
    <div class="ab-panels">
        <div class="ab-panel panel-family">
            <div class="ab-panel-header">
                <h3><i class="fas fa-info-circle"></i> Görev Detayları</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Müşteri</div>
                        <div class="ab-info-value">
                            <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>">
                                <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">İlgili Poliçe</div>
                        <div class="ab-info-value">
                            <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                            <?php else: ?>
                                <span class="ab-no-value">Poliçe belirtilmemiş</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Son Tarih</div>
                        <div class="ab-info-value <?php echo $is_overdue ? 'overdue' : ''; ?>">
                            <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?>
                            <?php if ($is_overdue): ?>
                            <span class="ab-overdue-text">(Gecikmiş!)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Sorumlu Temsilci</div>
                        <div class="ab-info-value">
                            <?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="ab-no-value">Atanmamış</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Görev Açıklaması</div>
                        <div class="ab-info-value ab-task-description-text">
                            <?php echo $task_description_formatted; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Oluşturulma Tarihi</div>
                        <div class="ab-info-value">
                            <?php echo date('d.m.Y H:i', strtotime($task->created_at)); ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Son Güncelleme</div>
                        <div class="ab-info-value">
                            <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Durum Bilgisi -->
        <div class="ab-panel panel-vehicle">
            <div class="ab-panel-header">
                <h3><i class="fas fa-chart-line"></i> Durum</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-status-info">
                    <?php 
                    switch ($task->status) {
                        case 'pending':
                            $status_icon = 'clock';
                            $status_text = 'Bu görev beklemede. Henüz çalışmaya başlanmadı.';
                            break;
                        case 'in_progress':
                            $status_icon = 'spinner';
                            $status_text = 'Bu görev üzerinde şu anda çalışılıyor.';
                            break;
                        case 'completed':
                            $status_icon = 'check-circle';
                            $status_text = 'Bu görev tamamlandı.';
                            break;
                        case 'cancelled':
                            $status_icon = 'ban';
                            $status_text = 'Bu görev iptal edildi.';
                            break;
                        default:
                            $status_icon = 'question-circle';
                            $status_text = 'Bu görevin durumu belirsiz.';
                    }
                    ?>
                    
                    <div class="ab-status-icon">
                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                    </div>
                    <div class="ab-status-message">
                        <p><?php echo $status_text; ?></p>
                        
                        <?php if ($task->status === 'completed'): ?>
                            <p>Tamamlanma Tarihi: <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($is_overdue && $task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                            <div class="ab-overdue-alert">
                                <p><i class="fas fa-exclamation-triangle"></i> Bu görevin son tarihi geçti.</p>
                                <p>Son Tarih: <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?></p>
                                <p>Gecikme: <?php 
                                    $diff = strtotime($current_date) - strtotime($task->due_date);
                                    $days = floor($diff / (60 * 60 * 24));
                                    $hours = floor(($diff - ($days * 60 * 60 * 24)) / (60 * 60));
                                    
                                    if ($days > 0) {
                                        echo $days . ' gün ' . $hours . ' saat';
                                    } else {
                                        echo $hours . ' saat';
                                    }
                                ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                <div class="ab-action-buttons">
                    <?php if ($is_admin || $task->representative_id == $current_user_rep_id): ?>
                    <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="ab-btn ab-btn-success">
                        <i class="fas fa-check"></i> Görevi Tamamla
                    </a>
                    <?php endif; ?>
                    
                    <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="ab-btn">
                        <i class="fas fa-edit"></i> Durumu Değiştir
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- İlgili Bilgiler -->
    <div class="ab-panel ab-full-panel panel-corporate">
        <div class="ab-panel-header">
            <h3><i class="fas fa-link"></i> İlişkili Bilgiler</h3>
        </div>
        <div class="ab-panel-body">
            <div class="ab-related-links">
                <div class="ab-related-link">
                    <div class="ab-related-icon customer-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="ab-related-content">
                        <h4>Müşteri</h4>
                        <p><?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></p>
                        <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="ab-btn ab-btn-sm">
                            <i class="fas fa-external-link-alt"></i> Müşteri Detayları
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                <div class="ab-related-link">
                    <div class="ab-related-icon policy-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div class="ab-related-content">
                        <h4>İlgili Poliçe</h4>
                        <p><?php echo esc_html($task->policy_number); ?></p>
                        <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="ab-btn ab-btn-sm">
                            <i class="fas fa-external-link-alt"></i> Poliçe Detayları
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="ab-related-link">
                    <div class="ab-related-icon task-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="ab-related-content">
                        <h4>Görevler</h4>
                        <p>Bu müşteri için diğer görevleri görüntüleyin</p>
                        <a href="?view=tasks&customer_id=<?php echo $task->customer_id; ?>" class="ab-btn ab-btn-sm">
                            <i class="fas fa-list"></i> Müşterinin Görevleri
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Görev detay stilleri */
.ab-task-details {
    margin: 20px auto;
    max-width: 1200px;
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

.ab-task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-task-title h1 {
    font-size: 22px;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #333;
    font-weight: 600;
    line-height: 1.3;
}

.ab-task-title h1 i {
    color: #555;
}

.ab-task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.ab-task-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Panel stilleri */
.ab-panels {
    display: grid;
    grid-template-columns: 3fr 2fr;
    gap: 20px;
    margin-bottom: 20px;
}

.ab-panel {
    background-color: #fff;
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.ab-panel:hover {
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
}

.ab-full-panel {
    grid-column: 1 / -1;
}

.ab-panel-header {
    background-color: #f8f9fa;
    padding: 14px 18px;
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

.ab-panel-body {
    padding: 18px;
}

/* Bilgi Grid */
.ab-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
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
    margin-bottom: 6px;
}

.ab-info-value {
    font-size: 14px;
    padding: 3px 0;
}

.ab-info-value a {
    color: #2271b1;
    text-decoration: none;
}

.ab-info-value a:hover {
    text-decoration: underline;
    color: #0366d6;
}

.ab-no-value {
    color: #999;
    font-style: italic;
}

.ab-info-value.overdue {
    color: #cb2431;
    font-weight: 500;
}

.ab-overdue-text {
    color: #cb2431;
    font-weight: 500;
    margin-left: 5px;
}

.ab-task-description-text {
    white-space: pre-line;
    line-height: 1.6;
    padding: 10px;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
}

/* Durum bilgisi */
.ab-status-info {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    animation: fadeIn 0.5s ease;
}

.ab-status-icon {
    font-size: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.7);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
}

.ab-status-icon i {
    color: #666;
}

.ab-status-info .fa-clock { color: #0366d6; }
.ab-status-info .fa-spinner { color: #bf8700; }
.ab-status-info .fa-check-circle { color: #22863a; }
.ab-status-info .fa-ban { color: #666; }

.ab-status-message {
    flex: 1;
}

.ab-status-message p {
    margin: 0 0 10px 0;
    line-height: 1.5;
    color: #555;
}

.ab-overdue-alert {
    background-color: #fff0f0;
    border: 1px solid #ffd7d9;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
    color: #cb2431;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    animation: fadeIn 0.5s ease;
}

.ab-overdue-alert p {
    margin: 5px 0;
    color: #cb2431;
}

.ab-overdue-alert p:first-child {
    font-weight: 600;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-overdue-alert i {
    color: #cb2431;
}

.ab-action-buttons {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* İlişkili bilgiler */
.ab-related-links {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}

.ab-related-link {
    display: flex;
    gap: 15px;
    padding: 20px;
    border: 1px solid #eee;
    border-radius: 6px;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.ab-related-link:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0, 0, 0, 0.08);
    background-color: #fff;
}

.ab-related-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    font-size: 22px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-related-content {
    flex: 1;
}

.ab-related-content h4 {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 600;
}

.ab-related-content p {
    margin: 0 0 12px 0;
    font-size: 13px;
    color: #666;
}

.ab-btn-sm {
    padding: 6px 10px;
    font-size: 12px;
}

/* Animasyonlar */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.ab-panel, .ab-related-link, .ab-overdue-alert {
    animation: fadeIn 0.5s ease;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .ab-panels {
        grid-template-columns: 1fr;
    }

    .ab-task-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .ab-task-actions {
        width: 100%;
        justify-content: flex-start;
        margin-top: 10px;
    }

    .ab-info-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-status-info {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .ab-related-links {
        grid-template-columns: 1fr;
    }
    
    .ab-related-link {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .ab-related-content {
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .ab-action-buttons {
        justify-content: center;
    }
}
</style>