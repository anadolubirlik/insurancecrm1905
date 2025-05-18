<?php
/**
 * Poliçe model sınıfı
 *
 * @package     Insurance_CRM
 * @subpackage  Models
 * @author      Anadolu Birlik
 * @since       1.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Insurance_CRM_Policy {
    /**
     * Veritabanı tablosu
     *
     * @var string
     */
    private $table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'insurance_crm_policies';
    }

    /**
     * Yeni poliçe ekler
     *
     * @param array $data Poliçe verileri
     * @return int|WP_Error Eklenen poliçe ID'si veya hata
     */
    public function add($data) {
        global $wpdb;

        // Müşteri kontrolü
        $customer = new Insurance_CRM_Customer();
        if (!$customer->get($data['customer_id'])) {
            return new WP_Error('invalid_customer', __('Geçersiz müşteri.', 'insurance-crm'));
        }

        // Poliçe numarası benzersizlik kontrolü
        if ($this->policy_number_exists($data['policy_number'])) {
            return new WP_Error('policy_exists', __('Bu poliçe numarası ile kayıtlı poliçe bulunmaktadır.', 'insurance-crm'));
        }

        $defaults = array(
            'status' => 'aktif',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $data = wp_parse_args($data, $defaults);

        // Veri temizleme
        $data = $this->sanitize_policy_data($data);

        // Dosya yükleme işlemi - DÜZELTİLDİ
        if (isset($data['document_file']) && !empty($data['document_file']['name'])) {
            $upload = $this->handle_document_upload($data['document_file']);
            if (is_wp_error($upload)) {
                return $upload;
            }
            $data['document_path'] = $upload;
            unset($data['document_file']);
        }

        // Format tanımlama için array hazırla
        $format_array = array(
            '%d', // customer_id
        );
        
        // Müşteri temsilcisi alanını kontrol et ve ekle
        if (isset($data['representative_id']) && !empty($data['representative_id'])) {
            $format_array[] = '%d'; // representative_id
        } else {
            $format_array[] = '%d'; // representative_id (NULL için)
        }
        
        // Diğer alanlar için format tanımları
        $additional_formats = array(
            '%s', // policy_number
            '%s', // policy_type
            '%s', // insurance_company
            '%s', // start_date
            '%s', // end_date
            '%f', // premium_amount
            '%s', // status
        );
        
        // Doküman yolu varsa formatını ekle
        if (!empty($data['document_path'])) {
            $additional_formats[] = '%s'; // document_path
        }
        
        // Tarih alanları
        $additional_formats = array_merge($additional_formats, array(
            '%s', // created_at
            '%s'  // updated_at
        ));
        
        // Tüm formatları birleştir
        $format_array = array_merge($format_array, $additional_formats);

        $inserted = $wpdb->insert(
            $this->table,
            $data,
            $format_array
        );

        if ($inserted) {
            $policy_id = $wpdb->insert_id;

            // Yenileme hatırlatması için görev oluştur
            $settings = get_option('insurance_crm_settings');
            $reminder_days = isset($settings['renewal_reminder_days']) ? intval($settings['renewal_reminder_days']) : 30;
            
            $reminder_date = date('Y-m-d H:i:s', strtotime($data['end_date'] . ' - ' . $reminder_days . ' days'));
            
            $task = new Insurance_CRM_Task();
            $task->add([
                'customer_id' => $data['customer_id'],
                'representative_id' => isset($data['representative_id']) ? $data['representative_id'] : null, // Temsilci ID'sini ekledik
                'policy_id' => $policy_id,
                'task_description' => sprintf(
                    __('Poliçe yenileme hatırlatması - %s', 'insurance-crm'),
                    $data['policy_number']
                ),
                'due_date' => $reminder_date,
                'priority' => 'high',
                'status' => 'pending'
            ]);

            // Aktivite logu
            $insurance_company_info = !empty($data['insurance_company']) ? ' (' . $data['insurance_company'] . ')' : '';
            $this->log_activity($policy_id, 'create', sprintf(
                __('Yeni poliçe eklendi: %s - %s%s', 'insurance-crm'),
                $data['policy_number'],
                $data['policy_type'],
                $insurance_company_info
            ));

            return $policy_id;
        }

        return new WP_Error('db_insert_error', __('Poliçe eklenirken bir hata oluştu.', 'insurance-crm'));
    }

    /**
     * Poliçe günceller
     *
     * @param int   $id   Poliçe ID
     * @param array $data Güncellenecek veriler
     * @return bool|WP_Error
     */
    public function update($id, $data) {
        global $wpdb;

        // Mevcut poliçe kontrolü
        $current = $this->get($id);
        if (!$current) {
            return new WP_Error('not_found', __('Poliçe bulunamadı.', 'insurance-crm'));
        }

        // Poliçe numarası kontrolü (değiştirilmişse)
        if (isset($data['policy_number']) && $data['policy_number'] !== $current->policy_number) {
            if ($this->policy_number_exists($data['policy_number'])) {
                return new WP_Error('policy_exists', __('Bu poliçe numarası ile kayıtlı poliçe bulunmaktadır.', 'insurance-crm'));
            }
        }

        $data['updated_at'] = current_time('mysql');

        // Veri temizleme
        $data = $this->sanitize_policy_data($data);

        // Dosya yükleme işlemi - DÜZELTİLDİ
        if (isset($data['document_file']) && !empty($data['document_file']['name'])) {
            $upload = $this->handle_document_upload($data['document_file']);
            if (is_wp_error($upload)) {
                return $upload;
            }
            
            // Eski dosyayı sil
            if (!empty($current->document_path)) {
                $this->delete_document($current->document_path);
            }
            
            $data['document_path'] = $upload;
            unset($data['document_file']);
        }

        // Format tanımlama için array hazırla
        $format_array = array();
        
        // Müşteri ID'si
        if (isset($data['customer_id'])) {
            $format_array[] = '%d'; // customer_id
        }
        
        // Müşteri temsilcisi ID'si
        if (isset($data['representative_id'])) {
            $format_array[] = '%d'; // representative_id
        }
        
        // Diğer alanlar için format tanımları - sadece var olan alanlar için
        if (isset($data['policy_number'])) $format_array[] = '%s'; // policy_number
        if (isset($data['policy_type'])) $format_array[] = '%s'; // policy_type
        if (isset($data['insurance_company'])) $format_array[] = '%s'; // insurance_company
        if (isset($data['start_date'])) $format_array[] = '%s'; // start_date
        if (isset($data['end_date'])) $format_array[] = '%s'; // end_date
        if (isset($data['premium_amount'])) $format_array[] = '%f'; // premium_amount
        if (isset($data['status'])) $format_array[] = '%s'; // status
        
        // Doküman yolu varsa formatını ekle
        if (!empty($data['document_path'])) {
            $format_array[] = '%s'; // document_path
        }
        
        // Tarih alanları
        if (isset($data['updated_at'])) $format_array[] = '%s';  // updated_at

        $updated = $wpdb->update(
            $this->table,
            $data,
            array('id' => $id),
            $format_array,
            array('%d')
        );

        if ($updated !== false) {
            // Yenileme hatırlatması güncelle
            if (isset($data['end_date']) && $data['end_date'] !== $current->end_date) {
                $settings = get_option('insurance_crm_settings');
                $reminder_days = isset($settings['renewal_reminder_days']) ? intval($settings['renewal_reminder_days']) : 30;
                
                $reminder_date = date('Y-m-d H:i:s', strtotime($data['end_date'] . ' - ' . $reminder_days . ' days'));
                
                // Mevcut hatırlatma görevini bul ve güncelle
                $task = new Insurance_CRM_Task();
                $tasks = $task->get_all([
                    'policy_id' => $id,
                    'status' => 'pending'
                ]);

                if (!empty($tasks)) {
                    foreach ($tasks as $t) {
                        if (strpos($t->task_description, __('Poliçe yenileme hatırlatması', 'insurance-crm')) !== false) {
                            $task_update_data = ['due_date' => $reminder_date];
                            
                            // Müşteri temsilcisi değiştiyse, görevi de güncelle
                            if (isset($data['representative_id'])) {
                                $task_update_data['representative_id'] = $data['representative_id'];
                            }
                            
                            $task->update($t->id, $task_update_data);
                            break;
                        }
                    }
                }
            }

            // Aktivite logu - Sigorta firması bilgisini ekle
            $insurance_company = isset($data['insurance_company']) ? $data['insurance_company'] : 
                                (isset($current->insurance_company) ? $current->insurance_company : '');
            $insurance_company_info = !empty($insurance_company) ? ' (' . $insurance_company . ')' : '';
            
            $this->log_activity($id, 'update', sprintf(
                __('Poliçe güncellendi: %s - %s%s', 'insurance-crm'),
                isset($data['policy_number']) ? $data['policy_number'] : $current->policy_number,
                isset($data['policy_type']) ? $data['policy_type'] : $current->policy_type,
                $insurance_company_info
            ));

            return true;
        }

        return new WP_Error('db_update_error', __('Poliçe güncellenirken bir hata oluştu.', 'insurance-crm'));
    }

    /**
     * Poliçe siler
     *
     * @param int $id Poliçe ID
     * @return bool|WP_Error
     */
    public function delete($id) {
        global $wpdb;

        // Mevcut poliçe kontrolü
        $policy = $this->get($id);
        if (!$policy) {
            return new WP_Error('not_found', __('Poliçe bulunamadı.', 'insurance-crm'));
        }

        // İlişkili görevleri sil
        $wpdb->delete(
            $wpdb->prefix . 'insurance_crm_tasks',
            array('policy_id' => $id),
            array('%d')
        );

        // Dosyayı sil
        if (!empty($policy->document_path)) {
            $this->delete_document($policy->document_path);
        }

        $deleted = $wpdb->delete(
            $this->table,
            array('id' => $id),
            array('%d')
        );

        if ($deleted) {
            // Aktivite logu - Sigorta firması bilgisini ekle
            $insurance_company_info = !empty($policy->insurance_company) ? ' (' . $policy->insurance_company . ')' : '';
            
            $this->log_activity($id, 'delete', sprintf(
                __('Poliçe silindi: %s - %s%s', 'insurance-crm'),
                $policy->policy_number,
                $policy->policy_type,
                $insurance_company_info
            ));

            return true;
        }

        return new WP_Error('db_delete_error', __('Poliçe silinirken bir hata oluştu.', 'insurance-crm'));
    }

    /**
     * Poliçe getirir
     *
     * @param int $id Poliçe ID
     * @return object|null
     */
    public function get($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, c.first_name, c.last_name, c.email, c.phone 
            FROM {$this->table} p 
            LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id 
            WHERE p.id = %d",
            $id
        ));
    }

    /**
     * Tüm poliçeleri getirir
     *
     * @param array $args Filtre parametreleri
     * @return array
     */
    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'customer_id' => 0,
            'representative_id' => 0, // Müşteri temsilcisi filtresi eklendi
            'status' => '',
            'policy_type' => '',
            'insurance_company' => '',
            'start_date' => '',
            'end_date' => '',
            'search' => '',
            'orderby' => 'id',
            'order' => 'DESC',
            'limit' => 0,
            'offset' => 0
        );

        $args = wp_parse_args($args, $defaults);
        $where = array('1=1');
        $values = array();

        if (!empty($args['customer_id'])) {
            $where[] = 'p.customer_id = %d';
            $values[] = $args['customer_id'];
        }
        
        // Müşteri temsilcisi filtresi
        if (!empty($args['representative_id'])) {
            $where[] = 'p.representative_id = %d';
            $values[] = $args['representative_id'];
        }

        if (!empty($args['status'])) {
            $where[] = 'p.status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['policy_type'])) {
            $where[] = 'p.policy_type = %s';
            $values[] = $args['policy_type'];
        }
        
        if (!empty($args['insurance_company'])) {
            $where[] = 'p.insurance_company = %s';
            $values[] = $args['insurance_company'];
        }

        if (!empty($args['start_date'])) {
            $where[] = 'p.start_date >= %s';
            $values[] = $args['start_date'];
        }

        if (!empty($args['end_date'])) {
            $where[] = 'p.end_date <= %s';
            $values[] = $args['end_date'];
        }

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(p.policy_number LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR p.insurance_company LIKE %s)';
            $values = array_merge($values, array($search, $search, $search, $search));
        }

        $sql = "SELECT p.*, c.first_name, c.last_name, c.email, c.phone 
                FROM {$wpdb->prefix}insurance_crm_policies p 
                LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id 
                WHERE " . implode(' AND ', $where);
        
        if (!empty($args['orderby'])) {
            $sql .= ' ORDER BY ' . esc_sql($args['orderby']) . ' ' . esc_sql($args['order']);
        }

        if (!empty($args['limit'])) {
            $sql .= ' LIMIT ' . absint($args['limit']);
            
            if (!empty($args['offset'])) {
                $sql .= ' OFFSET ' . absint($args['offset']);
            }
        }

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Poliçe verilerini temizler
     *
     * @param array $data Poliçe verileri
     * @return array
     */
    private function sanitize_policy_data($data) {
        $clean = array();

        if (isset($data['customer_id'])) {
            $clean['customer_id'] = absint($data['customer_id']);
        }
        
        // Müşteri temsilcisi alanını temizle
        if (isset($data['representative_id'])) {
            $clean['representative_id'] = !empty($data['representative_id']) ? absint($data['representative_id']) : null;
        }

        if (isset($data['policy_number'])) {
            $clean['policy_number'] = sanitize_text_field($data['policy_number']);
        }

        if (isset($data['policy_type'])) {
            $clean['policy_type'] = sanitize_text_field($data['policy_type']);
        }
        
        if (isset($data['insurance_company'])) {
            $clean['insurance_company'] = sanitize_text_field($data['insurance_company']);
        }

        if (isset($data['start_date'])) {
            $clean['start_date'] = sanitize_text_field($data['start_date']);
        }

        if (isset($data['end_date'])) {
            $clean['end_date'] = sanitize_text_field($data['end_date']);
        }

        if (isset($data['premium_amount'])) {
            $clean['premium_amount'] = floatval($data['premium_amount']);
        }

        if (isset($data['status'])) {
            $clean['status'] = sanitize_text_field($data['status']);
        }

        if (isset($data['document_path'])) {
            $clean['document_path'] = esc_url_raw($data['document_path']);
        }

        // Diğer özel alanları koruyalım
        if (isset($data['document_file'])) {
            $clean['document_file'] = $data['document_file'];
        }
        if (isset($data['created_at'])) {
            $clean['created_at'] = $data['created_at'];
        }
        if (isset($data['updated_at'])) {
            $clean['updated_at'] = $data['updated_at'];
        }

        return $clean;
    }

    /**
     * Poliçe dökümanını yükler
     *
     * @param array $file $_FILES array
     * @return string|WP_Error Yüklenen dosyanın yolu veya hata
     */
    private function handle_document_upload($file) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $upload_overrides = array(
            'test_form' => false,
            'mimes' => array(
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            )
        );

        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            return $movefile['url'];
        } else {
            return new WP_Error('upload_error', $movefile['error']);
        }
    }

    /**
     * Poliçe dökümanını siler
     *
     * @param string $file_url Dosya URL'si
     * @return bool
     */
    private function delete_document($file_url) {
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
        
        if (file_exists($file_path)) {
            return unlink($file_path);
        }
        
        return false;
    }

    /**
     * Poliçe numarasının benzersiz olup olmadığını kontrol eder
     *
     * @param string $policy_number Poliçe numarası
     * @param int    $exclude_id    Hariç tutulacak poliçe ID (güncelleme için)
     * @return bool
     */
    private function policy_number_exists($policy_number, $exclude_id = 0) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE policy_number = %s",
            $policy_number
        );

        if ($exclude_id > 0) {
            $sql .= $wpdb->prepare(" AND id != %d", $exclude_id);
        }

        return (bool) $wpdb->get_var($sql);
    }

    /**
     * Poliçe aktivitelerini loglar
     *
     * @param int    $policy_id Poliçe ID
     * @param string $action    İşlem türü
     * @param string $message   Log mesajı
     */
    private function log_activity($policy_id, $action, $message) {
        $current_user = wp_get_current_user();
        
        $log = array(
            'post_title' => sprintf(
                __('Poliçe %s - %s', 'insurance-crm'),
                $action,
                current_time('mysql')
            ),
            'post_content' => $message,
            'post_type' => 'insurance_crm_log',
            'post_status' => 'publish',
            'post_author' => $current_user->ID
        );

        $log_id = wp_insert_post($log);

        if ($log_id) {
            add_post_meta($log_id, '_policy_id', $policy_id);
            add_post_meta($log_id, '_action_type', $action);
        }
    }
}