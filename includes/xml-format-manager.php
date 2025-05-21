<?php
/**
 * XML Format Yöneticisi
 * Farklı XML formatlarını yönetir, algılar ve işler
 * @version 1.0.0
 */

class InsuranceXMLFormatManager {
    // Tanımlı formatlar
    private $registered_formats = array();
    
    // Varsayılan sistem formatları
    private $system_formats = array(
        'sompo' => array(
            'name' => 'Sompo Sigorta',
            'description' => 'Sompo Sigorta standart XML formatı',
            'detection_node' => 'ACENTEDATATRANSFERI',
            'test_node' => 'ACENTEDATATRANSFERI/POLICE', // XPath formatında ana düğüm
            'handler_class' => 'SompoXMLFormatHandler',
            'is_system' => true
        ),
        'mapfre' => array(
            'name' => 'Mapfre Sigorta',
            'description' => 'Mapfre Sigorta standart XML formatı',
            'detection_node' => 'POLICIES_DATA',
            'test_node' => 'POLICIES_DATA/POLICY', // XPath formatında ana düğüm
            'handler_class' => 'MapfreXMLFormatHandler',
            'is_system' => true
        ),
        'allianz' => array(
            'name' => 'Allianz Sigorta',
            'description' => 'Allianz Sigorta standart XML formatı',
            'detection_node' => 'ALLIANZ_POLICIES',
            'test_node' => 'ALLIANZ_POLICIES/POLICY_DATA', // XPath formatında ana düğüm
            'handler_class' => 'AllianzXMLFormatHandler',
            'is_system' => true
        )
    );
    
    // Singleton örneği
    private static $instance = null;
    
    /**
     * Singleton örneğini döndürür
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
        $this->load_formats();
    }
    
    /**
     * Kayıtlı formatları yükler
     */
    public function load_formats() {
        // Sistem formatlarını yükle
        $this->registered_formats = $this->system_formats;
        
        // Özel formatları veritabanından yükle
        $custom_formats = get_option('insurance_crm_xml_formats', array());
        if (!empty($custom_formats) && is_array($custom_formats)) {
            $this->registered_formats = array_merge($this->registered_formats, $custom_formats);
        }
        
        // Filtre uygula, eklentiler yeni formatlar ekleyebilir
        $this->registered_formats = apply_filters('insurance_crm_xml_formats', $this->registered_formats);
    }
    
    /**
     * Özel XML formatı ekler
     * 
     * @param string $format_id Format ID'si
     * @param array $format_data Format detayları
     * @return bool Ekleme başarılı mı?
     */
    public function add_custom_format($format_id, $format_data) {
        if (empty($format_id) || empty($format_data)) {
            return false;
        }
        
        // Format doğrula
        if (!isset($format_data['name']) || !isset($format_data['detection_node']) || 
            !isset($format_data['test_node']) || !isset($format_data['field_mappings'])) {
            return false;
        }
        
        $custom_formats = get_option('insurance_crm_xml_formats', array());
        $format_data['is_system'] = false; // Özel formatlar sistem formatı değil
        $custom_formats[$format_id] = $format_data;
        
        // Veritabanına kaydet
        update_option('insurance_crm_xml_formats', $custom_formats);
        
        // Yeni formatları yükle
        $this->load_formats();
        
        return true;
    }
    
    /**
     * Özel XML formatını siler
     * 
     * @param string $format_id Format ID'si
     * @return bool Silme başarılı mı?
     */
    public function delete_custom_format($format_id) {
        if (empty($format_id)) {
            return false;
        }
        
        // Sistem formatlarını silemezsin
        if (isset($this->system_formats[$format_id])) {
            return false;
        }
        
        $custom_formats = get_option('insurance_crm_xml_formats', array());
        
        if (isset($custom_formats[$format_id])) {
            unset($custom_formats[$format_id]);
            update_option('insurance_crm_xml_formats', $custom_formats);
            $this->load_formats();
            return true;
        }
        
        return false;
    }
    
    /**
     * XML içeriğine göre formatı otomatik algılar
     * 
     * @param SimpleXMLElement $xml XML içeriği
     * @return array|false Algılanan format bilgileri veya false
     */
    public function detect_format($xml) {
        if (!$xml) {
            return false;
        }
        
        // Debug bilgisi
        $detected_info = array(
            'tested_formats' => array(),
            'detected_format' => null,
            'xml_type' => gettype($xml),
            'top_level_nodes' => array()
        );
        
        // Basit XML Element ise top level node'ları kaydedelim
        if ($xml instanceof SimpleXMLElement) {
            foreach ($xml->children() as $child_name => $child) {
                $detected_info['top_level_nodes'][] = $child_name;
            }
        }
        
        // Her bir kayıtlı formatı test et
        foreach ($this->registered_formats as $format_id => $format_info) {
            $detection_result = $this->test_format($xml, $format_info);
            $detected_info['tested_formats'][$format_id] = $detection_result;
            
            if ($detection_result['match']) {
                $detected_info['detected_format'] = $format_id;
                return array(
                    'format_id' => $format_id,
                    'format_info' => $format_info,
                    'detection_info' => $detected_info
                );
            }
        }
        
        // Hiçbir format bulunamadı
        return array(
            'format_id' => null,
            'format_info' => null,
            'detection_info' => $detected_info
        );
    }
    
