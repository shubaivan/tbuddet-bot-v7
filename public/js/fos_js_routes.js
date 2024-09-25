fos.Router.setData({
    "base_url": "", "routes": {
        "admin-users-data-table": {
            "tokens": [["text", "\/admin\/users\/data-table"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "admin-orders-data-table": {
            "tokens": [["text", "\/admin\/orders\/data-table"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "admin-products-data-table": {
            "tokens": [["text", "\/admin\/products\/data-table"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "admin-products-create": {
            "tokens": [["text", "\/admin\/products\/create"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "admin-product-get": {
            "tokens": [["variable", "\/", "[^\/]++", "id", true], ["text", "\/admin\/product"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": ["GET"],
            "schemes": []
        },
        "admin-product-delete": {
            "tokens": [["variable", "\/", "[^\/]++", "id", true], ["text", "\/admin\/product"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": ["DELETE"],
            "schemes": []
        },
        "app_attachmentfile_getattachmentfilestemplate": {
            "tokens": [["text", "\/admin\/api\/attachment_files\/template"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "app_attachmentfile_postattachmentfile": {
            "tokens": [["text", "\/admin\/api\/attachment_file"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": ["POST"],
            "schemes": []
        },
        "app_attachmentfile_getattachmentfileslist": {
            "tokens": [["text", "\/admin\/api\/attachment_files\/list"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": [],
            "schemes": []
        },
        "app_attachmentfile_deleteattachmentfile": {
            "tokens": [["variable", "\/", "[^\/]++", "id", true], ["text", "\/admin\/api\/attachment_file"]],
            "defaults": [],
            "requirements": [],
            "hosttokens": [],
            "methods": ["DELETE"],
            "schemes": []
        }
    }, "prefix": "", "host": "localhost", "port": "", "scheme": "http", "locale": ""
});