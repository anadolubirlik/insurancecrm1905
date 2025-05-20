<?php
/**
 * Frontend Poliçe Yönetim Sayfası
 * @version 3.0.0 - Bütün sorunlar çözüldü
 */

include_once(dirname(__FILE__) . '/template-colors.php');

// Kullanıcı oturum kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Veritabanı tablolarını tanımlama
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// insured_party sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Müşteri tablosunda tc_identity sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $customers_table LIKE 'tc_identity'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $customers_table ADD COLUMN tc_identity VARCHAR(20) DEFAULT NULL AFTER last_name");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Müşteri tablosunda birth_date sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $customers_table LIKE 'birth_date'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $customers_table ADD COLUMN birth_date DATE DEFAULT NULL AFTER tc_identity");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Müşteri tablosunda representative_id sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $customers_table LIKE 'representative_id'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $customers_table ADD COLUMN representative_id INT DEFAULT NULL AFTER address");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// İptal işlemleri için yeni sütunlar kontrolü ve ekleme
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

// Mevcut kullanıcı temsilcisi ID'sini alma
function get_current_user_rep_id() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'", $current_user_id));
}
$current_user_rep_id = get_current_user_rep_id();

// Bildirim mesajı
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// XML veri işleme debug bilgilerini tutan array
$debug_info = array(
    'total_policies' => 0,
    'processed_policies' => 0,
    'matched_customers' => 0,
    'failed_matches' => 0,
    'last_error' => ''
);

/**
 * Adresten kredi kartı ve benzeri bilgileri temizleyen fonksiyon
 * ÖNEMLİ: BU FONKSİYON GÜNCELLENDİ - DAHA KAPSAMLI TEMİZLEME UYGULANIR
 *
 * @param string $address Temizlenecek adres
 * @return string Temizlenmiş adres
 */
function clean_address($address) {
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

// XML Yükleme ve Ön İzleme
$preview_data = null;
if (isset($_POST['preview_xml']) && isset($_POST['xml_import_nonce']) && wp_verify_nonce($_POST['xml_import_nonce'], 'xml_import_action')) {
    if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['xml_file']['tmp_name'];
        $file_type = mime_content_type($file_tmp);

        // Sadece XML dosyalarına izin ver
        if ($file_type !== 'text/xml' && $file_type !== 'application/xml') {
            $notice = '<div class="ab-notice ab-error">Lütfen geçerli bir XML dosyası yükleyin.</div>';
        } else {
            $xml_content = file_get_contents($file_tmp);
            // BOM karakterini temizleme (UTF-8 BOM sorunlarını önler)
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
                $notice = '<div class="ab-notice ab-error">' . $error_msg . '</div>';
            } else {
                $preview_data = array(
                    'policies' => array(),
                    'customers' => array(),
                );

                $processed_policies = 0;
                
                // Debug işlem başlangıç saati
                $debug_info['process_start'] = date('Y-m-d H:i:s'); 
                $debug_info['xml_structure'] = '';
                
                // XML yapısını belirle
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
                        process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                        $processed_policies++;
                    }
                    
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
                        process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                        $processed_policies++;
                    }
                    
                } else {
                    // Hiçbir bilinen yapı bulamazsak, farklı bir XML ağacı olabilir, tüm yapıyı tara
                    $debug_info['xml_structure'] = 'unknown structure, scanning';
                    $found_policies = false;
                    
                    // Bilinen tüm yapıları dene
                    foreach ($xml->children() as $tag_name => $node) {
                        if (strtoupper($tag_name) == 'POLICE' || $tag_name == 'POLICE') {
                            // İlk seviyede POLICE elementi
                            $debug_info['xml_structure'] = 'first level as ' . $tag_name;
                            $policies = $xml->{$tag_name};
                            $policy_count = $policies->count();
                            $debug_info['total_policies'] = $policy_count;
                            
                            for ($i = 0; $i < $policy_count; $i++) {
                                $policy_xml = $policies[$i];
                                process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                                $processed_policies++;
                            }
                            
                            $found_policies = true;
                            break;
                        }
                        
                        // İkinci seviyede POLICE elementi
                        foreach ($node->children() as $child_name => $child) {
                            if (strtoupper($child_name) == 'POLICE' || $child_name == 'POLICE') {
                                $debug_info['xml_structure'] = $tag_name . '->' . $child_name;
                                $policies = $xml->{$tag_name}->{$child_name};
                                $policy_count = $policies->count();
                                $debug_info['total_policies'] = $policy_count;
                                
                                for ($i = 0; $i < $policy_count; $i++) {
                                    $policy_xml = $policies[$i];
                                    process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                                    $processed_policies++;
                                }
                                
                                $found_policies = true;
                                break 2; // İç ve dış döngüden çık
                            }
                        }
                    }
                    
                    if (!$found_policies) {
                        $notice = '<div class="ab-notice ab-error">XML dosyası beklenen formatta değil. Poliçe bilgileri bulunamadı.</div>';
                        $preview_data = null;
                    }
                }
                
                // Hiç poliçe işlenmemişse hata ver
                if ($processed_policies === 0 && $preview_data !== null) {
                    $notice = '<div class="ab-notice ab-error">XML dosyası okundu, ancak hiçbir poliçe bilgisi bulunamadı. Sebep: ' . $debug_info['last_error'] . '</div>';
                    $preview_data = null;
                } else {
                    // İşlem başarılı - Debug bilgisini ekle
                    $debug_info['processed_policies'] = $processed_policies;
                    $preview_data['debug'] = $debug_info;
                }
            }
        }
    } else {
        $notice = '<div class="ab-notice ab-error">Lütfen bir XML dosyası seçin.</div>';
    }
}