    /**
     * Verilen XML'in belirli bir formatla eşleşip eşleşmediğini test eder
     * 
     * @param SimpleXMLElement $xml XML içeriği
     * @param array $format_info Format bilgileri
     * @return array Test sonuçları
     */
    private function test_format($xml, $format_info) {
        $result = array(
            'match' => false,
            'reason' => '',
            'tested_node' => $format_info['detection_node']
        );
        
        // Detection node var mı?
        $detection_node = $format_info['detection_node'];
        $test_node = $format_info['test_node'];
        
        // Düğüm adlarını parçala (XPath formatını kontrol et)
        $path_parts = explode('/', $detection_node);
        $current_node = $xml;
        
        // Her bir düğümü kontrol et
        foreach ($path_parts as $node_name) {
            if (empty($node_name)) continue;
            
            if (isset($current_node->$node_name)) {
                $current_node = $current_node->$node_name;
            } else {
                $result['reason'] = "Düğüm bulunamadı: $node_name";
                return $result;
            }
        }
        
        // Test node'unu kontrol edelim
        $test_parts = explode('/', $test_node);
        $current_test_node = $xml;
        
        // Her bir test düğümünü kontrol et
        foreach ($test_parts as $node_name) {
            if (empty($node_name)) continue;
            
            if (isset($current_test_node->$node_name)) {
                $current_test_node = $current_test_node->$node_name;
            } else {
                $result['reason'] = "Test düğümü bulunamadı: $node_name";
                return $result;
            }
        }
        
        // Eğer buraya kadar geldiyse eşleşme var demektir
        $result['match'] = true;
        return $result;
    }
    
    /**
     * Format işleyicisini oluşturur
     * 
     * @param string $format_id Format ID'si
     * @return XMLFormatHandler|false Format işleyicisi veya false
     */
    public function create_handler($format_id) {
        if (!isset($this->registered_formats[$format_id])) {
            return false;
        }
        
        $format = $this->registered_formats[$format_id];
        
        // Sistem formatı mı?
        if ($format['is_system']) {
            // Sınıfı yükle
            $handler_class = $format['handler_class'];
            if (class_exists($handler_class)) {
                return new $handler_class();
            }
        } else {
            // Özel format
            if (isset($format['field_mappings'])) {
                return new CustomXMLFormatHandler($format);
            }
        }
        
        return false;
    }
    
    /**
     * Tüm kayıtlı formatları döndürür
     * 
     * @return array Kayıtlı XML formatları
     */
    public function get_formats() {
        return $this->registered_formats;
    }
    
    /**
     * Belirli bir formatı döndürür
     * 
     * @param string $format_id Format ID'si
     * @return array|false Format bilgileri veya false
     */
    public function get_format($format_id) {
        return isset($this->registered_formats[$format_id]) ? $this->registered_formats[$format_id] : false;
    }
}

/**
 * XML Format İşleyicisi Arayüzü
 */
interface XMLFormatHandler {
    /**
     * XML'i işler ve poliçe/müşteri verilerini çıkarır
     * 
     * @param SimpleXMLElement $xml XML içeriği
     * @param array &$preview_data Çıkarılan verileri saklar
     * @param int $representative_id Temsilci ID'si
     * @param WPDB $wpdb WordPress veritabanı nesnesi 
     * @param string $customers_table Müşteri tablosu adı
     * @param array &$debug_info Debug bilgileri
     */
    public function process_xml($xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info);
}

/**
 * Sompo XML Format İşleyicisi
 */
class SompoXMLFormatHandler implements XMLFormatHandler {
    /**
     * XML'i işler ve poliçe/müşteri verilerini çıkarır (Sompo formatı)
     */
    public function process_xml($xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Yapı kontrolü
        if (isset($xml->ACENTEDATATRANSFERI) && isset($xml->ACENTEDATATRANSFERI->POLICE)) {
            // ACENTEDATATRANSFERI altındaki POLICE elementleri
            $debug_info['xml_structure'] = 'ACENTEDATATRANSFERI->POLICE';
            $policies = $xml->ACENTEDATATRANSFERI->POLICE;
            
            // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
            $policy_count = $policies->count();
            $debug_info['total_policies'] = $policy_count;
            
            // Her bir poliçeyi tek tek işle
            for ($i = 0; $i < $policy_count; $i++) {
                $policy_xml = $policies[$i];
                $this->process_policy_xml($policy_xml, $preview_data, $representative_id, $wpdb, $customers_table, $debug_info);
                $debug_info['processed_policies']++;
            }
            
            return true;
        } elseif (isset($xml->POLICE)) {
            // Doğrudan POLICE elementleri
            $debug_info['xml_structure'] = 'direct POLICE';
            $policies = $xml->POLICE;
            
            // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
            $policy_count = $policies->count();
            $debug_info['total_policies'] = $policy_count;
            
            // Her bir poliçeyi tek tek işle
            for ($i = 0; $i < $policy_count; $i++) {
                $policy_xml = $policies[$i];
                $this->process_policy_xml($policy_xml, $preview_data, $representative_id, $wpdb, $customers_table, $debug_info);
                $debug_info['processed_policies']++;
            }
            
            return true;
        }
        
        $debug_info['last_error'] = 'Sompo XML formatı algılanamadı';
        return false;
    }
    
