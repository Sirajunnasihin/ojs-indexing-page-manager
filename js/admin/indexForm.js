/**
 * Indexing Page Manager — index form helpers.
 *
 * Local logo preview (reads the chosen file as a data URL) + a remove button
 * that flags the hidden removeLogo field. The actual upload travels with the
 * form's multipart POST (field name "logoFile"), handled by ipmSubmitWithFiles
 * in templates/admin/_page.tpl — there is no separate temp-file round-trip.
 *
 * Plain vanilla JS so it works regardless of jQuery state on the page.
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('indexingPageManagerIndexForm');
        if (!form) return;

        var input  = document.getElementById('ipmLogoInput');
        var preview = document.getElementById('ipmLogoImg');
        var remove = document.getElementById('ipmLogoRemove');
        var removeFlag = document.getElementById('ipmRemoveLogo');

        if (input) {
            input.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;
                var file = this.files[0];
                if (removeFlag) removeFlag.value = '0';
                var reader = new FileReader();
                reader.onload = function (e) {
                    var src = e.target.result;
                    if (preview && preview.tagName === 'IMG') {
                        preview.setAttribute('src', src);
                    } else if (preview) {
                        var img = document.createElement('img');
                        img.id = 'ipmLogoImg';
                        img.src = src;
                        img.alt = '';
                        preview.parentNode.replaceChild(img, preview);
                        preview = img;
                    }
                };
                reader.onerror = function () {
                    var m = (window.IPM_I18N && window.IPM_I18N.logoUploadFailed) || 'Logo upload failed.';
                    if (window.ipmToast) window.ipmToast(m, 'error'); else alert(m);
                };
                reader.readAsDataURL(file);
            });
        }

        if (remove) {
            remove.addEventListener('click', function () {
                if (removeFlag) removeFlag.value = '1';
                if (input) input.value = '';
                if (preview) {
                    var ph = document.createElement('div');
                    ph.id = 'ipmLogoImg';
                    ph.className = 'ipm-logo-placeholder';
                    ph.textContent = '—';
                    preview.parentNode.replaceChild(ph, preview);
                    preview = ph;
                }
            });
        }
    }

    window.ipmIndexFormInit = init;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