// XML'den poliçe işleme fonksiyonu - ÖNEMLİ: GÜNCELLEME YAPILDI
function process_policy_xml($policy_xml, &$preview_data, $current_user_rep_id, $wpdb, $customers_table, &$debug_info) {
    // Poliçe türü belirleme
    $policy_type_raw = (string)$policy_xml->Urun_Adi;
    $policy_type_map = array(
        'ZORUNLU MALİ SORUMLULUK' => 'Trafik',
        'TICARI GENİŞLETİLMİŞ KASKO' => 'Kasko',
    );
    $policy_type = isset($policy_type_map[$policy_type_raw]) ? $policy_type_map[$policy_type_raw] : 'Diğer';

    // Müşteri bilgilerini al - Sigortali_AdiSoyadi veya Musteri_Adi kullan
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
    
    // Adres bilgisini temizle (kredi kartı ve diğer finansal bilgileri kaldır)
    $address_raw = (string)$policy_xml->Musteri_Adresi;
    $address = clean_address($address_raw);
    
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

    // ÖNEMLİ DÜZELTME: Müşteriyi TC kimlik numarasına göre kontrol et
    // tc_identity sütununu kullanıyoruz, identification_number DEĞİL!
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
            'representative_id' => $current_user_rep_id
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

// XML Onaylama ve Aktarma
if (isset($_POST['confirm_xml']) && isset($_POST['xml_confirm_nonce']) && wp_verify_nonce($_POST['xml_confirm_nonce'], 'xml_confirm_action')) {
    $selected_policies = isset($_POST['selected_policies']) ? array_map('sanitize_text_field', $_POST['selected_policies']) : array();
    $preview_data = isset($_POST['preview_data']) ? json_decode(stripslashes($_POST['preview_data']), true) : null;

    if (!$preview_data || empty($selected_policies)) {
        $notice = '<div class="ab-notice ab-error">Aktarılacak veri bulunamadı veya hiçbir poliçe seçilmedi.</div>';
    } else {
        $success_count = 0;
        $error_count = 0;
        $customer_success = 0;

        // Önce tüm müşterileri oluştur veya güncelle
        $customer_ids = array();
        foreach ($preview_data['customers'] as $customer_key => $customer) {
            // Müşteriyi kontrol et veya oluştur
            $existing_customer_id = $customer['customer_id'];
            if (!$existing_customer_id) {
                $customer_insert_data = array(
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'phone' => $customer['phone'],
                    'address' => $customer['address'],
                    'representative_id' => $current_user_rep_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                );
                
                // ÖNEMLİ DÜZELTME: TC kimlik numarasını tc_identity sütununa ekle
                if (!empty($customer['tc_kimlik'])) {
                    $customer_insert_data['tc_identity'] = $customer['tc_kimlik'];
                }
                
                // Doğum tarihini ekle
                if (!empty($customer['birth_date'])) {
                    $customer_insert_data['birth_date'] = $customer['birth_date'];
                }
                
                $result = $wpdb->insert($customers_table, $customer_insert_data);
                if ($result !== false) {
                    $customer_ids[$customer_key] = $wpdb->insert_id;
                    $customer_success++;
                } else {
                    $customer_ids[$customer_key] = null;
                    $error_count++;
                }
            } else {
                // Mevcut müşteriyi güncelle
                $customer_update_data = array(
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'phone' => $customer['phone'],
                    'address' => $customer['address'],
                    'updated_at' => current_time('mysql'),
                );
                
                // ÖNEMLİ DÜZELTME: TC kimlik numarasını tc_identity sütununa ekle/güncelle
                if (!empty($customer['tc_kimlik'])) {
                    $customer_update_data['tc_identity'] = $customer['tc_kimlik'];
                }
                
                // Doğum tarihini ekle/güncelle
                if (!empty($customer['birth_date'])) {
                    $customer_update_data['birth_date'] = $customer['birth_date'];
                }
                
                // Müşteri temsilcisi atanmamışsa ata
                $has_representative = $wpdb->get_var($wpdb->prepare(
                    "SELECT representative_id FROM $customers_table WHERE id = %d",
                    $existing_customer_id
                ));
                
                if (empty($has_representative)) {
                    $customer_update_data['representative_id'] = $current_user_rep_id;
                }
                
                $wpdb->update(
                    $customers_table,
                    $customer_update_data,
                    array('id' => $existing_customer_id)
                );
                $customer_ids[$customer_key] = $existing_customer_id;
            }
        }

        // Şimdi poliçeleri oluştur
        foreach ($preview_data['policies'] as $index => $policy_data) {
            // ÖNEMLİ DÜZELTME: String karşılaştırması yerine integer karşılaştırması
            $index_int = intval($index);
            if (!in_array((string)$index, $selected_policies) && !in_array($index_int, $selected_policies)) {
                continue; // Seçilmemiş poliçeleri atla
            }

            // Müşteri ID'sini al
            $customer_key = $policy_data['customer_key'];
            $customer_id = isset($customer_ids[$customer_key]) ? $customer_ids[$customer_key] : null;
            
            if (!$customer_id) {
                $error_count++;
                continue; // Müşteri oluşturulamadıysa poliçeyi atla
            }

            // Poliçeyi kontrol et (aynı poliçe numarası varsa güncelle)
            $existing_policy = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $policies_table WHERE policy_number = %s",
                $policy_data['policy_number']
            ));

            // Poliçe verilerini hazırla
            $policy_insert_data = array(
                'policy_number' => $policy_data['policy_number'],
                'customer_id' => $customer_id,
                'representative_id' => $current_user_rep_id,
                'policy_type' => $policy_data['policy_type'],
                'insurance_company' => $policy_data['insurance_company'],
                'start_date' => $policy_data['start_date'],
                'end_date' => $policy_data['end_date'],
                'premium_amount' => $policy_data['premium_amount'],
                'insured_party' => $policy_data['insured_party'],
                'status' => $policy_data['status'],
                'updated_at' => current_time('mysql'),
            );

            if ($existing_policy) {
                // Poliçeyi güncelle
                $result = $wpdb->update(
                    $policies_table, 
                    $policy_insert_data,
                    array('id' => $existing_policy->id)
                );
            } else {
                // Yeni poliçe ekle
                $policy_insert_data['created_at'] = current_time('mysql');
                $result = $wpdb->insert($policies_table, $policy_insert_data);
            }

            if ($result !== false) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        $notice = '<div class="ab-notice ab-success">' . $success_count . ' poliçe başarıyla aktarıldı. ' . $customer_success . ' yeni müşteri eklendi.';
        if ($error_count > 0) {
            $notice .= ' ' . $error_count . ' işlemde hata oluştu.';
        }
        $notice .= '</div>';

        // Ön izleme verisini sıfırla
        $preview_data = null;
    }

    $_SESSION['crm_notice'] = $notice;
    echo '<script>window.location.href = "' . esc_url('?view=policies') . '";</script>';
    exit;
}

