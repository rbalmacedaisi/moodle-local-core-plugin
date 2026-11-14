// Horarios cargados (pestana Proyeccion -> Horarios cargados). SOLO LECTURA.
// Muestra las clases reales del periodo (no la propuesta del tablero) y quien quedo en cada una, para ver e imprimir
// el horario semanal por estudiante, por cohorte (carrera + ingreso + jornada) o por clase.
// Datos: WS local_grupomakro_planning_save_config, seccion loaded_view (no escribe nada).
(function () {
    'use strict';
    var DAYS = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };
    var JORDER = ['Diurna', 'Nocturna', 'Sabatina'];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function hhmm(m) { return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
    function dmy(ymd) { var m = String(ymd || '').split('-'); return m.length === 3 ? m[2] + '/' + m[1] + '/' + m[0] : ''; }
    function dm(ymd) { var m = String(ymd || '').split('-'); return m.length === 3 ? m[2] + '/' + m[1] : ''; }
    function nowTxt() { var d = new Date(); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function jRank(j) { var i = JORDER.indexOf(j); return i < 0 ? 9 : i; }

    var CSS = '.lsg{display:grid;border:1px solid #94A3B8;background:#fff}.lsg-dh{text-align:center;font-weight:bold;background:#EEF2FF;border-left:1px solid #94A3B8;padding:3px;font-size:11px}'
        + '.lsg-ax{position:relative;border-top:1px solid #94A3B8}.lsg-hr{position:absolute;right:3px;font-size:9px;color:#64748B;transform:translateY(-50%)}'
        + '.lsg-col{position:relative;border-left:1px solid #94A3B8;border-top:1px solid #94A3B8}.lsg-ln{position:absolute;left:0;right:0;border-top:1px dotted #CBD5E1}'
        + '.lsg-cd{position:absolute;border:1px solid #4F46E5;background:#EEF2FF;border-radius:3px;padding:2px 4px;font-size:9.5px;line-height:1.25;overflow:hidden;color:#111;'
        + '-webkit-print-color-adjust:exact;print-color-adjust:exact}.lsg-cd b{font-size:10px}.lsg-cd.q{border-style:dashed;background:#FFFBEB;border-color:#D97706}'
        + '.lsg-cd.pv{background:#F1F5F9;border-color:#64748B}.lsg-cd .sm{color:#475569}'
        + '.lst-t{border-collapse:collapse;width:100%;margin-top:6px;font-size:10.5px}.lst-t th,.lst-t td{border:1px solid #CBD5E1;padding:2px 5px;text-align:left;vertical-align:top}'
        + '.lst-t th{background:#F1F5F9;-webkit-print-color-adjust:exact;print-color-adjust:exact}';

    function oneDay(c) { return !!c.first && c.first === c.last; }

    /** Sesiones de una sola fecha (seminarios): lista aparte para no tapar la cuadricula semanal. */
    function singles(items) {
        var l = items.filter(function (x) { return x.slot && oneDay(x.c); })
            .sort(function (x, y) { return x.c.first.localeCompare(y.c.first) || x.slot.start - y.slot.start; });
        if (!l.length) return '';
        return '<div style="font-size:11px;margin-top:6px"><b>Fechas puntuales (una sola sesión):</b> ' + l.map(function (x) {
            return esc(DAYS[x.slot.day]) + ' ' + esc(dmy(x.c.first)) + ' ' + hhmm(x.slot.start) + '–' + hhmm(x.slot.end) + ' ' + esc(x.c.short)
                + (x.slot.room ? ' (Aula ' + esc(x.slot.room) + ')' : '') + (x.note ? ' · ' + esc(x.note) : '');
        }).join('; ') + '</div>';
    }

    /** Cuadricula semanal: items = [{c (clase), slot, note}] */
    function grid(items, height) {
        items = items.filter(function (x) { return x.slot && !oneDay(x.c); });
        if (!items.length) return '<div style="color:#64748B;font-size:11px;padding:6px 0">Sin clases semanales con horario.</div>';
        var days = {};
        items.forEach(function (x) { days[x.slot.day] = true; });
        days = Object.keys(days).map(Number).sort(function (a, b) { return a - b; });
        var a = Math.min.apply(null, items.map(function (x) { return x.slot.start; }));
        var z = Math.max.apply(null, items.map(function (x) { return x.slot.end; }));
        a = Math.floor(a / 60) * 60; z = Math.ceil(z / 60) * 60;
        var ppm = Math.min(1.3, (height || 520) / Math.max(60, z - a)), H = Math.round((z - a) * ppm);
        var html = '<div class="lsg" style="grid-template-columns:46px repeat(' + days.length + ',1fr)"><div></div>';
        days.forEach(function (d) { html += '<div class="lsg-dh">' + DAYS[d] + '</div>'; });
        html += '<div class="lsg-ax" style="height:' + H + 'px">';
        for (var h = a; h <= z; h += 60) html += '<div class="lsg-hr" style="top:' + ((h - a) * ppm) + 'px">' + hhmm(h) + '</div>';
        html += '</div>';
        days.forEach(function (d) {
            html += '<div class="lsg-col" style="height:' + H + 'px">';
            for (var h2 = a; h2 <= z; h2 += 60) html += '<div class="lsg-ln" style="top:' + ((h2 - a) * ppm) + 'px"></div>';
            // Las que se suceden por semanas en la misma franja (mismo inicio, fechas sin cruce) van en una sola ficha;
            // las que se cruzan en hora y fechas van en carriles lado a lado.
            var list = [];
            items.filter(function (x) { return x.slot.day === d; })
                .sort(function (x, y) { return x.slot.start - y.slot.start || String(x.c.first).localeCompare(String(y.c.first)); })
                .forEach(function (x) {
                    var ch = list.find(function (o) { return o.slot.start === x.slot.start && o.chain[o.chain.length - 1].c.last < x.c.first; });
                    if (ch) { ch.chain.push(x); ch.slot = { day: d, start: ch.slot.start, end: Math.max(ch.slot.end, x.slot.end) }; return; }
                    list.push({ slot: x.slot, c: x.c, queue: x.queue, note: x.note, chain: [x] });
                });
            var lanes = [];
            list.forEach(function (x) {
                var l = 0;
                while (lanes[l] && lanes[l].some(function (o) { return o.slot.start < x.slot.end && x.slot.start < o.slot.end; })) l++;
                (lanes[l] = lanes[l] || []).push(x);
                x.lane = l;
            });
            list.forEach(function (x) {
                var n = 0;
                lanes.forEach(function (ln, i) { if (ln.some(function (o) { return o.slot.start < x.slot.end && x.slot.start < o.slot.end; })) n = Math.max(n, i + 1); });
                var w = 100 / Math.max(1, n);
                var box = '" style="top:' + ((x.slot.start - a) * ppm) + 'px;height:' + Math.max(16, (x.slot.end - x.slot.start) * ppm - 2) + 'px;left:'
                    + (x.lane * w) + '%;width:calc(' + w + '% - 2px)">';
                if (x.chain.length > 1) {
                    html += '<div class="lsg-cd' + (x.chain.some(function (y) { return y.queue; }) ? ' q' : '') + box
                        + '<b>' + hhmm(x.slot.start) + '–' + hhmm(x.slot.end) + ' · por semanas</b>' + x.chain.map(function (y) {
                            return '<div style="border-top:1px dotted #818CF8;margin-top:1px"><b>' + esc(dm(y.c.first)) + '–' + esc(dm(y.c.last)) + '</b> '
                                + esc(y.c.short) + (y.slot.room ? ' · Aula ' + esc(y.slot.room) : '') + '<span class="sm">'
                                + (y.c.teacher ? ' · ' + esc(y.c.teacher) : '') + (y.note ? ' · ' + esc(y.note) : '') + '</span></div>';
                        }).join('') + '</div>';
                    return;
                }
                html += '<div class="lsg-cd' + (x.queue ? ' q' : '') + (x.c.prev ? ' pv' : '') + box
                    + '<b>' + esc(x.c.short) + '</b><br>' + hhmm(x.slot.start) + '–' + hhmm(x.slot.end)
                    + (x.slot.room ? ' · Aula ' + esc(x.slot.room) : '') + '<br><span class="sm">' + esc(dm(x.c.first)) + '–' + esc(dm(x.c.last))
                    + (x.c.teacher ? ' · ' + esc(x.c.teacher) : '') + (x.note ? ' · ' + esc(x.note) : '') + '</span></div>';
            });
            html += '</div>';
        });
        return html + '</div>';
    }

    function detail(items, withCount) {
        var rows = items.slice().sort(function (x, y) {
            var sx = x.c.slots[0] || { day: 9, start: 0 }, sy = y.c.slots[0] || { day: 9, start: 0 };
            return (x.c.module ? 1 : 0) - (y.c.module ? 1 : 0) || sx.day - sy.day || sx.start - sy.start || x.c.short.localeCompare(y.c.short);
        });
        return '<table class="lst-t"><thead><tr><th>Asignatura</th><th>Clase</th><th>Horario</th><th>Fechas</th><th>Docente</th>'
            + (withCount ? '<th>Estudiantes</th>' : '<th>Estado</th>') + '</tr></thead><tbody>'
            + rows.map(function (x) {
                var c = x.c;
                return '<tr><td>' + esc(c.short) + (c.prev ? ' <i>(viene del periodo anterior)</i>' : '') + '</td><td>#' + c.id + ' · ' + esc(c.jornada) + '</td><td>'
                    + (c.module ? 'Módulo (sin horario)' : c.slots.map(function (s) { return DAYS[s.day] + ' ' + hhmm(s.start) + '–' + hhmm(s.end) + (s.room ? ' (' + esc(s.room) + ')' : ''); }).join('<br>'))
                    + '</td><td>' + esc(dmy(c.first)) + ' – ' + esc(dmy(c.last)) + (c.sessions ? ' (' + c.sessions + ' ses.)' : '') + '</td><td>' + esc(c.teacher || 'sin asignar')
                    + '</td><td>' + esc(withCount ? x.note : (x.queue ? 'en cola (aún no matriculado en el grupo)' : 'matriculado')) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    Vue.component('loaded-schedules', {
        template: `
        <div class="tl-proj-mods">
            <div class="tl-proj-pubnote" style="margin-bottom:8px">
                <v-icon size="16" color="#4F46E5">mdi-information-outline</v-icon>
                <span>Clases <b>reales</b> del periodo {{ periodName }} (las ya creadas, no la propuesta del tablero) y quién quedó en cada una.
                    Sirve para revisar e imprimir el horario de cada estudiante, de cada cohorte o de cada clase. Solo lectura.</span>
            </div>
            <div v-if="loading" class="tl-proj-empty">Cargando horarios…</div>
            <div v-else-if="error" class="tl-proj-empty" style="color:#B91C1C">{{ error }} <button class="tl-plan-link" @click="load">Reintentar</button></div>
            <template v-else-if="data">
                <div class="tl-proj-toolbar" style="flex-wrap:wrap;gap:6px">
                    <div class="tl-plan-tabs" style="flex:0 0 auto">
                        <button v-for="m in modes" :key="m.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': mode === m.key }" @click="mode = m.key; sel = null">
                            <v-icon size="14">{{ m.icon }}</v-icon> {{ m.label }}</button>
                    </div>
                    <select v-if="showCareers" v-model.number="fPlan" class="tl-cfg-input" title="Carrera">
                        <option :value="0">Todas las carreras</option>
                        <option v-for="p in planOpts" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <select v-model="fIngreso" class="tl-cfg-input" title="Periodo de ingreso del estudiante">
                        <option value="">Todo ingreso</option>
                        <option v-for="i in ingresoOpts" :key="i" :value="i">Ingreso {{ i || '(sin dato)' }}</option>
                    </select>
                    <select v-model="fJornada" class="tl-cfg-input" title="Jornada">
                        <option value="">Toda jornada</option>
                        <option v-for="j in jornadaOpts" :key="j" :value="j">{{ j }}</option>
                    </select>
                    <select v-if="mode !== 'clase'" v-model="fLevel" class="tl-cfg-input" title="Nivel en este periodo">
                        <option value="">Todo nivel</option>
                        <option v-for="l in levelOpts" :key="l" :value="l">{{ l }}</option>
                    </select>
                    <select v-if="mode === 'estudiante'" v-model="fLoad" class="tl-cfg-input">
                        <option value="con">Con asignaturas cargadas</option>
                        <option value="sin">Sin ninguna asignatura</option>
                        <option value="cola">Con alguna en cola</option>
                        <option value="">Todos</option>
                    </select>
                    <div class="tl-panel-search" style="flex:1;min-width:160px;max-width:260px">
                        <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                        <input v-model="q" type="text" :placeholder="mode === 'clase' ? 'Buscar asignatura o docente...' : 'Buscar nombre, documento o asignatura...'" class="tl-panel-search-input"/>
                    </div>
                    <button class="tl-plan-link" :disabled="!list.length" @click="printList(list)" :title="'Imprime una hoja por cada ' + unitName + ' de la lista'">
                        <v-icon size="14">mdi-printer</v-icon> Imprimir {{ list.length }} {{ unitName }}{{ list.length === 1 ? '' : 's' }}</button>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;margin-top:8px">
                    <div style="flex:0 0 330px;max-height:640px;overflow:auto;border:1px solid #E2E8F0;border-radius:6px">
                        <div v-if="!list.length" class="tl-proj-empty">Nada con estos filtros.</div>
                        <div v-for="u in list.slice(0, 400)" :key="u.key" @click="sel = u.key"
                             style="padding:6px 8px;border-bottom:1px solid #F1F5F9;cursor:pointer"
                             :style="sel === u.key ? 'background:#EEF2FF' : ''">
                            <div style="font-weight:600;font-size:12.5px">{{ u.title }}</div>
                            <div style="font-size:11px;color:#64748B">{{ u.sub }}</div>
                        </div>
                        <div v-if="list.length > 400" class="tl-proj-empty">Se muestran 400 de {{ list.length }}; afine los filtros (la impresión incluye todos).</div>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div v-if="!current" class="tl-proj-empty">Elija un{{ mode === 'cohorte' ? 'a' : '' }} {{ unitName }} de la lista para ver su horario.</div>
                        <template v-else>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                <div><div style="font-weight:700;font-size:14px">{{ current.title }}</div><div style="font-size:11.5px;color:#64748B">{{ current.sub }}</div></div>
                                <button class="tl-plan-link" @click="printList([current])"><v-icon size="14">mdi-printer</v-icon> Imprimir</button>
                            </div>
                            <div v-html="pageHtml(current, false)"></div>
                        </template>
                    </div>
                </div>
            </template>
        </div>`,
        props: {
            periodid: { type: Number, required: true },
            periodName: { type: String, default: '' },
            planIds: { type: String, default: '' },
            showCareers: { type: Boolean, default: true },
        },
        data: function () {
            return { loading: false, error: '', data: null, mode: 'estudiante', fPlan: 0, fIngreso: '', fJornada: '', fLevel: '', fLoad: 'con', q: '', sel: null,
                modes: [{ key: 'estudiante', label: 'Por estudiante', icon: 'mdi-account' }, { key: 'cohorte', label: 'Por cohorte', icon: 'mdi-account-group' },
                    { key: 'clase', label: 'Por clase', icon: 'mdi-google-classroom' }] };
        },
        created: function () {
            if (!document.getElementById('gmk-lsg-css')) {
                var st = document.createElement('style');
                st.id = 'gmk-lsg-css';
                st.textContent = CSS;
                document.head.appendChild(st);
            }
            this.load();
        },
        computed: {
            unitName: function () { return { estudiante: 'estudiante', cohorte: 'cohorte', clase: 'clase' }[this.mode]; },
            fixedPlans: function () { return String(this.planIds || '').split(',').map(Number).filter(Boolean); },
            classById: function () { var m = {}; (this.data ? this.data.classes : []).forEach(function (c) { m[c.id] = c; }); return m; },
            students: function () {
                var fp = this.fixedPlans;
                var list = this.data ? this.data.students : [];
                if (fp.length) list = list.filter(function (s) { return s.plans.some(function (p) { return fp.indexOf(p.planid) >= 0; }); });
                return list;
            },
            planOpts: function () {
                var m = {};
                this.students.forEach(function (s) { s.plans.forEach(function (p) { m[p.planid] = p.plan; }); });
                return Object.keys(m).map(function (k) { return { id: Number(k), name: m[k] }; }).sort(function (a, b) { return a.name.localeCompare(b.name); });
            },
            ingresoOpts: function () {
                var m = {};
                this.students.forEach(function (s) { if (s.classes.length) m[s.ingreso] = true; });
                return Object.keys(m).sort(function (a, b) { return (/^\d{4}/.test(b) ? 1 : 0) - (/^\d{4}/.test(a) ? 1 : 0) || b.localeCompare(a); });
            },
            jornadaOpts: function () {
                var m = {};
                (this.data ? this.data.classes : []).forEach(function (c) { if (c.jornada) m[c.jornada] = true; });
                this.students.forEach(function (s) { if (s.jornada) m[s.jornada] = true; });
                return Object.keys(m).sort(function (a, b) { return jRank(a) - jRank(b) || a.localeCompare(b); });
            },
            levelOpts: function () {
                var m = {};
                this.students.forEach(function (s) { s.plans.forEach(function (p) { if (p.level) m[p.level] = true; }); });
                return Object.keys(m).sort();
            },
            // Estudiantes que pasan los filtros de carrera, ingreso, jornada y nivel.
            fStudents: function () {
                var self = this, cb = this.classById;
                return this.students.filter(function (s) {
                    if (self.fPlan && !s.plans.some(function (p) { return p.planid === self.fPlan; })) return false;
                    if (self.fIngreso !== '' && s.ingreso !== self.fIngreso) return false;
                    if (self.fJornada && s.jornada !== self.fJornada) return false;
                    if (self.fLevel && !s.plans.some(function (p) { return p.level === self.fLevel; })) return false;
                    return true;
                }).map(function (s) {
                    return Object.assign({}, s, { items: s.classes.map(function (x) { return { c: cb[x.id], queue: x.st === 'en cola' }; }).filter(function (x) { return x.c; }) });
                });
            },
            list: function () {
                var self = this, q = this.q.trim().toLowerCase();
                var has = function (t) { return !q || String(t).toLowerCase().indexOf(q) >= 0; };
                var planTxt = function (s) { return s.plans.map(function (p) { return p.plan + ' · ' + p.level; }).join('; ') || 'sin carrera activa'; };
                if (this.mode === 'estudiante') {
                    return this.fStudents.filter(function (s) {
                        if (self.fLoad === 'con' && !s.items.length) return false;
                        if (self.fLoad === 'sin' && s.items.length) return false;
                        if (self.fLoad === 'cola' && !s.items.some(function (x) { return x.queue; })) return false;
                        return has(s.name + ' ' + s.doc + ' ' + s.items.map(function (x) { return x.c.short; }).join(' '));
                    }).map(function (s) {
                        return { key: 'u' + s.userid, kind: 'estudiante', title: s.name, s: s, items: s.items,
                            sub: (s.doc ? s.doc + ' · ' : '') + planTxt(s) + ' · ' + (s.jornada || 'sin jornada') + ' · ingreso ' + (s.ingreso || '—')
                                + ' · ' + s.items.length + ' asignatura(s)' };
                    });
                }
                if (this.mode === 'cohorte') {
                    var m = {};
                    this.fStudents.forEach(function (s) {
                        (s.plans.length ? s.plans : [{ planid: 0, plan: 'Sin carrera activa', level: '' }]).forEach(function (p) {
                            if (self.fPlan && p.planid !== self.fPlan) return;
                            var k = p.planid + '|' + s.ingreso + '|' + s.jornada;
                            var g = m[k] = m[k] || { key: 'c' + k, kind: 'cohorte', plan: p.plan, ingreso: s.ingreso, jornada: s.jornada, members: [], levels: {}, cls: {} };
                            g.members.push(s);
                            if (p.level) g.levels[p.level] = (g.levels[p.level] || 0) + 1;
                            s.items.forEach(function (x) { var e = g.cls[x.c.id] = g.cls[x.c.id] || { c: x.c, n: 0, q: 0 }; e.n++; if (x.queue) e.q++; });
                        });
                    });
                    return Object.keys(m).map(function (k) { return m[k]; }).map(function (g) {
                        var n = g.members.length;
                        g.items = Object.keys(g.cls).map(function (id) {
                            var e = g.cls[id];
                            return { c: e.c, note: e.n === n ? 'todos (' + n + ')' : e.n + ' de ' + n + (e.q ? ', ' + e.q + ' en cola' : '') };
                        });
                        var lv = Object.keys(g.levels).map(function (l) { return l + (Object.keys(g.levels).length > 1 ? ' (' + g.levels[l] + ')' : ''); }).join(', ');
                        g.title = g.plan + ' · ingreso ' + (g.ingreso || '—') + ' · ' + (g.jornada || 'sin jornada');
                        g.sub = n + ' estudiante(s) · nivel ' + (lv || '—') + ' · ' + g.items.length + ' asignatura(s)'
                            + (g.members.filter(function (s) { return !s.items.length; }).length ? ' · ' + g.members.filter(function (s) { return !s.items.length; }).length + ' sin carga' : '');
                        return g;
                    }).filter(function (g) { return has(g.title + ' ' + g.items.map(function (x) { return x.c.short; }).join(' ')); })
                        .sort(function (a, b) { return a.plan.localeCompare(b.plan) || String(b.ingreso).localeCompare(String(a.ingreso)) || jRank(a.jornada) - jRank(b.jornada); });
                }
                // Por clase
                var allowed = null;
                if (this.fixedPlans.length || this.fPlan || this.fIngreso !== '' || this.fJornada) {
                    allowed = {};
                    this.fStudents.forEach(function (s) { allowed[s.userid] = true; });
                }
                var byClass = {};
                this.fStudents.forEach(function (s) { s.items.forEach(function (x) { (byClass[x.c.id] = byClass[x.c.id] || []).push({ s: s, queue: x.queue }); }); });
                return (this.data ? this.data.classes : []).filter(function (c) {
                    if (self.fJornada && c.jornada !== self.fJornada) return false;
                    if (allowed && !(byClass[c.id] || []).length) return false;
                    return has(c.short + ' ' + c.teacher + ' #' + c.id);
                }).map(function (c) {
                    var mem = (byClass[c.id] || []).slice().sort(function (a, b) { return a.s.name.localeCompare(b.s.name); });
                    var sl = c.module ? 'Módulo' : c.slots.map(function (s) { return DAYS[s.day].slice(0, 3) + ' ' + hhmm(s.start) + '–' + hhmm(s.end); }).join(', ');
                    return { key: 'k' + c.id, kind: 'clase', c: c, mem: mem, items: [{ c: c, note: mem.length + ' est.' }],
                        title: c.short + ' · ' + c.jornada, sub: '#' + c.id + ' · ' + sl + ' · ' + (c.teacher || 'sin docente') + ' · ' + mem.length + ' estudiante(s)'
                            + (allowed && mem.length !== c.size ? ' de ' + c.size : '') + (c.prev ? ' · viene del periodo anterior' : '') };
                }).sort(function (a, b) { return jRank(a.c.jornada) - jRank(b.c.jornada) || a.c.short.localeCompare(b.c.short); });
            },
            current: function () { var k = this.sel; return this.list.find(function (u) { return u.key === k; }) || null; },
        },
        watch: { periodid: function () { this.load(); } },
        methods: {
            load: async function () {
                this.loading = true; this.error = '';
                try {
                    var body = new URLSearchParams({ wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_save_config',
                        section: 'loaded_view', data: JSON.stringify({ periodid: this.periodid }) });
                    var r = await window.axios.post(window.location.origin + '/webservice/rest/server.php', body);
                    if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                    if (!r.data.ok) throw new Error(r.data.message);
                    this.data = typeof r.data.saved === 'string' ? JSON.parse(r.data.saved) : r.data.saved;
                } catch (e) { this.error = 'No se pudieron cargar los horarios: ' + (e.message || e); }
                finally { this.loading = false; }
            },
            // Expande cada clase en sus franjas semanales para la cuadricula.
            gridItems: function (items) {
                var out = [];
                items.forEach(function (x) { (x.c.slots || []).forEach(function (s) { out.push({ c: x.c, slot: s, queue: x.queue, note: x.note }); }); });
                return out;
            },
            pageHtml: function (u, print) {
                var h = print ? 430 : 520;
                var mods = u.items.filter(function (x) { return x.c.module || !x.c.slots.length; });
                if (u.kind === 'clase') {
                    var c = u.c;
                    return grid(this.gridItems(u.items), print ? 160 : 220) + singles(this.gridItems(u.items))
                        + '<table class="lst-t"><thead><tr><th>#</th><th>Estudiante</th><th>Documento</th><th>Carrera · nivel</th><th>Jornada</th><th>Ingreso</th><th>Estado</th></tr></thead><tbody>'
                        + u.mem.map(function (m, i) {
                            return '<tr><td>' + (i + 1) + '</td><td>' + esc(m.s.name) + '</td><td>' + esc(m.s.doc) + '</td><td>'
                                + esc(m.s.plans.map(function (p) { return p.plan + ' · ' + p.level; }).join('; ')) + '</td><td>' + esc(m.s.jornada) + '</td><td>'
                                + esc(m.s.ingreso) + '</td><td>' + (m.queue ? 'en cola' : 'matriculado') + '</td></tr>';
                        }).join('') + '</tbody></table>' + (c.module ? '<div style="font-size:11px;color:#64748B">Módulo: sin horario ni sesiones.</div>' : '');
                }
                if (!u.items.length) return '<div style="color:#B45309;font-size:12px;padding:8px 0">No tiene ninguna asignatura cargada en este periodo.</div>';
                return grid(this.gridItems(u.items), h) + singles(this.gridItems(u.items))
                    + (mods.length ? '<div style="font-size:11px;margin-top:4px"><b>Sin horario:</b> ' + mods.map(function (x) { return esc(x.c.short) + (x.c.module ? ' (módulo)' : ''); }).join(', ') + '</div>' : '')
                    + detail(u.items, u.kind === 'cohorte')
                    + (u.kind === 'cohorte' ? '<div style="font-size:10.5px;margin-top:6px"><b>Estudiantes (' + u.members.length + '):</b> '
                        + u.members.map(function (s) { return esc(s.name) + (s.items.length ? '' : ' <i>(sin carga)</i>'); }).join(', ') + '</div>' : '');
            },
            printList: function (units) {
                var self = this;
                var title = 'Horario por ' + this.unitName + ' · Periodo ' + this.periodName;
                var logo = window.gmkReportLogo ? '<img src="' + esc(window.gmkReportLogo) + '" style="height:38px;width:auto" alt="">' : '';
                var pages = units.map(function (u) {
                    return '<div class="pg"><div class="hd"><div style="display:flex;align-items:center;gap:8px">' + logo + '<div><b>' + esc(u.title) + '</b><div class="sm">'
                        + esc(u.sub) + '</div></div></div><div class="sm" style="text-align:right">Periodo ' + esc(self.periodName) + '<br>Impreso ' + nowTxt()
                        + '</div></div>' + self.pageHtml(u, true) + '</div>';
                }).join('');
                var w = window.open('', '_blank');
                if (!w) { window.alert('El navegador bloqueó la ventana de impresión. Permita las ventanas emergentes para este sitio e intente de nuevo.'); return; }
                w.document.open();
                w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>'
                    + '*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;margin:0}'
                    + '.pg{page-break-after:always;padding:4px}.pg:last-child{page-break-after:auto}'
                    + '.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #333;margin-bottom:6px;padding-bottom:4px;font-size:13px}'
                    + '.sm{font-size:10px;color:#555}tr{page-break-inside:avoid}.bar{position:sticky;top:0;background:#fff;padding:6px;border-bottom:1px solid #ddd;margin-bottom:6px}'
                    + '.bar button{font-size:13px;padding:5px 14px;margin-right:8px;cursor:pointer}@media print{.bar{display:none}}@page{size:A4 landscape;margin:8mm}'
                    + CSS + '</style></head><body><div class="bar"><button onclick="window.print()">Imprimir</button><button onclick="window.close()">Cerrar</button>'
                    + '<span class="sm">' + units.length + ' hoja(s). En la ventana de impresión elija horizontal y "Gráficos de fondo" para ver los colores.</span></div>'
                    + (pages || '<div>No hay nada que imprimir.</div>')
                    + '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});<\/script></body></html>');
                w.document.close();
                w.focus();
            },
        },
    });
})();
