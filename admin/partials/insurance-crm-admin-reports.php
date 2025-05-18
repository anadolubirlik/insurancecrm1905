<?php
/**
 * Raporlar Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.0 (2025-05-02)
 */

if (!defined('WPINC')) {
    die;
}

// Raporlama sınıfını başlat
$reports = new Insurance_CRM_Reports();

// Tarih filtrelerini al
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$policy_type = isset($_GET['policy_type']) ? sanitize_text_field($_GET['policy_type']) : '';

// İstatistikleri al
$summary_stats = $reports->get_summary_stats($start_date, $end_date, $policy_type);
$task_stats = $reports->get_task_stats($start_date, $end_date);
$customer_stats = $reports->get_customer_stats($start_date, $end_date);

// Detaylı poliçe listesini al
$policies = $reports->get_detailed_policies($start_date, $end_date, $policy_type);

// Dışa aktarma işlemi
if (isset($_GET['export'])) {
    $export_type = sanitize_text_field($_GET['export']);
    
    // Raporun başlığı
    $report_title = sprintf(
        __('Sigorta CRM Raporu - %s ile %s arası', 'insurance-crm'),
        date_i18n('d.m.Y', strtotime($start_date)),
        date_i18n('d.m.Y', strtotime($end_date))
    );
    
    // Poliçe türü filtresi varsa başlığa ekle
    if (!empty($policy_type)) {
        $report_title .= ' - ' . ucfirst($policy_type) . ' ' . __('poliçeleri', 'insurance-crm');
    }
    
    if ($export_type === 'pdf') {
        // PDF dışa aktarma
        export_report_to_pdf($report_title, $summary_stats, $task_stats, $customer_stats, $policies);
    } 
    elseif ($export_type === 'excel') {
        // Excel dışa aktarma (XLSX formatında)
        export_report_to_excel($report_title, $summary_stats, $task_stats, $customer_stats, $policies);
    }
}

/**
 * Raporu PDF olarak dışa aktarır
 */