// Silme/İptal işlemi - İptal sayfasına yönlendirme
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $policy_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_policy_' . $policy_id)) {
        echo '<script>window.location.href = "?view=policies&action=cancel&id=' . $policy_id . '";</script>';
        exit;
    }
}

// Filtreleme için GET parametrelerini al ve sanitize et
$filters = array(
    'policy_number' => isset($_GET['policy_number']) ? sanitize_text_field($_GET['policy_number']) : '',
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : '',
    'policy_type' => isset($_GET['policy_type']) ? sanitize_text_field($_GET['policy_type']) : '',
    'insurance_company' => isset($_GET['insurance_company']) ? sanitize_text_field($_GET['insurance_company']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'insured_party' => isset($_GET['insured_party']) ? sanitize_text_field($_GET['insured_party']) : '',
);

// Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// Temel sorguyu oluştur
$base_query = "FROM $policies_table p 
               LEFT JOIN $customers_table c ON p.customer_id = c.id
               LEFT JOIN $representatives_table r ON p.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Yetki kontrolü
if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id) {
    $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
}

// Filtreleme kriterlerini sorguya ekle
if (!empty($filters['policy_number'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_number LIKE %s", '%' . $wpdb->esc_like($filters['policy_number']) . '%');
}
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND p.customer_id = %d", $filters['customer_id']);
}
if (!empty($filters['policy_type'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_type = %s", $filters['policy_type']);
}
if (!empty($filters['insurance_company'])) {
    $base_query .= $wpdb->prepare(" AND p.insurance_company = %s", $filters['insurance_company']);
}
if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND p.status = %s", $filters['status']);
}
if (!empty($filters['insured_party'])) {
    $base_query .= $wpdb->prepare(" AND p.insured_party LIKE %s", '%' . $wpdb->esc_like($filters['insured_party']) . '%');
}

// Toplam kayıt sayısını al
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT p.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'p.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Filtrelenmiş poliçe listesini al - tc_identity eklenmiş haliyle
$policies = $wpdb->get_results("
    SELECT p.*, 
           c.first_name, c.last_name, c.tc_identity,
           u.display_name as representative_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Diğer gerekli veriler
$settings = get_option('insurance_crm_settings');
// Eğer Sompo şirketlerde yoksa, ekle
$insurance_companies = isset($settings['insurance_companies']) ? $settings['insurance_companies'] : array();
if (!in_array('Sompo', $insurance_companies)) {
    // Eğer SOMPO varsa kaldır, Sompo ekle
    $key = array_search('SOMPO', $insurance_companies);
    if ($key !== false) {
        unset($insurance_companies[$key]);
    }
    $insurance_companies[] = 'Sompo';
    $settings['insurance_companies'] = array_values($insurance_companies);
    update_option('insurance_crm_settings', $settings);
}

$policy_types = isset($settings['default_policy_types']) ? $settings['default_policy_types'] : array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer');
$customers = $wpdb->get_results("SELECT id, first_name, last_name FROM $customers_table ORDER BY first_name, last_name");
$total_pages = ceil($total_items / $per_page);

$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new' && $current_action !== 'renew' && $current_action !== 'cancel');

// Güncel tarih bilgisi
$current_date = date('Y-m-d H:i:s');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container" id="policies-list-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    <?php echo $notice; ?>

    <?php if ($preview_data): ?>
    <!-- XML Ön İzleme Ekranı -->
    <div class="ab-crm-preview">
        <h2>XML Ön İzleme</h2>
        <p>Yüklenen XML dosyasındaki veriler aşağıda listelenmiştir. Aktarmak istediğiniz poliçeleri seçin ve onaylayın.</p>

        <!-- Debug Bilgileri -->
        <?php if (isset($preview_data['debug'])): ?>
        <div class="ab-debug-info">
            <p><strong>İşlem Bilgileri:</strong> XML'de <?php echo $preview_data['debug']['total_policies']; ?> poliçe bulundu, 
               <?php echo $preview_data['debug']['processed_policies']; ?> poliçe işlendi, 
               <?php echo $preview_data['debug']['matched_customers']; ?> mevcut müşteri eşleştirildi. 
               XML yapısı: <?php echo $preview_data['debug']['xml_structure']; ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Yeni Poliçeler -->
        <h3>Yeni Poliçeler (<?php echo count($preview_data['policies']); ?>)</h3>
        <form method="post" id="xml-confirm-form" class="ab-filter-form">
            <?php wp_nonce_field('xml_confirm_action', 'xml_confirm_nonce'); ?>
            <input type="hidden" name="preview_data" value="<?php echo esc_attr(json_encode($preview_data)); ?>">
            <div class="ab-crm-table-wrapper">
                <table class="ab-crm-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-policies" checked></th>
                            <th>Poliçe No</th>
                            <th>Müşteri</th>
                            <th>Poliçe Türü</th>
                            <th>Sigorta Firması</th>
                            <th>Başlangıç</th>
                            <th>Bitiş</th>
                            <th>Prim (₺)</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_data['policies'] as $index => $policy): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_policies[]" value="<?php echo $index; ?>" checked></td>
                                <td><?php echo esc_html($policy['policy_number']); ?></td>
                                <td>
                                    <?php
                                    $customer = $preview_data['customers'][$policy['customer_key']];
                                    echo esc_html($customer['first_name'] . ' ' . $customer['last_name']);
                                    if ($customer['status'] === 'Yeni') {
                                        echo ' <span class="ab-badge ab-badge-warning">Yeni</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($policy['policy_type']); ?></td>
                                <td><?php echo esc_html($policy['insurance_company']); ?></td>
                                <td><?php echo date('d.m.Y', strtotime($policy['start_date'])); ?></td>
                                <td><?php echo date('d.m.Y', strtotime($policy['end_date'])); ?></td>
                                <td><?php echo number_format($policy['premium_amount'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="ab-badge ab-badge-status-aktif">Aktif</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Yeni Müşteriler -->
            <h3>Yeni Müşteriler (<?php echo count(array_filter($preview_data['customers'], function($c) { return $c['status'] === 'Yeni'; })); ?>)</h3>
            <div class="ab-crm-table-wrapper">
                <table class="ab-crm-table">
                    <thead>
                        <tr>
                            <th>Ad</th>
                            <th>Soyad</th>
                            <th>TC Kimlik No</th>
                            <th>Doğum Tarihi</th>
                            <th>Telefon</th>
                            <th>Adres</th>
                            <th>Müşteri Temsilcisi</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Müşteri temsilcisi adını al
                        $rep_name = '';
                        if ($current_user_rep_id) {
                            $rep_user_id = $wpdb->get_var($wpdb->prepare(
                                "SELECT user_id FROM $representatives_table WHERE id = %d",
                                $current_user_rep_id
                            ));
                            
                            if ($rep_user_id) {
                                $rep_name = $wpdb->get_var($wpdb->prepare(
                                    "SELECT display_name FROM $users_table WHERE ID = %d",
                                    $rep_user_id
                                ));
                            }
                        }
                        
                        foreach ($preview_data['customers'] as $customer): 
                            if ($customer['status'] === 'Yeni'): 
                        ?>
                                <tr>
                                    <td><?php echo esc_html($customer['first_name']); ?></td>
                                    <td><?php echo esc_html($customer['last_name']); ?></td>
                                    <td><?php echo esc_html($customer['tc_kimlik']); ?></td>
                                    <td><?php echo !empty($customer['birth_date']) ? date('d.m.Y', strtotime($customer['birth_date'])) : '-'; ?></td>
                                    <td><?php echo esc_html($customer['phone']); ?></td>
                                    <td><?php echo esc_html($customer['address']); ?></td>
                                    <td><?php echo esc_html($rep_name); ?></td>
                                    <td><span class="ab-badge ab-badge-warning">Yeni</span></td>
                                </tr>
                        <?php 
                            endif; 
                        endforeach; 
                        
                        if (empty(array_filter($preview_data['customers'], function($c) { return $c['status'] === 'Yeni'; }))): 
                        ?>
                            <tr>
                                <td colspan="8">Yeni müşteri bulunamadı.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="ab-filter-row">
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" name="confirm_xml" class="ab-btn ab-btn-filter">Onayla ve Aktar</button>
                    <a href="?view=policies" class="ab-btn ab-btn-reset">İptal</a>
                </div>
            </div>
        </form>
    </div>
    <?php else: ?>
    <!-- Normal Poliçe Listesi -->
    <div class="ab-crm-header">
        <h1><i class="fas fa-file-contract"></i> Poliçeler</h1>
        <div class="ab-crm-header-actions">
            <a href="?view=policies&action=new" class="ab-btn ab-btn-primary">
                <i class="fas fa-plus"></i> Yeni Poliçe
            </a>
            <button type="button" id="import-xml-btn" class="ab-btn ab-btn-primary">
                <i class="fas fa-upload"></i> XML Aktar
            </button>
        </div>
    </div>
    
    <!-- XML Yükleme Formu -->
    <div id="xml-import-container" class="ab-crm-filters ab-filters-hidden">
        <form method="post" enctype="multipart/form-data" id="xml-import-form" class="ab-filter-form">
            <?php wp_nonce_field('xml_import_action', 'xml_import_nonce'); ?>
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="xml_file">XML Dosyası Seç</label>
                    <input type="file" name="xml_file" id="xml_file" accept=".xml" required class="ab-select">
                </div>
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" name="preview_xml" class="ab-btn ab-btn-filter">Ön İzleme</button>
                    <button type="button" id="cancel-import-btn" class="ab-btn ab-btn-reset">İptal</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Filtreleme Butonu ve Form -->
    <div class="ab-filter-toggle-container">
        <button type="button" id="toggle-filters-btn" class="ab-btn ab-toggle-filters">
            <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
        </button>
        
        <?php
        $active_filter_count = 0;
        foreach ($filters as $key => $value) {
            if (!empty($value)) $active_filter_count++;
        }
        if ($active_filter_count > 0):
        ?>
        <div class="ab-active-filters">
            <span><?php echo $active_filter_count; ?> aktif filtre</span>
            <a href="?view=policies" class="ab-clear-filters"><i class="fas fa-times"></i> Temizle</a>
        </div>
        <?php endif; ?>
    </div>
    
    <div id="policies-filters-container" class="ab-crm-filters <?php echo $active_filter_count > 0 ? '' : 'ab-filters-hidden'; ?>">
        <form method="get" id="policies-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="policies">
            <?php wp_nonce_field('policies_filter_nonce', 'policies_filter_nonce'); ?>
            
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="policy_number">Poliçe No</label>
                    <input type="text" name="policy_number" id="policy_number" value="<?php echo esc_attr($filters['policy_number']); ?>" placeholder="Poliçe No Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php echo $filters['customer_id'] == $c->id ? 'selected' : ''; ?>>
                                <?php echo esc_html($c->first_name . ' ' . $c->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="policy_type">Poliçe Türü</label>
                    <select name="policy_type" id="policy_type" class="ab-select">
                        <option value="">Tüm Poliçe Türleri</option>
                        <?php foreach ($policy_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $filters['policy_type'] == $type ? 'selected' : ''; ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insurance_company">Sigorta Firması</label>
                    <select name="insurance_company" id="insurance_company" class="ab-select">
                        <option value="">Tüm Sigorta Firmaları</option>
                        <?php foreach ($insurance_companies as $company): ?>
                            <option value="<?php echo $company; ?>" <?php echo $filters['insurance_company'] == $company ? 'selected' : ''; ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="aktif" <?php echo $filters['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="pasif" <?php echo $filters['status'] == 'pasif' ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insured_party">Sigorta Ettiren</label>
                    <input type="text" name="insured_party" id="insured_party" value="<?php echo esc_attr($filters['insured_party']); ?>" placeholder="Sigorta Ettiren Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn ab-btn-filter">Filtrele</button>
                    <a href="?view=policies" class="ab-btn ab-btn-reset">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Varsayılan Poliçe Listesi -->
    <?php if (!empty($policies)): ?>
    <div class="ab-crm-table-wrapper">
        <div class="ab-crm-table-info">
            <span>Toplam: <?php echo $total_items; ?> poliçe</span>
        </div>
        
        <table class="ab-crm-table">
            <thead>
                <tr>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_number', 'order' => $order === 'ASC' && $orderby === 'p.policy_number' ? 'DESC' : 'ASC')); ?>">
                            Poliçe No <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Müşteri</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_type', 'order' => $order === 'ASC' && $orderby === 'p.policy_type' ? 'DESC' : 'ASC')); ?>">
                            Poliçe Türü <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Sigorta Firması</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.end_date', 'order' => $order === 'ASC' && $orderby === 'p.end_date' ? 'DESC' : 'ASC')); ?>">
                            Bitiş Tarihi <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Prim</th>
                    <th>Durum</th>
                    <th>Döküman</th>
                    <th>Sigorta Ettiren</th>
                    <th class="ab-actions-column">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($policies as $policy): 
                    $is_expired = strtotime($policy->end_date) < time();
                    $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60);
                    $is_cancelled = !empty($policy->cancellation_date);
                    
                    $row_class = '';
                    if ($is_cancelled) {
                        $row_class = 'cancelled';
                    } elseif ($is_expired) {
                        $row_class = 'expired';
                    } elseif ($is_expiring_soon) {
                        $row_class = 'expiring-soon';
                    }
                    
                    if ($policy->policy_type === 'Kasko' || $policy->policy_type === 'Trafik') {
                        $row_class .= ' policy-vehicle';
                    } elseif ($policy->policy_type === 'Konut' || $policy->policy_type === 'DASK') {
                        $row_class .= ' policy-property';
                    } elseif ($policy->policy_type === 'Sağlık' || $policy->policy_type === 'Hayat') {
                        $row_class .= ' policy-health';
                    }
                ?>
                    <tr class="<?php echo trim($row_class); ?>">
                        <td>
                            <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" class="ab-policy-number">
                                <?php echo esc_html($policy->policy_number); ?>
                                <?php if ($is_cancelled): ?>
                                    <span class="ab-badge ab-badge-cancelled">İptal Edilmiş</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="ab-badge ab-badge-danger">Süresi Dolmuş</span>
                                <?php elseif ($is_expiring_soon): ?>
                                    <span class="ab-badge ab-badge-warning">Yakında Bitiyor</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>" class="ab-customer-link">
                                <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                <?php if (!empty($policy->tc_identity)): ?>
                                    <small>(<?php echo esc_html($policy->tc_identity); ?>)</small>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($policy->policy_type); ?></td>
                        <td><?php echo esc_html($policy->insurance_company); ?></td>
                        <td class="ab-date-cell"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                        <td class="ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></td>
                        <td>
                            <span class="ab-badge ab-badge-status-<?php echo esc_attr($policy->status); ?>">
                                <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                            </span>
                            <?php if (!empty($policy->cancellation_date)): ?>
                                <br><small class="ab-cancelled-date">İptal: <?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                                                      <?php if (!empty($policy->document_path)): ?>
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" title="Dökümanı Görüntüle" class="ab-btn ab-btn-sm">
                                    <i class="fas fa-file-pdf"></i> Görüntüle
                                </a>
                            <?php else: ?>
                                <span class="ab-no-document">Döküman yok</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo !empty($policy->insured_party) ? esc_html($policy->insured_party) : '-'; ?></td>
                        <td class="ab-actions-cell">
                            <div class="ab-actions">
                                <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" title="Görüntüle" class="ab-action-btn">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>" title="Düzenle" class="ab-action-btn">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($policy->status === 'aktif' && empty($policy->cancellation_date)): ?>
                                <a href="<?php echo wp_nonce_url('?view=policies&action=cancel&id=' . $policy->id, 'delete_policy_' . $policy->id); ?>" 
                                   title="İptal Et" class="ab-action-btn ab-action-danger">
                                    <i class="fas fa-ban"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
        <div class="ab-pagination">
            <?php
            $pagination_args = array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '«',
                'next_text' => '»',
                'total' => $total_pages,
                'current' => $current_page
            );
            echo paginate_links($pagination_args);
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ab-empty-state">
        <i class="fas fa-file-contract"></i>
        <h3>Poliçe bulunamadı</h3>
        <p>Arama kriterlerinize uygun poliçe bulunamadı.</p>
        <a href="?view=policies" class="ab-btn">Tüm Poliçeleri Göster</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            if (isset($_GET['id'])) {
                include_once('policies-view.php');
            }
            break;
        case 'new':
        case 'edit':
        case 'renew':
        case 'cancel':
            include_once('policies-form.php');
            break;
    }
}
?>

<style>
/* Debug Bilgileri Kutusu */
.ab-debug-info {
    background-color: #f0f8ff;
    border: 1px solid #c6e2ff;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 20px;
    font-size: 13px;
    color: #0066cc;
}

.ab-crm-container {
    max-width: 96%;
    width: 100%;
    margin: 0 auto;
    padding: 20px;
    font-family: inherit;
    color: #333;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e5e5;
    box-sizing: border-box;
}

.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-crm-header h1 {
    font-size: 22px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-crm-header-actions {
    display: flex;
    gap: 10px;
}

.ab-crm-header h1 i {
    color: #555;
}

.ab-notice {
    padding: 10px 15px;
    margin-bottom: 15px;
    border-left: 4px solid;
    border-radius: 3px;
    font-size: 14px;
}

.ab-success {
    background-color: #f0fff4;
    border-left-color: #38a169;
}

.ab-error {
    background-color: #fff5f5;
    border-left-color: #e53e3e;
}

.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    transition: all 0.2s;
    font-weight: 500;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #3d9140;
    color: white;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-crm-preview {
    background-color: #fff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-crm-preview h2 {
    font-size: 20px;
    margin-bottom: 10px;
}

.ab-crm-preview h3 {
    font-size: 16px;
    margin: 20px 0 10px;
}

.ab-filter-form {
    width: 100%;
}

.ab-filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    align-items: end;
}

.ab-filter-col {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.ab-filter-col label {
    font-size: 14px;
    font-weight: 500;
    color: #444;
    margin-bottom: 8px;
    line-height: 1.4;
}

.ab-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-select:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-filter-col input[type="text"],
.ab-filter-col input[type="file"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-filter-col input[type="text"]:focus,
.ab-filter-col input[type="file"]:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-button-col {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.ab-btn-filter {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-filter:hover {
    background-color: #3d9140;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-reset {
    background-color: #f8f9fa;
    border-color: #d1d5db;
    color: #666;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-reset:hover {
    background-color: #e5e7eb;
    color: #444;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-crm-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    background-color: #fff;
    border-radius: 4px;
    border: 1px solid #eee;
}

.ab-crm-table-info {
    padding: 8px 12px;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #eee;
    background-color: #f8f9fa;
}

.ab-crm-table {
    width: 100%;
    border-collapse: collapse;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.ab-crm-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #444;
}

.ab-crm-table th a {
    color: #444;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ab-crm-table th a:hover {
    color: #000;
}

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

tr.policy-vehicle td {
    background-color: #f0f8ff !important;
}

tr.policy-vehicle td:first-child {
    border-left: 3px solid #2271b1;
}

tr.policy-vehicle:hover td {
    background-color: #e6f3ff !important;
}

tr.policy-property td {
    background-color: #f0fff0 !important;
}

tr.policy-property td:first-child {
    border-left: 3px solid #4caf50;
}

tr.policy-property:hover td {
    background-color: #e6ffe6 !important;
}

tr.policy-health td {
    background-color: #fff0f5 !important;
}

tr.policy-health td:first-child {
    border-left: 3px solid #e91e63;
}

tr.policy-health:hover td {
    background-color: #ffe6f0 !important;
}

tr.expired td {
    background-color: #fff2f2 !important;
}

tr.expired td:first-child {
    border-left: 3px solid #e53935;
}

tr.expiring-soon td {
    background-color: #fffaeb !important;
}

tr.expiring-soon td:first-child {
    border-left: 3px solid #ffc107;
}

tr.cancelled td {
    background-color: #f9f0ff !important;
}

tr.cancelled td:first-child {
    border-left: 3px solid #9c27b0;
}

.ab-cancelled-date {
    color: #9c27b0;
    font-style: italic;
    font-size: 11px;
}

.ab-policy-number {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-policy-number:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-link:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link small {
    display: block;
    color: #666;
    font-size: 11px;
    margin-top: 2px;
}

.ab-actions-column {
    text-align: center;
    width: 100px;
    min-width: 100px;
}

.ab-actions-cell {
    text-align: center;
}

.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.ab-badge-status-aktif {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-status-pasif {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-danger {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-warning {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-cancelled {
    background-color: #f3e5f5;
    color: #9c27b0;
}

.ab-no-document {
    color: #999;
    font-style: italic;
    font-size: 12px;
}

.ab-amount {
    font-weight: 600;
    color: #0366d6;
}

.ab-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.ab-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    color: #555;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    transition: all 0.2s;
    text-decoration: none;
}

.ab-action-btn:hover {
    background-color: #eee;
    color: #333;
    text-decoration: none;
}

.ab-action-danger:hover {
    background-color: #ffe5e5;
    color: #d32f2f;
    border-color: #ffcccc;
}

.ab-action-btn i {
    font-size: 14px;
    display: inline-block;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filtreleme toggle kontrolü
    const toggleFiltersBtn = document.getElementById('toggle-filters-btn');
    const filtersContainer = document.getElementById('policies-filters-container');
    
    if (toggleFiltersBtn && filtersContainer) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersContainer.classList.toggle('ab-filters-hidden');
            toggleFiltersBtn.classList.toggle('active');
        });
    }

    // XML Yükleme toggle kontrolü
    const importXmlBtn = document.getElementById('import-xml-btn');
    const xmlImportContainer = document.getElementById('xml-import-container');
    const cancelImportBtn = document.getElementById('cancel-import-btn');
    
    if (importXmlBtn && xmlImportContainer) {
        importXmlBtn.addEventListener('click', function() {
            xmlImportContainer.classList.toggle('ab-filters-hidden');
            importXmlBtn.classList.toggle('active');
        });
    }
    
    if (cancelImportBtn && xmlImportContainer) {
        cancelImportBtn.addEventListener('click', function() {
            xmlImportContainer.classList.add('ab-filters-hidden');
            importXmlBtn.classList.remove('active');
        });
    }

    // Filtre Formu Submit Kontrolü
    const filterForm = document.querySelector('#policies-filter');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            const inputs = filterForm.querySelectorAll('input, select');
            let hasValue = false;
            inputs.forEach(input => {
                if (input.value.trim() && input.name !== 'view' && input.name !== 'policies_filter_nonce') {
                    hasValue = true;
                }
            });
            if (!hasValue) {
                e.preventDefault();
                alert('Lütfen en az bir filtre kriteri girin.');
            }
        });
    }

    // XML Yükleme Formu Submit Kontrolü
    const xmlImportForm = document.querySelector('#xml-import-form');
    if (xmlImportForm) {
        xmlImportForm.addEventListener('submit', function(e) {
            const xmlFileInput = document.getElementById('xml_file');
            if (!xmlFileInput.files.length) {
                e.preventDefault();
                alert('Lütfen bir XML dosyası seçin.');
            }
        });
    }

    // XML Onay Formu Kontrolü
    const xmlConfirmForm = document.querySelector('#xml-confirm-form');
    if (xmlConfirmForm) {
        const selectAllCheckbox = document.getElementById('select-all-policies');
        const policyCheckboxes = document.querySelectorAll('input[name="selected_policies[]"]');

        // Tümünü seç/terk et kontrolü
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                policyCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
        }

        // Bireysel checkbox değiştiğinde tümünü seç durumunu güncelle
        policyCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(policyCheckboxes).every(cb => cb.checked);
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = allChecked;
                }
            });
        });

        // Form gönderilmeden kontrol
        xmlConfirmForm.addEventListener('submit', function(e) {
            const checkedCount = Array.from(policyCheckboxes).filter(cb => cb.checked).length;
            if (checkedCount === 0) {
                e.preventDefault();
                alert('Lütfen en az bir poliçe seçin.');
            }
        });
    }
});
</script>