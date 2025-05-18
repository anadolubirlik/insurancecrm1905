<?php
/**
 * PDF Dışa Aktarımı için Yardımcı Sınıf
 * 
 * @package Insurance_CRM
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Raporu PDF olarak dışa aktarır - TCPDF varsa kullanır, yoksa alternatif çözüm sunar
 */
function insurance_crm_export_pdf($title, $content_callback) {
    // TCPDF var mı kontrol et
    if (file_exists(INSURANCE_CRM_VENDOR_DIR . 'tcpdf/tcpdf.php')) {
        require_once INSURANCE_CRM_VENDOR_DIR . 'tcpdf/tcpdf.php';
        
        // TCPDF örneği oluştur
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Belge bilgilerini ayarla
        $pdf->SetCreator('Insurance CRM');
        $pdf->SetAuthor('Insurance CRM');
        $pdf->SetTitle($title);
        
        // Başlık ve alt bilgiler
        $pdf->SetHeaderData('', 0, $title, '', array(0,0,0), array(255,255,255));
        $pdf->setHeaderFont(Array('dejavusans', '', 11));
        $pdf->setFooterFont(Array('dejavusans', '', 8));
        
        // Sayfa kenar boşlukları
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);
        
        // Otomatik sayfa sonu
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Yazı tipi
        $pdf->SetFont('dejavusans', '', 10);
        
        // İlk sayfayı ekle
        $pdf->AddPage();
        
        // İçeriği callback ile ekle
        if (is_callable($content_callback)) {
            call_user_func($content_callback, $pdf);
        }
        
        // Dosyayı çıktıla ve indir
        $pdf->Output($title . '.pdf', 'D');
        exit;
    } else {
        // TCPDF yoksa alternatif çözüm: HTML olarak indirme
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_title($title) . '.html"');
        
        echo '<!DOCTYPE html>';
        echo '<html><head><meta charset="UTF-8"><title>' . esc_html($title) . '</title>';
        echo '<style>
            body { font-family: Arial, sans-serif; }
            h1 { text-align: center; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            th { background-color: #f2f2f2; }
        </style>';
        echo '</head><body>';
        echo '<h1>' . esc_html($title) . '</h1>';
        
        // İçeriği callback ile ekle
        if (is_callable($content_callback)) {
            call_user_func($content_callback);
        }
        
        echo '</body></html>';
        exit;
    }
}