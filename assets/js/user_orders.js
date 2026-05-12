import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    let table;
    const defs = [];

    // 0 — id (clickable link to detail)
    defs.push({
        targets: 0,
        render: (data, type) => {
            if (type === 'display' && data) return '<a class="order-id-link" href="/admin/orders/' + data + '">#' + data + '</a>';
            return data;
        },
    });

    // 1 — total_amount with currency
    defs.push({
        targets: 1,
        render: (data, type, row) => {
            if (type !== 'display') return data;
            const v = Math.round(Number(data) || 0).toLocaleString('uk-UA');
            return '<span class="order-amount">₴' + v + '</span>';
        },
    });

    // 2 — order_status (status pill)
    defs.push({
        targets: 2,
        render: (data, type) => {
            if (type !== 'display') return data;
            const map = {
                'new':        ['status-new',        'Нове'],
                'processing': ['status-processing', 'В обробці'],
                'shipped':    ['status-shipped',    'Відправлено'],
                'delivered':  ['status-delivered',  'Доставлено'],
                'cancelled':  ['status-cancelled',  'Скасовано'],
            };
            const m = map[data];
            return m ? '<span class="order-status-pill ' + m[0] + '">' + m[1] + '</span>' : (data || '');
        },
    });

    // 3 — purchase_source pill
    defs.push({
        targets: 3,
        orderable: false,
        render: (data, type) => {
            if (type !== 'display') return data;
            if (data === 'tg')  return '<span class="src-pill tg" title="Telegram"><i class="fab fa-telegram-plane"></i> Telegram</span>';
            if (data === 'web') return '<span class="src-pill web" title="Email/Web"><i class="fas fa-envelope"></i> Web</span>';
            return '<span class="muted">—</span>';
        },
    });

    // 4 — Nova Poshta tracking
    defs.push({
        targets: 4,
        render: (data, type) => {
            if (type !== 'display') return data;
            if (data) return '<a href="https://novaposhta.ua/tracking/?cargo_number=' + data + '" target="_blank">' + data + '</a>';
            return '';
        },
    });

    // 7 — liq_pay_status
    defs.push({
        targets: 7,
        render: (data, type) => {
            if (type !== 'display') return data;
            if (data === 'success') return '<span style="color:#2e7d32;font-weight:600;">Оплачено</span>';
            if (data) return '<span style="color:#c62828;">' + data + '</span>';
            return '<span style="color:#999;">Очікує</span>';
        },
    });

    // Non-orderable: product_info, c_user_info, t_user_info
    defs.push({ targets: [8, 9, 10], orderable: false });

    const url = window.Routing.generate('admin-orders-data-table');

    table = $('#telegramUserTable').DataTable({
        order: [[0, 'desc']],
        responsive: true,
        fixedHeader: true,
        processing: true,
        serverSide: true,
        serverMethod: 'post',
        ajax: {
            url,
            data: function (d) {
                d.filter_status = $('#filterStatus').val();
                d.filter_payment = $('#filterPayment').val();
                d.filter_date_from = $('#filterDateFrom').val();
                d.filter_date_to = $('#filterDateTo').val();
                d.filter_hide_unpaid = $('#filterHideUnpaid').is(':checked') ? '1' : '0';
                d.filter_source = $('#filterSource').val();
            },
            dataSrc: function (json) {
                if (json && json.stats) renderStats(json.stats);
                return json.data || [];
            },
        },
        columns: th_keys,
        columnDefs: defs,
        language: {
            search: 'Пошук:',
            lengthMenu: 'Показати _MENU_ записів',
            info: 'Записи _START_ - _END_ з _TOTAL_',
            infoEmpty: 'Немає записів',
            zeroRecords: 'Нічого не знайдено',
            paginate: { previous: '&larr;', next: '&rarr;' },
        },
    });

    $('#applyFilters').on('click', () => table.ajax.reload());
    $('#resetFilters').on('click', () => {
        $('#filterStatus').val('');
        $('#filterPayment').val('');
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        $('#filterSource').val('');
        $('#filterHideUnpaid').prop('checked', true);
        table.ajax.reload();
    });
    $('#filterHideUnpaid').on('change', () => table.ajax.reload());

    function renderStats(stats) {
        const $bar = $('#ordersStatsBar');
        $bar.find('[data-stat="paid_amount"]').text('₴' + Math.round(stats.paid_amount || 0).toLocaleString('uk-UA'));
        $bar.find('[data-stat="paid_count"]').text((stats.paid_count || 0) + ' замовлень • ' + (stats.source_tg || 0) + ' TG • ' + (stats.source_web || 0) + ' Web');
        $bar.find('[data-stat="status_new"]').text(stats.status_new || 0);
        $bar.find('[data-stat="status_processing"]').text(stats.status_processing || 0);
        $bar.find('[data-stat="status_shipped"]').text(stats.status_shipped || 0);
        $bar.find('[data-stat="status_delivered"]').text(stats.status_delivered || 0);
        $bar.find('[data-stat="status_cancelled"]').text(stats.status_cancelled || 0);
        $bar.find('[data-stat="unpaid_count"]').text(stats.unpaid_count || 0);
        $bar.prop('hidden', false);
    }
});
