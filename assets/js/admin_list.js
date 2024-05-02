import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];
    common_defs.push({
        "targets": 10,
        "data": 'order_info',
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
        .generate('admin-telegram-users');

    table = $('#telegramUserTable').DataTable({
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': collectionData,
            "data": function ( d ) {
                console.log('ajax data', d);
            }
        },
        columns: th_keys,
        "columnDefs": common_defs
    });
});