<?php
/**
 * Ayarlar Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.0 (2025-05-02)
 * @version    1.1.2 (2025-05-17)
 */

if (!defined('WPINC')) {
    die;
}

// Medya yükleyici scriptlerini ve stillerini yükle
function insurance_crm_enqueue_media_scripts() {
    wp_enqueue_media(); // Medya yükleyici için gerekli script ve stilleri yükler
}
add_action('admin_enqueue_scripts', 'insurance_crm_enqueue_media_scripts');

// Ayarları kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insurance_crm_settings_nonce'])) {
    if (!wp_verify_nonce($_POST['insurance_crm_settings_nonce'], 'insurance_crm_save_settings')) {
        wp_die(__('Güvenlik doğrulaması başarısız', 'insurance-crm'));
    }

    $settings = array(
        'company_name' => sanitize_text_field($_POST['company_name']),
        'company_email' => sanitize_email($_POST['company_email']),
        'renewal_reminder_days' => intval($_POST['renewal_reminder_days']),
        'task_reminder_days' => intval($_POST['task_reminder_days']),
        'default_policy_types' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_policy_types']))),
        'insurance_companies' => array_map('sanitize_text_field', explode("\n", trim($_POST['insurance_companies']))),
        'default_task_types' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_task_types']))),
        'notification_settings' => array(
            'email_notifications' => isset($_POST['email_notifications']),
            'renewal_notifications' => isset($_POST['renewal_notifications']),
            'task_notifications' => isset($_POST['task_notifications'])
        ),
        'email_templates' => array(
            'renewal_reminder' => wp_kses_post($_POST['renewal_reminder_template']),
            'task_reminder' => wp_kses_post($_POST['task_reminder_template']),
            'new_policy' => wp_kses_post($_POST['new_policy_template'])
        ),
        'site_appearance' => array(
            'login_logo' => sanitize_text_field($_POST['login_logo']),
            'font_family' => sanitize_text_field($_POST['font_family']),
            'primary_color' => sanitize_hex_color($_POST['primary_color'])
        ),
        'file_upload_settings' => array(
            'allowed_file_types' => isset($_POST['allowed_file_types']) ? array_map('sanitize_text_field', $_POST['allowed_file_types']) : array()
        ),
        // Yeni meslek ayarları
        'occupation_settings' => array(
            'default_occupations' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_occupations'])))
        )
    );

    update_option('insurance_crm_settings', $settings);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Ayarlar başarıyla kaydedildi.', 'insurance-crm') . '</p></div>';
}

// Mevcut ayarları al
$settings = get_option('insurance_crm_settings', array());

// Varsayılan değerler
if (!isset($settings['insurance_companies'])) {
    $settings['insurance_companies'] = array();
}
if (!isset($settings['site_appearance'])) {
    $settings['site_appearance'] = array(
        'login_logo' => '',
        'font_family' => 'Arial, sans-serif',
        'primary_color' => '#2980b9'
    );
}
if (!isset($settings['file_upload_settings'])) {
    $settings['file_upload_settings'] = array(
        'allowed_file_types' => array('jpg', 'jpeg', 'pdf', 'docx') // Varsayılan dosya türleri
    );
}
if (!isset($settings['occupation_settings'])) {
    $settings['occupation_settings'] = array(
        'default_occupations' => array('Doktor', 'Mühendis', 'Öğretmen', 'Avukat') // Varsayılan meslekler
    );
}

// Mevcut dosya türlerini al
$allowed_file_types = $settings['file_upload_settings']['allowed_file_types'];
// Mevcut meslekleri al
$default_occupations = $settings['occupation_settings']['default_occupations'];
?>

