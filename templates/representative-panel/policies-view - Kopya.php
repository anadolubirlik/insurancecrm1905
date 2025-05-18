<?php
/**
 * Poliçe Detay Sayfası
 * @version 1.1.0
 */

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$policy_id = intval($_GET['id']);
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";

if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id) {
    $where_clause = $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
}

// Poliçe bilgilerini al
$policy = $wpdb->get_row($wpdb->prepare("
    SELECT p.*,
           c.first_name, c.last_name,
           u.display_name AS rep_name
    FROM $policies_table p
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE p.id = %d
    $where_clause
", $policy_id));

if (!$policy) {
    echo '<div class="ab-notice ab-error">Poliçe bulunamadı veya görüntüleme yetkiniz yok.</div>';
    return;
}

// Poliçe ile ilgili görevleri al
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$tasks = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $tasks_table 
    WHERE policy_id = %d
    ORDER BY due_date ASC
", $policy_id));

// Poliçe bitiş tarihini kontrol et
$current_date = date('Y-m-d');
$days_until_expiry = (strtotime($policy->end_date) - strtotime($current_date)) / (60 * 60 * 24);
$expiry_status = '';
$expiry_class = '';

if ($days_until_expiry < 0) {
    $expiry_status = 'Süresi Dolmuş';
    $expiry_class = 'expired';
} elseif ($days_until_expiry <= 30) {
    $expiry_status = 'Yakında Bitiyor (' . round($days_until_expiry) . ' gün)';
    $expiry_class = 'expiring-soon';
} else {
    $expiry_status = 'Aktif';
    $expiry_class = 'active';
}
?>

<div class="ab-policy-details">
    <!-- Poliçe Başlık Bilgisi -->
    <div class="ab-policy-header">
        <div class="ab-policy-title">
            <h1><i class="fas fa-file-contract"></i> Poliçe: <?php echo esc_html($policy->policy_number); ?></h1>
            <div class="ab-policy-meta">
                <span class="ab-badge ab-badge-status-<?php echo $policy->status; ?>">
                    <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                </span>
                <span class="ab-badge ab-badge-<?php echo $expiry_class; ?>">
                    <?php echo $expiry_status; ?>
                </span>
                <span class="ab-customer-info">
                    <i class="fas fa-user"></i>
                    <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>">
                        <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                    </a>
                </span>
            </div>
        </div>
        <div class="ab-policy-actions">
            <a href="?view=policies&action=edit&id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-primary">
                <i class="fas fa-edit"></i> Düzenle
            </a>
            <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-success">
                <i class="fas fa-tasks"></i> Yeni Görev
            </a>
            <a href="?view=policies" class="ab-btn ab-btn-secondary">
                <i class="fas fa-arrow-left"></i> Listeye Dön
            </a>
        </div>
    </div>
    
    <!-- Poliçe Bilgileri -->
    <div class="ab-panels">
        <div class="ab-panel panel-corporate">
            <div class="ab-panel-header">
                <h3><i class="fas fa-info-circle"></i> Poliçe Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Poliçe No</div>
                        <div class="ab-info-value"><?php echo esc_html($policy->policy_number); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Poliçe Türü</div>
                        <div class="ab-info-value"><?php echo esc_html($policy->policy_type); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Sigorta Firması</div>
                        <div class="ab-info-value"><?php echo esc_html($policy->insurance_company); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Müşteri Temsilcisi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($policy->rep_name) ? esc_html($policy->rep_name) : '<span class="ab-no-value">Atanmamış</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Başlangıç Tarihi</div>
                        <div class="ab-info-value"><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Bitiş Tarihi</div>
                        <div class="ab-info-value"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Prim Tutarı</div>
                        <div class="ab-info-value ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Durum</div>
                        <div class="ab-info-value">
                            <span class="ab-badge ab-badge-status-<?php echo $policy->status; ?>">
                                <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Döküman -->
        <div class="ab-panel panel-personal">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-pdf"></i> Poliçe Dökümanı</h3>
            </div>
            <div class="ab-panel-body">
                <?php if (!empty($policy->document_path)): ?>
                    <div class="ab-document">
                        <div class="ab-document-preview">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="ab-btn ab-btn-primary">
                            <i class="fas fa-download"></i> Dökümanı İndir
                        </a>
                    </div>
                <?php else: ?>
                    <div class="ab-empty-state ab-empty-small">
                        <i class="fas fa-file-upload"></i>
                        <p>Henüz yüklenmiş bir döküman bulunmuyor.</p>
                        <a href="?view=policies&action=edit&id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-sm">
                            Döküman Ekle
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Müşteri Bilgileri -->
        <div class="ab-panel panel-personal">
            <div class="ab-panel-header">
                <h3><i class="fas fa-user"></i> Müşteri Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Ad Soyad</div>
                        <div class="ab-info-value">
                            <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>">
                                <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                            </a>
                        </div>
                    </div>
                    
                    <?php
                    // Müşteri detaylarını al
                    $customer = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}insurance_crm_customers WHERE id = %d",
                        $policy->customer_id
                    ));
                    
                    if ($customer):
                    ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">TC Kimlik No</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->tc_identity); ?></div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">E-posta</div>
                        <div class="ab-info-value">
                            <a href="mailto:<?php echo esc_attr($customer->email); ?>">
                                <?php echo esc_html($customer->email); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Telefon</div>
                        <div class="ab-info-value">
                            <a href="tel:<?php echo esc_attr($customer->phone); ?>">
                                <?php echo esc_html($customer->phone); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Görevler -->
        <div class="ab-panel ab-full-panel panel-family">
            <div class="ab-panel-header">
                <h3><i class="fas fa-tasks"></i> İlgili Görevler</h3>
                <div class="ab-panel-actions">
                    <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-sm">
                        <i class="fas fa-plus"></i> Yeni Görev
                    </a>
                </div>
            </div>
            <div class="ab-panel-body">
                <?php if (empty($tasks)): ?>
                <div class="ab-empty-state ab-empty-small">
                    <p>Bu poliçe ile ilgili görev bulunmuyor.</p>
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
                            <?php 
                            foreach ($tasks as $task):
                                $is_overdue = strtotime($task->due_date) < time() && $task->status !== 'completed';
                                $row_class = $is_overdue ? 'overdue' : '';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>">
                                            <?php echo esc_html($task->task_description); ?>
                                        </a>
                                        <?php if ($is_overdue): ?>
                                            <span class="ab-badge ab-badge-danger">Gecikmiş</span>
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
                                        <span class="ab-badge ab-badge-task-<?php echo esc_attr($task->status); ?>">
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
                                            <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" title="Görüntüle">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" title="Düzenle">
                                                <i class="fas fa-edit"></i>
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
        
        <!-- Poliçe Yenileme -->
        <div class="ab-panel ab-full-panel panel-vehicle">
            <div class="ab-panel-header">
                <h3><i class="fas fa-sync-alt"></i> Poliçe Yenileme</h3>
            </div>
            <div class="ab-panel-body">
                <?php if ($days_until_expiry < 0): ?>
                <div class="ab-renewal-banner ab-renewal-expired">
                    <div class="ab-renewal-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="ab-renewal-content">
                        <h4>Bu poliçenin süresi dolmuştur!</h4>
                        <p>Poliçe <?php echo date('d.m.Y', strtotime($policy->end_date)); ?> tarihinde sona erdi.</p>
                    </div>
                    <div class="ab-renewal-action">
                        <a href="?view=policies&action=renew&id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-primary">
                            <i class="fas fa-sync-alt"></i> Poliçeyi Yenile
                        </a>
                    </div>
                </div>
                <?php elseif ($days_until_expiry <= 30): ?>
                <div class="ab-renewal-banner ab-renewal-soon">
                    <div class="ab-renewal-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="ab-renewal-content">
                        <h4>Bu poliçe yakında sona erecek!</h4>
                        <p>Poliçenin bitiş tarihine <?php echo round($days_until_expiry); ?> gün kaldı. Müşteriyle iletişime geçerek yenileme hakkında bilgi verin.</p>
                    </div>
                    <div class="ab-renewal-action">
                        <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>&task_type=renewal" class="ab-btn">
                            <i class="fas fa-tasks"></i> Hatırlatma Görevi Oluştur
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="ab-renewal-banner ab-renewal-active">
                    <div class="ab-renewal-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="ab-renewal-content">
                        <h4>Bu poliçe aktif durumda.</h4>
                        <p>Bitiş tarihi: <?php echo date('d.m.Y', strtotime($policy->end_date)); ?> (<?php echo round($days_until_expiry); ?> gün kaldı)</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* Poliçe detay stilleri */
