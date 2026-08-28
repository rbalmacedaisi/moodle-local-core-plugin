/**
 * Wellness admin dashboard.
 *
 * Vue 2 component mounted by /local/grupomakro_core/pages/wellness_dashboard.php.
 * Three tabs:
 *   1. Convenios (RF-09.1) â€” CRUD over gmk_wellness_partner
 *   2. Eventos (RF-09.2) â€” CRUD over gmk_wellness_event + attachments + registrations export
 *   3. Formularios dinÃ¡micos (RF-06) â€” list of dynamic forms with schema preview
 *
 * The component calls the existing ajax.php dispatcher with the
 * `local_grupomakro_*` actions defined in db/services.php.
 */
Vue.component('wellness-dashboard', {
    data() {
        return {
            tab: 'partners',
            loading: false,
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
            regEvent: null,
            registrations: [],
            // Forms
            forms: [],
            formSchemaPreview: {},
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
        toast(text, color = 'success') {
            this.snack = { show: true, color, text };
        },
        async refreshAll() {
            await Promise.all([this.refreshPartners(), this.refreshEvents(), this.refreshForms()]);
        },
        async refreshPartners() {
            this.loading = true;
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_list_wellness_partners',
                    args: {}
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.partners = res.data.data.partners || [];
                    this.partnerCategories = res.data.data.categories || [];
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
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_list_wellness_events',
                    args: {}
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.events = res.data.data.events || [];
                }
            } catch (e) {
                this.toast('Error al cargar eventos: ' + (e.message || e), 'error');
            } finally {
                this.loading = false;
            }
        },
        async refreshForms() {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_list_wellness_dynamic_forms',
                    args: {}
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.forms = res.data.data.forms || [];
                }
            } catch (e) {
                this.toast('Error al cargar formularios: ' + (e.message || e), 'error');
            }
        },

        // â”€â”€ Partners â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        openPartnerDialog(p) {
            this.partner = p ? Object.assign(this._blankPartner(), p, {
                startdate_ts: p.startdate || 0,
                enddate_ts:   p.enddate   || 0,
                startdate:    p.startdate ? this._fmtDateTimeLocal(p.startdate) : '',
                enddate:      p.enddate   ? this._fmtDateTimeLocal(p.enddate)   : '',
            }) : this._blankPartner();
            this.partnerDialog = true;
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
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_save_wellness_partner',
                    args
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success') {
                    this.toast('Convenio guardado.');
                    this.partnerDialog = false;
                    await this.refreshPartners();
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error al guardar', 'error');
                }
            } catch (e) {
                this.toast('Error al guardar: ' + (e.message || e), 'error');
            } finally {
                this.partnerSaving = false;
            }
        },
        async togglePartnerActive(p) {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_toggle_wellness_partner_active',
                    args: { id: p.id, active: !p.active }
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success') {
                    this.toast(p.active ? 'Convenio desactivado' : 'Convenio activado');
                    await this.refreshPartners();
                }
            } catch (e) { this.toast('Error: ' + (e.message || e), 'error'); }
        },

        // â”€â”€ Carnets â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        async onCarnetUserQuery(value) {
            if (!value || value.length < 2) { this.carnetUserOptions = []; return }
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_search_users',
                    args: { query: value, limit: 8 },
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.carnetUserOptions = res.data.data.users || [];
                }
            } catch (e) { /* soft-fail */ }
        },
        async onCarnetAction() {
            if (!this.carnetUserid) return
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_manage_carnet',
                    args: { action: this.carnetAction, userid: this.carnetUserid },
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success') {
                    this.toast('Carnet actualizado.')
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error', 'error')
                }
            } catch (e) { this.toast('Error: ' + (e.message || e), 'error'); }
        },

        // â”€â”€ Events â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        openEventDialog(e) {
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
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_save_wellness_event',
                    args
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success') {
                    this.toast('Evento guardado.');
                    this.eventDialog = false;
                    await this.refreshEvents();
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error al guardar', 'error');
                }
            } catch (e) {
                this.toast('Error al guardar: ' + (e.message || e), 'error');
            } finally {
                this.eventSaving = false;
            }
        },
        async toggleEventActive(e) {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_toggle_wellness_event_active',
                    args: { id: e.id, active: !e.active }
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success') {
                    await this.refreshEvents();
                }
            } catch (err) { this.toast('Error: ' + (err.message || err), 'error'); }
        },
        async openRegistrations(e) {
            this.regEvent = e;
            this.registrations = [];
            this.regDialog = true;
            // Reuse the admin list_partners handler? No, we need a separate
            // fetch: list registrations for an event. Phase 1 keeps this
            // dialog simple: we show registered_count and offer a CSV export.
        },
        async exportCsv(e) {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_export_event_registrations',
                    args: { eventid: e.id }
                }, { params: { sesskey }, timeout: 30000 });
                if (res.data && res.data.status === 'success' && res.data.data && res.data.data.csv) {
                    const blob = new Blob([res.data.data.csv], { type: 'text/csv;charset=utf-8' });
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

        // â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        _fmtDateTimeLocal(ts) {
            const d = new Date(ts * 1000);
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        },
        formatDate(ts) {
            if (!ts) return 'â€”';
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
      <v-tab value="partners">
        <v-icon left>mdi-handshake-outline</v-icon> Convenios
      </v-tab>
      <v-tab value="events">
        <v-icon left>mdi-calendar-star</v-icon> Eventos
      </v-tab>
      <v-tab value="forms">
        <v-icon left>mdi-clipboard-text-outline</v-icon> Formularios dinÃ¡micos
      </v-tab>
      <v-tab value="carnets">
        <v-icon left>mdi-card-account-details-outline</v-icon> Carnets
      </v-tab>
    </v-tabs>

  <v-window v-model="tab" class="mt-4">
    <!-- â”€â”€ PARTNERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <v-window-item value="partners">
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
            { text: 'CategorÃ­a', value: 'category_name' },
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
              {{ formatDate(item.startdate) }} <br> â†’ {{ formatDate(item.enddate) }}
            </span>
            <span v-else-if="item.startdate">Desde {{ formatDate(item.startdate) }}</span>
            <span v-else-if="item.enddate">Hasta {{ formatDate(item.enddate) }}</span>
            <span v-else class="grey--text">Permanente</span>
          </template>
          <template v-slot:item.active="{ item }">
            <v-chip :color="item.active ? 'green' : 'grey'" small dark>
              {{ item.active ? 'SÃ­' : 'No' }}
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
    </v-window-item>

    <!-- â”€â”€ EVENTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <v-window-item value="events">
      <v-card>
        <v-card-title>
          <v-text-field v-model="eventSearch" label="Buscar evento" prepend-inner-icon="mdi-magnify" hide-details clearable></v-text-field>
          <v-select v-model="eventCategoryFilter" :items="eventCategoryItems" label="CategorÃ­a" hide-details style="max-width:220px" class="ml-3"></v-select>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="openEventDialog(null)">
            <v-icon left>mdi-plus</v-icon> Nuevo evento
          </v-btn>
        </v-card-title>
        <v-data-table
          :headers="[
            { text: 'TÃ­tulo', value: 'title' },
            { text: 'CategorÃ­a', value: 'category' },
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
              {{ item.active ? 'SÃ­' : 'No' }}
            </v-chip>
          </template>
          <template v-slot:item._actions="{ item }">
            <v-btn icon small @click="openEventDialog(item)"><v-icon>mdi-pencil</v-icon></v-btn>
            <v-btn icon small @click="exportCsv(item)" title="Exportar CSV"><v-icon>mdi-download</v-icon></v-btn>
            <v-btn icon small @click="toggleEventActive(item)">
              <v-icon>{{ item.active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline' }}</v-icon>
            </v-btn>
          </template>
        </v-data-table>
      </v-card>
    </v-window-item>

    <!-- â”€â”€ CARNETS (RF-07 / RF-09.4) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <v-window-item value="carnets">
      <v-card>
        <v-card-title>GestiÃ³n de carnets digitales</v-card-title>
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
              ]" label="AcciÃ³n"></v-select>
            </v-col>
          </v-row>
          <v-alert type="info" text class="mt-3">
            Las acciones se registran en la auditorÃ­a de la fila correspondiente. Para regenerar token,
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
            Aplicar acciÃ³n
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-window-item>

    <!-- â”€â”€ FORMS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <v-window-item value="forms">
      <v-card>
        <v-card-title>Formularios dinÃ¡micos</v-card-title>
        <v-card-text>
          <v-alert type="info" text>
            Los formularios dinÃ¡micos se crean desde la base de datos (tabla
            <code>gmk_wellness_dynamic_form</code>). Este panel es solo de
            lectura en esta fase. La ediciÃ³n se habilita en una iteraciÃ³n
            posterior.
          </v-alert>
          <v-data-table
            :headers="[
              { text: 'ID', value: 'id' },
              { text: 'TÃ­tulo', value: 'title' },
              { text: 'Evento', value: 'event_title' },
              { text: 'Respuestas', value: 'response_count', align: 'center' },
              { text: 'Activo', value: 'active', align: 'center' }
            ]"
            :items="forms"
            :loading="loading"
            dense
          >
            <template v-slot:item.active="{ item }">
              <v-chip :color="item.active ? 'green' : 'grey'" small dark>
                {{ item.active ? 'SÃ­' : 'No' }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-window-item>
  </v-window>

  <!-- Partner dialog -->
  <v-dialog v-model="partnerDialog" max-width="700" scrollable>
    <v-card>
      <v-card-title>{{ partner.id ? 'Editar convenio' : 'Nuevo convenio' }}</v-card-title>
      <v-card-text>
        <v-text-field v-model="partner.name" label="Nombre de la empresa" required></v-text-field>
        <v-select v-model="partner.categoryid" :items="categoryItems" label="CategorÃ­a" required></v-select>
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
            <v-text-field v-model="partner.contact_value" label="Valor (telÃ©fono, email, URL)"></v-text-field>
          </v-col>
        </v-row>
        <v-text-field v-model.number="partner.sort" label="Orden" type="number"></v-text-field>
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
        <v-text-field v-model="event.title" label="TÃ­tulo" required></v-text-field>
        <v-text-field v-model="event.summary" label="Resumen (una lÃ­nea)"></v-text-field>
        <v-textarea v-model="event.description" label="DescripciÃ³n" rows="3"></v-textarea>
        <v-row>
          <v-col cols="4">
            <v-select v-model="event.category" :items="[
              { text: 'Deportivo', value: 'deportivo' },
              { text: 'Feria', value: 'feria' },
              { text: 'Taller', value: 'taller' },
              { text: 'Charla', value: 'charla' },
              { text: 'Otro', value: 'otro' }
            ]" label="CategorÃ­a"></v-select>
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
        <v-text-field v-model="event.location" label="UbicaciÃ³n"></v-text-field>
        <v-text-field v-model="event.virtual_url" label="URL sala virtual (Zoom, Teams, etc.)"></v-text-field>
        <v-row>
          <v-col cols="6">
            <v-text-field v-model="event.organizer_name" label="Organizador"></v-text-field>
          </v-col>
          <v-col cols="6">
            <v-text-field v-model="event.organizer_email" label="Email del organizador"></v-text-field>
          </v-col>
        </v-row>
        <v-switch v-model="event.requires_registration" label="Requiere inscripciÃ³n" inset></v-switch>
        <v-switch v-model="event.allow_waitlist" label="Permitir lista de espera cuando se llene" inset></v-switch>
        <v-switch v-model="event.active" label="Activo" inset></v-switch>

        <v-divider class="my-3"></v-divider>
        <div class="d-flex align-center">
          <strong>Material adjunto</strong>
          <v-spacer></v-spacer>
          <v-btn small color="primary" outlined @click="addAttachment">
            <v-icon left small>mdi-plus</v-icon> AÃ±adir adjunto
          </v-btn>
        </div>
        <v-list dense>
          <v-list-item v-for="(a, i) in eventAttachments" :key="i">
            <v-list-item-content>
              <v-row dense>
                <v-col cols="3">
                  <v-select v-model="a.kind" :items="[
                    { text: 'Folleto', value: 'handout' },
                    { text: 'GrabaciÃ³n', value: 'recording' },
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

  <v-snackbar v-model="snack.show" :color="snack.color" timeout="3500" top>
    {{ snack.text }}
  </v-snackbar>
</v-container>
`
});
