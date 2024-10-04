export function renderAttachmentFilesBlock(
    modelId,
    form,
    modal,
    button,
    attachmentFiles,
    uppy_callback_function,
    entity
) {
    const app_attachmentfile_getattachmentfilestemplate = window.Routing
        .generate('app_attachmentfile_getattachmentfilestemplate');

    $.ajax({
        type: "GET",
        url: app_attachmentfile_getattachmentfilestemplate,
        error: (result) => {
            if (result.responseJSON.message) {
                alert(result.responseJSON.message);
            }
        },
        success: (data) => {
            console.log(data);
            let template = data.template;
            if (template) {
                let parseHTML = $.parseHTML(template);
                let attachment_files_template = form.find('#attachment_files_template');
                attachment_files_template.empty();
                form.find('input[name="file_ids[]"]').remove();
                attachment_files_template.append(parseHTML);
                let uppy = renderUppy(modelId, form, modal, button, entity);
                if (attachmentFiles && uppy_callback_function) {
                    uppy_callback_function(uppy, attachmentFiles);
                }
            }
        }
    });
}

export function setMetadataToRequest(modelId, uppy, entity, button) {
    if (modelId) {
        uppy.setMeta({
            id: modelId, entity: entity
        });
    }
}

export function renderUppy(modelId, form, modal, button, entity) {
    const attachment_files = window.Routing
        .generate('app_attachmentfile_postattachmentfile');

    // Import the plugins
    const Uppy = require('@uppy/core')
    const XHRUpload = require('@uppy/xhr-upload')
    const Dashboard = require('@uppy/dashboard')
    let timeOfLastAttach = new Date();
    const uppy = Uppy({
        debug: true,
        autoProceed: false,
        restrictions: {
            maxFileSize: 100097152,//100Mb
        },
        onBeforeFileAdded: (currentFile, files) => {
            //чтоб мог добавить только один файл за раз
            let currentTime = new Date();
            if ((currentTime - timeOfLastAttach) < 700) {
                return false;
            }
            timeOfLastAttach = new Date();


            let isSameFile = files && Object.values(files).some(item => {
                return currentFile.name === item.data.name;
            });
            if (isSameFile) {
                alert('File already added.');
                return false;
            }

            return currentFile;
        },
        onBeforeUpload: (currentFiles) => {

        }
    });

    uppy.use(Dashboard, {
        trigger: '.UppyModalOpenerBtn',
        inline: true,
        target: '.DashboardContainer',
        replaceTargetContent: true,
        showProgressDetails: true,
        showRemoveButtonAfterComplete: true,
        note: 'add Images',
        height: 470,
        metaFields: [
            {id: 'name', name: 'name', placeholder: 'file name'},
            {id: 'caption', name: 'caption', placeholder: 'describe what the image is about'}
        ],
        browserBackButtonClose: true
    });

    setMetadataToRequest(modelId, uppy, entity, button);

    uppy.use(XHRUpload, {
        endpoint: attachment_files,
        formData: true,
        fieldName: 'files[]',
        // bundle: true,
        metaFields: null,
        getResponseData(responseText, response) {
            return {
                url: responseText
            }
        }
    });

    uppy.on('file-added', (file) => {

    });

    uppy.on('file-editor:complete', (updatedFile) => {
        // console.log(updatedFile)
    });

    uppy.on('upload-success', (file, body) => {
        let files = JSON.parse(body.body.url);
        $.each(files, function (k, v) {
            let input = $('<input>').attr({
                type: 'hidden',
                id: 'file_id_' + v.id,
                name: 'file_ids[]',
                class: 'attachment_files_to_object'
            });
            input.val(v.id);
            form.append(input);
            uppy.setFileMeta(file.id, {m_file_id: v.id})
        })
    });

    uppy.on('error', (error) => {
        console.error(error.stack)
    });

    uppy.on('file-removed', (file, reason) => {
        if (reason === 'removed-by-user' && file.meta.m_file_id) {
            var app_attachmentfile_deleteattachmentfile = Routing.generate('app_attachmentfile_deleteattachmentfile', {'id': file.meta.m_file_id});
            $.ajax({
                url: app_attachmentfile_deleteattachmentfile,
                type: 'DELETE',
                success: function (result) {
                    console.log(result);
                },
                error: function (result) {
                    console.log(result);
                }
            });
        }
    });

    uppy.on('upload-error', (file, error, response) => {
        uppy.removeFile(file.id);
        let parse = JSON.parse(response.body.url);

        uppy.info(parse.message, 'error', 5000);

        console.log('error with file:', file.id);
        console.log('error message:', error);
    });

    uppy.on('complete', result => {
        console.log('successful files:', result.successful)
        console.log('failed files:', result.failed)
    });

    return uppy;
}

export function addInitPhotoToUppy(uppy, blob, isErrorPhoto = false, item) {
    let configObj = {
        name: item.originalName, // override in onBeforeFileAdded event
        type: 'image/jpeg',
        data: blob,
        source: isErrorPhoto ? 'canvasPlaceholderError' : '',
    };

    let file_id = uppy.addFile(configObj);
    uppy.setFileMeta(file_id, {m_file_id: item.id})
}