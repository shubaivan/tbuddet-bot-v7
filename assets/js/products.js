import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];
    common_defs.push({
        "targets": 4,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function( index, value ) {
                    var pOrder = $('<p/>').append('<b>' + index + ':</b> ').append('<i>'+value+'</i>');
                    divTag.append(pOrder);
                });
            }

            return divTag.html();
        }
    });

    common_defs.push({
        "targets": 5,
        "orderable": false,
    });

    common_defs.push({
        "targets": 6,
        "orderable": false,
    });

    const collectionData = window.Routing
        .generate('admin-products-data-table');

    table = $('#telegramUserTable').DataTable({
        'order': [[1, 'desc']],
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