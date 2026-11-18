/**
 * Module Management component — admin page for managing independent study modules.
 * Lists all module classes grouped by period; shows enrolled students with deadline info.
 */
// Ícono ⓘ con una explicación breve de cómo se usa cada parte de la pantalla.
Vue.component('mm-info', {
    props: { text: { type: String, required: true }, color: { type: String, default: 'grey' } },
    template: `
<v-tooltip bottom max-width="340" open-on-click>
  <template v-slot:activator="{ on, attrs }">
    <v-icon x-small :color="color" class="ml-1" style="cursor:help; vertical-align:middle"
      v-bind="attrs" v-on="on" @click.stop>mdi-information-outline</v-icon>
  </template>
  <span style="white-space:pre-line">{{ text }}</span>
</v-tooltip>`,
});

Vue.component('modulemanagement', {
    data() {
        return {
            wwwroot: (typeof wwwroot !== 'undefined' ? wwwroot : ''),

            // Periods
            periods: [],
            selectedPeriodId: 0,

            // Modules table
            modules: [],
            loadingModules: false,
            moduleSearch: '',
            moduleHeaders: [
                { text: 'Asignatura',    value: 'coursename',           sortable: true  },
                { text: 'Período',       value: 'periodcode',           sortable: true  },
                { text: 'Grupo Moodle',  value: 'name',                 sortable: false },
                { text: 'Plazo (días)',  value: 'module_deadline_days', sortable: true, align: 'center' },
                { text: 'Inscritos',     value: 'enrolled_count',       sortable: true, align: 'center' },
                { text: 'Por calificar', value: '_pending',             sortable: true, align: 'center' },
                { text: 'Acciones',      value: '_actions',             sortable: false, align: 'center' },
            ],

            // Students dialog
            studentsDialog:    false,
            selectedModule:    null,
            students:          [],
            loadingStudents:   false,
            studentHeaders: [
                { text: 'Estudiante',       value: '_fullname',    sortable: true  },
                { text: 'Inscripción',      value: 'enrolldate',   sortable: true  },
                { text: 'Fecha límite',     value: 'duedate',      sortable: true  },
                { text: 'Días restantes',   value: '_daysremaining', sortable: true, align: 'center' },
                { text: 'Estado',           value: 'status',       sortable: true, align: 'center'  },
                { text: 'Entrega',          value: '_submissionorder', sortable: true, align: 'center' },
                { text: 'Acciones',         value: '_actions',     sortable: false, align: 'center' },
            ],

            // Extend deadline dialog
            extendDialog:         false,
            selectedEnrollment:   null,
            extendDays:           15,
            savingExtend:         false,

            // Update enrollment loading map { enrollmentid: true }
            updatingMap: {},
            deletingModuleId: null,

            // ── Solicitudes de módulo con factura Odoo ──────────────────────────
            activeTab: 0,                                    // 0 = módulos, 1 = solicitudes
            requestHeaders: [
                { text: 'Estudiante',         value: 'fullname',           sortable: true  },
                { text: 'Asignatura',         value: 'coursename',         sortable: true  },
                { text: 'Tipo',               value: 'module_type_label',  sortable: true  },
                { text: 'Factura #',          value: 'invoice_number',     sortable: true  },
                { text: 'Monto',              value: 'amount',             sortable: true, align: 'right' },
                { text: 'Estado pago',        value: '_payment_state',     sortable: false, align: 'center' },
                { text: 'Estado solicitud',   value: '_status',            sortable: true, align: 'center' },
                { text: 'Expira',             value: 'expires_at',         sortable: true  },
                { text: 'Acciones',           value: '_actions',           sortable: false, align: 'center' },
            ],
            requests: [],
            loadingRequests: false,
            requestSearch: '',
            requestStatusFilter: '',  // '' = todos, 'pending_payment', 'paid', 'enrolled', 'expired', 'cancelled'
            verifyingMap: {},         // { requestId: true } para spinner individual
            cancellingMap: {},        // { requestId: true } para spinner individual
            enrollingMap: {},         // { requestId: true } para spinner individual al inscribir

            // Grade entry dialog (shown when marking completed without an existing grade
            // or when the module has more than one activity)
            gradeDialog:        false,
            gradeEnrollment:    null,
            gradeActivities:    [],   // [{ itemid, name, grade, weight }]
            loadingGrade:       false,
            savingGrade:        false,

            // Revisión de entregas
            pendingByClass:     {},      // { classid: n entregas por calificar }
            onlyPending:        false,
            reviewDialog:       false,
            reviewEnrollment:   null,
            reviewDetail:       null,
            reviewForm:         [],      // [{ itemid, grade, feedback, weight }]
            loadingReview:      false,
            savingReview:       false,

            // Textos de ayuda (íconos ⓘ)
            help: {
                page: 'Cómo se trabaja un módulo:\n1. Filtre por período o busque la asignatura.\n2. La columna "Por calificar" indica cuántos estudiantes ya enviaron la actividad y esperan nota.\n3. Pulse "Estudiantes" para ver los inscritos y el estado de su entrega.\n4. Pulse "Revisar" en el estudiante: verá sus archivos, podrá poner la nota y el comentario y completarlo en el mismo paso.',
                period: 'Muestra solo los módulos del período académico elegido. "Todos los períodos" incluye los recién creados.',
                search: 'Escriba parte del nombre de la asignatura para filtrar la tabla.',
                onlyPending: 'Al activarlo, la tabla muestra solo los módulos que tienen entregas esperando calificación.',
                refresh: 'Vuelve a cargar los módulos y el conteo de entregas por calificar.',
                group: 'Grupo de Moodle del módulo. El enlace abre la lista de miembros del grupo en Moodle.',
                deadline: 'Días que tiene cada estudiante para terminar el módulo desde que se inscribe.',
                enrolled: 'Estudiantes con inscripción activa (aún no completados ni vencidos).',
                pending: 'Estudiantes que ya enviaron la actividad y todavía no tienen nota (o la reenviaron después de calificada). Pulse "Estudiantes" y luego "Revisar" para calificarlos.',
                moduleActions: '"Estudiantes": abre la lista de inscritos con el estado de su entrega.\n"Eliminar": borra el módulo, su grupo y las inscripciones (no desmatricula del curso).',
                due: 'Fecha límite para terminar el módulo. Se puede ampliar con "Extender".',
                days: 'Días que le quedan al estudiante antes de la fecha límite. Rojo: 7 días o menos; naranja: 15 o menos.',
                status: 'Activo: cursando el módulo.\nCompletado: ya se registró su nota final.\nVencido: pasó la fecha límite sin completar.',
                submission: 'Entregado · por calificar: el estudiante envió la actividad y falta la nota.\nCalificado: la actividad ya tiene nota.\nBorrador: subió archivos pero no pulsó "Enviar".\nSin entrega: no ha subido nada.',
                studentActions: '"Revisar": abre la entrega (archivos), permite poner nota y comentario y completar el módulo.\n"Extender": suma días a la fecha límite.\n"Completado": cierra el módulo con la nota que ya tenga.\n"Desvincular": quita al estudiante del módulo.',
                reviewFiles: 'Pulse el nombre del archivo para abrirlo en otra pestaña, o el ícono de descarga para guardarlo.',
                reviewGrade: 'Nota de 0 a 100. Se guarda en la actividad y en el libro de calificaciones, igual que si la pusiera el docente.',
                reviewFeedback: 'Comentario opcional para el estudiante. Lo verá junto a su nota en la actividad.',
                reviewWeight: 'Solo cuando el módulo tiene varias actividades: porcentaje de cada una en la nota final. Deben sumar 100%.',
                reviewSave: '"Guardar calificación": registra la nota y el comentario; el estudiante sigue activo en el módulo.\n"Guardar y completar": además cierra el módulo y pasa la nota final a su historial académico (aprobado con 70 o más).',
                reviewMoodle: 'Abre el calificador de Moodle para este estudiante (útil para anotar sobre el PDF).',
                reqStatusFilter: 'Filtra las solicitudes por su estado. "Pagado (por inscribir)" son las que ya pagaron y faltan por inscribir.',
                reqSearch: 'Escriba el nombre o el correo del estudiante y pulse Enter.',
                reqPayment: 'Estado del pago de la factura en Odoo. Si aparece "Pendiente" pero el estudiante ya pagó, use "Verificar pago".',
                reqStatus: 'Esperando pago: factura emitida sin pagar.\nPagado: listo para inscribir.\nInscrito: ya está en el módulo.\nExpirado/Cancelado: la solicitud ya no es válida.',
                reqExpires: 'Fecha en que vence la solicitud si no se paga.',
                reqActions: '"Verificar pago": consulta Odoo y actualiza el estado.\n"Inscribir": matricula al estudiante en el módulo (solo cuando está pagado).\n"Cancelar": anula la solicitud.',
                gradeDialog: 'Ingrese la nota de cada actividad. Al guardar se cierra el módulo del estudiante y la nota final pasa a su historial académico.',
            },
        };
    },

    computed: {
        visibleModules() {
            const list = this.modules.map(m => ({ ...m, _pending: this.pendingByClass[m.id] || 0 }));
            return this.onlyPending ? list.filter(m => m._pending > 0) : list;
        },
        totalPending() {
            return Object.values(this.pendingByClass).reduce((s, n) => s + (Number(n) || 0), 0);
        },
        reviewIsMulti() {
            return !!(this.reviewDetail && this.reviewDetail.activities.length > 1);
        },
        reviewWeightSum() {
            return this.reviewForm.reduce((s, a) => s + (Number(a.weight) || 0), 0);
        },
        reviewGradesValid() {
            return this.reviewForm.length > 0 && this.reviewForm.every(a =>
                a.grade === '' || a.grade === null || (Number(a.grade) >= 0 && Number(a.grade) <= 100));
        },
        reviewAnyGrade() {
            return this.reviewForm.some(a => a.grade !== '' && a.grade !== null);
        },
        reviewCanComplete() {
            if (!this.reviewGradesValid) return false;
            if (!this.reviewForm.every(a => a.grade !== '' && a.grade !== null)) return false;
            return !this.reviewIsMulti || Math.abs(this.reviewWeightSum - 100) < 0.01;
        },
        reviewFinalPreview() {
            if (!this.reviewForm.length || !this.reviewForm.every(a => a.grade !== '' && a.grade !== null)) return null;
            if (!this.reviewIsMulti) return Math.round(Number(this.reviewForm[0].grade) * 100) / 100;
            let w = 0, gw = 0;
            this.reviewForm.forEach(a => { w += Number(a.weight) || 0; gw += Number(a.grade) * (Number(a.weight) || 0); });
            return w > 0 ? Math.round((gw / w) * 100) / 100 : null;
        },
        periodItems() {
            const all = [{ id: 0, label: 'Todos los períodos' }];
            return all.concat(
                this.periods.map(p => ({ id: p.id, label: p.code || p.name }))
            );
        },

        // Grade dialog helpers
        gradeIsSingle() {
            return this.gradeActivities.length === 1;
        },
        gradeWeightSum() {
            return this.gradeActivities.reduce((s, a) => s + (Number(a.weight) || 0), 0);
        },
        gradeWeightValid() {
            // Single activity ignores weights; multiple must sum to ~100%.
            if (this.gradeIsSingle) return true;
            return Math.abs(this.gradeWeightSum - 100) < 0.01;
        },
        gradeAllFilled() {
            return this.gradeActivities.every(a =>
                a.grade !== null && a.grade !== '' && Number(a.grade) >= 0 && Number(a.grade) <= 100
            );
        },
        gradeFinalPreview() {
            if (!this.gradeActivities.length) return null;
            if (this.gradeIsSingle) {
                const g = Number(this.gradeActivities[0].grade);
                return isNaN(g) ? null : Math.round(g * 100) / 100;
            }
            let sumW = 0, sumGW = 0;
            for (const a of this.gradeActivities) {
                const g = Number(a.grade), w = Number(a.weight) || 0;
                if (isNaN(g)) continue;
                sumW += w; sumGW += g * w;
            }
            if (sumW <= 0) return null;
            return Math.round((sumGW / sumW) * 100) / 100;
        },
        gradeCanSave() {
            return this.gradeAllFilled && this.gradeWeightValid && this.gradeActivities.length > 0;
        },
    },

    mounted() {
        this.loadPeriods();
    },

    methods: {
        // ── Data loading ──────────────────────────────────────────────────────
        async loadPeriods() {
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_academic_periods',
                    sesskey,
                }});
                const data = (res.data || {}).data || [];
                this.periods = Array.isArray(data) ? data : [];
                // Default to "all periods" so newly created modules are always visible
                this.loadModules();
            } catch (e) {
                console.error('Error loading periods:', e);
                this.loadModules();
            }
        },

        async loadModules() {
            this.loadingModules = true;
            this.modules = [];
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:   'local_grupomakro_get_module_list',
                    sesskey,
                    periodId: this.selectedPeriodId || 0,
                }});
                const data = (res.data || {}).data || [];
                this.modules = Array.isArray(data) ? data : [];
            } catch (e) {
                console.error('Error loading modules:', e);
            } finally {
                this.loadingModules = false;
            }
            this.loadPendingCounts();
        },

        // Cantidad de entregas por calificar por módulo (columna "Por calificar").
        async loadPendingCounts() {
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_module_submissions', sesskey,
                }});
                const d = (res.data || {}).data || {};
                this.pendingByClass = (d.pending && typeof d.pending === 'object') ? d.pending : {};
            } catch (e) {
                console.error('Error loading pending submissions:', e);
            }
        },

        // Estado de entrega de cada inscrito del módulo abierto.
        async loadSubmissions(classId) {
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_module_submissions', sesskey, classId,
                }});
                const map = (((res.data || {}).data || {}).enrollments) || {};
                this.students = this.students.map(s => this.withSubmission(s, map[s.id]));
            } catch (e) {
                console.error('Error loading module submissions:', e);
            }
        },

        withSubmission(student, info) {
            const summary = info ? info.summary : 'none';
            // Orden para la columna: primero lo que espera nota.
            const order = { pending: 0, partial: 1, none: 2, graded: 3 };
            const acts = info ? (info.activities || []) : [];
            const graded = acts.filter(a => a.grade !== null);
            const draft = acts.some(a => a.submission === 'draft');
            return {
                ...student,
                _submission:      summary,
                _submissionorder: order[summary] !== undefined ? order[summary] : 9,
                _lastsubmission:  info ? info.lastsubmission : 0,
                _draft:           draft,
                _gradeLabel:      graded.length === 1 ? graded[0].grade : (graded.length ? graded.length + '/' + acts.length : null),
            };
        },

        submissionLabel(item) {
            if (item._submission === 'pending') return 'Entregado · por calificar';
            if (item._submission === 'graded')  return 'Calificado' + (item._gradeLabel !== null ? ' (' + item._gradeLabel + ')' : '');
            if (item._submission === 'partial') return 'Calificado parcial (' + item._gradeLabel + ')';
            if (item._draft) return 'Borrador sin enviar';
            return 'Sin entrega';
        },

        submissionColor(item) {
            return { pending: 'orange darken-2', graded: 'green darken-1', partial: 'light-green darken-2' }[item._submission]
                || (item._draft ? 'blue-grey' : 'grey');
        },

        // ── Revisión de la entrega ───────────────────────────────────────────
        async openReview(enrollment) {
            this.reviewEnrollment = enrollment;
            this.reviewDetail = null;
            this.reviewForm = [];
            this.reviewDialog = true;
            this.loadingReview = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_module_submission_detail', sesskey, enrollmentId: enrollment.id,
                }});
                const payload = res.data || {};
                if (payload.status !== 'success') {
                    throw new Error(((payload.data || payload).message) || 'No se pudo cargar la entrega.');
                }
                this.reviewDetail = payload.data;
                const acts = this.reviewDetail.activities || [];
                const equalW = acts.length ? Math.round((100 / acts.length) * 100) / 100 : 0;
                this.reviewForm = acts.map(a => ({
                    itemid:   a.itemid,
                    grade:    a.grade !== null ? a.grade : '',
                    feedback: a.feedback || '',
                    weight:   equalW,
                }));
                if (this.reviewForm.length > 1) {
                    const last = this.reviewForm.length - 1;
                    const drift = 100 - this.reviewForm.reduce((s, a) => s + a.weight, 0);
                    this.reviewForm[last].weight = Math.round((this.reviewForm[last].weight + drift) * 100) / 100;
                }
            } catch (e) {
                console.error('Error loading submission detail:', e);
                this.reviewDialog = false;
                this.showMessage('error', e.message || 'No se pudo cargar la entrega.');
            } finally {
                this.loadingReview = false;
            }
        },

        async saveReview(complete) {
            if (!this.reviewEnrollment || !this.reviewGradesValid || !this.reviewAnyGrade) return;
            if (complete && !this.reviewCanComplete) return;
            if (complete) {
                const ok = await window.Swal.fire({
                    title: 'Guardar y completar',
                    html: `Se guardará la nota y se cerrará el módulo de <b>${this.reviewEnrollment._fullname}</b>.<br>` +
                          `<small class="grey--text">Nota final: <b>${this.reviewFinalPreview}/100</b>. ` +
                          `La nota pasa a su historial académico.</small>`,
                    icon: 'question', showCancelButton: true,
                    confirmButtonText: 'Sí, completar', cancelButtonText: 'Cancelar', confirmButtonColor: '#4CAF50',
                });
                if (!ok.isConfirmed) return;
            }
            this.savingReview = true;
            const enrollment = this.reviewEnrollment;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_save_module_grades');
                params.append('sesskey', sesskey);
                params.append('enrollmentId', enrollment.id);
                params.append('grades', JSON.stringify(this.reviewForm
                    .filter(a => a.grade !== '' && a.grade !== null)
                    .map(a => ({ itemid: a.itemid, grade: Number(a.grade), feedback: a.feedback || '' }))));
                const res = await window.axios.post(ajaxUrl, params);
                const payload = res.data || {};
                if (payload.status !== 'success') {
                    throw new Error(((payload.data || payload).message) || 'No se pudo guardar la calificación.');
                }
                const saved = payload.data || {};
                const idx = this.students.findIndex(s => s.id === enrollment.id);
                if (idx !== -1) {
                    this.$set(this.students, idx, this.withSubmission(this.students[idx],
                        { summary: saved.summary, lastsubmission: this.students[idx]._lastsubmission, activities: saved.activities || [] }));
                }

                if (complete) {
                    // Una actividad: el cierre toma la nota recién guardada en la tarea.
                    // Varias: se envían notas y ponderaciones para calcular la final.
                    const gradesPayload = this.reviewIsMulti
                        ? this.reviewForm.map(a => ({ itemid: a.itemid, grade: Number(a.grade), weight: Number(a.weight) || 0 }))
                        : null;
                    const done = await this._sendComplete(enrollment, gradesPayload);
                    if (!done) return;
                } else {
                    this.showMessage('success', 'Calificación guardada.');
                }
                this.reviewDialog = false;
                this.loadPendingCounts();
            } catch (e) {
                console.error('Error saving module grades:', e);
                this.showMessage('error', e.message || 'No se pudo guardar la calificación.');
            } finally {
                this.savingReview = false;
            }
        },

        formatDateTime(ts) {
            if (!ts) return '—';
            return new Date(ts * 1000).toLocaleString('es-PA', {
                day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
            });
        },

        formatSize(bytes) {
            const n = Number(bytes) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        },

        fileIcon(mime) {
            const m = String(mime || '');
            if (m.indexOf('pdf') !== -1) return 'mdi-file-pdf-box';
            if (m.indexOf('image') === 0) return 'mdi-file-image';
            if (m.indexOf('word') !== -1 || m.indexOf('document') !== -1) return 'mdi-file-word';
            if (m.indexOf('sheet') !== -1 || m.indexOf('excel') !== -1) return 'mdi-file-excel';
            if (m.indexOf('presentation') !== -1 || m.indexOf('powerpoint') !== -1) return 'mdi-file-powerpoint';
            return 'mdi-file-document-outline';
        },

        activityStatusLabel(a) {
            if (a.needsgrading) return 'Entregado · por calificar';
            if (a.grade !== null) return 'Calificado';
            if (a.submission === 'submitted') return 'Entregado';
            if (a.submission === 'draft') return 'Borrador sin enviar';
            if (a.submission === 'reopened') return 'Reabierto';
            return 'Sin entrega';
        },

        activityStatusColor(a) {
            if (a.needsgrading) return 'orange darken-2';
            if (a.grade !== null) return 'green darken-1';
            if (a.submission === 'draft') return 'blue-grey';
            return 'grey';
        },

        async openStudents(module) {
            this.selectedModule  = module;
            this.students        = [];
            this.studentsDialog  = true;
            this.loadingStudents = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:  'local_grupomakro_get_module_students',
                    sesskey,
                    classId: module.id,
                }});
                const raw = (res.data || {}).data || [];
                const now = Math.floor(Date.now() / 1000);
                this.students = (Array.isArray(raw) ? raw : []).map(s => ({
                    ...s,
                    _fullname:      s.lastname + ', ' + s.firstname,
                    _daysremaining: Math.max(0, Math.ceil((s.duedate - now) / 86400)),
                    _submission:      'loading',
                    _submissionorder: 9,
                }));
            } catch (e) {
                console.error('Error loading module students:', e);
            } finally {
                this.loadingStudents = false;
            }
            if (this.students.length) {
                await this.loadSubmissions(module.id);
            }
        },

        async deleteModule(module) {
            if (!module || !module.id) return;

            const enrolledCount = Number(module.enrolled_count || 0);
            const confirmed = await window.Swal.fire({
                title: 'Eliminar módulo',
                html: `¿Eliminar <b>${module.name}</b>?<br><small class="red--text">Se eliminará el grupo Moodle, el registro del módulo y ${enrolledCount} inscripción(es) asociada(s). Los usuarios no se desmatricularán del curso completo.</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#D32F2F',
            });
            if (!confirmed.isConfirmed) return;

            this.deletingModuleId = module.id;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_delete_module',
                    sesskey,
                    classId: module.id,
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    this.modules = this.modules.filter(m => m.id !== module.id);
                    if (this.selectedModule && this.selectedModule.id === module.id) {
                        this.studentsDialog = false;
                        this.selectedModule = null;
                        this.students = [];
                    }
                    this.showMessage('success', ((payload.data || {}).message) || 'Módulo eliminado correctamente.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo eliminar el módulo.');
                }
            } catch (e) {
                console.error('Error deleting module:', e);
                this.showMessage('error', 'Error al eliminar el módulo.');
            } finally {
                this.deletingModuleId = null;
            }
        },

        // ── Enrollment updates ────────────────────────────────────────────────
        openExtendDialog(enrollment) {
            this.selectedEnrollment = enrollment;
            this.extendDays         = 15;
            this.extendDialog       = true;
        },

        async confirmExtend() {
            if (!this.selectedEnrollment || this.extendDays < 1) return;
            this.savingExtend = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: this.selectedEnrollment.id,
                    updateAction: 'extend',
                    days:         this.extendDays,
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const d = payload.data || {};
                    // Refresh the row
                    const idx = this.students.findIndex(s => s.id === this.selectedEnrollment.id);
                    if (idx !== -1) {
                        const now = Math.floor(Date.now() / 1000);
                        this.$set(this.students, idx, {
                            ...this.students[idx],
                            duedate:        d.duedate,
                            _daysremaining: Math.max(0, Math.ceil((d.duedate - now) / 86400)),
                        });
                    }
                    this.extendDialog = false;
                    this.showMessage('success', d.message || 'Plazo extendido correctamente.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo extender el plazo.');
                }
            } catch (e) {
                console.error('Error extending deadline:', e);
                this.showMessage('error', 'Error al extender el plazo.');
            } finally {
                this.savingExtend = false;
            }
        },

        async removeEnrollment(enrollment) {
            const confirmed = await window.Swal.fire({
                title:             'Desvincular estudiante',
                html:              `¿Desvincular a <b>${enrollment._fullname}</b> de este módulo?<br><small class="red--text">Se eliminará la inscripción y se removerá del grupo Moodle. Esta acción no se puede deshacer.</small>`,
                icon:              'warning',
                showCancelButton:  true,
                confirmButtonText: 'Sí, desvincular',
                cancelButtonText:  'Cancelar',
                confirmButtonColor:'#D32F2F',
            });
            if (!confirmed.isConfirmed) return;

            this.$set(this.updatingMap, enrollment.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: enrollment.id,
                    updateAction: 'remove',
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const d = payload.data || {};
                    // Remove row from students list
                    this.students = this.students.filter(s => s.id !== enrollment.id);
                    // Decrement count on parent module if was active
                    if (d.was_active) {
                        const mIdx = this.modules.findIndex(m => m.id === (this.selectedModule || {}).id);
                        if (mIdx !== -1) {
                            this.$set(this.modules, mIdx, {
                                ...this.modules[mIdx],
                                enrolled_count: Math.max(0, (this.modules[mIdx].enrolled_count || 1) - 1),
                            });
                        }
                    }
                    this.showMessage('success', d.message || 'Estudiante desvinculado.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo desvincular.');
                }
            } catch (e) {
                console.error('Error removing enrollment:', e);
                this.showMessage('error', 'Error al desvincular al estudiante.');
            } finally {
                this.$delete(this.updatingMap, enrollment.id);
            }
        },

        async markCompleted(enrollment) {
            // Load the module's gradable activities to decide the flow.
            this.$set(this.updatingMap, enrollment.id, true);
            let activities = [];
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_get_module_activities',
                    sesskey,
                    enrollmentId: enrollment.id,
                }});
                activities = ((res.data || {}).data || {}).activities || [];
            } catch (e) {
                console.error('Error loading module activities:', e);
                this.$delete(this.updatingMap, enrollment.id);
                this.showMessage('error', 'No se pudieron cargar las actividades del módulo.');
                return;
            }
            this.$delete(this.updatingMap, enrollment.id);

            // Single activity already graded → confirm and complete directly.
            if (activities.length === 1 && activities[0].current_grade !== null) {
                const confirmed = await window.Swal.fire({
                    title:             'Marcar como completado',
                    html:              `¿Marcar a <b>${enrollment._fullname}</b> como completado en este módulo?<br>` +
                                       `<small class="grey--text">Nota actual: <b>${activities[0].current_grade}/100</b></small>`,
                    icon:              'question',
                    showCancelButton:  true,
                    confirmButtonText: 'Sí, completado',
                    cancelButtonText:  'Cancelar',
                    confirmButtonColor:'#4CAF50',
                });
                if (!confirmed.isConfirmed) return;
                await this._sendComplete(enrollment, null);
                return;
            }

            // No gradable activities configured.
            if (activities.length === 0) {
                this.showMessage('error', 'El módulo no tiene actividades calificables configuradas.');
                return;
            }

            // Single activity without a grade, or multiple activities → open grade dialog.
            const equalW = Math.round((100 / activities.length) * 100) / 100;
            this.gradeEnrollment = enrollment;
            this.gradeActivities = activities.map(a => ({
                itemid: a.itemid,
                name:   a.name,
                grade:  a.current_grade !== null ? a.current_grade : '',
                weight: equalW,
            }));
            // Absorb rounding drift on the last item so weights sum to exactly 100%.
            if (this.gradeActivities.length > 1) {
                const last = this.gradeActivities.length - 1;
                const drift = 100 - this.gradeActivities.reduce((s, a) => s + a.weight, 0);
                this.gradeActivities[last].weight =
                    Math.round((this.gradeActivities[last].weight + drift) * 100) / 100;
            }
            this.gradeDialog = true;
        },

        async confirmGrades() {
            if (!this.gradeCanSave || !this.gradeEnrollment) return;
            this.savingGrade = true;
            const payload = this.gradeActivities.map(a => ({
                itemid: a.itemid,
                grade:  Number(a.grade),
                weight: this.gradeIsSingle ? 100 : (Number(a.weight) || 0),
            }));
            const ok = await this._sendComplete(this.gradeEnrollment, payload);
            this.savingGrade = false;
            if (ok) {
                this.gradeDialog     = false;
                this.gradeEnrollment = null;
                this.gradeActivities = [];
            }
        },

        async _sendComplete(enrollment, gradesPayload) {
            this.$set(this.updatingMap, enrollment.id, true);
            try {
                const params = {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: enrollment.id,
                    updateAction: 'complete',
                };
                if (gradesPayload) params.grades = JSON.stringify(gradesPayload);
                const res = await window.axios.get(ajaxUrl, { params });
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const idx = this.students.findIndex(s => s.id === enrollment.id);
                    if (idx !== -1) {
                        this.$set(this.students, idx, { ...this.students[idx], status: 'completed' });
                    }
                    // Refresh enrolled count on parent module
                    const mIdx = this.modules.findIndex(m => m.id === (this.selectedModule || {}).id);
                    if (mIdx !== -1) {
                        this.$set(this.modules, mIdx, {
                            ...this.modules[mIdx],
                            enrolled_count: Math.max(0, (this.modules[mIdx].enrolled_count || 1) - 1),
                        });
                    }
                    this.showMessage('success', ((payload.data || {}).message) || 'Inscripción marcada como completada.');
                    return true;
                }
                this.showMessage('error', ((payload.data || payload).message) || 'No se pudo actualizar.');
                return false;
            } catch (e) {
                console.error('Error marking complete:', e);
                this.showMessage('error', 'Error al actualizar la inscripción.');
                return false;
            } finally {
                this.$delete(this.updatingMap, enrollment.id);
            }
        },

        // ── Helpers ───────────────────────────────────────────────────────────
        formatDate(ts) {
            if (!ts) return '—';
            return new Date(ts * 1000).toLocaleDateString('es-PA', {
                day: '2-digit', month: '2-digit', year: 'numeric',
            });
        },

        daysColor(days) {
            if (days <= 0)  return 'grey';
            if (days <= 7)  return 'red darken-1';
            if (days <= 15) return 'orange darken-1';
            return 'green darken-1';
        },

        statusLabel(status) {
            const map = { active: 'Activo', completed: 'Completado', expired: 'Vencido' };
            return map[status] || status;
        },

        statusColor(status) {
            const map = { active: 'teal', completed: 'green', expired: 'red' };
            return map[status] || 'grey';
        },

        showMessage(type, text) {
            window.Swal.fire({ icon: type, text, toast: true, position: 'top-end',
                showConfirmButton: false, timer: 4000 });
        },

        // ── Module invoice requests (Solicitudes tab) ─────────────────────────
        async loadRequests() {
            this.loadingRequests = true;
            this.requests = [];
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_module_requests',
                    sesskey,
                    statusFilter: this.requestStatusFilter || '',
                    userSearch:   this.requestSearch || '',
                }});
                const raw = ((res.data || {}).data) || [];
                this.requests = (Array.isArray(raw) ? raw : []).map(r => Object.assign({}, r, {
                    _payment_state: r.payment_state || 'unpaid',
                    _status:        r.status || 'pending_payment',
                }));
            } catch (e) {
                console.error('Error loading module requests:', e);
                this.showMessage('error', 'Error al cargar solicitudes de módulo.');
            } finally {
                this.loadingRequests = false;
            }
        },
        async refreshPayment(request) {
            this.$set(this.verifyingMap, request.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_refresh_module_payment',
                    sesskey,
                    requestId: request.id,
                }});
                const payload = res.data || {};
                const d = (payload.data) ? payload.data : payload;
                if (payload.status === 'success' && d && d.ok) {
                    const idx = this.requests.findIndex(r => r.id === request.id);
                    if (idx !== -1) {
                        const current = this.requests[idx];
                        this.$set(this.requests, idx, Object.assign({}, current, {
                            payment_state:  d.payment_state || current.payment_state,
                            status:         d.request_status || current.status,
                            invoice_id:     d.invoice_id || current.invoice_id,
                            invoice_number: d.invoice_number || current.invoice_number,
                            _payment_state: d.payment_state || current.payment_state,
                            _status:        d.request_status || current.status,
                        }));
                    }
                    this.showMessage(d.paid ? 'success' : 'info', d.message || '');
                } else {
                    this.showMessage('error', (d && d.message) || 'No se pudo verificar el pago.');
                }
            } catch (e) {
                console.error('Error refreshing payment:', e);
                this.showMessage('error', 'Error al verificar el pago.');
            } finally {
                this.$delete(this.verifyingMap, request.id);
            }
        },
        async cancelRequest(request) {
            const confirmed = await window.Swal.fire({
                title: 'Cancelar solicitud',
                html: '¿Cancelar la solicitud de <b>' + (request.fullname || '') + '</b> para <b>' + (request.coursename || '') + '</b>?<br><small class="red--text">La factura quedará registrada pero no podrá usarse para inscribir al estudiante.</small>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'Volver',
                confirmButtonColor: '#D32F2F',
            });
            if (!confirmed.isConfirmed) return;
            this.$set(this.cancellingMap, request.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_cancel_module_request',
                    sesskey,
                    requestId: request.id,
                }});
                const payload = res.data || {};
                const d = (payload.data) ? payload.data : payload;
                if (payload.status === 'success' && d && d.ok) {
                    const idx = this.requests.findIndex(r => r.id === request.id);
                    if (idx !== -1) {
                        const current = this.requests[idx];
                        this.$set(this.requests, idx, Object.assign({}, current, {
                            status:  'cancelled',
                            _status: 'cancelled',
                        }));
                    }
                    this.showMessage('success', 'Solicitud cancelada.');
                } else {
                    this.showMessage('error', (d && d.error) || 'No se pudo cancelar.');
                }
            } catch (e) {
                console.error('Error cancelling request:', e);
                this.showMessage('error', 'Error al cancelar la solicitud.');
            } finally {
                this.$delete(this.cancellingMap, request.id);
            }
        },
        async confirmEnroll(request) {
            const sw = await window.Swal.fire({
                title: 'Inscribir módulo',
                html: '¿Inscribir a <b>' + (request.fullname || '') + '</b> en el módulo de <b>'
                    + (request.coursename || '') + '</b> (<i>'
                    + (request.module_type_label || request.module_type || '') + '</i>)?<br>'
                    + '<small class="grey--text">La factura '
                    + (request.invoice_number || '(sin número)') + ' ya está pagada.</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, inscribir',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#4CAF50',
            });
            if (!sw.isConfirmed) return;

            this.$set(this.enrollingMap, request.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_enroll_module',
                    sesskey,
                    userId: Number(request.userid),
                    coreCourseId: Number(request.corecourseid),
                    learningPlanId: Number(request.learningplanid || 0),
                }});
                const payload = res.data || {};
                const data = (payload.data) ? payload.data : payload;
                if (data && (data.status === 'ok' || data.status === 'warning')) {
                    const idx = this.requests.findIndex(r => r.id === request.id);
                    if (idx !== -1) {
                        const current = this.requests[idx];
                        this.$set(this.requests, idx, Object.assign({}, current, {
                            status:  'enrolled',
                            _status: 'enrolled',
                        }));
                    }
                    this.showMessage(
                        data.status === 'ok' ? 'success' : 'warning',
                        data.message || 'Inscripción procesada.'
                    );
                } else {
                    this.showMessage(
                        'error',
                        (data && data.message) || 'No se pudo inscribir al estudiante.'
                    );
                }
            } catch (e) {
                console.error('Error enrolling from module_management:', e);
                this.showMessage('error', 'Error al inscribir al estudiante.');
            } finally {
                this.$delete(this.enrollingMap, request.id);
            }
        },
        requestPaymentStateColor(state) {
            return state === 'paid' ? 'green darken-2' : 'orange darken-2';
        },
        requestPaymentStateLabel(state) {
            return state === 'paid' ? 'Pagado' : 'Pendiente';
        },
        requestStatusColor(status) {
            const map = {
                pending_payment: 'orange',
                paid:            'green',
                enrolled:        'teal',
                expired:         'red darken-2',
                cancelled:       'grey',
            };
            return map[status] || 'grey';
        },
        requestStatusLabel(status) {
            const map = {
                pending_payment: 'Esperando pago',
                paid:            'Pagado — listo para inscribir',
                enrolled:        'Inscrito',
                expired:         'Expirado',
                cancelled:       'Cancelado',
            };
            return map[status] || status;
        },
        formatAmount(amount) {
            const n = Number(amount || 0);
            if (!isFinite(n) || n <= 0) return '—';
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(n);
            } catch (e) { return String(n); }
        },
    },

    template: `
<v-container fluid class="pa-4">

  <!-- Header -->
  <v-row class="mb-2" align="center">
    <v-col>
      <div class="d-flex align-center">
        <v-icon large color="teal darken-2" class="mr-2">mdi-book-education-outline</v-icon>
        <span class="text-h5 font-weight-bold">Gestión de Módulos Independientes</span>
        <mm-info :text="help.page" color="teal darken-2"></mm-info>
      </div>
      <div class="text-caption grey--text mt-1">
        Administre los módulos de estudio autónomo: consulte inscritos, revise sus entregas, califique, extienda plazos y marque completados.
      </div>
    </v-col>
  </v-row>

  <!-- ── Students dialog ───────────────────────────────────────────────── -->
  <v-dialog v-model="studentsDialog" max-width="1150" scrollable>
    <v-card>
      <v-card-title class="teal darken-2 white--text">
        <v-icon left dark>mdi-account-group</v-icon>
        <span v-if="selectedModule">
          Estudiantes — {{ selectedModule.coursename }}
          <v-chip x-small class="ml-2" outlined dark>{{ selectedModule.periodcode }}</v-chip>
        </span>
        <mm-info color="white" text="Lista de inscritos del módulo. Los que ya enviaron la actividad y esperan nota aparecen primero, en naranja. Pulse &quot;Revisar&quot; para ver lo que entregó, calificarlo y completarlo."></mm-info>
        <v-spacer></v-spacer>
        <v-btn icon dark @click="studentsDialog = false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>

      <v-card-text class="pa-0">
        <v-data-table
          :headers="studentHeaders"
          :items="students"
          :loading="loadingStudents"
          loading-text="Cargando estudiantes..."
          no-data-text="No hay estudiantes inscritos en este módulo."
          :footer-props="{ 'items-per-page-options': [10, 25, 50] }"
          sort-by="_submissionorder"
          dense
        >
          <template v-slot:header.duedate="{ header }">{{ header.text }}<mm-info :text="help.due"></mm-info></template>
          <template v-slot:header._daysremaining="{ header }">{{ header.text }}<mm-info :text="help.days"></mm-info></template>
          <template v-slot:header.status="{ header }">{{ header.text }}<mm-info :text="help.status"></mm-info></template>
          <template v-slot:header._submissionorder="{ header }">{{ header.text }}<mm-info :text="help.submission"></mm-info></template>
          <template v-slot:header._actions="{ header }">{{ header.text }}<mm-info :text="help.studentActions"></mm-info></template>

          <!-- Entrega -->
          <template v-slot:item._submissionorder="{ item }">
            <v-progress-circular v-if="item._submission === 'loading'" indeterminate size="14" width="2" color="grey"></v-progress-circular>
            <template v-else>
              <v-chip x-small :color="submissionColor(item)" dark>{{ submissionLabel(item) }}</v-chip>
              <div v-if="item._lastsubmission" class="text-caption grey--text">{{ formatDateTime(item._lastsubmission) }}</div>
            </template>
          </template>
          <!-- Student name -->
          <template v-slot:item._fullname="{ item }">
            <span class="font-weight-medium">{{ item._fullname }}</span>
            <div class="text-caption grey--text">{{ item.email }}</div>
          </template>

          <!-- Enrollment date -->
          <template v-slot:item.enrolldate="{ item }">
            {{ formatDate(item.enrolldate) }}
          </template>

          <!-- Due date -->
          <template v-slot:item.duedate="{ item }">
            {{ formatDate(item.duedate) }}
          </template>

          <!-- Days remaining -->
          <template v-slot:item._daysremaining="{ item }">
            <v-chip x-small :color="daysColor(item._daysremaining)" dark v-if="item.status === 'active'">
              {{ item._daysremaining }} días
            </v-chip>
            <span v-else class="grey--text">—</span>
          </template>

          <!-- Status chip -->
          <template v-slot:item.status="{ item }">
            <v-chip x-small :color="statusColor(item.status)" dark>{{ statusLabel(item.status) }}</v-chip>
          </template>

          <!-- Row actions -->
          <template v-slot:item._actions="{ item }">
            <div style="white-space:nowrap">
              <v-btn x-small :color="item._submission === 'pending' ? 'orange darken-2' : 'blue darken-2'" dark
                class="mr-1"
                :disabled="!!updatingMap[item.id]"
                @click="openReview(item)"
                title="Ver la entrega, calificar y completar"
              >
                <v-icon x-small left>mdi-file-eye-outline</v-icon> Revisar
              </v-btn>
              <template v-if="item.status === 'active'">
                <v-btn x-small outlined color="orange darken-2"
                  class="mr-1"
                  :disabled="!!updatingMap[item.id]"
                  @click="openExtendDialog(item)"
                  title="Extender plazo"
                >
                  <v-icon x-small left>mdi-clock-plus-outline</v-icon> Extender
                </v-btn>
                <v-btn x-small outlined color="green darken-2"
                  class="mr-1"
                  :loading="!!updatingMap[item.id]"
                  @click="markCompleted(item)"
                  title="Marcar como completado"
                >
                  <v-icon x-small left>mdi-check-circle-outline</v-icon> Completado
                </v-btn>
              </template>
              <v-btn x-small outlined color="red darken-2"
                :loading="!!updatingMap[item.id]"
                :disabled="!!updatingMap[item.id]"
                @click="removeEnrollment(item)"
                title="Desvincular del módulo"
              >
                <v-icon x-small left>mdi-account-remove-outline</v-icon> Desvincular
              </v-btn>
            </div>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>
  </v-dialog>

  <!-- ── Revisar entrega, calificar y completar ─────────────────────────── -->
  <v-dialog v-model="reviewDialog" max-width="820" persistent scrollable>
    <v-card>
      <v-card-title class="blue darken-2 white--text">
        <v-icon left dark>mdi-file-eye-outline</v-icon>
        Revisar entrega
        <mm-info color="white" text="Aquí ve lo que entregó el estudiante, pone la nota y el comentario. Puede solo guardar la calificación o guardarla y completar el módulo en el mismo paso."></mm-info>
        <v-spacer></v-spacer>
        <v-btn icon dark @click="reviewDialog = false" :disabled="savingReview"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>

      <v-card-text class="pt-4">
        <div v-if="loadingReview" class="text-center py-8">
          <v-progress-circular indeterminate color="blue darken-2"></v-progress-circular>
        </div>
        <template v-else-if="reviewDetail">
          <div class="mb-3">
            Estudiante: <strong>{{ reviewDetail.fullname }}</strong>
            <span class="grey--text text-caption ml-1">{{ reviewDetail.email }}</span>
            <v-chip x-small class="ml-2" :color="statusColor(reviewDetail.status)" dark>{{ statusLabel(reviewDetail.status) }}</v-chip>
            <div class="text-caption grey--text">Fecha límite: {{ formatDate(reviewDetail.duedate) }}</div>
          </div>

          <v-alert v-if="!reviewDetail.activities.length" dense outlined type="warning">
            El módulo no tiene actividades calificables configuradas.
          </v-alert>

          <v-card v-for="(a, i) in reviewDetail.activities" :key="a.itemid" outlined class="mb-3">
            <v-card-subtitle class="pb-1 d-flex align-center flex-wrap">
              <span class="font-weight-bold black--text mr-2">{{ a.name }}</span>
              <v-chip x-small :color="activityStatusColor(a)" dark class="mr-2">{{ activityStatusLabel(a) }}</v-chip>
              <span v-if="a.submittedat && a.submission !== 'new'" class="text-caption">
                {{ a.submission === 'submitted' ? 'Enviado' : 'Modificado' }}: {{ formatDateTime(a.submittedat) }}
              </span>
              <v-spacer></v-spacer>
              <v-btn v-if="a.gradeurl" x-small text color="blue darken-2" :href="a.gradeurl" target="_blank">
                <v-icon x-small left>mdi-open-in-new</v-icon> Abrir en Moodle
              </v-btn>
              <mm-info v-if="a.gradeurl" :text="help.reviewMoodle"></mm-info>
            </v-card-subtitle>

            <v-card-text class="pt-1">
              <div class="text-caption font-weight-medium mb-1">
                Archivos entregados <mm-info :text="help.reviewFiles"></mm-info>
              </div>
              <div v-if="!a.files.length && !a.onlinetext" class="text-caption grey--text mb-2">
                {{ a.module === 'assign' ? 'El estudiante no ha subido archivos.' : 'Esta actividad no recibe archivos; solo se califica.' }}
              </div>
              <v-list v-if="a.files.length" dense class="py-0 mb-2">
                <v-list-item v-for="f in a.files" :key="f.id" class="px-1" style="min-height:30px">
                  <v-icon small class="mr-2" color="blue-grey">{{ fileIcon(f.mimetype) }}</v-icon>
                  <a :href="f.url" target="_blank" class="text-body-2" style="text-decoration:none">{{ f.filename }}</a>
                  <span class="text-caption grey--text ml-2">{{ formatSize(f.filesize) }}</span>
                  <v-btn icon x-small class="ml-1" :href="f.url + '&download=1'" title="Descargar">
                    <v-icon x-small>mdi-download</v-icon>
                  </v-btn>
                </v-list-item>
              </v-list>
              <div v-if="a.onlinetext" class="pa-2 mb-2 grey lighten-4 rounded text-body-2" v-html="a.onlinetext"></div>

              <v-row dense align="start">
                <v-col cols="12" sm="3">
                  <v-text-field v-model="reviewForm[i].grade" type="number" min="0" max="100" step="0.01"
                    label="Nota (0–100)" outlined dense hide-details="auto"
                    :rules="[v => v === '' || v === null || (Number(v) >= 0 && Number(v) <= 100) || 'Entre 0 y 100']">
                    <template v-slot:append-outer><mm-info :text="help.reviewGrade"></mm-info></template>
                  </v-text-field>
                </v-col>
                <v-col cols="12" sm="3" v-if="reviewIsMulti">
                  <v-text-field v-model.number="reviewForm[i].weight" type="number" min="0" max="100" step="0.01"
                    label="Ponderación" suffix="%" outlined dense hide-details>
                    <template v-slot:append-outer><mm-info :text="help.reviewWeight"></mm-info></template>
                  </v-text-field>
                </v-col>
                <v-col cols="12" :sm="reviewIsMulti ? 6 : 9">
                  <v-textarea v-model="reviewForm[i].feedback" label="Comentario para el estudiante (opcional)"
                    outlined dense rows="2" auto-grow hide-details>
                    <template v-slot:append-outer><mm-info :text="help.reviewFeedback"></mm-info></template>
                  </v-textarea>
                </v-col>
              </v-row>
              <div v-if="a.overridden" class="text-caption orange--text mt-1">
                La nota actual fue puesta directamente en el libro de calificaciones; al guardar se reemplaza por la nueva.
              </div>
            </v-card-text>
          </v-card>

          <div v-if="reviewIsMulti" class="text-caption">
            Suma de ponderaciones:
            <strong :class="Math.abs(reviewWeightSum - 100) < 0.01 ? 'green--text' : 'red--text'">{{ reviewWeightSum.toFixed(2) }}%</strong>
          </div>
          <v-chip v-if="reviewFinalPreview !== null" color="green darken-2" dark label class="mt-2">
            Nota final del módulo: <strong class="ml-1">{{ reviewFinalPreview }}/100</strong>
          </v-chip>
        </template>
      </v-card-text>

      <v-card-actions>
        <mm-info :text="help.reviewSave"></mm-info>
        <v-spacer></v-spacer>
        <v-btn text @click="reviewDialog = false" :disabled="savingReview">Cancelar</v-btn>
        <v-btn outlined color="blue darken-2" :loading="savingReview"
          :disabled="loadingReview || !reviewGradesValid || !reviewAnyGrade" @click="saveReview(false)">
          <v-icon small left>mdi-content-save-outline</v-icon> Guardar calificación
        </v-btn>
        <v-btn v-if="reviewDetail && reviewDetail.status === 'active'" color="green darken-2" dark :loading="savingReview"
          :disabled="loadingReview || !reviewCanComplete" @click="saveReview(true)">
          <v-icon small left>mdi-check-circle-outline</v-icon> Guardar y completar
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Extend deadline dialog ─────────────────────────────────────────── -->
  <v-dialog v-model="extendDialog" max-width="420">
    <v-card>
      <v-card-title>Extender plazo <mm-info text="Escriba cuántos días agregar. Se suman a la fecha límite actual del estudiante, no a la fecha de hoy."></mm-info></v-card-title>
      <v-card-text>
        <div class="mb-3" v-if="selectedEnrollment">
          Estudiante: <strong>{{ selectedEnrollment._fullname }}</strong><br>
          Fecha límite actual: <strong>{{ formatDate(selectedEnrollment.duedate) }}</strong>
        </div>
        <v-text-field
          v-model.number="extendDays"
          label="Días adicionales"
          type="number"
          min="1"
          max="365"
          outlined
          dense
          suffix="días"
        ></v-text-field>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="extendDialog = false" :disabled="savingExtend">Cancelar</v-btn>
        <v-btn color="orange darken-2" dark :loading="savingExtend" @click="confirmExtend">
          Extender plazo
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Grade entry dialog ─────────────────────────────────────────────── -->
  <v-dialog v-model="gradeDialog" max-width="640" persistent scrollable>
    <v-card>
      <v-card-title class="green darken-2 white--text">
        <v-icon left dark>mdi-check-circle-outline</v-icon>
        Calificar y completar
        <mm-info color="white" :text="help.gradeDialog"></mm-info>
        <v-spacer></v-spacer>
        <v-btn icon dark @click="gradeDialog = false" :disabled="savingGrade"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>

      <v-card-text class="pt-4">
        <div class="mb-3" v-if="gradeEnrollment">
          Estudiante: <strong>{{ gradeEnrollment._fullname }}</strong>
        </div>
        <div class="text-caption grey--text mb-2" v-if="gradeIsSingle">
          Ingrese la nota (0–100) de la actividad. Se guardará en el libro de calificaciones.
        </div>
        <div class="text-caption grey--text mb-2" v-else>
          Ingrese la nota (0–100) y la ponderación (%) de cada actividad. Las ponderaciones deben sumar 100%.
        </div>

        <v-simple-table dense>
          <template v-slot:default>
            <thead>
              <tr>
                <th class="text-left">Actividad</th>
                <th class="text-center" style="width:120px">Nota (0–100)</th>
                <th class="text-center" style="width:130px" v-if="!gradeIsSingle">Ponderación (%)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(a, i) in gradeActivities" :key="a.itemid">
                <td>{{ a.name }}</td>
                <td class="text-center">
                  <v-text-field
                    v-model.number="a.grade"
                    type="number" min="0" max="100" step="0.01"
                    dense outlined hide-details class="centered-input"
                  ></v-text-field>
                </td>
                <td class="text-center" v-if="!gradeIsSingle">
                  <v-text-field
                    v-model.number="a.weight"
                    type="number" min="0" max="100" step="0.01"
                    dense outlined hide-details suffix="%" class="centered-input"
                  ></v-text-field>
                </td>
              </tr>
            </tbody>
          </template>
        </v-simple-table>

        <v-row class="mt-3" align="center" no-gutters v-if="!gradeIsSingle">
          <v-col>
            <span class="text-caption">Suma ponderaciones:
              <strong :class="gradeWeightValid ? 'green--text' : 'red--text'">
                {{ gradeWeightSum.toFixed(2) }}%
              </strong>
            </span>
          </v-col>
        </v-row>

        <v-alert v-if="!gradeWeightValid && !gradeIsSingle" dense outlined type="warning" class="mt-2 mb-0">
          Las ponderaciones deben sumar 100%.
        </v-alert>

        <div class="mt-3">
          <v-chip color="green darken-2" dark label>
            Nota final: <strong class="ml-1">{{ gradeFinalPreview !== null ? gradeFinalPreview : '—' }}/100</strong>
          </v-chip>
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="gradeDialog = false" :disabled="savingGrade">Cancelar</v-btn>
        <v-btn color="green darken-2" dark :loading="savingGrade" :disabled="!gradeCanSave" @click="confirmGrades">
          Guardar y completar
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Tab navigation ─────────────────────────────────────────────────── -->
  <v-card outlined class="mt-4">
    <v-tabs v-model="activeTab" background-color="transparent" color="teal darken-2">
      <v-tab>
        <v-icon left small>mdi-book-multiple</v-icon> Módulos
        <v-chip v-if="totalPending > 0" x-small color="orange darken-2" dark class="ml-2"
          title="Entregas esperando calificación">{{ totalPending }}</v-chip>
      </v-tab>
      <v-tab @click="loadRequests">
        <v-icon left small>mdi-receipt-text-outline</v-icon> Solicitudes de módulo
      </v-tab>
    </v-tabs>

    <v-tabs-items v-model="activeTab">
      <!-- Módulos tab: filters + table -->
      <v-tab-item>
        <v-card flat>
          <!-- Filters -->
          <v-card-text>
            <v-row align="center" class="mb-2">
              <v-col cols="12" sm="4" md="3">
                <v-select
                  v-model="selectedPeriodId"
                  :items="periodItems"
                  item-text="label"
                  item-value="id"
                  label="Período académico"
                  outlined
                  dense
                  hide-details
                  @change="loadModules"
                >
                  <template v-slot:append-outer><mm-info :text="help.period"></mm-info></template>
                </v-select>
              </v-col>
              <v-col cols="12" sm="5" md="4">
                <v-text-field
                  v-model="moduleSearch"
                  prepend-inner-icon="mdi-magnify"
                  label="Buscar asignatura..."
                  outlined
                  dense
                  hide-details
                  clearable
                >
                  <template v-slot:append-outer><mm-info :text="help.search"></mm-info></template>
                </v-text-field>
              </v-col>
              <v-col cols="12" sm="3" class="d-flex align-center">
                <v-switch v-model="onlyPending" color="orange darken-2" dense hide-details class="mt-0"
                  :label="'Solo con entregas por calificar' + (totalPending ? ' (' + totalPending + ')' : '')"></v-switch>
                <mm-info :text="help.onlyPending"></mm-info>
              </v-col>
              <v-col class="text-right">
                <v-btn small outlined color="teal darken-2" @click="loadModules" :loading="loadingModules">
                  <v-icon small left>mdi-refresh</v-icon> Actualizar
                </v-btn>
                <mm-info :text="help.refresh"></mm-info>
              </v-col>
            </v-row>
          </v-card-text>

          <!-- Modules table -->
          <v-data-table
            :headers="moduleHeaders"
            :items="visibleModules"
            :loading="loadingModules"
            :search="moduleSearch"
            loading-text="Cargando módulos..."
            no-data-text="No hay módulos registrados para el período seleccionado."
            :footer-props="{ 'items-per-page-options': [10, 25, 50] }"
            dense
          >
            <template v-slot:header.name="{ header }">{{ header.text }}<mm-info :text="help.group"></mm-info></template>
            <template v-slot:header.module_deadline_days="{ header }">{{ header.text }}<mm-info :text="help.deadline"></mm-info></template>
            <template v-slot:header.enrolled_count="{ header }">{{ header.text }}<mm-info :text="help.enrolled"></mm-info></template>
            <template v-slot:header._pending="{ header }">{{ header.text }}<mm-info :text="help.pending"></mm-info></template>
            <template v-slot:header._actions="{ header }">{{ header.text }}<mm-info :text="help.moduleActions"></mm-info></template>

            <!-- Por calificar -->
            <template v-slot:item._pending="{ item }">
              <v-chip v-if="item._pending > 0" x-small color="orange darken-2" dark style="cursor:pointer"
                @click="openStudents(item)" title="Abrir estudiantes para calificar">
                <v-icon x-small left>mdi-file-clock-outline</v-icon>{{ item._pending }}
              </v-chip>
              <span v-else class="grey--text">—</span>
            </template>
            <!-- Asignatura -->
            <template v-slot:item.coursename="{ item }">
              <span class="font-weight-medium">{{ item.coursename }}</span>
            </template>

            <!-- Período -->
            <template v-slot:item.periodcode="{ item }">
              <v-chip x-small outlined color="teal">{{ item.periodcode }}</v-chip>
            </template>

            <!-- Grupo Moodle — link to group members page -->
            <template v-slot:item.name="{ item }">
              <a
                v-if="item.groupid"
                :href="wwwroot + '/group/members.php?group=' + item.groupid"
                target="_blank"
                class="teal--text text--darken-2"
                style="text-decoration:none; display:inline-flex; align-items:center; gap:4px;"
                title="Ver grupo en Moodle"
              >
                <v-icon x-small color="teal darken-2">mdi-open-in-new</v-icon>
                {{ item.name }}
              </a>
              <span v-else>{{ item.name }}</span>
            </template>

            <!-- Plazo días -->
            <template v-slot:item.module_deadline_days="{ item }">
              <span>{{ item.module_deadline_days }} días</span>
            </template>

            <!-- Inscritos -->
            <template v-slot:item.enrolled_count="{ item }">
              <v-chip x-small :color="item.enrolled_count > 0 ? 'teal darken-2' : 'grey'" dark>
                {{ item.enrolled_count }}
              </v-chip>
            </template>

            <!-- Acciones -->
            <template v-slot:item._actions="{ item }">
              <div style="white-space:nowrap">
                <v-btn x-small color="teal darken-2" dark class="mr-1"
                  :disabled="deletingModuleId === item.id"
                  @click="openStudents(item)" title="Ver estudiantes inscritos">
                  <v-icon x-small left>mdi-account-group</v-icon> Estudiantes
                </v-btn>
                <v-btn x-small outlined color="red darken-2"
                  :loading="deletingModuleId === item.id"
                  :disabled="!!deletingModuleId && deletingModuleId !== item.id"
                  @click="deleteModule(item)" title="Eliminar módulo y grupo">
                  <v-icon x-small left>mdi-delete-outline</v-icon> Eliminar
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>
      </v-tab-item>

      <!-- Solicitudes tab -->
      <v-tab-item>
        <v-card flat>
          <v-card-text>
            <v-row align="center" class="mb-2">
              <v-col cols="12" sm="4" md="3">
                <v-select
                  v-model="requestStatusFilter"
                  :items="[
                    { text: 'Todos los estados',   value: '' },
                    { text: 'Esperando pago',      value: 'pending_payment' },
                    { text: 'Pagado (por inscribir)', value: 'paid' },
                    { text: 'Inscrito',            value: 'enrolled' },
                    { text: 'Expirado',            value: 'expired' },
                    { text: 'Cancelado',           value: 'cancelled' },
                  ]"
                  item-text="text"
                  item-value="value"
                  label="Estado"
                  outlined dense hide-details
                  @change="loadRequests"
                >
                  <template v-slot:append-outer><mm-info :text="help.reqStatusFilter"></mm-info></template>
                </v-select>
              </v-col>
              <v-col cols="12" sm="5" md="5">
                <v-text-field
                  v-model="requestSearch"
                  prepend-inner-icon="mdi-magnify"
                  label="Buscar estudiante (nombre o email)"
                  outlined dense hide-details clearable
                  @keyup.enter="loadRequests"
                >
                  <template v-slot:append-outer><mm-info :text="help.reqSearch"></mm-info></template>
                </v-text-field>
              </v-col>
              <v-col cols="auto" class="text-right">
                <v-btn small outlined color="teal darken-2" @click="loadRequests" :loading="loadingRequests">
                  <v-icon small left>mdi-refresh</v-icon> Actualizar
                </v-btn>
              </v-col>
            </v-row>

            <v-data-table
              :headers="requestHeaders"
              :items="requests"
              :loading="loadingRequests"
              :search="requestSearch"
              loading-text="Cargando solicitudes..."
              no-data-text="No hay solicitudes de módulo registradas."
              :footer-props="{ 'items-per-page-options': [10, 25, 50] }"
              dense
            >
              <template v-slot:header._payment_state="{ header }">{{ header.text }}<mm-info :text="help.reqPayment"></mm-info></template>
              <template v-slot:header._status="{ header }">{{ header.text }}<mm-info :text="help.reqStatus"></mm-info></template>
              <template v-slot:header.expires_at="{ header }">{{ header.text }}<mm-info :text="help.reqExpires"></mm-info></template>
              <template v-slot:header._actions="{ header }">{{ header.text }}<mm-info :text="help.reqActions"></mm-info></template>
              <!-- Estudiante -->
              <template v-slot:item.fullname="{ item }">
                <div class="font-weight-medium">{{ item.fullname }}</div>
                <div class="text-caption grey--text">{{ item.email }}</div>
              </template>

              <!-- Asignatura -->
              <template v-slot:item.coursename="{ item }">
                <span class="font-weight-medium">{{ item.coursename }}</span>
              </template>

              <!-- Tipo -->
              <template v-slot:item.module_type_label="{ item }">
                <v-chip x-small outlined :color="item.module_type === 'materias_especializadas' ? 'deep-purple' : 'teal'">
                  {{ item.module_type_label || item.module_type }}
                </v-chip>
              </template>

              <!-- Factura -->
              <template v-slot:item.invoice_number="{ item }">
                <span v-if="item.invoice_number">{{ item.invoice_number }}</span>
                <span v-else class="grey--text">—</span>
              </template>

              <!-- Monto -->
              <template v-slot:item.amount="{ item }">
                <span class="font-weight-medium">{{ formatAmount(item.amount) }}</span>
              </template>

              <!-- Estado de pago -->
              <template v-slot:item._payment_state="{ item }">
                <v-chip x-small :color="requestPaymentStateColor(item._payment_state)" dark>
                  {{ requestPaymentStateLabel(item._payment_state) }}
                </v-chip>
              </template>

              <!-- Estado de solicitud -->
              <template v-slot:item._status="{ item }">
                <v-chip x-small :color="requestStatusColor(item._status)" dark>
                  {{ requestStatusLabel(item._status) }}
                </v-chip>
              </template>

              <!-- Expira -->
              <template v-slot:item.expires_at="{ item }">
                <span v-if="item.expires_at">{{ formatDate(item.expires_at) }}</span>
                <span v-else class="grey--text">—</span>
              </template>

              <!-- Acciones -->
              <template v-slot:item._actions="{ item }">
                <div style="white-space:nowrap">
                  <v-btn v-if="item._status === 'pending_payment'"
                    x-small color="orange darken-2" dark class="mr-1"
                    :loading="!!verifyingMap[item.id]"
                    :disabled="!!verifyingMap[item.id] || !!cancellingMap[item.id] || !!enrollingMap[item.id]"
                    @click="refreshPayment(item)" title="Consultar estado de pago en Odoo">
                    <v-icon x-small left>mdi-refresh</v-icon> Verificar pago
                  </v-btn>
                  <v-btn v-if="item._status === 'pending_payment'"
                    x-small outlined color="red darken-2"
                    :loading="!!cancellingMap[item.id]"
                    :disabled="!!cancellingMap[item.id] || !!verifyingMap[item.id] || !!enrollingMap[item.id]"
                    @click="cancelRequest(item)" title="Cancelar esta solicitud">
                    <v-icon x-small left>mdi-close-circle-outline</v-icon> Cancelar
                  </v-btn>
                  <v-btn v-if="item._status === 'paid'"
                    x-small color="green darken-2" dark class="mr-1"
                    :loading="!!enrollingMap[item.id]"
                    :disabled="!!enrollingMap[item.id] || !!verifyingMap[item.id] || !!cancellingMap[item.id]"
                    @click="confirmEnroll(item)"
                    title="Inscribir al estudiante en el módulo">
                    <v-icon x-small left>mdi-book-education-outline</v-icon> Inscribir
                  </v-btn>
                  <v-chip v-if="item._status === 'enrolled'" x-small color="teal darken-2" dark>Inscrito</v-chip>
                  <v-chip v-if="item._status === 'expired'" x-small color="red darken-2" dark>Expirado</v-chip>
                  <v-chip v-if="item._status === 'cancelled'" x-small color="grey">Cancelado</v-chip>
                </div>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-tab-item>
    </v-tabs-items>
  </v-card>

</v-container>
`,
});
