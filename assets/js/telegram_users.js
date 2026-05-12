import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    let table;
    const defs = [];

    // id — compact monospace
    defs.push({
        targets: 0,
        render: (data, type, row) => '<span class="row-id">#' + row.id + '</span>',
    });

    // Combined user identity: bold display name + @username + telegram_id
    defs.push({
        targets: 1,
        orderable: false,
        render: (data, type, row) => {
            const name = (row.first_name || '') + ' ' + (row.last_name || '');
            const display = name.trim() || '—';
            const handle = row.username ? '@' + row.username : '';
            return '<div class="user-cell">' +
                '<div class="user-name">' + escapeHtml(display) + '</div>' +
                (handle ? '<div class="user-handle">' + escapeHtml(handle) + '</div>' : '') +
                '</div>';
        },
    });

    // Phone — plain
    defs.push({
        targets: 2,
        render: (data, type, row) => row.phone_number ? '<span class="tel">' + escapeHtml(row.phone_number) + '</span>' : '<span class="muted">—</span>',
    });

    // Orders summary — chips. Paid (green) shows count + total. Pending (gray) shows leftover count.
    defs.push({
        targets: 3,
        orderable: false,
        render: (data, type, row) => {
            const paidCount = parseInt(row.orders_paid_count || 0, 10);
            const paidAmount = parseFloat(row.orders_paid_amount || 0);
            const totalCount = parseInt(row.orders_total_count || 0, 10);
            const pendingCount = Math.max(0, totalCount - paidCount);

            if (!totalCount) {
                return '<span class="muted">немає</span>';
            }
            const paidChip = paidCount > 0
                ? '<span class="orders-chip paid" title="Оплачені">' + paidCount + ' • ₴' + formatMoney(paidAmount) + '</span>'
                : '';
            const pendingChip = pendingCount > 0
                ? '<span class="orders-chip pending" title="Очікують оплати">' + pendingCount + ' очік.</span>'
                : '';
            return '<button type="button" class="orders-summary-btn" data-user-id="' + row.id + '">' +
                (paidChip || '<span class="orders-chip empty">0</span>') + pendingChip +
                '</button>';
        },
    });

    // start
    defs.push({
        targets: 4,
        render: (data, type, row) => row.start ? '<span class="ts-cell">' + row.start + '</span>' : '',
    });

    // last_visit
    defs.push({
        targets: 5,
        render: (data, type, row) => row.last_visit ? '<span class="ts-cell">' + row.last_visit + '</span>' : '',
    });

    // actions — link to view orders
    defs.push({
        targets: 6,
        orderable: false,
        render: (data, type, row) =>
            '<button type="button" class="action-btn action-edit orders-summary-btn" data-user-id="' + row.id + '" title="Замовлення">' +
            '<i class="fas fa-eye"></i></button>',
    });

    const url = window.Routing.generate('admin-users-data-table');

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
                d.filter_orders = $('#filterOrders').val();
                d.filter_reg_from = $('#filterRegFrom').val();
                d.filter_reg_to = $('#filterRegTo').val();
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

    // Filter buttons
    $('#applyFilters').on('click', () => table.ajax.reload());
    $('#resetFilters').on('click', () => {
        $('#filterOrders').val('');
        $('#filterRegFrom').val('');
        $('#filterRegTo').val('');
        table.ajax.reload();
    });

    // Orders modal: click chip → load JSON → render
    const $modal = $('#userOrdersModal');
    $('#telegramUserTable').on('click', '.orders-summary-btn', function () {
        const userId = $(this).data('userId');
        if (!userId) return;
        openOrdersModal(userId);
    });

    function openOrdersModal(userId) {
        $modal.find('.uo-body').html('<div class="uo-loading">Завантаження…</div>');
        $modal.find('.uo-user').text('');
        $modal.modal('show');
        $.getJSON('/admin/users/' + userId + '/orders.json')
            .done(data => renderUserOrders(data))
            .fail(() => $modal.find('.uo-body').html('<div class="uo-error">Не вдалось завантажити замовлення.</div>'));
    }

    function renderUserOrders(data) {
        $modal.find('.uo-user').text(
            data.user.name + (data.user.phone ? ' • ' + data.user.phone : '')
        );
        if (!data.orders.length) {
            $modal.find('.uo-body').html('<div class="uo-empty">У користувача поки немає замовлень.</div>');
            return;
        }
        const rows = data.orders.map(o => {
            const paidBadge = o.is_paid
                ? '<span class="uo-pill paid">Оплачено</span>'
                : '<span class="uo-pill pending">Не оплачено</span>';
            const statusBadge = o.status ? '<span class="uo-pill status">' + escapeHtml(o.status) + '</span>' : '';
            return '<a class="uo-row" href="' + o.detail_url + '" target="_blank">' +
                '<span class="uo-id">#' + o.id + '</span>' +
                '<span class="uo-amount">₴' + formatMoney(o.total_amount) + '</span>' +
                paidBadge + statusBadge +
                '<span class="uo-date">' + escapeHtml(o.created_at || '') + '</span>' +
                '</a>';
        }).join('');
        $modal.find('.uo-body').html('<div class="uo-list">' + rows + '</div>');
    }

    function formatMoney(v) {
        v = Math.round(Number(v) || 0);
        return v.toLocaleString('uk-UA');
    }
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
});
