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
    // Admin bar’ı gizle
    show_admin_bar(false);

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
        $login_error = 'Kullanıcı adı veya şifre hatalı.';
    }
    if (isset($_GET['login']) && $_GET['login'] === 'inactive') {
        $login_error = 'Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.';
    }

    $settings = get_option('insurance_crm_settings', array());
    $company_name = !empty($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    $logo_url = !empty($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : plugins_url('/assets/images/insurance-logo.png', dirname(__FILE__));
    $font_family = !empty($settings['site_appearance']['font_family']) ? $settings['site_appearance']['font_family'] : '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    $primary_color = !empty($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#2980b9';
    $primary_color_rgb = hex2rgb($primary_color); // Arka plan için RGB formatına çevir
    $background_color = "rgba({$primary_color_rgb['r']}, {$primary_color_rgb['g']}, {$primary_color_rgb['b']}, 0.15)"; // Flu arka plan
    $button_gradient_end = adjustBrightness($primary_color, 1.2);
    ?>
    <div class="insurance-crm-login-wrapper">
        <div class="insurance-crm-login-box">
            <div class="login-header">
                <div class="login-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo">
                </div>
                <h2><?php echo esc_html($company_name); ?></h2>
                <h3>Müşteri Temsilcisi Girişi</h3>
            </div>
            
            <?php if (!empty($login_error)): ?>
                <div class="login-error"><?php echo esc_html($login_error); ?></div>
            <?php endif; ?>
            
            <form method="post" class="insurance-crm-login-form" id="loginform">
                <div class="form-group">
                    <label for="username">Kullanıcı Adı veya E-posta</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="dashicons dashicons-admin-users"></i></span>
                        <input type="text" name="username" id="username" required autocomplete="username">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Şifre</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="dashicons dashicons-lock"></i></span>
                        <input type="password" name="password" id="password" required autocomplete="current-password">
                        <span class="toggle-password"><i class="dashicons dashicons-visibility"></i></span>
                    </div>
                </div>

                <div class="form-group remember-me">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        <span>Beni Hatırla</span>
                    </label>
                </div>

                <div class="form-group">
                    <button type="submit" name="insurance-crm-login" class="login-button" id="wp-submit">
                        <span class="button-text">Giriş Yap</span>
                        <span class="button-loading" style="display:none;">
                            <i class="dashicons dashicons-update spin"></i>
                        </span>
                    </button>
                    <div class="login-loading" style="display:none;">
                        <i class="dashicons dashicons-update spin"></i> Giriş yapılıyor...
                    </div>
                </div>
                
                <?php wp_nonce_field('insurance_crm_login', 'insurance_crm_login_nonce'); ?>
            </form>
            
            <div class="login-footer">
                <p><?php echo date('Y'); ?> © <?php echo esc_html($company_name); ?> - Sigorta CRM</p>
            </div>
        </div>
    </div>

    <style>
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        height: 100%;
        overflow-x: hidden;
        overflow-y: hidden;
    }

    .insurance-crm-login-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background: <?php echo esc_attr($background_color); ?>;
        padding: 0;
        position: relative;
        overflow: hidden;
        font-family: <?php echo esc_attr($font_family); ?>;
        margin: 0;
    }

    .insurance-crm-login-wrapper::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.05) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.05) 50%, rgba(255, 255, 255, 0.05) 75%, transparent 75%, transparent);
        background-size: 40px 40px;
        opacity: 0.3;
        z-index: 0;
    }

    .insurance-crm-login-box {
        width: 100%;
        max-width: 420px;
        max-height: 90vh;
        background: #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        border-radius: 16px;
        padding: 30px;
        position: relative;
        z-index: 1;
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.95);
        overflow-y: auto;
        box-sizing: border-box;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-5vh);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .login-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .login-logo {
        margin-bottom: 15px;
    }

    .login-logo img {
        max-width: 200px;
        max-height: 80px;
        object-fit: contain;
        transition: transform 0.3s ease;
    }

    .login-logo img:hover {
        transform: scale(1.05);
    }

    .login-header h2 {
        font-size: 28px;
        font-weight: 700;
        color: <?php echo esc_attr($primary_color); ?>;
        margin: 0 0 5px;
    }

    .login-header h3 {
        font-size: 16px;
        font-weight: 400;
        color: #6b7280;
        margin: 0;
    }

    .login-error {
        background: #fef2f2;
        color: #dc2626;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 4px solid #dc2626;
        font-size: 14px;
        text-align: center;
        animation: shake 0.3s ease;
        box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
        20%, 40%, 60%, 80% { transform: translateX(3px); }
    }

    .insurance-crm-login-form .form-group {
        margin-bottom: 20px;
        position: relative;
    }

    .insurance-crm-login-form label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
    }

    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-icon {
        position: absolute;
        left: 12px;
        color: #9ca3af;
        font-size: 18px;
        z-index: 1;
    }

    .input-icon .dashicons {
        width: 18px;
        height: 18px;
    }

    .toggle-password {
        position: absolute;
        right: 12px;
        color: #9ca3af;
        font-size: 18px;
        cursor: pointer;
        z-index: 1;
    }

    .toggle-password .dashicons {
        width: 18px;
        height: 18px;
    }

    .toggle-password .dashicons-visibility::before {
        content: "\f177";
    }

    .toggle-password .dashicons-hidden::before {
        content: "\f530";
    }

    .insurance-crm-login-form input[type="text"],
    .insurance-crm-login-form input[type="password"] {
        width: 100%;
        padding: 12px 40px;
        border: none;
        border-bottom: 2px solid #e5e7eb;
        background: transparent;
        font-size: 15px;
        color: #1a1a1a;
        transition: border-bottom-color 0.3s ease, box-shadow 0.3s ease;
        box-sizing: border-box;
    }

    .insurance-crm-login-form input:focus {
        outline: none;
        border-bottom-color: <?php echo esc_attr($primary_color); ?>;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .insurance-crm-login-form input:-webkit-autofill,
    .insurance-crm-login-form input:-webkit-autofill:hover,
    .insurance-crm-login-form input:-webkit-autofill:focus {
        -webkit-box-shadow: 0 0 0px 1000px #fff inset;
        -webkit-text-fill-color: #1a1a1a;
        caret-color: #1a1a1a;
    }

    .remember-me {
        margin-bottom: 25px;
        display: flex;
        align-items: center;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        font-size: 14px;
        color: #6b7280;
        cursor: pointer;
    }

    .checkbox-label input {
        margin-right: 8px;
        accent-color: <?php echo esc_attr($primary_color); ?>;
    }

    .login-button {
        width: 100%;
        background: linear-gradient(90deg, <?php echo esc_attr($primary_color); ?> 0%, <?php echo esc_attr($button_gradient_end); ?> 100%);
        color: white;
        border: none;
        padding: 14px;
        font-size: 16px;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        position: relative;
    }

    .login-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        background: linear-gradient(90deg, <?php echo esc_attr(adjustBrightness($primary_color, 1.1)); ?> 0%, <?php echo esc_attr($primary_color); ?> 100%);
    }

    .login-button:active {
        transform: translateY(0);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .login-button.loading .button-text {
        opacity: 0;
    }

    .login-button.loading .button-loading {
        display: inline-flex;
        opacity: 1;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }

    .button-loading {
        display: none;
        align-items: center;
        gap: 8px;
    }

    .login-footer {
        text-align: center;
        margin-top: 20px;
        color: #6b7280;
        font-size: 13px;
    }

    .login-loading {
        text-align: center;
        margin-top: 10px;
        color: #6b7280;
        font-size: 14px;
        display: none;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .spin {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    @media (max-width: 480px) {
        .insurance-crm-login-box {
            padding: 20px;
            margin: 0 15px;
            max-height: 85vh;
        }

        .login-header h2 {
            font-size: 24px;
        }

        .login-header h3 {
            font-size: 14px;
        }

        .login-logo img {
            max-width: 160px;
            max-height: 60px;
        }

        .insurance-crm-login-form input[type="text"],
        .insurance-crm-login-form input[type="password"] {
            padding: 10px 36px;
            font-size: 14px;
        }

        .login-button {
            padding: 12px;
            font-size: 15px;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Şifreyi göster/gizle özelliği
        $('.toggle-password').on('click', function() {
            const $icon = $(this).find('.dashicons');
            const $input = $(this).siblings('input');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        });

        // Form gönderimi
        $("#loginform").on("submit", function(e) {
            e.preventDefault();
            const $button = $("#wp-submit");
            $button.addClass('loading').prop("disabled", true);
            $(".login-loading").show();
            $(".login-error").remove();

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: $(this).serialize() + '&action=insurance_crm_login',
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect;
                    } else {
                        $(".login-header").after('<div class="login-error">' + response.data.message + '</div>');
                        $button.removeClass('loading').prop("disabled", false);
                        $(".login-loading").hide();
                    }
                },
                error: function() {
                    $(".login-header").after('<div class="login-error">Bir hata oluştu, lütfen tekrar deneyin.</div>');
                    $button.removeClass('loading').prop("disabled", false);
                    $(".login-loading").hide();
                }
            });
        });
    });
    </script>
    <?php
    
    return ob_get_clean();
}

// Renk parlaklığını ayarlama fonksiyonu
function adjustBrightness($hex, $factor) {
    $hex = str_replace('#', '', $hex);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = min(255, max(0, round($r * $factor)));
    $g = min(255, max(0, round($g * $factor)));
    $b = min(255, max(0, round($b * $factor)));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// Hex rengi RGB formatına çevirme
function hex2rgb($hex) {
    $hex = str_replace('#', '', $hex);
    return array(
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2))
    );
}

// AJAX Login Handler
add_action('wp_ajax_nopriv_insurance_crm_login', 'insurance_crm_handle_login');
function insurance_crm_handle_login() {
    check_ajax_referer('insurance_crm_login', 'insurance_crm_login_nonce');

    $credentials = array(
        'user_login'    => sanitize_text_field($_POST['username']),
        'user_password' => $_POST['password'],
        'remember'      => isset($_POST['remember']) && $_POST['remember'] === 'on' ? true : false
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

    // Google Fonts - Inter
    wp_enqueue_style('google-fonts-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null);
}
add_action('wp_enqueue_scripts', 'insurance_crm_rep_panel_assets');