.ab-policy-details {
    margin: 20px auto;
    max-width: 1200px;
    padding: 20px;
    font-family: inherit;
    color: #333;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e5e5;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    0% { opacity: 0; transform: translateY(-10px); }
    100% { opacity: 1; transform: translateY(0); }
}

.ab-policy-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ab-policy-title h1 {
    font-size: 24px;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #333;
}

.ab-policy-title h1 i {
    color: #5d5d5d;
}

.ab-policy-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.ab-customer-info {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 500;
}

.ab-customer-info a {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-info a:hover {
    text-decoration: underline;
}

.ab-policy-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Panel stilleri */
.ab-panels {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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
    color: #333;
}

.ab-panel-actions {
    display: flex;
    gap: 5px;
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
}

.ab-no-value {
    color: #999;
    font-style: italic;
}

/* Badge Stilleri */
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

.ab-badge-task-completed {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-task-pending {
    background-color: #f1f8ff;
    color: #0366d6;
}

.ab-badge-task-in_progress {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-task-cancelled {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-expired {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-expiring-soon {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-active {
    background-color: #e6ffed;
    color: #22863a;
}

/* Dökumanlar */
.ab-document {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
    padding: 15px 0;
}

.ab-document-preview {
    width: 120px;
    height: 160px;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    transition: transform 0.3s ease;
}

.ab-document-preview:hover {
    transform: scale(1.05);
}

.ab-document-preview i {
    font-size: 60px;
    color: #e53935;
}

/* Küçük boş durum */
.ab-empty-small {
    padding: 20px;
    text-align: center;
    background-color: #f9f9f9;
    border-radius: 4px;
}

.ab-empty-small i {
    font-size: 32px;
    color: #999;
    margin-bottom: 10px;
}

.ab-empty-small p {
    margin: 10px 0;
    color: #666;
}

/* Poliçe Yenileme Banner */
.ab-renewal-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    padding: 20px;
    border-radius: 6px;
    margin-bottom: 15px;
    gap: 20px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.ab-renewal-banner:hover {
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.ab-renewal-expired {
    background-color: #fff0f0;
    border: 1px solid #ffd7d9;
}

.ab-renewal-soon {
    background-color: #fff8e5;
    border: 1px solid #ffeab6;
}

.ab-renewal-active {
    background-color: #f0fff4;
    border: 1px solid #dcffe4;
}

.ab-renewal-icon {
    font-size: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.7);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
}

.ab-renewal-expired .ab-renewal-icon {
    color: #cb2431;
}

.ab-renewal-soon .ab-renewal-icon {
    color: #bf8700;
}

.ab-renewal-active .ab-renewal-icon {
    color: #22863a;
}

.ab-renewal-content {
    flex: 1;
    min-width: 200px;
}

.ab-renewal-content h4 {
    margin: 0 0 8px 0;
    font-size: 18px;
    font-weight: 600;
}

.ab-renewal-content p {
    margin: 0;
    font-size: 14px;
    color: #555;
}

.ab-renewal-action {
    display: flex;
    justify-content: flex-end;
    align-items: center;
}

/* Tablo yalın ve hafif gölgeli */
.ab-table-container {
    margin-top: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border-radius: 6px;
    overflow: hidden;
}

/* Animasyonlar */
.ab-panel, .ab-document-preview, .ab-renewal-banner {
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .ab-panels {
        grid-template-columns: 1fr;
    }

    .ab-policy-header {
        flex-direction: column;
    }

    .ab-policy-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .ab-info-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-renewal-banner {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .ab-renewal-content {
        text-align: center;
    }
    
    .ab-renewal-action {
        width: 100%;
        justify-content: center;
        margin-top: 15px;
    }
}
</style>