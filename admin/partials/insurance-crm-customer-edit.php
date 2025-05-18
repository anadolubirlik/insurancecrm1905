<?php
/**
 * Müşteri düzenleme sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 */

if (!defined('WPINC')) {
    die;
}

// Müşteri ID'sini al
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Form gönderildiyse
if (isset($_POST['submit_customer']) && isset($_POST['customer_nonce']) && 
    wp_verify_nonce($_POST['customer_nonce'], 'edit_customer')) {
    
    // Müşteri verileri
    $customer_data = array(
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name' => sanitize_text_field($_POST['last_name']),
        'tc_identity' => sanitize_text_field($_POST['tc_identity']),
        'email' => sanitize_email($_POST['email']),
        'phone' => sanitize_text_field($_POST['phone']),
        'address' => sanitize_textarea_field($_POST['address']),
        'category' => sanitize_text_field($_POST['category']),
        'status' => sanitize_text_field($_POST['status']),
        'representative_id' => isset($_POST['representative_id']) ? intval($_POST['representative_id']) : NULL
    );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    if ($customer_id > 0) {
        // Mevcut müşteriyi güncelle
        $wpdb->update(
            $table_name,
            $customer_data,
            array('id' => $customer_id)
        );
        
        echo '<div class="updated"><p>Müşteri başarıyla güncellendi.</p></div>';
    } else {
        // Yeni müşteri ekle
        $wpdb->insert(
            $table_name,
            $customer_data
        );
        $customer_id = $wpdb->insert_id;
        
        echo '<div class="updated"><p>Müşteri başarıyla eklendi.</p></div>';
    }
}

// Müşteri verilerini al
$customer = null;
if ($customer_id > 0) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $customer_id
    ));
}

// Müşteri temsilcilerini al
$representatives = array();
global $wpdb;
$table_reps = $wpdb->prefix . 'insurance_crm_representatives';
$table_users = $wpdb->prefix . 'users';
$query = "
    SELECT r.id, u.display_name 
    FROM $table_reps r
    JOIN $table_users u ON r.user_id = u.ID
    WHERE r.status = 'active'
    ORDER BY u.display_name ASC
";
$representatives = $wpdb->get_results($query);

?>