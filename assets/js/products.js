import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];
    common_defs.push({
        "targets": 3,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function( index, value ) {
                    if (Object.keys(value).length) {
                        $.each(data, function( index, value ) {
                            var pOrder = $('<p/>').append('<b>' + value.property_name + ':</b> ').append('<i>'+value.property_value+'</i>');
                            divTag.append(pOrder);
                        })
                    }
                });
            }

            return divTag.html();
        }
    });

    common_defs.push({
        "targets": 6,
        data: 'action',
        render: function (data, type, row, meta) {
            return '    <!-- Button trigger modal -->\n' +
                '    <button type="button" class="btn btn-primary" data-product-id="' + row.id + '" data-toggle="modal" data-target="#exampleModal">\n' +
                '        Редагувати\n' +
                '    </button>';
        }
    });

    const collectionData = window.Routing
        .generate('admin-products-data-table');

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
    let exampleModal = $('#exampleModal');
    exampleModal.on('show.bs.modal', function (event) {
        var modal = $(this);
        let form = modal.find("form");

        modal.find('#save_product').remove();
        modal.find('.row').remove();
        form.find('input').val('');

        var button = $(event.relatedTarget); // Button that triggered the modal
        let productId = button.data('productId');
        if (productId !== undefined) {
            $.ajax({
                type: "GET",
                url: window.Routing
                    .generate('admin-product-get') + '/' + productId,
                error: (result) => {
                    console.log(result.responseJSON.status);
                },
                success: (data) => {
                    console.log(data);
                }
            })
        } else {
            var divTagRow = $('<div/>', {'class': "row"});
            divTagRow.attr('order', 0);

            var divTagColPlus = $('<div/>', {'class': "col text-right remove_block"});
            divTagColPlus.append('<i class="fas fa-plus-circle"></i>');
            divTagRow.append(divTagColPlus);

            form.append(divTagRow);
        }

        modal.on('click', '.remove_block .fa-minus-square', function () {
            let current = $(this);
            let block = current.closest('.form-group');
            block.remove();
        });

        divTagRow.on('click', function () {
            let order = parseInt($('#createProduct .row').attr('order')) + 1;
            form.append(addPropertiesBlock(order));
            $('#createProduct .row').attr('order', order);
        })

        form.append('<button id="save_product" type="button" class="btn btn-primary">Зберегти</button>')

        $('.btn#save_product').on('click', function () {
            let createProduct = $('#createProduct');

            let serialize = createProduct.serialize();

            const app_rest_admin_brand_editbrand = window.Routing
                .generate('admin-products-create');

            $.ajax({
                type: "POST",
                url: app_rest_admin_brand_editbrand,
                data: serialize,
                error: (result) => {
                    createProduct.find('.required_args').remove();
                    console.log(result.responseJSON.status);
                },
                success: (data) => {
                    exampleModal.modal('toggle');
                    table.ajax.reload(null, false);
                }
            });
        });

        function addPropertiesBlock(order)
        {
            var divTag = $('<div/>', {'class': "form-group"});

            let label1 = $("<label>");
            label1.attr({'for': 'property_value'});
            let input1 = $('<input>', {
                'id': 'property_value',
                'class': 'form-control',
                'name': 'product_properties['+order+'][property_value]'
            });
            let small1 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('назва властивості');

            let label2 = $("<label>");
            label2.attr({'for': 'property_name'});
            let input2 = $('<input>', {
                'id': 'property_name',
                'class': 'form-control',
                'name': 'product_properties['+order+'][property_name]'
            });
            let small2 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('значення властивості');

            divTag.append(label1).append(input1).append(small1);
            divTag.append(label2).append(input2).append(small2);
            var divTagColMinus = $('<div/>', {'class': "col text-right remove_block"});
            divTagColMinus.append('<i class="fas fa-minus-square"></i>')
            divTag.append(divTagColMinus);

            return divTag;
        }
    })
});