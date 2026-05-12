import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    let table;
    const defs = [];

    // origin_id — compact monospace, prefixed with source initial
    defs.push({
        targets: 0,
        render: (data, type, row) => {
            const tag = row.source === 'tg' ? 'T' : 'W';
            return '<span class="row-id">' + tag + ':' + row.origin_id + '</span>';
        },
    });

    // User cell — display name + handle (email or @username)
    defs.push({
        targets: 1,
        orderable: true,
        render: (data, type, row) => {
            const display = row.display_name && row.display_name.trim() ? row.display_name : '—';
            const handle = row.handle ? escapeHtml(row.handle) : '';
            return '<div class="user-cell">' +
                '<div class="user-name">' + escapeHtml(display) + '</div>' +
                (handle ? '<div class="user-handle">' + (row.source === 'tg' ? '@' + handle : handle) + '</div>' : '') +
                '</div>';
        },
    });

    // Phone
    defs.push({
        targets: 2,
        render: (data, type, row) => row.phone ? '<span class="tel">' + escapeHtml(row.phone) + '</span>' : '<span class="muted">—</span>',
    });

    // Orders chips
    defs.push({
        targets: 3,
        orderable: true,
        render: (data, type, row) => {
            const paidCount = row.orders_paid_count || 0;
            const paidAmount = row.orders_paid_amount || 0;
            const totalCount = row.orders_total_count || 0;
            const pendingCount = Math.max(0, totalCount - paidCount);

            if (!totalCount) return '<span class="muted">немає</span>';
            const paidChip = paidCount > 0
                ? '<span class="orders-chip paid" title="Оплачені">' + paidCount + ' • ₴' + formatMoney(paidAmount) + '</span>'
                : '';
            const pendingChip = pendingCount > 0
                ? '<span class="orders-chip pending" title="Очікують оплати">' + pendingCount + ' очік.</span>'
                : '';
            return '<button type="button" class="orders-summary-btn" data-source="' + row.source + '" data-origin-id="' + row.origin_id + '">' +
                (paidChip || '<span class="orders-chip empty">0</span>') + pendingChip +
                '</button>';
        },
    });

    // created_at
    defs.push({
        targets: 4,
        render: (data, type, row) => row.created_at ? '<span class="ts-cell">' + row.created_at + '</span>' : '',
    });

    // last_visit
    defs.push({
        targets: 5,
        render: (data, type, row) => row.last_visit ? '<span class="ts-cell">' + row.last_visit + '</span>' : '',
    });

    // source pill
    defs.push({
        targets: 6,
        orderable: false,
        render: (data, type, row) => row.source === 'tg'
            ? '<span class="src-pill tg" title="Telegram"><i class="fab fa-telegram-plane"></i> Telegram</span>'
            : '<span class="src-pill web" title="Email/Web"><i class="fas fa-envelope"></i> Web</span>',
    });

    // actions
    defs.push({
        targets: 7,
        orderable: false,
        render: (data, type, row) =>
            '<button type="button" class="action-btn action-edit orders-summary-btn" data-source="' + row.source + '" data-origin-id="' + row.origin_id + '" title="Замовлення">' +
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
                d.filter_source = $('#filterSource').val();
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
        $('#filterOrders').val('');
        $('#filterRegFrom').val('');
        $('#filterRegTo').val('');
        $('#filterSource').val('');
        table.ajax.reload();
    });

    const $modal = $('#userOrdersModal');
    $('#telegramUserTable').on('click', '.orders-summary-btn', function () {
        const source = $(this).data('source');
        const originId = $(this).data('originId');
        if (!source || !originId) return;
        openOrdersModal(source, originId);
    });

    function openOrdersModal(source, originId) {
        $modal.find('.uo-body').html('<div class="uo-loading">Завантаження…</div>');
        $modal.find('.uo-user').text('');
        $modal.modal('show');
        $.getJSON('/admin/users/' + source + '/' + originId + '/orders.json')
            .done(data => renderUserOrders(data))
            .fail(() => $modal.find('.uo-body').html('<div class="uo-error">Не вдалось завантажити замовлення.</div>'));
    }

    function renderUserOrders(data) {
        const parts = [data.user.name];
        if (data.user.extra) parts.push(data.user.extra);
        if (data.user.phone) parts.push(data.user.phone);
        $modal.find('.uo-user').text(parts.join(' • '));

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
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
});
