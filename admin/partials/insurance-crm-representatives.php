<?php
/**
 * Müşteri Temsilcileri Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 * @version    1.0.5
 */

if (!defined('WPINC')) {
    die;
}

// Sekme yönetimi
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'representatives';

// Aksiyon kontrolü
$action = isset($_GET['action']) ? $_GET['action'] : '';
$rep_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = ($action === 'edit' && $rep_id > 0);
$adding = ($action === 'new');
$edit_rep = null;

// Düzenleme için temsilci bilgilerini al
if ($editing) {
    global $wpdb;
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    
    // Debug için tablo adını görelim
    error_log('Tablo adı: ' . $table_reps);
    
    // Önce sadece temsilci kaydını al
    $rep_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_reps} WHERE id = %d",
        $rep_id
    ));
    
    if ($rep_record) {
        // Temsilci kaydı varsa, kullanıcı bilgilerini al
        $user_data = get_userdata($rep_record->user_id);
        
        if ($user_data) {
            // Kullanıcı verilerini ve temsilci verilerini birleştir
            $edit_rep = (object) array_merge(
                (array) $rep_record,
                array(
                    'email' => $user_data->user_email,
                    'display_name' => $user_data->display_name,
                    'username' => $user_data->user_login,
                    'first_name' => $user_data->first_name,
                    'last_name' => $user_data->last_name
                )
            );
        } else {
            // Kullanıcı kaydı yok ama temsilci kaydı var
            $edit_rep = $rep_record;
            $edit_rep->email = '';
            $edit_rep->display_name = 'Kullanıcı Kaydı Yok';
            $edit_rep->username = '';
            $edit_rep->first_name = '';
            $edit_rep->last_name = '';
            
            echo '<div class="notice notice-warning"><p>Bu temsilcinin WordPress kullanıcı kaydı bulunamadı (User ID: ' . $rep_record->user_id . ').</p></div>';
        }
    } else {
        // Temsilci kaydı bulunamadı
        echo '<div class="notice notice-error"><p>Temsilci kaydı bulunamadı. (ID: ' . $rep_id . ')</p>';
        
        // Mevcut temsilcileri listeleyelim - debug için
        $all_reps = $wpdb->get_results("SELECT id, user_id FROM {$table_reps}");
        echo '<p>Mevcut temsilci kayıtları:</p><ul>';
        if ($all_reps) {
            foreach ($all_reps as $rep) {
                echo '<li>ID: ' . $rep->id . ', User ID: ' . $rep->user_id . '</li>';
            }
        } else {
            echo '<li>Hiç temsilci kaydı bulunamadı.</li>';
        }
        echo '</ul></div>';
        
        $editing = false;
    }
}

