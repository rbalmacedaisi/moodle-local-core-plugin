// Impresion y exportacion a Excel del tablero de horarios (solo lectura: no guarda ni cambia nada).
// Usa el estado del motor tal como se ve en pantalla (incluidos los cambios sin guardar).
// window.GmkBoardReport.print(ctx, modo) y window.GmkBoardReport.excel(ctx)
// ctx = { S, E, board, conflicts, periodName, dirty, draftInfo }
(function () {
    'use strict';
    var R = {};
    var JORDER = ['Diurna', 'Nocturna', 'Sabatina'];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    function ymdTxt(ymd) { var m = String(ymd || '').split('-'); return m.length === 3 ? m[2] + '/' + m[1] + '/' + m[0] : ''; }
    function ymdDate(ymd) { var m = String(ymd || '').split('-'); return m.length === 3 ? new Date(Number(m[0]), Number(m[1]) - 1, Number(m[2])) : null; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function epochTxt(ts) { if (!ts) return ''; var d = new Date(ts * 1000); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear(); }
    function epochDate(ts) { if (!ts) return null; var d = new Date(ts * 1000); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
    function jRank(j) { var i = JORDER.indexOf(j); return i < 0 ? 9 : i; }
    function stampTxt(d) { return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function nowTxt() { return stampTxt(new Date()); }
    function eventLabel(kind) {
        return { jueves: 'Seminario (jueves)', domingo: 'Seminario (domingo)', revalida: 'Seminario (sábado de reválida)',
            sabado_tarde: 'Seminario (sábado tarde)' }[kind] || (kind ? 'Seminario' : '');
    }

    /** Filas del horario: un registro por ficha (grupo o parte) y por clase ya creada del periodo. */
    R.rows = function (ctx) {
        var S = ctx.S, E = ctx.E, b = ctx.board, conf = ctx.conflicts || {};
        var careers = {};
        (b.careers || []).forEach(function (c) { careers[c.id] = c.name; });
        var tName = function (id) { return id ? ((S.teachers[id] || {}).name || 'Docente #' + id) : ''; };
        var room = function (id) { return id ? (S.rooms[id] || { name: 'Aula #' + id, capacity: 0 }) : null; };
        var rows = [];
        S.order.forEach(function (k) {
            var g = S.groups[k], p = S.place[k], placed = E.placed(p);
            var r = placed ? E.range(S, k, p) : { dates: [] };
            var rm = placed ? room(p.roomid) : null;
            var cs = conf[k] || [];
            var levels = Object.keys(g.levels || {}).map(function (l) { return l + ' (' + g.levels[l] + ')'; });
            rows.push({ key: k, fixed: false, jornada: g.jornada, name: g.name, courseid: g.courseid,
                group: 'G' + g.number + (g.of > 1 ? ' de ' + g.of : ''), part: g.parts > 1 ? g.part + '/' + g.parts : '',
                placed: placed, day: placed ? p.day : 0, dayName: placed ? E.DAYS[p.day] : '',
                start: placed ? p.start : 0, end: placed ? p.end : 0,
                startTxt: placed ? E.hhmm(p.start) : '', endTxt: placed ? E.hhmm(p.end) : '',
                minutes: placed ? p.end - p.start : (g.duration || 0), sessions: g.sessions || 0,
                dates: r.dates || [], first: (r.dates || [])[0] || '', last: (r.dates || []).slice(-1)[0] || '',
                weeks: placed ? E.weeksLabel(S, k, p) : '',
                teacherid: placed ? p.teacherid : 0, teacher: placed ? tName(p.teacherid) : '',
                roomid: placed ? p.roomid : 0, room: rm ? rm.name : '', roomCap: rm ? (rm.capacity || '') : '',
                size: g.size, adelantos: g.adelantos || 0, adelIds: g.adelanto_ids || [], userids: g.userids || [],
                careers: (g.plans || []).map(function (id) { return careers[id] || ('Plan ' + id); }).join(', '),
                levels: levels.join(', '), long: !!g.long, event: g.event || '', yields: !!g.yields,
                type: g.event ? eventLabel(g.event) : (g.long ? 'Cuatrimestral' : 'Bloque'),
                isolated: g.isolated_plan ? (careers[g.isolated_plan] || 'Plan ' + g.isolated_plan) : '',
                locked: !!p.locked, published: !!g.published,
                hard: cs.filter(function (c) { return c.sev === 'hard'; }).length,
                soft: cs.filter(function (c) { return c.sev !== 'hard' && c.sev !== 'info'; }).length,
                conflicts: cs });
        });
        // Clases ya creadas que siguen en este periodo (cuentan en el horario real).
        var pstart = (S.period.start || 0) + 7 * 86400;
        (S.fixed || []).forEach(function (f) {
            if (f.to && f.to < pstart) return;
            var rm = room(f.roomid);
            // "2026-V (D) INGLÉS I (PRESENCIAL) B" -> "INGLÉS I B"
            var short = String(f.name || '').replace(/^\s*\d{4}-[IVX]+\s*\([A-Z]\)\s*/, '').replace(/\s*\(PRESENCIAL\)/i, '');
            rows.push({ key: f.id, fixed: true, jornada: f.jornada || '', name: short, fullname: f.name, courseid: f.courseid, group: 'Clase #' + f.classid, part: '',
                placed: true, day: f.day, dayName: E.DAYS[f.day] || '', start: f.start, end: f.end, startTxt: E.hhmm(f.start), endTxt: E.hhmm(f.end),
                minutes: f.end - f.start, sessions: 0, dates: [], first: '', last: '', fromTs: f.from, toTs: f.to,
                weeks: (f.from ? epochTxt(f.from) : '') + (f.to ? ' – ' + epochTxt(f.to) : ''),
                teacherid: f.teacherid, teacher: tName(f.teacherid), roomid: f.roomid, room: rm ? rm.name : '', roomCap: rm ? (rm.capacity || '') : '',
                size: f.users ? f.users.size : 0, adelantos: 0, adelIds: [], userids: f.users ? Array.from(f.users) : [],
                careers: '', levels: '', long: false, event: '', yields: false,
                type: f.samePeriod ? 'Ya creada en este periodo' : 'Ya creada (viene del periodo anterior)', isolated: '',
                locked: true, published: true, hard: 0, soft: 0, conflicts: [] });
        });
        rows.sort(function (a, b) {
            return jRank(a.jornada) - jRank(b.jornada) || (a.placed ? 0 : 1) - (b.placed ? 0 : 1) || a.day - b.day
                || a.start - b.start || a.name.localeCompare(b.name) || String(a.group).localeCompare(String(b.group));
        });
        return rows;
    };

    function header(ctx, title) {
        var st = ctx.dirty ? 'Incluye cambios sin guardar' : (ctx.draftInfo && ctx.draftInfo.saved_at
            ? 'Borrador guardado el ' + stampTxt(new Date(ctx.draftInfo.saved_at * 1000)) + (ctx.draftInfo.byname ? ' por ' + ctx.draftInfo.byname : '')
            : 'Borrador sin guardar');
        var logo = window.gmkReportLogo ? '<img class="logo" src="' + esc(window.gmkReportLogo) + '" alt="">' : '';
        return '<div class="hd"><div class="hl">' + logo + '<div><b>' + esc(title) + '</b> · Periodo ' + esc(ctx.periodName) + '</div></div><div class="sm">' + esc(st)
            + ' · Impreso ' + esc(nowTxt()) + ' · Propuesta: no son clases creadas salvo las marcadas "ya creada"</div></div>';
    }

    /** Cuadricula semanal (dias x horas) con las fichas dadas. */
    function grid(ctx, list, opts) {
        var E = ctx.E;
        list = list.filter(function (r) { return r.placed; });
        if (!list.length) return '<div class="sm">Sin clases con horario.</div>';
        var days = {};
        list.forEach(function (r) { days[r.day] = true; });
        (opts.days || []).forEach(function (d) { days[d] = true; });
        days = Object.keys(days).map(Number).sort(function (a, b) { return a - b; });
        var a = Math.min.apply(null, list.map(function (r) { return r.start; }));
        var z = Math.max.apply(null, list.map(function (r) { return r.end; }));
        a = Math.floor(a / 60) * 60; z = Math.ceil(z / 60) * 60;
        // Escala tope para que pocas horas no se vean como fichas gigantes.
        var ppm = Math.min(1.1, (opts.height || 600) / Math.max(60, z - a)), H = Math.round((z - a) * ppm);
        var html = '<div class="grid" style="grid-template-columns:44px repeat(' + days.length + ',1fr)"><div></div>';
        days.forEach(function (d) { html += '<div class="dh">' + esc(E.DAYS[d]) + '</div>'; });
        html += '<div class="ax" style="height:' + H + 'px">';
        for (var h = a; h <= z; h += 60) html += '<div class="hr" style="top:' + ((h - a) * ppm) + 'px">' + E.hhmm(h) + '</div>';
        html += '</div>';
        days.forEach(function (d) {
            html += '<div class="col" style="height:' + H + 'px">';
            for (var h2 = a; h2 <= z; h2 += 60) html += '<div class="ln" style="top:' + ((h2 - a) * ppm) + 'px"></div>';
            // Asignaturas que se suceden por semanas en la misma franja (mismo inicio, fechas sin cruce) van en una sola ficha.
            var chains = [];
            list.filter(function (r) { return r.day === d; }).sort(function (x, y) { return x.start - y.start || String(x.first).localeCompare(String(y.first)); })
                .forEach(function (r) {
                    if (!r.fixed && r.first) {
                        var c = chains.find(function (c) { return !c.fixed && c.start === r.start && c.items[c.items.length - 1].last < r.first; });
                        if (c) { c.items.push(r); c.end = Math.max(c.end, r.end); return; }
                    }
                    chains.push({ start: r.start, end: r.end, fixed: r.fixed, items: [r] });
                });
            E.lanes(chains).forEach(function (c) {
                var w = 100 / (c.lanes || 1), r = c.items[0];
                var box = '" style="top:' + ((c.start - a) * ppm) + 'px;height:' + Math.max(14, (c.end - c.start) * ppm - 2) + 'px;left:' + (c.lane * w)
                    + '%;width:calc(' + w + '% - 2px)">';
                var who = function (x) {
                    return (opts.hideTeacher ? '' : esc(x.teacher || 'Sin docente')) + (opts.hideTeacher || opts.hideRoom ? '' : ' · ') + (opts.hideRoom ? '' : esc(x.room || 'sin aula'));
                };
                if (c.items.length > 1) {
                    html += '<div class="cd' + (c.items.some(function (x) { return x.hard; }) ? ' bad' : '') + box
                        + '<b>' + esc(E.hhmm(c.start) + '–' + E.hhmm(c.end)) + ' · por semanas</b>'
                        + c.items.map(function (x) {
                            return '<div class="ch"><b>' + esc((x.weeks.split(' · ')[1] || '')) + '</b> ' + esc(x.name) + ' ' + esc(x.group)
                                + (opts.showJornada ? ' · ' + esc(x.jornada) : '') + ' · ' + x.size + ' est · ' + who(x) + '</div>';
                        }).join('') + '</div>';
                    return;
                }
                var partial = r.weeks && r.weeks.indexOf('todo') !== 0 && !r.fixed;
                html += '<div class="cd' + (r.fixed ? ' fx' : '') + (r.hard ? ' bad' : '') + box
                    + '<b>' + esc(r.name) + '</b><br>' + esc(r.startTxt + '–' + r.endTxt) + ' · ' + esc(r.fixed ? 'ya creada' : r.group + (r.part ? ' p' + r.part : ''))
                    + (opts.showJornada ? ' · ' + esc(r.jornada) : '') + ' · ' + r.size + ' est'
                    + (partial ? '<br>' + esc(r.weeks.split(' · ').slice(0, 2).join(' · ')) : '')
                    + '<br>' + who(r) + '</div>';
            });
            html += '</div>';
        });
        return html + '</div>';
    }

    function table(list, cols) {
        var html = '<table><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>';
        list.forEach(function (r) { html += '<tr>' + cols.map(function (c) { return '<td>' + esc(c[1](r)) + '</td>'; }).join('') + '</tr>'; });
        return html + '</tbody></table>';
    }
    var DETAIL = [
        ['Asignatura', function (r) { return r.name; }], ['Grupo', function (r) { return r.group + (r.part ? ' (parte ' + r.part + ')' : ''); }],
        ['Jornada', function (r) { return r.jornada; }], ['Día', function (r) { return r.dayName || 'Sin horario'; }],
        ['Hora', function (r) { return r.placed ? r.startTxt + '–' + r.endTxt : ''; }],
        ['Fechas', function (r) { return r.fixed ? r.weeks : (r.first ? ymdTxt(r.first) + ' – ' + ymdTxt(r.last) + ' (' + r.dates.length + ' ses.)' : ''); }],
        ['Docente', function (r) { return r.teacher || (r.placed ? 'Sin docente' : ''); }], ['Aula', function (r) { return r.room; }],
        ['Est.', function (r) { return r.size; }]];

    function css() {
        return '*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#111;margin:0}'
            + '.pg{page-break-after:always;padding:4px}.pg:last-child{page-break-after:auto}'
            + '.hl{display:flex;align-items:center;gap:8px;white-space:nowrap;margin-right:12px}.hd>.sm{text-align:right}.logo{height:40px;width:auto}.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #333;margin-bottom:6px;padding-bottom:3px;font-size:12px}'
            + '.sm{font-size:9px;color:#555}h2{font-size:14px;margin:4px 0 6px}h3{font-size:12px;margin:10px 0 4px}'
            + '.grid{display:grid;gap:0;border:1px solid #999}.dh{text-align:center;font-weight:bold;background:#eee;border-left:1px solid #999;padding:3px}'
            + '.ax{position:relative;border-top:1px solid #999}.hr{position:absolute;right:3px;font-size:8px;color:#555;transform:translateY(-50%)}'
            + '.col{position:relative;border-left:1px solid #999;border-top:1px solid #999}.ln{position:absolute;left:0;right:0;border-top:1px dotted #ccc}'
            + '.cd{position:absolute;border:1px solid #4F46E5;background:#EEF2FF;border-radius:3px;padding:1px 3px;font-size:8px;line-height:1.2;overflow:hidden;'
            + '-webkit-print-color-adjust:exact;print-color-adjust:exact}.cd b{font-size:8.5px}.cd.fx{border-color:#64748B;background:#F1F5F9}'
            + '.cd.bad{border-color:#DC2626;background:#FEF2F2}.ch{border-top:1px dotted #818CF8;margin-top:1px}'
            + 'table{border-collapse:collapse;width:100%;margin-top:4px}th,td{border:1px solid #bbb;padding:2px 4px;text-align:left;vertical-align:top}'
            + 'th{background:#eee;-webkit-print-color-adjust:exact;print-color-adjust:exact}tr{page-break-inside:avoid}'
            + '.lst{page-break-inside:avoid;margin-bottom:10px}.lst table td:first-child{width:24px;text-align:right}'
            + '.bar{position:sticky;top:0;background:#fff;padding:6px;border-bottom:1px solid #ddd;margin-bottom:6px}'
            + '.bar button{font-size:13px;padding:5px 14px;margin-right:8px;cursor:pointer}'
            + '@media print{.bar{display:none}}@page{size:' + 'A4 landscape;margin:8mm}';
    }

    /** Imprime. modo: 'jornada' | 'docente' | 'aula' | 'listado' | 'listas'. */
    R.print = function (ctx, mode) {
        var rows = R.rows(ctx);
        var pages = [];
        var titles = { jornada: 'Horario por jornada', docente: 'Horario por docente', aula: 'Horario por aula', listado: 'Listado de horarios', listas: 'Listas de clase' };
        var title = titles[mode] || 'Horario';
        var by = function (fn) {
            var m = {};
            rows.forEach(function (r) { var k = fn(r); if (k === null) return; (m[k] = m[k] || []).push(r); });
            return m;
        };
        if (mode === 'jornada') {
            var bj = by(function (r) { return r.jornada; });
            Object.keys(bj).sort(function (a, b) { return jRank(a) - jRank(b); }).forEach(function (j) {
                var list = bj[j], un = list.filter(function (r) { return !r.placed; });
                pages.push('<h2>Jornada ' + esc(j) + ' · ' + list.filter(function (r) { return r.placed; }).length + ' clases con horario</h2>'
                    + grid(ctx, list, { height: 560 })
                    + '<h3 style="page-break-before:always">Detalle · Jornada ' + esc(j) + '</h3>' + table(list, DETAIL.filter(function (c) { return c[0] !== 'Jornada'; })));
            });
        } else if (mode === 'docente' || mode === 'aula') {
            var field = mode === 'docente' ? 'teacher' : 'room';
            var bt = by(function (r) { return r.placed ? (r[field] || (mode === 'docente' ? 'Sin docente' : 'Sin aula')) : null; });
            Object.keys(bt).sort(function (a, b) { return (/^Sin /.test(a) ? 1 : 0) - (/^Sin /.test(b) ? 1 : 0) || a.localeCompare(b); }).forEach(function (n) {
                var list = bt[n], mins = list.reduce(function (s, r) { return s + r.minutes; }, 0);
                pages.push('<h2>' + esc(n) + ' · ' + list.length + ' clase(s) · ' + (mins / 60).toFixed(1).replace('.0', '') + ' h por semana (máx.)'
                    + (mode === 'aula' && list[0].roomCap ? ' · capacidad ' + list[0].roomCap : '') + '</h2>'
                    + grid(ctx, list, { height: 430, showJornada: true, hideTeacher: mode === 'docente', hideRoom: mode === 'aula' })
                    + table(list, DETAIL));
            });
        } else if (mode === 'listado') {
            var bl = by(function (r) { return r.jornada; });
            Object.keys(bl).sort(function (a, b) { return jRank(a) - jRank(b); }).forEach(function (j) {
                pages.push('<h2>Jornada ' + esc(j) + '</h2>' + table(bl[j], DETAIL.filter(function (c) { return c[0] !== 'Jornada'; })
                    .concat([['Carreras', function (r) { return r.careers; }], ['Estado', function (r) {
                        return r.fixed ? 'ya creada' : (r.hard ? r.hard + ' choque(s)' : '') + (r.soft ? (r.hard ? ', ' : '') + r.soft + ' aviso(s)' : '') || 'ok';
                    }]])));
            });
        } else if (mode === 'listas') {
            var info = ctx.board.student_info || {}, names = ctx.board.students || {};
            var html = '';
            rows.filter(function (r) { return !r.fixed; }).forEach(function (r) {
                var adel = {};
                r.adelIds.forEach(function (u) { adel[u] = true; });
                var studs = r.userids.map(function (u) {
                    var i = info[u] || {};
                    return { name: names[u] || ('Usuario ' + u), doc: i.doc || '', plans: (i.plans || []).map(function (p) { return p.plan + ' · ' + p.level; }).join('; '),
                        adel: adel[u] ? 'adelanto' : '' };
                }).sort(function (a, b) { return a.name.localeCompare(b.name); });
                html += '<div class="lst"><h3>' + esc(r.name) + ' · ' + esc(r.group + (r.part ? ' (parte ' + r.part + ')' : '')) + ' · ' + esc(r.jornada) + '</h3>'
                    + '<div class="sm">' + esc(r.placed ? r.dayName + ' ' + r.startTxt + '–' + r.endTxt : 'Sin horario')
                    + (r.first ? ' · ' + ymdTxt(r.first) + ' – ' + ymdTxt(r.last) : '') + ' · Docente: ' + esc(r.teacher || 'sin asignar')
                    + ' · Aula: ' + esc(r.room || 'sin asignar') + ' · ' + r.size + ' estudiantes</div>'
                    + '<table><thead><tr><th>#</th><th>Estudiante</th><th>Documento</th><th>Carrera · nivel</th><th></th></tr></thead><tbody>'
                    + studs.map(function (s, i) {
                        return '<tr><td>' + (i + 1) + '</td><td>' + esc(s.name) + '</td><td>' + esc(s.doc) + '</td><td>' + esc(s.plans) + '</td><td>' + esc(s.adel) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            });
            pages.push(html);
        }
        var w = window.open('', '_blank');
        if (!w) { window.alert('El navegador bloqueó la ventana de impresión. Permita las ventanas emergentes para este sitio e intente de nuevo.'); return; }
        w.document.open();
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title + ' ' + ctx.periodName) + '</title><style>' + css() + '</style></head><body>'
            + '<div class="bar"><button onclick="window.print()">Imprimir</button><button onclick="window.close()">Cerrar</button>'
            + '<span class="sm">Consejo: en la ventana de impresión elija horizontal y "Gráficos de fondo" para ver los colores.</span></div>'
            + (pages.length ? pages : ['<div>No hay nada que imprimir.</div>']).map(function (p) { return '<div class="pg">' + header(ctx, title) + p + '</div>'; }).join('')
            + '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},200);});<\/script></body></html>');
        w.document.close();
        w.focus();
    };

    // En Moodle hay RequireJS: la libreria se registra como modulo AMD 'xlsx' y window.XLSX queda vacio.
    function loadXLSX() {
        var ready = function () { return window.XLSX && window.XLSX.utils ? window.XLSX : null; };
        var viaAmd = function (ok, fail) {
            if (typeof window.require === 'function' && typeof window.define === 'function' && window.define.amd) {
                window.require(['xlsx'], function (X) { if (X && X.utils) ok(X); else fail(new Error('La librería de Excel no cargó bien.')); },
                    function () { fail(new Error('No se pudo cargar la librería de Excel.')); });
            } else fail(new Error('La librería de Excel no cargó bien.'));
        };
        if (ready()) return Promise.resolve(ready());
        if (R._loading) return R._loading;
        R._loading = new Promise(function (ok, fail) {
            if (window.XLSX && !window.XLSX.utils) { viaAmd(ok, fail); return; }   // ya inyectada antes
            var sc = document.createElement('script');
            sc.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
            sc.onload = function () { if (ready()) ok(ready()); else viaAmd(ok, fail); };
            sc.onerror = function () { fail(new Error('No se pudo cargar la librería de Excel (¿sin internet?).')); };
            document.head.appendChild(sc);
        }).catch(function (e) { R._loading = null; throw e; });
        return R._loading;
    }
    function sheet(X, aoa, widths) {
        var ws = X.utils.aoa_to_sheet(aoa, { cellDates: true, dateNF: 'dd/mm/yyyy' });
        ws['!cols'] = widths.map(function (w) { return { wch: w }; });
        if (aoa.length > 1) ws['!autofilter'] = { ref: X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: aoa.length - 1, c: aoa[0].length - 1 } }) };
        // Fechas con formato dd/mm/aaaa.
        Object.keys(ws).forEach(function (a) { if (a[0] !== '!' && ws[a].t === 'd') ws[a].z = 'dd/mm/yyyy'; });
        return ws;
    }

    /** Exporta un .xlsx con varias hojas. Devuelve una promesa. */
    R.excel = function (ctx) {
        return loadXLSX().then(function (X) {
            var rows = R.rows(ctx), E = ctx.E;
            var info = ctx.board.student_info || {}, names = ctx.board.students || {};
            var wb = X.utils.book_new();
            var groups = rows.filter(function (r) { return !r.fixed; });
            // Resumen
            var res = [['Planificación de horarios — periodo ' + ctx.periodName], ['Generado', nowTxt()],
                ['Estado', ctx.dirty ? 'Incluye cambios sin guardar del tablero' : (ctx.draftInfo && ctx.draftInfo.saved_at
                    ? 'Borrador guardado el ' + stampTxt(new Date(ctx.draftInfo.saved_at * 1000)) + ' por ' + (ctx.draftInfo.byname || '') : 'Borrador sin guardar')],
                ['Nota', 'Es la propuesta del tablero: no son clases creadas, salvo las filas marcadas "Ya creada".'], [],
                ['Jornada', 'Grupos', 'Con horario', 'Sin horario', 'Con choques', 'Con avisos', 'Sin docente', 'Sin aula', 'Puestos (estudiantes)', 'Clases ya creadas']];
            var js = {};
            rows.forEach(function (r) { js[r.jornada || '(sin jornada)'] = true; });
            Object.keys(js).sort(function (a, b) { return jRank(a) - jRank(b); }).forEach(function (j) {
                var l = groups.filter(function (r) { return (r.jornada || '(sin jornada)') === j; });
                var pl = l.filter(function (r) { return r.placed; });
                res.push([j, l.length, pl.length, l.length - pl.length, l.filter(function (r) { return r.hard; }).length, l.filter(function (r) { return r.soft; }).length,
                    pl.filter(function (r) { return !r.teacherid; }).length, pl.filter(function (r) { return !r.roomid; }).length,
                    l.reduce(function (s, r) { return s + r.size; }, 0), rows.filter(function (r) { return r.fixed && (r.jornada || '(sin jornada)') === j; }).length]);
            });
            X.utils.book_append_sheet(wb, sheet(X, res, [28, 10, 12, 12, 12, 11, 12, 10, 20, 18]), 'Resumen');

            // Horario: una fila por ficha
            var H = [['Jornada', 'Asignatura', 'Id curso', 'Grupo', 'Parte', 'Tipo', 'Día', 'Inicio', 'Fin', 'Minutos por sesión', 'Sesiones',
                'Primera fecha', 'Última fecha', 'Semanas', 'Docente', 'Aula', 'Capacidad aula', 'Estudiantes', 'Adelantos', 'Carreras', 'Niveles',
                'Solo carrera', 'Cede fecha (natación)', 'Bloqueada', 'Publicada', 'Choques', 'Avisos', 'Detalle de choques y avisos']];
            rows.forEach(function (r) {
                H.push([r.jornada, r.fullname || r.name, r.courseid || '', r.group, r.part, r.type, r.dayName || 'Sin horario', r.startTxt, r.endTxt, r.placed ? r.minutes : '',
                    r.sessions || '', r.fixed ? epochDate(r.fromTs) : ymdDate(r.first), r.fixed ? epochDate(r.toTs) : ymdDate(r.last), r.fixed ? '' : r.weeks,
                    r.teacher, r.room, r.roomCap, r.size, r.adelantos || '', r.careers, r.levels, r.isolated, r.yields ? 'Sí' : '',
                    r.locked ? 'Sí' : '', r.published ? 'Sí' : '', r.hard || '', r.soft || '',
                    r.conflicts.filter(function (c) { return c.sev !== 'info'; }).map(function (c) { return (c.sev === 'hard' ? '✗ ' : '! ') + c.msg; }).join(' | ')]);
            });
            X.utils.book_append_sheet(wb, sheet(X, H, [10, 40, 8, 10, 6, 22, 10, 7, 7, 9, 8, 12, 12, 26, 28, 18, 9, 10, 9, 40, 30, 20, 10, 9, 9, 8, 8, 70]), 'Horario');

            // Sesiones: una fila por fecha de clase
            var SS = [['Fecha', 'Día', 'Inicio', 'Fin', 'Jornada', 'Asignatura', 'Grupo', 'Parte', 'Sesión', 'De', 'Tipo de sesión', 'Docente', 'Aula', 'Estudiantes']];
            var ses = [];
            groups.filter(function (r) { return r.placed; }).forEach(function (r) {
                var g = ctx.S.groups[r.key];
                var lastPart = !g.parts || g.parts <= 1 || g.part === g.parts;
                r.dates.forEach(function (d, i) {
                    var isLast = i === r.dates.length - 1;
                    ses.push({ d: d, r: r, n: i + 1, kind: r.event ? 'Seminario' : (!r.long && lastPart && isLast ? 'Evaluación' : 'Clase') });
                });
            });
            ses.sort(function (a, b) { return a.d.localeCompare(b.d) || a.r.start - b.r.start || a.r.name.localeCompare(b.r.name); });
            ses.forEach(function (x) {
                var dd = ymdDate(x.d);
                SS.push([dd, E.DAYS[dd.getDay() === 0 ? 7 : dd.getDay()], x.r.startTxt, x.r.endTxt, x.r.jornada, x.r.name, x.r.group, x.r.part, x.n, x.r.dates.length,
                    x.kind, x.r.teacher, x.r.room, x.r.size]);
            });
            X.utils.book_append_sheet(wb, sheet(X, SS, [12, 10, 7, 7, 10, 40, 10, 6, 7, 5, 12, 28, 18, 10]), 'Sesiones');

            // Estudiantes: una fila por estudiante y grupo
            var ST = [['Estudiante', 'Documento', 'Correo', 'Teléfono', 'Jornada del estudiante', 'Ingreso', 'Carrera(s) · nivel', 'Egresa',
                'Asignatura', 'Grupo', 'Parte', 'Jornada del grupo', 'Día', 'Inicio', 'Fin', 'Primera fecha', 'Última fecha', 'Docente', 'Aula', 'Adelanto']];
            groups.forEach(function (r) {
                var adel = {};
                r.adelIds.forEach(function (u) { adel[u] = true; });
                r.userids.forEach(function (u) {
                    var i = info[u] || {};
                    ST.push([names[u] || ('Usuario ' + u), i.doc || '', i.email || '', i.phone || '', i.jornada || '', i.ingreso || '',
                        (i.plans || []).map(function (p) { return p.plan + ' · ' + p.level; }).join('; '),
                        (i.plans || []).some(function (p) { return p.finishing; }) ? 'Sí' : '',
                        r.name, r.group, r.part, r.jornada, r.dayName || 'Sin horario', r.startTxt, r.endTxt, ymdDate(r.first), ymdDate(r.last),
                        r.teacher, r.room, adel[u] ? 'Sí' : '']);
                });
            });
            var head = ST.shift();
            ST.sort(function (a, b) { return String(a[0]).localeCompare(String(b[0])) || String(a[8]).localeCompare(String(b[8])); });
            ST.unshift(head);
            X.utils.book_append_sheet(wb, sheet(X, ST, [32, 14, 30, 13, 12, 10, 40, 7, 40, 10, 6, 12, 10, 7, 7, 12, 12, 28, 18, 9]), 'Estudiantes');

            // Docentes
            var DC = [['Docente', 'Clases', 'Minutos por semana (máx.)', 'Horas reloj en el periodo', 'Sesiones en el periodo', 'Jornadas', 'Días', 'Asignaturas', 'Con choques']];
            var bt = {};
            rows.filter(function (r) { return r.placed; }).forEach(function (r) { var n = r.teacher || 'Sin docente'; (bt[n] = bt[n] || []).push(r); });
            Object.keys(bt).sort(function (a, b) { return (a === 'Sin docente') - (b === 'Sin docente') || a.localeCompare(b); }).forEach(function (n) {
                var l = bt[n], uniq = function (f) { var m = {}; l.forEach(function (r) { m[f(r)] = true; }); return Object.keys(m).join(', '); };
                var nses = l.reduce(function (s, r) { return s + (r.dates.length || 0); }, 0);
                DC.push([n, l.length, l.reduce(function (s, r) { return s + r.minutes; }, 0),
                    Math.round(l.reduce(function (s, r) { return s + r.minutes * (r.dates.length || 0); }, 0) / 6) / 10, nses || '',
                    uniq(function (r) { return r.jornada; }), l.slice().sort(function (x, y) { return x.day - y.day; })
                        .map(function (r) { return r.dayName; }).filter(function (v, i, arr) { return arr.indexOf(v) === i; }).join(', '),
                    l.map(function (r) { return r.name + ' ' + r.group + ' (' + r.dayName + ' ' + r.startTxt + ')'; }).join('; '),
                    l.filter(function (r) { return r.hard; }).length || '']);
            });
            X.utils.book_append_sheet(wb, sheet(X, DC, [30, 8, 14, 14, 12, 22, 30, 90, 10]), 'Docentes');

            // Aulas
            var AU = [['Aula', 'Capacidad', 'Clases', 'Minutos por semana (máx.)', 'Mayor grupo', 'Grupos que no caben', 'Días', 'Detalle']];
            var br = {};
            rows.filter(function (r) { return r.placed; }).forEach(function (r) { var n = r.room || 'Sin aula'; (br[n] = br[n] || []).push(r); });
            Object.keys(br).sort(function (a, b) { return (a === 'Sin aula') - (b === 'Sin aula') || a.localeCompare(b); }).forEach(function (n) {
                var l = br[n], cap = Number(l[0].roomCap) || 0;
                var dset = {};
                l.forEach(function (r) { dset[r.dayName] = true; });
                AU.push([n, cap || '', l.length, l.reduce(function (s, r) { return s + r.minutes; }, 0), Math.max.apply(null, l.map(function (r) { return r.size; })),
                    cap ? l.filter(function (r) { return r.size > cap; }).length || '' : '', Object.keys(dset).join(', '),
                    l.map(function (r) { return r.dayName + ' ' + r.startTxt + '–' + r.endTxt + ' ' + r.name + ' ' + r.group; }).join('; ')]);
            });
            X.utils.book_append_sheet(wb, sheet(X, AU, [22, 10, 8, 14, 11, 14, 30, 100]), 'Aulas');

            // Choques y avisos
            var CH = [['Jornada', 'Asignatura', 'Grupo', 'Día', 'Hora', 'Gravedad', 'Tipo', 'Detalle']];
            groups.forEach(function (r) {
                r.conflicts.filter(function (c) { return c.sev !== 'info'; }).forEach(function (c) {
                    CH.push([r.jornada, r.fullname || r.name, r.group + (r.part ? ' (' + r.part + ')' : ''), r.dayName, r.placed ? r.startTxt + '–' + r.endTxt : '',
                        c.sev === 'hard' ? 'Choque' : 'Aviso', c.type || '', c.msg]);
                });
            });
            X.utils.book_append_sheet(wb, sheet(X, CH, [10, 40, 12, 10, 12, 9, 14, 100]), 'Choques y avisos');

            var xs = exclusionRows(ctx.board.exclusions || []);
            X.utils.book_append_sheet(wb, sheet(X, xs, EXCL_W), 'Excluidos por choque');

            X.writeFile(wb, 'horarios_' + String(ctx.periodName || 'periodo').replace(/[^\w-]+/g, '_') + '.xlsx');
            return { groups: groups.length, sessions: ses.length, students: ST.length - 1 };
        });
    };

    // Informe de estudiantes excluidos de grupos por choques (para cargarles la asignatura despues).
    var EXCL_W = [32, 14, 30, 40, 40, 10, 40, 24, 16, 10];
    function exclusionRows(list) {
        var out = [['Estudiante', 'Documento', 'Correo', 'Carrera(s) · nivel', 'Asignatura que no se le carga', 'Jornada', 'Por el choque con',
            'Registrado por', 'Fecha', 'Grupo publicado']];
        list.slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)) || String(a.course).localeCompare(String(b.course)); })
            .forEach(function (e) {
                out.push([e.name, e.documento || '', e.email || '', e.plans || '', e.course, e.jornada, e.with || '', e.byname || '',
                    e.time ? stampTxt(new Date(e.time * 1000)) : '', e.locked ? 'Sí' : '']);
            });
        return out;
    }
    R.exclusionsExcel = function (list, periodName) {
        return loadXLSX().then(function (X) {
            var wb = X.utils.book_new();
            X.utils.book_append_sheet(wb, sheet(X, exclusionRows(list || []), EXCL_W), 'Excluidos por choque');
            X.writeFile(wb, 'excluidos_por_choque_' + String(periodName || 'periodo').replace(/[^\w-]+/g, '_') + '.xlsx');
        });
    };

    window.GmkBoardReport = R;
})();
