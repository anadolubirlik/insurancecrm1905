<?php
/**
 * XML Format Yönetimi Admin Paneli
 * @version 1.0.0
 */

/**
 * XML Format Yönetimi menüsünü ekler
 */
function register_xml_format_menu() {
    add_submenu_page(
        'edit.php?post_type=insurance_policy',
        'XML Format Yönetimi',
        'XML Formatları',
        'manage_options',
        'xml-format-manager',
        'xml_format_manager_page'
    );
}
add_action('admin_menu', 'register_xml_format_menu');

/**
 * XML Format Yönetimi sayfası
 */
function xml_format_manager_page() {
    require_once(plugin_dir_path(__FILE__) . 'xml-format-manager.php');
    $format_manager = InsuranceXMLFormatManager::get_instance();
    
    // Form işleme
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $format_id = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : '';
    
    // Yeni format ekleme veya güncelleme işlemi
    if (isset($_POST['submit_format']) && isset($_POST['xml_format_nonce']) && wp_verify_nonce($_POST['xml_format_nonce'], 'save_xml_format')) {
        $format_data = array(
            'name' => sanitize_text_field($_POST['format_name']),
            'description' => sanitize_textarea_field($_POST['format_description']),
            'detection_node' => sanitize_text_field($_POST['detection_node']),
            'test_node' => sanitize_text_field($_POST['test_node']),
            'insurance_company' => sanitize_text_field($_POST['insurance_company']),
            'field_mappings' => array()
        );
        
        // Alan eşleştirmeleri
        $field_names = $_POST['field_name'];
        $field_paths = $_POST['field_path'];
        
        foreach ($field_names as $index => $name) {
            if (!empty($name) && !empty($field_paths[$index])) {
                $format_data['field_mappings'][$name] = sanitize_text_field($field_paths[$index]);
            }
        }
        
        // Poliçe türü eşleştirmeleri
        if (isset($_POST['type_original']) && isset($_POST['type_mapped'])) {
            $type_originals = $_POST['type_original'];
            $type_mapped = $_POST['type_mapped'];
            $format_data['type_mappings'] = array();
            
            foreach ($type_originals as $index => $original) {
                if (!empty($original) && !empty($type_mapped[$index])) {
                    $format_data['type_mappings'][$original] = sanitize_text_field($type_mapped[$index]);
                }
            }
        }
        
        $new_format_id = sanitize_title($_POST['format_id']);
        
        if (empty($new_format_id)) {
            $new_format_id = sanitize_title($format_data['name']);
        }
        
        // Formatı kaydet
        $result = $format_manager->add_custom_format($new_format_id, $format_data);
        
        if ($result) {
            echo '<div class="notice notice-success"><p>XML formatı başarıyla kaydedildi.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>XML formatı kaydedilirken bir hata oluştu.</p></div>';
        }
    }
    
    // Format silme işlemi
    if ($action === 'delete' && !empty($format_id) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_format_' . $format_id)) {
        if ($format_manager->delete_custom_format($format_id)) {
            echo '<div class="notice notice-success"><p>XML formatı başarıyla silindi.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>XML formatı silinirken bir hata oluştu.</p></div>';
        }
    }
    
    // XML formatı test etme
    if (isset($_POST['test_xml']) && isset($_POST['xml_test_nonce']) && wp_verify_nonce($_POST['xml_test_nonce'], 'test_xml_format')) {
        if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['xml_file']['tmp_name'];
            $file_type = mime_content_type($file_tmp);
            
            // XML dosyası kontrolü
            if ($file_type !== 'text/xml' && $file_type !== 'application/xml') {
                echo '<div class="notice notice-error"><p>Lütfen geçerli bir XML dosyası yükleyin.</p></div>';
            } else {
                $xml_content = file_get_contents($file_tmp);
                $xml_content = preg_replace('/^\xEF\xBB\xBF/', '', $xml_content);
                
                // Hata işleme modunu etkinleştir
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xml_content);
                $libxml_errors = libxml_get_errors();
                libxml_clear_errors();
                
                if ($xml === false || !empty($libxml_errors)) {
                    echo '<div class="notice notice-error"><p>XML dosyası ayrıştırılamadı.</p></div>';
                } else {
                    // Formatı algıla
                    $detection_result = $format_manager->detect_format($xml);
                    
                    echo '<div class="notice notice-info">';
                    echo '<h3>XML Dosya Testi Sonuçları</h3>';
                    
                    if ($detection_result['format_id']) {
                        $detected_format = $format_manager->get_format($detection_result['format_id']);
                        echo '<p><strong>Algılanan Format:</strong> ' . esc_html($detected_format['name']) . ' (' . esc_html($detection_result['format_id']) . ')</p>';
                        
                        // Format detaylarını göster
                        echo '<pre>';
                        print_r($detected_format);
                        echo '</pre>';
                        
                        // Algılama detaylarını göster
                        echo '<h4>Algılama Detayları:</h4>';
                        echo '<pre>';
                        print_r($detection_result['detection_info']);
                        echo '</pre>';
                    } else {
                        echo '<p><strong>Hiçbir format algılanamadı.</strong></p>';
                        echo '<h4>XML Yapısı:</h4>';
                        echo '<pre>';
                        print_r($detection_result['detection_info']);
                        echo '</pre>';
                    }
                    echo '</div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Lütfen bir XML dosyası yükleyin.</p></div>';
        }
    }
    
    // Sayfa içeriği
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('XML Format Yönetimi', 'insurance-crm') . '</h1>';
    
    // Format düzenleme veya ekleme formu
    if ($action === 'edit' || $action === 'add') {
        $editing_format = array(
            'id' => '',
            'name' => '',
            'description' => '',
            'detection_node' => '',
            'test_node' => '',
            'insurance_company' => '',
            'field_mappings' => array(),
            'type_mappings' => array()
        );
        
        $is_editing = false;
        
        if ($action === 'edit' && !empty($format_id)) {
            $format = $format_manager->get_format($format_id);
            if ($format) {
                $editing_format = array_merge($editing_format, $format);
                $editing_format['id'] = $format_id;
                $is_editing = true;
            }
        }
        
        echo '<div class="card">';
        echo '<h2>' . ($is_editing ? 'XML Formatını Düzenle' : 'Yeni XML Formatı Ekle') . '</h2>';
        
        echo '<form method="post" action="" class="xml-format-form">';
        wp_nonce_field('save_xml_format', 'xml_format_nonce');
        
        echo '<div class="form-group">';
        echo '<label for="format_id">Format ID:</label>';
        echo '<input type="text" id="format_id" name="format_id" value="' . esc_attr($editing_format['id']) . '" ' . ($is_editing ? 'readonly' : '') . ' placeholder="Format ID otomatik oluşturulacak">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="format_name">Format Adı:</label>';
        echo '<input type="text" id="format_name" name="format_name" value="' . esc_attr($editing_format['name']) . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="format_description">Açıklama:</label>';
        echo '<textarea id="format_description" name="format_description" rows="3">' . esc_textarea($editing_format['description']) . '</textarea>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="detection_node">Algılama Düğümü (Format Belirteci):</label>';
        echo '<input type="text" id="detection_node" name="detection_node" value="' . esc_attr($editing_format['detection_node']) . '" required>';
        echo '<p class="description">Örnek: ACENTEDATATRANSFERI veya POLICIES_DATA</p>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="test_node">Test Düğümü (Poliçe Listesi Yolu):</label>';
        echo '<input type="text" id="test_node" name="test_node" value="' . esc_attr($editing_format['test_node']) . '" required>';
        echo '<p class="description">Örnek: ACENTEDATATRANSFERI/POLICE veya POLICIES_DATA/POLICY</p>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="insurance_company">Sigorta Şirketi:</label>';
        echo '<input type="text" id="insurance_company" name="insurance_company" value="' . esc_attr($editing_format['insurance_company']) . '" required>';
        echo '</div>';
        
        echo '<hr>';
        
        // Alan eşleştirmeleri
        echo '<h3>Alan Eşleştirmeleri</h3>';
        echo '<p class="description">XML dosyasındaki alanları sistem alanlarıyla eşleştirin. Her bir alan için XPath formatında yol belirtin.</p>';
        
        echo '<div class="field-mappings">';
        
        // Gerekli alanların listesi
        $required_fields = array(
            'first_name' => 'Müşteri Adı',
            'last_name' => 'Müşteri Soyadı',
            'full_name' => 'Tam Ad (Ad Soyad birleşik)',
            'phone' => 'Telefon',
            'address' => 'Adres',
            'tc_kimlik' => 'TC Kimlik No',
            'birth_date' => 'Doğum Tarihi',
            'policy_number' => 'Poliçe No',
            'endorsement' => 'Zeyil/Revizyon No',
            'policy_type' => 'Poliçe Türü',
            'start_date' => 'Başlangıç Tarihi',
            'end_date' => 'Bitiş Tarihi',
            'premium' => 'Prim Tutarı',
            'insured_party' => 'Sigorta Ettiren'
        );
        
        foreach ($required_fields as $field_id => $field_label) {
            $field_value = isset($editing_format['field_mappings'][$field_id]) ? $editing_format['field_mappings'][$field_id] : '';
            
            echo '<div class="mapping-row">';
            echo '<label>' . esc_html($field_label) . ':</label>';
            echo '<input type="hidden" name="field_name[]" value="' . esc_attr($field_id) . '">';
            echo '<input type="text" name="field_path[]" value="' . esc_attr($field_value) . '" placeholder="XML yolu örn: CUSTOMER/NAME">';
            echo '</div>';
        }
        
        echo '</div>'; // field-mappings end
        
        echo '<hr>';
        
        // Poliçe türü eşleştirmeleri
        echo '<h3>Poliçe Türü Eşleştirmeleri</h3>';
        echo '<p class="description">XML\'deki poliçe türü değerlerini sistem türleriyle eşleştirin.</p>';
        
        echo '<div class="type-mappings">';
        
        // Mevcut tür eşleştirmelerini göster
        if (!empty($editing_format['type_mappings'])) {
            foreach ($editing_format['type_mappings'] as $original => $mapped) {
                echo '<div class="mapping-row type-mapping-row">';
                echo '<input type="text" name="type_original[]" value="' . esc_attr($original) . '" placeholder="XML\'deki değer">';
                echo '<span class="mapping-arrow">→</span>';
                echo '<input type="text" name="type_mapped[]" value="' . esc_attr($mapped) . '" placeholder="Sistem değeri">';
                echo '<button type="button" class="remove-mapping button-link"><span class="dashicons dashicons-no-alt"></span></button>';
                echo '</div>';
            }
        }
        
        // Boş satır ekle
        echo '<div class="mapping-row type-mapping-row">';
        echo '<input type="text" name="type_original[]" value="" placeholder="XML\'deki değer">';
        echo '<span class="mapping-arrow">→</span>';
        echo '<input type="text" name="type_mapped[]" value="" placeholder="Sistem değeri">';
        echo '<button type="button" class="remove-mapping button-link"><span class="dashicons dashicons-no-alt"></span></button>';
        echo '</div>';
        
        echo '<button type="button" id="add-type-mapping" class="button">+ Yeni Eşleştirme Ekle</button>';
        
        echo '</div>'; // type-mappings end
        
        echo '<div class="form-submit">';
        echo '<input type="submit" name="submit_format" class="button button-primary" value="' . ($is_editing ? 'Formatı Güncelle' : 'Format Ekle') . '">';
        echo '<a href="?page=xml-format-manager" class="button">İptal</a>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>'; // card end
    } 
    // XML dosyası test etme formu
    elseif ($action === 'test') {
        echo '<div class="card">';
        echo '<h2>XML Dosyası Test Et</h2>';
        echo '<p>Bir XML dosyası yükleyin ve hangi formatta olduğunu tespit edin.</p>';
        
        echo '<form method="post" action="" enctype="multipart/form-data">';
        wp_nonce_field('test_xml_format', 'xml_test_nonce');
        
        echo '<div class="form-group">';
        echo '<label for="xml_file">XML Dosyası:</label>';
        echo '<input type="file" id="xml_file" name="xml_file" accept=".xml" required>';
        echo '</div>';
        
        echo '<div class="form-submit">';
        echo '<input type="submit" name="test_xml" class="button button-primary" value="XML Dosyasını Test Et">';
        echo '<a href="?page=xml-format-manager" class="button">İptal</a>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
    }
    // Format listesi
    else {
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<a href="?page=xml-format-manager&action=add" class="button button-primary">Yeni Format Ekle</a>';
        echo '<a href="?page=xml-format-manager&action=test" class="button">XML Dosyası Test Et</a>';
        echo '</div>';
        echo '</div>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Ad</th>';
        echo '<th>Açıklama</th>';
        echo '<th>Sigorta Şirketi</th>';
        echo '<th>Sistem Formatı?</th>';
        echo '<th>Algılama Düğümü</th>';
        echo '<th>İşlemler</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $formats = $format_manager->get_formats();
        
        if (!empty($formats)) {
            foreach ($formats as $id => $format) {
                echo '<tr>';
                echo '<td>' . esc_html($id) . '</td>';
                echo '<td>' . esc_html($format['name']) . '</td>';
                echo '<td>' . esc_html($format['description']) . '</td>';
                echo '<td>' . (isset($format['insurance_company']) ? esc_html($format['insurance_company']) : '-') . '</td>';
                echo '<td>' . (isset($format['is_system']) && $format['is_system'] ? 'Evet' : 'Hayır') . '</td>';
                echo '<td>' . esc_html($format['detection_node']) . '</td>';
                echo '<td class="actions">';
                
                // Sistem formatları düzenlenemez/silinemez
                if (!isset($format['is_system']) || !$format['is_system']) {
                    echo '<a href="?page=xml-format-manager&action=edit&format=' . esc_attr($id) . '" class="button button-small">Düzenle</a> ';
                    echo '<a href="' . wp_nonce_url('?page=xml-format-manager&action=delete&format=' . esc_attr($id), 'delete_format_' . $id) . '" class="button button-small" onclick="return confirm(\'Bu formatı silmek istediğinizden emin misiniz?\');">Sil</a>';
                } else {
                    echo '<em>Sistem formatı</em>';
                }
                
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7">Henüz kayıtlı XML formatı bulunmamaktadır.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    echo '</div>'; // wrap end
    
    // Sayfamız için CSS ve JS
    ?>
    <style>
    .xml-format-form .form-group {
        margin-bottom: 15px;
    }
    
    .xml-format-form label {
        display: block;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .xml-format-form input[type="text"],
    .xml-format-form textarea {
        width: 100%;
        max-width: 500px;
    }
    
    .xml-format-form .description {
        font-style: italic;
        color: #666;
        font-size: 12px;
    }
    
    .field-mappings,
    .type-mappings {
        background: #f9f9f9;
        padding: 15px;
        border: 1px solid #e0e0e0;
        margin-top: 10px;
        margin-bottom: 15px;
    }
    
    .mapping-row {
        display: flex;
        align-items: center;
        margin-bottom: 8px;
    }
    
    .mapping-row label {
        width: 150px;
        margin-bottom: 0;
    }
    
    .mapping-row input[type="text"] {
        flex: 1;
    }
    
    .type-mapping-row {
        margin-bottom: 10px;
    }
    
    .mapping-arrow {
        margin: 0 10px;
        color: #555;
    }
    
    .remove-mapping {
        margin-left: 10px;
        color: #a00;
        cursor: pointer;
    }
    
    .form-submit {
        margin-top: 20px;
    }
    
    .card {
        background: #fff;
        border: 1px solid #e0e0e0;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
        padding: 20px;
        margin-bottom: 20px;
        margin-top: 20px;
    }
    
    .actions .button {
        margin-right: 5px;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Poliçe türü eşleştirmesi ekleme
        $('#add-type-mapping').on('click', function() {
            var newRow = $('<div class="mapping-row type-mapping-row">' +
                '<input type="text" name="type_original[]" value="" placeholder="XML\'deki değer">' +
                '<span class="mapping-arrow">→</span>' +
                '<input type="text" name="type_mapped[]" value="" placeholder="Sistem değeri">' +
                '<button type="button" class="remove-mapping button-link"><span class="dashicons dashicons-no-alt"></span></button>' +
                '</div>');
                
            $(this).before(newRow);
        });
        
        // Eşleştirme satırını silme (delegasyon ile)
        $('.type-mappings').on('click', '.remove-mapping', function() {
            $(this).closest('.type-mapping-row').remove();
        });
    });
    </script>
    <?php
}