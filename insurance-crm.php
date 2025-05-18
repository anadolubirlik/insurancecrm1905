<?php
/**
 * Insurance CRM
 *
 * @package     Insurance_CRM
 * @author      Anadolu Birlik
 * @copyright   2025 Anadolu Birlik
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: Insurance CRM
 * Plugin URI:  https://github.com/anadolubirlik/insurance-crm
 * Description: Sigorta acenteleri için müşteri ve poliçe yönetim sistemi.
 * Version:     1.1.3
 * Author:      Anadolu Birlik
 * Author URI:  https://github.com/anadolubirlik
 * Text Domain: insurance-crm
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Plugin version.
 */
define('INSURANCE_CRM_VERSION', '1.1.3');

/**
 * Plugin base path
 */
define('INSURANCE_CRM_PATH', plugin_dir_path(__FILE__));

/**
 * Plugin URL
 */
define('INSURANCE_CRM_URL', plugin_dir_url(__FILE__));

/**
 * Plugin activation.
 */
function activate_insurance_crm() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-insurance-crm-activator.php';
    Insurance_CRM_Activator::activate();
    
    add_option('insurance_crm_activation_time', time());
    update_option('insurance_crm_menu_initialized', 'no');
    update_option('insurance_crm_menu_cache_cleared', 'no');
}

/**
 * Plugin deactivation.
 */
function deactivate_insurance_crm() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-insurance-crm-deactivator.php';
    Insurance_CRM_Deactivator::deactivate();
    
    delete_option('insurance_crm_menu_initialized');
    delete_option('insurance_crm_menu_cache_cleared');
}

register_activation_hook(__FILE__, 'activate_insurance_crm');
register_deactivation_hook(__FILE__, 'deactivate_insurance_crm');

/**
 * The core plugin class
 */
require plugin_dir_path(__FILE__) . 'includes/class-insurance-crm.php';

/**
 * Begins execution of the plugin.
 */
function run_insurance_crm() {
    $plugin = new Insurance_CRM();
    $plugin->run();
}

run_insurance_crm();

/**
 * Custom capabilities for the plugin
 */
function insurance_crm_add_capabilities() {
    $roles = array('administrator', 'editor');
    $capabilities = array(
        'read_insurance_crm',
        'edit_insurance_crm',
        'edit_others_insurance_crm',
        'publish_insurance_crm',
        'read_private_insurance_crm',
        'manage_insurance_crm'
    );

    foreach ($roles as $role) {
        $role_obj = get_role($role);
        if (!empty($role_obj)) {
            foreach ($capabilities as $cap) {
                $role_obj->add_cap($cap);
    }
        }
    }
}
register_activation_hook(__FILE__, 'insurance_crm_add_capabilities');

/**
 * Database tables creation
 */
function insurance_crm_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_customers = $wpdb->prefix . 'insurance_crm_customers';
    $sql_customers = "CREATE TABLE IF NOT EXISTS $table_customers (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        tc_identity varchar(11) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        address text,
        category varchar(20) DEFAULT 'bireysel',
        status varchar(20) DEFAULT 'aktif',
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY tc_identity (tc_identity),
        KEY email (email),
        KEY status (status),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_policies = $wpdb->prefix . 'insurance_crm_policies';
    $sql_policies = "CREATE TABLE IF NOT EXISTS $table_policies (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) NOT NULL,
        policy_number varchar(50) NOT NULL,
        policy_type varchar(50) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        premium_amount decimal(10,2) NOT NULL,
        status varchar(20) DEFAULT 'aktif',
        document_path varchar(255),
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY policy_number (policy_number),
        KEY customer_id (customer_id),
        KEY status (status),
        KEY end_date (end_date),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_tasks = $wpdb->prefix . 'insurance_crm_tasks';
    $sql_tasks = "CREATE TABLE IF NOT EXISTS $table_tasks (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) NOT NULL,
        policy_id bigint(20),
        task_description text NOT NULL,
        due_date datetime NOT NULL,
        priority varchar(20) DEFAULT 'medium',
        status varchar(20) DEFAULT 'pending',
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY customer_id (customer_id),
        KEY policy_id (policy_id),
        KEY status (status),
        KEY due_date (due_date),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_representatives = $wpdb->prefix . 'insurance_crm_representatives';
    $sql_representatives = "CREATE TABLE IF NOT EXISTS $table_representatives (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        title varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        department varchar(100) NOT NULL,
        monthly_target decimal(10,2) DEFAULT 0.00,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_customers);
    dbDelta($sql_policies);
    dbDelta($sql_tasks);
    dbDelta($sql_representatives);

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD KEY IF NOT EXISTS representative_id (representative_id)");

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_policies ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_policies ADD KEY IF NOT EXISTS representative_id (representative_id)");

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_tasks ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_tasks ADD KEY IF NOT EXISTS representative_id (representative_id)");
}
register_activation_hook(__FILE__, 'insurance_crm_create_tables');