    /**
     * Sompo XML poliçe işleme 
     * Mevcut süreçteki process_policy_xml fonksiyonuyla aynı
     */
    private function process_policy_xml($policy_xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Poliçe türü belirleme
        $policy_type_raw = (string)$policy_xml->Urun_Adi;
        $policy_type_map = array(
            'ZORUNLU MALİ SORUMLULUK' => 'Trafik',
            'TICARI GENİŞLETİLMİŞ KASKO' => 'Kasko',
        );
        $policy_type = isset($policy_type_map[$policy_type_raw]) ? $policy_type_map[$policy_type_raw] : 'Diğer';

        // Müşteri bilgilerini al
        $customer_name = (string)$policy_xml->Musteri_Adi;
        if (empty($customer_name)) {
            $customer_name = (string)$policy_xml->Sigortali_AdiSoyadi;
        }

        // Kullanılabilir müşteri adı yoksa, bu poliçeyi atlayalım
        if (empty($customer_name)) {
            $debug_info['failed_matches']++;
            $debug_info['last_error'] = 'Müşteri adı bulunamadı';
            return;
        }

        $customer_name = trim($customer_name);
        $customer_parts = preg_split('/\s+/', $customer_name, 2);
        $first_name = !empty($customer_parts[0]) ? $customer_parts[0] : 'Bilinmeyen';
        $last_name = !empty($customer_parts[1]) ? $customer_parts[1] : '';
        
        // Telefon numarası
        $phone = (string)$policy_xml->Sigortali_MobilePhone;
        if (empty($phone)) {
            $phone = (string)$policy_xml->Telefon;
        }
        
        // Adres bilgisini temizle
        $address_raw = (string)$policy_xml->Musteri_Adresi;
        $address = $this->clean_address($address_raw);
        
        // TC Kimlik numarasını al
        $tc_kimlik = (string)$policy_xml->TCKimlikNo;
        if (empty($tc_kimlik)) {
            $tc_kimlik = (string)$policy_xml->Musteri_TCKimlikNo;
        }
        if (empty($tc_kimlik)) {
            $tc_kimlik = (string)$policy_xml->Sigortali_TCKimlikNo;
        }
        
        // Doğum tarihi bilgisini al ve formatla (MySQL date format: YYYY-MM-DD)
        $birth_date = null;
        if (!empty($policy_xml->Musteri_Dogum_Tarihi)) {
            $birth_date_raw = (string)$policy_xml->Musteri_Dogum_Tarihi;
            $birth_date = date('Y-m-d', strtotime(str_replace('.', '-', $birth_date_raw)));
        }

        // Müşteriyi TC kimlik numarasına göre kontrol et
        $customer_id = null;
        if (!empty($tc_kimlik)) {
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE tc_identity = %s",
                $tc_kimlik
            ));
        }

        if (!$customer_id) {
            // TC kimliğe göre bulunamadıysa, isim ve telefona göre kontrol et
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
                $first_name,
                $last_name,
                $phone
            ));
        }

        $customer_status = $customer_id ? 'Mevcut' : 'Yeni';
        if ($customer_id) {
            $debug_info['matched_customers']++;
        }

        // Müşteri verisini ön izlemeye ekle
        $customer_key = md5($tc_kimlik . $first_name . $last_name . $phone);
        if (!isset($preview_data['customers'][$customer_key])) {
            $preview_data['customers'][$customer_key] = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'address' => $address, 
                'address_raw' => $address_raw,  // Debug için ham adresi de kaydediyoruz
                'tc_kimlik' => $tc_kimlik,
                'birth_date' => $birth_date,
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $representative_id
            );
        }

        // Poliçe verilerini hazırla
        $policy_number = (string)$policy_xml->Police_NO;
        $zeyl_no = (string)$policy_xml->Zeyl_NO;
        if (!empty($zeyl_no) && $zeyl_no != '0') {
            $policy_number .= '-' . $zeyl_no;
        }

        // Tarih formatlarını kontrol et ve düzelt
        $start_date_raw = (string)$policy_xml->PoliceBaslangicTarihi;
        $end_date_raw = (string)$policy_xml->PoliceBitisTarihi;
        
        // Tarih formatlarını düzenli hale getir (gün-ay-yıl formatından yıl-ay-gün'e)
        $start_date = date('Y-m-d', strtotime(str_replace('.', '-', $start_date_raw)));
        $end_date = date('Y-m-d', strtotime(str_replace('.', '-', $end_date_raw)));
        
        // Tüm poliçeleri aktif olarak işaretle
        $status = 'aktif';

        // Brut primi hesapla
        $premium_amount = 0;
        if (isset($policy_xml->BrutPrim)) {
            $premium_amount = floatval((string)$policy_xml->BrutPrim);
        }

        // Poliçe verisini ön izlemeye ekle
        $preview_data['policies'][] = array(
            'policy_number' => $policy_number,
            'customer_key' => $customer_key,
            'policy_type' => $policy_type,
            'insurance_company' => 'Sompo',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'premium_amount' => $premium_amount,
            'insured_party' => '', // Sigorta ettiren boş bırakıldı
            'status' => $status,
            'xml_fields' => array(
                'Urun_Adi' => (string)$policy_xml->Urun_Adi,
                'Police_NO' => (string)$policy_xml->Police_NO,
                'Zeyl_NO' => (string)$policy_xml->Zeyl_NO
            )
        );
    }
    
    /**
     * Adresten kredi kartı bilgilerini temizle
     */
    private function clean_address($address) {
        // Hiçbir adres yoksa boş döndür
        if (empty($address)) {
            return '';
        }
        
        // Kredi kartı numarası ve diğer finansal bilgileri kaldır
        // Daha kapsamlı regex desenleri eklendi
        $patterns = [
            // Kart numarası formatları
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?[\*]{4}[\s\-]?\d{4}\b/i',
            '/\b\d{6}[\*]{6}\d{4}\b/i',
            
            // Kart numaraları (başlangıç rakamına göre)
            '/\b5\d{3}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i', // Mastercard
            '/\b4\d{3}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i', // Visa
            
            // Etiketli kart bilgileri
            '/Kart[ _]?No[:\s]*[\d\*\s\-]+/i',
            '/Kart[ _]?Sahibi[:\s]*[^<>\/\n\r]+/i',
            '/Kart[_\s]Tahsil[_\s]Tarihi[:\s]*[^<>\/\n\r]+/i',
            
            // Finansal bilgiler
            '/BrutTutar[:\s]*[\-\d\.,]+/i',
            '/Tahsilat[_\s]Doviz[:\s]*[A-Z]+/i',
            '/Police[_\s]Doviz[:\s]*[A-Z]+/i',
            '/Police[_\s]Tutar[:\s]*[\-\d\.,]+/i',
            '/Tahsil[_\s]Tarihi[:\s]*[\d\.\-\/]+/i',
            '/TahsilatTutari[:\s]*[\d\.\,]+/i',
            
            // XML formatından gelen kart bilgisi
            '/\d{5}[\s\-]?\d{5}[\s\-]?\d{5}[\s\-]?\d{5}/i',
            '/\b\d{5}\s+\d{5}\s+\d{5}\b/i',
            
            // XML'deki diğer finansal bilgiler
            '/[\d\s]{15,30}/', // Uzun sayı dizileri (büyük olasılıkla kart numaraları)
            '/[\d]{5,6}\s[\d]{5,6}\s[\d]{5,6}/', // Boşlukla ayrılmış sayı grupları
        ];
        
        foreach ($patterns as $pattern) {
            $address = preg_replace($pattern, '[KART BİLGİSİ ÇIKARILDI]', $address);
        }
        
        // Ardışık 3 veya daha fazla boşlukları tek boşluğa indir ve trim
        $address = preg_replace('/\s{3,}/', ' ', $address);
        $address = preg_replace('/\s+/', ' ', $address);
        return trim($address);
    }
}