<div class="wrap insurance-crm-wrap">
    <h1><?php _e('Insurance CRM Ayarları', 'insurance-crm'); ?></h1>

    <form method="post" class="insurance-crm-settings-form">
        <?php wp_nonce_field('insurance_crm_save_settings', 'insurance_crm_settings_nonce'); ?>

        <div class="insurance-crm-settings-tabs">
            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active"><?php _e('Genel', 'insurance-crm'); ?></a>
                <a href="#notifications" class="nav-tab"><?php _e('Bildirimler', 'insurance-crm'); ?></a>
                <a href="#templates" class="nav-tab"><?php _e('E-posta Şablonları', 'insurance-crm'); ?></a>
                <a href="#site-appearance" class="nav-tab"><?php _e('Site Görünümü', 'insurance-crm'); ?></a>
                <a href="#file-upload-settings" class="nav-tab"><?php _e('Dosya Yükleme Ayarları', 'insurance-crm'); ?></a>
                <a href="#occupations" class="nav-tab"><?php _e('Meslekler', 'insurance-crm'); ?></a>
            </nav>

            <!-- Genel Ayarlar -->
            <div id="general" class="insurance-crm-settings-tab active">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="company_name"><?php _e('Şirket Adı', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="company_name" id="company_name" class="regular-text" 
                                   value="<?php echo esc_attr($settings['company_name']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="company_email"><?php _e('Şirket E-posta', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="company_email" id="company_email" class="regular-text" 
                                   value="<?php echo esc_attr($settings['company_email']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="renewal_reminder_days"><?php _e('Yenileme Hatırlatma (Gün)', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="renewal_reminder_days" id="renewal_reminder_days" class="small-text" 
                                   value="<?php echo esc_attr($settings['renewal_reminder_days']); ?>" min="1" max="90">
                            <p class="description"><?php _e('Poliçe yenileme hatırlatması için kaç gün önceden bildirim gönderilsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="task_reminder_days"><?php _e('Görev Hatırlatma (Gün)', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="task_reminder_days" id="task_reminder_days" class="small-text" 
                                   value="<?php echo esc_attr($settings['task_reminder_days']); ?>" min="1" max="30">
                            <p class="description"><?php _e('Görev hatırlatması için kaç gün önceden bildirim gönderilsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_policy_types"><?php _e('Varsayılan Poliçe Türleri', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_policy_types" id="default_policy_types" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['default_policy_types'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir poliçe türü yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="insurance_companies"><?php _e('Sigorta Firmaları', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="insurance_companies" id="insurance_companies" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['insurance_companies'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir sigorta firması yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_task_types"><?php _e('Varsayılan Görev Türleri', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_task_types" id="default_task_types" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['default_task_types'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir görev türü yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Bildirim Ayarları -->
            <div id="notifications" class="insurance-crm-settings-tab">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('E-posta Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="email_notifications">
                                    <input type="checkbox" name="email_notifications" id="email_notifications" 
                                           <?php checked($settings['notification_settings']['email_notifications']); ?>>
                                    <?php _e('E-posta bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Yenileme Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="renewal_notifications">
                                    <input type="checkbox" name="renewal_notifications" id="renewal_notifications" 
                                           <?php checked($settings['notification_settings']['renewal_notifications']); ?>>
                                    <?php _e('Poliçe yenileme bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Görev Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="task_notifications">
                                    <input type="checkbox" name="task_notifications" id="task_notifications" 
                                           <?php checked($settings['notification_settings']['task_notifications']); ?>>
                                    <?php _e('Görev bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- E-posta Şablonları -->
            <div id="templates" class="insurance-crm-settings-tab">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="renewal_reminder_template"><?php _e('Yenileme Hatırlatma Şablonu', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['renewal_reminder'],
                                'renewal_reminder_template',
                                array(
                                    'textarea_name' => 'renewal_reminder_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {end_date}, {premium_amount}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="task_reminder_template"><?php _e('Görev Hatırlatma Şablonu', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['task_reminder'],
                                'task_reminder_template',
                                array(
                                    'textarea_name' => 'task_reminder_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {task_description}, {due_date}, {priority}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="new_policy_template"><?php _e('Yeni Poliçe Bildirimi', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['new_policy'],
                                'new_policy_template',
                                array(
                                    'textarea_name' => 'new_policy_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {start_date}, {end_date}, {premium_amount}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Site Görünümü Ayarları -->
            <div id="site-appearance" class="insurance-crm-settings-tab">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="login_logo"><?php _e('Giriş Paneli Logo', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="login_logo" id="login_logo" class="regular-text" 
                                   value="<?php echo esc_attr($settings['site_appearance']['login_logo']); ?>">
                            <button type="button" class="button button-secondary upload-login-logo">
                                <?php _e('Medya Kütüphanesini Aç', 'insurance-crm'); ?>
                            </button>
                            <p class="description"><?php _e('Giriş panelinde görünecek logo URL’si.', 'insurance-crm'); ?></p>
                            <?php if (!empty($settings['site_appearance']['login_logo'])): ?>
                                <div class="logo-preview" style="margin-top: 10px;">
                                    <img src="<?php echo esc_url($settings['site_appearance']['login_logo']); ?>" alt="Logo Önizleme" style="max-width: 200px; max-height: 100px;">
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="font_family"><?php _e('Font Ailesi', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="font_family" id="font_family" class="regular-text" 
                                   value="<?php echo esc_attr($settings['site_appearance']['font_family']); ?>">
                            <p class="description"><?php _e('Örnek: "Arial, sans-serif" veya "Open Sans, sans-serif".', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="primary_color"><?php _e('Ana Renk', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="color" name="primary_color" id="primary_color" 
                                   value="<?php echo esc_attr($settings['site_appearance']['primary_color']); ?>">
                            <p class="description"><?php _e('Giriş paneli ve butonlar için ana renk.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Dosya Yükleme Ayarları -->
            <div id="file-upload-settings" class="insurance-crm-settings-tab">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e('İzin Verilen Dosya Formatları', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span><?php _e('İzin Verilen Dosya Formatları', 'insurance-crm'); ?></span></legend>
                                <div class="insurance-crm-checkbox-group">
                                    <label for="file_type_jpg">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_jpg" value="jpg" 
                                               <?php checked(in_array('jpg', $allowed_file_types)); ?>>
                                        <?php _e('JPEG Resim Dosyaları (.jpg)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_jpeg">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_jpeg" value="jpeg" 
                                               <?php checked(in_array('jpeg', $allowed_file_types)); ?>>
                                        <?php _e('JPEG Resim Dosyaları (.jpeg)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_png">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_png" value="png" 
                                               <?php checked(in_array('png', $allowed_file_types)); ?>>
                                        <?php _e('PNG Resim Dosyaları (.png)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_pdf">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_pdf" value="pdf" 
                                               <?php checked(in_array('pdf', $allowed_file_types)); ?>>
                                        <?php _e('PDF Dokümanları (.pdf)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_doc">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_doc" value="doc" 
                                               <?php checked(in_array('doc', $allowed_file_types)); ?>>
                                        <?php _e('Word Dokümanları (.doc)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_docx">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_docx" value="docx" 
                                               <?php checked(in_array('docx', $allowed_file_types)); ?>>
                                        <?php _e('Word Dokümanları (.docx)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_xls">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_xls" value="xls" 
                                               <?php checked(in_array('xls', $allowed_file_types)); ?>>
                                        <?php _e('Excel Tabloları (.xls)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_xlsx">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_xlsx" value="xlsx" 
                                               <?php checked(in_array('xlsx', $allowed_file_types)); ?>>
                                        <?php _e('Excel Tabloları (.xlsx)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_txt">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_txt" value="txt" 
                                               <?php checked(in_array('txt', $allowed_file_types)); ?>>
                                        <?php _e('Metin Dosyaları (.txt)', 'insurance-crm'); ?>
                                    </label><br>
                                    <label for="file_type_zip">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_zip" value="zip" 
                                               <?php checked(in_array('zip', $allowed_file_types)); ?>>
                                        <?php _e('Arşiv Dosyaları (.zip)', 'insurance-crm'); ?>
                                    </label>
                                </div>
                            </fieldset>
                            <p class="description"><?php _e('Seçili formatlar dışındaki dosyaların sistem tarafından reddedileceğini unutmayın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Meslekler Ayarları -->
            <div id="occupations" class="insurance-crm-settings-tab">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="default_occupations"><?php _e('Varsayılan Meslekler', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_occupations" id="default_occupations" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['occupation_settings']['default_occupations'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir meslek yazın. Bu meslekler, müşteri formunda dropdown menüde listelenecektir.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(__('Ayarları Kaydet', 'insurance-crm')); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab yönetimi
    $('.insurance-crm-settings-tabs nav a').click(function(e) {
        e.preventDefault();
        var tab = $(this).attr('href').substring(1);
        
        // Tab butonlarını güncelle
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Tab içeriklerini güncelle
        $('.insurance-crm-settings-tab').removeClass('active');
        $('#' + tab).addClass('active');
    });

    // Medya yükleyici (Login Logo)
    var mediaUploader;
    $('.upload-login-logo').on('click', function(e) {
        e.preventDefault();

        // Eğer medya yükleyici zaten açıksa, tekrar aç
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Medya yükleyiciyi oluştur
        mediaUploader = wp.media({
            title: '<?php _e('Logo Seç', 'insurance-crm'); ?>',
            button: {
                text: '<?php _e('Bu Logoyu Kullan', 'insurance-crm'); ?>'
            },
            library: {
                type: 'image' // Sadece resim dosyalarını göster
            },
            multiple: false
        });

        // Resim seçildiğinde
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#login_logo').val(attachment.url);
            
            // Önizlemeyi güncelle
            var preview = $('.logo-preview');
            if (preview.length) {
                preview.find('img').attr('src', attachment.url);
            } else {
                $('#login_logo').after('<div class="logo-preview" style="margin-top: 10px;"><img src="' + attachment.url + '" alt="Logo Önizleme" style="max-width: 200px; max-height: 100px;"></div>');
            }
        });

        // Medya yükleyiciyi aç
        mediaUploader.open();
    });
});
</script>

<style>
.insurance-crm-settings-tabs {
    margin-top: 20px;
}

.insurance-crm-settings-tab {
    display: none;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-top: none;
}

.insurance-crm-settings-tab.active {
    display: block;
}

.insurance-crm-settings-form .form-table th {
    width: 300px;
}

.insurance-crm-settings-form .description {
    margin-top: 5px;
    color: #666;
}

.insurance-crm-checkbox-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}

.insurance-crm-checkbox-group label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 14px;
}
</style>