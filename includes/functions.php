<?php
/**
 * Yardımcı fonksiyonlar
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/includes
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Müşteri sayfası
 */
function insurance_crm_customers() {
    // Müşteri ayrıntıları sayfası
    if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php';
            return;
        }
    }
    
    // Müşteri düzenleme sayfası
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    // Yeni müşteri ekleme
    if (isset($_GET['action']) && $_GET['action'] === 'new') {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    // Varsayılan müşteri listesi
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-customers.php';
}

/**
 * Müşteriyi görüntülemek için yönlendirme fonksiyonu
 */
function insurance_crm_redirect_customer_links() {
    if (!is_admin() || !current_user_can('read_insurance_crm')) {
        return;
    }
    
    // URL'de müşteri ismi geçiyorsa ayrıntılar sayfasına yönlendir
    if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-customers') {
        if (isset($_GET['customer_name']) && isset($_GET['id'])) {
            $customer_id = intval($_GET['id']);
            wp_redirect(admin_url('admin.php?page=insurance-crm-customers&action=view&id=' . $customer_id));
            exit;
        }
    }
    
    // Poliçe sayfasından müşteri adına tıklandığında
    if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-policies' && isset($_GET['view_customer']) && isset($_GET['customer_id'])) {
        $customer_id = intval($_GET['customer_id']);
        wp_redirect(admin_url('admin.php?page=insurance-crm-customers&action=view&id=' . $customer_id));
        exit;
    }
}
add_action('admin_init', 'insurance_crm_redirect_customer_links');

// functions.php dosyasına eklenecek
function temsilci_panel_shortcode() {
    ob_start();
    ?>
    <div class="login-container" style="max-width: 400px; margin: 50px auto; background: #fff; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px;">
        <div class="login-logo" style="text-align: center; margin-bottom: 30px;">
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.png" alt="Anadolu Birlik Sigorta" style="max-width: 200px; height: auto;">
            <?php if (!file_exists(get_template_directory() . '/assets/images/logo.png')): ?>
                <h2 style="color: #2c3e50; font-size: 24px;">Anadolu Birlik Sigorta</h2>
            <?php endif; ?>
        </div>
        <div class="login-form">
            <h2 style="color: #2c3e50; text-align: center; margin-bottom: 20px; font-size: 22px;">Müşteri Temsilcisi Girişi</h2>
            <form action="#" method="post" id="temsilci-login-form">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="username" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Kullanıcı Adı</label>
                    <input type="text" id="username" name="username" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="password" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Şifre</label>
                    <input type="password" id="password" name="password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-actions" style="display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 14px;">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember" style="color: #555;">Beni Hatırla</label>
                    </div>
                    <a href="#" class="forgot-password" style="color: #3498db; text-decoration: none;">Şifremi Unuttum</a>
                </div>
                <button type="submit" class="btn-login" style="width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 500; cursor: pointer; transition: background 0.3s;">Giriş Yap</button>
            </form>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('temsilci-login-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            // Basit bir demo giriş kontrolü - gerçekte sunucuda doğrulanmalıdır
            if(username === 'demo' && password === 'demo123') {
                alert('Başarıyla giriş yaptınız! Dashboard hazır olduğunda yönlendirileceksiniz.');
            } else {
                alert('Geçersiz kullanıcı adı veya şifre.');
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('temsilci_panel', 'temsilci_panel_shortcode');


// Müşteri temsilcileri için giriş kontrolü ve yönlendirme
add_action('wp_login', 'redirect_insurance_representative_after_login', 10, 2);
function redirect_insurance_representative_after_login($user_login, $user) {
    // Kullanıcının rollerini kontrol et
    if (in_array('insurance_representative', (array)$user->roles)) {
        // Müşteri temsilcisinin durumunu kontrol et
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d",
            $user->ID
        ));
        
        // Eğer status aktifse, dashboarda yönlendir
        if ($status === 'active') {
            wp_redirect(home_url('/temsilci-paneli/'));
            exit;
        }
    }
}

// Login sayfası özelleştirmesi
add_filter('login_redirect', 'custom_login_redirect', 10, 3);
function custom_login_redirect($redirect_to, $requested_redirect_to, $user) {
    // Kullanıcı giriş yapmışsa ve müşteri temsilcisiyse panele yönlendir
    if (!is_wp_error($user) && in_array('insurance_representative', (array)$user->roles)) {
        // Müşteri temsilcisinin durumunu kontrol et
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d",
            $user->ID
        ));
        
        // Eğer status aktifse, dashboarda yönlendir
        if ($status === 'active') {
            return home_url('/temsilci-paneli/');
        }
    }
    
    // Diğer kullanıcılar için normal yönlendirme
    return $redirect_to;
}