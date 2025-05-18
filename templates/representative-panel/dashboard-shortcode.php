<?php
/**
 * Müşteri Temsilcisi Dashboard Shortcode
 */

// Shortcode fonksiyonunu oluştur
function insurance_crm_representative_dashboard_shortcode() {
    // Buffer başlat - çıktıyı yakalamak için
    ob_start();
    
    // Kullanıcı giriş yapmamışsa login sayfasına yönlendir
    if (!is_user_logged_in()) {
        wp_redirect(home_url('/temsilci-girisi/'));
        exit;
    }

    // Kullanıcı müşteri temsilcisi değilse ana sayfaya yönlendir
    $user = wp_get_current_user();
    if (!in_array('insurance_representative', (array)$user->roles)) {
        echo '<div class="insurance-crm-error">
           <center> <p>Üzgünüm, ancak bu sayfayı görüntüleme yetkiniz bulunmuyor. Bu sayfa sadece müşteri temsilcileri içindir.</p></center>
            <a href="' . home_url() . '" class="button">Ana Sayfaya Dön</a>
        </div>';
        return ob_get_clean();
    }

    // Dashboard içeriği için asıl dosyayı dahil et
    include_once(dirname(__FILE__) . '/dashboard.php');
    
    // Buffer'ı döndür
    return ob_get_clean();
}

// Shortcode'u kaydet
add_shortcode('temsilci_dashboard', 'insurance_crm_representative_dashboard_shortcode');