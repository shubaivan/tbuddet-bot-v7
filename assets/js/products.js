import 'select2';
import '../styles/app.scss';

import {createErrorImgPlaceHolder, delay, getBlobFromImageUri} from './photos_config.js';

import {addInitPhotoToUppy, renderAttachmentFilesBlock} from "./uppy_attachment_files";

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    let filter_category_id;

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

    var common_defs = [];
    common_defs.push({
        "targets": 5,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function (index, value) {
                    var pOrder = $('<p/>').append('<b>' + value.property_name + ':</b> ').append('<i>' + value.property_value + '</i>');
                    divTag.append(pOrder);
                });
            }

            return divTag.html();
        }
    });

    common_defs.push({
        "targets": 2,
        "render": function (data, type, row, meta) {
            // row.filePath
            let imgs = '';
            $.each(row.filePath, function (index, filePath) {
                imgs = imgs + '<img src="' + filePath + '" class="img-thumbnail"><br>';
            })
            return imgs;
        }
    })

    common_defs.push({
        "targets": 1,
        "render": function (data, type, row, meta) {
            // row.filePath
            let categories = '';
            $.each(row.categories, function (index, category) {
                categories = categories + '<b>' + category + '<b><br>';
            })
            return categories;
        }
    })

    common_defs.push({
        "targets": 8,
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
        initComplete: function () {
            initiateCategoriesSelect();
        },
        'order': [[0, 'desc']],
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': collectionData,
            "data": function (d) {
                if (filter_category_id) {
                    d.filter_category_id = filter_category_id;
                }
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

        modal.find('.error-message').remove();
        modal.find('#save_product').remove();
        modal.find('.prop_conf').remove();
        modal.find('.prop_set').remove();
        form.find('input').val('');

        var divPropConf = $('<div/>', {'class': "prop_conf"});
        divPropConf.attr('order', 0);

        var divPropSet = $('<div/>', {'class': "prop_set"});

        var divTagColPlus = $('<div/>', {'class': "col text-right remove_block", 'text': "Додати характиристику"});
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
                    modal.find('#exampleModalLabel').text('Редагувати продукт')
                    form.find('#product_name').val(data.product_name)
                    form.find('#price').val(data.price)

                    let product_id_input = $('<input>').attr({
                        type: 'hidden',
                        id: 'product_id',
                        name: 'product_id'
                    });
                    product_id_input.val(data.id);
                    form.append(product_id_input);

                    if (Object.keys(data.product_properties).length) {
                        $.each(data.product_properties, function (index, productProperty) {
                            if (Object.keys(productProperty).length) {
                                let order = parseInt($('#createProduct .prop_conf').attr('order')) + 1;
                                divPropSet.append(addPropertiesBlock(order, productProperty.property_name, productProperty.property_value));
                                $('#createProduct .prop_conf').attr('order', order)
                            }
                        });
                    }

                    var categories_select_form_group = $('<div>').addClass('form-group');

                    var categories_select = $('<select>').addClass('category_select');
                    categories_select.attr('name', 'category_ids[]');
                    categories_select.attr('id', 'productCategory');
                    categories_select.appendTo(categories_select_form_group);

                    form.prepend(categories_select_form_group);
                    applySelect2ToShopsSelect(categories_select, {width: '100%'});


                    $.each(data.categories_info, function (index, category) {
                        // Set the value, creating a new option if necessary
                        if (categories_select.find("option[value='" + category.id + "']").length) {
                            categories_select.val(category.id).trigger('change');
                        } else {
                            // Create a DOM Option and pre-select by default
                            var newOption = new Option(category.name, category.id, true, true);
                            // Append it to the select
                            categories_select.append(newOption).trigger('change');
                        }
                    })

                    $.ajax({
                        type: "POST",
                        url: window.Routing
                            .generate('app_attachmentfile_getattachmentfileslist'),
                        data: {
                            id: productId, entity: 'App\\Entity\\Product'
                        },
                        error: (result) => {
                            console.log(result.responseJSON.status);
                        },
                        success: (data) => {
                            renderAttachmentFilesBlock(productId, form, modal, button, data,
                                function (uppy, data) {
                                    (async function (arr) {
                                        //Promise.all не подходит, т.к. он отвалится если хоть одна фотка не загрузится
                                        for (let item of arr) {

                                            try {
                                                let blob = await getBlobFromImageUri(item.path);
                                                await delay(1000);//минимальное время задержки для корректной работы добавления фоток в Dashborad Uppy
                                                addInitPhotoToUppy(uppy, blob, false, item);

                                            } catch (e) {

                                                let blob = await createErrorImgPlaceHolder();
                                                await delay(1000);
                                                addInitPhotoToUppy(uppy, blob, true, item);
                                                continue;
                                            } finally {
                                                uppy.getFiles().forEach(file => {
                                                    uppy.setFileState(file.id, {
                                                        progress: {uploadComplete: true, uploadStarted: true}
                                                    })
                                                })
                                            }

                                        }
                                    })(data);
                                }, 'App\\Entity\\Product');
                        }
                    });
                }
            })
        } else {
            modal.find('#exampleModalLabel').text('Створити новий продукт')

            renderAttachmentFilesBlock(
                null,
                form,
                modal,
                button,
                [],
                null,
                'App\\Entity\\Product'
            );

            var categories_select_form_group = $('<div>').addClass('form-group');

            var categories_select = $('<select>').addClass('category_select');
            categories_select.attr('name', 'category_ids[]');
            categories_select.attr('id', 'productCategory');
            categories_select.appendTo(categories_select_form_group);

            form.prepend(categories_select_form_group);
            applySelect2ToShopsSelect(categories_select, {width: '100%', dropdownParent: $('#exampleModal')});
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
            $('.error-message').remove();
            let inputColumns = createProduct.find('input');
            if (inputColumns.length) {
                $.each(inputColumns, function (k, v) {
                    $(v).prop('required', false);
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

                    $.each(result.responseJSON.errors, function (propIndex, propValue) {
                        let invalidInput = form.find('#' + propIndex);

                        $.each(propValue, function (index, value) {
                            if (invalidInput.length > 0) {
                                invalidInput.prop('required', true);
                                invalidInput.parent().closest('.form-group').append($('<span/>', {
                                    'text': value.message,
                                    'class': 'error-message'
                                }))
                            } else {
                                alert('Поле: ' + propIndex + ' ' + value.message);
                            }
                        });
                    });
                },
                success: (data) => {
                    exampleModal.modal('toggle');
                    table.ajax.reload(null, false);
                }
            });
        });
    })

    exampleModal.on('hide.bs.modal', function (event) {
        var modal = $(this);

        let form = modal.find("form");
        form.trigger("reset");
        form.find('textarea').val('');

        form.find('.strategies_select').remove();
        let strategySelect2Container = form.find('.strategy_select2_container');
        if (strategySelect2Container) {
            strategySelect2Container.remove();
        }

        modal.find('.render_play_ground').remove();
        modal.find('.select2-container').remove();

        form.find('.category_select').remove();
        form.find('input[name="file_ids[]"]').remove();

        form.find('input[type=hidden]').remove();
    });

    function applySelect2ToShopsSelect(select, width = {}) {

        let options = $.extend(width, {
            placeholder: {
                id: '-1', // the value of the option
                text: 'Оберіть категорію'
            },
            dropdownAutoWidth: true,
            multiple: true,
            allowClear: true,
            templateResult: formatShopOption,
            ajax: {
                type: 'post',
                url: window.Routing
                    .generate('admin-category-select2'),
                data: function (params) {
                    let query = {
                        search: params.term,
                        page: params.page || 1,
                        type: 'public'
                    };

                    // Query parameters will be ?search=[term]&type=public
                    return query;
                },
                processResults: function (data) {
                    console.log('processResults', data);
                    return data;
                }
            }
        });
        select.select2(options);
    }

    function formatShopOption(option) {
        return $(
            '<div><strong>' + option.text + '</strong></div>'
        );
    }

    function addPropertiesBlock(order, inputName = null, inputValue = null) {
        var divTag = $('<div/>', {'class': "form-group"});

        let label1 = $("<label>");
        label1.attr({'for': 'property_value'});
        let input1 = $('<input>', {
            'id': 'property_value',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][property_value]'
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
            'name': 'product_properties[' + order + '][property_name]'
        });
        if (inputName !== null) {
            input2.val(inputName)
        }
        let small2 = $("<small>", {
            'class': 'form-text text-muted'
        }).text('назва властивості');

        divTag.append(label2).append(input2).append(small2);
        divTag.append(label1).append(input1).append(small1);

        var divTagColMinus = $('<div/>', {'class': "col text-right remove_block", 'text': "Видалити"});
        divTagColMinus.append('<i class="fas fa-minus-square"></i>')
        divTag.append(divTagColMinus);

        return divTag;
    }

    function initiateCategoriesSelect() {
        var filter_category_select = $('<select>').addClass('filter_category_select');
        filter_category_select.attr('name', 'filter_category_select[]')
        filter_category_select.attr('multiple', 'multiple')
        filter_category_select.insertBefore($('#telegramUserTable'));
        applySelect2ToShopsSelect(filter_category_select, {width: '20%'});
        applyOnChangeToResourceCategorySelect(filter_category_select, {width: '20%'});
    }

    function applyOnChangeToResourceCategorySelect(filter_category_select) {
        filter_category_select.on('change', function (e) {
            if (table) {
                filter_category_id = $(this).val();
                console.log(filter_category_id);
                table.draw();
            }
        })
    }
});