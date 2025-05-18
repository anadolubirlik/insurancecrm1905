<?php
// Bu kod parçası, admin/partials/insurance-crm-admin-reports.php dosyasındaki
// PDF ve Excel export işlemlerini güncellemek için kullanılacak

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
        // PDF dışa aktarma - helper fonksiyon kullanarak
        insurance_crm_export_pdf($report_title, function($pdf = null) use ($summary_stats, $task_stats, $customer_stats, $policies, $report_title) {
            if ($pdf) {
                // TCPDF kullanılıyorsa burası çalışır
                // Burada TCPDF için gereken içerik eklenir (mevcut export_report_to_pdf fonksiyonundaki gibi)
                
                // İçerik başlığı
                $pdf->SetFont('dejavusans', 'B', 16);
                $pdf->Cell(0, 10, $report_title, 0, 1, 'C');
                $pdf->Ln(5);
                
                // Özet İstatistikler
                // (TCPDF formatında içerik eklemek için gereken kodlar)
            } else {
                // HTML alternatifi kullanılıyorsa burası çalışır
                ?>
                <h2><?php echo esc_html(__('Özet İstatistikler', 'insurance-crm')); ?></h2>
                <table>
                    <tr>
                        <th><?php echo esc_html(__('Toplam Poliçe', 'insurance-crm')); ?></th>
                        <td><?php echo number_format(isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(__('Toplam Prim', 'insurance-crm')); ?></th>
                        <td><?php echo number_format(isset($summary_stats->total_premium) ? $summary_stats->total_premium : 0, 2) . ' ₺'; ?></td>
                    </tr>
                    <!-- Diğer özet istatistikler -->
                </table>
                
                <!-- Burada diğer tablo ve veriler için HTML formatında çıktılar eklenir -->
                <?php
            }
        });
    } elseif ($export_type === 'excel') {
        // Excel dışa aktarma - helper fonksiyon kullanarak
        insurance_crm_export_excel($report_title, function($spreadsheet_or_csv) use ($summary_stats, $task_stats, $customer_stats, $policies, $report_title) {
            if (is_object($spreadsheet_or_csv) && $spreadsheet_or_csv instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
                // PhpSpreadsheet kullanılıyorsa burası çalışır
                $sheet = $spreadsheet_or_csv->getActiveSheet();
                $sheet->setTitle(__('Özet', 'insurance-crm'));
                
                // Başlık
                $sheet->setCellValue('A1', $report_title);
                $sheet->mergeCells('A1:D1');
                // Geri kalan PhpSpreadsheet işlemleri eklenir
            } else {
                // CSV alternatifi kullanılıyorsa burası çalışır
                $csv = $spreadsheet_or_csv; // Bu bir fopen kaynağıdır
                
                // Özet İstatistikler
                fputcsv($csv, array(__('Özet İstatistikler', 'insurance-crm')));
                fputcsv($csv, array(
                    __('Toplam Poliçe', 'insurance-crm'),
                    __('Toplam Prim', 'insurance-crm'),
                    __('Yeni Müşteri', 'insurance-crm'),
                    __('Yenileme Oranı', 'insurance-crm')
                ));
                
                fputcsv($csv, array(
                    isset($summary_stats->total_policies) ? $summary_stats->total_policies : 0,
                    isset($summary_stats->total_premium) ? $summary_stats->total_premium : 0,
                    isset($summary_stats->new_customers) ? $summary_stats->new_customers : 0,
                    isset($summary_stats->renewal_rate) ? $summary_stats->renewal_rate . '%' : '0%'
                ));
                
                // Diğer CSV verilerini ekleyin
            }
        });
    }
}
?>