/**
 * Otomatik yenileme hatırlatmaları için cron job
 */
function insurance_crm_schedule_cron_job() {
    if (!wp_next_scheduled('insurance_crm_daily_cron')) {
        wp_schedule_event(time(), 'daily', 'insurance_crm_daily_cron');
    }
}
register_activation_hook(__FILE__, 'insurance_crm_schedule_cron_job');

/**
 * Cron job kaldırma
 */
function insurance_crm_unschedule_cron_job() {
    wp_clear_scheduled_hook('insurance_crm_daily_cron');
}
register_deactivation_hook(__FILE__, 'insurance_crm_unschedule_cron_job');

/**
 * Veritabanı kurulumu için tablo kontrolü
 */
function insurance_crm_check_db_tables() {
    global $wpdb;
    
    $tables = array(
        'insurance_crm_customers',
        'insurance_crm_policies',
        'insurance_crm_tasks',
        'insurance_crm_representatives'
    );
    
    $missing_tables = array();
    
    foreach ($tables as $table) {
        $table_name = $wpdb->prefix . $table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $missing_tables[] = $table;
        }
    }
    
    if (!empty($missing_tables)) {
        insurance_crm_create_tables();
    }
}
add_action('plugins_loaded', 'insurance_crm_check_db_tables');

/**
 * Günlük kontroller ve bildirimler
 */
function insurance_crm_daily_tasks() {
    global $wpdb;
    $settings = get_option('insurance_crm_settings');
    $renewal_days = isset($settings['renewal_reminder_days']) ? intval($settings['renewal_reminder_days']) : 30;
    $task_days = isset($settings['task_reminder_days']) ? intval($settings['task_reminder_days']) : 1;

    $upcoming_renewals = $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, c.first_name, c.last_name, c.email 
         FROM {$wpdb->prefix}insurance_crm_policies p
         JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
         WHERE p.status = 'aktif' 
         AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %d DAY)",
        $renewal_days
    ));

    $upcoming_tasks = $wpdb->get_results($wpdb->prepare(
        "SELECT t.*, c.first_name, c.last_name, c.email 
         FROM {$wpdb->prefix}insurance_crm_tasks t
         JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
         WHERE t.status = 'pending'
         AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
        $task_days
    ));

    if (!empty($upcoming_renewals) || !empty($upcoming_tasks)) {
        $notification_email = isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');

        $message = "Merhaba,\n\n";
        $message .= "Bu e-posta $company_name sisteminden otomatik olarak gönderilmiştir.\n\n";

        if (!empty($upcoming_renewals)) {
            $message .= "YAKLAŞAN POLİÇE YENİLEMELERİ:\n";
            $message .= "--------------------------------\n";
            foreach ($upcoming_renewals as $renewal) {
                $days_left = (strtotime($renewal->end_date) - time()) / (60 * 60 * 24);
                $message .= sprintf(
                    "%s %s - Poliçe No: %s - Bitiş Tarihi: %s (%d gün kaldı)\n",
                    $renewal->first_name,
                    $renewal->last_name,
                    $renewal->policy_number,
                    date('d.m.Y', strtotime($renewal->end_date)),
                    ceil($days_left)
                );
            }
            $message .= "\n";
        }

        if (!empty($upcoming_tasks)) {
            $message .= "YAKLAŞAN GÖREVLER:\n";
            $message .= "--------------------------------\n";
            foreach ($upcoming_tasks as $task) {
                $days_left = (strtotime($task->due_date) - time()) / (60 * 60 * 24);
                $message .= sprintf(
                    "%s %s - %s - Tarih: %s (%d gün kaldı)\n",
                    $task->first_name,
                    $task->last_name,
                    $task->task_description,
                    date('d.m.Y H:i', strtotime($task->due_date)),
                    ceil($days_left)
                );
            }
        }

        wp_mail(
            $notification_email,
            'Insurance CRM - Günlük Hatırlatmalar',
            $message,
            array('Content-Type: text/plain; charset=UTF-8')
        );
    }
}
add_action('insurance_crm_daily_cron', 'insurance_crm_daily_tasks');

/**
 * Müşteri Temsilcisi rolü ve yetkileri
 */
function insurance_crm_add_representative_role() {
    add_role('insurance_representative', 'Müşteri Temsilcisi', array(
        'read' => true,
        'upload_files' => true,
        'read_insurance_crm' => true,
        'edit_insurance_crm' => true,
        'publish_insurance_crm' => true
    ));
}
register_activation_hook(__FILE__, 'insurance_crm_add_representative_role');