// Form gönderildiğinde işlem yap
if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
    wp_verify_nonce($_POST['representative_nonce'], 'add_edit_representative')) {
    
    if ($editing && isset($_POST['rep_id'])) {
        // Mevcut temsilciyi güncelle
        $rep_data = array(
            'title' => sanitize_text_field($_POST['title']),
            'phone' => sanitize_text_field($_POST['phone']),
            'department' => sanitize_text_field($_POST['department']),
            'monthly_target' => floatval($_POST['monthly_target']),
            'updated_at' => current_time('mysql')
        );
        
        global $wpdb;
        $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
        
        $update_result = $wpdb->update(
            $table_reps,
            $rep_data,
            array('id' => intval($_POST['rep_id']))
        );
        
        if ($update_result === false) {
            echo '<div class="notice notice-error"><p>Güncelleme sırasında bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
        } else {
            // Kullanıcı bilgilerini güncelle
            if (isset($_POST['first_name']) && isset($_POST['last_name']) && isset($_POST['email']) && isset($edit_rep->user_id)) {
                $user_id = $edit_rep->user_id;
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => sanitize_text_field($_POST['first_name']),
                    'last_name' => sanitize_text_field($_POST['last_name']),
                    'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name']),
                    'user_email' => sanitize_email($_POST['email'])
                ));
            }
            
            // Şifre değiştirme kontrolü
            if (!empty($_POST['password']) && !empty($_POST['confirm_password']) && $_POST['password'] === $_POST['confirm_password'] && isset($edit_rep->user_id)) {
                wp_set_password($_POST['password'], $edit_rep->user_id);
            }
            
            echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
            
            // Şifre değiştirilmişse, güncellemeden sonra sayfayı yenile 
            if (!empty($_POST['password'])) {
                echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives') . '";</script>';
            } else {
                // Düzenleme bitince listeye dön
                echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives&updated=1') . '";</script>';
            }
        }
    } elseif ($adding || !isset($_POST['rep_id'])) {
        // Yeni temsilci oluştur
        if (isset($_POST['username']) && isset($_POST['password']) && isset($_POST['confirm_password'])) {
            $username = sanitize_user($_POST['username']);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($username) || empty($password) || empty($confirm_password)) {
                echo '<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>';
            } else if ($password !== $confirm_password) {
                echo '<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>';
            } else if (username_exists($username)) {
                echo '<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>';
            } else if (email_exists($_POST['email'])) {
                echo '<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>';
            } else {
                // Kullanıcı oluştur
                $user_id = wp_create_user($username, $password, sanitize_email($_POST['email']));
                
                if (!is_wp_error($user_id)) {
                    // Kullanıcı detaylarını güncelle
                    wp_update_user(
                        array(
                            'ID' => $user_id,
                            'first_name' => sanitize_text_field($_POST['first_name']),
                            'last_name' => sanitize_text_field($_POST['last_name']),
                            'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name'])
                        )
                    );
                    
                    // Kullanıcıya rol ata
                    $user = new WP_User($user_id);
                    $user->set_role('insurance_representative');
                    
                    // Müşteri temsilcisi kaydı oluştur
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'insurance_crm_representatives';
                    
                    $insert_result = $wpdb->insert(
                        $table_name,
                        array(
                            'user_id' => $user_id,
                            'title' => sanitize_text_field($_POST['title']),
                            'phone' => sanitize_text_field($_POST['phone']),
                            'department' => sanitize_text_field($_POST['department']),
                            'monthly_target' => floatval($_POST['monthly_target']),
                            'status' => 'active',
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql')
                        )
                    );
                    
                    if ($insert_result !== false) {
                        echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>';
                        
                        // Ekleme işlemi bitince listeye dön
                        echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives&added=1') . '";</script>';
                    } else {
                        echo '<div class="notice notice-error"><p>Temsilci kaydı oluşturulurken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message() . '</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>';
        }
    }
}

// Ekip Ekleme/Düzenleme İşlemleri
if (isset($_POST['submit_team']) && isset($_POST['team_nonce']) && 
    wp_verify_nonce($_POST['team_nonce'], 'add_edit_team')) {
    
    $team_name = sanitize_text_field($_POST['team_name']);
    $team_leader_id = intval($_POST['team_leader_id']);
    $team_members = isset($_POST['team_members']) ? array_map('intval', $_POST['team_members']) : array();
    $team_id = isset($_POST['team_id']) ? sanitize_text_field($_POST['team_id']) : 'team_' . uniqid();
    
    $settings = get_option('insurance_crm_settings', array());
    if (!isset($settings['teams_settings'])) {
        $settings['teams_settings'] = array('teams' => array());
    }
    
    // Ekip bilgilerini kaydet
    $settings['teams_settings']['teams'][$team_id] = array(
        'name' => $team_name,
        'leader_id' => $team_leader_id,
        'members' => $team_members
    );
    
    update_option('insurance_crm_settings', $settings);
    
    echo '<div class="notice notice-success"><p>Ekip bilgileri başarıyla kaydedildi.</p></div>';
}

// Ekip Silme İşlemi
if ($active_tab === 'teams' && isset($_GET['delete_team']) && isset($_GET['_wpnonce'])) {
    $team_id = sanitize_text_field($_GET['delete_team']);
    
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_team_' . $team_id)) {
        $settings = get_option('insurance_crm_settings', array());
        
        if (isset($settings['teams_settings']['teams'][$team_id])) {
            unset($settings['teams_settings']['teams'][$team_id]);
            update_option('insurance_crm_settings', $settings);
            echo '<div class="notice notice-success"><p>Ekip başarıyla silindi.</p></div>';
        }
    }
}

// Silme işlemi
if ($action === 'delete' && $rep_id > 0) {
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_representative_' . $rep_id)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_representatives';
        
        // Önce kullanıcı ID'sini al
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table_name} WHERE id = %d",
            $rep_id
        ));
        
        if ($user_id) {
            // WordPress kullanıcısını sil
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            
            // Kullanıcıyı silme işlemi
            if (wp_delete_user($user_id)) {
                // Temsilci kaydını sil
                $wpdb->delete($table_name, array('id' => $rep_id));
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla silindi.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Kullanıcı silinirken bir hata oluştu.</p></div>';
            }
        } else {
            // Temsilci kaydını sil
            $wpdb->delete($table_name, array('id' => $rep_id));
            echo '<div class="notice notice-success"><p>Müşteri temsilcisi kaydı silindi.</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>Geçersiz silme işlemi.</p></div>';
    }
}

