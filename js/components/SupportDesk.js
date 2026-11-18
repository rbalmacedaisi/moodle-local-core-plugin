/**
 * SupportDesk.js
 * Bandeja del personal de la mesa de ayuda (20261001120, §8.1 del plan).
 * Montado por pages/support_desk.php. El servidor decide que tickets ve cada
 * quien (local\support\manager); aqui solo se pinta.
 */
Vue.component('support-desk', {
    props: { config: { type: Object, default: () => ({}) } },
    template: `
    <v-container fluid class="pa-2 pa-md-4">
      <div class="d-flex align-center flex-wrap mb-3">
        <v-icon color="primary" class="mr-2">mdi-lifebuoy</v-icon>
        <span class="text-h6 mr-4">Mesa de ayuda</span>
        <v-switch v-model="available" inset hide-details dense class="mt-0 mr-4" color="success"
          :label="available ? 'Disponible' : 'Ausente'" @change="setAvailability"></v-switch>
        <v-spacer></v-spacer>
        <v-text-field v-model="search" dense outlined hide-details clearable prepend-inner-icon="mdi-magnify"
          placeholder="Número, asunto o estudiante" style="max-width: 320px" class="mr-2"
          @input="debouncedList"></v-text-field>
        <v-btn v-if="config.settingsurl" small outlined color="primary" :href="config.settingsurl">
          <v-icon left small>mdi-cog</v-icon> Configuración
        </v-btn>
      </div>

      <v-card outlined class="d-flex" style="min-height: 560px; height: calc(100vh - 220px); overflow: hidden;">
        <!-- Lista -->
        <div class="d-flex flex-column" :style="{ width: isMobile && active ? '0' : (isMobile ? '100%' : '420px'),
             borderRight: '1px solid rgba(0,0,0,0.12)', display: isMobile && active ? 'none' : 'flex' }">
          <v-tabs v-model="tabIndex" show-arrows height="40" @change="onTab">
            <v-tab v-for="t in visibleTabs" :key="t.key" class="px-2 text-none">
              <span :class="{ 'red--text font-weight-bold': t.key === 'unassigned' && count(t.key) > 0 }">{{ t.label }}</span>
              <v-chip x-small class="ml-1" :color="chipColor(t.key)" :dark="count(t.key) > 0 && t.key !== 'closed'">{{ count(t.key) }}</v-chip>
            </v-tab>
          </v-tabs>
          <v-divider></v-divider>
          <v-progress-linear v-if="loadingList" indeterminate color="primary"></v-progress-linear>
          <div class="flex-grow-1" style="overflow-y: auto;">
            <div v-if="!loadingList && tickets.length === 0" class="pa-6 text-center text--secondary">
              <v-icon large>mdi-inbox-outline</v-icon>
              <p class="mt-2 mb-0">No hay tickets en esta pestaña.</p>
            </div>
            <v-list two-line dense class="py-0">
              <template v-for="t in tickets">
                <v-list-item :key="t.id" @click="openTicket(t.id)" :input-value="active && active.id === t.id">
                  <v-list-item-avatar size="14" class="mr-3 align-self-start mt-4">
                    <v-icon small :color="fmt.semaphoreColor(t.semaphore)">mdi-circle</v-icon>
                  </v-list-item-avatar>
                  <v-list-item-content>
                    <v-list-item-title :class="{ 'font-weight-bold': t.unread > 0 }">
                      <v-icon v-if="t.confidential" x-small color="purple" class="mr-1">mdi-lock</v-icon>
                      {{ t.number }} · {{ t.requestername }}
                    </v-list-item-title>
                    <v-list-item-subtitle>{{ t.areaname }} / {{ t.categoryname }}</v-list-item-subtitle>
                    <v-list-item-subtitle class="text-caption">
                      <v-chip x-small label :color="fmt.statusColor(t.status)" dark class="mr-1">{{ t.statuslabel }}</v-chip>
                      <v-chip v-if="t.priority === 2" x-small label color="red" outlined class="mr-1">Alta</v-chip>
                      <span v-if="t.assignedname">{{ t.assignedname }}</span><span v-else class="red--text">Sin asignar</span>
                    </v-list-item-subtitle>
                  </v-list-item-content>
                  <v-list-item-action class="align-self-start mt-3">
                    <v-list-item-action-text>{{ fmt.waiting(t.waitingminutes) || shortDate(t.lastmessageat || t.timecreated) }}</v-list-item-action-text>
                    <v-chip v-if="t.unread > 0" x-small color="pink" dark class="mt-1">{{ t.unread }}</v-chip>
                  </v-list-item-action>
                </v-list-item>
                <v-divider :key="'d' + t.id"></v-divider>
              </template>
            </v-list>
          </div>
          <div v-if="total > tickets.length" class="pa-2 text-caption text-center text--secondary">
            Mostrando {{ tickets.length }} de {{ total }}. Usa la búsqueda para acotar.
          </div>
        </div>

        <!-- Ticket -->
        <div class="d-flex flex-column flex-grow-1" style="min-width: 0;" v-if="!isMobile || active">
          <template v-if="active">
            <div class="pa-3">
              <div class="d-flex align-center flex-wrap">
                <v-btn v-if="isMobile" icon class="mr-1" @click="active = null"><v-icon>mdi-arrow-left</v-icon></v-btn>
                <span class="text-h6 mr-2">{{ active.number }}</span>
                <v-chip small label :color="fmt.statusColor(active.status)" dark class="mr-2">{{ active.statuslabel }}</v-chip>
                <v-chip v-if="active.confidential" small label color="purple" dark class="mr-2"><v-icon x-small left>mdi-lock</v-icon>Confidencial</v-chip>
                <v-chip v-if="active.semaphore !== 'none'" small label outlined :color="fmt.semaphoreColor(active.semaphore)" class="mr-2">
                  <v-icon x-small left>mdi-timer-sand</v-icon>Esperando {{ fmt.waiting(active.waitingminutes) || '0 min' }}
                </v-chip>
                <v-spacer></v-spacer>
                <v-btn icon small title="Actualizar" @click="refreshActive(true)"><v-icon small>mdi-refresh</v-icon></v-btn>
              </div>
              <div class="text-subtitle-2 mt-1">{{ active.subject }}</div>
              <div class="text-caption text--secondary">
                {{ active.requestername }}<span v-if="active.requesteridnumber"> · {{ active.requesteridnumber }}</span>
                <span v-if="active.requesteremail"> · {{ active.requesteremail }}</span>
                · {{ active.areaname }} / {{ active.categoryname }}
                · Atiende: <b>{{ active.assignedname || 'Sin asignar' }}</b>
                · Creado {{ fmt.date(active.timecreated) }}
                <span v-if="previoustickets"> · {{ previoustickets }} ticket(s) anteriores</span>
              </div>
              <div class="mt-2 d-flex flex-wrap" style="gap: 6px;">
                <v-btn v-if="can('take')" small color="primary" depressed :loading="busy === 'take'" @click="act('take')">
                  <v-icon left small>mdi-hand-back-right</v-icon>Tomar</v-btn>
                <v-btn v-if="can('assign')" small outlined @click="openAssign"><v-icon left small>mdi-account-switch</v-icon>Reasignar</v-btn>
                <v-btn v-if="can('unassign')" small outlined :loading="busy === 'unassign'" @click="act('unassign')">
                  <v-icon left small>mdi-tray-arrow-up</v-icon>Devolver a la cola</v-btn>
                <v-btn v-if="can('resolve')" small outlined color="success" :loading="busy === 'resolve'" @click="confirmAct('resolve', '¿Marcar como resuelto? El estudiante confirmará si se resolvió.')">
                  <v-icon left small>mdi-check-circle</v-icon>Resolver</v-btn>
                <v-btn v-if="can('close')" small text color="grey darken-1" :loading="busy === 'close'" @click="confirmAct('close', '¿Cerrar el ticket sin esperar la confirmación del estudiante?')">
                  Cerrar</v-btn>
                <v-menu v-if="can('set_priority')" offset-y>
                  <template v-slot:activator="{ on }">
                    <v-btn small text v-on="on">Prioridad: {{ fmt.priorityLabel(active.priority) }} <v-icon small>mdi-menu-down</v-icon></v-btn>
                  </template>
                  <v-list dense>
                    <v-list-item v-for="p in [0, 1, 2]" :key="p" @click="act('set_priority', { priority: p })">
                      <v-list-item-title>{{ fmt.priorityLabel(p) }}</v-list-item-title>
                    </v-list-item>
                  </v-list>
                </v-menu>
                <v-btn v-if="can('set_category')" small text @click="openCategory">Cambiar tema</v-btn>
                <v-btn small text @click="showHistory = !showHistory">{{ showHistory ? 'Ocultar' : 'Ver' }} historial</v-btn>
              </div>
            </div>
            <v-divider></v-divider>
            <div v-if="showHistory" class="pa-3 grey lighten-4" style="max-height: 180px; overflow-y: auto;">
              <div v-for="e in events" :key="e.id" class="text-caption">
                <b>{{ fmt.date(e.timecreated) }}</b> · {{ e.actorname }} · {{ e.description }}
              </div>
            </div>
            <div ref="scroller" class="flex-grow-1 pa-3" style="overflow-y: auto; background: rgba(0,0,0,0.02);">
              <div v-if="hasMore" class="text-center mb-2">
                <v-btn small text color="primary" :loading="loadingOlder" @click="loadOlder">Ver mensajes anteriores</v-btn>
              </div>
              <div v-for="m in messages" :key="m.id" class="d-flex mb-2"
                   :class="m.senderrole === 'system' ? 'justify-center' : (m.senderrole === 'student' ? 'justify-start' : 'justify-end')">
                <div v-if="m.senderrole === 'system'" class="text-caption text--secondary px-3 py-1 rounded grey lighten-3" style="max-width: 80%; white-space: pre-wrap;">
                  <v-icon x-small class="mr-1">mdi-robot-outline</v-icon>{{ m.body }}
                </div>
                <div v-else class="px-3 py-2 rounded-lg" style="max-width: 78%;"
                     :class="m.internal ? 'amber lighten-4' : (m.senderrole === 'student' ? 'white elevation-1' : 'blue lighten-5')">
                  <div class="text-caption font-weight-medium mb-1">
                    {{ m.sendername }}<span v-if="m.internal" class="ml-1 amber--text text--darken-4">· Nota interna (el estudiante no la ve)</span>
                  </div>
                  <div style="white-space: pre-wrap; word-break: break-word;">{{ m.body }}</div>
                  <div class="text-right mt-1" style="font-size: 11px; opacity: .7;">{{ fmt.date(m.timecreated) }}</div>
                </div>
              </div>
            </div>
            <v-divider></v-divider>
            <div class="pa-3">
              <v-alert v-if="!active.canwrite" dense text type="info" class="mb-0">El ticket está {{ active.statuslabel.toLowerCase() }}; no admite mensajes.</v-alert>
              <template v-else>
                <v-btn-toggle v-model="mode" mandatory dense class="mb-2">
                  <v-btn small value="reply">Responder</v-btn>
                  <v-btn small value="askinfo">Pedir información</v-btn>
                  <v-btn small value="note">Nota interna</v-btn>
                </v-btn-toggle>
                <v-alert v-if="sendError" dense text type="error" class="mb-2" dismissible @input="sendError = ''">{{ sendError }}</v-alert>
                <div class="d-flex align-end">
                  <v-textarea v-model="draft" outlined dense hide-details auto-grow rows="2" maxlength="4000"
                    :background-color="mode === 'note' ? 'amber lighten-5' : ''"
                    :placeholder="placeholder" @keydown.enter.ctrl.prevent="send"></v-textarea>
                  <v-btn color="primary" class="ml-2" fab small depressed :loading="sending"
                    :disabled="!draft || !draft.trim()" @click="send"><v-icon>mdi-send</v-icon></v-btn>
                </div>
                <div class="text-caption text--secondary mt-1">Ctrl+Enter envía. {{ modeHelp }}</div>
              </template>
            </div>
          </template>
          <div v-else class="d-flex flex-column align-center justify-center flex-grow-1 text--secondary pa-6 text-center">
            <v-icon size="64">mdi-lifebuoy</v-icon>
            <p class="mt-2">Selecciona un ticket de la lista.</p>
          </div>
        </div>
      </v-card>

      <v-dialog v-model="assignDialog.open" max-width="480">
        <v-card>
          <v-card-title>Reasignar {{ active && active.number }}</v-card-title>
          <v-card-text>
            <v-progress-linear v-if="assignDialog.loading" indeterminate></v-progress-linear>
            <v-list dense>
              <v-list-item v-for="u in assignDialog.users" :key="u.id" @click="assignTo(u.id)"
                :disabled="active && u.id === active.assignedto">
                <v-list-item-content>
                  <v-list-item-title>{{ u.fullname }}</v-list-item-title>
                  <v-list-item-subtitle>{{ u.openload }} abiertos · {{ u.available ? 'Disponible' : 'Ausente' }}</v-list-item-subtitle>
                </v-list-item-content>
              </v-list-item>
            </v-list>
            <p v-if="!assignDialog.loading && !assignDialog.users.length" class="text--secondary">No hay agentes configurados para este tema.</p>
          </v-card-text>
          <v-card-actions><v-spacer></v-spacer><v-btn text @click="assignDialog.open = false">Cerrar</v-btn></v-card-actions>
        </v-card>
      </v-dialog>

      <v-dialog v-model="categoryDialog.open" max-width="480">
        <v-card>
          <v-card-title>Cambiar tema</v-card-title>
          <v-card-text>
            <v-select v-model="categoryDialog.categoryid" :items="categoryDialog.items" item-text="label" item-value="id"
              outlined dense label="Tema"></v-select>
            <v-alert dense text type="warning" class="mb-0" v-if="active && active.confidential">
              El ticket es confidencial y lo seguirá siendo aunque cambie de tema.</v-alert>
          </v-card-text>
          <v-card-actions><v-spacer></v-spacer>
            <v-btn text @click="categoryDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :disabled="!categoryDialog.categoryid" @click="saveCategory">Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <v-snackbar v-model="snack.show" :color="snack.color" timeout="5000">{{ snack.text }}</v-snackbar>
    </v-container>
    `,
    data() {
        return {
            fmt: window.gmkSupportFormat,
            allTabs: [
                { key: 'unassigned', label: 'Sin asignar' },
                { key: 'mine', label: 'Míos' },
                { key: 'area', label: 'Mi área' },
                { key: 'overdue', label: 'Vencidos' },
                { key: 'all', label: 'Todos' },
                { key: 'closed', label: 'Cerrados' },
            ],
            tabIndex: 1,
            counts: {},
            me: { supervisor: false, available: true, manage: false },
            available: true,
            tickets: [],
            total: 0,
            search: '',
            loadingList: false,
            active: null,
            messages: [],
            events: [],
            previoustickets: 0,
            hasMore: false,
            loadingOlder: false,
            showHistory: false,
            draft: '',
            mode: 'reply',
            sending: false,
            sendError: '',
            busy: '',
            assignDialog: { open: false, loading: false, users: [] },
            categoryDialog: { open: false, categoryid: 0, items: [] },
            snack: { show: false, text: '', color: 'error' },
            timers: [],
            searchTimer: null,
        };
    },
    computed: {
        visibleTabs() {
            return this.allTabs.filter(t => t.key !== 'all' || this.me.supervisor);
        },
        currentTab() {
            const t = this.visibleTabs[this.tabIndex];
            return t ? t.key : 'mine';
        },
        isMobile() {
            return this.$vuetify.breakpoint.smAndDown;
        },
        placeholder() {
            return { reply: 'Respuesta al estudiante', askinfo: 'Qué información necesitas del estudiante', note: 'Nota interna para el equipo' }[this.mode];
        },
        modeHelp() {
            return {
                reply: 'El estudiante recibe la respuesta en el portal y por correo.',
                askinfo: 'El ticket queda en "Esperando estudiante" y el reloj se detiene.',
                note: 'Solo la ve el personal.',
            }[this.mode];
        },
    },
    async mounted() {
        await this.loadList();
        if (this.config.ticketid) {
            await this.openTicket(parseInt(this.config.ticketid, 10));
        }
        this.timers.push(setInterval(() => { if (!document.hidden) this.loadList(true); }, 30000));
        this.timers.push(setInterval(() => { if (!document.hidden && this.active) this.refreshActive(false); }, 15000));
    },
    beforeDestroy() {
        this.timers.forEach(clearInterval);
        clearTimeout(this.searchTimer);
    },
    methods: {
        call(name, args) {
            return window.gmkSupportCall('local_grupomakro_support_' + name, args);
        },
        notify(text, color) {
            this.snack = { show: true, text: text, color: color || 'error' };
        },
        count(key) {
            return this.counts[key] || 0;
        },
        chipColor(key) {
            if (!this.count(key) || key === 'closed') return '';
            return { unassigned: 'red', overdue: 'red', mine: 'primary' }[key] || 'blue-grey';
        },
        can(action) {
            return !!(this.active && this.active.actions && this.active.actions.indexOf(action) !== -1);
        },
        shortDate(ts) {
            if (!ts) return '';
            const d = new Date(ts * 1000);
            if (d.toDateString() === new Date().toDateString()) {
                return d.toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString('es-PA', { day: '2-digit', month: 'short' });
        },
        onTab() {
            this.loadList();
        },
        debouncedList() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.loadList(), 400);
        },
        async loadList(silent) {
            if (!silent) this.loadingList = true;
            try {
                const data = await this.call('desk_list', { tab: this.currentTab, search: this.search || '', page: 0, perpage: 100 });
                const counts = {};
                (data.counts || []).forEach(c => { counts[c.tab] = c.count; });
                this.counts = counts;
                const wassupervisor = this.me.supervisor;
                this.me = data.me;
                this.available = data.me.available;
                this.tickets = data.tickets || [];
                this.total = data.total || 0;
                if (!wassupervisor && data.me.supervisor && this.tabIndex >= 4) {
                    // "Todos" appeared: keep the same tab selected.
                    this.tabIndex++;
                }
            } catch (e) {
                if (!silent) this.notify(e.message);
            } finally {
                this.loadingList = false;
            }
        },
        async openTicket(id) {
            this.sendError = '';
            this.draft = '';
            this.mode = 'reply';
            try {
                const data = await this.call('get_ticket', { ticketid: id, beforeid: 0, limit: 50 });
                this.applyTicket(data);
                this.scrollToBottom();
                await this.markRead();
            } catch (e) {
                this.notify(e.message);
            }
        },
        applyTicket(data) {
            this.active = data.ticket;
            this.messages = data.messages || [];
            this.events = data.events || [];
            this.previoustickets = data.previoustickets || 0;
            this.hasMore = !!data.hasmore;
        },
        async refreshActive(manual) {
            if (!this.active) return;
            const lastid = this.messages.length ? this.messages[this.messages.length - 1].id : 0;
            try {
                const data = await this.call('get_ticket', { ticketid: this.active.id, beforeid: 0, limit: 50 });
                const fresh = (data.messages || []).filter(m => m.id > lastid);
                this.active = data.ticket;
                this.events = data.events || [];
                if (fresh.length) {
                    this.messages = this.messages.concat(fresh);
                    this.scrollToBottom();
                    await this.markRead();
                }
            } catch (e) {
                if (manual) this.notify(e.message);
            }
        },
        async loadOlder() {
            if (!this.active || !this.messages.length) return;
            this.loadingOlder = true;
            try {
                const data = await this.call('get_ticket', { ticketid: this.active.id, beforeid: this.messages[0].id, limit: 50 });
                this.messages = (data.messages || []).concat(this.messages);
                this.hasMore = !!data.hasmore;
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.loadingOlder = false;
            }
        },
        async markRead() {
            if (!this.active || !this.active.unread) return;
            try {
                await this.call('mark_read', { ticketid: this.active.id });
                this.active.unread = 0;
                const row = this.tickets.find(t => t.id === this.active.id);
                if (row) row.unread = 0;
            } catch (e) {
                // Not critical.
            }
        },
        async send() {
            const body = (this.draft || '').trim();
            if (!body || this.sending || !this.active) return;
            this.sending = true;
            this.sendError = '';
            try {
                const data = await this.call('send_message', {
                    ticketid: this.active.id, body: body,
                    internal: this.mode === 'note', askinfo: this.mode === 'askinfo',
                });
                this.draft = '';
                this.mode = 'reply';
                await this.refreshActive(true);
                this.loadList(true);
            } catch (e) {
                this.sendError = e.message;
            } finally {
                this.sending = false;
            }
        },
        async act(action, extra) {
            if (!this.active) return;
            this.busy = action;
            try {
                const data = await this.call('desk_action', Object.assign({
                    ticketid: this.active.id, action: action, targetuserid: 0, priority: -1, categoryid: 0,
                }, extra || {}));
                if (!data.visible) {
                    this.active = null;
                    this.notify('El ticket se movió a un tema que no puedes ver.', 'info');
                } else {
                    await this.refreshActive(true);
                }
                this.loadList(true);
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.busy = '';
            }
        },
        async confirmAct(action, text) {
            if (this.$swal) {
                const r = await this.$swal.fire({ text: text, icon: 'question', showCancelButton: true,
                    confirmButtonText: 'Sí', cancelButtonText: 'Cancelar' });
                if (!r.isConfirmed) return;
            } else if (!window.confirm(text)) {
                return;
            }
            await this.act(action);
        },
        async openAssign() {
            this.assignDialog = { open: true, loading: true, users: [] };
            try {
                const data = await this.call('get_assignable', { ticketid: this.active.id });
                this.assignDialog.users = data.users || [];
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.assignDialog.loading = false;
            }
        },
        async assignTo(userid) {
            this.assignDialog.open = false;
            await this.act('assign', { targetuserid: userid });
        },
        async openCategory() {
            try {
                const data = await this.call('get_assignable', { ticketid: this.active.id });
                this.categoryDialog = {
                    open: true,
                    categoryid: this.active.categoryid,
                    items: (data.categories || []).map(c => ({ id: c.id, label: c.areaname + ' / ' + c.name + (c.confidential ? ' (confidencial)' : '') })),
                };
            } catch (e) {
                this.notify(e.message);
            }
        },
        async saveCategory() {
            const id = this.categoryDialog.categoryid;
            this.categoryDialog.open = false;
            await this.act('set_category', { categoryid: id });
        },
        async setAvailability(value) {
            try {
                const data = await this.call('set_availability', { available: !!value });
                this.available = data.available;
                this.notify(data.available ? 'Quedaste disponible: recibirás tickets nuevos.' : 'Quedaste ausente: no recibirás tickets nuevos.', 'info');
            } catch (e) {
                this.available = !value;
                this.notify(e.message);
            }
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.scroller;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
    },
});
