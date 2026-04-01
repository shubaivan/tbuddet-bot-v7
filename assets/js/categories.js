document.addEventListener("DOMContentLoaded", function () {
    $(document).on('click', '.delete-model', function () {
        alert('Видалти?')
        var button = $(this); // Button that triggered the modal
        let modelId = button.data('modelId');
        $.ajax({
            type: "DELETE",
            url: window.Routing
                .generate('admin-category-delete') + '/' + modelId,
            error: (result) => {
                console.log(result);
            },
            success: (data) => {
                console.log(data);
                table.ajax.reload(null, false);
            }
        })
    });
    let table;
    var common_defs = [];

    common_defs.push({
        "targets": 1,
        "render": function ( data, type, row, meta ) {
            var divTag = $('<div/>');
            $.each(row.parents, function( index, value ) {
                var pOrder = $('<p/>').append('<b>' + value + '</b> ');
                divTag.append(pOrder);
            })
            return divTag.html();
        }
    })

    common_defs.push({
        "targets": 2,
        "render": function ( data, type, row, meta ) {
            let imgs = '';
            $.each(row.filePath, function( index, filePath ) {
                imgs = imgs + '<img src="'+filePath+'" class="img-thumbnail"><br>';
            })
            return imgs;
        }
    })

    common_defs.push({
        "targets": 3,
        "render": function ( data, type, row, meta ) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function (language, valueOfLanguage) {
                    var pOrder = $('<p/>')
                        .append('<b>Мова: ' + language + '</b>; ')
                        .append('<i>' + valueOfLanguage + '</i>; ')
                    ;
                    divTag.append(pOrder);
                });
            }

            return divTag.html();
        }
    })

    common_defs.push({
        "targets": 7,
        data: 'action',
        render: function (data, type, row, meta) {
            return '<a href="/admin/category/form/' + row.id + '" target="_blank" class="btn btn-primary btn-sm">Редагувати</a> ' +
                '<button class="btn btn-danger btn-sm delete-model" data-model-id="' + row.id + '">Видалити</button>';
        }
    });

    const collectionData = window.Routing
        .generate('admin-categories-data-table');

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
                console.log('ajax data', d);
            }
        },
        columns: th_keys,
        "columnDefs": common_defs
    });
});