/**
 * Yeni müşteri temsilcisi oluşturulduğunda otomatik kullanıcı oluştur
 */
function insurance_crm_create_representative_user($rep_id, $data) {
    $username = sanitize_user(strtolower($data['first_name'] . '.' . $data['last_name']));
    
    $original_username = $username;
    $count = 1;
    while (username_exists($username)) {
        $username = $original_username . $count;
        $count++;
    }

    $password = wp_generate_password();
    
    $user_data = array(
        'user_login' => $username,
        'user_pass' => $password,
        'user_email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'role' => 'insurance_representative'
    );

    $user_id = wp_insert_user($user_data);

    if (!is_wp_error($user_id)) {
        update_user_meta($user_id, '_insurance_representative_id', $rep_id);
        wp_send_new_user_notifications($user_id, 'both');
        return $user_id;
    }

    return false;
}

/**
 * Admin initialize
 */
function insurance_crm_admin_init() {
    if (!class_exists('Insurance_CRM_Admin')) {
        require_once plugin_dir_path(__FILE__) . 'admin/class-insurance-crm-admin.php';
    }
    
    $admin_dir = plugin_dir_path(__FILE__) . 'admin/';
    if (!file_exists($admin_dir)) {
        mkdir($admin_dir, 0755, true);
    }
    
    $users_page_path = $admin_dir . 'users-page.php';
    if (!file_exists($users_page_path)) {
        $users_page_content = '<?php
if (!defined("ABSPATH")) {
    exit;
}

function insurance_crm_users_page() {
    echo "<div class=\"wrap\">";
    echo "<h1>Kullanıcı Yönetimi</h1>";
    echo "</div>";
}';
        file_put_contents($users_page_path, $users_page_content);
    }
}
add_action('admin_init', 'insurance_crm_admin_init');

/**
 * Get total premium amount
 */
function insurance_crm_get_total_premium($customer_id = null) {
    global $wpdb;
    
    $where = '';
    $args = array();
    
    if ($customer_id) {
        $where = 'WHERE customer_id = %d';
        $args[] = $customer_id;
    }
    
    $query = $wpdb->prepare(
        "SELECT SUM(premium_amount) as total_premium 
         FROM {$wpdb->prefix}insurance_crm_policies 
         $where",
        $args
    );
    
    return $wpdb->get_var($query);
}

/**
 * Plugin'i etkinleştirirken örnek veri ekle
 */
function insurance_crm_add_sample_data() {
    if (get_option('insurance_crm_sample_data_added')) {
        return;
    }
    
    if (!get_role('insurance_representative')) {
        add_role('insurance_representative', 'Müşteri Temsilcisi', array(
            'read' => true,
            'upload_files' => true,
            'read_insurance_crm' => true,
            'edit_insurance_crm' => true,
            'publish_insurance_crm' => true
        ));
    }
    
    $username = 'temsilci';
    if (!username_exists($username) && !email_exists('temsilci@example.com')) {
        $user_id = wp_create_user(
            $username,
            'temsilci123',
            'temsilci@example.com'
        );
        
        if (!is_wp_error($user_id)) {
            $user = new WP_User($user_id);
            $user->set_role('insurance_representative');
            
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'insurance_crm_representatives',
                array(
                    'user_id' => $user_id,
                    'title' => 'Kıdemli Müşteri Temsilcisi',
                    'phone' => '5551234567',
                    'department' => 'Bireysel Satış',
                    'monthly_target' => 50000.00,
                    'status' => 'active'
                )
            );
        }
    }
    
    update_option('insurance_crm_sample_data_added', true);
}
register_activation_hook(__FILE__, 'insurance_crm_add_sample_data');

/**
 * Plugin güncelleme kontrolü ve işlemleri
 */
function insurance_crm_update_check() {
    $current_version = get_option('insurance_crm_version');
    
    if ($current_version !== INSURANCE_CRM_VERSION) {
        delete_option('insurance_crm_menu_initialized');
        delete_option('insurance_crm_menu_cache_cleared');
        update_option('insurance_crm_version', INSURANCE_CRM_VERSION);
        
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%menu%'");
        
        insurance_crm_create_required_files();
    }
}
add_action('plugins_loaded', 'insurance_crm_update_check');

/**
 * 1.0.3 versiyonu için gerekli dosyaları oluştur
 */
