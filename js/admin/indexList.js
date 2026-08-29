/**
 * Indexing Page Manager — admin glue (index list, section list).
 *
 * Handles the data-ipm-action verbs that need AJAX or jQuery UI:
 *   - toggleIndex / toggleSection  → POST …/indexToggle | sectionToggle
 *   - deleteIndex / deleteSection  → POST …/indexDelete | sectionDelete then reload
 *   - jQuery UI Sortable for the section list + per-section index rows, calling
 *     sectionReorder / indexReorder
 *
 * Plain navigation ([data-ipm-action="open"]) is handled by the inline script
 * in templates/admin/_page.tpl, so it is intentionally NOT bound here.
 *
 * Globals from templates/admin/_page.tpl:
 *   window.IPM = { baseUrl, pluginName, homeUrl, csrfToken }
 *   window.IPM_I18N = { requestFailed, deleteIndexConfirm, deleteSectionConfirm,
 *                       logoUploadFailed, saving, saved, saveFailed, networkError }
 */
(function ($) {
    'use strict';
    if (!$) return;

    function homeBase() {
        var home = (window.IPM && window.IPM.homeUrl) || '';
        if (home) {
            return home.replace(/\/+$/, '').replace(/\/[^\/?#]+$/, '');
        }
        var path = window.location.pathname || '';
        var match = path.match(/^(.*\/indexingPageManager)(?:\/[^\/?#]*)?\/?$/);
        if (match) {
            return window.location.protocol + '//' + window.location.host + match[1];
        }
        return null;
    }

    function pageUrl(op) {
        // Modal context (IndexingPageManagerPlugin::manage(), opened via the
        // plugin grid's "Manage Indexing Page" action): the modal shell
        // defines this global from a server-built URL template, which is
        // the reliable path — prefer it whenever it's present.
        if (typeof window.ipmManageUrl === 'function') {
            return window.ipmManageUrl(op);
        }
        // Legacy custom-page context (/indexingPageManager/...): unchanged.
        var base = homeBase();
        if (base === null) {
            return window.location.href;
        }
        return base + '/' + encodeURIComponent(op);
    }

    function postAction(op, data, onSuccess, onError) {
        data = data || {};
        if (window.IPM && window.IPM.csrfToken) {
            data.csrfToken = window.IPM.csrfToken;
        }
        $.ajax({
            url:      pageUrl(op),
            method:   'POST',
            data:     data,
            dataType: 'json',
            headers:  { 'X-Csrf-Token': (window.IPM && window.IPM.csrfToken) || '' },
            success:  function (resp) {
                // Endpoints return the OJS JSONMessage envelope {status, content}.
                if (resp && resp.status === false) {
                    var msg = (resp.content && typeof resp.content === 'string')
                        ? resp.content
                        : ((window.IPM_I18N && window.IPM_I18N.requestFailed) || 'Request failed.');
                    if (window.ipmToast) window.ipmToast(msg, 'error'); else alert(msg);
                    return;
                }
                if (typeof onSuccess === 'function') onSuccess(resp);
            },
            error: function () {
                if (typeof onError === 'function') { onError(); return; }
                var m = (window.IPM_I18N && window.IPM_I18N.requestFailed) || 'Request failed.';
                if (window.ipmToast) window.ipmToast(m, 'error'); else alert(m);
            }
        });
    }

    function bindToggles($scope) {
        $scope.on('click.ipm', '[data-ipm-action="toggleIndex"]', function () {
            var $btn = $(this);
            var newState = !$btn.hasClass('is-active');
            postAction('indexToggle', {
                indexId: $btn.data('index-id'),
                isActive: newState ? 1 : 0
            }, function () {
                $btn.toggleClass('is-active', newState);
                $btn.attr('aria-pressed', newState ? 'true' : 'false');
                $btn.closest('.ipm-admin-row').toggleClass('is-inactive', !newState);
            });
        });

        $scope.on('click.ipm', '[data-ipm-action="toggleSection"]', function () {
            var $btn = $(this);
            var newState = !$btn.hasClass('is-active');
            postAction('sectionToggle', {
                sectionId: $btn.data('section-id'),
                isActive: newState ? 1 : 0
            }, function () {
                $btn.toggleClass('is-active', newState);
                $btn.attr('aria-pressed', newState ? 'true' : 'false');
                $btn.closest('.ipm-admin-section').find('.ipm-admin-section-name')
                    .toggleClass('is-inactive', !newState);
            });
        });
    }

    function bindDeletes($scope) {
        $scope.on('click.ipm', '[data-ipm-action="deleteIndex"]', function () {
            var $btn = $(this);
            var name = $btn.data('index-name') || '';
            var msg = ((window.IPM_I18N && window.IPM_I18N.deleteIndexConfirm)
                       || 'Delete "{$name}"? This cannot be undone.').replace('{$name}', name);
            if (!confirm(msg)) return;
            postAction('indexDelete', { indexId: $btn.data('index-id') }, function () {
                window.location.href = pageUrl('indexes');
            });
        });

        $scope.on('click.ipm', '[data-ipm-action="deleteSection"]', function () {
            var $btn = $(this);
            var name = $btn.data('section-name') || '';
            var msg = ((window.IPM_I18N && window.IPM_I18N.deleteSectionConfirm)
                       || 'Delete section "{$name}"? Indexes stay but lose this assignment.').replace('{$name}', name);
            if (!confirm(msg)) return;
            postAction('sectionDelete', { sectionId: $btn.data('section-id') }, function () {
                window.location.href = pageUrl('sections');
            });
        });
    }

    function bindSortables($scope) {
        if (!$.fn.sortable) return; // jQuery UI not present — drag-drop disabled gracefully

        $scope.find('[data-ipm-sortable="sections"]').each(function () {
            var $list = $(this);
            $list.sortable({
                items: '.ipm-admin-section',
                handle: '.ipm-section-drag-handle',
                update: function () {
                    var order = $list.find('.ipm-admin-section').map(function () {
                        return $(this).data('section-id');
                    }).get();
                    postAction('sectionReorder', { 'order[]': order });
                }
            });
        });

        $scope.find('[data-ipm-sortable="indexes"]').each(function () {
            var $list = $(this);
            $list.sortable({
                items: '.ipm-admin-row',
                handle: '.ipm-index-drag-handle',
                update: function () {
                    var order = $list.find('.ipm-admin-row').map(function () {
                        return $(this).data('index-id');
                    }).get();
                    postAction('indexReorder', {
                        sectionId: $list.data('section-id'),
                        'order[]': order
                    });
                }
            });
        });
    }

    function init() {
        var $scope = $('.ipm-admin');
        if (!$scope.length) $scope = $('.ipm-body');
        if (!$scope.length) return;
        $scope.off('.ipm');
        bindToggles($scope);
        bindDeletes($scope);
        bindSortables($scope);
    }

    window.ipmAdminInit = init;
    $(function () { init(); });
})(window.jQuery);
