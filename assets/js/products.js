import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");
    $(document).on('click', '.delete-product', function () {
        alert('Видалти?')
        var button = $(this); // Button that triggered the modal
        let productId = button.data('productId');
        $.ajax({
            type: "DELETE",
            url: window.Routing
                .generate('admin-product-delete') + '/' + productId,
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
        "targets": 3,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function( index, value ) {
                    var pOrder = $('<p/>').append('<b>' + value.property_name + ':</b> ').append('<i>'+value.property_value+'</i>');
                    divTag.append(pOrder);
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
                '    </button>' +
                '    <button class="btn btn-danger delete-product" data-product-id="' + row.id + '">Видалити</button>   '
                ;
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
        modal.find('.prop_conf').remove();
        modal.find('.prop_set').remove();
        form.find('input').val('');

        var divPropConf = $('<div/>', {'class': "prop_conf"});
        divPropConf.attr('order', 0);

        var divPropSet = $('<div/>', {'class': "prop_set"});

        var divTagColPlus = $('<div/>', {'class': "col text-right remove_block"});
        divTagColPlus.append('<i class="fas fa-plus-circle"></i>');
        divPropConf.append(divTagColPlus);

        form.append(divPropSet);
        form.append(divPropConf);

        var button = $(event.relatedTarget); // Button that triggered the modal
        let productId = button.data('productId');

        if (productId !== undefined) {
            $.ajax({
                type: "GET",
                url: window.Routing
                    .generate('admin-product-get') + '/' + productId,
                error: (result) => {
                    console.log(result);
                },
                success: (data) => {
                    console.log(data);
                    form.find('#exampleModalLabel').text('Редагування')
                    form.find('#product_name').val(data.productName)
                    form.find('#product_price').val(data.price)

                    let product_id_input = $('<input>').attr({
                        type: 'hidden',
                        id: 'product_id',
                        name: 'product_id'
                    });
                    product_id_input.val(data.id);
                    form.append(product_id_input);

                    if (Object.keys(data.productProperties).length) {
                        $.each(data.productProperties, function( index, productProperty ) {
                            if (Object.keys(productProperty).length) {
                                let order = parseInt($('#createProduct .prop_conf').attr('order')) + 1;
                                divPropSet.append(addPropertiesBlock(order, productProperty.property_name, productProperty.property_value));
                                $('#createProduct .prop_conf').attr('order', order )
                            }
                        });
                    }
                }
            })
        } else {
            form.find('#exampleModalLabel').text('Створити новий продукт')
        }

        modal.on('click', '.remove_block .fa-minus-square', function () {
            let current = $(this);
            let block = current.closest('.form-group');
            block.remove();
        });

        divPropConf.on('click', function () {
            let order = parseInt($('#createProduct .prop_conf').attr('order')) + 1;
            divPropSet.append(addPropertiesBlock(order));
            $('#createProduct .prop_conf').attr('order', order);
        })

        form.append('<button id="save_product" type="button" class="btn btn-primary">Зберегти</button>')

        $('.btn#save_product').on('click', function () {
            let createProduct = $('#createProduct');

            let inputColumns = createProduct.find('input');
            if (inputColumns.length) {
                $.each(inputColumns, function (k, v) {
                    $(v).val($.trim($(v).val()));
                })
            }

            let serialize = createProduct.serialize();

            const admin_products_create = window.Routing
                .generate('admin-products-create');

            $.ajax({
                type: "POST",
                url: admin_products_create,
                data: serialize,
                error: (result) => {
                    console.log(result);
                },
                success: (data) => {
                    exampleModal.modal('toggle');
                    table.ajax.reload(null, false);
                }
            });
        });

        function addPropertiesBlock(order, inputName = null, inputValue = null)
        {
            var divTag = $('<div/>', {'class': "form-group"});

            let label1 = $("<label>");
            label1.attr({'for': 'property_value'});
            let input1 = $('<input>', {
                'id': 'property_value',
                'class': 'form-control',
                'name': 'product_properties['+order+'][property_value]'
            });
            if (inputValue !== null) {
                input1.val(inputValue)
            }
            let small1 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('значення властивості');

            let label2 = $("<label>");
            label2.attr({'for': 'property_name'});
            let input2 = $('<input>', {
                'id': 'property_name',
                'class': 'form-control',
                'name': 'product_properties['+order+'][property_name]'
            });
            if (inputName !== null) {
                input2.val(inputName)
            }
            let small2 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('назва властивості');

            divTag.append(label2).append(input2).append(small2);
            divTag.append(label1).append(input1).append(small1);

            var divTagColMinus = $('<div/>', {'class': "col text-right remove_block"});
            divTagColMinus.append('<i class="fas fa-minus-square"></i>')
            divTag.append(divTagColMinus);

            return divTag;
        }
    })
});