function export_report_to_pdf($title, $summary_stats, $task_stats, $customer_stats, $policies) {
    // FPDF veya TCPDF kütüphanesini yüklemeyi dene
    if (!class_exists('TCPDF')) {
        // TCPDF kütüphanesi yoksa, indirilmesi gerektiğini belirt
        echo '<div class="notice notice-error"><p>';
        echo __('PDF çıktısı için TCPDF kütüphanesi gereklidir. Plugin klasörüne TCPDF kütüphanesini ekleyin veya Composer ile yükleyin.', 'insurance-crm');
        echo '</p></div>';
        return;
    }
    
    // PDF oluştur (TCPDF kullanarak)
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
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
    
    // İçerik başlığı
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(5);
    
    // 1. Özet İstatistikler
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 10, __('Özet İstatistikler', 'insurance-crm'), 0, 1);
    $pdf->SetFont('dejavusans', '', 10);
    
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(90, 8, __('Toplam Poliçe', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(85, 8, number_format(isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0), 1, 1, 'L');
    
    $pdf->Cell(90, 8, __('Toplam Prim', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(85, 8, number_format(isset($summary_stats->total_premium) ? $summary_stats->total_premium : 0, 2) . ' ₺', 1, 1, 'L');
    
    $pdf->Cell(90, 8, __('Yeni Müşteri', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(85, 8, number_format(isset($summary_stats->new_customers) ? $summary_stats->new_customers : 0), 1, 1, 'L');
    
    $pdf->Cell(90, 8, __('Yenileme Oranı', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(85, 8, number_format(isset($summary_stats->renewal_rate) ? $summary_stats->renewal_rate : 0, 1) . '%', 1, 1, 'L');
    
    $pdf->Ln(5);
    
    // 2. Poliçe Türü Dağılımı
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 10, __('Poliçe Türü Dağılımı', 'insurance-crm'), 0, 1);
    $pdf->SetFont('dejavusans', 'B', 10);
    
    // Tablo başlıkları
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(55, 8, __('Poliçe Türü', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(40, 8, __('Adet', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(45, 8, __('Toplam Prim', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(35, 8, __('Oran', 'insurance-crm'), 1, 1, 'L', 1);
    
    $pdf->SetFont('dejavusans', '', 10);
    
    if (isset($summary_stats->policy_type_distribution) && is_array($summary_stats->policy_type_distribution)) {
        $total_policies = isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0;
        
        foreach ($summary_stats->policy_type_distribution as $type) {
            $count = isset($type->count) ? $type->count : 0;
            $percentage = $total_policies > 0 ? ($count / $total_policies) * 100 : 0;
            
            $pdf->Cell(55, 8, isset($type->label) ? $type->label : '', 1);
            $pdf->Cell(40, 8, number_format($count), 1);
            $pdf->Cell(45, 8, number_format(isset($type->total_premium) ? $type->total_premium : 0, 2) . ' ₺', 1);
            $pdf->Cell(35, 8, number_format($percentage, 1) . '%', 1);
            $pdf->Ln();
        }
    }
    
    $pdf->Ln(5);
    
    // 3. Müşteri İstatistikleri
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 10, __('Müşteri İstatistikleri', 'insurance-crm'), 0, 1);
    
    // Kategori Dağılımı
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(0, 8, __('Kategori Dağılımı', 'insurance-crm'), 0, 1);
    $pdf->SetFont('dejavusans', 'B', 10);
    
    // Tablo başlıkları
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(80, 8, __('Kategori', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(55, 8, __('Müşteri Sayısı', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(40, 8, __('Oran', 'insurance-crm'), 1, 1, 'L', 1);
    
    $pdf->SetFont('dejavusans', '', 10);
    
    if (isset($customer_stats->category_distribution) && is_array($customer_stats->category_distribution)) {
        $total_customers = isset($customer_stats->total_customers) ? $customer_stats->total_customers : 0;
        
        foreach ($customer_stats->category_distribution as $category) {
            $count = isset($category->count) ? $category->count : 0;
            $percentage = $total_customers > 0 ? ($count / $total_customers) * 100 : 0;
            
            $pdf->Cell(80, 8, isset($category->label) ? $category->label : '', 1);
            $pdf->Cell(55, 8, number_format($count), 1);
            $pdf->Cell(40, 8, number_format($percentage, 1) . '%', 1);
            $pdf->Ln();
        }
    }
    
    $pdf->Ln(5);
    
    // En Çok Poliçesi Olan Müşteriler
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(0, 8, __('En Çok Poliçesi Olan Müşteriler', 'insurance-crm'), 0, 1);
    $pdf->SetFont('dejavusans', 'B', 10);
    
    // Tablo başlıkları
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(80, 8, __('Müşteri', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(45, 8, __('Poliçe Sayısı', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(50, 8, __('Toplam Prim', 'insurance-crm'), 1, 1, 'L', 1);
    
    $pdf->SetFont('dejavusans', '', 10);
    
    if (isset($customer_stats->top_customers) && is_array($customer_stats->top_customers)) {
        foreach ($customer_stats->top_customers as $customer) {
            $pdf->Cell(80, 8, isset($customer->customer_name) ? $customer->customer_name : '', 1);
            $pdf->Cell(45, 8, number_format(isset($customer->policy_count) ? $customer->policy_count : 0), 1);
            $pdf->Cell(50, 8, number_format(isset($customer->total_premium) ? $customer->total_premium : 0, 2) . ' ₺', 1);
            $pdf->Ln();
        }
    }
    
    // Yeni sayfa ekle
    $pdf->AddPage();
    
    // 4. Detaylı Poliçe Listesi
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 10, __('Detaylı Poliçe Listesi', 'insurance-crm'), 0, 1);
    $pdf->SetFont('dejavusans', 'B', 9); // Daha küçük font kullan
    
    // Tablo başlıkları
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(30, 8, __('Poliçe No', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(40, 8, __('Müşteri', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(25, 8, __('Tür', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(25, 8, __('Başlangıç', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(25, 8, __('Bitiş', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(25, 8, __('Prim', 'insurance-crm'), 1, 0, 'L', 1);
    $pdf->Cell(15, 8, __('Durum', 'insurance-crm'), 1, 1, 'L', 1);
    
    $pdf->SetFont('dejavusans', '', 8);
    
    if (is_array($policies)) {
        foreach ($policies as $policy) {
            $pdf->Cell(30, 7, isset($policy->policy_number) ? $policy->policy_number : '', 1);
            $pdf->Cell(40, 7, isset($policy->customer_name) ? $policy->customer_name : '', 1);
            $pdf->Cell(25, 7, isset($policy->policy_type) ? $policy->policy_type : '', 1);
            $pdf->Cell(25, 7, isset($policy->start_date) ? date_i18n('d.m.Y', strtotime($policy->start_date)) : '', 1);
            $pdf->Cell(25, 7, isset($policy->end_date) ? date_i18n('d.m.Y', strtotime($policy->end_date)) : '', 1);
            $pdf->Cell(25, 7, number_format(isset($policy->premium_amount) ? $policy->premium_amount : 0, 2) . ' ₺', 1);
            $pdf->Cell(15, 7, isset($policy->status) ? $policy->status : '', 1);
            $pdf->Ln();
        }
    }
    
    // Dosyayı çıktıla ve indir
    $pdf->Output($title . '.pdf', 'D'); // D = download
    exit;
}

/**
 * Raporu Excel olarak dışa aktarır (XLSX formatında)
 */
function export_report_to_excel($title, $summary_stats, $task_stats, $customer_stats, $policies) {
    // PhpSpreadsheet kütüphanesini yüklemeyi dene
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'vendor/autoload.php';
        
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // Kütüphane yoksa, indirilmesi gerektiğini belirt
            echo '<div class="notice notice-error"><p>';
            echo __('Excel (XLSX) çıktısı için PhpSpreadsheet kütüphanesi gereklidir. Composer ile "composer require phpoffice/phpspreadsheet" komutu ile yükleyebilirsiniz.', 'insurance-crm');
            echo '</p></div>';
            return;
        }
    }
    
    // XLSX dosyası oluştur
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    
    // Belge özellikleri
    $spreadsheet->getProperties()
        ->setCreator('Insurance CRM')
        ->setLastModifiedBy('Insurance CRM')
        ->setTitle($title)
        ->setSubject('Insurance CRM Raporu')
        ->setDescription('Sigorta CRM raporlama verisi');
        
    // İlk çalışma sayfası
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('Özet', 'insurance-crm'));
    
    // Başlık
    $sheet->setCellValue('A1', $title);
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // 1. Özet İstatistikler
    $sheet->setCellValue('A3', __('Özet İstatistikler', 'insurance-crm'));
    $sheet->mergeCells('A3:D3');
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
    
    $sheet->setCellValue('A4', __('Toplam Poliçe', 'insurance-crm'));
    $sheet->setCellValue('B4', number_format(isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0));
    
    $sheet->setCellValue('A5', __('Toplam Prim', 'insurance-crm'));
    $sheet->setCellValue('B5', number_format(isset($summary_stats->total_premium) ? $summary_stats->total_premium : 0, 2) . ' ₺');
    
    $sheet->setCellValue('A6', __('Yeni Müşteri', 'insurance-crm'));
    $sheet->setCellValue('B6', number_format(isset($summary_stats->new_customers) ? $summary_stats->new_customers : 0));
    
    $sheet->setCellValue('A7', __('Yenileme Oranı', 'insurance-crm'));
    $sheet->setCellValue('B7', number_format(isset($summary_stats->renewal_rate) ? $summary_stats->renewal_rate : 0, 1) . '%');
    
    // 2. Poliçe Türü Dağılımı
    $sheet->setCellValue('A9', __('Poliçe Türü Dağılımı', 'insurance-crm'));
    $sheet->mergeCells('A9:D9');
    $sheet->getStyle('A9')->getFont()->setBold(true)->setSize(12);
    
    $sheet->setCellValue('A10', __('Poliçe Türü', 'insurance-crm'));
    $sheet->setCellValue('B10', __('Adet', 'insurance-crm'));
    $sheet->setCellValue('C10', __('Toplam Prim', 'insurance-crm'));
    $sheet->setCellValue('D10', __('Oran', 'insurance-crm'));
    $sheet->getStyle('A10:D10')->getFont()->setBold(true);
    
    $row = 11;
    
    if (isset($summary_stats->policy_type_distribution) && is_array($summary_stats->policy_type_distribution)) {
        $total_policies = isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0;
        
        foreach ($summary_stats->policy_type_distribution as $type) {
            $count = isset($type->count) ? $type->count : 0;
            $percentage = $total_policies > 0 ? ($count / $total_policies) * 100 : 0;
            
            $sheet->setCellValue('A' . $row, isset($type->label) ? $type->label : '');
            $sheet->setCellValue('B' . $row, $count);
            $sheet->setCellValue('C' . $row, isset($type->total_premium) ? $type->total_premium : 0);
            $sheet->setCellValue('D' . $row, $percentage . '%');
            
            $row++;
        }
    }
    
    // Para birimi formatı (C sütunu için)
    $sheet->getStyle('C11:C' . ($row-1))->getNumberFormat()->setFormatCode('#,##0.00 ₺');
    
    // 3. Yeni çalışma sayfası - Müşteri İstatistikleri
    $customerSheet = $spreadsheet->createSheet();
    $customerSheet->setTitle(__('Müşteri İstatistikleri', 'insurance-crm'));
    
    $customerSheet->setCellValue('A1', $title . ' - ' . __('Müşteri İstatistikleri', 'insurance-crm'));
    $customerSheet->mergeCells('A1:C1');
    $customerSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $customerSheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Kategori Dağılımı
    $customerSheet->setCellValue('A3', __('Kategori Dağılımı', 'insurance-crm'));
    $customerSheet->mergeCells('A3:C3');
    $customerSheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
    
    $customerSheet->setCellValue('A4', __('Kategori', 'insurance-crm'));
    $customerSheet->setCellValue('B4', __('Müşteri Sayısı', 'insurance-crm'));
    $customerSheet->setCellValue('C4', __('Oran', 'insurance-crm'));
    $customerSheet->getStyle('A4:C4')->getFont()->setBold(true);
    
    $row = 5;
    
    if (isset($customer_stats->category_distribution) && is_array($customer_stats->category_distribution)) {
        $total_customers = isset($customer_stats->total_customers) ? $customer_stats->total_customers : 0;
        
        foreach ($customer_stats->category_distribution as $category) {
            $count = isset($category->count) ? $category->count : 0;
            $percentage = $total_customers > 0 ? ($count / $total_customers) * 100 : 0;
            
            $customerSheet->setCellValue('A' . $row, isset($category->label) ? $category->label : '');
            $customerSheet->setCellValue('B' . $row, $count);
            $customerSheet->setCellValue('C' . $row, $percentage . '%');
            
            $row++;
        }
    }
    
    // En Çok Poliçesi Olan Müşteriler
    $customerSheet->setCellValue('A' . ($row + 2), __('En Çok Poliçesi Olan Müşteriler', 'insurance-crm'));
    $customerSheet->mergeCells('A' . ($row + 2) . ':C' . ($row + 2));
    $customerSheet->getStyle('A' . ($row + 2))->getFont()->setBold(true)->setSize(12);
    
    $customerSheet->setCellValue('A' . ($row + 3), __('Müşteri', 'insurance-crm'));
    $customerSheet->setCellValue('B' . ($row + 3), __('Poliçe Sayısı', 'insurance-crm'));
    $customerSheet->setCellValue('C' . ($row + 3), __('Toplam Prim', 'insurance-crm'));
    $customerSheet->getStyle('A' . ($row + 3) . ':C' . ($row + 3))->getFont()->setBold(true);
    
    $row += 4;
    
    if (isset($customer_stats->top_customers) && is_array($customer_stats->top_customers)) {
        foreach ($customer_stats->top_customers as $customer) {
            $customerSheet->setCellValue('A' . $row, isset($customer->customer_name) ? $customer->customer_name : '');
            $customerSheet->setCellValue('B' . $row, isset($customer->policy_count) ? $customer->policy_count : 0);
            $customerSheet->setCellValue('C' . $row, isset($customer->total_premium) ? $customer->total_premium : 0);
            
            $row++;
        }
    }
    
    // Para birimi formatı (C sütunu için)
    $customerSheet->getStyle('C' . ($row - count($customer_stats->top_customers)) . ':C' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00 ₺');
    
    // 4. Yeni çalışma sayfası - Detaylı Poliçe Listesi
    $policySheet = $spreadsheet->createSheet();
    $policySheet->setTitle(__('Poliçe Listesi', 'insurance-crm'));
    
    $policySheet->setCellValue('A1', $title . ' - ' . __('Detaylı Poliçe Listesi', 'insurance-crm'));
    $policySheet->mergeCells('A1:G1');
    $policySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $policySheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $policySheet->setCellValue('A3', __('Poliçe No', 'insurance-crm'));
    $policySheet->setCellValue('B3', __('Müşteri', 'insurance-crm'));
    $policySheet->setCellValue('C3', __('Tür', 'insurance-crm'));
    $policySheet->setCellValue('D3', __('Başlangıç', 'insurance-crm'));
    $policySheet->setCellValue('E3', __('Bitiş', 'insurance-crm'));
    $policySheet->setCellValue('F3', __('Prim', 'insurance-crm'));
    $policySheet->setCellValue('G3', __('Durum', 'insurance-crm'));
    $policySheet->getStyle('A3:G3')->getFont()->setBold(true);
    
    $row = 4;
    
    if (is_array($policies)) {
        foreach ($policies as $policy) {
            $policySheet->setCellValue('A' . $row, isset($policy->policy_number) ? $policy->policy_number : '');
            $policySheet->setCellValue('B' . $row, isset($policy->customer_name) ? $policy->customer_name : '');
            $policySheet->setCellValue('C' . $row, isset($policy->policy_type) ? $policy->policy_type : '');
            
            // Tarih formatı
            if (isset($policy->start_date)) {
                $policySheet->setCellValue('D' . $row, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($policy->start_date)));
                $policySheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('DD.MM.YYYY');
            }
            
            if (isset($policy->end_date)) {
                $policySheet->setCellValue('E' . $row, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($policy->end_date)));
                $policySheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('DD.MM.YYYY');
            }
            
            $policySheet->setCellValue('F' . $row, isset($policy->premium_amount) ? $policy->premium_amount : 0);
            $policySheet->setCellValue('G' . $row, isset($policy->status) ? $policy->status : '');
            
            $row++;
        }
    }
    
    // Para birimi formatı (F sütunu için)
    $policySheet->getStyle('F4:F' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00 ₺');
    
    // Sütun genişliklerini otomatik ayarla
    foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
        $worksheet->calculateColumnWidths();
        foreach(range('A', $worksheet->getHighestColumn()) as $col) {
            $worksheet->getColumnDimension($col)->setAutoSize(true);
        }
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
}
?>

<div class="wrap insurance-crm-wrap">
    <div class="insurance-crm-header">
        <h1><?php _e('Raporlar', 'insurance-crm'); ?></h1>
    </div>

    <!-- Filtre Formu -->
    <div class="insurance-crm-filters">
        <form method="get" class="insurance-crm-filter-form">
            <input type="hidden" name="page" value="insurance-crm-reports">
            
            <div class="filter-row">
                <label for="start_date"><?php _e('Başlangıç Tarihi:', 'insurance-crm'); ?></label>
                <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                
                <label for="end_date"><?php _e('Bitiş Tarihi:', 'insurance-crm'); ?></label>
                <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                
                <label for="policy_type"><?php _e('Poliçe Türü:', 'insurance-crm'); ?></label>
                <select name="policy_type" id="policy_type">
                    <option value=""><?php _e('Tümü', 'insurance-crm'); ?></option>
                    <?php
                    $settings = get_option('insurance_crm_settings');
                    if (isset($settings['default_policy_types']) && is_array($settings['default_policy_types'])) {
                        foreach ($settings['default_policy_types'] as $type) {
                            echo sprintf(
                                '<option value="%s" %s>%s</option>',
                                $type,
                                selected($policy_type, $type, false),
                                ucfirst($type)
                            );
                        }
                    }
                    ?>
                </select>
                
                <?php submit_button(__('Filtrele', 'insurance-crm'), 'primary', 'submit', false); ?>
                
                <div class="export-buttons">
                    <button type="submit" name="export" value="pdf" class="button">
                        <?php _e('PDF İndir', 'insurance-crm'); ?>
                    </button>
                    <button type="submit" name="export" value="excel" class="button">
                        <?php _e('Excel İndir', 'insurance-crm'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Özet İstatistikler -->
    <div class="insurance-crm-stats">
        <div class="insurance-crm-stat-card">
            <h3><?php _e('Toplam Poliçe', 'insurance-crm'); ?></h3>
            <div class="insurance-crm-stat-number">
                <?php echo number_format(isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0); ?>
            </div>
        </div>

        <div class="insurance-crm-stat-card">
            <h3><?php _e('Toplam Prim', 'insurance-crm'); ?></h3>
            <div class="insurance-crm-stat-number">
                <?php echo number_format(isset($summary_stats->total_premium) ? $summary_stats->total_premium : 0, 2) . ' ₺'; ?>
            </div>
        </div>

        <div class="insurance-crm-stat-card">
            <h3><?php _e('Yeni Müşteri', 'insurance-crm'); ?></h3>
            <div class="insurance-crm-stat-number">
                <?php echo number_format(isset($summary_stats->new_customers) ? $summary_stats->new_customers : 0); ?>
            </div>
        </div>

        <div class="insurance-crm-stat-card">
            <h3><?php _e('Yenileme Oranı', 'insurance-crm'); ?></h3>
            <div class="insurance-crm-stat-number">
                <?php echo number_format(isset($summary_stats->renewal_rate) ? $summary_stats->renewal_rate : 0, 1) . '%'; ?>
            </div>
        </div>
    </div>

    <div class="insurance-crm-report-sections">
        <!-- Poliçe Türü Dağılımı -->
        <div class="insurance-crm-report-section">
            <h3><?php _e('Poliçe Türü Dağılımı', 'insurance-crm'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Poliçe Türü', 'insurance-crm'); ?></th>
                        <th><?php _e('Adet', 'insurance-crm'); ?></th>
                        <th><?php _e('Toplam Prim', 'insurance-crm'); ?></th>
                        <th><?php _e('Oran', 'insurance-crm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (isset($summary_stats->policy_type_distribution) && is_array($summary_stats->policy_type_distribution)) {
                        foreach ($summary_stats->policy_type_distribution as $type): 
                            $total_policies = isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0;
                            $count = isset($type->count) ? $type->count : 0;
                            $percentage = $total_policies > 0 ? ($count / $total_policies) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html(isset($type->label) ? $type->label : ''); ?></td>
                        <td><?php echo number_format($count); ?></td>
                        <td><?php echo number_format(isset($type->total_premium) ? $type->total_premium : 0, 2) . ' ₺'; ?></td>
                        <td><?php echo number_format($percentage, 1) . '%'; ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Görev İstatistikleri -->
        <div class="insurance-crm-report-section">
            <h3><?php _e('Görev İstatistikleri', 'insurance-crm'); ?></h3>
            <div class="insurance-crm-stats">
                <div class="insurance-crm-stat-card">
                    <h4><?php _e('Bekleyen Görevler', 'insurance-crm'); ?></h4>
                    <div class="insurance-crm-stat-number">
                        <?php 
                        $pending_count = 0;
                        if (isset($task_stats->status_distribution) && is_array($task_stats->status_distribution)) {
                            $pending_tasks = array_filter($task_stats->status_distribution, function($item) {
                                return isset($item->status) && $item->status === 'pending';
                            });
                            $pending_count = !empty($pending_tasks) ? current($pending_tasks)->count : 0;
                        }
                        echo $pending_count;
                        ?>
                    </div>
                </div>

                <div class="insurance-crm-stat-card">
                    <h4><?php _e('Geciken Görevler', 'insurance-crm'); ?></h4>
                    <div class="insurance-crm-stat-number insurance-crm-text-danger">
                        <?php echo number_format(isset($task_stats->overdue_tasks) ? $task_stats->overdue_tasks : 0); ?>
                    </div>
                </div>

                <div class="insurance-crm-stat-card">
                    <h4><?php _e('Tamamlanan Görevler', 'insurance-crm'); ?></h4>
                    <div class="insurance-crm-stat-number insurance-crm-text-success">
                        <?php 
                        $completed_count = 0;
                        if (isset($task_stats->status_distribution) && is_array($task_stats->status_distribution)) {
                            $completed_tasks = array_filter($task_stats->status_distribution, function($item) {
                                return isset($item->status) && $item->status === 'completed';
                            });
                            $completed_count = !empty($completed_tasks) ? current($completed_tasks)->count : 0;
                        }
                        echo $completed_count;
                        ?>
                    </div>
                </div>

                <div class="insurance-crm-stat-card">
                    <h4><?php _e('Ortalama Tamamlanma Süresi', 'insurance-crm'); ?></h4>
                    <div class="insurance-crm-stat-number">
                        <?php echo number_format(isset($task_stats->avg_completion_time) ? $task_stats->avg_completion_time : 0, 1) . ' ' . __('saat', 'insurance-crm'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Müşteri İstatistikleri -->
        <div class="insurance-crm-report-section">
            <h3><?php _e('Müşteri İstatistikleri', 'insurance-crm'); ?></h3>
            
            <!-- Kategori Dağılımı -->
            <div class="insurance-crm-subsection">
                <h4><?php _e('Kategori Dağılımı', 'insurance-crm'); ?></h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Kategori', 'insurance-crm'); ?></th>
                            <th><?php _e('Müşteri Sayısı', 'insurance-crm'); ?></th>
                            <th><?php _e('Oran', 'insurance-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (isset($customer_stats->category_distribution) && is_array($customer_stats->category_distribution)) {
                            $total_customers = isset($customer_stats->total_customers) ? $customer_stats->total_customers : 0;
                            foreach ($customer_stats->category_distribution as $category): 
                                $count = isset($category->count) ? $category->count : 0;
                                $percentage = $total_customers > 0 ? ($count / $total_customers) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html(isset($category->label) ? $category->label : ''); ?></td>
                            <td><?php echo number_format($count); ?></td>
                            <td><?php echo number_format($percentage, 1) . '%'; ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- En Çok Poliçesi Olan Müşteriler -->
            <div class="insurance-crm-subsection">
                <h4><?php _e('En Çok Poliçesi Olan Müşteriler', 'insurance-crm'); ?></h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Müşteri', 'insurance-crm'); ?></th>
                            <th><?php _e('Poliçe Sayısı', 'insurance-crm'); ?></th>
                            <th><?php _e('Toplam Prim', 'insurance-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (isset($customer_stats->top_customers) && is_array($customer_stats->top_customers)) {
                            foreach ($customer_stats->top_customers as $customer): 
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-customers&action=edit&id=' . (isset($customer->id) ? $customer->id : 0)); ?>">
                                    <?php echo esc_html(isset($customer->customer_name) ? $customer->customer_name : ''); ?>
                                </a>
                            </td>
                            <td><?php echo number_format(isset($customer->policy_count) ? $customer->policy_count : 0); ?></td>
                            <td><?php echo number_format(isset($customer->total_premium) ? $customer->total_premium : 0, 2) . ' ₺'; ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Detaylı Poliçe Listesi -->
        <div class="insurance-crm-report-section">
            <h3><?php _e('Detaylı Poliçe Listesi', 'insurance-crm'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Poliçe No', 'insurance-crm'); ?></th>
                        <th><?php _e('Müşteri', 'insurance-crm'); ?></th>
                        <th><?php _e('Tür', 'insurance-crm'); ?></th>
                        <th><?php _e('Başlangıç', 'insurance-crm'); ?></th>
                        <th><?php _e('Bitiş', 'insurance-crm'); ?></th>
                        <th><?php _e('Prim', 'insurance-crm'); ?></th>
                        <th><?php _e('Durum', 'insurance-crm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (is_array($policies)) {
                        foreach ($policies as $policy):
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . (isset($policy->id) ? $policy->id : 0)); ?>">
                                <?php echo esc_html(isset($policy->policy_number) ? $policy->policy_number : ''); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(isset($policy->customer_name) ? $policy->customer_name : ''); ?></td>
                        <td><?php echo esc_html(isset($policy->policy_type) ? $policy->policy_type : ''); ?></td>
                        <td><?php echo isset($policy->start_date) ? date_i18n('d.m.Y', strtotime($policy->start_date)) : ''; ?></td>
                        <td><?php echo isset($policy->end_date) ? date_i18n('d.m.Y', strtotime($policy->end_date)) : ''; ?></td>
                        <td><?php echo number_format(isset($policy->premium_amount) ? $policy->premium_amount : 0, 2) . ' ₺'; ?></td>
                        <td>
                            <span class="insurance-crm-badge insurance-crm-badge-<?php echo (isset($policy->status) && $policy->status === 'aktif') ? 'success' : 'danger'; ?>">
                                <?php echo esc_html(isset($policy->status) ? $policy->status : ''); ?>
                            </span>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>