// İşlem mesajları
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
}

if (isset($_GET['added']) && $_GET['added'] === '1') {
    echo '<div class="notice notice-success"><p>Yeni müşteri temsilcisi eklendi.</p></div>';
}

// Mevcut temsilcileri listele
global $wpdb;
$table_name = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM {$table_name} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active' 
     ORDER BY r.created_at DESC"
);

// Ekipleri al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Müşteri Temsilcileri Yönetimi</h1>
    
    <?php if (!$editing && !$adding): ?>
        <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=new'); ?>" class="page-title-action">Yeni Temsilci Ekle</a>
    <?php endif; ?>
    
    <hr class="wp-header-end">
    
    <?php if (!$editing && !$adding): ?>
    <!-- Sekme Menüsü -->
    <h2 class="nav-tab-wrapper">
        <a href="?page=insurance-crm-representatives&tab=representatives" class="nav-tab <?php echo $active_tab === 'representatives' ? 'nav-tab-active' : ''; ?>">Müşteri Temsilcileri</a>
        <a href="?page=insurance-crm-representatives&tab=teams" class="nav-tab <?php echo $active_tab === 'teams' ? 'nav-tab-active' : ''; ?>">Ekipler</a>
    </h2>
    
    <?php if ($active_tab === 'representatives'): ?>
    <!-- TEMSİLCİLER LİSTESİ -->
    <div class="insurance-crm-table-container">
        <table class="wp-list-table widefat fixed striped representatives">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ad Soyad</th>
                    <th>E-posta</th>
                    <th>Ünvan</th>
                    <th>Telefon</th>
                    <th>Departman</th>
                    <th>Aylık Hedef</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($representatives)): ?>
                    <tr>
                        <td colspan="8">Hiç müşteri temsilcisi bulunamadı.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($representatives as $rep): ?>
                    <tr>
                        <td><?php echo esc_html($rep->id); ?></td>
                        <td><?php echo esc_html($rep->display_name); ?></td>
                        <td><?php echo esc_html($rep->email); ?></td>
                        <td><?php echo esc_html($rep->title); ?></td>
                        <td><?php echo esc_html($rep->phone); ?></td>
                        <td><?php echo esc_html($rep->department); ?></td>
                        <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=insurance-crm-representatives&action=edit&id=' . $rep->id)); ?>" 
                               class="button button-small">
                                Düzenle
                            </a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=insurance-crm-representatives&action=delete&id=' . $rep->id), 'delete_representative_' . $rep->id)); ?>" 
                               class="button button-small delete-representative" 
                               onclick="return confirm('Bu müşteri temsilcisini silmek istediğinizden emin misiniz?');">
                                Sil
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php elseif ($active_tab === 'teams'): ?>
    <!-- EKİPLER SEKMESİ İÇERİĞİ -->
    <div class="insurance-crm-table-container teams-container">
        <div class="teams-header">
            <h3>Ekip Yönetimi</h3>
            <p class="description">Müşteri Temsilcilerini ekiplere ayırın ve ekip liderlerini belirleyin.</p>
            <a href="?page=insurance-crm-representatives&tab=teams&action=new_team" class="button button-primary">Yeni Ekip Oluştur</a>
        </div>

        <?php if (isset($_GET['action']) && $_GET['action'] === 'new_team' || isset($_GET['action']) && $_GET['action'] === 'edit_team'): ?>
            <?php
            $edit_team = null;
            $edit_team_id = '';
            
            if (isset($_GET['action']) && $_GET['action'] === 'edit_team' && isset($_GET['team_id'])) {
                $edit_team_id = sanitize_text_field($_GET['team_id']);
                if (isset($teams[$edit_team_id])) {
                    $edit_team = $teams[$edit_team_id];
                }
            }
            ?>
            <!-- Ekip Ekleme/Düzenleme Formu -->
            <div class="team-form-container">
                <h3><?php echo $edit_team ? 'Ekibi Düzenle' : 'Yeni Ekip Oluştur'; ?></h3>
                <form method="post" action="?page=insurance-crm-representatives&tab=teams">
                    <?php wp_nonce_field('add_edit_team', 'team_nonce'); ?>
                    
                    <?php if ($edit_team): ?>
                        <input type="hidden" name="team_id" value="<?php echo esc_attr($edit_team_id); ?>">
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="team_name">Ekip Adı <span class="required">*</span></label></th>
                            <td>
                                <input type="text" name="team_name" id="team_name" class="regular-text" required
                                       value="<?php echo $edit_team ? esc_attr($edit_team['name']) : ''; ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team_leader_id">Ekip Lideri <span class="required">*</span></label></th>
                            <td>
                                <select name="team_leader_id" id="team_leader_id" class="regular-text" required>
                                    <option value="">-- Ekip Lideri Seçin --</option>
                                    <?php foreach ($representatives as $rep): ?>
                                        <option value="<?php echo esc_attr($rep->id); ?>" 
                                                <?php selected($edit_team && $edit_team['leader_id'] == $rep->id); ?>>
                                            <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team_members">Ekip Üyeleri</label></th>
                            <td>
                                <div class="team-members-selection">
                                    <?php 
                                    $selected_members = $edit_team ? $edit_team['members'] : array();
                                    foreach ($representatives as $rep):
                                        // Lider aynı zamanda üye olamaz
                                        if ($edit_team && $edit_team['leader_id'] == $rep->id) continue;
                                    ?>
                                        <label class="team-member-checkbox">
                                            <input type="checkbox" name="team_members[]" value="<?php echo esc_attr($rep->id); ?>"
                                                   <?php checked(in_array($rep->id, $selected_members)); ?>>
                                            <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">Bu ekibe dahil olacak müşteri temsilcilerini seçin.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit_team" class="button button-primary" 
                               value="<?php echo $edit_team ? 'Ekibi Güncelle' : 'Ekip Oluştur'; ?>">
                        <a href="?page=insurance-crm-representatives&tab=teams" class="button">İptal</a>
                    </p>
                </form>
            </div>
        <?php else: ?>
            <!-- Ekipler Listesi -->
            <?php if (empty($teams)): ?>
                <div class="notice notice-info">
                    <p>Henüz hiç ekip oluşturulmamış. İlk ekibinizi oluşturmak için "Yeni Ekip Oluştur" butonunu kullanın.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped teams">
                    <thead>
                        <tr>
                            <th>Ekip Adı</th>
                            <th>Ekip Lideri</th>
                            <th>Üye Sayısı</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team_id => $team): 
                            // Ekip lideri bilgilerini al
                            $leader_info = '---';
                            foreach ($representatives as $rep) {
                                if ($rep->id == $team['leader_id']) {
                                    $leader_info = $rep->display_name . ' (' . $rep->title . ')';
                                    break;
                                }
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($leader_info); ?></td>
                                <td><?php echo count($team['members']); ?> Üye</td>
                                <td>
                                    <a href="?page=insurance-crm-representatives&tab=teams&action=edit_team&team_id=<?php echo esc_attr($team_id); ?>" 
                                       class="button button-small">
                                        Düzenle
                                    </a>
                                    <a href="<?php echo esc_url(wp_nonce_url('?page=insurance-crm-representatives&tab=teams&delete_team=' . $team_id, 'delete_team_' . $team_id)); ?>" 
                                       class="button button-small" 
                                       onclick="return confirm('Bu ekibi silmek istediğinizden emin misiniz?');">
                                        Sil
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <!-- TEMSİLCİ DÜZENLEME / EKLEME FORMU -->
    <div class="insurance-crm-form-container">
        <h2><?php echo $editing ? 'Müşteri Temsilcisini Düzenle' : 'Yeni Müşteri Temsilcisi Ekle'; ?></h2>
        <form method="post" action="" class="insurance-crm-form">
            <?php wp_nonce_field('add_edit_representative', 'representative_nonce'); ?>
            
            <?php if ($editing): ?>
                <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">
            <?php endif; ?>
            
            <div class="insurance-crm-form-section">
                <h3>Kullanıcı Bilgileri</h3>
                
                <table class="form-table">
                    <?php if (!$editing): ?>
                        <tr>
                            <th><label for="username">Kullanıcı Adı <span class="required">*</span></label></th>
                            <td><input type="text" name="username" id="username" class="regular-text" required></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>Kullanıcı Adı</th>
                            <td><strong><?php echo esc_html($edit_rep->username); ?></strong> (Kullanıcı adı değiştirilemez)</td>
                        </tr>
                    <?php endif; ?>
                        
                    <tr>
                        <th><label for="password">Şifre <?php echo $editing ? '' : '<span class="required">*</span>'; ?></label></th>
                        <td>
                            <input type="password" name="password" id="password" class="regular-text" <?php echo !$editing ? 'required' : ''; ?>>
                            <?php if ($editing): ?>
                                <p class="description">Şifreyi değiştirmek için yeni şifre girin veya mevcut şifreyi korumak için boş bırakın.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="confirm_password">Şifre (Tekrar) <?php echo $editing ? '' : '<span class="required">*</span>'; ?></label></th>
                        <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" <?php echo !$editing ? 'required' : ''; ?>></td>
                    </tr>
                    <tr>
                        <th><label for="first_name">Ad <span class="required">*</span></label></th>
                        <td>
                            <input type="text" name="first_name" id="first_name" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->first_name) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="last_name">Soyad <span class="required">*</span></label></th>
                        <td>
                            <input type="text" name="last_name" id="last_name" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->last_name) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email">E-posta <span class="required">*</span></label></th>
                        <td>
                            <input type="email" name="email" id="email" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->email) : ''; ?>">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="insurance-crm-form-section">
                <h3>Temsilci Bilgileri</h3>
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Ünvan <span class="required">*</span></label></th>
                        <td>
                            <input type="text" name="title" id="title" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->title) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="phone">Telefon <span class="required">*</span></label></th>
                        <td>
                            <input type="tel" name="phone" id="phone" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->phone) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="department">Departman</label></th>
                        <td>
                            <input type="text" name="department" id="department" class="regular-text"
                                   value="<?php echo $editing ? esc_attr($edit_rep->department) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="monthly_target">Aylık Hedef (₺) <span class="required">*</span></label></th>
                        <td>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->monthly_target) : ''; ?>">
                            <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit_representative" class="button button-primary" 
                       value="<?php echo $editing ? 'Temsilciyi Güncelle' : 'Müşteri Temsilcisi Ekle'; ?>">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives'); ?>" class="button">İptal</a>
            </p>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
