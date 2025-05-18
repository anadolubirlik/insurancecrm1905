/**
 * Representative Panel JavaScript
 */
jQuery(document).ready(function($) {
    // Hızlı Ekle dropdown
    $('#quick-add-toggle').on('click', function(e) {
        e.preventDefault();
        $('.quick-add-dropdown .dropdown-content').toggleClass('show');
    });
    
    // Bildirimler dropdown
    $('#notifications-toggle').on('click', function(e) {
        e.preventDefault();
        $('.notifications-dropdown .dropdown-content').toggleClass('show');
    });
    
    // Dropdown dışına tıklandığında kapat
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.quick-add-dropdown').length) {
            $('.quick-add-dropdown .dropdown-content').removeClass('show');
        }
        
        if (!$(e.target).closest('.notifications-dropdown').length) {
            $('.notifications-dropdown .dropdown-content').removeClass('show');
        }
    });
    
    // Eğer ChartJS ve productionChart elementi varsa grafik çiz
    if (typeof Chart !== 'undefined' && $('#productionChart').length > 0) {
        const ctx = document.getElementById('productionChart').getContext('2d');
        
    
    // Form submit butonları için onay sorgusu
    $('.confirm-action').on('click', function(e) {
        if (!confirm($(this).data('confirm') || 'Bu işlemi onaylıyor musunuz?')) {
            e.preventDefault();
        }
    });
    
    // DatePicker eklentisi
    if ($.fn.datepicker) {
        $('.datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true
        });
    }
});