function insurance_crm_create_required_files() {
    $admin_dir = INSURANCE_CRM_PATH . 'admin/';
    $partials_dir = $admin_dir . 'partials/';
    
    if (!file_exists($admin_dir)) {
        mkdir($admin_dir, 0755, true);
    }
    
    if (!file_exists($partials_dir)) {
        mkdir($partials_dir, 0755, true);
    }
    
    $representatives_content = '<?php
/**
 * Müşteri Temsilcileri Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 */

if (!defined("WPINC")) {
    die;
}

$rep_id = isset($_GET["edit"]) ? intval($_GET["edit"]) : 0;
$editing = ($rep_id > 0);
$edit_rep = null;

if ($editing) {
    global $wpdb;
    $table_reps = $wpdb->prefix . "insurance_crm_representatives";
    $table_users = $wpdb->users;
    
    $edit_rep = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, u.user_email as email, u.display_name, u.user_login as username,
                u.first_name, u.last_name
         FROM $table_reps r 
         LEFT JOIN $table_users u ON r.user_id = u.ID 
         WHERE r.id = %d",
        $rep_id
    ));
    
    if (!$edit_rep) {
        $editing = false;
    }
}

if (isset($_POST["submit_representative"]) && isset($_POST["representative_nonce"]) && 
    wp_verify_nonce($_POST["representative_nonce"], "add_edit_representative")) {
    
    if ($editing) {
        $rep_data = array(
            "title" => sanitize_text_field($_POST["title"]),
            "phone" => sanitize_text_field($_POST["phone"]),
            "department" => sanitize_text_field($_POST["department"]),
            "monthly_target" => floatval($_POST["monthly_target"]),
            "updated_at" => current_time("mysql")
        );
        
        global $wpdb;
        $table_reps = $wpdb->prefix . "insurance_crm_representatives";
        
        $wpdb->update(
            $table_reps,
            $rep_data,
            array("id" => $rep_id)
        );
        
        if (isset($_POST["first_name"]) && isset($_POST["last_name"]) && isset($_POST["email"])) {
            $user_id = $edit_rep->user_id;
            wp_update_user(array(
                "ID" => $user_id,
                "first_name" => sanitize_text_field($_POST["first_name"]),
                "last_name" => sanitize_text_field($_POST["last_name"]),
                "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"]),
                "user_email" => sanitize_email($_POST["email"])
            ));
        }
        
        if (!empty($_POST["password"]) && !empty($_POST["confirm_password"]) && $_POST["password"] == $_POST["confirm_password"]) {
            wp_set_password($_POST["password"], $edit_rep->user_id);
        }
        
        echo \'<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>\';
        
        echo \'<script>window.location.href = "\' . admin_url("admin.php?page=insurance-crm-representatives") . \'";</script>\';
    } else {
        if (isset($_POST["username"]) && isset($_POST["password"]) && isset($_POST["confirm_password"])) {
            $username = sanitize_user($_POST["username"]);
            $password = $_POST["password"];
            $confirm_password = $_POST["confirm_password"];
            
            if (empty($username) || empty($password) || empty($confirm_password)) {
                echo \'<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>\';
            } else if ($password !== $confirm_password) {
                echo \'<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>\';
            } else if (username_exists($username)) {
                echo \'<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>\';
            } else if (email_exists($_POST["email"])) {
                echo \'<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>\';
            } else {
                $user_id = wp_create_user($username, $password, sanitize_email($_POST["email"]));
                
                if (!is_wp_error($user_id)) {
                    wp_update_user(
                        array(
                            "ID" => $user_id,
                            "first_name" => sanitize_text_field($_POST["first_name"]),
                            "last_name" => sanitize_text_field($_POST["last_name"]),
                            "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"])
                        )
                    );
                    
                    $user = new WP_User($user_id);
                    $user->set_role("insurance_representative");
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . "insurance_crm_representatives";
                    
                    $wpdb->insert(
                        $table_name,
                        array(
                            "user_id" => $user_id,
                            "title" => sanitize_text_field($_POST["title"]),
                            "phone" => sanitize_text_field($_POST["phone"]),
                            "department" => sanitize_text_field($_POST["department"]),
                            "monthly_target" => floatval($_POST["monthly_target"]),
                            "status" => "active",
                            "created_at" => current_time("mysql"),
                            "updated_at" => current_time("mysql")
                        )
                    );
                    
                    echo \'<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>\';
                } else {
                    echo \'<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: \' . $user_id->get_error_message() . \'</p></div>\';
                }
            }
        } else {
            echo \'<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>\';
        }
    }
}

global $wpdb;
$table_name = $wpdb->prefix . "insurance_crm_representatives";
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM $table_name r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = \'active\' 
     ORDER BY r.created_at DESC"
);
?>

