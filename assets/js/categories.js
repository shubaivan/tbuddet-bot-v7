import 'select2';

import {
    getBlobFromImageUri,
    createErrorImgPlaceHolder,
    delay
} from './photos_config.js';

import {renderAttachmentFilesBlock, addInitPhotoToUppy} from "./uppy_attachment_files";

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");
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
            // row.filePath
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
            return '    <!-- Button trigger modal -->\n' +
                '    <button type="button" class="btn btn-primary" data-model-id="' + row.id + '" data-toggle="modal" data-target="#exampleModal">\n' +
                '        Редагувати\n' +
                '    </button>' +
                '    <button class="btn btn-danger delete-model" data-model-id="' + row.id + '">Видалити</button>   '
                ;
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
    let exampleModal = $('#exampleModal');
    exampleModal.on('show.bs.modal', function (event) {
        var modal = $(this);
        let form = modal.find("form");

        modal.find('.error-message').remove();
        modal.find('#save_model').remove();

        form.find('input').val('');

        var button = $(event.relatedTarget); // Button that triggered the modal
        let modelId = button.data('modelId');

        if (modelId !== undefined) {
            $.ajax({
                type: "GET",
                url: window.Routing
                    .generate('admin-category-get') + '/' + modelId,
                error: (result) => {
                    console.log(result);
                },
                success: (data) => {
                    console.log(data);
                    modal.find('#exampleModalLabel').text('Редагувати категорію')

                    form.find('#category_name_ua').val(data.category_name.ua)
                    form.find('#category_name_en').val(data.category_name.en)

                    form.find('#order_category').val(data.order_category)

                    let category_id_input = $('<input>').attr({
                        type: 'hidden',
                        id: 'category_id',
                        name: 'category_id'
                    });

                    category_id_input.val(data.id);
                    form.append(category_id_input);

                    var categories_select_form_group = $('<div>').addClass('form-group');

                    var categories_select = $('<select>').addClass('category_select');
                    categories_select.attr('name', 'category_ids[]');
                    categories_select.attr('id', 'productCategory');
                    categories_select.appendTo(categories_select_form_group);

                    form.prepend(categories_select_form_group);
                    applySelect2ToShopsSelect(categories_select, {width: '100%'});


                    $.each(data.parents, function (index, category) {
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
                            id: modelId, entity: 'App\\Entity\\Category'
                        },
                        error: (result) => {
                            console.log(result.responseJSON.status);
                        },
                        success: (data) => {
                            renderAttachmentFilesBlock(modelId, form, modal, button, data,
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
                                }, 'App\\Entity\\Category');
                        }
                    });
                }
            })
        } else {
            modal.find('#exampleModalLabel').text('Створити нову категорію')

            var categories_select_form_group = $('<div>').addClass('form-group');

            var categories_select = $('<select>').addClass('category_select');
            categories_select.attr('name', 'category_ids[]');
            categories_select.attr('id', 'productCategory');
            categories_select.appendTo(categories_select_form_group);

            form.prepend(categories_select_form_group);
            applySelect2ToShopsSelect(categories_select, {width: '100%', dropdownParent: $('#exampleModal')});

            renderAttachmentFilesBlock(
                null,
                form,
                modal,
                button,
                [],
                null,
                'App\\Entity\\Category'
            );
        }

        form.append('<button id="save_model" type="button" class="btn btn-primary">Зберегти</button>')

        $('.btn#save_model').on('click', function () {
            let createCategory = $('#createCategory');
            $('.error-message').remove();
            let inputColumns = createCategory.find('input');
            if (inputColumns.length) {
                $.each(inputColumns, function (k, v) {
                    $(v).prop('required', false);
                    $(v).val($.trim($(v).val()));
                })
            }

            let serialize = createCategory.serialize();

            const admin_categories_create = window.Routing
                .generate('admin-categories-create');

            $.ajax({
                type: "POST",
                url: admin_categories_create,
                data: serialize,
                error: (result) => {
                    console.log(result);

                    $.each(result.responseJSON.error, function( index, value ) {
                        let invalidInput = form.find('#'+index);
                        if (invalidInput.length > 0) {
                            invalidInput.prop('required', true);
                            invalidInput.parent().closest('.form-group').append($('<span/>', {
                                'text': value.message,
                                'class': 'error-message'
                            }))
                        } else  {
                            alert('Поле: '+index+' '+value.message);
                        }
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
});