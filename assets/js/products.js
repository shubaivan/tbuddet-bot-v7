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

    $(document).on('click', '.duplicate-product', function () {
        alert('Копія?')
        var button = $(this); // Button that triggered the modal
        let productId = button.data('productId');
        $.ajax({
            type: "GET",
            url: window.Routing
                .generate('admin-product-duplicate') + '/' + productId,
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
        "targets": 3,
        "orderable": false,
        "render": function (data, type, row, meta) {
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
    });

    common_defs.push({
        "targets": 5,
        "orderable": false,
        "render": function (data, type, row, meta) {
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
    });

    common_defs.push({
        "targets": 6,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function (index, propByLanguage) {
                    var pIndex = $('<p/>').text('Номер властивості ' + (index + 1));
                    divTag.append(pIndex);
                    $.each(propByLanguage, function (language, prop) {
                        var bLanguage = $('<b/>').text(language)
                        divTag.append(bLanguage);
                        var pOrder = $('<p/>')
                            .append('<b>Назва: ' + prop.property_name + '</b>; ').append('<br>')
                            .append('<i>Значення: ' + prop.property_value + '</i>; ').append('<br>')
                            .append('<b>Збільшення ціни: ' + prop.property_price_impact + '</b> ');
                        divTag.append(pOrder);
                    })
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
        "targets": 9,
        data: 'action',
        render: function (data, type, row, meta) {
            return '    <!-- Button trigger modal -->\n' +
                '    <button type="button" class="btn btn-primary" data-product-id="' + row.id + '" data-toggle="modal" data-target="#exampleModal">\n' +
                '        Редагувати\n' +
                '    </button>' +
                '    <button class="btn btn-danger delete-product" data-product-id="' + row.id + '">Видалити</button>   ' +
                '    <button class="btn btn-secondary duplicate-product" data-product-id="' + row.id + '">Зробити копію</button>   '
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

                    form.find('#product_name_ua').val(data.product_name.ua)
                    form.find('#product_name_en').val(data.product_name.en)

                    form.find('#price').val(data.price)

                    form.find('#description_ua').val(data.description.ua)
                    form.find('#description_en').val(data.description.en)

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
                                divPropSet.append(addPropertiesBlock(order, productProperty.property_name, productProperty.property_value, productProperty.property_price_impact));
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
            let divTagOneProp = current.attr('divtagoneprop');
            $('#' + divTagOneProp).remove();
        });

        divPropConf.on('click', function () {
            let order = parseInt($('#createProduct .prop_conf').attr('order')) + 1;

            var divTagOneProp = $('<div/>', {'class': "form-group", 'id': 'one_prop_' + order});
            divTagOneProp.append(addPropertiesBlock(order));

            var rowMinus = $('<div/>', {'class': "row"});
            var colMinus = $('<div/>', {'class': "col"});
            var divTagMinus = $('<div/>', {'class': "form-group"});

            var divTagColMinus = $('<div/>', {'class': "form-group text-right remove_block", 'text': "Видалити"});

            var iMinus = $('<i/>', {'class': "fas fa-minus-square", 'divtagoneprop': 'one_prop_' + order});
            divTagColMinus.append(iMinus)

            divTagMinus
                .append(divTagColMinus);

            colMinus.append(divTagMinus);
            rowMinus.append(colMinus);
            divTagOneProp.append(rowMinus);

            divPropSet.append(divTagOneProp);

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

    function addPropertiesBlock(
        order,
        inputNameUa = null,
        inputValueUa = null,
        inputPriceImpactUa = null,
        inputNameEn = null,
        inputValueEn = null,
        inputPriceImpactEn = null,
    ) {
        var row = $('<div/>', {'class': "row"});

        var colUa = $('<div/>', {'class': "col"});
        var divTagUa = $('<div/>', {'class': "form-group"});


        let inputValueUaTag = $('<input>', {
            'id': 'property_value_ua',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][ua][property_value]'
        });
        if (inputValueUa !== null) {
            inputValueUaTag.val(inputValueUa)
        }
        let smallValueUa = $("<small>", {
            'class': 'form-text text-muted'
        }).text('значення властивості укр');


        let inputNameUaTag = $('<input>', {
            'id': 'property_name_ua',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][ua][property_name]'
        });
        if (inputNameUa !== null) {
            inputNameUaTag.val(inputNameUa)
        }
        let smallNamePropUa = $("<small>", {
            'class': 'form-text text-muted'
        }).text('назва властивості укр');


        let inputImpactUaTag = $('<input>', {
            'id': 'property_price_impact_ua',
            'type': 'number',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][ua][property_price_impact]'
        });
        if (inputPriceImpactUa !== null) {
            inputImpactUaTag.val(inputPriceImpactUa)
        }
        let smallImpactUa = $("<small>", {
            'class': 'form-text text-muted'
        }).text('збільшення ціни властвивості на продукт у грн');


        divTagUa
            .append(inputValueUaTag).append(smallValueUa)
            .append(inputNameUaTag).append(smallNamePropUa)
            .append(inputImpactUaTag).append(smallImpactUa);

        colUa.append(divTagUa);
        row.append(colUa);



        var colEn = $('<div/>', {'class': "col"});
        var divTagEn = $('<div/>', {'class': "form-group"});


        let inputValueEnTag = $('<input>', {
            'id': 'property_value_en',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][en][property_value]'
        });
        if (inputValueEn !== null) {
            inputValueEnTag.val(inputValueEn)
        }
        let smallValueEn = $("<small>", {
            'class': 'form-text text-muted'
        }).text('значення властивості english');



        let inputNameEnTag = $('<input>', {
            'id': 'property_name_en',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][en][property_name]'
        });
        if (inputNameEn !== null) {
            inputNameUaTag.val(inputNameEn)
        }
        let smallNamePropEn = $("<small>", {
            'class': 'form-text text-muted'
        }).text('назва властивості english');


        let inputImpactEnTag = $('<input>', {
            'id': 'property_price_impact_en',
            'type': 'number',
            'class': 'form-control',
            'name': 'product_properties[' + order + '][en][property_price_impact]'
        });
        if (inputPriceImpactEn !== null) {
            inputImpactEnTag.val(inputPriceImpactEn)
        }
        let smallImpactEn = $("<small>", {
            'class': 'form-text text-muted'
        }).text('збільшення ціни властвивості на продукт у usd');

        divTagEn
            .append(inputValueEnTag).append(smallValueEn)
            .append(inputNameEnTag).append(smallNamePropEn)
            .append(inputImpactEnTag).append(smallImpactEn);
        colEn.append(divTagEn);
        row.append(colEn);

        return row;
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