<div class="wrap">
    <h1>Müşteri Temsilcileri</h1>
    
    <h2>Mevcut Müşteri Temsilcileri</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
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
            <?php foreach ($representatives as $rep): ?>
            <tr>
                <td><?php echo esc_html($rep->display_name); ?></td>
                <td><?php echo esc_html($rep->email); ?></td>
                <td><?php echo esc_html($rep->title); ?></td>
                <td><?php echo esc_html($rep->phone); ?></td>
                <td><?php echo esc_html($rep->department); ?></td>
                <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                <td>
                    <a href="<?php echo admin_url(\'admin.php?page=insurance-crm-representatives&edit=\' . $rep->id); ?>" 
                       class="button button-small">
                        Düzenle
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url(\'admin.php?page=insurance-crm-representatives&action=delete&id=\' . $rep->id), \'delete_representative_\' . $rep->id); ?>" 
                       class="button button-small" 
                       onclick="return confirm(\'Bu müşteri temsilcisini silmek istediğinizden emin misiniz?\');">
                        Sil
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <hr>
    
    <?php if ($editing): ?>
        <h2>Müşteri Temsilcisini Düzenle</h2>
    <?php else: ?>
        <h2>Yeni Müşteri Temsilcisi Ekle</h2>
    <?php endif; ?>
    
    <form method="post" action="">
        <?php wp_nonce_field("add_edit_representative", "representative_nonce"); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">
        <?php endif; ?>
        
        <table class="form-table">
            <?php if (!$editing): ?>
                <tr>
                    <th><label for="username">Kullanıcı Adı</label></th>
                    <td><input type="text" name="username" id="username" class="regular-text" required></td>
                </tr>
            <?php endif; ?>
                
            <tr>
                <th><label for="password">Şifre</label></th>
                <td>
                    <input type="password" name="password" id="password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>>
                    <p class="description">
                        <?php echo $editing ? "Değiştirmek istemiyorsanız boş bırakın." : "En az 8 karakter uzunluğunda olmalıdır."; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="confirm_password">Şifre (Tekrar)</label></th>
                <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>></td>
            </tr>
            <tr>
                <th><label for="first_name">Ad</label></th>
                <td>
                    <input type="text" name="first_name" id="first_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->first_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="last_name">Soyad</label></th>
                <td>
                    <input type="text" name="last_name" id="last_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->last_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="email">E-posta</label></th>
                <td>
                    <input type="email" name="email" id="email" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->email) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="title">Ünvan</label></th>
                <td>
                    <input type="text" name="title" id="title" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->title) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="phone">Telefon</label></th>
                <td>
                    <input type="tel" name="phone" id="phone" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->phone) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="department">Departman</label></th>
                <td>
                    <input type="text" name="department" id="department" class="regular-text"
                           value="<?php echo $editing ? esc_attr($edit_rep->department) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="monthly_target">Aylık Hedef (₺)</label></th>
                <td>
                    <input type="number" step="0.01" name="monthly_target" id="monthly_target" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->monthly_target) : ""; ?>">
                    <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="submit_representative" class="button button-primary" 
                   value="<?php echo $editing ? "Temsilciyi Güncelle" : "Müşteri Temsilcisi Ekle"; ?>">
            <?php if ($editing): ?>
                <a href="<?php echo admin_url("admin.php?page=insurance-crm-representatives"); ?>" class="button">İptal</a>
            <?php endif; ?>
        </p>
    </form>
</div>';
    
    file_put_contents($partials_dir . 'insurance-crm-representatives.php', $representatives_content);
    
    $templates_dir = INSURANCE_CRM_PATH . 'templates/';
    if (!file_exists($templates_dir)) {
        mkdir($templates_dir, 0755, true);
    }
}

/**
 * Admin menüleri ekle
 */
function insurance_crm_admin_menu() {
    static $menu_initialized = false;
    
    if ($menu_initialized) {
        return;
    }
    
    $menu_initialized = true;
    
    add_menu_page(
        'Insurance CRM',
        'Insurance CRM',
        'manage_options',
        'insurance-crm',
        'insurance_crm_dashboard',
        'dashicons-businessman',
        30
    );

    add_submenu_page(
        'insurance-crm',
        'Gösterge Paneli',
        'Gösterge Paneli',
        'manage_options',
        'insurance-crm',
        'insurance_crm_dashboard'
    );

    add_submenu_page(
        'insurance-crm',
        'Müşteriler',
        'Müşteriler',
        'manage_options',
        'insurance-crm-customers',
        'insurance_crm_customers'
    );

    add_submenu_page(
        'insurance-crm',
        'Müşteri Temsilcileri',
        'Müşteri Temsilcileri',
        'manage_options',
        'insurance-crm-representatives',
        'insurance_crm_display_representatives_page'
    );

    update_option('insurance_crm_menu_initialized', 'yes');
}
add_action('admin_menu', 'insurance_crm_admin_menu');