/**
 * Mapfre XML Format İşleyicisi
 * Not: Bu örnek bir şablon, gerçek Mapfre XML yapısına göre düzenlenmelidir
 */
class MapfreXMLFormatHandler implements XMLFormatHandler {
    /**
     * XML'i işler ve poliçe/müşteri verilerini çıkarır (Mapfre formatı)
     */
    public function process_xml($xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Yapı kontrolü
        if (isset($xml->POLICIES_DATA) && isset($xml->POLICIES_DATA->POLICY)) {
            // POLICIES_DATA altındaki POLICY elementleri
            $debug_info['xml_structure'] = 'POLICIES_DATA->POLICY';
            $policies = $xml->POLICIES_DATA->POLICY;
            
            // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
            $policy_count = $policies->count();
            $debug_info['total_policies'] = $policy_count;
            
            // Her bir poliçeyi tek tek işle
            for ($i = 0; $i < $policy_count; $i++) {
                $policy_xml = $policies[$i];
                $this->process_policy_xml($policy_xml, $preview_data, $representative_id, $wpdb, $customers_table, $debug_info);
                $debug_info['processed_policies']++;
            }
            
            return true;
        }
        
        $debug_info['last_error'] = 'Mapfre XML formatı algılanamadı';
        return false;
    }
    
