<?php
/**
 * Müşteri Temsilcisi Giriş İşlemleri
 */

class Insurance_CRM_Auth {
    
    /**
     * Sınıf örneklemesini başlat
     */
    public function __construct() {
        // Login form işlemini dinle
        add_action('init', array($this, 'process_login'));
        
        // WordPress giriş form kontrolünü ekle
        add_filter('authenticate', array($this, 'check_representative_status'), 30, 3);
    }
    
    /**
     * Login formunu işle
     */
    public function process_login() {
        // Login form gönderildi mi kontrol et
        if (isset($_POST['insurance_crm_login']) && isset($_POST['insurance_crm_login_nonce'])) {
            
            // Nonce doğrulama
            if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_login')) {
                wp_die('Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
            }
            
            // Kullanıcı adı ve şifreyi al
            $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            
            // Hatırla seçeneği
            $remember = isset($_POST['remember']) ? true : false;
            
            // Kullanıcı doğrulama
            $user = wp_authenticate($username, $password);
            
            // Hata kontrolü
            if (is_wp_error($user)) {
                // Hata yönlendirmesi
                wp_redirect(add_query_arg('login', 'failed', wp_get_referer()));
                exit;
            } else {
                // Başarılı giriş
                wp_set_auth_cookie($user->ID, $remember);
                
                // Dashboard'a yönlendir
                wp_redirect(home_url('/temsilci-paneli/'));
                exit;
            }
        }
    }
    
    /**
     * Müşteri temsilcisi durumunu kontrol et
     */
    public function check_representative_status($user, $username, $password) {
        // Kullanıcı zaten doğrulanmış mı kontrol et
        if (is_wp_error($user) || empty($username) || empty($password)) {
            return $user;
        }
        
        // Kullanıcı müşteri temsilcisi mi kontrol et
        if (in_array('insurance_representative', (array)$user->roles)) {
            
            // Veritabanında temsilcinin durumunu kontrol et
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
                 WHERE user_id = %d",
                $user->ID
            ));
            
            // Eğer temsilci pasif ise giriş izni verme
            if ($status !== 'active') {
                return new WP_Error('account_inactive', 'Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.');
            }
        }
        
        return $user;
    }
}

// Sınıfı başlat
new Insurance_CRM_Auth();