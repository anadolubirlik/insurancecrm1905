<?php
/**
 * Müşteri Temsilcisi Paneli için Shortcodelar
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Müşteri Temsilcisi Dashboard Shortcode
 */
function insurance_crm_representative_dashboard_shortcode() {
    ob_start();
    
    // Yönetici panelinde düzenleme ekranındaysa yönlendirme yapma
    if (is_admin() && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit') {
        echo '<div class="insurance-crm-notice">Temsilci Paneli önizlemesi düzenleme ekranında görüntülenemez.</div>';
        return ob_get_clean();
    }
    
    if (!is_user_logged_in()) {
        error_log('Insurance CRM Dashboard Redirect: User not logged in, redirecting to login');
        wp_safe_redirect(home_url('/temsilci-girisi/'));
        exit;
    }

    $user = wp_get_current_user();
    if (in_array('administrator', (array)$user->roles)) {
        include_once(plugin_dir_path(dirname(__FILE__)) . 'templates/boss-panel/dashboard.php');
        return ob_get_clean();
    } elseif (!in_array('insurance_representative', (array)$user->roles)) {
        echo '<div class="insurance-crm-error">
            <p><center>Bu sayfayı görüntüleme yetkiniz bulunmuyor. Bu sayfa sadece müşteri temsilcileri ve yöneticiler içindir.<center></p>
            <a href="' . esc_url(home_url()) . '" class="button">Ana Sayfaya Dön</a>
        </div>';
        return ob_get_clean();
    }

    include_once(plugin_dir_path(dirname(__FILE__)) . 'templates/representative-panel/dashboard.php');
    
    return ob_get_clean();
}

/**
 * Müşteri Temsilcisi Login Shortcode
 */
function insurance_crm_representative_login_shortcode() {
    ob_start();
    
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('administrator', (array)$user->roles)) {
            error_log('Insurance CRM Login Redirect: Administrator logged in, redirecting to boss dashboard');
            wp_safe_redirect(home_url('/boss-panel/'));
            exit;
        } elseif (in_array('insurance_representative', (array)$user->roles)) {
            error_log('Insurance CRM Login Redirect: User already logged in, redirecting to dashboard');
            wp_safe_redirect(home_url('/temsilci-paneli/'));
            exit;
        }
    }

    $login_error = '';
    if (isset($_GET['login']) && $_GET['login'] === 'failed') {
        $login_error = '<div class="login-error">Kullanıcı adı veya şifre hatalı.</div>';
    }
    if (isset($_GET['login']) && $_GET['login'] === 'inactive') {
        $login_error = '<div class="login-error">Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.</div>';
    }

    $settings = get_option('insurance_crm_settings', array());
    $company_name = !empty($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    $logo_url = !empty($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : plugins_url('/assets/images/insurance-logo.png', dirname(__FILE__));
    $font_family = !empty($settings['site_appearance']['font_family']) ? $settings['site_appearance']['font_family'] : 'Arial, sans-serif';
    $primary_color = !empty($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#2980b9';
    ?>
    <div class="insurance-crm-login-wrapper" style="background-color: <?php echo esc_attr($primary_color); ?>33;">
        <div class="insurance-crm-login-box">
            <div class="login-header">
                <h2 style="font-family: <?php echo esc_attr($font_family); ?>;"><?php echo esc_html($company_name); ?></h2>
                <div class="login-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo" style="max-height: 80px; max-width: 160px;">
                </div>
                <h3 style="font-family: <?php echo esc_attr($font_family); ?>;">Müşteri Temsilcisi Girişi</h3>
            </div>
            
            <?php echo $login_error; ?>
            
            <form method="post" class="insurance-crm-login-form" id="loginform">
                <div class="form-group">
                    <label for="username"><i class="dashicons dashicons-admin-users"></i></label>
                    <input type="text" name="username" id="username" placeholder="Kullanıcı Adı" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="dashicons dashicons-lock"></i></label>
                    <input type="password" name="password" id="password" placeholder="Şifre" required autocomplete="current-password">
                </div>

                <div class="form-group">
                    <input type="submit" name="insurance_crm_login" value="Giriş Yap" class="login-button" id="wp-submit" style="background-color: <?php echo esc_attr($primary_color); ?>;">
                    <div class="login-loading" style="display:none;">
                        <i class="dashicons dashicons-update spin"></i> Giriş yapılıyor...
                    </div>
                </div>
                
                <?php wp_nonce_field('insurance_crm_login', 'insurance_crm_login_nonce'); ?>
            </form>
            
            <div class="login-footer">
                <p style="font-family: <?php echo esc_attr($font_family); ?>;"><?php echo date('Y'); ?> © <?php echo esc_html($company_name); ?> - Sigorta CRM</p>
            </div>
        </div>
    </div>

    <style>
    .insurance-crm-login-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: calc(100vh - 20px);
        padding: 10px;
        background: #f7f9fc;
        position: relative;
        overflow: hidden;
    }

    .insurance-crm-login-box {
        width: 100%;
        max-width: 400px;
        background: #fff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 20px;
        animation: fadeIn 0.3s ease;
        position: relative;
        z-index: 1;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-5px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .login-header {
        text-align: center;
        margin-bottom: 20px;
    }

    .login-header h2 {
        font-size: 24px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 10px;
    }

    .login-header h3 {
        font-size: 16px;
        font-weight: 500;
        color: #7f8c8d;
        margin: 5px 0;
    }

    .login-logo {
        margin: 10px 0;
        display: flex;
        justify-content: center;
    }

    .login-logo img {
        max-height: 80px;
        max-width: 160px;
        object-fit: contain;
    }

    .insurance-crm-login-form .form-group {
        margin-bottom: 15px;
        position: relative;
    }

    .insurance-crm-login-form label {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #95a5a6;
        z-index: 2;
    }

    .insurance-crm-login-form input[type="text"],
    .insurance-crm-login-form input[type="password"] {
        width: 100%;
        padding: 12px 12px 12px 35px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .insurance-crm-login-form input[type="text"]:focus,
    .insurance-crm-login-form input[type="password"]:focus {
        border-color: #2980b9;
        outline: none;
        box-shadow: 0 0 0 2px rgba(41, 128, 185, 0.1);
    }

    .login-button {
        width: 100%;
        background-color: #2980b9;
        color: white;
        border: none;
        padding: 12px;
        font-size: 14px;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .login-button:hover {
        background-color: #3498db;
    }

    .login-button:active {
        background-color: #1c638d;
    }

    .login-error {
        background-color: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 15px;
        border-left: 3px solid #f5c6cb;
        animation: shake 0.3s;
        text-align: center;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
        20%, 40%, 60%, 80% { transform: translateX(3px); }
    }

    .login-footer {
        text-align: center;
        margin-top: 20px;
        color: #7f8c8d;
        font-size: 12px;
    }

    .login-loading {
        text-align: center;
        margin-top: 10px;
        color: #7f8c8d;
        display: none;
    }

    .spin {
        animation: spin 1.5s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Responsive Design */
    @media (max-width: 480px) {
        .insurance-crm-login-box {
            padding: 15px;
            margin: 0 10px;
        }

        .insurance-crm-login-form input[type="text"],
        .insurance-crm-login-form input[type="password"] {
            padding: 10px 10px 10px 30px;
            font-size: 13px;
        }

        .login-header h2 {
            font-size: 20px;
        }

        .login-header h3 {
            font-size: 14px;
        }

        .login-logo img {
            max-height: 60px;
            max-width: 120px;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        $("#loginform").on("submit", function(e) {
            $("#wp-submit").prop("disabled", true);
            $(".login-loading").show();

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: $(this).serialize() + '&action=insurance_crm_login',
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect;
                    } else {
                        $(".login-error").remove();
                        $(".login-header").after('<div class="login-error">' + response.data.message + '</div>');
                        $("#wp-submit").prop("disabled", false);
                        $(".login-loading").hide();
                    }
                },
                error: function() {
                    $(".login-error").remove();
                    $(".login-header").after('<div class="login-error">Bir hata oluştu, lütfen tekrar deneyin.</div>');
                    $("#wp-submit").prop("disabled", false);
                    $(".login-loading").hide();
                }
            });

            e.preventDefault();
        });
    });
    </script>
    <?php
    
    return ob_get_clean();
}

// AJAX Login Handler
add_action('wp_ajax_nopriv_insurance_crm_login', 'insurance_crm_handle_login');
function insurance_crm_handle_login() {
    check_ajax_referer('insurance_crm_login', 'insurance_crm_login_nonce');

    $credentials = array(
        'user_login'    => sanitize_text_field($_POST['username']),
        'user_password' => $_POST['password'],
        'remember'      => false
    );

    $user = wp_signon($credentials, false);

    if (is_wp_error($user)) {
        wp_send_json_error(array('message' => $user->get_error_message()));
    } else {
        if (in_array('administrator', (array)$user->roles)) {
            wp_send_json_success(array('redirect' => home_url('/boss-panel/')));
        } elseif (in_array('insurance_representative', (array)$user->roles)) {
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
                $user->ID
            ));

            if ($status === 'active') {
                wp_send_json_success(array('redirect' => home_url('/temsilci-paneli/')));
            } else {
                wp_send_json_error(array('message' => 'Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.'));
            }
        } else {
            wp_send_json_error(array('message' => 'Bu kullanıcı müşteri temsilcisi veya yönetici değil.'));
        }
    }

    wp_die();
}