    /**
     * Mapfre XML poliçe işleme 
     * Bu metod, Mapfre'nin XML yapısına göre özelleştirilmelidir
     */
    private function process_policy_xml($policy_xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Poliçe türü belirleme
        $policy_type_raw = (string)$policy_xml->POLICY_TYPE;
        $policy_type_map = array(
            'TRAFFIC' => 'Trafik',
            'CASCO' => 'Kasko',
        );
        $policy_type = isset($policy_type_map[$policy_type_raw]) ? $policy_type_map[$policy_type_raw] : 'Diğer';

        // Müşteri bilgilerini al - CUSTOMER ya da INSURED_CUSTOMER node'larından
        $customer_name = (string)$policy_xml->CUSTOMER->FULL_NAME;
        if (empty($customer_name)) {
            $customer_name = (string)$policy_xml->INSURED_CUSTOMER->FULL_NAME;
        }

        // Kullanılabilir müşteri adı yoksa, bu poliçeyi atlayalım
        if (empty($customer_name)) {
            $debug_info['failed_matches']++;
            $debug_info['last_error'] = 'Müşteri adı bulunamadı';
            return;
        }

        $customer_name = trim($customer_name);
        $customer_parts = preg_split('/\s+/', $customer_name, 2);
        $first_name = !empty($customer_parts[0]) ? $customer_parts[0] : 'Bilinmeyen';
        $last_name = !empty($customer_parts[1]) ? $customer_parts[1] : '';
        
        // Telefon numarası
        $phone = (string)$policy_xml->CUSTOMER->PHONE;
        if (empty($phone)) {
            $phone = (string)$policy_xml->CUSTOMER->MOBILE;
        }
        
        // Adres bilgisini temizle
        $address_raw = (string)$policy_xml->CUSTOMER->ADDRESS;
        $address = $this->clean_address($address_raw);
        
        // TC Kimlik numarasını al
        $tc_kimlik = (string)$policy_xml->CUSTOMER->ID_NUMBER;
        
        // Doğum tarihi bilgisini al ve formatla (MySQL date format: YYYY-MM-DD)
        $birth_date = null;
        if (!empty($policy_xml->CUSTOMER->BIRTH_DATE)) {
            $birth_date_raw = (string)$policy_xml->CUSTOMER->BIRTH_DATE;
            $birth_date = date('Y-m-d', strtotime($birth_date_raw));
        }

        // Müşteriyi TC kimlik numarasına göre kontrol et
        $customer_id = null;
        if (!empty($tc_kimlik)) {
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE tc_identity = %s",
                $tc_kimlik
            ));
        }

        if (!$customer_id) {
            // TC kimliğe göre bulunamadıysa, isim ve telefona göre kontrol et
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
                $first_name,
                $last_name,
                $phone
            ));
        }

        $customer_status = $customer_id ? 'Mevcut' : 'Yeni';
        if ($customer_id) {
            $debug_info['matched_customers']++;
        }

        // Müşteri verisini ön izlemeye ekle
        $customer_key = md5($tc_kimlik . $first_name . $last_name . $phone);
        if (!isset($preview_data['customers'][$customer_key])) {
            $preview_data['customers'][$customer_key] = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'address' => $address, 
                'address_raw' => $address_raw,
                'tc_kimlik' => $tc_kimlik,
                'birth_date' => $birth_date,
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $representative_id
            );
        }

        // Poliçe verilerini hazırla
        $policy_number = (string)$policy_xml->POLICY_NUMBER;
        $revision = (string)$policy_xml->REVISION;
        if (!empty($revision) && $revision != '0') {
            $policy_number .= '-' . $revision;
        }

        // Tarih formatlarını kontrol et ve düzelt
        $start_date = (string)$policy_xml->START_DATE;
        $end_date = (string)$policy_xml->END_DATE;
        
        // Tüm poliçeleri aktif olarak işaretle
        $status = 'aktif';

        // Brut primi hesapla
        $premium_amount = 0;
        if (isset($policy_xml->PREMIUM)) {
            $premium_amount = floatval((string)$policy_xml->PREMIUM);
        }

        // Poliçe verisini ön izlemeye ekle
        $preview_data['policies'][] = array(
            'policy_number' => $policy_number,
            'customer_key' => $customer_key,
            'policy_type' => $policy_type,
            'insurance_company' => 'Mapfre',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'premium_amount' => $premium_amount,
            'insured_party' => (string)$policy_xml->INSURED_NAME, // Sigorta ettiren
            'status' => $status,
            'xml_fields' => array(
                'POLICY_TYPE' => (string)$policy_xml->POLICY_TYPE,
                'POLICY_NUMBER' => (string)$policy_xml->POLICY_NUMBER,
                'REVISION' => (string)$policy_xml->REVISION
            )
        );
    }
    
    /**
     * Adresten kredi kartı bilgilerini temizle (Sompo sınıfıyla aynı)
     */
    private function clean_address($address) {
        // Hiçbir adres yoksa boş döndür
        if (empty($address)) {
            return '';
        }
        
        // Kredi kartı numarası ve diğer finansal bilgileri kaldır
        $patterns = [
            // Kart numarası formatları
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?[\*]{4}[\s\-]?\d{4}\b/i',
            '/\b\d{6}[\*]{6}\d{4}\b/i',
            
            // Aynı panel kodu
        ];
        
        foreach ($patterns as $pattern) {
            $address = preg_replace($pattern, '[KART BİLGİSİ ÇIKARILDI]', $address);
        }
        
        $address = preg_replace('/\s{3,}/', ' ', $address);
        $address = preg_replace('/\s+/', ' ', $address);
        return trim($address);
    }
}