/**
 * Tekrarlanan menüleri temizle
 */
function insurance_crm_remove_duplicate_menus() {
    global $submenu, $menu;
    
    if (isset($submenu) && isset($menu)) {
        $insurance_crm_menu_positions = array();
        
        foreach ($menu as $position => $menu_item) {
            if (isset($menu_item[2]) && $menu_item[2] === 'insurance-crm') {
                $insurance_crm_menu_positions[] = $position;
            }
        }
        
        if (count($insurance_crm_menu_positions) > 1) {
            $first_position = array_shift($insurance_crm_menu_positions);
            foreach ($insurance_crm_menu_positions as $position) {
                unset($menu[$position]);
            }
        }
        
        if (isset($submenu['insurance-crm'])) {
            $seen_items = array();
            $new_submenu = array();
            
            foreach ($submenu['insurance-crm'] as $position => $item) {
                $menu_slug = $item[2];
                
                if (!isset($seen_items[$menu_slug])) {
                    $seen_items[$menu_slug] = true;
                    $new_submenu[$position] = $item;
                }
            }
            
            if (!empty($new_submenu)) {
                $submenu['insurance-crm'] = $new_submenu;
            }
        }
    }
}
add_action('admin_menu', 'insurance_crm_remove_duplicate_menus', 9999);

/**
 * Admin sayfaları için callback fonksiyonları
 */
function insurance_crm_dashboard() {
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-dashboard.php';
}

/**
 * Müşteriler sayfası
 */
function insurance_crm_customers() {
    insurance_crm_check_customer_db_updates();
    
    if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php';
            return;
        }
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'new') {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-customers.php';
}

/**
 * Müşteri veritabanı tablo güncellemelerini kontrol et
 */
function insurance_crm_check_customer_db_updates() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    $columns_to_add = array(
        'birth_date' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS birth_date DATE DEFAULT NULL",
        'gender' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS gender VARCHAR(10) DEFAULT NULL",
        'is_pregnant' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS is_pregnant TINYINT(1) DEFAULT 0",
        'pregnancy_week' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pregnancy_week INT DEFAULT NULL",
        'occupation' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS occupation VARCHAR(100) DEFAULT NULL",
        'spouse_name' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS spouse_name VARCHAR(100) DEFAULT NULL",
        'spouse_birth_date' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS spouse_birth_date DATE DEFAULT NULL",
        'children_count' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_count INT DEFAULT 0",
        'children_names' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_names TEXT DEFAULT NULL",
        'children_birth_dates' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_birth_dates TEXT DEFAULT NULL",
        'has_vehicle' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_vehicle TINYINT(1) DEFAULT 0",
        'vehicle_plate' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(20) DEFAULT NULL",
        'has_pet' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_pet TINYINT(1) DEFAULT 0",
        'pet_name' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_name VARCHAR(50) DEFAULT NULL",
        'pet_type' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_type VARCHAR(50) DEFAULT NULL",
        'pet_age' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_age VARCHAR(20) DEFAULT NULL",
        'owns_home' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS owns_home TINYINT(1) DEFAULT 0",
        'has_daskel_policy' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_dask_policy TINYINT(1) DEFAULT 0",
        'dask_policy_expiry' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS dask_policy_expiry DATE DEFAULT NULL",
        'has_home_policy' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_home_policy TINYINT(1) DEFAULT 0",
        'home_policy_expiry' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS home_policy_expiry DATE DEFAULT NULL"
    );
    
    foreach ($columns_to_add as $column => $query) {
        $wpdb->query($query);
    }
    
    $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
    
    $sql = "CREATE TABLE IF NOT EXISTS $notes_table (
        id INT NOT NULL AUTO_INCREMENT,
        customer_id INT NOT NULL,
        note_content TEXT NOT NULL,
        note_type VARCHAR(20) NOT NULL,
        rejection_reason VARCHAR(50) DEFAULT NULL,
        created_by BIGINT(20) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY customer_id (customer_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    update_option('insurance_crm_customer_db_updated', 'yes');
}

/**
 * Admin script ve stilleri ekle
 */
function insurance_crm_admin_scripts() {
    wp_enqueue_style('insurance-crm-admin-style', INSURANCE_CRM_URL . 'admin/css/insurance-crm-admin.css', array(), INSURANCE_CRM_VERSION, 'all');
    wp_enqueue_script('insurance-crm-admin-script', INSURANCE_CRM_URL . 'admin/js/insurance-crm-admin.js', array('jquery'), INSURANCE_CRM_VERSION, false);
}
add_action('admin_enqueue_scripts', 'insurance_crm_admin_scripts');

