document.addEventListener("DOMContentLoaded", function () {
    let table;
    const defs = [];

    // 0 — id
    defs.push({
        targets: 0,
        render: (data, type, row) => '<span class="row-id">#' + row.id + '</span>',
    });

    // 1 — filePath (image carousel + lightbox)
    defs.push({
        targets: 1,
        orderable: false,
        render: (data, type, row) => {
            const imgs = (row.filePath || []).filter(Boolean);
            if (!imgs.length) {
                return '<div class="product-img-cell empty"><span class="img-empty">—</span></div>';
            }
            const first = imgs[0];
            const total = imgs.length;
            const payload = encodeURIComponent(JSON.stringify(imgs));
            const counter = total > 1 ? '<span class="img-counter">1/' + total + '</span>' : '';
            const arrows = total > 1
                ? '<button type="button" class="img-nav prev" aria-label="previous">‹</button>' +
                  '<button type="button" class="img-nav next" aria-label="next">›</button>'
                : '';
            return '<div class="product-img-cell" data-imgs="' + payload + '" data-idx="0">' +
                '<a class="img-link" href="' + first + '" target="_blank" rel="noopener">' +
                '<img src="' + first + '" class="img-thumbnail product-img-primary" loading="lazy" alt="">' +
                '</a>' + arrows + counter +
                '</div>';
        },
    });

    // 2 — name (bold UA + small EN)
    defs.push({
        targets: 2,
        orderable: false,
        render: (data, type, row) => {
            const cn = row.category_name || {};
            const ua = cn.ua || '';
            const en = cn.en || '';
            return '<div class="cat-name">' +
                '<div class="cat-name-ua">' + escapeHtml(ua || '—') + '</div>' +
                (en ? '<div class="cat-name-en">' + escapeHtml(en) + '</div>' : '') +
                '</div>';
        },
    });

    // 3 — parents (pills)
    defs.push({
        targets: 3,
        orderable: false,
        render: (data, type, row) => {
            const parents = (row.parents || []).filter(Boolean);
            if (!parents.length) return '<span class="muted">—</span>';
            return parents.map(p => '<span class="cat-parent-pill">' + escapeHtml(p) + '</span>').join(' ');
        },
    });

    // 4 — products_count
    defs.push({
        targets: 4,
        orderable: false,
        render: (data, type, row) => {
            const n = parseInt(row.products_count || 0, 10);
            if (!n) return '<span class="muted">0</span>';
            const cls = n > 0 ? 'cat-count-chip has' : 'cat-count-chip';
            return '<span class="' + cls + '">' + n + '</span>';
        },
    });

    // 5 — order_category
    defs.push({
        targets: 5,
        render: (data, type, row) => '<span class="cat-order">' + (row.order_category ?? 0) + '</span>',
    });

    // 6 — updated_at
    defs.push({
        targets: 6,
        render: (data, type, row) => row.updated_at ? '<span class="ts-cell">' + row.updated_at + '</span>' : '',
    });

    // 7 — actions (icon buttons)
    defs.push({
        targets: 7,
        orderable: false,
        render: (data, type, row) => {
            return '<div class="action-icons">' +
                '<a class="action-btn action-edit" href="/admin/category/form/' + row.id + '" target="_blank" title="Редагувати">' +
                    '<i class="fas fa-pen"></i>' +
                '</a>' +
                '<button type="button" class="action-btn action-del delete-model" data-model-id="' + row.id + '" title="Видалити">' +
                    '<i class="fas fa-trash"></i>' +
                '</button>' +
                '</div>';
        },
    });

    const url = window.Routing.generate('admin-categories-data-table');

    // Synthetic columns need data: null so DataTables doesn't try to look them up on the row.
    const syntheticCols = new Set(['filePath', 'name', 'parents', 'products_count', 'action']);
    const columns = th_keys.map(c => syntheticCols.has(c.data) ? { data: null, defaultContent: '' } : c);

    table = $('#telegramUserTable').DataTable({
        order: [[0, 'desc']],
        responsive: true,
        fixedHeader: true,
        processing: true,
        serverSide: true,
        serverMethod: 'post',
        ajax: { url },
        columns,
        columnDefs: defs,
        language: {
            search: 'Пошук:',
            lengthMenu: 'Показати _MENU_ записів',
            info: 'Записи _START_ - _END_ з _TOTAL_',
            infoEmpty: 'Немає записів',
            zeroRecords: 'Нічого не знайдено',
            paginate: { previous: '&larr;', next: '&rarr;' },
        },
    });

    // Image carousel arrows inside the cell
    $('#telegramUserTable').on('click', '.product-img-cell .img-nav', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const cell = $(this).closest('.product-img-cell');
        const imgs = parseImgs(cell);
        if (!imgs.length) return;
        const total = imgs.length;
        let idx = parseInt(cell.attr('data-idx') || '0', 10);
        idx = $(this).hasClass('next') ? (idx + 1) % total : (idx - 1 + total) % total;
        setCellIdx(cell, imgs, idx);
    });

    // Click on the thumbnail opens lightbox
    $('#telegramUserTable').on('click', '.product-img-cell .img-link', function (e) {
        e.preventDefault();
        const cell = $(this).closest('.product-img-cell');
        const imgs = parseImgs(cell);
        if (!imgs.length) return;
        const idx = parseInt(cell.attr('data-idx') || '0', 10);
        openLightbox(imgs, idx);
    });

    // Delete handler
    $('#telegramUserTable').on('click', '.delete-model', function () {
        if (!confirm('Видалити цю категорію?')) return;
        const modelId = $(this).data('modelId');
        $.ajax({
            type: 'DELETE',
            url: window.Routing.generate('admin-category-delete') + '/' + modelId,
            success: () => table.ajax.reload(null, false),
            error: (r) => console.error(r),
        });
    });

    // Lightbox state
    const $lightbox = $('#productImageLightbox');
    let lightboxImgs = [];
    let lightboxIdx = 0;

    function parseImgs(cell) {
        try { return JSON.parse(decodeURIComponent(cell.attr('data-imgs') || '[]')); }
        catch (_) { return []; }
    }
    function setCellIdx(cell, imgs, idx) {
        cell.attr('data-idx', idx);
        const url = imgs[idx];
        cell.find('img.product-img-primary').attr('src', url);
        cell.find('a.img-link').attr('href', url);
        cell.find('.img-counter').text((idx + 1) + '/' + imgs.length);
    }
    function renderLightbox() {
        const total = lightboxImgs.length;
        if (!total) return;
        $lightbox.find('.lightbox-img').attr('src', lightboxImgs[lightboxIdx]);
        $lightbox.find('.lightbox-counter').text((lightboxIdx + 1) + ' / ' + total);
        $lightbox.find('.lightbox-nav').toggle(total > 1);
    }
    function openLightbox(imgs, startIdx) {
        lightboxImgs = imgs;
        lightboxIdx = Math.max(0, Math.min(startIdx, imgs.length - 1));
        renderLightbox();
        $lightbox.modal('show');
    }
    $lightbox.on('click', '.lightbox-nav', function () {
        const total = lightboxImgs.length;
        if (!total) return;
        lightboxIdx = $(this).hasClass('next')
            ? (lightboxIdx + 1) % total
            : (lightboxIdx - 1 + total) % total;
        renderLightbox();
    });
    $(document).on('keydown', function (e) {
        if (!$lightbox.hasClass('show')) return;
        if (e.key === 'ArrowRight') $lightbox.find('.lightbox-nav.next').trigger('click');
        else if (e.key === 'ArrowLeft') $lightbox.find('.lightbox-nav.prev').trigger('click');
    });

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
});