/**
 * Allianz XML Format İşleyicisi
 * Not: Bu örnek bir şablon, gerçek Allianz XML yapısına göre düzenlenmelidir
 */
class AllianzXMLFormatHandler implements XMLFormatHandler {
    /**
     * XML'i işler ve poliçe/müşteri verilerini çıkarır (Allianz formatı)
     */
    public function process_xml($xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Yapı kontrolü
        if (isset($xml->ALLIANZ_POLICIES) && isset($xml->ALLIANZ_POLICIES->POLICY_DATA)) {
            // ALLIANZ_POLICIES altındaki POLICY_DATA elementleri
            $debug_info['xml_structure'] = 'ALLIANZ_POLICIES->POLICY_DATA';
            $policies = $xml->ALLIANZ_POLICIES->POLICY_DATA;
            
            // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
            $policy_count = $policies->count();
            $debug_info['total_policies'] = $policy_count;
            
            // Her bir poliçeyi tek tek işle
            for ($i = 0; $i < $policy_count; $i++) {
                $policy_xml = $policies[$i];
                $this->process_policy_xml($policy_xml, $preview_data, $representative_id, $wpdb, $customers_table, $debug_info);
                $debug_info['processed_policies']++;
            }
            
            return true;
        }
        
        $debug_info['last_error'] = 'Allianz XML formatı algılanamadı';
        return false;
    }
    
    /**
     * Allianz XML poliçe işleme 
     * Bu metod, Allianz'ın XML yapısına göre özelleştirilmelidir
     */
    private function process_policy_xml($policy_xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Poliçe türü belirleme - PRODUCT node'undan
        $policy_type_raw = (string)$policy_xml->PRODUCT;
        $policy_type_map = array(
            'TRAFFIC_INSURANCE' => 'Trafik',
            'MOTOR_CASCO' => 'Kasko',
            'HOME_INSURANCE' => 'Konut',
        );
        $policy_type = isset($policy_type_map[$policy_type_raw]) ? $policy_type_map[$policy_type_raw] : 'Diğer';

        // Müşteri bilgilerini al - INSURED düğümünden
        $customer = $policy_xml->INSURED;
        $customer_name = (string)$customer->NAME . ' ' . (string)$customer->SURNAME;

        // Kullanılabilir müşteri adı yoksa, bu poliçeyi atlayalım
        if (empty(trim($customer_name))) {
            $debug_info['failed_matches']++;
            $debug_info['last_error'] = 'Müşteri adı bulunamadı';
            return;
        }

        $first_name = (string)$customer->NAME;
        $last_name = (string)$customer->SURNAME;
        
        // Telefon numarası
        $phone = (string)$customer->MOBILE_PHONE;
        if (empty($phone)) {
            $phone = (string)$customer->PHONE;
        }
        
        // Adres bilgisini temizle
        $address_raw = (string)$customer->ADDRESS;
        $address = $this->clean_address($address_raw);
        
        // TC Kimlik numarasını al
        $tc_kimlik = (string)$customer->ID_NUMBER;
        
        // Doğum tarihi bilgisini al ve formatla (MySQL date format: YYYY-MM-DD)
        $birth_date = null;
        if (!empty($customer->BIRTH_DATE)) {
            $birth_date_raw = (string)$customer->BIRTH_DATE;
            $birth_date = date('Y-m-d', strtotime($birth_date_raw));
        }

        // Müşteriyi TC kimlik numarasına göre kontrol et
        $customer_id = null;
        if (!empty($tc_kimlik)) {
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE tc_identity = %s",
                $tc_kimlik
            ));
        }

        if (!$customer_id) {
            // TC kimliğe göre bulunamadıysa, isim ve telefona göre kontrol et
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
                $first_name,
                $last_name,
                $phone
            ));
        }

        $customer_status = $customer_id ? 'Mevcut' : 'Yeni';
        if ($customer_id) {
            $debug_info['matched_customers']++;
        }

        // Müşteri verisini ön izlemeye ekle
        $customer_key = md5($tc_kimlik . $first_name . $last_name . $phone);
        if (!isset($preview_data['customers'][$customer_key])) {
            $preview_data['customers'][$customer_key] = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'address' => $address, 
                'address_raw' => $address_raw,
                'tc_kimlik' => $tc_kimlik,
                'birth_date' => $birth_date,
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $representative_id
            );
        }

        // Poliçe verilerini hazırla
        $policy_number = (string)$policy_xml->POLICY_NUMBER;
        $endorsement = (string)$policy_xml->ENDORSEMENT;
        if (!empty($endorsement) && $endorsement != '0') {
            $policy_number .= '-' . $endorsement;
        }

        // Tarih formatlarını kontrol et ve düzelt
        $start_date = (string)$policy_xml->POLICY_START_DATE;
        $end_date = (string)$policy_xml->POLICY_END_DATE;
        
        // Tüm poliçeleri aktif olarak işaretle
        $status = 'aktif';

        // Brut primi hesapla
        $premium_amount = 0;
        if (isset($policy_xml->GROSS_PREMIUM)) {
            $premium_amount = floatval((string)$policy_xml->GROSS_PREMIUM);
        }

        // Poliçe verisini ön izlemeye ekle
        $preview_data['policies'][] = array(
            'policy_number' => $policy_number,
            'customer_key' => $customer_key,
            'policy_type' => $policy_type,
            'insurance_company' => 'Allianz',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'premium_amount' => $premium_amount,
            'insured_party' => (string)$policy_xml->POLICYHOLDER->NAME . ' ' . (string)$policy_xml->POLICYHOLDER->SURNAME, // Sigorta ettiren
            'status' => $status,
            'xml_fields' => array(
                'PRODUCT' => (string)$policy_xml->PRODUCT,
                'POLICY_NUMBER' => (string)$policy_xml->POLICY_NUMBER,
                'ENDORSEMENT' => (string)$policy_xml->ENDORSEMENT
            )
        );
    }
    
    /**
     * Adresten kredi kartı bilgilerini temizle (diğerleriyle aynı)
     */
    private function clean_address($address) {
        // Aynı temizleme fonksiyonu
        if (empty($address)) {
            return '';
        }
        
        $patterns = [
            // Aynı temizleme patternleri
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
        ];
        
        foreach ($patterns as $pattern) {
            $address = preg_replace($pattern, '[KART BİLGİSİ ÇIKARILDI]', $address);
        }
        
        $address = preg_replace('/\s{3,}/', ' ', $address);
        $address = preg_replace('/\s+/', ' ', $address);
        return trim($address);
    }
}

