<?php
/**
 * Excel Dışa Aktarımı için Yardımcı Sınıf
 * 
 * @package Insurance_CRM
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Raporu Excel olarak dışa aktarır - PhpSpreadsheet varsa kullanır, yoksa CSV olarak verir
 */
function insurance_crm_export_excel($title, $content_callback) {
    // PhpSpreadsheet var mı kontrol et
    if (file_exists(INSURANCE_CRM_VENDOR_DIR . 'phpoffice/phpspreadsheet/src/Spreadsheet.php')) {
        require_once INSURANCE_CRM_VENDOR_DIR . 'phpoffice/phpspreadsheet/src/Spreadsheet.php';
        require_once INSURANCE_CRM_VENDOR_DIR . 'phpoffice/phpspreadsheet/src/Writer/Xlsx.php';
        
        // PhpSpreadsheet örneği oluştur
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        
        // İçeriği callback ile ekle
        if (is_callable($content_callback)) {
            call_user_func($content_callback, $spreadsheet);
        }
        
        // XLSX dosyasını oluştur
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Dosya adı oluştur
        $filename = sanitize_title($title) . '.xlsx';
        
        // HTTP başlıkları
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        // Dosyayı çıktıla
        $writer->save('php://output');
        exit;
    } else {
        // PhpSpreadsheet yoksa alternatif çözüm: CSV olarak ver
        $filename = sanitize_title($title) . '.csv';
        
        // HTTP başlıklarını ayarla
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // CSV çıktısı için PHP çıktı akışını aç
        $output = fopen('php://output', 'w');
        
        // Excel'in UTF-8 kodlamasını doğru tanıması için BOM ekle
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Başlık satırını ekle
        fputcsv($output, array($title));
        
        // İçeriği callback ile ekle
        if (is_callable($content_callback)) {
            call_user_func($content_callback, $output);
        }
        
        fclose($output);
        exit;
    }
}