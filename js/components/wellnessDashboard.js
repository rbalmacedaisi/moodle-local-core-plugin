/**
 * Wellness admin dashboard.
 *
 * Vue 2 component mounted by /local/grupomakro_core/pages/wellness_dashboard.php.
 * Three tabs:
 *   1. Convenios (RF-09.1) — CRUD over gmk_wellness_partner
 *   2. Eventos (RF-09.2) — CRUD over gmk_wellness_event + attachments + registrations export
 *   3. Formularios dinámicos (RF-06) — list of dynamic forms with schema preview
 *
 * The component calls the existing ajax.php dispatcher with the
 * `local_grupomakro_*` actions defined in db/services.php.
 */
Vue.component('wellness-dashboard', {
    data() {
        return {
            tab: 'partners',
            loading: false,
            partnerImage: null,
            eventImage: null,
            imageUploading: false,
            snack: { show: false, color: 'success', text: '' },

            // Partners
            partners: [],
            partnerCategories: [],
            partnerSearch: '',
            partnerDialog: false,
            partnerSaving: false,
            partner: this._blankPartner(),
            // Events
            events: [],
            eventSearch: '',
            eventCategoryFilter: '',
            eventDialog: false,
            eventSaving: false,
            event: this._blankEvent(),
            eventAttachments: [],
            regDialog: false,
            registrationsSearch: '',
            regEvent: null,
            // BBB guest-room state (RF-04): while a request is in flight
            // we disable both buttons to avoid a second click creating a
            // duplicate room (the WS is idempotent but the user would still
            // see two toast messages and a confusing state).
            bbbCreating: false,
            bbbDeleting: false,
            registrations: [],
            // Forms (RF-06 / RF-09.2) — full editor (was read-only until 20261001022)
            forms: [],
            formDialog: false,
            formSaving: false,
            form: this._blankForm(),
            formCoverImage: null,
            // View responses side-panel
            responsesDialog: false,
            responsesLoading: false,
            responsesFormId: 0,
            responsesFormTitle: '',
            responses: [],
            // Carnet admin (RF-07 / RF-09.4)
            carnetUserid: 0,
            carnetUserSearch: '',
            carnetUserOptions: [],
            carnetAction: 'renew',
        };
    },
    computed: {
        filteredPartners() {
            const t = (this.partnerSearch || '').toLowerCase().trim();
            return (this.partners || []).filter(p => {
                if (!t) return true;
                return (p.name || '').toLowerCase().indexOf(t) !== -1
                    || (p.benefit_description || '').toLowerCase().indexOf(t) !== -1;
            });
        },
        filteredEvents() {
            const t = (this.eventSearch || '').toLowerCase().trim();
            const c = (this.eventCategoryFilter || '').trim();
            return (this.events || []).filter(e => {
                if (c && e.category !== c) return false;
                if (!t) return true;
                return (e.title || '').toLowerCase().indexOf(t) !== -1;
            });
        },
        categoryItems() {
            return (this.partnerCategories || []).map(c => ({ text: c.name, value: c.id }));
        },
        eventCategoryItems() {
            return [
                { text: 'Todos', value: '' },
                { text: 'Deportivo', value: 'deportivo' },
                { text: 'Feria', value: 'feria' },
                { text: 'Taller', value: 'taller' },
                { text: 'Charla', value: 'charla' },
                { text: 'Otro', value: 'otro' },
            ];
        },
        // Active events for the form editor's event selector (plus a "ninguno"
        // entry for reusable forms not tied to a specific event).
        eventItems() {
            const opts = [{ text: '(Ninguno — formulario reutilizable)', value: 0 }];
            (this.events || []).forEach(e => {
                if (e.active) {
                    opts.push({ text: e.title, value: e.id });
                }
            });
            return opts;
        },
        // Live preview of the form the admin is editing, rendered through the
        // same shape DynamicFormRenderer.vue uses on the LXP side.
        formPreviewFields() {
            try {
                const parsed = JSON.parse(this.form.schema_json || '{"fields":[]}');
                return Array.isArray(parsed.fields) ? parsed.fields : [];
            } catch (_e) {
                return [];
            }
        },
        formSchemaError() {
            if (!this.form.schema_json) return '';
            try {
                const parsed = JSON.parse(this.form.schema_json);
                if (!parsed || typeof parsed !== 'object') return 'El schema debe ser un objeto JSON';
                if (!Array.isArray(parsed.fields) || parsed.fields.length === 0) {
                    return 'Agrega al menos un campo al schema';
                }
                const seen = {};
                for (const f of parsed.fields) {
                    if (!f.name || !f.label || !f.type) {
                        return 'Cada campo necesita name, label y type';
                    }
                    if (seen[f.name]) return `Nombre de campo duplicado: ${f.name}`;
                    seen[f.name] = true;
                }
                return '';
            } catch (e) {
                return `JSON inválido: ${e.message}`;
            }
        },
        registrationsHeaders() {
            return [
                { text: 'ID',            value: 'id',            width: 70 },
                { text: 'Nombre',        value: 'fullname' },
                { text: 'Usuario',       value: 'username' },
                { text: 'Email',         value: 'email' },
                { text: 'Estado',        value: 'status' },
                { text: 'Modalidad',     value: 'modality', align: 'center', width: 110 },
                { text: 'Inscrito',      value: 'registered_at', width: 170 },
                { text: 'Origen',        value: 'source', align: 'center', width: 110 },
                { text: '',              value: 'actions', sortable: false, align: 'center', width: 50 },
            ];
        },
        filteredRegistrations() {
            // v-data-table applies its own :search filter, but having the
            // computed lets the template access the full list for the
            // status counters above the table.
            return this.registrations || [];
        },
    },
    mounted() {
        this.refreshAll();
    },
    methods: {
        _blankPartner() {
            return {
                id: 0,
                name: '',
                categoryid: 0,
                benefit_description: '',
                conditions: '',
                requirements: '',
                startdate: '',
                enddate: '',
                startdate_ts: 0,
                enddate_ts: 0,
                contact_label: '',
                contact_value: '',
                logo_path: '',
                sort: 0,
                active: true,
            };
        },
        _blankEvent() {
            const now = new Date();
            now.setMinutes(0, 0, 0);
            now.setHours(now.getHours() + 1);
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setMinutes(0, 0, 0);
            tomorrow.setHours(9);
            return {
                id: 0,
                title: '',
                summary: '',
                description: '',
                category: 'otro',
                startdate: now.toISOString().substring(0, 16),
                enddate: tomorrow.toISOString().substring(0, 16),
                startdate_ts: 0,
                enddate_ts: 0,
                modality: 'presencial',
                location: '',
                virtual_url: '',
                bbb_cmid: 0,
                bbb_guest_url: '',
                capacity: 0,
                requires_registration: true,
                allow_waitlist: false,
                registration_opens_ts: 0,
                registration_closes_ts: 0,
                organizer_name: '',
                organizer_email: '',
                cover_path: '',
                active: true,
            };
        },
        _blankForm() {
            return {
                id: 0,
                title: '',
                description: '',
                eventid: 0,
                schema_json: JSON.stringify({ fields: [] }, null, 2),
                cover_path: '',
                active: true,
            };
        },
        // Field catalogue for the schema editor. Order matters: it's the order
        // shown in the v-select inside the field row.
        formFieldTypes() {
            return [
                { text: 'Texto corto', value: 'text' },
                { text: 'Texto largo', value: 'textarea' },
                { text: 'Selección única', value: 'select' },
                { text: 'Selección múltiple', value: 'multiselect' },
                { text: 'Casilla de verificación', value: 'checkbox' },
                { text: 'Número', value: 'number' },
                { text: 'Fecha', value: 'date' },
                { text: 'Correo electrónico', value: 'email' },
            ];
        },
        toast(text, color = 'success') {
            this.snack = { show: true, color, text };
        },
        async refreshAll() {
            await Promise.all([this.refreshPartners(), this.refreshEvents(), this.refreshForms()]);
        },
        async refreshPartners() {
            this.loading = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_list_wellness_partners', {});
                if (res && res.status === 'success' && res.data) {
                    this.partners = res.data.partners || [];
                    this.partnerCategories = res.data.categories || [];
                } else {
                    this.toast('No se pudieron cargar los convenios', 'error');
                }
            } catch (e) {
                this.toast('Error al cargar convenios: ' + (e.message || e), 'error');
            } finally {
                this.loading = false;
            }
        },
        async refreshEvents() {
            this.loading = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_list_wellness_events', {});
                if (res && res.status === 'success' && res.data) {
                    this.events = res.data.events || [];
                }
            } catch (e) {
                this.toast('Error al cargar eventos: ' + (e.message || e), 'error');
            } finally {
                this.loading = false;
            }
        },
        async refreshForms() {
            try {
                const res = await this.callWs('local_grupomakro_admin_list_wellness_dynamic_forms', {});
                if (res && res.status === 'success' && res.data) {
                    this.forms = res.data.forms || [];
                }
            } catch (e) {
                this.toast('Error al cargar formularios: ' + (e.message || e), 'error');
            }
        },

        // -- Forms editor (RF-06 / RF-09.2) ---------------------------------
        // The schema editor builds the JSON manually instead of a JSON-input
        // textarea so non-technical users don't have to touch raw JSON. We
        // serialise back to JSON at save time and the server re-validates with
        // wellness_dynamic_form_manager::validate_schema().
        _readFormSchema() {
            let parsed;
            try {
                parsed = JSON.parse(this.form.schema_json || '{"fields":[]}');
            } catch (_e) {
                parsed = { fields: [] };
            }
            if (!parsed || typeof parsed !== 'object') parsed = { fields: [] };
            if (!Array.isArray(parsed.fields)) parsed.fields = [];
            return parsed;
        },
        _writeFormSchema(schema) {
            this.form.schema_json = JSON.stringify(schema, null, 2);
        },
        addFormField() {
            const schema = this._readFormSchema();
            schema.fields.push({
                name: '',
                label: '',
                type: 'text',
                required: false,
                options: [],
                max: 0,
                min: 0,
            });
            this._writeFormSchema(schema);
        },
        removeFormField(idx) {
            const schema = this._readFormSchema();
            schema.fields.splice(idx, 1);
            this._writeFormSchema(schema);
        },
        moveFormField(idx, dir) {
            const schema = this._readFormSchema();
            const j = idx + dir;
            if (j < 0 || j >= schema.fields.length) return;
            const tmp = schema.fields[idx];
            schema.fields[idx] = schema.fields[j];
            schema.fields[j] = tmp;
            this._writeFormSchema(schema);
        },
        addFormFieldOption(idx) {
            const schema = this._readFormSchema();
            if (!schema.fields[idx]) return;
            if (!Array.isArray(schema.fields[idx].options)) schema.fields[idx].options = [];
            schema.fields[idx].options.push('');
            this._writeFormSchema(schema);
        },
        removeFormFieldOption(idx, optIdx) {
            const schema = this._readFormSchema();
            if (!schema.fields[idx] || !Array.isArray(schema.fields[idx].options)) return;
            schema.fields[idx].options.splice(optIdx, 1);
            this._writeFormSchema(schema);
        },
        openFormDialog(f) {
            this.formCoverImage = null;
            if (f) {
                // Deep-copy so the cancel button restores the original.
                let parsedSchema = { fields: [] };
                try {
                    parsedSchema = JSON.parse(f.schema_json || '{"fields":[]}');
                    if (!parsedSchema || typeof parsedSchema !== 'object') parsedSchema = { fields: [] };
                    if (!Array.isArray(parsedSchema.fields)) parsedSchema.fields = [];
                } catch (_e) {
                    parsedSchema = { fields: [] };
                }
                this.form = {
                    id: f.id,
                    title: f.title,
                    description: f.description || '',
                    eventid: f.eventid || 0,
                    schema_json: JSON.stringify(parsedSchema, null, 2),
                    cover_path: f.cover_path || '',
                    active: !!f.active,
                };
            } else {
                this.form = this._blankForm();
            }
            this.formDialog = true;
        },
        async saveForm() {
            if (!this.form.title || this.form.title.trim() === '') {
                this.toast('Título obligatorio.', 'error');
                return;
            }
            const schemaError = this.formSchemaError;
            if (schemaError) {
                this.toast(schemaError, 'error');
                return;
            }
            this.formSaving = true;
            try {
                const args = {
                    id: this.form.id || 0,
                    title: this.form.title,
                    description: this.form.description || '',
                    eventid: this.form.eventid || 0,
                    schema_json: this.form.schema_json,
                    cover_path: this.form.cover_path || '',
                    active: !!this.form.active,
                };
                const res = await this.callWs('local_grupomakro_admin_save_wellness_dynamic_form', args);
                if (res && res.status === 'success' && res.data && res.data.ok) {
                    const newid = res.data.id || this.form.id;
                    // Portada: subir solo si el usuario eligio una nueva.
                    if (this.formCoverImage && newid) {
                        const url = await this.uploadCover('form', newid, this.formCoverImage);
                        if (url) this.form.cover_path = url;
                    }
                    this.toast('Formulario guardado.');
                    this.formDialog = false;
                    await this.refreshForms();
                } else {
                    this.toast((res && res.message) || 'Error al guardar el formulario.', 'error');
                }
            } catch (e) {
                this.toast('Error al guardar: ' + (e.message || e), 'error');
            } finally {
                this.formSaving = false;
            }
        },
        async toggleFormActive(f) {
            try {
                const res = await this.callWs('local_grupomakro_admin_toggle_wellness_dynamic_form_active', { id: f.id, active: !f.active });
                if (res && res.status === 'success' && res.data && res.data.ok) {
                    f.active = !f.active ? 1 : 0;
                    this.toast(f.active ? 'Formulario activado.' : 'Formulario desactivado.');
                    await this.refreshForms();
                } else {
                    this.toast((res && res.message) || 'No se pudo cambiar el estado.', 'error');
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error');
            }
        },
        async viewFormResponses(f) {
            this.responsesFormId = f.id;
            this.responsesFormTitle = f.title;
            this.responses = [];
            this.responsesDialog = true;
            this.responsesLoading = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_list_wellness_dynamic_form_responses', { formid: f.id });
                if (res && res.status === 'success' && res.data) {
                    this.responses = res.data.responses || [];
                } else {
                    this.toast((res && res.message) || 'No se pudieron cargar las respuestas.', 'error');
                }
            } catch (e) {
                this.toast('Error al cargar respuestas: ' + (e.message || e), 'error');
            } finally {
                this.responsesLoading = false;
            }
        },
        exportFormResponsesCsv() {
            // CSV manual para no depender de un WS adicional: el admin descarga
            // las respuestas que ya vio en pantalla.
            const rows = [['ID', 'Estudiante', 'Email', 'Enviado', 'Respuestas (JSON)']];
            this.responses.forEach(r => {
                rows.push([
                    r.id,
                    r.student_name || '',
                    r.email || '',
                    new Date(Number(r.submitted_at) * 1000).toISOString(),
                    JSON.stringify(r.answers || {})
                ]);
            });
            const escape = (v) => {
                const s = String(v == null ? '' : v);
                if (/[",\n]/.test(s)) {
                    return '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            };
            const csv = rows.map(row => row.map(escape).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'formulario_' + this.responsesFormId + '_respuestas.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },
        formatAnswer(value) {
            if (value === null || value === undefined || value === '') return '—';
            if (Array.isArray(value)) return value.join(', ');
            if (typeof value === 'boolean') return value ? 'Sí' : 'No';
            return String(value);
        },

        // -- Partners ------------------
        openPartnerDialog(p) {
            this.partnerImage = null;
            this.partner = p ? Object.assign(this._blankPartner(), p, {
                startdate_ts: p.startdate || 0,
                enddate_ts:   p.enddate   || 0,
                startdate:    p.startdate ? this._fmtDateTimeLocal(p.startdate) : '',
                enddate:      p.enddate   ? this._fmtDateTimeLocal(p.enddate)   : '',
            }) : this._blankPartner();
            this.partnerDialog = true;
        },
        // Lee el fichero como data URL y lo manda al WS de portada. Se llama
        // DESPUES de guardar, porque el endpoint necesita el id del registro.
        readAsDataUrl(file) {
            return new Promise((resolve, reject) => {
                const fr = new FileReader();
                fr.onload = () => resolve(fr.result);
                fr.onerror = () => reject(new Error('No se pudo leer el archivo'));
                fr.readAsDataURL(file);
            });
        },
        async uploadCover(kind, itemid, file) {
            if (!file || !itemid) return null;
            this.imageUploading = true;
            try {
                const content = await this.readAsDataUrl(file);
                const res = await this.callWs('local_grupomakro_admin_upload_wellness_image', { kind, itemid, content },);
                if (res && res.status === 'success' && res.data) {
                    return res.data.url;
                }
                this.toast(res && res.message ? res.message : 'No se pudo subir la portada', 'error');
                return null;
            } catch (e) {
                this.toast('Error al subir la portada: ' + (e.message || e), 'error');
                return null;
            } finally {
                this.imageUploading = false;
            }
        },
        async savePartner() {
            this.partnerSaving = true;
            try {
                const args = {
                    id: this.partner.id || 0,
                    name: this.partner.name,
                    categoryid: this.partner.categoryid,
                    benefit_description: this.partner.benefit_description,
                    conditions: this.partner.conditions,
                    requirements: this.partner.requirements,
                    startdate: this.partner.startdate ? Math.floor(new Date(this.partner.startdate).getTime() / 1000) : 0,
                    enddate: this.partner.enddate ? Math.floor(new Date(this.partner.enddate).getTime() / 1000) : 0,
                    contact_label: this.partner.contact_label,
                    contact_value: this.partner.contact_value,
                    logo_path: this.partner.logo_path,
                    sort: this.partner.sort,
                    active: !!this.partner.active,
                };
                const res = await this.callWs('local_grupomakro_admin_save_wellness_partner', args);
                if (res && res.status === 'success') {
                    const newid = (res.data && res.data.id) || this.partner.id || 0;
                    if (this.partnerImage) {
                        await this.uploadCover('partner', newid, this.partnerImage);
                        this.partnerImage = null;
                    }
                    this.toast('Convenio guardado.');
                    this.partnerDialog = false;
                    await this.refreshPartners();
                } else {
                    this.toast(res && res.message ? res.message : 'Error al guardar', 'error');
                }
            } catch (e) {
                this.toast('Error al guardar: ' + (e.message || e), 'error');
            } finally {
                this.partnerSaving = false;
            }
        },
        async togglePartnerActive(p) {
            try {
                const res = await this.callWs('local_grupomakro_admin_toggle_wellness_partner_active', { id: p.id, active: !p.active });
                if (res && res.status === 'success') {
                    this.toast(p.active ? 'Convenio desactivado' : 'Convenio activado');
                    await this.refreshPartners();
                }
            } catch (e) { this.toast('Error: ' + (e.message || e), 'error'); }
        },

        // -- Carnets ------------------
        async onCarnetUserQuery(value) {
            if (!value || value.length < 2) { this.carnetUserOptions = []; return }
            try {
                const res = await this.callWs('local_grupomakro_search_users', { query: value, limit: 8 },);
                if (res && res.status === 'success' && res.data) {
                    this.carnetUserOptions = res.data.users || [];
                }
            } catch (e) { /* soft-fail */ }
        },
        async onCarnetAction() {
            if (!this.carnetUserid) return
            try {
                const res = await this.callWs('local_grupomakro_admin_manage_carnet', { action: this.carnetAction, userid: this.carnetUserid },);
                if (res && res.status === 'success') {
                    this.toast('Carnet actualizado.')
                } else {
                    this.toast(res && res.message ? res.message : 'Error', 'error')
                }
            } catch (e) { this.toast('Error: ' + (e.message || e), 'error'); }
        },

        // -- Events ------------------
        openEventDialog(e) {
            this.eventImage = null;
            this.eventAttachments = e && e.id ? [] : [];
            this.event = e ? Object.assign(this._blankEvent(), e, {
                startdate_ts: e.startdate || 0,
                enddate_ts: e.enddate || 0,
                startdate: e.startdate ? this._fmtDateTimeLocal(e.startdate) : '',
                enddate:   e.enddate   ? this._fmtDateTimeLocal(e.enddate)   : '',
                registration_opens_ts:  e.registration_opens_at  || 0,
                registration_closes_ts: e.registration_closes_at || 0,
            }) : this._blankEvent();
            this.eventDialog = true;
        },
        addAttachment() {
            this.eventAttachments.push({ kind: 'handout', label: '', url: '', file_path: '', mimetype: '', filesize: 0 });
        },
        removeAttachment(i) {
            this.eventAttachments.splice(i, 1);
        },
        async saveEvent() {
            this.eventSaving = true;
            try {
                const args = {
                    id: this.event.id || 0,
                    title: this.event.title,
                    summary: this.event.summary,
                    description: this.event.description,
                    category: this.event.category,
                    startdate: this.event.startdate ? Math.floor(new Date(this.event.startdate).getTime() / 1000) : 0,
                    enddate: this.event.enddate ? Math.floor(new Date(this.event.enddate).getTime() / 1000) : 0,
                    modality: this.event.modality,
                    location: this.event.location,
                    virtual_url: this.event.virtual_url,
                    // Enviamos bbb_cmid explicitamente para que un eventual
                    // cambio (que el frontend no expone hoy) viaje al server;
                    // el manager lo aceptara solo si viene esta clave.
                    bbb_cmid: this.event.bbb_cmid || 0,
                    capacity: this.event.capacity,
                    requires_registration: this.event.requires_registration,
                    allow_waitlist: this.event.allow_waitlist,
                    registration_opens_at: this.event.registration_opens_ts || 0,
                    registration_closes_at: this.event.registration_closes_ts || 0,
                    organizer_name: this.event.organizer_name,
                    organizer_email: this.event.organizer_email,
                    cover_path: this.event.cover_path,
                    active: this.event.active,
                    attachments: JSON.stringify(this.eventAttachments),
                };
                const res = await fetch(ajaxUrl + '?sesskey=' + encodeURIComponent(sesskey), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'local_grupomakro_admin_save_wellness_event',
                        args
                    }),
                    credentials: 'same-origin'
                });
                // TEMP debug to diagnose the M_ID console error
                let resData;
                try {
                    resData = await res.json();
                } catch (parseErr) {
                    const txt = await res.text();
                    console.error('[saveEvent] non-JSON response:', txt.slice(0, 500));
                    throw new Error('La respuesta no es JSON. Probable sesion expirada.');
                }
                if (typeof console !== 'undefined') {
                    console.log('[saveEvent] response:', JSON.stringify(resData));
                }
                if (resData && resData.status === 'success') {
                    const newid = (resData.data && resData.data.id) || this.event.id || 0;
                    if (this.eventImage) {
                        await this.uploadCover('event', newid, this.eventImage);
                        this.eventImage = null;
                    }
                    this.toast('Evento guardado.');
                    this.eventDialog = false;
                    await this.refreshEvents();
                } else {
                    this.toast(resData && resData.message ? resData.message : 'Error al guardar', 'error');
                }
            } catch (e) {
                this.toast('Error al guardar: ' + (e.message || e), 'error');
            } finally {
                this.eventSaving = false;
            }
        },
        async toggleEventActive(e) {
            try {
                const res = await this.callWs('local_grupomakro_admin_toggle_wellness_event_active', { id: e.id, active: !e.active });
                if (res && res.status === 'success') {
                    await this.refreshEvents();
                }
            } catch (err) { this.toast('Error: ' + (err.message || err), 'error'); }
        },
        async deleteEvent(e) {
            // Two-step confirmation because the action is destructive: it
            // also tears down the BBB room if any, drops registrations and
            // detaches dynamic forms. Active=false is the reversible path;
            // this one isn't.
            const count = (e.registered_count || 0);
            const hasBbb = !!e.bbb_cmid;
            const lines = [
                'Vas a eliminar el evento "' + (e.title || '') + '" definitivamente.',
            ];
            if (count > 0) {
                lines.push('Se borraran ' + count + ' inscripcion(es) asociada(s).');
            }
            if (hasBbb) {
                lines.push('Se eliminara la sala BBB asociada (link de invitado quedara inactivo).');
            }
            lines.push('');
            lines.push('Esta accion NO se puede deshacer.');
            if (!confirm(lines.join('\n'))) return;
            try {
                const res = await this.callWs('local_grupomakro_admin_delete_wellness_event', { id: e.id });
                if (res && res.status === 'success') {
                    this.toast('Evento eliminado.');
                    await this.refreshEvents();
                } else {
                    this.toast((res && res.message) || 'No se pudo eliminar el evento.', 'error');
                }
            } catch (err) {
                this.toast('Error al eliminar: ' + (err.message || err), 'error');
            }
        },
        // -- BBB guest link (RF-04) -------------------------------------------
        async createBbbForEvent() {
            if (!this.event.id) {
                this.toast('Guarda primero el evento para poder generar la sala.', 'error');
                return;
            }
            this.bbbCreating = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_create_wellness_event_bbb', { eventid: this.event.id });
                const data = res.data && res.data;
                if (res && res.status === 'success' && data && data.ok) {
                    this.event.bbb_cmid = data.cmid;
                    this.event.bbb_guest_url = data.guest_url;
                    if (data.already) {
                        this.toast('Este evento ya tenía una sala; mostrando el link existente.');
                    } else {
                        this.toast('Sala BBB creada. Comparte el link con los asistentes.');
                    }
                } else {
                    this.toast((res && res.message) || 'No se pudo crear la sala BBB.', 'error');
                }
            } catch (e) {
                this.toast('Error al crear la sala: ' + (e.message || e), 'error');
            } finally {
                this.bbbCreating = false;
            }
        },
        async deleteBbbForEvent() {
            if (!this.event.bbb_cmid) return;
            if (!confirm('¿Eliminar la sala BBB del evento? El link de invitado dejara de funcionar.')) {
                return;
            }
            this.bbbDeleting = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_delete_wellness_event_bbb', { eventid: this.event.id });
                if (res && res.status === 'success' && res.data && res.data.ok) {
                    this.event.bbb_cmid = 0;
                    this.event.bbb_guest_url = '';
                    this.toast('Sala BBB eliminada.');
                } else {
                    this.toast((res && res.message) || 'No se pudo eliminar la sala.', 'error');
                }
            } catch (e) {
                this.toast('Error al eliminar la sala: ' + (e.message || e), 'error');
            } finally {
                this.bbbDeleting = false;
            }
        },
        // POST to ajax.php with a JSON body, returning the parsed
        // {status, data, message} envelope. Native fetch bypasses the
        // YUI XMLHttpRequest.prototype patching that triggered the
        // 'Cannot read properties of undefined (reading M_ID)' console
        // error on every axios call (200.js:1:761, after minification
        // = Vue's _isMounted).
        async callWs(action, args = {}, opts = {}) {
            const timeout = opts.timeout || 30000;
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeout);
            let res;
            try {
                res = await fetch(ajaxUrl + '?sesskey=' + encodeURIComponent(sesskey), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, args }),
                    credentials: 'same-origin',
                    signal: controller.signal
                });
            } catch (e) {
                clearTimeout(timer);
                throw new Error(e.message || 'fetch failed');
            }
            clearTimeout(timer);
            let body;
            try {
                body = await res.json();
            } catch (parseErr) {
                const txt = await res.text().catch(() => '');
                throw new Error('Respuesta no JSON (' + res.status + '): ' + txt.slice(0, 200));
            }
            return body;
        },
        copyToClipboard(text) {
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(() => this.toast('Link copiado al portapapeles.'))
                    .catch(() => this._fallbackCopy(text));
            } else {
                this._fallbackCopy(text);
            }
        },
        _fallbackCopy(text) {
            // Para navegadores antiguos / iframes sin clipboard API.
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                this.toast('Link copiado al portapapeles.');
            } catch (_e) {
                this.toast('No se pudo copiar. Selecciona y copia manualmente.', 'error');
            } finally {
                document.body.removeChild(ta);
            }
        },
        async openRegistrations(e) {
            this.regEvent = e;
            this.registrations = [];
            this.regDialog = true;
            try {
                const res = await this.callWs('local_grupomakro_admin_list_event_registrations', { eventid: e.id });
                if (res && res.status === 'success' && res.data && res.data.registrations) {
                    this.registrations = res.data.registrations;
                } else {
                    this.toast((res && res.message) || 'No se pudieron cargar los inscritos.', 'error');
                }
            } catch (e) {
                this.toast('Error al cargar inscritos: ' + (e.message || e), 'error');
            }
        },
        async cancelRegistrationAsAdmin(r) {
            const name = r.fullname || ('usuario #' + r.userid);
            if (!confirm('Desinscribir a "' + name + '" del evento?\n\nQuedara registrado en auditoria como cancelacion de backoffice.')) return;
            try {
                const res = await this.callWs('local_grupomakro_admin_cancel_event_registration', {
                    eventid: r.eventid, userid: r.userid,
                });
                if (res && res.status === 'success') {
                    this.toast(res.already ? 'La inscripcion ya estaba cancelada.' : 'Inscripcion cancelada.');
                    // Update the row locally so the dialog reflects the change
                    // without a round-trip; we'll re-fetch if the user closes
                    // and reopens.
                    const i = this.registrations.findIndex(x => x.id === r.id);
                    if (i !== -1) {
                        this.$set(this.registrations[i], 'status', 'cancelada');
                        this.$set(this.registrations[i], 'cancelled_at', Math.floor(Date.now() / 1000));
                    }
                    // Reflect the new count in the events table badge.
                    this.event.registered_count = Math.max(0, (this.event.registered_count || 0) - 1);
                    await this.refreshEvents();
                } else {
                    this.toast((res && res.message) || 'No se pudo cancelar la inscripcion.', 'error');
                }
            } catch (e) {
                this.toast('Error al cancelar: ' + (e.message || e), 'error');
            }
        },
        regStatusColor(s) {
            return ({
                'confirmada': 'green',
                'asistio': 'success',
                'lista_de_espera': 'orange darken-2',
                'cancelada': 'grey',
                'no_asistio': 'red',
            })[s] || 'grey';
        },
        countByStatus(status) {
            return (this.registrations || []).filter(r => r && r.status === status).length;
        },
        regStatusLabel(s) {
            return ({
                'confirmada': 'Confirmada',
                'asistio': 'Asistio',
                'lista_de_espera': 'Lista de espera',
                'cancelada': 'Cancelada',
                'no_asistio': 'No asistio',
            })[s] || s;
        },
        async exportCsv(e) {
            try {
                const res = await this.callWs('local_grupomakro_admin_export_event_registrations', { eventid: e.id });
                if (res && res.status === 'success' && res.data && res.data.csv) {
                    const blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `inscritos_evento_${e.id}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(url), 500);
                } else {
                    this.toast('No se pudo exportar', 'error');
                }
            } catch (err) { this.toast('Error: ' + (err.message || err), 'error'); }
        },

        // -- Helpers ------------------
        _fmtDateTimeLocal(ts) {
            const d = new Date(ts * 1000);
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        },
        formatDate(ts) {
            if (!ts) return '—';
            const d = new Date(ts * 1000);
            return d.toLocaleString();
        },
        categoryLabel(c) {
            return { deportivo: 'Deportivo', feria: 'Feria', taller: 'Taller', charla: 'Charla', otro: 'Otro' }[c] || c;
        },
    },
    template: `
<v-container fluid>
<v-tabs v-model="tab" background-color="primary" dark grow>
      <v-tab href="#partners">
        <v-icon left>mdi-handshake-outline</v-icon> Convenios
      </v-tab>
      <v-tab href="#events">
        <v-icon left>mdi-calendar-star</v-icon> Eventos
      </v-tab>
      <v-tab href="#forms">
        <v-icon left>mdi-clipboard-text-outline</v-icon> Formularios dinámicos
      </v-tab>
      <v-tab href="#carnets">
        <v-icon left>mdi-card-account-details-outline</v-icon> Carnets
      </v-tab>
    </v-tabs>

  <v-tabs-items v-model="tab" class="mt-4">
    <!-- -- PARTNERS ------------------ -->
    <v-tab-item value="partners">
      <v-card>
        <v-card-title>
          <v-text-field v-model="partnerSearch" label="Buscar por nombre o beneficio" prepend-inner-icon="mdi-magnify" hide-details clearable></v-text-field>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="openPartnerDialog(null)">
            <v-icon left>mdi-plus</v-icon> Nuevo convenio
          </v-btn>
        </v-card-title>
        <v-data-table
          :headers="[
            { text: 'Nombre', value: 'name' },
            { text: 'Categoría', value: 'category_name' },
            { text: 'Beneficio', value: 'benefit_description' },
            { text: 'Vigente', value: 'period', sortable: false },
            { text: 'Activo', value: 'active', align: 'center' },
            { text: 'Acciones', value: '_actions', sortable: false, align: 'center' }
          ]"
          :items="filteredPartners"
          :loading="loading"
          no-data-text="No hay convenios registrados."
          dense
        >
          <template v-slot:item.period="{ item }">
            <span v-if="item.startdate && item.enddate">
              {{ formatDate(item.startdate) }} <br> → {{ formatDate(item.enddate) }}
            </span>
            <span v-else-if="item.startdate">Desde {{ formatDate(item.startdate) }}</span>
            <span v-else-if="item.enddate">Hasta {{ formatDate(item.enddate) }}</span>
            <span v-else class="grey--text">Permanente</span>
          </template>
          <template v-slot:item.active="{ item }">
            <v-chip :color="item.active ? 'green' : 'grey'" small dark>
              {{ item.active ? 'Sí' : 'No' }}
            </v-chip>
          </template>
          <template v-slot:item._actions="{ item }">
            <v-btn icon small @click="openPartnerDialog(item)"><v-icon>mdi-pencil</v-icon></v-btn>
            <v-btn icon small @click="togglePartnerActive(item)">
              <v-icon>{{ item.active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline' }}</v-icon>
            </v-btn>
          </template>
        </v-data-table>
      </v-card>
    </v-tab-item>

    <!-- -- EVENTS ------------------ -->
    <v-tab-item value="events">
      <v-card>
        <v-card-title>
          <v-text-field v-model="eventSearch" label="Buscar evento" prepend-inner-icon="mdi-magnify" hide-details clearable></v-text-field>
          <v-select v-model="eventCategoryFilter" :items="eventCategoryItems" label="Categoría" hide-details style="max-width:220px" class="ml-3"></v-select>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="openEventDialog(null)">
            <v-icon left>mdi-plus</v-icon> Nuevo evento
          </v-btn>
        </v-card-title>
        <v-data-table
          :headers="[
            { text: 'Título', value: 'title' },
            { text: 'Categoría', value: 'category' },
            { text: 'Inicio', value: 'startdate' },
            { text: 'Fin', value: 'enddate' },
            { text: 'Modalidad', value: 'modality' },
            { text: 'Inscritos', value: 'registered_count', align: 'center' },
            { text: 'Cupo', value: 'capacity', align: 'center' },
            { text: 'Activo', value: 'active', align: 'center' },
            { text: 'Acciones', value: '_actions', sortable: false, align: 'center' }
          ]"
          :items="filteredEvents"
          :loading="loading"
          no-data-text="No hay eventos registrados."
          dense
        >
          <template v-slot:item.category="{ item }">{{ categoryLabel(item.category) }}</template>
          <template v-slot:item.startdate="{ item }">{{ formatDate(item.startdate) }}</template>
          <template v-slot:item.enddate="{ item }">{{ formatDate(item.enddate) }}</template>
          <template v-slot:item.active="{ item }">
            <v-chip :color="item.active ? 'green' : 'grey'" small dark>
              {{ item.active ? 'Sí' : 'No' }}
            </v-chip>
          </template>
          <template v-slot:item._actions="{ item }">
            <v-btn icon small @click="openRegistrations(item)" title="Ver inscritos / desinscribir">
              <v-badge
                v-if="item.registered_count > 0"
                :content="String(item.registered_count)"
                :value="item.registered_count"
                color="primary"
                overlap
              >
                <v-icon>mdi-account-group</v-icon>
              </v-badge>
              <v-icon v-else>mdi-account-group-outline</v-icon>
            </v-btn>
            <v-btn icon small @click="openEventDialog(item)" title="Editar">
              <v-icon>mdi-pencil</v-icon>
            </v-btn>
            <v-btn icon small @click="exportCsv(item)" title="Exportar CSV">
              <v-icon>mdi-download</v-icon>
            </v-btn>
            <v-btn icon small @click="toggleEventActive(item)" :title="item.active ? 'Desactivar' : 'Activar'">
              <v-icon>{{ item.active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline' }}</v-icon>
            </v-btn>
            <v-btn icon small color="red" @click="deleteEvent(item)" title="Eliminar definitivamente">
              <v-icon>mdi-delete</v-icon>
            </v-btn>
          </template>
        </v-data-table>
      </v-card>
    </v-tab-item>

    <!-- -- CARNETS (RF-07 / RF-09.4) ------------------ -->
    <v-tab-item value="carnets">
      <v-card>
        <v-card-title>Gestión de carnets digitales</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model.number="carnetUserid"
                :items="carnetUserOptions"
                :search-input.sync="carnetUserSearch"
                item-text="fullname"
                item-value="id"
                label="Estudiante"
                placeholder="Buscar por nombre, apellido o email"
                prepend-inner-icon="mdi-account-search"
                @update:search-input="onCarnetUserQuery"
                clearable
                return-object
              >
                <template v-slot:item="{ item }">
                  <v-list-item-content>
                    <v-list-item-title>{{ item.fullname }}</v-list-item-title>
                    <v-list-item-subtitle>{{ item.email }}</v-list-item-subtitle>
                  </v-list-item-content>
                </template>
                <template v-slot:selection="{ item }">
                  <span v-if="item">{{ item.fullname }} ({{ item.email }})</span>
                </template>
              </v-autocomplete>
            </v-col>
            <v-col cols="12" md="6">
              <v-select v-model="carnetAction" :items="[
                { text: 'Renovar / Extender vigencia', value: 'renew' },
                { text: 'Suspender', value: 'suspend' },
                { text: 'Reactivar', value: 'reinstate' },
                { text: 'Regenerar token QR (comprometido)', value: 'regenerate_token' },
                { text: 'Marcar como egresado', value: 'graduate' },
              ]" label="Acción"></v-select>
            </v-col>
          </v-row>
          <v-alert type="info" text class="mt-3">
            Las acciones se registran en la auditoría de la fila correspondiente. Para regenerar token,
            notifica al estudiante por otro canal (correo, llamada) para que re-descargue el carnet.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn
            color="primary"
            :disabled="!carnetUserid"
            @click="onCarnetAction"
          >
            Aplicar acción
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-tab-item>

    <!-- -- FORMS (RF-06 / RF-09.2) ------------------ -->
    <v-tab-item value="forms">
      <v-card>
        <v-card-title class="d-flex align-center">
          <span>Formularios dinámicos</span>
          <v-spacer></v-spacer>
          <v-btn color="primary" depressed @click="openFormDialog(null)">
            <v-icon left>mdi-plus</v-icon> Nuevo formulario
          </v-btn>
        </v-card-title>
        <v-card-text>
          <v-alert type="info" text class="mb-3">
            Define un schema (lista de campos) que el estudiante verá en el LXP
            cuando abra el evento asociado. El backend valida la estructura al
            guardar.
          </v-alert>
          <v-data-table
            :headers="[
              { text: 'ID', value: 'id' },
              { text: 'Título', value: 'title' },
              { text: 'Evento', value: 'event_title' },
              { text: 'Respuestas', value: 'response_count', align: 'center' },
              { text: 'Activo', value: 'active', align: 'center' },
              { text: 'Acciones', value: 'actions', sortable: false, align: 'center', width: 220 }
            ]"
            :items="forms"
            :loading="loading"
            dense
          >
            <template v-slot:item.event_title="{ item }">
              <span v-if="item.event_title">{{ item.event_title }}</span>
              <span v-else class="grey--text text--darken-1 font-italic">(Reutilizable)</span>
            </template>
            <template v-slot:item.active="{ item }">
              <v-chip :color="item.active ? 'green' : 'grey'" small dark>
                {{ item.active ? 'Sí' : 'No' }}
              </v-chip>
            </template>
            <template v-slot:item.actions="{ item }">
              <v-btn icon small @click="openFormDialog(item)" title="Editar">
                <v-icon>mdi-pencil</v-icon>
              </v-btn>
              <v-btn icon small @click="viewFormResponses(item)" title="Ver respuestas">
                <v-icon>mdi-format-list-bulleted</v-icon>
              </v-btn>
              <v-btn
                icon small
                :title="item.active ? 'Desactivar' : 'Activar'"
                @click="toggleFormActive(item)"
              >
                <v-icon :color="item.active ? 'amber darken-2' : 'green'">
                  {{ item.active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off' }}
                </v-icon>
              </v-btn>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-tab-item>
  </v-tabs-items>

  <!-- Registrations dialog (per-event) -->
  <v-dialog v-model="regDialog" max-width="1000" scrollable>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon left color="primary">mdi-account-group</v-icon>
        <span class="title">Inscritos: {{ regEvent ? regEvent.title : '' }}</span>
        <v-spacer></v-spacer>
        <v-btn icon @click="regDialog = false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-divider></v-divider>
      <v-card-text style="max-height: 70vh;">
        <v-row dense class="mb-3">
          <v-col cols="12" md="3">
            <v-chip color="green" dark small>Confirmadas: {{ countByStatus('confirmada') + countByStatus('asistio') }}</v-chip>
          </v-col>
          <v-col cols="12" md="3">
            <v-chip color="orange darken-2" dark small>Lista de espera: {{ countByStatus('lista_de_espera') }}</v-chip>
          </v-col>
          <v-col cols="12" md="3">
            <v-chip color="grey" dark small>Canceladas: {{ countByStatus('cancelada') }}</v-chip>
          </v-col>
          <v-col cols="12" md="3">
            <v-chip color="red" dark small>No asistio: {{ countByStatus('no_asistio') }}</v-chip>
          </v-col>
        </v-row>
        <v-text-field
          v-model="registrationsSearch"
          prepend-inner-icon="mdi-magnify"
          label="Buscar por nombre, email o usuario"
          outlined dense clearable
          class="mb-3"
        />
        <v-data-table
          :headers="registrationsHeaders"
          :items="filteredRegistrations"
          :search="registrationsSearch"
          :items-per-page="15"
          dense
        >
          <template v-slot:item.status="{ item }">
            <v-chip :color="regStatusColor(item.status)" dark small>
              {{ regStatusLabel(item.status) }}
            </v-chip>
          </template>
          <template v-slot:item.modality="{ item }">
            <span :class="item.modality ? '' : 'grey--text text--darken-1 font-italic'">
              {{ item.modality || '—' }}
            </span>
          </template>
          <template v-slot:item.source="{ item }">
            <v-chip small outlined :color="item.source === 'backoffice' ? 'amber darken-2' : 'blue-grey'">
              {{ item.source }}
            </v-chip>
          </template>
          <template v-slot:item.registered_at="{ item }">
            {{ new Date(Number(item.registered_at) * 1000).toLocaleString('es-PA') }}
          </template>
          <template v-slot:item.actions="{ item }">
            <v-btn
              v-if="item.status !== 'cancelada'"
              icon small color="red"
              title="Desinscribir"
              @click="cancelRegistrationAsAdmin(item)"
            >
              <v-icon>mdi-account-minus</v-icon>
            </v-btn>
            <v-icon v-else color="grey" title="Ya cancelada">mdi-cancel</v-icon>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>
  </v-dialog>

  <!-- Partner dialog -->
  <v-dialog v-model="partnerDialog" max-width="700" scrollable>
    <v-card>
      <v-card-title>{{ partner.id ? 'Editar convenio' : 'Nuevo convenio' }}</v-card-title>
      <v-card-text>
        <v-text-field v-model="partner.name" label="Nombre de la empresa" required></v-text-field>
        <v-select v-model="partner.categoryid" :items="categoryItems" label="Categoría" required></v-select>
        <v-textarea v-model="partner.benefit_description" label="Beneficio / descuento" rows="2" required></v-textarea>
        <v-textarea v-model="partner.conditions" label="Condiciones de uso" rows="2"></v-textarea>
        <v-textarea v-model="partner.requirements" label="Requisitos" rows="2"></v-textarea>
        <v-row>
          <v-col cols="6">
            <v-text-field v-model="partner.startdate" label="Inicio de vigencia" type="datetime-local"></v-text-field>
          </v-col>
          <v-col cols="6">
            <v-text-field v-model="partner.enddate" label="Fin de vigencia" type="datetime-local"></v-text-field>
          </v-col>
        </v-row>
        <v-row>
          <v-col cols="4">
            <v-text-field v-model="partner.contact_label" label="Tipo de contacto"></v-text-field>
          </v-col>
          <v-col cols="8">
            <v-text-field v-model="partner.contact_value" label="Valor (teléfono, email, URL)"></v-text-field>
          </v-col>
        </v-row>
        <v-text-field v-model.number="partner.sort" label="Orden" type="number"></v-text-field>
        <v-divider class="my-3"></v-divider>
        <div class="text-subtitle-2 mb-1">Logo / portada del convenio</div>
        <v-alert dense text type="info" class="mb-2">
          Tamaño recomendado <strong>600 x 600 px</strong> (cuadrado). Mínimo 300 x 300 px.
          Formatos JPG, PNG o WebP, hasta 3 MB. Es la imagen que ve el estudiante en la tarjeta del convenio.
        </v-alert>
        <v-img v-if="partner.logo_path" :src="partner.logo_path" max-height="120" contain class="mb-2 grey lighten-4"></v-img>
        <v-file-input
          v-model="partnerImage"
          accept="image/jpeg,image/png,image/webp"
          label="Subir logo (se guarda al pulsar Guardar)"
          prepend-icon="mdi-image"
          show-size
          clearable
          :loading="imageUploading"
          hint="Si dejas este campo vacío se conserva la imagen actual."
          persistent-hint
        ></v-file-input>
        <v-switch v-model="partner.active" label="Activo" inset></v-switch>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="partnerDialog = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="partnerSaving" @click="savePartner">Guardar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Event dialog -->
  <v-dialog v-model="eventDialog" max-width="800" scrollable>
    <v-card>
      <v-card-title>{{ event.id ? 'Editar evento' : 'Nuevo evento' }}</v-card-title>
      <v-card-text>
        <v-text-field v-model="event.title" label="Título" required></v-text-field>
        <v-text-field v-model="event.summary" label="Resumen (una línea)"></v-text-field>
        <v-textarea v-model="event.description" label="Descripción" rows="3"></v-textarea>
        <v-row>
          <v-col cols="4">
            <v-select v-model="event.category" :items="[
              { text: 'Deportivo', value: 'deportivo' },
              { text: 'Feria', value: 'feria' },
              { text: 'Taller', value: 'taller' },
              { text: 'Charla', value: 'charla' },
              { text: 'Otro', value: 'otro' }
            ]" label="Categoría"></v-select>
          </v-col>
          <v-col cols="4">
            <v-select v-model="event.modality" :items="[
              { text: 'Presencial', value: 'presencial' },
              { text: 'Virtual', value: 'virtual' },
              { text: 'Mixto', value: 'mixto' }
            ]" label="Modalidad"></v-select>
          </v-col>
          <v-col cols="4">
            <v-text-field v-model.number="event.capacity" label="Cupo (0 = ilimitado)" type="number"></v-text-field>
          </v-col>
        </v-row>
        <v-row>
          <v-col cols="6">
            <v-text-field v-model="event.startdate" label="Inicio" type="datetime-local" required></v-text-field>
          </v-col>
          <v-col cols="6">
            <v-text-field v-model="event.enddate" label="Fin" type="datetime-local"></v-text-field>
          </v-col>
        </v-row>
        <v-text-field v-model="event.location" label="Ubicación"></v-text-field>

        <!--
          Sesión virtual: el admin puede generar una sala BBB con link de
          invitado (patron manage_meetings.php) o pegar una URL externa para
          Zoom/Teams/otra. La sala BBB tiene prioridad en el LXP: si existe
          el bbb_guest_url, el estudiante ve ese boton y nunca ve el
          virtual_url.
        -->
        <v-card outlined class="mb-3">
          <v-card-text>
            <div class="d-flex align-center mb-2">
              <v-icon left color="primary">mdi-video</v-icon>
              <strong>Sala virtual</strong>
              <v-spacer></v-spacer>
              <v-chip v-if="event.bbb_cmid" small color="green" dark>BBB activo</v-chip>
              <v-chip v-else small color="grey lighten-1">Sin sala BBB</v-chip>
            </div>

            <div v-if="event.bbb_cmid && event.bbb_guest_url" class="mb-3">
              <v-text-field
                v-model="event.bbb_guest_url"
                label="Link de invitado"
                readonly outlined dense
                prepend-inner-icon="mdi-link-variant"
                :hint="'Primer participante en entrar = anfitrión. cmid=' + event.bbb_cmid"
                persistent-hint
              />
              <div class="d-flex mt-2">
                <v-btn
                  small color="primary" depressed
                  @click="copyToClipboard(event.bbb_guest_url)"
                >
                  <v-icon left small>mdi-content-copy</v-icon> Copiar link
                </v-btn>
                <v-btn
                  small color="grey" outlined
                  :href="event.bbb_guest_url" target="_blank" rel="noopener"
                  class="ml-2"
                >
                  <v-icon left small>mdi-open-in-new</v-icon> Probar
                </v-btn>
                <v-spacer></v-spacer>
                <v-btn
                  small color="red" outlined
                  :loading="bbbDeleting" :disabled="bbbDeleting"
                  @click="deleteBbbForEvent"
                >
                  <v-icon left small>mdi-delete</v-icon> Eliminar sala
                </v-btn>
              </div>
            </div>

            <div v-else class="mb-3">
              <v-alert dense text type="info" class="mb-2">
                Genera una sala BBB con link de invitado: cualquier persona con
                el link puede unirse, y la primera en entrar queda como
                anfitriona (igual que en el Gestor de Sesiones Virtuales).
                <strong>Primero guarda el evento.</strong>
              </v-alert>
              <v-btn
                color="primary" depressed
                :loading="bbbCreating" :disabled="bbbCreating || !event.id"
                @click="createBbbForEvent"
              >
                <v-icon left>mdi-video-plus</v-icon>
                Generar link de invitado
              </v-btn>
              <span v-if="!event.id" class="caption ml-3 grey--text">
                Guarda el evento antes de generar la sala.
              </span>
            </div>

            <v-divider class="my-3"></v-divider>
            <v-text-field
              v-model="event.virtual_url"
              label="O pegar URL externa (Zoom, Teams, Meet, etc.)"
              hint="Si generaste una sala BBB, esta URL se ignora en el LXP."
              persistent-hint
              outlined dense
              prepend-inner-icon="mdi-link"
            />
          </v-card-text>
        </v-card>
        <v-row>
          <v-col cols="6">
            <v-text-field v-model="event.organizer_name" label="Organizador"></v-text-field>
          </v-col>
          <v-col cols="6">
            <v-text-field v-model="event.organizer_email" label="Email del organizador"></v-text-field>
          </v-col>
        </v-row>
        <v-switch v-model="event.requires_registration" label="Requiere inscripción" inset></v-switch>
        <v-switch v-model="event.allow_waitlist" label="Permitir lista de espera cuando se llene" inset></v-switch>
        <v-divider class="my-3"></v-divider>
        <div class="text-subtitle-2 mb-1">Imagen de portada del evento</div>
        <v-alert dense text type="info" class="mb-2">
          Tamaño recomendado <strong>1200 x 630 px</strong> (relación 1.91:1). Mínimo 600 x 315 px.
          Formatos JPG, PNG o WebP, hasta 3 MB. Se muestra al estudiante en la tarjeta y en el detalle del evento.
        </v-alert>
        <v-img v-if="event.cover_path" :src="event.cover_path" max-height="160" contain class="mb-2 grey lighten-4"></v-img>
        <v-file-input
          v-model="eventImage"
          accept="image/jpeg,image/png,image/webp"
          label="Subir portada (se guarda al pulsar Guardar)"
          prepend-icon="mdi-image"
          show-size
          clearable
          :loading="imageUploading"
          hint="Si dejas este campo vacío se conserva la imagen actual."
          persistent-hint
        ></v-file-input>
        <v-switch v-model="event.active" label="Activo" inset></v-switch>

        <v-divider class="my-3"></v-divider>
        <div class="d-flex align-center">
          <strong>Material adjunto</strong>
          <v-spacer></v-spacer>
          <v-btn small color="primary" outlined @click="addAttachment">
            <v-icon left small>mdi-plus</v-icon> Añadir adjunto
          </v-btn>
        </div>
        <v-list dense>
          <v-list-item v-for="(a, i) in eventAttachments" :key="i">
            <v-list-item-content>
              <v-row dense>
                <v-col cols="3">
                  <v-select v-model="a.kind" :items="[
                    { text: 'Folleto', value: 'handout' },
                    { text: 'Grabación', value: 'recording' },
                    { text: 'Enlace', value: 'link' },
                    { text: 'Otro', value: 'other' }
                  ]" label="Tipo" dense hide-details></v-select>
                </v-col>
                <v-col cols="5"><v-text-field v-model="a.label" label="Etiqueta" dense hide-details /></v-col>
                <v-col cols="3">
                  <v-text-field v-if="a.kind === 'link'" v-model="a.url" label="URL" dense hide-details />
                  <v-text-field v-else v-model="a.file_path" label="Ruta de archivo" dense hide-details />
                </v-col>
                <v-col cols="1"><v-btn icon small @click="removeAttachment(i)"><v-icon>mdi-delete</v-icon></v-btn></v-col>
              </v-row>
            </v-list-item-content>
          </v-list-item>
        </v-list>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="eventDialog = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="eventSaving" @click="saveEvent">Guardar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- -- Form dialog (create / edit) ------------------ -->
  <v-dialog v-model="formDialog" max-width="1000" scrollable persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon left color="primary">mdi-form-select</v-icon>
        {{ form.id ? 'Editar formulario' : 'Nuevo formulario' }}
      </v-card-title>
      <v-divider></v-divider>
      <v-card-text style="max-height: 70vh;">
        <v-row dense>
          <v-col cols="12" md="8">
            <v-text-field
              v-model="form.title"
              label="Título del formulario"
              required dense outlined
              :rules="[v => !!v || 'obligatorio']"
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="form.eventid"
              :items="eventItems"
              label="Evento asociado"
              dense outlined clearable
            />
          </v-col>
        </v-row>
        <v-textarea
          v-model="form.description"
          label="Descripción (instrucciones para el estudiante)"
          rows="2" outlined dense
        />

        <v-divider class="my-4"></v-divider>
        <div class="d-flex align-center mb-2">
          <strong>Campos del formulario</strong>
          <v-spacer></v-spacer>
          <v-btn small color="primary" outlined @click="addFormField">
            <v-icon left small>mdi-plus</v-icon> Agregar campo
          </v-btn>
        </div>
        <v-alert v-if="formSchemaError" type="error" dense text class="mb-3">
          {{ formSchemaError }}
        </v-alert>

        <div v-if="formPreviewFields.length === 0" class="text-center pa-4 grey--text text--darken-1">
          Aún no hay campos. Pulsa "Agregar campo" para empezar.
        </div>

        <v-card
          v-for="(field, idx) in formPreviewFields"
          :key="idx"
          class="mb-3" outlined
        >
          <v-card-text>
            <v-row dense>
              <v-col cols="12" md="3">
                <v-text-field
                  v-model="field.name"
                  label="Nombre técnico"
                  hint="Sin espacios, único en el formulario"
                  persistent-hint dense outlined
                  :rules="[v => /^[a-z][a-z0-9_]*$/.test(v) || 'Solo minúsculas, números y _; debe empezar con letra']"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="field.label"
                  label="Etiqueta visible"
                  dense outlined
                  :rules="[v => !!v || 'obligatorio']"
                />
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="field.type"
                  :items="formFieldTypes()"
                  label="Tipo de campo"
                  dense outlined
                />
              </v-col>
              <v-col cols="12" md="2" class="d-flex align-center">
                <v-switch
                  v-model="field.required"
                  label="Obligatorio" dense inset
                  hide-details
                />
              </v-col>
            </v-row>

            <v-row v-if="field.type === 'select' || field.type === 'multiselect'" dense class="mt-2">
              <v-col cols="12">
                <div class="d-flex align-center mb-1">
                  <strong class="caption">Opciones</strong>
                  <v-spacer></v-spacer>
                  <v-btn x-small outlined color="primary" @click="addFormFieldOption(idx)">
                    <v-icon left x-small>mdi-plus</v-icon> Opción
                  </v-btn>
                </div>
                <v-row v-for="(opt, optIdx) in field.options" :key="optIdx" dense class="mb-1">
                  <v-col>
                    <v-text-field
                      v-model="field.options[optIdx]"
                      dense outlined hide-details
                      :placeholder="'Opción ' + (optIdx + 1)"
                    />
                  </v-col>
                  <v-col cols="auto">
                    <v-btn icon small @click="removeFormFieldOption(idx, optIdx)">
                      <v-icon>mdi-close</v-icon>
                    </v-btn>
                  </v-col>
                </v-row>
                <div v-if="!field.options || field.options.length === 0" class="caption grey--text">
                  Agrega al menos una opción.
                </div>
              </v-col>
            </v-row>

            <v-row v-if="field.type === 'text' || field.type === 'textarea'" dense class="mt-2">
              <v-col cols="6" md="3">
                <v-text-field
                  v-model.number="field.max"
                  label="Máx. caracteres (0 = sin límite)"
                  type="number" min="0" dense outlined
                />
              </v-col>
            </v-row>

            <v-row v-if="field.type === 'number'" dense class="mt-2">
              <v-col cols="6" md="3">
                <v-text-field
                  v-model.number="field.min"
                  label="Valor mínimo" type="number" dense outlined
                />
              </v-col>
              <v-col cols="6" md="3">
                <v-text-field
                  v-model.number="field.max"
                  label="Valor máximo" type="number" dense outlined
                />
              </v-col>
            </v-row>

            <div class="d-flex mt-2">
              <v-btn icon small :disabled="idx === 0" @click="moveFormField(idx, -1)" title="Subir">
                <v-icon>mdi-arrow-up</v-icon>
              </v-btn>
              <v-btn icon small :disabled="idx === formPreviewFields.length - 1" @click="moveFormField(idx, 1)" title="Bajar">
                <v-icon>mdi-arrow-down</v-icon>
              </v-btn>
              <v-spacer></v-spacer>
              <v-btn icon small color="red" @click="removeFormField(idx)" title="Eliminar campo">
                <v-icon>mdi-delete</v-icon>
              </v-btn>
            </div>
          </v-card-text>
        </v-card>

        <v-divider class="my-4"></v-divider>

        <div class="d-flex align-center mb-2">
          <strong>Vista previa (lo que verá el estudiante)</strong>
        </div>
        <v-card outlined class="pa-3 grey lighten-4">
          <div v-if="formPreviewFields.length === 0" class="caption grey--text text--darken-1">
            Agrega campos para ver el preview.
          </div>
          <div v-for="(field, idx) in formPreviewFields" :key="'prev-' + idx" class="mb-3">
            <div v-if="field.type === 'checkbox'">
              <v-checkbox
                :label="field.label + (field.required ? ' *' : '')"
                disabled
                hide-details
              />
            </div>
            <div v-else-if="field.type === 'textarea'">
              <v-textarea
                :label="field.label + (field.required ? ' *' : '')"
                disabled rows="2" outlined dense
              />
            </div>
            <div v-else-if="field.type === 'select'">
              <v-select
                :label="field.label + (field.required ? ' *' : '')"
                :items="(field.options || []).filter(o => o && o.trim())"
                disabled outlined dense
              />
            </div>
            <div v-else-if="field.type === 'multiselect'">
              <v-select
                :label="field.label + (field.required ? ' *' : '')"
                :items="(field.options || []).filter(o => o && o.trim())"
                multiple chips disabled outlined dense
              />
            </div>
            <div v-else-if="field.type === 'number'">
              <v-text-field
                :label="field.label + (field.required ? ' *' : '')"
                type="number" disabled outlined dense
              />
            </div>
            <div v-else-if="field.type === 'date'">
              <v-text-field
                :label="field.label + (field.required ? ' *' : '')"
                type="date" disabled outlined dense
              />
            </div>
            <div v-else>
              <v-text-field
                :label="field.label + (field.required ? ' *' : '')"
                :type="field.type === 'email' ? 'email' : 'text'"
                disabled outlined dense
              />
            </div>
          </div>
        </v-card>

        <v-divider class="my-4"></v-divider>

        <div class="text-subtitle-2 mb-1">Portada del formulario (opcional)</div>
        <v-alert dense text type="info" class="mb-2">
          Tamaño recomendado <strong>1200 x 630 px</strong>. JPG, PNG o WebP hasta 3 MB.
        </v-alert>
        <v-img v-if="form.cover_path" :src="form.cover_path" max-height="140" contain class="mb-2 grey lighten-4"></v-img>
        <v-file-input
          v-model="formCoverImage"
          accept="image/jpeg,image/png,image/webp"
          label="Subir portada"
          prepend-icon="mdi-image"
          show-size clearable
          :loading="imageUploading"
          hint="Si lo dejas vacío se conserva la portada actual."
          persistent-hint
        ></v-file-input>
        <v-switch v-model="form.active" label="Formulario activo" inset></v-switch>
      </v-card-text>
      <v-divider></v-divider>
      <v-card-actions class="px-4 py-3">
        <v-btn text @click="formDialog = false" :disabled="formSaving">Cancelar</v-btn>
        <v-spacer></v-spacer>
        <v-btn
          color="primary" depressed
          :loading="formSaving"
          :disabled="!!formSchemaError"
          @click="saveForm"
        >
          <v-icon left small>mdi-content-save</v-icon> Guardar
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- -- Responses viewer ------------------ -->
  <v-dialog v-model="responsesDialog" max-width="900" scrollable>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon left color="primary">mdi-format-list-bulleted</v-icon>
        Respuestas: {{ responsesFormTitle }}
        <v-spacer></v-spacer>
        <v-btn icon @click="responsesDialog = false">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>
      <v-divider></v-divider>
      <v-card-text style="max-height: 65vh;">
        <div class="d-flex align-center mb-3">
          <v-chip small color="primary" class="mr-2">{{ responses.length }} respuestas</v-chip>
          <v-spacer></v-spacer>
          <v-btn
            small outlined color="primary"
            :disabled="responses.length === 0"
            @click="exportFormResponsesCsv"
          >
            <v-icon left small>mdi-download</v-icon> Exportar CSV
          </v-btn>
        </div>
        <v-alert v-if="responsesLoading" type="info" dense>Cargando...</v-alert>
        <v-alert v-else-if="responses.length === 0" type="info" dense>
          Aún no hay respuestas para este formulario.
        </v-alert>
        <v-card
          v-for="r in responses" :key="r.id" outlined class="mb-3"
        >
          <v-card-text>
            <div class="d-flex align-center">
              <strong>{{ r.student_name || ('#' + r.userid) }}</strong>
              <span class="caption grey--text ml-2">{{ r.email }}</span>
              <v-spacer></v-spacer>
              <span class="caption grey--text text--darken-1">
                {{ new Date(Number(r.submitted_at) * 1000).toLocaleString('es-PA') }}
              </span>
            </div>
            <v-divider class="my-2"></v-divider>
            <div v-if="r.answers && Object.keys(r.answers).length">
              <div v-for="(v, k) in r.answers" :key="k" class="mb-1">
                <span class="caption grey--text">{{ k }}:</span>
                <span class="ml-2">{{ formatAnswer(v) }}</span>
              </div>
            </div>
            <div v-else class="caption grey--text text--darken-1 font-italic">
              (Respuesta vacía)
            </div>
          </v-card-text>
        </v-card>
      </v-card-text>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snack.show" :color="snack.color" timeout="5000" right bottom multi-line>
    {{ snack.text }}
  </v-snackbar>
</v-container>
`
});

