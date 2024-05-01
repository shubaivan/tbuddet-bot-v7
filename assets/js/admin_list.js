import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");
    const body = $('body');
    let table;

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
        columns: th_keys
    });
});