import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    let table;
    var common_defs = [];

    // Make order ID a clickable link
    common_defs.push({
        "targets": 0,
        "render": function (data, type, row) {
            if (type === 'display' && data) {
                return '<a href="/admin/orders/' + data + '">#' + data + '</a>';
            }
            return data;
        }
    });

    // Style order_status with badge
    common_defs.push({
        "targets": 2,
        "render": function (data, type, row) {
            if (type !== 'display') return data;
            const labels = {
                'new': '<span style="background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-size:12px;">Нове</span>',
                'processing': '<span style="background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:10px;font-size:12px;">В обробці</span>',
                'shipped': '<span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:12px;">Відправлено</span>',
                'delivered': '<span style="background:#e0f2f1;color:#00695c;padding:2px 8px;border-radius:10px;font-size:12px;">Доставлено</span>',
                'cancelled': '<span style="background:#fce4ec;color:#c62828;padding:2px 8px;border-radius:10px;font-size:12px;">Скасовано</span>',
            };
            return labels[data] || data || '';
        }
    });

    // Tracking number with link
    common_defs.push({
        "targets": 3,
        "render": function (data, type, row) {
            if (type === 'display' && data) {
                return '<a href="https://novaposhta.ua/tracking/?cargo_number=' + data + '" target="_blank" title="Відстежити">' + data + '</a>';
            }
            return data || '';
        }
    });

    // LiqPay status styling
    common_defs.push({
        "targets": 6,
        "render": function (data, type, row) {
            if (type !== 'display') return data;
            if (data === 'success') return '<span style="color:#2e7d32;font-weight:600;">Оплачено</span>';
            if (data) return '<span style="color:#c62828;">' + data + '</span>';
            return '<span style="color:#999;">Очікує</span>';
        }
    });

    // Non-orderable columns: t_user_info, c_user_info, product_info
    common_defs.push({ "targets": [7, 8, 9], "orderable": false });

    const collectionData = window.Routing
        .generate('admin-orders-data-table');

    table = $('#telegramUserTable').DataTable({
        'order': [[0, 'desc']],
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': collectionData,
            "data": function ( d ) {
                d.filter_status = $('#filterStatus').val();
                d.filter_payment = $('#filterPayment').val();
                d.filter_date_from = $('#filterDateFrom').val();
                d.filter_date_to = $('#filterDateTo').val();
            }
        },
        columns: th_keys,
        "columnDefs": common_defs,
        "language": {
            "search": "Пошук:",
            "lengthMenu": "Показати _MENU_ записів",
            "info": "Записи _START_ - _END_ з _TOTAL_",
            "infoEmpty": "Немає записів",
            "zeroRecords": "Нічого не знайдено",
            "paginate": { "previous": "&larr;", "next": "&rarr;" }
        }
    });

    // Filter buttons
    $('#applyFilters').on('click', function() { table.ajax.reload(); });
    $('#resetFilters').on('click', function() {
        $('#filterStatus').val('');
        $('#filterPayment').val('');
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        table.ajax.reload();
    });
});