/**
 * Özel XML Format İşleyicisi
 * Bu sınıf, kullanıcı tarafından tanımlanan XML şablonlarını işler
 */
class CustomXMLFormatHandler implements XMLFormatHandler {
    private $format;
    
    /**
     * Constructor
     * 
     * @param array $format Format tanımlama bilgileri
     */
    public function __construct($format) {
        $this->format = $format;
    }
    
    /**
     * XML'i işler ve poliçe/müşteri verilerini çıkarır
     */
    public function process_xml($xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Yapı kontrolü
        if (!isset($this->format['test_node'])) {
            $debug_info['last_error'] = 'Özel şablon için test düğümü belirtilmemiş';
            return false;
        }
        
        // Test node'u XPath formatında parçala
        $test_path_parts = explode('/', $this->format['test_node']);
        $current_node = $xml;
        
        foreach ($test_path_parts as $node_name) {
            if (empty($node_name)) continue;
            
            if (isset($current_node->$node_name)) {
                $current_node = $current_node->$node_name;
            } else {
                $debug_info['last_error'] = "Özel şablon için belirtilen düğüm bulunamadı: $node_name";
                return false;
            }
        }
        
        // Poliçe elementlerinin bulunduğu düğüme ulaştık
        $policies = $current_node;
        $policy_count = $policies->count();
        $debug_info['total_policies'] = $policy_count;
        $debug_info['xml_structure'] = $this->format['test_node'];
        
        // Her bir poliçeyi özel alan eşleştirmelerine göre işle
        for ($i = 0; $i < $policy_count; $i++) {
            $policy_xml = $policies[$i];
            $this->process_policy_xml($policy_xml, $preview_data, $representative_id, $wpdb, $customers_table, $debug_info);
            $debug_info['processed_policies']++;
        }
        
        return true;
    }
    
