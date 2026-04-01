import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];

    common_defs.push({
        "targets": 7,
        "data": 'order_info',
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            $.each(data.split('|'), function( index, value ) {
                var pOrder = $('<p/>').append('<i>'+value+'</i>');
                divTag.append(pOrder);
            });

            return divTag.html();
        }
    });

    const collectionData = window.Routing
        .generate('admin-users-data-table');

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
                d.filter_orders = $('#filterOrders').val();
                d.filter_reg_from = $('#filterRegFrom').val();
                d.filter_reg_to = $('#filterRegTo').val();
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
        $('#filterOrders').val('');
        $('#filterRegFrom').val('');
        $('#filterRegTo').val('');
        table.ajax.reload();
    });
});