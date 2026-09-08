/**
 * Clickable media thumbnails with lightbox preview and download.
 */
(function (global) {
    'use strict';

    function escapeAttr(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    function resolveMediaPath(path, basePrefix) {
        const p = String(path || '').replace(/^\/+/, '');
        if (!p) {
            return '';
        }
        if (/^https?:\/\//i.test(p) || p.startsWith('data:')) {
            return p;
        }
        const prefix = basePrefix || '../';
        return prefix + p;
    }

    function ensureMediaLightboxModal() {
        if (document.getElementById('mediaLightboxModal')) {
            return;
        }

        document.body.insertAdjacentHTML(
            'beforeend',
            '<div class="modal fade" id="mediaLightboxModal" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<h5 class="modal-title">Photo Preview</h5>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                        '</div>' +
                        '<div class="modal-body text-center p-2">' +
                            '<img id="mediaLightboxImage" src="" alt="Preview" class="img-fluid rounded media-lightbox-image">' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<a id="mediaLightboxDownload" href="#" class="btn btn-primary" download>' +
                                '<i class="bi bi-download"></i> Download' +
                            '</a>' +
                            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
    }

    function openMediaLightbox(url, fileName) {
        if (!url) {
            return;
        }
        ensureMediaLightboxModal();

        const img = document.getElementById('mediaLightboxImage');
        const download = document.getElementById('mediaLightboxDownload');
        const modalEl = document.getElementById('mediaLightboxModal');

        if (img) {
            img.src = url;
            img.alt = fileName || 'Photo';
        }
        if (download) {
            download.href = url;
            download.setAttribute('download', fileName || 'photo.jpg');
        }

        if (typeof bootstrap !== 'undefined' && modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else {
            window.open(url, '_blank', 'noopener');
        }
    }

    /**
     * @param {HTMLElement} container
     * @param {Array} items - { file_path, file_name, url, name }
     * @param {Function} resolveUrl - optional (item) => url
     * @param {Object} options - optional { iconOnlyDownload: boolean }
     */
    function renderMediaGallery(container, items, resolveUrl, options) {
        const iconOnlyDownload = !!(options && options.iconOnlyDownload);
        if (!container) {
            return;
        }

        container.innerHTML = '';

        if (!container.classList.contains('row')) {
            container.classList.add('row', 'g-2');
        }

        if (!Array.isArray(items) || items.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">No photos uploaded</p>';
            return;
        }

        items.forEach(function (item, idx) {
            const url = typeof resolveUrl === 'function'
                ? resolveUrl(item)
                : (item.url || item.file_path || '');
            const fileName = item.file_name || item.name || ('photo-' + (idx + 1) + '.jpg');

            if (!url) {
                return;
            }

            const col = document.createElement('div');
            col.className = 'col-6 col-md-3';
            col.innerHTML =
                '<div class="media-gallery-card">' +
                    '<button type="button" class="media-gallery-thumb-btn" data-url="' + escapeAttr(url) + '" data-name="' + escapeAttr(fileName) + '" title="View full size">' +
                        '<img src="' + escapeAttr(url) + '" alt="' + escapeAttr(fileName) + '" class="media-gallery-thumb-img">' +
                        '<span class="media-gallery-thumb-overlay"><i class="bi bi-zoom-in"></i> View</span>' +
                    '</button>' +
                    '<a href="' + escapeAttr(url) + '" download="' + escapeAttr(fileName) + '" class="btn btn-sm btn-outline-primary w-100 mt-1" title="Download">' +
                        (iconOnlyDownload ? '<i class="bi bi-download"></i>' : '<i class="bi bi-download"></i> Download') +
                    '</a>' +
                '</div>';

            container.appendChild(col);
        });

        container.querySelectorAll('.media-gallery-thumb-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openMediaLightbox(btn.getAttribute('data-url'), btn.getAttribute('data-name'));
            });
        });
    }

    /**
     * Render local File previews (doctor upload before submit).
     */
    function renderLocalMediaGallery(container, files, onRemove) {
        if (!container) {
            return;
        }

        container.innerHTML = '';

        if (!Array.isArray(files) || files.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">No photos uploaded yet</p>';
            return;
        }

        files.forEach(function (file, idx) {
            const reader = new FileReader();
            reader.onload = function (e) {
                const url = e.target.result;
                const fileName = file.name || ('photo-' + (idx + 1) + '.jpg');
                const col = document.createElement('div');
                col.className = 'col-6 col-md-4 position-relative';
                col.innerHTML =
                    '<div class="media-gallery-card">' +
                        '<button type="button" class="media-gallery-thumb-btn" data-url="' + escapeAttr(url) + '" data-name="' + escapeAttr(fileName) + '" title="View full size">' +
                            '<img src="' + escapeAttr(url) + '" alt="' + escapeAttr(fileName) + '" class="media-gallery-thumb-img">' +
                            '<span class="media-gallery-thumb-overlay"><i class="bi bi-zoom-in"></i> View</span>' +
                        '</button>' +
                        '<div class="d-flex gap-1 mt-1">' +
                            '<a href="' + escapeAttr(url) + '" download="' + escapeAttr(fileName) + '" class="btn btn-sm btn-outline-primary flex-grow-1">' +
                                '<i class="bi bi-download"></i> Download' +
                            '</a>' +
                            (typeof onRemove === 'function'
                                ? '<button type="button" class="btn btn-sm btn-outline-danger media-gallery-remove" data-idx="' + idx + '" title="Remove"><i class="bi bi-trash"></i></button>'
                                : '') +
                        '</div>' +
                    '</div>';

                col.querySelector('.media-gallery-thumb-btn')?.addEventListener('click', function () {
                    openMediaLightbox(url, fileName);
                });

                col.querySelector('.media-gallery-remove')?.addEventListener('click', function () {
                    onRemove(parseInt(this.getAttribute('data-idx'), 10));
                });

                container.appendChild(col);
            };
            reader.readAsDataURL(file);
        });
    }

    global.ensureMediaLightboxModal = ensureMediaLightboxModal;
    global.openMediaLightbox = openMediaLightbox;
    global.renderMediaGallery = renderMediaGallery;
    global.renderLocalMediaGallery = renderLocalMediaGallery;
    global.resolveMediaPath = resolveMediaPath;
})(window);