    /**
     * Özel XML poliçe işleme
     */
    private function process_policy_xml($policy_xml, &$preview_data, $representative_id, $wpdb, $customers_table, &$debug_info) {
        // Formatın alan eşleştirmelerini kullanarak değerleri al
        $mappings = $this->format['field_mappings'];
        
        // Ad ve soyad
        $first_name = $this->get_value_from_xml($policy_xml, $mappings, 'first_name', 'Bilinmeyen');
        $last_name = $this->get_value_from_xml($policy_xml, $mappings, 'last_name', '');
        
        // Eğer isim alanları ayrı değil, tam isim birleşikse
        if (isset($mappings['full_name']) && empty($first_name) && empty($last_name)) {
            $full_name = $this->get_value_from_xml($policy_xml, $mappings, 'full_name', '');
            if (!empty($full_name)) {
                $name_parts = preg_split('/\s+/', $full_name, 2);
                $first_name = !empty($name_parts[0]) ? $name_parts[0] : 'Bilinmeyen';
                $last_name = !empty($name_parts[1]) ? $name_parts[1] : '';
            }
        }
        
        // Telefon
        $phone = $this->get_value_from_xml($policy_xml, $mappings, 'phone', '');
        
        // Adres
        $address_raw = $this->get_value_from_xml($policy_xml, $mappings, 'address', '');
        $address = $this->clean_address($address_raw);
        
        // TC Kimlik
        $tc_kimlik = $this->get_value_from_xml($policy_xml, $mappings, 'tc_kimlik', '');
        
        // Doğum tarihi
        $birth_date = null;
        $birth_date_raw = $this->get_value_from_xml($policy_xml, $mappings, 'birth_date', '');
        if (!empty($birth_date_raw)) {
            $birth_date = date('Y-m-d', strtotime($birth_date_raw));
        }
        
        // Müşteriyi TC kimlik numarasına göre kontrol et
        $customer_id = null;
        if (!empty($tc_kimlik)) {
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE tc_identity = %s",
                $tc_kimlik
            ));
        }

        if (!$customer_id) {
            // TC kimliğe göre bulunamadıysa, isim ve telefona göre kontrol et
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
                $first_name,
                $last_name,
                $phone
            ));
        }

        $customer_status = $customer_id ? 'Mevcut' : 'Yeni';
        if ($customer_id) {
            $debug_info['matched_customers']++;
        }

        // Müşteri verisini ön izlemeye ekle
        $customer_key = md5($tc_kimlik . $first_name . $last_name . $phone);
        if (!isset($preview_data['customers'][$customer_key])) {
            $preview_data['customers'][$customer_key] = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'address' => $address, 
                'address_raw' => $address_raw,
                'tc_kimlik' => $tc_kimlik,
                'birth_date' => $birth_date,
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $representative_id
            );
        }

        // Poliçe bilgileri
        $policy_number = $this->get_value_from_xml($policy_xml, $mappings, 'policy_number', '');
        $endorsement = $this->get_value_from_xml($policy_xml, $mappings, 'endorsement', '');
        
        if (!empty($endorsement) && $endorsement != '0') {
            $policy_number .= '-' . $endorsement;
        }

        $start_date = $this->get_value_from_xml($policy_xml, $mappings, 'start_date', '');
        $end_date = $this->get_value_from_xml($policy_xml, $mappings, 'end_date', '');
        
        // Poliçe türü
        $policy_type_raw = $this->get_value_from_xml($policy_xml, $mappings, 'policy_type', '');
        $policy_type = $policy_type_raw;
        
        // Eğer şablonda bir tip eşleştirme tablosu tanımlanmışsa kullan
        if (isset($this->format['type_mappings']) && !empty($this->format['type_mappings'][$policy_type_raw])) {
            $policy_type = $this->format['type_mappings'][$policy_type_raw];
        }
        
        // Prim tutarı
        $premium_amount = 0;
        $premium_raw = $this->get_value_from_xml($policy_xml, $mappings, 'premium', '0');
        if (!empty($premium_raw)) {
            $premium_amount = floatval($premium_raw);
        }
        
        // Sigorta şirketi
        $insurance_company = isset($this->format['insurance_company']) ? $this->format['insurance_company'] : 'Diğer';
        
        // Sigorta ettiren
        $insured_party = $this->get_value_from_xml($policy_xml, $mappings, 'insured_party', '');

        // Poliçe verisini ön izlemeye ekle
        $preview_data['policies'][] = array(
            'policy_number' => $policy_number,
            'customer_key' => $customer_key,
            'policy_type' => $policy_type,
            'insurance_company' => $insurance_company,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'premium_amount' => $premium_amount,
            'insured_party' => $insured_party,
            'status' => 'aktif',
            'xml_fields' => array(
                'policy_type' => $policy_type_raw,
                'policy_number' => $policy_number,
                'endorsement' => $endorsement
            )
        );
    }
    
    /**
     * XML'den değer alma
     * 
     * @param SimpleXMLElement $xml XML düğümü
     * @param array $mappings Alan eşleştirmeleri
     * @param string $field_name Alan adı
     * @param mixed $default Varsayılan değer
     * @return string Bulunan değer
     */
    private function get_value_from_xml($xml, $mappings, $field_name, $default = '') {
        if (!isset($mappings[$field_name]) || empty($mappings[$field_name])) {
            return $default;
        }
        
        $xpath = $mappings[$field_name];
        $path_parts = explode('/', $xpath);
        $current_node = $xml;
        
        foreach ($path_parts as $node_name) {
            if (empty($node_name)) continue;
            
            if (isset($current_node->$node_name)) {
                $current_node = $current_node->$node_name;
            } else {
                return $default;
            }
        }
        
        return (string)$current_node ?: $default;
    }
    
    /**
     * Adresten kredi kartı bilgilerini temizle
     */
    private function clean_address($address) {
        // Ortak temizleme fonksiyonu
        if (empty($address)) {
            return '';
        }
        
        $patterns = [
            // Temizleme patternleri
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
            // Diğer temizleme patternleri...
        ];
        
        foreach ($patterns as $pattern) {
            $address = preg_replace($pattern, '[KART BİLGİSİ ÇIKARILDI]', $address);
        }
        
        $address = preg_replace('/\s{3,}/', ' ', $address);
        $address = preg_replace('/\s+/', ' ', $address);
        return trim($address);
    }
}