// Shortcode'ları kaydet
add_shortcode('temsilci_dashboard', 'insurance_crm_representative_dashboard_shortcode');
add_shortcode('temsilci_login', 'insurance_crm_representative_login_shortcode');

// Müşteri temsilcileri için giriş kontrolü ve yönlendirme
add_filter('login_redirect', 'insurance_crm_login_redirect', 10, 3);
function insurance_crm_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!is_wp_error($user) && isset($user->roles) && is_array($user->roles)) {
        if (in_array('administrator', $user->roles)) {
            return home_url('/boss-panel/');
        } elseif (in_array('insurance_representative', $user->roles)) {
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
                 WHERE user_id = %d",
                $user->ID
            ));
            
            if ($status === 'active') {
                return home_url('/temsilci-paneli/');
            } else {
                return add_query_arg('login', 'inactive', home_url('/temsilci-girisi/'));
            }
        }
    }
    
    return $redirect_to;
}

// Kullanıcı giriş hatalarını yakala
add_filter('authenticate', 'insurance_crm_check_representative_status', 30, 3);
function insurance_crm_check_representative_status($user, $username, $password) {
    if (!is_wp_error($user) && $username && $password) {
        if (in_array('insurance_representative', (array)$user->roles)) {
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
                 WHERE user_id = %d",
                $user->ID
            ));
            
            if ($status !== 'active') {
                error_log('Insurance CRM Authenticate Error: Representative status is not active for user ID ' . $user->ID);
                return new WP_Error('account_inactive', '<strong>HATA</strong>: Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.');
            }
        }
    }
    
    return $user;
}

// Frontend dosyalarını ekle
function insurance_crm_rep_panel_assets() {
    wp_enqueue_style('dashicons');
    
    if (is_page('temsilci-paneli')) {
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array('jquery'), '3.9.1', true);
    }
}
add_action('wp_enqueue_scripts', 'insurance_crm_rep_panel_assets');