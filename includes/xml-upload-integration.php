<?php
/**
 * XML Yükleme Entegrasyonu
 * XML dosyalarını algılayıp işleyen frontend kodu
 * @version 1.0.0
 */

/**
 * Frontend poliçe XML yükleme işlemlerini yönetir
 */
class InsuranceCrmXmlUploader {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Singleton örneği döndürür
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // XML format yöneticisini yükle
        require_once(plugin_dir_path(__FILE__) . 'xml-format-manager.php');
        
        // XML yükleme işlemini dinle
        add_action('init', array($this, 'process_xml_upload'));
    }
    
    /**
     * XML yükleme işlemini gerçekleştirir
     */
    public function process_xml_upload() {
        if (!isset($_POST['preview_xml']) || !isset($_POST['xml_import_nonce']) || !wp_verify_nonce($_POST['xml_import_nonce'], 'xml_import_action')) {
            return;
        }
        
        if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Lütfen bir XML dosyası seçin.</div>';
            return;
        }
        
        $file_tmp = $_FILES['xml_file']['tmp_name'];
        $file_type = mime_content_type($file_tmp);
        
        // Sadece XML dosyalarına izin ver
        if ($file_type !== 'text/xml' && $file_type !== 'application/xml') {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Lütfen geçerli bir XML dosyası yükleyin.</div>';
            return;
        }
        
        $xml_content = file_get_contents($file_tmp);
        $xml_content = preg_replace('/^\xEF\xBB\xBF/', '', $xml_content);
        
        // Hata işleme modunu etkinleştir
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        $libxml_errors = libxml_get_errors();
        libxml_clear_errors();
        
        if ($xml === false || !empty($libxml_errors)) {
            $error_msg = "XML dosyası ayrıştırılamadı. ";
            if (!empty($libxml_errors)) {
                $error_msg .= "Hata: " . $libxml_errors[0]->message;
            }
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">' . $error_msg . '</div>';
            return;
        }
        
        // Format yöneticisini ve gereken diğer değişkenleri al
        $format_manager = InsuranceXMLFormatManager::get_instance();
        global $wpdb;
        $current_user_rep_id = get_current_user_rep_id(); // Bu fonksiyon policies.php içinde tanımlanmış olmalı
        $customers_table = $wpdb->prefix . 'insurance_crm_customers';
        
        // Önizleme verilerini hazırla
        $preview_data = array(
            'policies' => array(),
            'customers' => array(),
        );
        
        $debug_info = array(
            'total_policies' => 0,
            'processed_policies' => 0,
            'matched_customers' => 0,
            'failed_matches' => 0,
            'last_error' => '',
            'process_start' => date('Y-m-d H:i:s'),
        );
        
        // Format algılama
        $detection_result = $format_manager->detect_format($xml);
        
        if (!$detection_result['format_id']) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">XML formatı tanınamadı. Lütfen XML dosyasının formatını kontrol edin.</div>';
            return;
        }
        
        $format_id = $detection_result['format_id'];
        $debug_info['detected_format'] = $format_id;
        $debug_info['xml_structure'] = $detection_result['format_info']['test_node'];
        
        // Format için uygun işleyiciyi al
        $handler = $format_manager->create_handler($format_id);
        
        if (!$handler) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Algılanan format için işleyici oluşturulamadı: ' . $format_id . '</div>';
            return;
        }
        
        // XML verilerini işle
        $result = $handler->process_xml($xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
        
        if (!$result) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">XML verileri işlenirken hata oluştu: ' . $debug_info['last_error'] . '</div>';
            return;
        }
        
        // Hiç poliçe işlenmemişse hata ver
        if ($debug_info['processed_policies'] === 0) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">XML dosyası okundu, ancak hiçbir poliçe bilgisi bulunamadı. Sebep: ' . $debug_info['last_error'] . '</div>';
            return;
        }
        
        // İşlem başarılı - Debug bilgisini ekle
        $debug_info['process_end'] = date('Y-m-d H:i:s');
        $preview_data['debug'] = $debug_info;
        
        // Önizleme verisini sakla
        $_SESSION['xml_preview_data'] = $preview_data;
        
        // Önizleme sayfasına yönlendir
        wp_redirect('?view=policies&action=preview_xml');
        exit;
    }
}

// Sınıfı başlat
InsuranceCrmXmlUploader::get_instance();