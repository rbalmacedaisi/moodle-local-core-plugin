/**
 * manage_courses.js — Rich class management dashboard.
 * Lazy-loads per-class stats (attendance, students, weights, activities, BBB)
 * and enforces validation before closing a class.
 */
require(["jquery", "core/modal_factory", "core/modal_events"],
function ($, ModalFactory, ModalEvents) {

    // ── Cache ──────────────────────────────────────────────────────────────
    var gmkStats = {}; // classId → stats object

    // ── Helpers ───────────────────────────────────────────────────────────
    function ajaxGet(action, params) {
        return $.ajax({
            url: window.gmkAjaxUrl,
            method: 'GET',
            data: $.extend({ action: action, sesskey: window.gmkSesskey }, params)
        });
    }

    function loadStats(classId) {
        if (gmkStats[classId]) {
            return $.Deferred().resolve(gmkStats[classId]).promise();
        }
        return ajaxGet('local_grupomakro_get_class_stats', { classId: classId })
            .then(function (r) {
                var s = (r && r.data) ? r.data : null;
                if (s) { gmkStats[classId] = s; }
                return s;
            });
    }

    // ── Sub-modal (stacked on top of any existing modal) ──────────────────
    function showSubModal(title, bodyHtml) {
        var id = 'gmk-sub-modal-overlay';
        $('#' + id).remove();
        var overlay = $(
            '<div id="' + id + '" style="position:fixed;top:0;left:0;width:100%;height:100%;' +
            'z-index:9999;background:rgba(0,0,0,.55);display:flex;align-items:center;' +
            'justify-content:center;padding:16px;">' +
            '<div class="gmk-sub-modal-box" style="background:#fff;border-radius:8px;' +
            'max-width:720px;width:100%;max-height:85vh;display:flex;flex-direction:column;">' +
            '<div class="gmk-sub-header" style="padding:16px 20px;border-bottom:1px solid #eee;' +
            'font-weight:600;font-size:1rem;display:flex;justify-content:space-between;align-items:center;">' +
            '<span>' + title + '</span>' +
            '<button class="btn btn-sm btn-light gmk-sub-close" style="font-size:18px;line-height:1;">&times;</button>' +
            '</div>' +
            '<div class="gmk-sub-body" style="padding:16px 20px;overflow-y:auto;flex:1;">' +
            bodyHtml + '</div></div></div>'
        );
        $('body').append(overlay);
        overlay.on('click', '.gmk-sub-close', function () { overlay.remove(); });
        overlay.on('click', function (e) { if ($(e.target).is(overlay)) { overlay.remove(); } });
    }

    // ── Stat pills ────────────────────────────────────────────────────────
    function buildStatsRow(classId, stats) {
        if (!stats) {
            return '<div class="gmk-stats-row mt-1"><small class="text-muted">Sin datos.</small></div>';
        }

        var a = stats.attendance;
        var s = stats.students;
        var g = stats.grades;
        var b = stats.bbb;

        var pills = '';

        // Attendance
        var attOk  = a.pending === 0;
        var attCls = attOk ? 'gmk-pill-ok' : 'gmk-pill-danger';
        var attTxt = attOk
            ? (a.total_past + ' ses.')
            : (a.pending + ' pendiente' + (a.pending > 1 ? 's' : ''));
        pills += '<span class="gmk-pill ' + attCls + '" title="Sesiones de asistencia — ' +
                 a.complete + '/' + a.total_past + ' registradas">' +
                 '<i class="mdi mdi-clipboard-check-outline"></i> ' + attTxt + '</span>';

        // Students (clickable)
        var stuTxt = s.total + ' est.' +
                     (s.total > 0 ? ' <span style="color:#28a745">✓' + s.approved + '</span>' +
                     ' <span style="color:#dc3545">✗' + s.failed + '</span>' : '');
        pills += '<span class="gmk-pill gmk-pill-info gmk-pill-click" ' +
                 'data-action="students" data-classid="' + classId + '" ' +
                 'title="Ver listado de estudiantes">' +
                 '<i class="mdi mdi-account-group"></i> ' + stuTxt + '</span>';

        // Grade weights (clickable)
        var wOk  = g.is_100;
        var wCls = wOk ? 'gmk-pill-ok' : 'gmk-pill-warning';
        pills += '<span class="gmk-pill ' + wCls + ' gmk-pill-click" ' +
                 'data-action="grades" data-classid="' + classId + '" ' +
                 'title="Ver ponderaciones">' +
                 '<i class="mdi mdi-scale-balance"></i> ' + g.total_weight + '%</span>';

        // Activities
        pills += '<span class="gmk-pill gmk-pill-neutral" title="Actividades calificables">' +
                 '<i class="mdi mdi-book-open-page-variant"></i> ' + g.item_count + ' act.</span>';

        // BBB recordings
        var bbbTxt = b.with_recordings + '/' + b.total + ' grab.';
        var bbbCls = (b.total > 0 && b.with_recordings < b.total) ? 'gmk-pill-warning' : 'gmk-pill-neutral';
        pills += '<span class="gmk-pill ' + bbbCls + '" title="Sesiones BBB grabadas">' +
                 '<i class="mdi mdi-video"></i> ' + bbbTxt + '</span>';

        return '<div class="gmk-stats-row mt-2">' + pills + '</div>';
    }

    // ── Student list sub-modal ────────────────────────────────────────────
    function openStudentModal(list, className) {
        var statusColors = { 4: '#28a745', 5: '#dc3545', 6: '#fd7e14', 2: '#007bff',
                             1: '#6c757d', 0: '#aaa', 3: '#17a2b8', 7: '#6f42c1' };
        var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
            '<thead><tr style="background:#343a40;color:#fff;">' +
            '<th style="padding:8px 10px;">Estudiante</th>' +
            '<th style="padding:8px 10px;">ID / Cédula</th>' +
            '<th style="padding:8px 10px;">Estado</th>' +
            '<th style="padding:8px 10px;text-align:right;">Nota</th>' +
            '</tr></thead><tbody>';

        if (list.length === 0) {
            html += '<tr><td colspan="4" style="padding:12px;text-align:center;color:#999;">Sin estudiantes.</td></tr>';
        }
        list.forEach(function (st, i) {
            var bg  = i % 2 === 0 ? '#fff' : '#f8f9fa';
            var col = statusColors[st.status] || '#6c757d';
            html += '<tr style="background:' + bg + ';">' +
                '<td style="padding:7px 10px;">' + htmlEsc(st.name) + '</td>' +
                '<td style="padding:7px 10px;color:#666;">' + htmlEsc(st.idnumber) + '</td>' +
                '<td style="padding:7px 10px;">' +
                '<span style="background:' + col + ';color:#fff;padding:2px 8px;border-radius:4px;' +
                'font-size:11px;">' + htmlEsc(st.status_label) + '</span></td>' +
                '<td style="padding:7px 10px;text-align:right;font-weight:600;">' +
                (st.grade !== null && st.grade !== undefined ? st.grade : '—') + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        showSubModal('Estudiantes — ' + (className || ''), html);
    }

    // ── Grade weights sub-modal ───────────────────────────────────────────
    function openGradeModal(items, totalWeight, className) {
        var isOk     = Math.abs(totalWeight - 100) < 0.5;
        var headerBg = isOk ? '#28a745' : '#ffc107';
        var headerFg = isOk ? '#fff' : '#333';

        var html = '<div style="background:' + headerBg + ';color:' + headerFg + ';' +
            'padding:10px 14px;border-radius:6px;margin-bottom:12px;font-weight:600;">' +
            'Total ponderación: ' + totalWeight + '%' +
            (isOk ? ' ✓' : ' ⚠ Debe ser 100%') + '</div>' +
            '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
            '<thead><tr style="background:#343a40;color:#fff;">' +
            '<th style="padding:8px 10px;">Actividad</th>' +
            '<th style="padding:8px 10px;">Tipo</th>' +
            '<th style="padding:8px 10px;text-align:right;">Peso %</th>' +
            '<th style="padding:8px 10px;text-align:right;">Nota máx.</th>' +
            '<th style="padding:8px 10px;text-align:right;">Prom. est.</th>' +
            '<th style="padding:8px 10px;text-align:right;">Calificados</th>' +
            '</tr></thead><tbody>';

        if (items.length === 0) {
            html += '<tr><td colspan="6" style="padding:12px;text-align:center;color:#999;">Sin actividades.</td></tr>';
        }
        items.forEach(function (it, i) {
            var bg = i % 2 === 0 ? '#fff' : '#f8f9fa';
            html += '<tr style="background:' + bg + ';">' +
                '<td style="padding:7px 10px;">' + htmlEsc(it.name) + '</td>' +
                '<td style="padding:7px 10px;color:#666;">' + htmlEsc(it.module) + '</td>' +
                '<td style="padding:7px 10px;text-align:right;font-weight:600;">' + it.weight + '%</td>' +
                '<td style="padding:7px 10px;text-align:right;">' + it.grademax + '</td>' +
                '<td style="padding:7px 10px;text-align:right;">' +
                (it.avg_pct !== null ? it.avg_pct + '%' : '—') + '</td>' +
                '<td style="padding:7px 10px;text-align:right;">' + it.graded_count + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        showSubModal('Ponderaciones — ' + (className || ''), html);
    }

    function htmlEsc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Build HTML for one class item ─────────────────────────────────────
    function buildClassHtml(sch) {
        var isClosed = sch.closed == 1;
        var badge    = isClosed
            ? '<span class="badge badge-danger ml-2">CERRADO</span>'
            : '<span class="badge badge-success ml-2">ABIERTO</span>';
        var icon     = isClosed ? 'mdi-lock' : 'mdi-calendar-clock';
        var avatarCls = isClosed ? 'custom-avatar' : 'custom-avatar blue-avatar';

        var dateRange = '';
        if (sch.initdate > 0 && sch.enddate > 0) {
            dateRange = ' &nbsp;|&nbsp; ' +
                new Date(sch.initdate * 1000).toLocaleDateString() + ' → ' +
                new Date(sch.enddate  * 1000).toLocaleDateString();
        }

        var actions = '';
        if (isClosed) {
            actions += '<button class="btn btn-sm btn-outline-primary reopen-schedule-btn" ' +
                       'data-id="' + sch.id + '">' +
                       '<i class="mdi mdi-lock-open-variant"></i> Re-abrir</button> ';
        } else {
            actions += '<button class="btn btn-sm btn-outline-danger close-class-btn" ' +
                       'data-id="' + sch.id + '">' +
                       '<i class="mdi mdi-lock"></i> Cerrar</button> ';
        }
        if (sch.approved == 1) {
            actions += '<button class="btn btn-sm btn-outline-warning revert-schedule-btn" ' +
                       'data-id="' + sch.id + '">' +
                       '<i class="mdi mdi-undo"></i> Revertir</button> ';
            if (!isClosed) {
                actions += '<button class="btn btn-sm btn-outline-info enroll-student-btn" ' +
                           'data-id="' + sch.id + '" data-name="' + htmlEsc(sch.name) + '">' +
                           '<i class="mdi mdi-account-plus"></i> Inscribir</button>';
            }
        }

        return '<li class="item-list" data-classid="' + sch.id + '" ' +
               'style="' + (isClosed ? 'opacity:.85;background:#f9f9f9;' : '') + '">' +
               '<div class="' + avatarCls + '"><i class="mdi ' + icon + '"></i></div>' +
               '<div class="list-item-info" style="flex:1;">' +
               '<div class="list-item-info-text">' +
               '<p style="margin:0;">' + htmlEsc(sch.name) + badge + '</p>' +
               '<span class="list-item-subtext">' +
               sch.inithourformatted + ' – ' + sch.endhourformatted + dateRange +
               '</span></div>' +
               '<div id="gmk-stats-' + sch.id + '" class="gmk-stats-placeholder">' +
               '<small class="text-muted"><i class="mdi mdi-loading mdi-spin"></i> Cargando…</small>' +
               '</div>' +
               '</div>' +
               '<div class="list-item-actions pl-2" style="white-space:nowrap;">' + actions + '</div>' +
               '</li>';
    }

    // ── Open schedules modal ──────────────────────────────────────────────
    function openSchedulesModal(courseId, courseName) {
        var data      = (window.gmkCourseData && window.gmkCourseData[courseId])
            ? window.gmkCourseData[courseId] : null;
        var schedules = data ? data.schedules : [];

        var bodyHtml = '<div class="modal-mimic-title">' + htmlEsc(courseName) + '</div>' +
            '<ul class="modules-item-list">';

        if (schedules.length > 0) {
            schedules.forEach(function (sch) { bodyHtml += buildClassHtml(sch); });
        } else {
            bodyHtml += '<li class="item-list">' +
                '<i class="mdi mdi-alert-circle-outline mr-2"></i> No hay horarios registrados.</li>';
        }
        bodyHtml += '</ul>';

        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: 'Horarios del Curso',
            body: bodyHtml,
            large: true
        }).then(function (modal) {
            modal.show();
            // Lazy-load stats for every class in this modal
            schedules.forEach(function (sch) {
                loadStats(sch.id).then(function (stats) {
                    var placeholder = $('#gmk-stats-' + sch.id);
                    if (placeholder.length) {
                        placeholder.html(buildStatsRow(sch.id, stats));
                    }
                });
            });
        });
    }

    // ── Inline enrollment modal (unchanged logic, new style) ──────────────
    function openEnrollModal(classId, className) {
        var bodyHtml =
            '<div class="form-group">' +
            '<label>Buscar usuario (nombre o email)</label>' +
            '<div class="input-group">' +
            '<input type="text" class="form-control" id="gmk-enroll-input" placeholder="Mínimo 3 caracteres…">' +
            '<div class="input-group-append">' +
            '<button class="btn btn-primary" id="gmk-enroll-search" data-classid="' + classId + '">Buscar</button>' +
            '</div></div></div>' +
            '<div id="gmk-enroll-results" style="max-height:220px;overflow-y:auto;margin-top:8px;"></div>';

        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: 'Inscribir estudiante — ' + htmlEsc(className),
            body: bodyHtml
        }).then(function (modal) {
            modal.show();
            setTimeout(function () {
                $('#gmk-enroll-search').data('classid', classId);
            }, 80);
        });
    }

    // ── Close class — progress modal + background mode ────────────────────
    var GMK_CLOSE_STEPS = [
        'Verificando ponderaciones y configuración',
        'Registrando inasistencias pendientes como ausentes',
        'Calculando calificaciones finales de cada estudiante',
        'Generando registro de cierre'
    ];

    function gmkEsc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
                              .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function gmkUpdateStep(i, state) {
        var el = $('#gmk-step-' + i);
        if (!el.length) { return; }
        var icons = {
            pending: '<i class="mdi mdi-circle-outline" style="color:#ccc;"></i>',
            active:  '<i class="mdi mdi-loading mdi-spin" style="color:#1976d2;"></i>',
            done:    '<i class="mdi mdi-check-circle" style="color:#28a745;"></i>',
            error:   '<i class="mdi mdi-close-circle" style="color:#dc3545;"></i>'
        };
        el.find('.gmk-close-step-icon').html(icons[state] || icons.pending);
        var c = state === 'active' ? '#1565c0' : state === 'done' ? '#28a745' :
                state === 'error' ? '#dc3545' : '#aaa';
        el.find('.gmk-close-step-label').css({ color: c, fontWeight: state === 'active' ? '600' : '400' });
    }

    function gmkSetProgress(pct) {
        $('#gmk-close-progress').css('width', Math.min(pct, 100) + '%');
    }

    function gmkShowFloatBadge(state, html) {
        var badge = $('#gmk-close-float');
        if (!badge.length) { badge = $('<div id="gmk-close-float"></div>'); $('body').append(badge); }
        var bg = { running: '#1565c0', success: '#2e7d32', error: '#c62828' }[state] || '#1565c0';
        badge.css('background', bg).html(html);
        if (state !== 'running') {
            badge.off('click').on('click', function () { badge.fadeOut(300, function () { badge.remove(); }); });
            setTimeout(function () { badge.fadeOut(500, function () { badge.remove(); }); }, 9000);
        }
    }

    function gmkCloseModalHtml() {
        var steps = '';
        GMK_CLOSE_STEPS.forEach(function (lbl, i) {
            steps += '<div class="gmk-close-step" id="gmk-step-' + i + '">' +
                     '<span class="gmk-close-step-icon"><i class="mdi mdi-circle-outline" style="color:#ccc;"></i></span>' +
                     '<span class="gmk-close-step-label" style="color:#aaa;">' + gmkEsc(lbl) + '</span></div>';
        });
        return '<div id="gmk-close-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;' +
            'z-index:10001;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;padding:16px;">' +
            '<div style="background:#fff;border-radius:12px;padding:28px 30px;max-width:480px;width:100%;' +
            'box-shadow:0 8px 40px rgba(0,0,0,.3);">' +
            // Header
            '<div style="display:flex;align-items:center;gap:12px;margin-bottom:22px;">' +
            '<div style="width:42px;height:42px;border-radius:50%;background:#e3f2fd;display:flex;' +
            'align-items:center;justify-content:center;flex-shrink:0;">' +
            '<i class="mdi mdi-lock-clock" style="font-size:22px;color:#1976d2;"></i></div>' +
            '<div><div style="font-weight:700;font-size:1.05rem;">Cerrando clase</div>' +
            '<div style="font-size:12px;color:#888;margin-top:2px;">Esto puede tardar unos segundos — puede continuar navegando</div></div></div>' +
            // Steps
            '<div id="gmk-close-steps" style="margin-bottom:18px;">' + steps + '</div>' +
            // Progress bar
            '<div style="background:#e9ecef;border-radius:4px;height:6px;margin-bottom:20px;overflow:hidden;">' +
            '<div id="gmk-close-progress" style="background:#1976d2;height:6px;border-radius:4px;' +
            'width:0%;transition:width .6s ease;"></div></div>' +
            // Result area
            '<div id="gmk-close-result" style="display:none;margin-bottom:16px;"></div>' +
            // Buttons
            '<div id="gmk-close-btns" style="display:flex;gap:8px;justify-content:flex-end;">' +
            '<button id="gmk-close-bg-btn" class="btn btn-sm btn-outline-secondary">' +
            '<i class="mdi mdi-arrow-collapse-down"></i> Segundo plano</button>' +
            '</div></div></div>';
    }

    function closeClass(classId) {
        var stats = gmkStats[classId];

        // Pre-flight: ponderaciones
        if (stats && !stats.grades.is_100) {
            alert('No se puede cerrar: las ponderaciones suman ' +
                  stats.grades.total_weight + '% (debe ser 100%).');
            return;
        }

        // Pre-flight: asistencia pendiente — aviso, no bloqueo
        var pendingMsg = '';
        if (stats && stats.attendance.pending > 0) {
            pendingMsg = 'Hay ' + stats.attendance.pending + ' sesión(es) con estudiantes sin registro.\n' +
                         'Se marcarán automáticamente como inasistencia.\n\n';
        }

        if (!confirm(pendingMsg + '¿Cerrar esta clase y calcular las calificaciones finales?\n' +
                     'Esta acción bloqueará el libro de calificaciones.')) {
            return;
        }

        // Show progress modal
        var modal = $(gmkCloseModalHtml());
        $('body').append(modal);
        GMK_CLOSE_STEPS.forEach(function (_, i) { gmkUpdateStep(i, 'pending'); });
        gmkUpdateStep(0, 'active');
        gmkSetProgress(5);

        var isBackground = false;
        var requestDone  = false;
        var lastResult   = null;

        // Simulate step advancement while request runs
        var stepDelays = [2800, 5400, 8000];
        var stepTimers = stepDelays.map(function (ms, idx) {
            return setTimeout(function () {
                if (requestDone) { return; }
                gmkUpdateStep(idx, 'done');
                gmkUpdateStep(idx + 1, 'active');
                gmkSetProgress(10 + (idx + 1) * 22);
            }, ms);
        });

        // Slow progress-bar crawl
        var crawlTimer = setInterval(function () {
            var bar = document.getElementById('gmk-close-progress');
            if (!bar) { clearInterval(crawlTimer); return; }
            var cur = parseFloat(bar.style.width) || 0;
            if (cur < 88) { gmkSetProgress(cur + 0.3); }
        }, 350);

        // Background button
        modal.on('click', '#gmk-close-bg-btn', function () {
            isBackground = true;
            $('#gmk-close-overlay').remove();
            gmkShowFloatBadge('running',
                '<i class="mdi mdi-loading mdi-spin"></i> Cerrando clase...');
            if (requestDone) { applyResult(lastResult); }
        });

        function applyResult(r) {
            stepTimers.forEach(clearTimeout);
            clearInterval(crawlTimer);
            requestDone = true;
            lastResult  = r;

            var ok = r && r.data && r.data.ok;
            var sm = ok ? (r.data.summary || {}) : null;

            if (isBackground) {
                if (ok) {
                    gmkShowFloatBadge('success',
                        '<i class="mdi mdi-check-circle" style="font-size:20px;"></i>' +
                        '<div><div style="font-weight:700;">Clase cerrada</div>' +
                        '<div style="font-size:12px;opacity:.9;">✅ ' + (sm.approved || 0) +
                        ' aprobados &nbsp;❌ ' + (sm.failed || 0) + ' reprobados</div></div>');
                    setTimeout(function () { location.reload(); }, 5000);
                } else {
                    gmkShowFloatBadge('error',
                        '<i class="mdi mdi-alert-circle" style="font-size:20px;"></i>' +
                        '<div><div style="font-weight:700;">Error al cerrar</div>' +
                        '<div style="font-size:12px;opacity:.9;">' +
                        gmkEsc((r && r.data && r.data.error) || 'Error desconocido') + '</div></div>');
                }
                return;
            }

            // Update modal with result
            GMK_CLOSE_STEPS.forEach(function (_, i) {
                gmkUpdateStep(i, ok ? 'done' : (i === 0 ? 'error' : 'pending'));
            });
            gmkSetProgress(ok ? 100 : 25);
            $('#gmk-close-bg-btn').hide();

            var resultHtml;
            if (ok) {
                var cards = [
                    { n: sm.approved || 0, lbl: 'Aprobados',      color: '#28a745' },
                    { n: sm.failed   || 0, lbl: 'Reprobados',     color: '#dc3545' }
                ];
                if (sm.revalid  > 0) { cards.push({ n: sm.revalid,  lbl: 'Pend. reválida', color: '#856404' }); }
                if (sm.no_grade > 0) { cards.push({ n: sm.no_grade, lbl: 'Sin nota',        color: '#6c757d' }); }
                var cardHtml = cards.map(function (c) {
                    return '<div style="text-align:center;background:#fff;border-radius:8px;padding:12px 10px;min-width:90px;">' +
                           '<div style="font-size:26px;font-weight:700;color:' + c.color + ';">' + c.n + '</div>' +
                           '<div style="font-size:11px;color:#555;">' + c.lbl + '</div></div>';
                }).join('');
                resultHtml =
                    '<div style="background:#f0fff4;border:1px solid #b7dfbd;border-radius:10px;padding:16px 18px;">' +
                    '<div style="font-weight:700;color:#155724;font-size:15px;margin-bottom:12px;">' +
                    '✓ Clase cerrada correctamente</div>' +
                    '<div style="display:flex;flex-wrap:wrap;gap:8px;">' + cardHtml + '</div></div>';
            } else {
                resultHtml =
                    '<div style="background:#fff5f5;border:1px solid #f5c6cb;border-radius:10px;padding:16px 18px;">' +
                    '<div style="font-weight:700;color:#721c24;margin-bottom:6px;">✗ No se pudo cerrar la clase</div>' +
                    '<div style="font-size:13px;color:#721c24;">' +
                    gmkEsc((r && r.data && r.data.error) || 'Error desconocido') + '</div></div>';
            }

            $('#gmk-close-result').html(resultHtml).show();
            $('#gmk-close-btns').append(
                '<button id="gmk-close-done-btn" class="btn btn-sm ' +
                (ok ? 'btn-success' : 'btn-secondary') + '">' +
                (ok ? '<i class="mdi mdi-check"></i> Entendido' : '<i class="mdi mdi-close"></i> Cerrar') +
                '</button>'
            );
            modal.on('click', '#gmk-close-done-btn', function () {
                $('#gmk-close-overlay').remove();
                if (ok) { location.reload(); }
            });
        }

        ajaxGet('local_grupomakro_close_class_period', { classId: classId })
            .done(function (r) { applyResult(r); })
            .fail(function (xhr) {
                applyResult({ data: {
                    ok: false,
                    error: (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText || 'Error de red'
                }});
            });
    }

    // ── CSS injected once ──────────────────────────────────────────────────
    $('head').append('<style>' +
        '.gmk-stats-row { display:flex; flex-wrap:wrap; gap:6px; }' +
        '.gmk-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 10px;' +
        '  border-radius:12px; font-size:12px; font-weight:500; line-height:1.4; }' +
        '.gmk-pill i { font-size:13px; }' +
        '.gmk-pill-ok      { background:#d4edda; color:#155724; }' +
        '.gmk-pill-danger  { background:#f8d7da; color:#721c24; }' +
        '.gmk-pill-warning { background:#fff3cd; color:#856404; }' +
        '.gmk-pill-info    { background:#d1ecf1; color:#0c5460; }' +
        '.gmk-pill-neutral { background:#e2e3e5; color:#383d41; }' +
        '.gmk-pill-click   { cursor:pointer; }' +
        '.gmk-pill-click:hover { filter:brightness(.92); }' +
        // Close-progress modal
        '.gmk-close-step{display:flex;align-items:center;gap:10px;margin:6px 0;font-size:13.5px;}' +
        '.gmk-close-step-icon{width:22px;text-align:center;flex-shrink:0;font-size:16px;}' +
        '.gmk-close-step-label{transition:color .3s;}' +
        '#gmk-close-float{position:fixed;bottom:24px;right:24px;z-index:10002;border-radius:12px;' +
        'padding:14px 18px;box-shadow:0 4px 20px rgba(0,0,0,.35);font-size:13.5px;font-weight:500;' +
        'display:flex;align-items:center;gap:10px;min-width:220px;color:#fff;cursor:pointer;}' +
        '#gmk-close-float:hover{filter:brightness(.93);}' +
        '</style>');

    // ── Period close — calls local_grupomakro_close_period and shows result table ──
    function closePeriod(periodId, periodName) {
        if (!confirm('¿Cerrar TODAS las clases activas del período "' + periodName + '"?\n\n' +
                     'Se recalcularán las notas finales y se actualizarán los estados académicos.\n' +
                     'Esta acción es reversible solo clase por clase mediante el botón Re-abrir.')) {
            return;
        }

        var btnEl = $('#gmk-close-period-btn');
        btnEl.prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin"></i> Cerrando…');

        ajaxGet('local_grupomakro_close_period', { periodId: periodId })
            .done(function (r) {
                btnEl.prop('disabled', false).html('<i class="mdi mdi-lock-check"></i> Cerrar período');
                if (!r || !r.data) {
                    alert('Respuesta inesperada del servidor.');
                    return;
                }
                var d = r.data;
                var rows = '';
                (d.results || []).forEach(function (res) {
                    var icon    = res.ok ? '✓' : '✗';
                    var iconCls = res.ok ? 'color:#28a745' : 'color:#dc3545';
                    var detail  = res.ok
                        ? ('✓' + res.summary.approved + ' &nbsp;✗' + res.summary.failed +
                           (res.summary.revalid > 0 ? ' &nbsp;⚠' + res.summary.revalid : '') +
                           (res.summary.no_grade > 0 ? ' &nbsp;—' + res.summary.no_grade : ''))
                        : ('<span style="color:#dc3545">' + htmlEsc(res.error || '—') + '</span>');
                    rows += '<tr style="background:' + (rows === '' ? '#fff' : '#f8f9fa') + ';">' +
                        '<td style="padding:7px 10px;font-weight:500;">' + htmlEsc(res.name) + '</td>' +
                        '<td style="padding:7px 10px;text-align:center;' + iconCls + ';font-weight:700;">' + icon + '</td>' +
                        '<td style="padding:7px 10px;font-size:12px;">' + detail + '</td>' +
                        '</tr>';
                });

                var bodyHtml =
                    '<div style="background:#e9ecef;padding:10px 14px;border-radius:6px;margin-bottom:14px;">' +
                    'Período: <strong>' + htmlEsc(d.period_name) + '</strong> &nbsp;|&nbsp; ' +
                    d.total + ' clase(s) procesada(s)</div>' +
                    '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
                    '<thead><tr style="background:#343a40;color:#fff;">' +
                    '<th style="padding:8px 10px;">Clase</th>' +
                    '<th style="padding:8px 10px;text-align:center;">Estado</th>' +
                    '<th style="padding:8px 10px;">Detalle</th>' +
                    '</tr></thead><tbody>' + (rows || '<tr><td colspan="3" style="padding:12px;text-align:center;color:#999;">No se encontraron clases activas en este período.</td></tr>') +
                    '</tbody></table>';
                showSubModal('Resultado — Cierre de período', bodyHtml);
                // Reload after closing the sub-modal so the table reflects the new closed state.
                $('#gmk-sub-modal-overlay').on('click', '.gmk-sub-close', function () { location.reload(); });
            })
            .fail(function (xhr) {
                btnEl.prop('disabled', false).html('<i class="mdi mdi-lock-check"></i> Cerrar período');
                alert('Error: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.statusText));
            });
    }

    // ── Document-level event handlers ──────────────────────────────────────
    $(document).ready(function () {

        // Open schedules modal
        $(document).on('click', '.view-schedules-btn', function (e) {
            e.preventDefault();
            openSchedulesModal($(this).data('courseid'), $(this).data('coursename'));
        });

        // Open plans modal (unchanged)
        $(document).on('click', '.view-plans-btn', function (e) {
            e.preventDefault();
            var courseId   = $(this).data('courseid');
            var courseName = $(this).data('coursename');
            var plans = (window.gmkCourseData && window.gmkCourseData[courseId])
                ? window.gmkCourseData[courseId].plans : [];

            var bodyHtml = '<div class="modal-mimic-title">' + htmlEsc(courseName) + '</div>' +
                '<ul class="modules-item-list">';
            if (plans.length > 0) {
                plans.forEach(function (p) {
                    bodyHtml += '<li class="item-list">' +
                        '<div class="custom-avatar"><i class="mdi mdi-notebook-multiple"></i></div>' +
                        '<div class="list-item-info"><div class="list-item-info-text">' +
                        '<p>' + htmlEsc(p.name) + '</p></div></div></li>';
                });
            } else {
                bodyHtml += '<li class="item-list">Sin planes asociados.</li>';
            }
            bodyHtml += '</ul>';

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: 'Planes de Aprendizaje',
                body: bodyHtml
            }).then(function (m) { m.show(); });
        });

        // Close class
        $(document).on('click', '.close-class-btn', function (e) {
            e.preventDefault();
            closeClass(parseInt($(this).data('id')));
        });

        // Reopen class — show modal with 2 options
        $(document).on('click', '.reopen-schedule-btn', function (e) {
            e.preventDefault();
            var classId   = parseInt($(this).data('id'));
            var className = $(this).closest('li.item-list').find('p').first().text().replace(/CERRADO|ABIERTO/g, '').trim();

            var bodyHtml =
                '<p style="color:#555;margin-bottom:16px;">Selecciona cómo deseas reabrir la clase <strong>' + htmlEsc(className) + '</strong>:</p>' +
                '<div style="border:1px solid #dee2e6;border-radius:6px;padding:14px 16px;margin-bottom:10px;cursor:pointer;" id="gmk-reopen-full">' +
                '<label style="cursor:pointer;display:flex;gap:10px;align-items:flex-start;">' +
                '<input type="radio" name="gmk_reopen_mode" value="full" style="margin-top:3px;">' +
                '<div><strong>Reabrir para corrección completa</strong><br>' +
                '<small style="color:#666;">Desbloquea el libro de calificaciones <em>y</em> revierte el estado académico ' +
                'de todos los estudiantes a "Cursando". Usa esta opción si necesitas recalcular notas y volver a cerrar.</small></div>' +
                '</label></div>' +
                '<div style="border:1px solid #dee2e6;border-radius:6px;padding:14px 16px;cursor:pointer;" id="gmk-reopen-grades">' +
                '<label style="cursor:pointer;display:flex;gap:10px;align-items:flex-start;">' +
                '<input type="radio" name="gmk_reopen_mode" value="grades" style="margin-top:3px;">' +
                '<div><strong>Reabrir solo el libro de calificaciones</strong><br>' +
                '<small style="color:#666;">Desbloquea el gradebook de Moodle únicamente. Los estados de los estudiantes ' +
                '(Aprobado / Reprobado) <strong>NO se revierten</strong>. Usa esta opción para corregir un peso o una calificación puntual.</small></div>' +
                '</label></div>' +
                '<div style="margin-top:16px;text-align:right;">' +
                '<button class="btn btn-secondary btn-sm mr-2 gmk-reopen-cancel">Cancelar</button>' +
                '<button class="btn btn-primary btn-sm gmk-reopen-confirm">Confirmar reapertura</button></div>';

            showSubModal('Reabrir clase', bodyHtml);

            // Allow clicking the card to select the radio.
            $(document).on('click', '#gmk-reopen-full, #gmk-reopen-grades', function () {
                $(this).find('input[type=radio]').prop('checked', true);
            });
            $(document).on('click', '.gmk-reopen-cancel', function () {
                $('#gmk-sub-modal-overlay').remove();
            });
            $(document).on('click', '.gmk-reopen-confirm', function () {
                var mode = $('input[name=gmk_reopen_mode]:checked').val();
                if (!mode) { alert('Selecciona una opción.'); return; }
                var revertStates = mode === 'full';
                $('#gmk-sub-modal-overlay').remove();
                require(['core/ajax'], function (Ajax) {
                    Ajax.call([{
                        methodname: 'local_grupomakro_toggle_class_status',
                        args: { classId: classId, open: true, revert_states: revertStates }
                    }])[0].then(function () { location.reload(); })
                           .fail(function (ex) { alert('Error: ' + ex.message); });
                });
            });
        });

        // Revert approval
        $(document).on('click', '.revert-schedule-btn', function (e) {
            e.preventDefault();
            var classId = $(this).data('id');
            if (!confirm('¿Revertir aprobación? Se eliminará el grupo y los estudiantes volverán a pre-inscripción.')) { return; }
            require(['core/ajax'], function (Ajax) {
                Ajax.call([{
                    methodname: 'local_grupomakro_revert_approval',
                    args: { classId: classId }
                }])[0].then(function (r) {
                    if (r.status === 'ok') { location.reload(); }
                    else { alert(r.message); }
                }).fail(function (ex) { alert('Error: ' + ex.message); });
            });
        });

        // Open enroll modal
        $(document).on('click', '.enroll-student-btn', function (e) {
            e.preventDefault();
            openEnrollModal($(this).data('id'), $(this).data('name'));
        });

        // Student search
        $(document).on('click', '#gmk-enroll-search', function (e) {
            e.preventDefault();
            var q       = $('#gmk-enroll-input').val();
            var classId = $(this).data('classid');
            var results = $('#gmk-enroll-results');
            if (q.length < 3) { alert('Ingrese al menos 3 caracteres.'); return; }
            results.html('<div class="text-center"><i class="mdi mdi-loading mdi-spin"></i> Buscando…</div>');
            require(['core/ajax'], function (Ajax) {
                Ajax.call([{
                    methodname: 'local_grupomakro_search_users',
                    args: { query: q }
                }])[0].then(function (users) {
                    var html = '<ul class="list-group">';
                    if (users.length > 0) {
                        users.forEach(function (u) {
                            html += '<li class="list-group-item d-flex justify-content-between align-items-center">' +
                                '<div><strong>' + htmlEsc(u.fullname) + '</strong><br>' +
                                '<small>' + htmlEsc(u.email) + '</small></div>' +
                                '<button class="btn btn-sm btn-success perform-enroll-btn" ' +
                                'data-userid="' + u.id + '" data-classid="' + classId + '">Inscribir</button>' +
                                '</li>';
                        });
                    } else {
                        html += '<li class="list-group-item">Sin resultados.</li>';
                    }
                    results.html(html + '</ul>');
                }).fail(function (ex) { results.html('<span class="text-danger">Error: ' + ex.message + '</span>'); });
            });
        });

        // Perform enrollment
        $(document).on('click', '.perform-enroll-btn', function (e) {
            e.preventDefault();
            var btn     = $(this);
            var userId  = btn.data('userid');
            var classId = btn.data('classid');
            btn.prop('disabled', true).text('Inscribiendo…');
            require(['core/ajax'], function (Ajax) {
                Ajax.call([{
                    methodname: 'local_grupomakro_manual_enroll',
                    args: { classId: classId, userId: userId }
                }])[0].then(function (r) {
                    if (r.status === 'ok') {
                        btn.removeClass('btn-success').addClass('btn-secondary').text('Inscrito ✓');
                    } else {
                        btn.prop('disabled', false).text('Inscribir');
                        alert('Error: ' + r.message);
                    }
                }).fail(function (ex) {
                    btn.prop('disabled', false).text('Inscribir');
                    alert('Error: ' + ex.message);
                });
            });
        });

        // Period close button
        $(document).on('click', '#gmk-close-period-btn', function (e) {
            e.preventDefault();
            closePeriod($(this).data('periodid'), $(this).data('periodname'));
        });

        // Stat pill clicks → sub-modals
        $(document).on('click', '.gmk-pill-click', function (e) {
            e.stopPropagation();
            var action  = $(this).data('action');
            var classId = $(this).data('classid');
            var stats   = gmkStats[classId];
            if (!stats) { return; }

            // Find the class name from the DOM
            var className = $(this).closest('li.item-list').find('p').first().text().trim();

            if (action === 'students') {
                openStudentModal(stats.students.list, className);
            } else if (action === 'grades') {
                openGradeModal(stats.grades.items, stats.grades.total_weight, className);
            }
        });
    });
});
