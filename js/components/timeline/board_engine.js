// Motor del tablero de horarios de la linea de tiempo (sin dependencias: se prueba con node).
// Trabaja con lo que devuelve schedule_board::load (grupos, docentes, aulas, ocupacion fija, festivos, configuracion)
// y una tabla de ubicaciones { key: { day, start, end, teacherid, roomid, locked } } en minutos desde medianoche.
//
// Choques:
//  - duros (rojo): estudiantes con dos clases a la vez, docente o aula ocupados, fuera de la jornada.
//  - avisos (ambar): docente fuera de su disponibilidad o sin la habilidad, aula chica, hora de almuerzo.
//  - informativo: sesiones que se pierden por festivos.
// Dos clases chocan si comparten dia y se cruzan las horas (dejando el receso configurado entre una y otra) y sus
// fechas se cruzan mas de una semana (asi una clase que termina la primera semana del periodo no bloquea todo).
(function (root) {
    'use strict';
    var DAY = 86400;
    var E = {};
    E.DAYS = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };
    E.DAYS_SHORT = { 1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue', 5: 'Vie', 6: 'Sáb', 7: 'Dom' };

    E.toMin = function (t) {
        var m = /^(\d{1,2}):(\d{2})/.exec(String(t || ''));
        return m ? Number(m[1]) * 60 + Number(m[2]) : -1;
    };
    E.hhmm = function (m) {
        m = Math.round(m);
        return (m < 600 ? '0' : '') + Math.floor(m / 60) + ':' + (m % 60 < 10 ? '0' : '') + (m % 60);
    };

    /** Dias de la jornada: sabatina el sabado; diurna y nocturna de lunes a viernes. */
    E.jornadaDays = function (cfg, jornada) {
        var w = (cfg.shiftWindows || {})[jornada] || {};
        if (Array.isArray(w.days) && w.days.length) {
            return w.days.map(function (d) { return typeof d === 'number' ? d : E.dayNum(d); }).filter(Boolean);
        }
        return /sab/i.test(jornada) ? [6] : [1, 2, 3, 4, 5];
    };
    E.dayNum = function (s) {
        var n = String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        return { lunes: 1, martes: 2, miercoles: 3, jueves: 4, viernes: 5, sabado: 6, domingo: 7 }[n] || 0;
    };
    /** Franja horaria de la jornada en minutos. */
    E.window = function (cfg, jornada) {
        var w = (cfg.shiftWindows || {})[jornada] || {};
        var a = E.toMin(w.start), b = E.toMin(w.end);
        var ga = E.toMin(cfg.startTime), gb = E.toMin(cfg.endTime);
        if (a < 0) a = ga >= 0 ? ga : 7 * 60;
        if (b < 0) b = gb >= 0 ? gb : 22 * 60;
        return { start: a, end: b };
    };

    /**
     * Estado del tablero. board = respuesta de schedule_board::load; placements = ubicaciones (del borrador o vacias).
     */
    E.create = function (board, placements) {
        var s = {
            cfg: board.config || {}, period: board.period || {}, groups: {}, order: [], teachers: {}, rooms: {}, fixed: [],
            holidays: board.holidays || [], place: {},
        };
        s.pad = Math.max(0, Number(s.cfg.interval || 0));
        s.prefs = { subjects: ((board.preferences || {}).subjects || []) };
        s.names = board.students || {};
        (board.groups || []).forEach(function (g) {
            var from = Number((s.period.jornada_starts || {})[g.jornada] || s.period.start || 0);
            var cal = (board.calendars || {})[g.cal] || null;
            var parts = (g.parts && g.parts.length) ? g.parts : [{ key: g.key, sessions: g.sessions || 0 }];
            var users = new Set(g.userids);
            parts.forEach(function (pt, i) {
                s.groups[pt.key] = Object.assign({}, g, { key: pt.key, gkey: g.key, part: i + 1, parts: parts.length,
                    sessions: Number(pt.sessions || 0), users: users, calendar: cal, realDates: pt.dates || null,
                    from: cal ? Number(cal.from) : from, to: cal ? Number(cal.to) : from + (g.long ? 16 : 8) * 7 * DAY });
                s.order.push(pt.key);
            });
        });
        (board.teachers || []).forEach(function (t) {
            s.teachers[t.id] = Object.assign({}, t, { skillSet: new Set((t.skills || []).map(Number)) });
        });
        (board.rooms || []).forEach(function (r) { s.rooms[r.id] = r; });
        (board.fixed || []).forEach(function (f) {
            var users = new Set(f.userids || []);
            (f.sessions || []).forEach(function (x) {
                s.fixed.push({ id: 'c' + f.id, classid: f.id, courseid: Number(f.courseid || 0), name: f.name, day: x.day, start: x.start, end: x.end, teacherid: f.teacherid || 0,
                    roomid: x.roomid || 0, users: users, from: f.initdate || 0, to: f.enddate || 0, jornada: f.jornada, samePeriod: f.samePeriod });
            });
        });
        Object.keys(placements || {}).forEach(function (k) {
            if (s.groups[k]) s.place[k] = Object.assign({ day: 0, start: 0, end: 0, teacherid: 0, roomid: 0, locked: false, week: 0 }, placements[k]);
        });
        s.order.forEach(function (k) {
            if (!s.place[k]) s.place[k] = { day: 0, start: 0, end: 0, teacherid: 0, roomid: 0, locked: false, week: 0 };
            // Ya publicado: queda fijo (la clase existe; se cambia desde la gestion de clases).
            if (s.groups[k].published) s.place[k].locked = true;
        });
        return s;
    };

    // Entre fichas del tablero, las fechas exactas de sus sesiones; con clases ya creadas, una semana de tolerancia.
    function datesOverlap(a, b) {
        if (!a.from || !b.from || !a.to || !b.to) return true;
        if (a.isCard && b.isCard) {
            // Con las fechas exactas de ambas fichas: solo chocan si comparten una fecha (p. ej. un seminario en el sabado de
            // revalidas no choca con una cuatrimestral que ese sabado no tiene clase aunque su rango lo abarque).
            if (a.dates && b.dates && a.dates.length && b.dates.length) {
                var set = {};
                a.dates.forEach(function (d) { set[d] = true; });
                return b.dates.some(function (d) { return set[d]; });
            }
            return a.from < b.to && b.from < a.to;
        }
        return a.from < b.to - 7 * DAY && b.from < a.to - 7 * DAY;
    }
    function ymdEpoch(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
        return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) / 1000 + 5 * 3600 : 0;
    }
    /**
     * Fechas de una ficha en una ubicacion: las sesiones van seguidas desde la semana 'week' del calendario del dia.
     * @return { dates: [Y-m-d], from, to, missing, total }
     */
    E.range = function (s, k, p) {
        var g = s.groups[k];
        p = p || s.place[k];
        // Publicado: las fechas reales de la clase.
        if (g.realDates && g.realDates.length) {
            var rd = g.realDates;
            return { dates: rd, from: ymdEpoch(rd[0]), to: ymdEpoch(rd[rd.length - 1]) + DAY, missing: 0, total: rd.length, isCard: true };
        }
        var all = g.calendar && g.calendar.days ? (g.calendar.days[p.day] || g.calendar.days[String(p.day)] || []) : null;
        if (!all) return { dates: [], from: g.from, to: g.to, missing: 0, total: 0, isCard: false };
        var n = g.sessions || all.length;
        var w = Math.max(0, p.week || 0);
        var dates = all.slice(w, w + n);
        return { dates: dates, from: dates.length ? ymdEpoch(dates[0]) : g.from,
            to: dates.length ? ymdEpoch(dates[dates.length - 1]) + DAY : g.to, missing: n - dates.length, total: all.length, isCard: true };
    };
    /** Texto de semanas de una ficha ("sem 3–4, 30 nov–7 dic") cuando no ocupa todo el bloque. */
    E.weeksLabel = function (s, k, p) {
        var r = E.range(s, k, p);
        if (!r.dates.length) return '';
        p = p || s.place[k];
        var w = Math.max(0, p.week || 0);
        var total = r.total;
        var wk = '';
        var g = s.groups[k];
        if (g.realDates && g.realDates.length) {
            // Publicada: la semana sale de las fechas reales dentro del calendario del dia (no de 'week', que va en 0).
            var all = g.calendar && g.calendar.days ? (g.calendar.days[p.day] || g.calendar.days[String(p.day)] || []) : [];
            var i0 = all.indexOf(r.dates[0]);
            var i1 = all.indexOf(r.dates[r.dates.length - 1]);
            if (i0 < 0 || i1 < 0) {
                wk = 'fechas de la clase'; // fuera del calendario del bloque: solo las fechas
            } else {
                w = i0;
                total = all.length;
                wk = (i0 === 0 && r.dates.length >= total) ? 'todo el bloque' : 'sem ' + (i0 + 1) + (i1 > i0 ? '–' + (i1 + 1) : '');
            }
        }
        var f = function (ymd) { var m = ymd.split('-'); return Number(m[2]) + '/' + Number(m[1]); };
        if (!wk) {
            var full = w === 0 && r.dates.length >= total;
            wk = full ? 'todo el bloque' : 'sem ' + (w + 1) + (r.dates.length > 1 ? '–' + (w + r.dates.length) : '');
        }
        return wk + ' · ' + f(r.dates[0]) + (r.dates.length > 1 ? '–' + f(r.dates[r.dates.length - 1]) : '') + ' · ' + r.dates.length + ' ses.';
    };
    function timesOverlap(a, b, pad) {
        return a.start < b.end + pad && b.start < a.end + pad;
    }
    /** Nombres de los estudiantes en comun (hasta 3) para el texto del choque. */
    function sharedNames(s, setA, setB) {
        var out = [], small = setA.size < setB.size ? setA : setB, big = small === setA ? setB : setA;
        small.forEach(function (u) { if (big.has(u) && out.length < 4) out.push((s.names && s.names[u]) || ('usuario ' + u)); });
        return out.length > 3 ? out.slice(0, 3).join(', ') + '…' : out.join(', ');
    }
    function shared(setA, setB) {
        var n = 0, small = setA.size < setB.size ? setA : setB, big = small === setA ? setB : setA;
        small.forEach(function (u) { if (big.has(u)) n++; });
        return n;
    }
    E.placed = function (p) { return !!(p && p.day > 0 && p.end > p.start); };

    /** Otras ocupaciones del mismo dia (grupos ubicados + clases fijas), sin el grupo k. */
    function others(s, k, day) {
        var out = [];
        s.order.forEach(function (o) {
            if (o === k) return;
            var p = s.place[o];
            if (E.placed(p) && p.day === day) {
                var g = s.groups[o];
                var r = E.range(s, o, p);
                out.push({ id: o, name: g.name + ' G' + g.number + (g.parts > 1 ? ' (' + g.part + '/' + g.parts + ')' : ''), day: p.day,
                    start: p.start, end: p.end, teacherid: p.teacherid, roomid: p.roomid, users: g.users, from: r.from, to: r.to,
                    isCard: r.isCard, dates: r.dates, isGroup: true, gkey: g.gkey, courseid: Number(g.courseid) });
            }
        });
        s.fixed.forEach(function (f) { if (f.day === day) out.push(f); });
        return out;
    }

    /**
     * Choques de un grupo en una ubicacion (la suya o una que se esta probando).
     * @return [{ type, sev: 'hard'|'soft'|'info', msg, with }]
     */
    E.conflicts = function (s, k, p) {
        var g = s.groups[k];
        p = p || s.place[k];
        var out = [];
        if (!g || !E.placed(p)) return out;
        var range = E.range(s, k, p);
        var w = E.windowFor(s, k);
        var pf = E.prefFor(s, k);
        if (range.isCard && range.missing > 0) {
            out.push({ type: 'fechas', sev: 'hard', msg: 'Desde la semana ' + ((p.week || 0) + 1) + ' solo caben ' + range.dates.length + ' de sus '
                + g.sessions + ' sesiones en el bloque. Empiece antes o use otro día.' });
        }
        var ruleWin = pf && pf.override && pf.start >= 0;
        if (pf && pf.firstSlot) {
            var fs = pf.start >= 0 ? pf.start : w.start;
            if (p.start !== fs) {
                out.push({ type: pf.strict ? 'regla' : 'preferencia', sev: pf.strict ? 'hard' : 'soft',
                    msg: 'La regla de la asignatura pide empezar a primera hora (' + E.hhmm(fs) + ').' });
            }
        }
        if (E.daysFor(s, k).indexOf(p.day) === -1) {
            out.push({ type: 'jornada', sev: 'hard', msg: E.DAYS[p.day] + ' no es día ' + (ruleWin ? 'de la regla de la asignatura' : 'de la jornada ' + g.jornada) + '.' });
        } else if (p.start < w.start || p.end > w.end) {
            out.push({ type: 'jornada', sev: 'hard', msg: 'Fuera de la franja ' + (ruleWin ? 'de la asignatura' : 'de la jornada ' + g.jornada) + ' (' + E.hhmm(w.start) + '–' + E.hhmm(w.end) + ').' });
        }
        var ls = E.toMin(s.cfg.lunchStart), le = E.toMin(s.cfg.lunchEnd);
        if (ls >= 0 && le > ls && p.start < le && ls < p.end && !g.event) {   // los de dia completo ya incluyen el almuerzo
            out.push({ type: 'almuerzo', sev: 'soft', msg: 'Se cruza con la hora de almuerzo (' + s.cfg.lunchStart + '–' + s.cfg.lunchEnd + ').' });
        }
        var me = { start: p.start, end: p.end };
        var parallelWith = [];
        others(s, k, p.day).forEach(function (o) {
            if (!timesOverlap(me, o, s.pad) || !datesOverlap(range, o)) return;
            if (o.gkey && o.gkey === g.gkey && o.id !== k) {
                out.push({ type: 'estudiantes', sev: 'hard', n: g.size, with: o.id, msg: 'Se cruza con otra parte de la misma asignatura (' + o.name + ').' });
                return;
            }
            var n = shared(g.users, o.users);
            var when = E.hhmm(o.start) + '–' + E.hhmm(o.end);
            var og = s.groups[o.id];
            if (n && og && ((g.yields && og.event) || (g.event && og.yields))) {
                // Natacion cede: ese dia no se da (el publicador quita la fecha); no es un choque.
                out.push({ type: 'cede', sev: 'info', with: o.id, msg: 'Ese día ' + (g.yields ? 'no hay sesión: sus estudiantes tienen ' + o.name
                    : n + ' estudiante(s) no tendrán ' + o.name) + ' (' + when + ').' });
                return;
            }
            if (n) out.push({ type: 'estudiantes', sev: 'hard', n: n, with: o.id, msg: n + ' estudiante(s) también tienen ' + o.name + ' (' + when + '): '
                + sharedNames(s, g.users, o.users) + '.' });
            if (p.teacherid && o.teacherid === p.teacherid) {
                if (pf && pf.parallel && (pf.parallel.indexOf(Number(o.courseid)) !== -1 || Number(o.courseid) === Number(g.courseid))) {
                    parallelWith.push(o);
                } else {
                    out.push({ type: 'docente', sev: 'hard', with: o.id, msg: 'El docente ya dicta ' + o.name + ' (' + when + ').' });
                }
            }
            if (p.roomid && o.roomid === p.roomid) {
                out.push({ type: 'aula', sev: 'hard', with: o.id, msg: 'El aula está ocupada por ' + o.name + ' (' + when + ').' });
            }
        });
        if (parallelWith.length) {
            // Grupos del mismo docente a la vez: permitido entre asignaturas en paralelo, hasta el maximo.
            if (parallelWith.length + 1 > pf.parallelMax) {
                out.push({ type: 'docente', sev: 'hard', with: parallelWith[0].id, msg: 'El docente tendría ' + (parallelWith.length + 1)
                    + ' grupos a la vez y la regla permite ' + pf.parallelMax + ' (' + parallelWith.map(function (o) { return o.name; }).join(', ') + ').' });
            } else {
                out.push({ type: 'paralelo', sev: 'info', msg: 'En paralelo con el mismo docente: ' + parallelWith.map(function (o) { return o.name; }).join(', ') + '.' });
            }
        }
        if (pf && pf.teacherid && p.teacherid !== pf.teacherid) {
            var tn = s.teachers[pf.teacherid] ? s.teachers[pf.teacherid].name : 'otro docente';
            out.push({ type: 'docente_fijo', sev: 'soft', msg: p.teacherid ? 'La regla de la asignatura indica a ' + tn + '.'
                : 'Sin docente: la regla indica a ' + tn + ', pero a esta hora ya tiene el máximo de grupos en paralelo u otra clase.' });
        }
        if (p.teacherid) {
            var t = s.teachers[p.teacherid];
            if (t) {
                if (!t.skillSet.has(Number(g.courseid))) {
                    out.push({ type: 'habilidad', sev: 'soft', msg: t.name + ' no tiene registrada la habilidad de esta asignatura.' });
                }
                if (!E.available(t, p)) {
                    out.push({ type: 'disponibilidad', sev: 'soft', msg: t.name + ' no tiene disponibilidad ' + E.DAYS[p.day] + ' ' + E.hhmm(p.start) + '–' + E.hhmm(p.end) + '.' });
                }
            }
        }
        if (pf && !ruleWin) {
            var sev = pf.strict ? 'hard' : 'soft', kind = pf.strict ? 'obligatorios' : 'preferidos';
            if (pf.days.length && pf.days.indexOf(p.day) === -1) {
                out.push({ type: pf.strict ? 'regla' : 'preferencia', sev: sev, msg: 'Fuera de los días ' + kind + ' ' + (pf.source === 'asignatura' ? 'de la asignatura' : 'de la jornada ' + g.jornada)
                    + ' (' + pf.days.map(function (d) { return E.DAYS_SHORT[d]; }).join(', ') + ').' });
            }
            if (pf.start >= 0 && pf.end > pf.start && (p.start < pf.start || p.end > pf.end)) {
                out.push({ type: pf.strict ? 'regla' : 'preferencia', sev: sev, msg: 'Fuera de la franja ' + (pf.strict ? 'obligatoria' : 'preferida') + ' de la asignatura (' + E.hhmm(pf.start) + '–' + E.hhmm(pf.end) + ').' });
            }
        } else if (pf && ruleWin && !pf.strict && pf.days.length && pf.days.indexOf(p.day) === -1) {
            out.push({ type: 'preferencia', sev: 'soft', msg: 'Fuera de los días preferidos de la asignatura.' });
        }
        if (p.roomid && s.rooms[p.roomid] && s.rooms[p.roomid].capacity < g.size) {
            out.push({ type: 'capacidad', sev: 'soft', msg: 'El aula tiene capacidad para ' + s.rooms[p.roomid].capacity + ' y el grupo tiene ' + g.size + '.' });
        }
        return out;
    };

    /**
     * Preferencia de dia y hora de una ficha: la de la asignatura (de su carrera si hay, si no la general) o, si no hay,
     * los dias preferidos de su jornada. Es preferencia: si no se cumple, queda un aviso.
     * @return null | { days: [1..6], start, end (minutos o -1), source: 'asignatura'|'jornada' }
     */
    E.prefFor = function (s, k) {
        var g = s.groups[k];
        var spec = function (x) { return (x.jornada ? 2 : 0) + (Number(x.planid) ? 1 : 0); };
        var cands = (s.prefs.subjects || []).filter(function (x) {
            return Number(x.courseid) === Number(g.courseid) && (!Number(x.planid) || (g.plans || []).indexOf(Number(x.planid)) !== -1)
                && (!x.jornada || x.jornada === g.jornada);
        }).sort(function (a, b) { return spec(b) - spec(a); });
        if (cands.length) {
            var c = cands[0];
            return { days: (c.days || []).map(Number), start: c.start ? E.toMin(c.start) : -1, end: c.end ? E.toMin(c.end) : -1, source: 'asignatura', raw: c,
                strict: !!c.strict, override: !!c.override_window, firstSlot: !!c.first_slot, teacherid: Number(c.teacherid || 0),
                parallel: (c.parallel || []).map(Number), parallelMax: Number(c.parallel_max || 4) };
        }
        var w = (s.cfg.shiftWindows || {})[g.jornada] || {};
        if (Array.isArray(w.preferredDays) && w.preferredDays.length) {
            return { days: w.preferredDays.map(Number), start: -1, end: -1, source: 'jornada' };
        }
        return null;
    };

    /** Dias posibles de una ficha: los de la regla si su franja vale para todas las jornadas y es obligatoria; si no, los de su jornada. */
    E.daysFor = function (s, k) {
        var pf = E.prefFor(s, k);
        if (pf && pf.override && pf.start >= 0 && pf.strict && pf.days.length) return pf.days.slice();
        if (pf && pf.override && pf.start >= 0) {
            var base = E.jornadaDays(s.cfg, s.groups[k].jornada);
            return base.concat(pf.days.filter(function (d) { return base.indexOf(d) === -1; })).sort();
        }
        return E.jornadaDays(s.cfg, s.groups[k].jornada);
    };
    /** Franja posible de una ficha: la de la asignatura si vale para todas las jornadas; si no, la de su jornada. */
    E.windowFor = function (s, k) {
        var pf = E.prefFor(s, k);
        if (pf && pf.override && pf.start >= 0 && pf.end > pf.start) return { start: pf.start, end: pf.end };
        return E.window(s.cfg, s.groups[k].jornada);
    };

    /** Tramo de semanas de una ficha en su dia: { w0, w1, total } (indices en el calendario de ese dia). */
    E.weekSpan = function (s, k, p) {
        p = p || s.place[k];
        var r = E.range(s, k, p);
        var w0 = Math.max(0, p.week || 0);
        return { w0: w0, w1: w0 + Math.max(1, r.dates.length) - 1, total: Math.max(r.total, 1), partial: r.isCard && r.dates.length < r.total };
    };

    /**
     * Acomodo secuencial: cada ficha corta que esta sola en su franja se intenta encadenar en la franja (dia y hora) de
     * otra ficha corta de los mismos estudiantes o del mismo docente, en las semanas libres, sin choques y sin sumar
     * avisos. Asi dos asignaturas de 2 y 5 sesiones ocupan una sola franja en las 7 semanas. Devuelve { moved }.
     */
    E.compact = function (s) {
        var moved = 0;
        var mates = function (k, p) {
            return s.order.filter(function (o) {
                var q = s.place[o];
                return o !== k && E.placed(q) && q.day === p.day && q.start === p.start && s.groups[o].jornada === s.groups[k].jornada;
            });
        };
        for (var round = 0; round < 4; round++) {
            var changed = false;
            var shorts = s.order.filter(function (k) {
                var p = s.place[k];
                return E.placed(p) && !p.locked && !s.groups[k].published && E.weekSpan(s, k, p).partial;
            }).sort(function (a, b) { return (s.groups[a].sessions || 0) - (s.groups[b].sessions || 0); });
            shorts.forEach(function (k) {
                var p = s.place[k], g = s.groups[k];
                if (mates(k, p).length) return;
                var cur = E.conflicts(s, k, p);
                if (cur.some(function (c) { return c.sev === 'hard'; })) return;
                var curSoft = cur.filter(function (c) { return c.sev === 'soft'; }).length;
                var best = null;
                s.order.forEach(function (o) {
                    var op = s.place[o], og = s.groups[o];
                    if (o === k || og.jornada !== g.jornada || !E.placed(op) || !E.weekSpan(s, o, op).partial) return;
                    var link = shared(g.users, og.users) + (p.teacherid && p.teacherid === op.teacherid ? 5 : 0);
                    if (!link) return;
                    for (var wk = 0; wk <= E.maxWeek(s, k, op.day); wk++) {
                        var test = { day: op.day, start: op.start, end: op.start + (p.end - p.start), week: wk,
                            teacherid: p.teacherid, roomid: op.roomid || p.roomid, locked: false };
                        var cs = E.conflicts(s, k, test);
                        if (cs.some(function (c) { return c.sev === 'hard'; })) continue;
                        var so = cs.filter(function (c) { return c.sev === 'soft'; }).length;
                        if (so > curSoft) continue;
                        var sc = so * 5 - link + wk * 0.01;
                        if (!best || sc < best.sc) best = { sc: sc, test: test };
                    }
                });
                if (best) {
                    s.place[k] = Object.assign({}, p, best.test);
                    moved++;
                    changed = true;
                }
            });
            if (!changed) break;
        }
        return { moved: moved };
    };

    /** Semanas del bloque para una ficha (el dia de su jornada con menos fechas). */
    function blockWeeks(s, k) {
        var g = s.groups[k];
        var days = E.jornadaDays(s.cfg, g.jornada);
        if (!g.calendar || !g.calendar.days) return 0;
        return Math.min.apply(null, days.map(function (d) { return (g.calendar.days[d] || g.calendar.days[String(d)] || []).length; }));
    }

    /**
     * Cadenas de asignaturas cortas: por jornada, las fichas que usan menos semanas que el bloque se empaquetan en
     * secuencias que llenan las semanas (de mayor a menor, prefiriendo las de los mismos estudiantes) y cada secuencia se
     * ubica como un solo bloque en una franja libre. Devuelve { chains: [[keys]], placed: [keys] }.
     */
    E.chainPlace = function (s) {
        var shorts = s.order.filter(function (k) {
            var g = s.groups[k], p = s.place[k];
            var W = blockWeeks(s, k);
            return !p.locked && !E.placed(p) && g.sessions > 0 && W > 0 && g.sessions < W;
        });
        var byJ = {};
        shorts.forEach(function (k) { (byJ[s.groups[k].jornada] = byJ[s.groups[k].jornada] || []).push(k); });
        var chains = [], placed = [];
        Object.keys(byJ).forEach(function (j) {
            var list = byJ[j].sort(function (a, b) { return s.groups[b].sessions - s.groups[a].sessions || a.localeCompare(b); });
            var W = blockWeeks(s, list[0]);
            var bins = [];
            list.forEach(function (k) {
                var g = s.groups[k], best = null;
                bins.forEach(function (bin) {
                    if (bin.used + g.sessions > W) return;
                    if (bin.keys.some(function (o) { return s.groups[o].gkey === g.gkey; })) return; // partes de la misma: dias distintos
                    var aff = bin.keys.reduce(function (a, o) { return a + shared(g.users, s.groups[o].users); }, 0);
                    if (!best || aff > best.aff || (aff === best.aff && bin.used > best.bin.used)) best = { bin: bin, aff: aff };
                });
                if (best) { best.bin.keys.push(k); best.bin.used += g.sessions; }
                else bins.push({ keys: [k], used: g.sessions });
            });
            bins.filter(function (bin) { return bin.keys.length > 1; }).sort(function (a, b) { return b.used - a.used; }).forEach(function (bin) {
                var w = E.window(s.cfg, j), bestSlot = null;
                var maxdur = Math.max.apply(null, bin.keys.map(function (k) { return s.groups[k].duration; }));
                E.jornadaDays(s.cfg, j).forEach(function (day) {
                    starts(s, bin.keys[0], day, maxdur).forEach(function (st) {
                        var off = 0, soft = 0, ok = true, tests = [];
                        bin.keys.forEach(function (k) {
                            if (!ok) return;
                            var g = s.groups[k];
                            var p = { day: day, start: st, end: st + g.duration, week: off, teacherid: 0, roomid: 0, locked: false };
                            var cs = E.conflicts(s, k, p);
                            if (cs.some(function (c) { return c.sev === 'hard'; })) { ok = false; return; }
                            soft += cs.reduce(function (a, c) { return a + (c.sev !== 'soft' ? 0 : c.type === 'preferencia' ? 25 : 20); }, 0);
                            tests.push([k, p]);
                            off += g.sessions;
                        });
                        if (!ok) return;
                        var sc = soft + (st - w.start) * 0.001;
                        if (!bestSlot || sc < bestSlot.sc) bestSlot = { sc: sc, tests: tests };
                    });
                });
                if (!bestSlot) return;
                // Se ubica la cadena; docente y aula por ficha (cada asignatura puede tener su docente).
                bestSlot.tests.forEach(function (t) { s.place[t[0]] = t[1]; });
                bestSlot.tests.forEach(function (t) {
                    var k = t[0], p = s.place[k];
                    var tt = E.bestTeacher(s, k, p, true);
                    p.teacherid = tt ? tt.id : 0;
                    var r = E.bestRoom(s, k, p);
                    p.roomid = r ? r.id : 0;
                    placed.push(k);
                });
                chains.push(bin.keys.slice());
            });
        });
        return { chains: chains, placed: placed };
    };

    /** Disponibilidad: el dia debe tener una franja que cubra toda la sesion (un dia sin franjas NO esta disponible). */
    E.available = function (t, p) {
        var ranges = (t.avail || {})[p.day] || (t.avail || {})[String(p.day)] || [];
        for (var i = 0; i < ranges.length; i++) {
            if (p.start >= ranges[i][0] && p.end <= ranges[i][1]) return true;
        }
        return false;
    };

    E.holidaysLost = function (s, g, day) {
        return (s.holidays || []).filter(function (h) {
            return h.day === day && h.date >= g.from && h.date < g.to;
        }).map(function (h) {
            var d = new Date(h.date * 1000);
            return d.getDate() + '/' + (d.getMonth() + 1) + ' ' + h.name;
        });
    };

    E.allConflicts = function (s) {
        var out = {};
        s.order.forEach(function (k) { out[k] = E.conflicts(s, k); });
        return out;
    };
    E.severity = function (list) {
        if (!list || !list.length) return 'ok';
        if (list.some(function (c) { return c.sev === 'hard'; })) return 'hard';
        if (list.some(function (c) { return c.sev === 'soft'; })) return 'soft';
        return 'ok';
    };

    /** Minutos semanales de un docente en el tablero (grupos ubicados + clases fijas del periodo). */
    E.teacherLoad = function (s, tid) {
        var m = 0;
        s.order.forEach(function (k) {
            var p = s.place[k];
            if (E.placed(p) && p.teacherid === tid) {
                var g = s.groups[k], r = E.range(s, k, p);
                m += (p.end - p.start) * (r.isCard && r.total ? Math.min(1, r.dates.length / r.total) : 1);
            }
        });
        s.fixed.forEach(function (f) { if (f.teacherid === tid && f.samePeriod) m += f.end - f.start; });
        return m;
    };

    /** Docentes para un grupo en una ubicacion, ordenados: habilidad, disponibilidad, libre, menos carga. */
    E.teacherOptions = function (s, k, p) {
        var g = s.groups[k];
        p = p || s.place[k];
        return Object.keys(s.teachers).map(function (id) {
            var t = s.teachers[id];
            var test = Object.assign({}, p, { teacherid: t.id });
            var skill = t.skillSet.has(Number(g.courseid));
            var avail = E.placed(p) ? E.available(t, p) : null;
            var busy = E.placed(p) ? E.conflicts(s, k, test).some(function (c) { return c.type === 'docente'; }) : false;
            var load = E.teacherLoad(s, t.id);
            var score = (skill ? 0 : 100) + (avail === false ? 40 : 0) + (busy ? 1000 : 0) + load / 60;
            return { id: t.id, name: t.name, skill: skill, avail: avail, busy: busy, load: load, score: score, hasAvail: t.has_avail };
        }).sort(function (a, b) { return a.score - b.score || a.name.localeCompare(b.name); });
    };

    /** Aulas para un grupo en una ubicacion: libres primero, luego la mas chica que alcanza. */
    E.roomOptions = function (s, k, p) {
        var g = s.groups[k];
        p = p || s.place[k];
        return Object.keys(s.rooms).map(function (id) {
            var r = s.rooms[id];
            var test = Object.assign({}, p, { roomid: r.id });
            var busy = E.placed(p) ? E.conflicts(s, k, test).some(function (c) { return c.type === 'aula'; }) : false;
            var fits = r.capacity >= g.size;
            return { id: r.id, name: r.name, capacity: r.capacity, busy: busy, fits: fits,
                score: (busy ? 1000 : 0) + (fits ? 0 : 100) + Math.abs(r.capacity - g.size) / 100 };
        }).sort(function (a, b) { return a.score - b.score || a.name.localeCompare(b.name); });
    };

    /** Horas de inicio posibles en un dia: cada 30 min desde el inicio de la franja y justo despues de lo que ya hay. */
    function starts(s, k, day, dur) {
        var g = s.groups[k];
        var w = E.windowFor(s, k);
        var fpf = E.prefFor(s, k);
        if (fpf && fpf.firstSlot && fpf.strict) return [fpf.start >= 0 ? fpf.start : w.start];
        dur = dur || g.duration;
        var set = {};
        for (var t = w.start; t + dur <= w.end; t += 30) set[t] = true;
        others(s, k, day).forEach(function (o) {
            var t2 = o.end + s.pad;
            if (t2 >= w.start && t2 + dur <= w.end) set[t2] = true;
            var t3 = o.start - s.pad - dur;
            if (t3 >= w.start && t3 + dur <= w.end) set[t3] = true;
        });
        if (w.end - dur >= w.start) set[w.end - dur] = true;
        return Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
    }

    /** Evalua todas las ubicaciones posibles de un grupo (con su docente y aula actuales si los tiene). */
    E.candidates = function (s, k, opts) {
        opts = opts || {};
        var g = s.groups[k];
        var cur = s.place[k];
        var out = [];
        var dur = E.placed(cur) ? cur.end - cur.start : g.duration;
        E.daysFor(s, k).forEach(function (day) {
            var maxw = E.maxWeek(s, k, day);
            starts(s, k, day, dur).forEach(function (st) {
                for (var wk = 0; wk <= maxw; wk++) {
                    var p = { day: day, start: st, end: st + dur, week: wk, teacherid: opts.ignoreTeacher ? 0 : cur.teacherid,
                        roomid: opts.ignoreRoom ? 0 : cur.roomid };
                    var cs = E.conflicts(s, k, p);
                    var hard = cs.filter(function (c) { return c.sev === 'hard'; });
                    var soft = cs.filter(function (c) { return c.sev === 'soft'; });
                    var studentsHit = hard.filter(function (c) { return c.type === 'estudiantes'; }).reduce(function (a, c) { return a + c.n; }, 0);
                    var pref = soft.filter(function (c) { return c.type === 'preferencia'; }).length;
                    out.push({ day: day, start: st, end: st + dur, week: wk, conflicts: cs, hard: hard.length, soft: soft.length, pref: pref, studentsHit: studentsHit });
                }
            });
        });
        return out;
    };

    /** Ultima semana en que puede empezar una ficha en ese dia (0 si ocupa todo el bloque). */
    E.maxWeek = function (s, k, day) {
        var g = s.groups[k];
        var all = g.calendar && g.calendar.days ? (g.calendar.days[day] || g.calendar.days[String(day)] || []) : [];
        return Math.max(0, all.length - (g.sessions || all.length));
    };

    /** Mejor semana de inicio para una ficha en un dia y hora (menos choques duros, luego avisos, luego antes). */
    E.bestWeek = function (s, k, p) {
        var best = null;
        for (var wk = 0; wk <= E.maxWeek(s, k, p.day); wk++) {
            var cs = E.conflicts(s, k, Object.assign({}, p, { week: wk }));
            var h = cs.filter(function (c) { return c.sev === 'hard'; }).length, so = cs.filter(function (c) { return c.sev === 'soft'; }).length;
            var sc = h * 100 + so * 10 + wk * 0.1 - seqBonus(s, k, Object.assign({}, p, { week: wk }));
            if (!best || sc < best.sc) best = { week: wk, sc: sc, conflicts: cs };
        }
        return best;
    };

    // ---- Compactacion del horario de los estudiantes (menos dias y sin huecos) ----
    // Un dia mas de clase "vale" DAY_COST minutos de hueco: entre venir otro dia o esperar hora y media, se prefiere esperar.
    var DAY_COST = 90;
    /** Minutos de hueco de una lista de intervalos [ini, fin] de un dia (lo que pasa entre clases, sin el receso ni el almuerzo). */
    function gapOf(s, iv) {
        if (iv.length < 2) return 0;
        iv = iv.slice().sort(function (a, b) { return a[0] - b[0]; });
        var tol = Math.max(15, s.pad + 10), ls = E.toMin(s.cfg.lunchStart), le = E.toMin(s.cfg.lunchEnd);
        var gap = 0, end = iv[0][1];
        for (var i = 1; i < iv.length; i++) {
            if (iv[i][0] > end + tol) {
                var g = iv[i][0] - end;
                if (ls >= 0 && le > ls) g -= Math.max(0, Math.min(iv[i][0], le) - Math.max(end, ls));
                gap += Math.max(0, g);
            }
            end = Math.max(end, iv[i][1]);
        }
        return gap;
    }
    /** Clases de los estudiantes del grupo k en el dia: { 'Y-m-d': { uid: [[ini, fin]] } } en las fechas dadas (sin k). */
    function dayIndex(s, k, day, dates) {
        var g = s.groups[k], idx = {};
        dates.forEach(function (d) { idx[d] = {}; });
        others(s, k, day).forEach(function (o) {
            var us = [];
            o.users.forEach(function (u) { if (g.users.has(u)) us.push(u); });
            if (!us.length) return;
            var set = null;
            if (o.dates && o.dates.length) { set = {}; o.dates.forEach(function (d) { set[d] = true; }); }
            dates.forEach(function (d) {
                if (set ? !set[d] : (o.from && o.to && (ymdEpoch(d) < o.from || ymdEpoch(d) >= o.to))) return;
                us.forEach(function (u) { (idx[d][u] = idx[d][u] || []).push([o.start, o.end, o.gkey === g.gkey]); });
            });
        });
        return idx;
    }
    /**
     * Lo que cuesta a los estudiantes del grupo k tenerlo en la ubicacion p (frente a no tenerlo): cuantos tendrian que
     * venir un dia mas y cuantos minutos de hueco se suman, en todas sus fechas. Las partes de la misma asignatura el
     * mismo dia cuentan como dia extra (deben ir en dias distintos).
     * @return { newDays, gap, sibling, n (estudiantes x fechas), cost (minutos equivalentes) }
     */
    E.compactCost = function (s, k, p) {
        var g = s.groups[k];
        var r = E.range(s, k, p);
        var dates = r.dates && r.dates.length ? r.dates : [];
        var out = { newDays: 0, gap: 0, sibling: 0, n: 0, cost: 0 };
        if (!dates.length || !g.users.size) return out;
        var idx = dayIndex(s, k, p.day, dates);
        dates.forEach(function (d) {
            g.users.forEach(function (u) {
                out.n++;
                var iv = idx[d][u];
                if (!iv || !iv.length) { out.newDays++; return; }
                if (iv.some(function (x) { return x[2]; })) out.sibling++;
                out.gap += gapOf(s, iv.concat([[p.start, p.end]])) - gapOf(s, iv);
            });
        });
        out.cost = out.newDays * DAY_COST + out.gap + out.sibling * DAY_COST * 2;
        return out;
    };
    /** Costo de compactacion por estudiante y fecha (para comparar ubicaciones de un mismo grupo junto a los avisos). */
    function compactScore(s, k, p) {
        var c = E.compactCost(s, k, p);
        return c.n ? c.cost / c.n : 0;
    }

    /** Costo del horario de unos estudiantes: DAY_COST por cada dia con clase + minutos de hueco (en todas las fechas). */
    E.studentCost = function (s, uids) {
        var want = {}, per = {};
        uids.forEach(function (u) { want[u] = true; });
        s.order.forEach(function (k) {
            var p = s.place[k];
            if (!E.placed(p)) return;
            var g = s.groups[k], us = [];
            g.users.forEach(function (u) { if (want[u]) us.push(u); });
            if (!us.length) return;
            E.range(s, k, p).dates.forEach(function (d) {
                us.forEach(function (u) { var x = (per[u] = per[u] || {}); (x[d] = x[d] || []).push([p.start, p.end]); });
            });
        });
        var cost = 0;
        Object.keys(per).forEach(function (u) {
            Object.keys(per[u]).forEach(function (d) { cost += DAY_COST + gapOf(s, per[u][d]); });
        });
        return cost;
    };
    /** Otra parte de la misma asignatura ya va ese dia en esas fechas (las partes van en dias distintos). */
    function siblingSameDay(s, k, p) {
        var g = s.groups[k];
        if (!g || g.parts < 2) return false;
        var r = E.range(s, k, p);
        return others(s, k, p.day).some(function (o) { return o.gkey === g.gkey && datesOverlap(r, o); });
    }

    /**
     * Compactar: mueve fichas sin candado (no publicadas, no eventos) a otro dia, hora o semana donde sus estudiantes
     * vengan menos dias y tengan menos huecos. Si la mejor hora la ocupan 1 o 2 grupos movibles con estudiantes en comun,
     * prueba moverlos a ellos tambien. Cada ficha movida queda SIN choques duros, sin sumar avisos, con su docente (o
     * otro con la habilidad, disponible y libre si el suyo no puede y la regla no lo fija) y con aula libre que alcance.
     * Solo acepta un cambio si baja el costo total de los estudiantes afectados (dias + huecos).
     * Devuelve { moved, gain (minutos equivalentes), keys }.
     */
    E.densify = function (s, opts) {
        opts = opts || {};
        var moved = 0, gain = 0, movedKeys = {};
        var softOf = function (cs) { return cs.filter(function (c) { return c.sev === 'soft'; }).length; };
        var hardOf = function (cs) { return cs.filter(function (c) { return c.sev === 'hard'; }); };
        var movable = function (k) {
            var p = s.place[k], g = s.groups[k];
            return !!(g && E.placed(p) && !p.locked && !g.published && !g.event);
        };
        var fixedTeacher = function (k) { var r = E.prefFor(s, k); return r && r.teacherid ? r.teacherid : 0; };
        // Deja la ficha k en np con docente valido y aula; false si no se puede sin choques duros o con mas avisos.
        var settle = function (k, np, maxSoft, hadRoom) {
            var test = Object.assign({}, np, { roomid: 0 });
            var cs = E.conflicts(s, k, test);
            var hard = hardOf(cs);
            if (hard.length && hard.every(function (x) { return x.type === 'docente'; }) && !fixedTeacher(k)) {
                var t = E.bestTeacher(s, k, Object.assign({}, test, { teacherid: 0 }), true);
                if (!t) return false;
                test.teacherid = t.id;
                cs = E.conflicts(s, k, test);
                hard = hardOf(cs);
            }
            if (hard.length || softOf(cs) > maxSoft) return false;
            var keep = np.roomid && !E.conflicts(s, k, Object.assign({}, test, { roomid: np.roomid })).some(function (x) { return x.type === 'aula'; });
            test.roomid = keep ? np.roomid : 0;
            if (!keep) {
                var r = E.bestRoom(s, k, test);
                if (!r && hadRoom) return false;
                test.roomid = r ? r.id : 0;
            }
            cs = E.conflicts(s, k, test);
            if (hardOf(cs).length || softOf(cs) > maxSoft) return false;
            s.place[k] = Object.assign({}, s.place[k], test, { locked: false });
            return true;
        };
        // Mejor hora (sin choques duros) para una ficha que se desplaza, por su costo de compactacion.
        var relocate = function (o, maxSoft, hadRoom) {
            var op = s.place[o];
            var list = E.candidates(s, o, { ignoreRoom: true }).filter(function (c) {
                return !c.hard && c.soft <= maxSoft && !siblingSameDay(s, o, c);
            }).map(function (c) {
                var np = { day: c.day, start: c.start, end: c.end, week: c.week, teacherid: op.teacherid, roomid: op.roomid };
                return { np: np, cost: E.compactCost(s, o, np).cost };
            }).sort(function (a, b) { return a.cost - b.cost; });
            for (var i = 0; i < list.length && i < 6; i++) {
                if (settle(o, list[i].np, maxSoft, hadRoom)) return true;
            }
            return false;
        };
        for (var round = 0; round < (opts.rounds || 4); round++) {
            var changed = false;
            var keys = s.order.filter(function (k) {
                return movable(k) && s.groups[k].users.size && (!opts.jornada || s.groups[k].jornada === opts.jornada);
            }).sort(function (a, b) { return s.groups[b].size - s.groups[a].size || a.localeCompare(b); });
            keys.forEach(function (k) {
                var p = s.place[k], g = s.groups[k];
                var cur = E.conflicts(s, k, p);
                if (hardOf(cur).length) return;
                var curSoft = softOf(cur);
                var curMarg = E.compactCost(s, k, p).cost;
                var cands = E.candidates(s, k, { ignoreRoom: true }).filter(function (c) {
                    return !(c.day === p.day && c.start === p.start && (c.week || 0) === (p.week || 0)) && c.soft <= curSoft && !siblingSameDay(s, k, c);
                }).map(function (c) {
                    var np = { day: c.day, start: c.start, end: c.end, week: c.week, teacherid: p.teacherid, roomid: p.roomid };
                    return { c: c, np: np, marg: E.compactCost(s, k, np).cost };
                }).filter(function (x) { return x.marg < curMarg - 1; }).sort(function (a, b) { return a.marg - b.marg; });
                var best = null;
                for (var i = 0; i < cands.length && i < 12; i++) {
                    var x = cands[i], hard = hardOf(x.c.conflicts);
                    var blockers = {};
                    var ok = hard.every(function (h) {
                        if (h.type === 'docente' && !fixedTeacher(k)) return true;
                        if (h.type !== 'estudiantes' || !h.with || !movable(h.with)) return false;
                        blockers[h.with] = true;
                        return true;
                    });
                    var bl = Object.keys(blockers);
                    if (!ok || bl.length > 2) continue;
                    var users = [];
                    g.users.forEach(function (u) { users.push(u); });
                    bl.forEach(function (o) { s.groups[o].users.forEach(function (u) { users.push(u); }); });
                    var saved = {};
                    [k].concat(bl).forEach(function (o) { saved[o] = Object.assign({}, s.place[o]); });
                    var before = E.studentCost(s, users);
                    var done = true;
                    // Se sacan los que estorban, se ubica k y se reubica a cada uno donde menos cueste.
                    var bsoft = {};
                    bl.forEach(function (o) { bsoft[o] = softOf(E.conflicts(s, o)); s.place[o] = Object.assign({}, s.place[o], { day: 0, start: 0, end: 0 }); });
                    if (!settle(k, x.np, curSoft, !!p.roomid)) done = false;
                    for (var j = 0; done && j < bl.length; j++) {
                        if (!relocate(bl[j], bsoft[bl[j]], !!saved[bl[j]].roomid)) done = false;
                    }
                    if (done) {
                        done = [k].concat(bl).every(function (o) { return !hardOf(E.conflicts(s, o)).length; });
                    }
                    var after = done ? E.studentCost(s, users) : Infinity;
                    if (done && after < before - 1 && (!best || before - after > best.gain)) {
                        best = { gain: before - after, place: {} };
                        [k].concat(bl).forEach(function (o) { best.place[o] = Object.assign({}, s.place[o]); });
                    }
                    Object.keys(saved).forEach(function (o) { s.place[o] = saved[o]; });
                    if (best && !bl.length) break;   // movida simple que mejora: la mejor por costo marginal
                }
                if (!best) return;
                Object.keys(best.place).forEach(function (o) { s.place[o] = best.place[o]; movedKeys[o] = true; moved++; });
                gain += best.gain;
                changed = true;
            });
            if (!changed) break;
        }
        return { moved: moved, gain: Math.round(gain), keys: Object.keys(movedKeys) };
    };

    /**
     * Dispersion del horario de los estudiantes: por estudiante y semana, dias con clase, horas, dias de mas (frente a
     * los minimos que necesitaria si su horario cupiera seguido en la franja de su jornada) y minutos de hueco entre
     * clases (sin receso ni almuerzo). Cuenta las fichas ubicadas y las clases fijas (de otros periodos) que se cruzan.
     * @return { students: [{ uid, jornada, days, hours, extra, gap }], byJornada: { j: { n, days, extra, gap,
     *          extraHist: [0,1,2,3+], gapHist: [0, <1h, 1-2h, >2h], compact (% sin dias de mas ni huecos) } } }
     */
    E.dispersion = function (s) {
        var per = {}, jor = {}, from = Infinity, to = 0;
        var add = function (u, d, a, b, j) {
            ((per[u] = per[u] || {})[d] = per[u][d] || []).push([a, b]);
            var x = (jor[u] = jor[u] || {}); x[j] = (x[j] || 0) + (b - a);
        };
        s.order.forEach(function (k) {
            var p = s.place[k];
            if (!E.placed(p)) return;
            var g = s.groups[k], r = E.range(s, k, p);
            if (!r.dates.length) return;
            from = Math.min(from, r.from); to = Math.max(to, r.to);
            r.dates.forEach(function (d) { g.users.forEach(function (u) { add(u, d, p.start, p.end, g.jornada); }); });
        });
        if (to) {
            s.fixed.forEach(function (f) {
                var a = Math.max(f.from || from, from), b = Math.min(f.to || to, to);
                for (var t = a; t < b; t += DAY) {
                    var dt = new Date((t - 5 * 3600) * 1000), dn = dt.getUTCDay() || 7;
                    if (dn !== f.day) continue;
                    var ymd = dt.toISOString().slice(0, 10);
                    f.users.forEach(function (u) { add(u, ymd, f.start, f.end, f.jornada); });
                }
            });
        }
        var monday = function (ymd) {
            var d = new Date(ymd + 'T12:00:00Z');
            d.setUTCDate(d.getUTCDate() - (d.getUTCDay() + 6) % 7);
            return d.toISOString().slice(0, 10);
        };
        var students = [], byJ = {};
        Object.keys(per).forEach(function (u) {
            var j = Object.keys(jor[u]).sort(function (a, b) { return jor[u][b] - jor[u][a]; })[0];
            var w = E.window(s.cfg, j), cap = Math.max(60, w.end - w.start);
            var weeks = {};
            Object.keys(per[u]).forEach(function (d) {
                var iv = per[u][d].slice().sort(function (a, b) { return a[0] - b[0]; }), busy = 0, end = -1;
                iv.forEach(function (x) { busy += Math.max(0, x[1] - Math.max(x[0], end)); end = Math.max(end, x[1]); });
                var W = (weeks[monday(d)] = weeks[monday(d)] || { days: 0, mins: 0, gap: 0 });
                W.days++; W.mins += busy; W.gap += gapOf(s, iv);
            });
            var ws = Object.keys(weeks).map(function (x) { return weeks[x]; }), n = ws.length;
            var avg = function (f) { return ws.reduce(function (a, x) { return a + f(x); }, 0) / n; };
            var row = { uid: Number(u), jornada: j, weeks: n, days: avg(function (x) { return x.days; }), hours: avg(function (x) { return x.mins; }) / 60,
                extra: avg(function (x) { return Math.max(0, x.days - Math.max(1, Math.ceil(x.mins / cap))); }), gap: avg(function (x) { return x.gap; }) };
            students.push(row);
            var J = (byJ[j] = byJ[j] || { n: 0, days: 0, extra: 0, gap: 0, extraHist: [0, 0, 0, 0], gapHist: [0, 0, 0, 0], compact: 0 });
            J.n++; J.days += row.days; J.extra += row.extra; J.gap += row.gap;
            J.extraHist[Math.min(3, Math.round(row.extra))]++;
            J.gapHist[row.gap < 10 ? 0 : row.gap < 60 ? 1 : row.gap <= 120 ? 2 : 3]++;
            if (Math.round(row.extra) === 0 && row.gap < 10) J.compact++;
        });
        Object.keys(byJ).forEach(function (j) {
            var J = byJ[j];
            J.days = J.days / J.n; J.extra = J.extra / J.n; J.gap = J.gap / J.n; J.compact = Math.round(J.compact * 100 / J.n);
        });
        return { students: students, byJornada: byJ };
    };
    /**
     * Bonificacion por encadenar: en la misma franja (dia y hora) justo antes o despues hay otra ficha de los mismos
     * estudiantes o del mismo docente (p. ej. una asignatura de 2 sesiones seguida de otra de 5 en 7 semanas).
     */
    function seqBonus(s, k, p) {
        var g = s.groups[k];
        var r = E.range(s, k, p);
        if (!r.isCard || !r.dates.length || r.dates.length >= r.total) return 0;
        var b = 0;
        others(s, k, p.day).forEach(function (o) {
            if (!o.isCard || Math.abs(o.start - p.start) > 15) return;
            var touch = Math.abs(o.from - r.to) <= 8 * DAY || Math.abs(r.from - o.to) <= 8 * DAY;
            if (touch && (shared(g.users, o.users) || (p.teacherid && p.teacherid === o.teacherid))) b += 3;
        });
        return b;
    }

    /** Mejor docente libre, con habilidad y disponibilidad (o null). */
    E.bestTeacher = function (s, k, p, strict) {
        var rpf = E.prefFor(s, k);
        if (rpf && rpf.teacherid && s.teachers[rpf.teacherid]) {
            var fixed = E.teacherOptions(s, k, p).find(function (o) { return o.id === rpf.teacherid; });
            if (fixed && !fixed.busy) return fixed;
        }
        var opts = E.teacherOptions(s, k, p);
        var best = opts.find(function (o) { return o.skill && o.avail && !o.busy; });
        if (best || strict) return best || null;
        return opts.find(function (o) { return o.skill && !o.busy; }) || null;
    };
    E.bestRoom = function (s, k, p) {
        var r = E.roomOptions(s, k, p).find(function (o) { return !o.busy && o.fits; });
        return r || null;
    };

    /**
     * Ubica automaticamente los grupos sin horario (y no bloqueados). Para cada uno elige la ubicacion sin choques
     * duros que tenga un docente con habilidad y disponibilidad libre, menos avisos y reparta mejor la semana;
     * le asigna ese docente y un aula libre. Devuelve { placed: [...], failed: [{key, reason}] }.
     */
    E.autoPlace = function (s, opts) {
        opts = opts || {};
        var todo = s.order.filter(function (k) {
            var p = s.place[k];
            return !p.locked && (!E.placed(p) || opts.all);
        });
        if (opts.all) todo.forEach(function (k) { s.place[k] = { day: 0, start: 0, end: 0, teacherid: 0, roomid: 0, locked: false, week: 0 }; });
        // Primero las cadenas de asignaturas cortas (ocupan una franja en todas las semanas, como una asignatura completa).
        var chained = opts.chains === false ? { chains: [], placed: [] } : E.chainPlace(s);
        todo = todo.filter(function (k) { return chained.placed.indexOf(k) === -1; });
        // Primero los mas dificiles: los que menos ubicaciones libres tienen, los que ocupan mas semanas y los mas grandes.
        var freeCount = {};
        todo.forEach(function (k) {
            freeCount[k] = E.candidates(s, k, { ignoreTeacher: true, ignoreRoom: true }).filter(function (c) { return !c.hard; }).length;
        });
        todo.sort(function (a, b) {
            return freeCount[a] - freeCount[b] || (s.groups[b].sessions || 0) - (s.groups[a].sessions || 0)
                || s.groups[b].size - s.groups[a].size || a.localeCompare(b);
        });
        var placed = [], failed = [];
        todo.forEach(function (k) {
            var g = s.groups[k];
            // El docente es de la clase: si otra parte ya lo tiene, se intenta el mismo.
            var rpf = E.prefFor(s, k);
            var keepTeacher = s.place[k].teacherid || (rpf && rpf.teacherid) || siblingTeacher(s, k);
            s.place[k].teacherid = keepTeacher;
            var cands = E.candidates(s, k, { ignoreRoom: true, ignoreTeacher: !keepTeacher }).filter(function (c) { return !c.hard; });
            if (!cands.length && keepTeacher && !s.place[k].locked) {
                keepTeacher = 0;
                s.place[k].teacherid = 0;
                cands = E.candidates(s, k, { ignoreRoom: true, ignoreTeacher: true }).filter(function (c) { return !c.hard; });
            }
            if (!cands.length && E.repair(s, k)) {
                placed.push(k);
                return;
            }
            if (!cands.length) {
                failed.push({ key: k, reason: E.whyNoSlot(s, k) });
                return;
            }
            var best = null;
            cands.forEach(function (c, i) {
                var p = { day: c.day, start: c.start, end: c.end, week: c.week, teacherid: keepTeacher, roomid: 0 };
                var t = keepTeacher ? null : E.bestTeacher(s, k, p, true);
                var room = E.bestRoom(s, k, p);
                var score = (c.soft - (c.pref || 0)) * 20 + (c.pref || 0) * 25 - pairBonus(s, k, c) + (keepTeacher || t ? 0 : 15) + (room ? 0 : 10)
                    + c.week * 0.05 - seqBonus(s, k, Object.assign({}, p, { teacherid: keepTeacher || (t ? t.id : 0) })) + i * 0.001;
                if (!best || score < best.score) best = { c: c, t: t, room: room, score: score };
            });
            var np = { day: best.c.day, start: best.c.start, end: best.c.end, week: best.c.week, locked: false,
                teacherid: keepTeacher || (best.t ? best.t.id : 0), roomid: best.room ? best.room.id : 0 };
            s.place[k] = np;
            placed.push(k);
        });
        var compacted = opts.compact === false ? { moved: 0 } : E.compact(s);
        var dense = opts.densify === false ? { moved: 0, gain: 0 } : E.densify(s);
        return { placed: chained.placed.concat(placed), failed: failed, chained: compacted.moved + chained.placed.length,
            chains: chained.chains, densified: dense.moved };
    };

    /**
     * Paralelo con el mismo docente: se prefiere el dia y la hora donde ya corre la OTRA asignatura del paralelo (p. ej.
     * si los martes sigue piscina del periodo anterior, el grupo nuevo de scuba entra el martes) y se evita juntar
     * grupos de la misma asignatura.
     */
    function pairBonus(s, k, c) {
        var pf = E.prefFor(s, k);
        if (!pf || !pf.parallel || !pf.parallel.length) return 0;
        var b = 0, g = s.groups[k];
        var rg = E.range(s, k, Object.assign({}, s.place[k], c));
        others(s, k, c.day).forEach(function (o) {
            if (!timesOverlap(c, o, 0) || !datesOverlap(rg, o)) return;
            var tid = pf.teacherid || s.place[k].teacherid;
            if (tid && o.teacherid !== tid) return;
            if (pf.parallel.indexOf(Number(o.courseid)) !== -1) b += 30;
            else if (Number(o.courseid) === Number(g.courseid)) b -= 10;
        });
        return b;
    }

    function siblingTeacher(s, k) {
        var g = s.groups[k];
        if (!g || g.parts < 2) return 0;
        for (var i = 0; i < s.order.length; i++) {
            var o = s.order[i];
            if (o !== k && s.groups[o].gkey === g.gkey && s.place[o].teacherid) return s.place[o].teacherid;
        }
        return 0;
    }
    E.siblings = function (s, k) {
        var g = s.groups[k];
        return s.order.filter(function (o) { return s.groups[o].gkey === g.gkey; });
    };

    /**
     * Reparacion: si un grupo no tiene hora libre, prueba ubicarlo donde solo lo bloquean grupos movibles (no fijos
     * ni bloqueados) y mover cada uno de esos a otra hora sin choques duros. Si no se logra, deja todo como estaba.
     */
    E.repair = function (s, k) {
        var g = s.groups[k];
        var cands = E.candidates(s, k, { ignoreTeacher: true, ignoreRoom: true }).map(function (c) {
            var withs = {};
            var movable = c.conflicts.filter(function (x) { return x.sev === 'hard'; }).every(function (x) {
                if (!x.with || !s.groups[x.with] || s.place[x.with].locked) return false;
                withs[x.with] = true;
                return true;
            });
            return { c: c, movable: movable, withs: Object.keys(withs) };
        }).filter(function (x) { return x.movable && x.withs.length && x.withs.length <= 2; })
            .sort(function (a, b) { return a.withs.length - b.withs.length || a.c.soft - b.c.soft; });
        for (var i = 0; i < cands.length && i < 40; i++) {
            var saved = JSON.stringify(s.place);
            var c = cands[i].c;
            s.place[k] = { day: c.day, start: c.start, end: c.end, week: c.week, teacherid: 0, roomid: 0, locked: false };
            var ok = cands[i].withs.every(function (o) {
                var op = s.place[o];
                var keepT = op.teacherid;
                s.place[o] = { day: 0, start: 0, end: 0, teacherid: keepT, roomid: 0, locked: false, week: 0 };
                var alt = E.candidates(s, o, { ignoreRoom: true, ignoreTeacher: !keepT }).filter(function (x) { return !x.hard; })
                    .sort(function (a, b) { return a.soft - b.soft; })[0];
                if (!alt) return false;
                var np = { day: alt.day, start: alt.start, end: alt.end, week: alt.week, teacherid: keepT, roomid: 0, locked: false };
                var r = E.bestRoom(s, o, np);
                np.roomid = r ? r.id : 0;
                // Si su docente no puede a la nueva hora, se busca otro.
                if (keepT && (!s.teachers[keepT] || !E.available(s.teachers[keepT], np))) keepT = 0;
                np.teacherid = keepT;
                if (!keepT) {
                    var t = E.bestTeacher(s, o, np, true);
                    np.teacherid = t ? t.id : 0;
                }
                s.place[o] = np;
                return true;
            });
            if (ok && !E.conflicts(s, k).some(function (x) { return x.sev === 'hard'; })) {
                var p = s.place[k];
                var t2 = E.bestTeacher(s, k, p, true);
                p.teacherid = t2 ? t2.id : 0;
                var r2 = E.bestRoom(s, k, p);
                p.roomid = r2 ? r2.id : 0;
                return true;
            }
            var back = JSON.parse(saved);
            Object.keys(back).forEach(function (kk) { s.place[kk] = back[kk]; });
        }
        return false;
    };

    /** Explicacion de por que un grupo no tiene hora libre. */
    E.whyNoSlot = function (s, k) {
        var g = s.groups[k];
        var days = E.jornadaDays(s.cfg, g.jornada);
        var w = E.window(s.cfg, g.jornada);
        // Estudiante del grupo con mas horas ocupadas en la jornada.
        var worst = 0;
        g.userids.forEach(function (u) {
            var mins = 0;
            days.forEach(function (d) {
                others(s, k, d).forEach(function (o) {
                    if (o.users.has(u) && datesOverlap({ from: g.from, to: g.to }, o)) mins += o.end - o.start;
                });
            });
            worst = Math.max(worst, mins);
        });
        var cap = days.length * (w.end - w.start);
        return 'Cada hora posible de la jornada ' + g.jornada + ' (' + days.map(function (d) { return E.DAYS_SHORT[d]; }).join(', ')
            + ' ' + E.hhmm(w.start) + '–' + E.hhmm(w.end) + ') choca con otra clase de al menos uno de sus estudiantes (el más cargado ya tiene '
            + Math.round(worst / 60 * 10) / 10 + ' h de ' + Math.round(cap / 60 * 10) / 10 + ' h). Puede moverlo a mano (se verá con quién choca), '
            + 'mover otro grupo, partirlo o ampliar la franja de la jornada en Configuración.';
    };

    /** Asigna docente (y aula si falta) a los grupos ubicados que no tienen. Devuelve { assigned, missing: [keys] }. */
    E.autoAssign = function (s) {
        var assigned = 0, rooms = 0, missing = [];
        // Primero los grupos con menos docentes posibles.
        var keys = s.order.filter(function (k) { return E.placed(s.place[k]) && !s.place[k].locked; });
        var nopts = {};
        keys.forEach(function (k) {
            nopts[k] = E.teacherOptions(s, k).filter(function (o) { return o.skill && o.avail && !o.busy; }).length;
        });
        keys.sort(function (a, b) { return nopts[a] - nopts[b]; });
        keys.forEach(function (k) {
            var p = s.place[k];
            if (!p.teacherid) {
                var t = E.bestTeacher(s, k, p, true);
                if (t) { p.teacherid = t.id; assigned++; } else missing.push(k);
            }
            if (!p.roomid) {
                var r = E.bestRoom(s, k, p);
                if (r) { p.roomid = r.id; rooms++; }
            }
        });
        return { assigned: assigned, rooms: rooms, missing: missing };
    };

    /** Sugerencias para un grupo: mejores ubicaciones sin choques duros y docentes que encajan. */
    E.suggestions = function (s, k, max) {
        max = max || 5;
        var g = s.groups[k];
        var p = s.place[k];
        var out = [];
        var cands = E.candidates(s, k, { ignoreRoom: false }).filter(function (c) { return !c.hard; })
            .map(function (c) { return Object.assign(c, { load: compactScore(s, k, c) }); })
            .sort(function (a, b) { return a.soft - b.soft || a.load - b.load || a.week - b.week || a.day - b.day || a.start - b.start; });
        var curConf = E.conflicts(s, k);
        var curHard = curConf.filter(function (c) { return c.sev === 'hard'; }).length;
        var curSoft = curConf.filter(function (c) { return c.sev === 'soft'; }).length;
        // Mover solo si no tiene horario o si tiene choques/avisos; nunca a donde ya esta, ni a algo con mas avisos.
        var wantMove = !E.placed(p) || curHard || curSoft;
        cands = cands.filter(function (c) {
            return wantMove && !(c.day === p.day && c.start === p.start && (c.week || 0) === (p.week || 0)) && (curHard || !E.placed(p) || c.soft < curSoft);
        });
        cands.slice(0, max).forEach(function (c) {
            var test = { day: c.day, start: c.start, end: c.end, week: c.week, teacherid: p.teacherid, roomid: p.roomid };
            var why = curHard ? 'resuelve ' + curHard + ' choque(s)' : (E.placed(p) ? 'quedan ' + c.soft + ' aviso(s) en vez de ' + curSoft : 'sin choques');
            if (c.soft) why += ', con ' + c.soft + ' aviso(s)';
            var wl = E.weeksLabel(s, k, test);
            out.push({ kind: 'mover', day: c.day, start: c.start, end: c.end, week: c.week, soft: c.soft,
                label: 'Mover a ' + E.DAYS[c.day] + ' ' + E.hhmm(c.start) + '–' + E.hhmm(c.end) + (wl.indexOf('todo') === 0 ? '' : ' (' + wl.split(' · ')[0] + ')'), why: why,
                teacher: p.teacherid ? null : E.bestTeacher(s, k, test, true) });
        });
        // Sin hora libre: probar hacerle lugar moviendo los grupos que lo bloquean (en una copia).
        if (!out.length && (curHard || !E.placed(p))) {
            var backup = JSON.stringify(s.place);
            if (!p.locked && E.repair(s, k)) {
                var after = s.place;
                var before = JSON.parse(backup);
                var moved = s.order.filter(function (o) {
                    return o !== k && (before[o].day !== after[o].day || before[o].start !== after[o].start || (before[o].week || 0) !== (after[o].week || 0));
                }).map(function (o) {
                    return s.groups[o].name + ' G' + s.groups[o].number + ' → ' + E.DAYS_SHORT[after[o].day] + ' ' + E.hhmm(after[o].start);
                });
                var np = after[k];
                out.push({ kind: 'reparar', label: 'Hacerle lugar: ' + E.DAYS[np.day] + ' ' + E.hhmm(np.start) + '–' + E.hhmm(np.end),
                    why: 'mueve ' + moved.length + ' grupo(s) sin choques: ' + moved.join('; ') });
            }
            var restore = JSON.parse(backup);
            Object.keys(restore).forEach(function (kk) { s.place[kk] = restore[kk]; });
        }
        if (E.placed(p)) {
            var cs = E.conflicts(s, k);
            if (cs.some(function (c) { return c.type === 'docente' || c.type === 'disponibilidad' || c.type === 'habilidad'; }) || !p.teacherid) {
                E.teacherOptions(s, k).filter(function (o) { return o.skill && o.avail && !o.busy && o.id !== p.teacherid; }).slice(0, 3).forEach(function (o) {
                    out.push({ kind: 'docente', teacherid: o.id, label: 'Asignar a ' + o.name, why: 'tiene la habilidad, está disponible y libre ('
                        + Math.round(o.load / 60) + ' h/sem en el tablero)' });
                });
            }
            if (cs.some(function (c) { return c.type === 'aula' || c.type === 'capacidad'; }) || !p.roomid) {
                var r = E.bestRoom(s, k, p);
                if (r && r.id !== p.roomid) out.push({ kind: 'aula', roomid: r.id, label: 'Usar el aula ' + r.name, why: 'libre, capacidad ' + r.capacity });
            }
        }
        return out;
    };

    /** Reparto en carriles de las tarjetas que se cruzan en un dia (para dibujarlas lado a lado). */
    E.lanes = function (items) {
        var sorted = items.slice().sort(function (a, b) { return a.start - b.start || b.end - a.end; });
        var laneEnds = [], clusters = [], cur = null, curEnd = -1;
        sorted.forEach(function (it) {
            if (!cur || it.start >= curEnd) { cur = []; clusters.push(cur); laneEnds = []; curEnd = -1; }
            var lane = laneEnds.findIndex(function (e) { return e <= it.start; });
            if (lane === -1) { lane = laneEnds.length; laneEnds.push(it.end); } else laneEnds[lane] = it.end;
            it.lane = lane;
            cur.push(it);
            curEnd = Math.max(curEnd, it.end);
            cur.lanes = laneEnds.length;
        });
        clusters.forEach(function (c) { c.forEach(function (it) { it.lanes = c.lanes; }); });
        return sorted;
    };

    /** Resumen: grupos ubicados, con choques, sin docente, sin aula. */
    E.summary = function (s) {
        var all = E.allConflicts(s);
        var r = { total: s.order.length, placed: 0, hard: 0, soft: 0, noTeacher: 0, noRoom: 0, conflicts: all };
        s.order.forEach(function (k) {
            var p = s.place[k];
            if (!E.placed(p)) return;
            r.placed++;
            var sev = E.severity(all[k]);
            if (sev === 'hard') r.hard++;
            else if (sev === 'soft') r.soft++;
            if (!p.teacherid) r.noTeacher++;
            if (!p.roomid) r.noRoom++;
        });
        return r;
    };

    /** Ubicaciones listas para guardar (solo las que tienen algo). */
    E.serialize = function (s) {
        var out = {};
        s.order.forEach(function (k) {
            var p = s.place[k];
            if (E.placed(p) || p.teacherid || p.roomid) {
                out[k] = { day: E.placed(p) ? p.day : 0, start: E.placed(p) ? p.start : 0, end: E.placed(p) ? p.end : 0,
                    teacherid: p.teacherid || 0, roomid: p.roomid || 0, locked: !!p.locked, week: E.placed(p) ? (p.week || 0) : 0,
                    sessions: s.groups[k].sessions || 0 };
            }
        });
        return out;
    };

    root.GmkBoard = E;
    if (typeof module !== 'undefined' && module.exports) module.exports = E;
})(typeof window !== 'undefined' ? window : this);
