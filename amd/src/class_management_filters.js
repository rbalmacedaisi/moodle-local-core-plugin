// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Server-side pagination + filters for the classmanagement page.
 *
 * The first paint is rendered by the PHP page (so the page loads with
 * content). Once this module initialises it takes over:
 *   - binds events on the filter bar (search, period, course, status,
 *     perpage, sort, clear),
 *   - re-fetches via Ajax.call to local_grupomakro_list_classes_paged,
 *   - re-renders the grid and list views from the JSON payload,
 *   - re-binds the per-row interactions (delete + update period +
 *     shift form submit) using event delegation so we never have to
 *     manually rebind after each re-render,
 *   - keeps the URL query string and localStorage in sync.
 *
 * The companion module local_grupomakro_core/delete_class.js owns the
 * view toggle, the "select all" + bulk delete + delete confirm flow.
 * We let that module run alongside us and only handle the parts that
 * need to fire after a re-render (delete / update-period).
 *
 * @module     local_grupomakro_core/class_management_filters
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'jquery'], function(Ajax, $) {
    'use strict';

    const STORAGE_KEY = 'classManagementFilters';
    const DEBOUNCE_MS = 300;
    const WS_METHOD = 'local_grupomakro_list_classes_paged';

    const state = {
        search: '',
        periodid: 0,
        learningplanid: 0,
        corecourseid: 0,
        status: 'active',
        sort: 'timecreated',
        dir: 'DESC',
        page: 0,
        perpage: 25,
        sesskey: '',
    };

    // Cache of the most recent response so we can re-render on view toggle
    // without re-hitting the server.
    let lastResponse = null;
    // True while a fetch is in-flight; suppresses extra requests.
    let inflight = false;
    // Debounce timer id for the search input.
    let debounceTimer = null;

    /**
     * Public init, called by js_call_amd with the bootstrap data the PHP
     * page rendered: the initial filters (already in the query string) +
     * the sesskey (form actions still rely on it).
     */
    const init = (initial) => {
        initial = initial || {};
        if (initial.sesskey) {
            state.sesskey = String(initial.sesskey);
        }
        if (initial.search !== undefined) {
            state.search = String(initial.search);
        }
        if (initial.periodid) {
            state.periodid = parseInt(initial.periodid, 10) || 0;
        }
        if (initial.learningplanid) {
            state.learningplanid = parseInt(initial.learningplanid, 10) || 0;
        }
        if (initial.corecourseid) {
            state.corecourseid = parseInt(initial.corecourseid, 10) || 0;
        }
        if (initial.status && ['active', 'closed', 'all'].indexOf(initial.status) !== -1) {
            state.status = initial.status;
        }
        if (initial.sort) {
            state.sort = String(initial.sort);
        }
        if (initial.dir) {
            state.dir = String(initial.dir).toUpperCase() === 'ASC' ? 'ASC' : 'DESC';
        }
        if (initial.perpage) {
            state.perpage = clampPerpage(parseInt(initial.perpage, 10));
        }
        if (initial.page !== undefined) {
            state.page = Math.max(0, parseInt(initial.page, 10) || 0);
        }

        bindFilterBar();
        bindPaginator();
        bindRowDelegation();

        // If the URL had no filters but localStorage does, restore. This
        // makes "back to the page after browsing elsewhere" remember the
        // user's last view.
        const fromUrl = hasAnyFilterInUrl();
        if (!fromUrl) {
            restoreFromStorage();
            // Reflect restored state into the form controls.
            reflectStateToForm();
        } else {
            persistToStorage();
        }
    };

    const clampPerpage = (n) => Math.max(1, Math.min(200, parseInt(n, 10) || 25));

    const hasAnyFilterInUrl = () => {
        const params = new URLSearchParams(window.location.search);
        return ['search', 'periodid', 'learningplanid', 'corecourseid', 'status', 'sort', 'dir', 'page', 'perpage']
            .some(k => params.has(k) && params.get(k) !== '');
    };

    const restoreFromStorage = () => {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return;
            }
            const saved = JSON.parse(raw);
            if (!saved || typeof saved !== 'object') {
                return;
            }
            if (typeof saved.search === 'string') {
                state.search = saved.search;
            }
            if (saved.periodid) {
                state.periodid = parseInt(saved.periodid, 10) || 0;
            }
            if (saved.learningplanid) {
                state.learningplanid = parseInt(saved.learningplanid, 10) || 0;
            }
            if (saved.corecourseid) {
                state.corecourseid = parseInt(saved.corecourseid, 10) || 0;
            }
            if (saved.status && ['active', 'closed', 'all'].indexOf(saved.status) !== -1) {
                state.status = saved.status;
            }
            if (saved.sort) {
                state.sort = String(saved.sort);
            }
            if (saved.dir) {
                state.dir = String(saved.dir).toUpperCase() === 'ASC' ? 'ASC' : 'DESC';
            }
            if (saved.perpage) {
                state.perpage = clampPerpage(saved.perpage);
            }
        } catch (e) {
            // localStorage may be disabled; ignore silently.
        }
    };

    const persistToStorage = () => {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                search: state.search,
                periodid: state.periodid,
                learningplanid: state.learningplanid,
                corecourseid: state.corecourseid,
                status: state.status,
                sort: state.sort,
                dir: state.dir,
                perpage: state.perpage,
            }));
        } catch (e) {
            // ignore
        }
    };

    const syncUrl = () => {
        const params = new URLSearchParams();
        if (state.search) {
            params.set('search', state.search);
        }
        if (state.periodid) {
            params.set('periodid', String(state.periodid));
        }
        if (state.learningplanid) {
            params.set('learningplanid', String(state.learningplanid));
        }
        if (state.corecourseid) {
            params.set('corecourseid', String(state.corecourseid));
        }
        if (state.status !== 'active') {
            params.set('status', state.status);
        }
        if (state.sort !== 'timecreated') {
            params.set('sort', state.sort);
        }
        if (state.dir !== 'DESC') {
            params.set('dir', state.dir);
        }
        if (state.page > 0) {
            params.set('page', String(state.page));
        }
        if (state.perpage !== 25) {
            params.set('perpage', String(state.perpage));
        }
        const qs = params.toString();
        const url = window.location.pathname + (qs ? '?' + qs : '');
        window.history.replaceState({}, '', url);
    };

    const reflectStateToForm = () => {
        const $search = $('#classmgmt-search');
        if ($search.length) {
            $search.val(state.search);
        }
        const $period = $('#classmgmt-period');
        if ($period.length) {
            $period.val(String(state.periodid));
        }
        const $lp = $('#classmgmt-learningplan');
        if ($lp.length) {
            $lp.val(String(state.learningplanid));
        }
        const $course = $('#classmgmt-course');
        if ($course.length) {
            $course.val(String(state.corecourseid));
        }
        const $perpage = $('#classmgmt-perpage');
        if ($perpage.length) {
            $perpage.val(String(state.perpage));
        }
        const $sort = $('#classmgmt-sort');
        if ($sort.length) {
            $sort.val(state.sort);
        }
        const $dir = $('#classmgmt-dir');
        if ($dir.length) {
            $dir.val(state.dir);
        }
        $('input[name="classmgmt-status"][value="' + state.status + '"]').prop('checked', true);
    };

    const bindFilterBar = () => {
        // Debounced search.
        $('#classmgmt-search').on('input', function() {
            const v = $(this).val();
            clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => {
                state.search = v;
                state.page = 0;
                triggerFetch();
            }, DEBOUNCE_MS);
        });

        $('#classmgmt-period').on('change', function() {
            state.periodid = parseInt($(this).val(), 10) || 0;
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-learningplan').on('change', function() {
            state.learningplanid = parseInt($(this).val(), 10) || 0;
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-course').on('change', function() {
            state.corecourseid = parseInt($(this).val(), 10) || 0;
            state.page = 0;
            triggerFetch();
        });

        $('input[name="classmgmt-status"]').on('change', function() {
            if (!$(this).is(':checked')) {
                return;
            }
            state.status = $(this).val();
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-perpage').on('change', function() {
            state.perpage = clampPerpage($(this).val());
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-sort').on('change', function() {
            state.sort = $(this).val();
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-dir').on('change', function() {
            state.dir = $(this).val() === 'ASC' ? 'ASC' : 'DESC';
            state.page = 0;
            triggerFetch();
        });

        $('#classmgmt-clear').on('click', function(event) {
            event.preventDefault();
            state.search = '';
            state.periodid = 0;
            state.learningplanid = 0;
            state.corecourseid = 0;
            state.status = 'active';
            state.sort = 'timecreated';
            state.dir = 'DESC';
            state.page = 0;
            state.perpage = 25;
            reflectStateToForm();
            triggerFetch();
        });
    };

    const bindPaginator = () => {
        $(document).on('click', '#classmgmt-paginator [data-page-action]', function(event) {
            event.preventDefault();
            const action = $(this).data('page-action');
            const totalPages = parseInt($('#classmgmt-paginator').data('totalpages'), 10) || 0;
            if (action === 'prev') {
                state.page = Math.max(0, state.page - 1);
            } else if (action === 'next') {
                state.page = Math.min(Math.max(0, totalPages - 1), state.page + 1);
            } else if (action === 'first') {
                state.page = 0;
            } else if (action === 'last') {
                state.page = Math.max(0, totalPages - 1);
            } else if (action === 'jump') {
                const v = parseInt($('#classmgmt-page-jump').val(), 10);
                if (!isNaN(v)) {
                    state.page = Math.max(0, Math.min(Math.max(0, totalPages - 1), v - 1));
                }
            }
            triggerFetch();
        });
    };

    /**
     * Per-row interactions (delete button + update period button) are
     * delegated to the document so they survive re-renders. The shift
     * update form posts directly so we don't intercept it.
     */
    const bindRowDelegation = () => {
        $(document).on('click', '.updatePeriodButton', function(event) {
            event.preventDefault();
            const btn = event.currentTarget;
            const classId = btn.getAttribute('data-class-id') || '';
            const className = btn.getAttribute('data-class-name') || '-';
            const studentCount = btn.getAttribute('data-student-count') || '0';

            const idInput = document.getElementById('updatePeriodClassId');
            const nameSpan = document.getElementById('updatePeriodClassName');
            const countSpan = document.getElementById('updatePeriodStudentCount');
            const sel = document.getElementById('updatePeriodSelect');
            if (idInput) {
                idInput.value = classId;
            }
            if (nameSpan) {
                nameSpan.textContent = className;
            }
            if (countSpan) {
                countSpan.textContent = studentCount;
            }
            if (sel) {
                sel.value = '';
            }
        });

        // Capture the id when a delete button is clicked so the modal
        // confirm uses it. The bulk select / "delete selected" path is
        // owned by delete_class.js; we only handle the single-row path.
        $(document).on('click', '.deleteButton', function() {
            const id = this.getAttribute('class-id') || '';
            if (id) {
                window.localStorage.setItem('classManagementDeleteId', id);
            }
        });
    };

    const triggerFetch = () => {
        if (inflight) {
            return;
        }
        inflight = true;
        showLoading();

        const promise = Ajax.call([{
            methodname: WS_METHOD,
            args: {
                search: state.search,
                periodid: state.periodid,
                learningplanid: state.learningplanid,
                corecourseid: state.corecourseid,
                status: state.status,
                sort: state.sort,
                dir: state.dir,
                page: state.page,
                perpage: state.perpage,
            }
        }]);

        promise[0].done(function(response) {
            inflight = false;
            hideLoading();
            lastResponse = response;
            renderResponse(response);
            persistToStorage();
            syncUrl();
        }).fail(function(ex) {
            inflight = false;
            hideLoading();
            window.console.error('class_management_filters fetch failed', ex);
            showError(ex && ex.message ? ex.message : 'Request failed');
        });
    };

    const showLoading = () => {
        const bar = document.getElementById('classmgmt-loading');
        if (bar) {
            bar.style.display = '';
        }
    };

    const hideLoading = () => {
        const bar = document.getElementById('classmgmt-loading');
        if (bar) {
            bar.style.display = 'none';
        }
    };

    const showError = (message) => {
        const bar = document.getElementById('classmgmt-error');
        if (!bar) {
            return;
        }
        bar.textContent = message;
        bar.style.display = '';
    };

    const renderResponse = (response) => {
        const items = (response && response.items) ? response.items : [];
        const total = (response && response.total) ? response.total : 0;
        const totalpages = (response && response.totalpages) ? response.totalpages : 0;
        const page = (response && typeof response.page === 'number') ? response.page : state.page;
        const perpage = (response && response.perpage) ? response.perpage : state.perpage;

        renderGrid(items);
        renderList(items);
        renderResultsCount(total, page, perpage);
        renderPaginator(total, totalpages, page);

        // Hide the empty-state banner if any items; show it otherwise.
        const empty = document.getElementById('classmgmt-empty');
        const gridContainer = document.getElementById('class-grid-view');
        const listContainer = document.getElementById('class-list-view');
        if (empty) {
            empty.style.display = items.length === 0 ? '' : 'none';
        }
        if (gridContainer) {
            gridContainer.style.display = items.length === 0 ? 'none' : '';
        }
        if (listContainer) {
            listContainer.style.display = items.length === 0 ? 'none' : '';
        }
    };

    const renderGrid = (items) => {
        const wrap = document.querySelector('#class-grid-view .d-flex.flex-wrap');
        if (!wrap) {
            return;
        }
        wrap.innerHTML = items.map(buildGridCard).join('');
    };

    const renderList = (items) => {
        const tbody = document.querySelector('#class-list-view table tbody');
        if (!tbody) {
            return;
        }
        if (items.length === 0) {
            tbody.innerHTML = '';
            return;
        }
        tbody.innerHTML = items.map(buildListRow).join('');
    };

    const renderResultsCount = (total, page, perpage) => {
        const counter = document.getElementById('classmgmt-results-count');
        if (!counter) {
            return;
        }
        if (total === 0) {
            counter.setAttribute('data-i18n-empty', '1');
            counter.textContent = '';
            return;
        }
        const from = (page * perpage) + 1;
        const to = Math.min(total, from + perpage - 1);
        // The template wraps the count in a data-i18n helper so we use
        // a simple substitution here. Strings are pre-translated by the
        // template renderer (initial paint) and by us here.
        counter.innerHTML = formatResultsCount(from, to, total);
    };

    const formatResultsCount = (from, to, total) => {
        const tpl = window.M && window.M.str && window.M.str['local_grupomakro_core']
            && window.M.str['local_grupomakro_core']['classmgmt:results_count'];
        if (tpl) {
            return tpl.replace('{$a->from}', from).replace('{$a->to}', to).replace('{$a->total}', total);
        }
        return from + '-' + to + ' of ' + total + ' classes';
    };

    const renderPaginator = (total, totalpages, page) => {
        const wrap = document.getElementById('classmgmt-paginator');
        if (!wrap) {
            return;
        }
        wrap.setAttribute('data-totalpages', String(totalpages));
        wrap.setAttribute('data-page', String(page));
        wrap.innerHTML = buildPaginatorHtml(total, totalpages, page);
    };

    const buildPaginatorHtml = (total, totalpages, page) => {
        const disabled = totalpages <= 1;
        const isFirst = page <= 0;
        const isLast = page >= totalpages - 1;
        const prevLabel = getStr('classmgmt:previous_page', 'Previous');
        const nextLabel = getStr('classmgmt:next_page', 'Next');
        const ofTpl = getStr('classmgmt:page_x_of_y', 'Page {$a->page} of {$a->total}')
            .replace('{$a->page}', String(page + 1))
            .replace('{$a->total}', String(Math.max(1, totalpages)));
        if (disabled) {
            return '<span class="text-muted small">' + escapeHtml(ofTpl) + '</span>';
        }
        return ''
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="first" ' + (isFirst ? 'disabled' : '') + '><i class="fa fa-step-backward"></i></button> '
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="prev" ' + (isFirst ? 'disabled' : '') + '><i class="fa fa-chevron-left"></i> ' + escapeHtml(prevLabel) + '</button> '
            + '<span class="mx-2 small text-muted">' + escapeHtml(ofTpl) + '</span> '
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="next" ' + (isLast ? 'disabled' : '') + '>' + escapeHtml(nextLabel) + ' <i class="fa fa-chevron-right"></i></button> '
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="last" ' + (isLast ? 'disabled' : '') + '><i class="fa fa-step-forward"></i></button>';
    };

    const getStr = (key, fallback) => {
        const m = window.M && window.M.str && window.M.str['local_grupomakro_core'];
        return (m && m[key]) ? m[key] : fallback;
    };

    const escapeHtml = (s) => {
        if (s === undefined || s === null) {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const buildGridCard = (c) => {
        const closedBadge = c.closed === 1
            ? '<span class="badge badge-secondary ml-2">' + escapeHtml(getStr('classmgmt:closed_badge', 'Closed')) + '</span>'
            : '';
        const groupurl = c.groupurl
            ? '<a href="' + escapeHtml(c.groupurl) + '" target="_blank" rel="noopener" class="text-decoration-none text-dark">' + escapeHtml(c.name) + ' <i class="fa fa-external-link text-muted ml-1" style="font-size:12px"></i></a>' + closedBadge
            : escapeHtml(c.name) + closedBadge;
        const typelabel = escapeHtml(c.typelabel || '');
        const icon = escapeHtml(c.icon || '');
        const startDate = escapeHtml(c.startDate || '');
        const inithour = escapeHtml(c.inithourformatted || '');
        const days = escapeHtml(c.classDaysString || '');
        const instructorImg = escapeHtml(c.instructorProfileImage || '');
        const instructorName = escapeHtml(c.instructorName || '');
        const shiftValue = escapeHtml(c.shiftvalue || '');
        const shiftDisplay = escapeHtml(c.shiftdisplay || '');
        const shiftPlaceholder = escapeHtml(getStr('classmgmt:sort_name', 'Sin jornada'));
        return ''
            + '<div class="card position-relative border-0 mx-2 mb-3 shadow-sm" style="width: 320px;">'
            +   '<div class="v-alert__border v-alert__border--top"></div>'
            +   '<div class="card-body position-relative pb-2">'
            +     '<h6 class="card-title">' + groupurl + '</h6>'
            +     '<div class="d-flex mb-2">'
            +       '<i class="mr-3"><img src="' + instructorImg + '" class="rounded-circle" style="width:40px"/></i>'
            +       '<div class="d-flex flex-column">'
            +         '<span>' + instructorName + '</span>'
            +         '<span class="text-primary">Instructor</span>'
            +       '</div>'
            +     '</div>'
            +     '<div class="d-flex pl-2 flex-column">'
            +       '<div class="mb-1"><i class="' + icon + ' mr-1"></i> ' + typelabel + '</div>'
            +       '<div class="mb-1"><i class="fa fa-calendar-o mr-2"></i> <span class="text-muted startdate">' + startDate + '</span></div>'
            +       '<div class="mb-1"><i class="fa fa-clock-o mr-2"></i> <span class="text-muted startdate">' + inithour + '</span> <small class="text-muted">' + days + '</small></div>'
            +       '<div class="mb-1 mt-2">'
            +         '<small class="font-weight-bold d-block">' + shiftDisplay + '</small>'
            +         '<form method="post" class="d-flex align-items-center mt-1">'
            +           '<input type="hidden" name="sesskey" value="' + escapeHtml(state.sesskey) + '">'
            +           '<input type="hidden" name="action" value="update_shift">'
            +           '<input type="hidden" name="classid" value="' + parseInt(c.id, 10) + '">'
            +           '<input type="text" name="shift" value="' + shiftValue + '" class="form-control form-control-sm mr-2" placeholder="' + shiftPlaceholder + '">'
            +           '<button type="submit" class="btn btn-sm btn-outline-primary">' + escapeHtml(getStr('classmgmt:sort_asc', 'Guardar')) + '</button>'
            +         '</form>'
            +       '</div>'
            +     '</div>'
            +   '</div>'
            +   '<div class="card-footer d-flex">'
            +     '<div class="spacer"></div>'
            +     '<div class="options">'
            +       '<a data-toggle="tooltip" data-placement="bottom" title="Modificar" href="/local/grupomakro_core/pages/editclass.php?class_id=' + parseInt(c.id, 10) + '"><i class="fa fa-gear mx-1 text-secondary" style="font-size:20px"></i></a>'
            +       '<a data-toggle="tooltip" data-placement="bottom" title="Actualizar periodo" href="#" class="updatePeriodButton" data-class-id="' + parseInt(c.id, 10) + '" data-class-name="' + escapeHtml(c.name) + '" data-student-count="' + parseInt(c.enroledStudents, 10) + '"><i class="fa fa-calendar mx-1 text-info" style="font-size:20px"></i></a>'
            +       '<a data-placement="bottom" title="Eliminar" data-toggle="modal" class="deleteButton" class-id="' + parseInt(c.id, 10) + '" data-target="#deleteClassModalCenter"><i class="fa fa-trash mx-1" style="font-size:20px"></i></a>'
            +     '</div>'
            +   '</div>'
            + '</div>';
    };

    const buildListRow = (c) => {
        const closedBadge = c.closed === 1
            ? '<span class="badge badge-secondary ml-2">' + escapeHtml(getStr('classmgmt:closed_badge', 'Closed')) + '</span>'
            : '';
        const groupurl = c.groupurl
            ? '<a href="' + escapeHtml(c.groupurl) + '" target="_blank" rel="noopener" class="text-decoration-none">' + escapeHtml(c.name) + ' <i class="fa fa-external-link text-muted ml-1" style="font-size:11px"></i></a>' + closedBadge
            : escapeHtml(c.name) + closedBadge;
        const typelabel = escapeHtml(c.typelabel || '');
        const icon = escapeHtml(c.icon || '');
        const startDate = escapeHtml(c.startDate || '');
        const inithour = escapeHtml(c.inithourformatted || '');
        const days = escapeHtml(c.classDaysString || '');
        const instructorImg = escapeHtml(c.instructorProfileImage || '');
        const instructorName = escapeHtml(c.instructorName || '');
        const shiftValue = escapeHtml(c.shiftvalue || '');
        const shiftPlaceholder = escapeHtml(getStr('classmgmt:sort_name', 'Sin jornada'));
        return ''
            + '<tr>'
            +   '<td class="text-center align-middle">'
            +     '<input type="checkbox" class="class-checkbox" value="' + parseInt(c.id, 10) + '">'
            +   '</td>'
            +   '<td class="align-middle font-weight-bold">' + groupurl + '</td>'
            +   '<td class="align-middle">'
            +     '<div class="d-flex align-items-center">'
            +       '<img src="' + instructorImg + '" class="rounded-circle mr-2" style="width:32px; height:32px; object-fit:cover;">'
            +       '<div class="d-flex flex-column"><span class="mb-0">' + instructorName + '</span></div>'
            +     '</div>'
            +   '</td>'
            +   '<td class="align-middle"><i class="' + icon + ' mr-1"></i> ' + typelabel + '</td>'
            +   '<td class="align-middle"><i class="fa fa-calendar-o mr-1 text-muted"></i> ' + startDate + '</td>'
            +   '<td class="align-middle"><div><i class="fa fa-clock-o mr-1 text-muted"></i> ' + inithour + '</div><small class="text-muted">' + days + '</small></td>'
            +   '<td class="align-middle">'
            +     '<form method="post" class="d-flex align-items-center">'
            +       '<input type="hidden" name="sesskey" value="' + escapeHtml(state.sesskey) + '">'
            +       '<input type="hidden" name="action" value="update_shift">'
            +       '<input type="hidden" name="classid" value="' + parseInt(c.id, 10) + '">'
            +       '<input type="text" name="shift" value="' + shiftValue + '" class="form-control form-control-sm mr-2" style="min-width: 110px;" placeholder="' + shiftPlaceholder + '">'
            +       '<button type="submit" class="btn btn-sm btn-outline-primary">' + escapeHtml(getStr('classmgmt:sort_asc', 'Guardar')) + '</button>'
            +     '</form>'
            +   '</td>'
            +   '<td class="align-middle text-center">'
            +     '<a data-toggle="tooltip" title="Modificar" href="/local/grupomakro_core/pages/editclass.php?class_id=' + parseInt(c.id, 10) + '" class="btn btn-sm btn-light border"><i class="fa fa-gear text-secondary"></i></a>'
            +     '<a data-toggle="tooltip" title="Actualizar periodo" href="#" class="updatePeriodButton btn btn-sm btn-light border text-info ml-1" data-class-id="' + parseInt(c.id, 10) + '" data-class-name="' + escapeHtml(c.name) + '" data-student-count="' + parseInt(c.enroledStudents, 10) + '"><i class="fa fa-calendar"></i></a>'
            +     '<a title="Eliminar" data-toggle="modal" class="deleteButton btn btn-sm btn-light border text-danger ml-1" class-id="' + parseInt(c.id, 10) + '" data-target="#deleteClassModalCenter"><i class="fa fa-trash"></i></a>'
            +   '</td>'
            + '</tr>';
    };

    return {init: init};
});