/**
 * Public script ve stilleri ekle
 */
function insurance_crm_public_scripts() {
    wp_enqueue_style('insurance-crm-public-style', INSURANCE_CRM_URL . 'public/css/insurance-crm-public.css', array(), INSURANCE_CRM_VERSION, 'all');
    wp_enqueue_script('insurance-crm-public-script', INSURANCE_CRM_URL . 'public/js/insurance-crm-public.js', array('jquery'), INSURANCE_CRM_VERSION, false);
    
    wp_enqueue_style('dashicons');
}
add_action('wp_enqueue_scripts', 'insurance_crm_public_scripts');

/**
 * AJAX işlemleri için hooks
 */
function insurance_crm_ajax_handler() {
    wp_die();
}
add_action('wp_ajax_insurance_crm_ajax', 'insurance_crm_ajax_handler');

/**
 * Müşteri Temsilcileri sayfasını görüntüle
 */
function insurance_crm_display_representatives_page() {
    if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-representatives.php')) {
        require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-representatives.php';
    } else {
        if (!current_user_can('manage_insurance_crm')) {
            wp_die(__('Bu sayfaya erişim izniniz yok.'));
        }

        if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
            wp_verify_nonce($_POST['representative_nonce'], 'add_edit_representative')) {
            
            $rep_data = array(
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'title' => sanitize_text_field($_POST['title']),
                'phone' => sanitize_text_field($_POST['phone']),
                'department' => sanitize_text_field($_POST['department']),
                'monthly_target' => floatval($_POST['monthly_target'])
            );

            global $wpdb;
            $table_name = $wpdb->prefix . 'insurance_crm_representatives';

            if (isset($_POST['rep_id']) && !empty($_POST['rep_id'])) {
                $wpdb->update(
                    $table_name,
                    array(
                        'title' => $rep_data['title'],
                        'phone' => $rep_data['phone'],
                        'department' => $rep_data['department'],
                        'monthly_target' => $rep_data['monthly_target'],
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => intval($_POST['rep_id']))
                );
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
            } else {
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
                    } else if (email_exists($rep_data['email'])) {
                        echo '<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>';
                    } else {
                        $user_id = wp_create_user($username, $password, $rep_data['email']);
                        
                        if (!is_wp_error($user_id)) {
                            wp_update_user(
                                array(
                                    'ID' => $user_id,
                                    'first_name' => $rep_data['first_name'],
                                    'last_name' => $rep_data['last_name'],
                                    'display_name' => $rep_data['first_name'] . ' ' . $rep_data['last_name']
                                )
                            );
                            
                            $user = new WP_User($user_id);
                            $user->set_role('insurance_representative');
                            
                            $wpdb->insert(
                                $table_name,
                                array(
                                    'user_id' => $user_id,
                                    'title' => $rep_data['title'],
                                    'phone' => $rep_data['phone'],
                                    'department' => $rep_data['department'],
                                    'monthly_target' => $rep_data['monthly_target'],
                                    'status' => 'active',
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql')
                                )
                            );
                            
                            echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>';
                        } else {
                            echo '<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message() . '</p></div>';
                        }
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>';
                }
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_representative_' . $_GET['id'])) {
                global $wpdb;
                $rep_id = intval($_GET['id']);
                $table_name = $wpdb->prefix . 'insurance_crm_representatives';
                
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM $table_name WHERE id = %d",
                    $rep_id
                ));
                
                if ($user_id) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                    wp_delete_user($user_id);
                }
                
                $wpdb->delete($table_name, array('id' => $rep_id));
                
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi silindi.</p></div>';
            }
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_representatives';
        $representatives = $wpdb->get_results(
            "SELECT r.*, u.user_email as email, u.display_name 
             FROM $table_name r 
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.status = 'active' 
             ORDER BY r.created_at DESC"
        );
        ?>
        <div class="wrap">
            <h1>Müşteri Temsilcileri</h1>
            
            <h2>Mevcut Müşteri Temsilcileri</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
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
                    <?php foreach ($representatives as $rep): ?>
                    <tr>
                        <td><?php echo esc_html($rep->display_name); ?></td>
                        <td><?php echo esc_html($rep->email); ?></td>
                        <td><?php echo esc_html($rep->title); ?></td>
                        <td><?php echo esc_html($rep->phone); ?></td>
                        <td><?php echo esc_html($rep->department); ?></td>
                        <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=edit&id=' . $rep->id); ?>" 
                               class="button button-small">
                                Düzenle
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=insurance-crm-representatives&action=delete&id=' . $rep->id), 'delete_representative_' . $rep->id); ?>" 
                               class="button button-small" 
                               onclick="return confirm('Bu müşteri temsilcisini silmek istediğinizden emin misiniz?');">
                                Sil
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <hr>
            
            <h2>Yeni Müşteri Temsilcisi Ekle</h2>
            <form method="post" action="">
                <?php wp_nonce_field('add_edit_representative', 'representative_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="username">Kullanıcı Adı</label></th>
                        <td><input type="text" name="username" id="username" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="password">Şifre</label></th>
                        <td>
                            <input type="password" name="password" id="password" class="regular-text" required>
                            <p class="description">En az 8 karakter uzunluğunda olmalıdır.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="confirm_password">Şifre (Tekrar)</label></th>
                        <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="first_name">Ad</label></th>
                        <td><input type="text" name="first_name" id="first_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="last_name">Soyad</label></th>
                        <td><input type="text" name="last_name" id="last_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="email">E-posta</label></th>
                        <td><input type="email" name="email" id="email" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="title">Ünvan</label></th>
                        <td><input type="text" name="title" id="title" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="phone">Telefon</label></th>
                        <td><input type="tel" name="phone" id="phone" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="department">Departman</label></th>
                        <td><input type="text" name="department" id="department" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="monthly_target">Aylık Hedef (₺)</label></th>
                        <td>
                            <input type="number" step="0.01" name="monthly_target" id="monthly_target" class="regular-text" required>
                            <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit_representative" class="button button-primary" value="Müşteri Temsilcisi Ekle">
                </p>
            </form>
        </div>
        <?php
    }
}