.insurance-crm-form-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-top: 20px;
}
.insurance-crm-form-section {
    margin-bottom: 20px;
}
.insurance-crm-form-section h3 {
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    margin-bottom: 15px;
}
.insurance-crm-table-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.required {
    color: #dc3232;
}

/* Teams Tab Styles */
.teams-container .teams-header {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.teams-container .teams-header h3 {
    margin-bottom: 5px;
}

.teams-container .teams-header .description {
    margin-bottom: 15px;
}

.team-form-container {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    margin-bottom: 20px;
}

.team-members-selection {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
}

.team-member-checkbox {
    display: block;
    margin-bottom: 5px;
}

.team-member-checkbox input {
    margin-right: 5px;
}

table.teams th:first-child,
table.teams td:first-child {
    width: 30%;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Şifre eşleşme kontrolü
    $('#confirm_password').on('blur', function() {
        var password = $('#password').val();
        var confirmPassword = $(this).val();
        
        if (password && confirmPassword && password !== confirmPassword) {
            alert('Şifreler eşleşmiyor!');
            $(this).val('').focus();
        }
    });
    
    // Form gönderildiğinde doğrulama
    $('form.insurance-crm-form').on('submit', function(e) {
        var password = $('#password').val();
        var confirmPassword = $('#confirm_password').val();
        
        if (password && password !== confirmPassword) {
            e.preventDefault();
            alert('Şifreler eşleşmiyor!');
            $('#confirm_password').focus();
            return false;
        }
        
        // Diğer doğrulamalar...
        return true;
    });

    // Ekip Lideri seçildiğinde, o kişiyi üyelerden çıkar
    $('#team_leader_id').on('change', function() {
        var leaderId = $(this).val();
        
        // Tüm checkboxları aktifleştir
        $('.team-member-checkbox input').prop('disabled', false);
        
        if (leaderId) {
            // Lideri üye listesinden çıkar
            $('.team-member-checkbox input[value="' + leaderId + '"]')
                .prop('checked', false)
                .prop('disabled', true);
        }
    });

    // Sayfa yüklendiğinde lideri kontrol et
    if ($('#team_leader_id').length) {
        $('#team_leader_id').trigger('change');
    }
});
</script>