/**
 * Sayfa şablonlarını kaydet
 */
function insurance_crm_create_pages() {
    $pages = array(
        array(
            'post_title'    => 'Temsilci Girişi',
            'post_content'  => '[temsilci_login]',
            'post_name'     => 'temsilci-girisi'
        ),
        array(
            'post_title'    => 'Temsilci Paneli',
            'post_content'  => '[temsilci_dashboard]',
            'post_name'     => 'temsilci-paneli'
        )
    );

    foreach ($pages as $page) {
        if (!get_page_by_path($page['post_name'], OBJECT, 'page')) {
            wp_insert_post(array(
                'post_title'    => $page['post_title'],
                'post_content'  => $page['post_content'],
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_name'     => $page['post_name']
            ));
        }
    }
}
register_activation_hook(__FILE__, 'insurance_crm_create_pages');

/**
 * Shortcode'ları ekle
 */
function insurance_crm_add_shortcodes() {
    require_once plugin_dir_path(__FILE__) . 'includes/shortcodes-representative-panel.php';
}
add_action('init', 'insurance_crm_add_shortcodes');

/**
 * Login işlemini yönet
 */
function insurance_crm_process_login() {
    if (isset($_POST['insurance_crm_login']) && isset($_POST['insurance_crm_login_nonce'])) {
        if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_login')) {
            error_log('Insurance CRM Login Error: Invalid nonce');
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        $username = sanitize_user($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;
        
        if (is_email($username)) {
            $user_data = get_user_by('email', $username);
            if ($user_data) {
                $username = $user_data->user_login;
            }
        }
        
        $creds = array(
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember
        );
        
        $user = wp_signon($creds, is_ssl());
        
        if (is_wp_error($user)) {
            error_log('Insurance CRM Login Error: ' . $user->get_error_message());
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        if (!in_array('insurance_representative', (array)$user->roles)) {
            wp_logout();
            error_log('Insurance CRM Login Error: User is not a representative');
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        global $wpdb;
        $rep_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $user->ID
        ));
        
        if ($rep_status !== 'active') {
            wp_logout();
            error_log('Insurance CRM Login Error: Representative status is not active');
            wp_safe_redirect(add_query_arg('login', 'inactive', home_url('/temsilci-girisi/')));
            exit;
        }
        
        error_log('Insurance CRM Login Success: User ID ' . $user->ID);
        wp_safe_redirect(home_url('/temsilci-paneli/'));
        exit;
    }
}
add_action('init', 'insurance_crm_process_login', 5);

/**
 * Admin notifikasyonu ekle
 */
function insurance_crm_admin_notice() {
    if (isset($_GET['page']) && strpos($_GET['page'], 'insurance-crm') !== false) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Insurance CRM v1.1.3:</strong> Yönetici Paneline Hoşgeldiniz.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'insurance_crm_admin_notice');

require_once plugin_dir_path(__FILE__) . 'includes/shortcodes-representative-panel.php';

add_action('template_redirect', 'insurance_crm_template_redirect');
function insurance_crm_template_redirect() {
}