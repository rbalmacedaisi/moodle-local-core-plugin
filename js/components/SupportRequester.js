/**
 * SupportRequester.js
 * Mesa de ayuda, fase 2 (20261001121, §8.4): "Ayuda TI" para docentes en
 * Moodle. Mis solicitudes, nueva solicitud (solo areas para docentes), chat,
 * "¿Se resolvió tu caso?" y calificacion 1-5 + "¿Te resolvimos?" + comentario.
 * Usa los mismos WS que el portal, con audience=teacher.
 */
Vue.component('support-requester', {
    props: { config: { type: Object, default: () => ({}) } },
    template: `
    <v-container fluid class="gmk-sup pa-2 pa-md-4" style="max-width: 1100px;">
      <div class="d-flex align-center mb-3">
        <v-icon color="primary" class="mr-2">mdi-lifebuoy</v-icon>
        <span class="text-subtitle-1 text-sm-h6 font-weight-medium">Ayuda TI</span>
        <v-spacer></v-spacer>
        <v-btn color="primary" depressed small @click="openNew"><v-icon left small>mdi-plus</v-icon>Nueva solicitud</v-btn>
      </div>
      <div class="gmk-desk" :class="{ 'gmk-desk--open': !!active }">
        <div class="gmk-desk__list">
          <v-tabs v-model="filterIndex" height="44" @change="loadList">
            <v-tab class="text-none">Abiertas</v-tab><v-tab class="text-none">Cerradas</v-tab>
          </v-tabs>
          <v-divider></v-divider>
          <v-progress-linear v-if="loading" indeterminate></v-progress-linear>
          <div class="gmk-desk__scroll">
            <div v-if="!loading && !tickets.length" class="pa-6 text-center text--secondary">
              <v-icon large>mdi-inbox-outline</v-icon>
              <p class="mt-2 mb-0">No tienes solicitudes {{ filterIndex === 0 ? 'abiertas' : 'cerradas' }}.</p>
            </div>
            <v-list two-line class="py-0">
              <v-list-item v-for="t in tickets" :key="t.id" @click="openTicket(t.id)" :input-value="active && active.id === t.id">
                <v-list-item-content>
                  <v-list-item-title :class="{ 'font-weight-bold': t.unread > 0 }" class="gmk-wrap">{{ t.number }} · {{ t.subject }}</v-list-item-title>
                  <v-list-item-subtitle>
                    <v-chip x-small label :color="fmt.statusColor(t.status)" dark class="mr-1">{{ t.statuslabel }}</v-chip>
                    {{ t.areaname }} / {{ t.categoryname }}
                  </v-list-item-subtitle>
                </v-list-item-content>
                <v-list-item-action class="ml-2">
                  <v-list-item-action-text>{{ fmt.date(t.lastmessageat || t.timecreated) }}</v-list-item-action-text>
                  <v-chip v-if="t.unread > 0" x-small color="pink" dark>{{ t.unread }}</v-chip>
                </v-list-item-action>
              </v-list-item>
            </v-list>
          </div>
        </div>
        <div class="gmk-desk__chat">
          <template v-if="active">
            <div class="d-flex align-center px-1 px-md-3 pt-1 pt-md-2">
              <v-btn v-if="isMobile" icon @click="closeTicket" aria-label="Volver a mis solicitudes"><v-icon>mdi-arrow-left</v-icon></v-btn>
              <div class="gmk-min0 flex-grow-1 d-flex align-center flex-wrap" style="gap: 4px 8px;">
                <span class="text-subtitle-1 text-md-h6 font-weight-medium">{{ active.number }}</span>
                <v-chip small label :color="fmt.statusColor(active.status)" dark>{{ active.statuslabel }}</v-chip>
              </div>
              <v-btn v-if="active.actions.indexOf('cancel') >= 0" small text color="error" @click="cancel">Cancelar</v-btn>
            </div>
            <div class="text-caption text--secondary px-3 pb-2 gmk-wrap">{{ active.areaname }} / {{ active.categoryname }} · {{ active.subject }}
              <span v-if="active.assignedname"> · Te atiende {{ active.assignedname }}</span></div>
            <v-divider></v-divider>
            <div ref="scroll" class="gmk-desk__messages pa-2 pa-md-3">
              <div v-for="m in messages" :key="m.id" class="d-flex mb-2" :class="m.mine ? 'justify-end' : 'justify-start'">
                <div :style="bubble(m)" class="gmk-bubble">
                  <div class="text-caption font-weight-medium">{{ m.senderrole === 'system' ? 'Mesa de ayuda' : m.sendername }}</div>
                  <div v-if="m.body">{{ m.body }}</div>
                  <support-attachments :items="m.attachments"></support-attachments>
                  <div class="text-caption text--secondary text-right">{{ fmt.date(m.timecreated) }}</div>
                </div>
              </div>
            </div>
            <div class="gmk-desk__composer pa-2 pa-md-3">
              <v-alert v-if="active.actions.indexOf('confirm') >= 0" type="info" text dense class="mb-2">
                <div class="mb-2">¿Se resolvió tu caso?</div>
                <div class="d-flex" style="gap: 8px;">
                  <v-btn small color="success" depressed :loading="busy" @click="confirm(true)">Sí</v-btn>
                  <v-btn small outlined color="error" :loading="busy" @click="confirm(false)">No</v-btn>
                </div>
              </v-alert>
              <v-alert v-if="!active.isopen && active.rating > 0" type="success" text dense class="mb-2">
                Calificaste la atención con {{ active.rating }} de 5. ¡Gracias!
              </v-alert>
              <v-btn v-if="!active.isopen && !active.rating && active.status === 'cerrado'" block depressed color="amber" class="mb-2"
                @click="rateDialog.open = true"><v-icon left>mdi-star</v-icon>Calificar la atención</v-btn>
              <support-attach-picker v-if="active.canwrite" ref="picker" :key="'pk' + active.id" :limits="attachlimits"
                :ticketid="active.id" :disabled="sending" @state="attach = $event" @error="notify($event)"></support-attach-picker>
              <div v-if="active.canwrite" class="d-flex align-end">
                <v-textarea v-model="draft" outlined dense auto-grow rows="1" hide-details placeholder="Escribe tu mensaje"
                  class="mr-2" :maxlength="4000"></v-textarea>
                <v-btn color="primary" fab small depressed :loading="sending || attach.busy" :disabled="!canSend" aria-label="Enviar" @click="send">
                  <v-icon>mdi-send</v-icon></v-btn>
              </div>
            </div>
          </template>
          <div v-else class="d-flex flex-column align-center justify-center flex-grow-1 text--secondary pa-6 text-center">
            <v-icon large>mdi-message-text-outline</v-icon>
            <p class="mt-2 mb-0">Elige una solicitud o abre una nueva. Soporte Tecnológico te responde aquí y por la campanita de Moodle.</p>
          </div>
        </div>
      </div>

      <!-- New request -->
      <v-dialog v-model="newDialog.open" max-width="560" :fullscreen="$vuetify.breakpoint.xsOnly" persistent>
        <v-card>
          <v-card-title>Nueva solicitud</v-card-title>
          <v-card-text>
            <v-progress-linear v-if="!catalog" indeterminate></v-progress-linear>
            <template v-else>
              <v-alert v-if="!topics.length" type="warning" text dense>No hay temas disponibles para docentes en este momento.</v-alert>
              <v-select v-model="newDialog.categoryid" :items="topics" item-text="label" item-value="id" label="Tema" outlined dense></v-select>
              <v-alert v-if="selectedArea && !selectedArea.isopen" type="info" text dense>
                Estamos fuera del horario de atención ({{ selectedArea.hours }}). Te responderemos al inicio de la siguiente jornada.
              </v-alert>
              <v-text-field v-model="newDialog.subject" label="Asunto (opcional)" outlined dense maxlength="200"></v-text-field>
              <v-textarea v-model="newDialog.body" label="Describe lo que necesitas" outlined auto-grow rows="4" :maxlength="catalog.max_body"></v-textarea>
              <v-alert v-if="newDialog.error" type="error" text dense>{{ newDialog.error }}</v-alert>
            </template>
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="newDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :loading="newDialog.busy" :disabled="!newDialog.categoryid || !newDialog.body.trim()" @click="create">Enviar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Rating -->
      <v-dialog v-model="rateDialog.open" max-width="480" :fullscreen="$vuetify.breakpoint.xsOnly" persistent>
        <v-card v-if="active">
          <v-card-title>¿Cómo te atendimos?</v-card-title>
          <v-card-text>
            <p class="mb-2">Solicitud {{ active.number }} · {{ active.areaname }}</p>
            <v-rating v-model="rateDialog.rating" length="5" hover large color="amber" background-color="grey lighten-1"></v-rating>
            <div class="mt-3 mb-1">¿Te resolvimos lo que necesitabas?</div>
            <v-btn-toggle v-model="rateDialog.solved" mandatory dense>
              <v-btn :value="true" small>Sí</v-btn><v-btn :value="false" small>No</v-btn>
            </v-btn-toggle>
            <v-textarea v-model="rateDialog.comment" label="Comentario (opcional)" outlined auto-grow rows="2" class="mt-4" maxlength="1000"></v-textarea>
          </v-card-text>
          <v-card-actions>
            <v-btn text @click="dismissRating">Ahora no</v-btn>
            <v-spacer></v-spacer>
            <v-btn color="primary" depressed :disabled="!rateDialog.rating" :loading="rateDialog.busy" @click="rate">Enviar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <v-snackbar v-model="snack.show" :color="snack.color" timeout="5000">{{ snack.text }}</v-snackbar>
    </v-container>`,
    data() {
        return {
            fmt: window.gmkSupportFormat,
            filterIndex: 0,
            tickets: [],
            loading: false,
            active: null,
            messages: [],
            draft: '',
            sending: false,
            busy: false,
            catalog: null,
            newDialog: { open: false, categoryid: null, subject: '', body: '', busy: false, error: '' },
            rateDialog: { open: false, rating: 0, solved: true, comment: '', busy: false },
            attachlimits: null,
            attach: { busy: false, failed: false, count: 0, draftitemid: 0 },
            snack: { show: false, text: '', color: 'error' },
            timers: [],
        };
    },
    computed: {
        isMobile() {
            return this.$vuetify.breakpoint.smAndDown;
        },
        canSend() {
            return !this.sending && !this.attach.busy && !this.attach.failed && (!!this.draft.trim() || this.attach.count > 0);
        },
        topics() {
            if (!this.catalog) return [];
            const out = [];
            this.catalog.areas.filter(a => !a.external).forEach(a => a.topics.forEach(t => out.push({ id: t.id, label: a.name + ' / ' + t.name, areaid: a.id })));
            return out;
        },
        selectedArea() {
            const t = this.topics.find(x => x.id === this.newDialog.categoryid);
            return t && this.catalog ? this.catalog.areas.find(a => a.id === t.areaid) : null;
        },
    },
    async mounted() {
        await this.loadList();
        if (this.config.ticketid) {
            await this.openTicket(parseInt(this.config.ticketid, 10));
            if (this.config.rate && this.active && this.active.status === 'cerrado' && !this.active.rating) {
                this.rateDialog.open = true;
            }
        } else if (this.config.new) {
            this.openNew();
        }
        this.timers.push(setInterval(() => { if (!document.hidden && this.active) this.refreshActive(); }, 15000));
        this.timers.push(setInterval(() => { if (!document.hidden) this.loadList(); }, 60000));
    },
    created() {
        this.onPopState = () => {
            if (this.active && !(window.history.state && window.history.state.gmkTicket)) this.active = null;
        };
        window.addEventListener('popstate', this.onPopState);
    },
    beforeDestroy() {
        window.removeEventListener('popstate', this.onPopState);
        this.timers.forEach(clearInterval);
    },
    methods: {
        call(name, args) {
            return window.gmkSupportCall('local_grupomakro_support_' + name, args);
        },
        notify(text, color) {
            this.snack = { show: true, text: text, color: color || 'error' };
        },
        closeTicket() {
            if (window.history.state && window.history.state.gmkTicket) {
                window.history.back();
                return;
            }
            this.active = null;
        },
        bubble(m) {
            const bg = m.senderrole === 'system' ? '#eceff1' : (m.mine ? '#e3f2fd' : '#f1f8e9');
            return { background: bg, borderRadius: '10px', padding: '6px 10px' };
        },
        async loadList() {
            this.loading = true;
            try {
                const data = await this.call('get_my_tickets', { filter: this.filterIndex === 0 ? 'open' : 'closed' });
                this.tickets = data.tickets;
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.loading = false;
            }
        },
        async openTicket(id) {
            try {
                const data = await this.call('get_ticket', { ticketid: id, beforeid: 0, limit: 50 });
                this.active = data.ticket;
                this.messages = data.messages;
                if (data.attachlimits) this.attachlimits = data.attachlimits;
                if (this.isMobile && !(window.history.state && window.history.state.gmkTicket)) {
                    // Celular: el boton "atras" del telefono vuelve a la lista.
                    window.history.pushState({ gmkTicket: id }, '');
                }
                await this.call('mark_read', { ticketid: id });
                this.scrollDown();
                const row = this.tickets.find(t => t.id === id);
                if (row) row.unread = 0;
            } catch (e) {
                this.notify(e.message);
            }
        },
        async refreshActive() {
            try {
                const data = await this.call('get_ticket', { ticketid: this.active.id, beforeid: 0, limit: 50 });
                const grew = data.messages.length && (!this.messages.length || data.messages[data.messages.length - 1].id !== this.messages[this.messages.length - 1].id);
                this.active = data.ticket;
                this.messages = data.messages;
                if (data.attachlimits) this.attachlimits = data.attachlimits;
                if (grew) {
                    await this.call('mark_read', { ticketid: this.active.id });
                    this.scrollDown();
                }
            } catch (e) {
                // Transient: next poll retries.
            }
        },
        scrollDown() {
            this.$nextTick(() => { const el = this.$refs.scroll; if (el) el.scrollTop = el.scrollHeight; });
        },
        async send() {
            if (!this.canSend) return;
            this.sending = true;
            try {
                await this.call('send_message', { ticketid: this.active.id, body: this.draft,
                    draftitemid: this.attach.count > 0 ? this.attach.draftitemid : 0 });
                this.draft = '';
                if (this.$refs.picker) this.$refs.picker.reset();
                await this.refreshActive();
                this.scrollDown();
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.sending = false;
            }
        },
        async confirm(resolved) {
            this.busy = true;
            try {
                const data = await this.call('confirm_resolution', { ticketid: this.active.id, resolved: resolved });
                await this.openTicket(data.ticket.id);
                await this.loadList();
                if (resolved && this.active.status === 'cerrado') this.rateDialog.open = true;
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.busy = false;
            }
        },
        async cancel() {
            if (!window.confirm('¿Cancelar esta solicitud?')) return;
            try {
                await this.call('cancel_ticket', { ticketid: this.active.id });
                await this.openTicket(this.active.id);
                await this.loadList();
            } catch (e) {
                this.notify(e.message);
            }
        },
        async openNew() {
            this.newDialog = { open: true, categoryid: null, subject: '', body: '', busy: false, error: '' };
            if (!this.catalog) {
                try {
                    this.catalog = await this.call('get_catalog', { audience: 'teacher' });
                    if (this.topics.length === 1) this.newDialog.categoryid = this.topics[0].id;
                } catch (e) {
                    this.newDialog.error = e.message;
                    this.catalog = { areas: [], max_body: 4000 };
                }
            }
        },
        async create() {
            this.newDialog.busy = true;
            this.newDialog.error = '';
            try {
                const data = await this.call('create_ticket', {
                    categoryid: this.newDialog.categoryid, subject: this.newDialog.subject, body: this.newDialog.body,
                    classid: 0, audience: 'teacher',
                });
                this.newDialog.open = false;
                this.filterIndex = 0;
                await this.loadList();
                await this.openTicket(data.ticket.id);
                this.notify('Solicitud ' + data.ticket.number + ' enviada.', 'success');
            } catch (e) {
                this.newDialog.error = e.message;
            } finally {
                this.newDialog.busy = false;
            }
        },
        async rate() {
            this.rateDialog.busy = true;
            try {
                await this.call('rate_ticket', { ticketid: this.active.id, rating: this.rateDialog.rating, solved: this.rateDialog.solved,
                    comment: this.rateDialog.comment, dismiss: false });
                this.rateDialog.open = false;
                await this.openTicket(this.active.id);
                this.notify('¡Gracias por tu calificación!', 'success');
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.rateDialog.busy = false;
            }
        },
        async dismissRating() {
            try {
                await this.call('rate_ticket', { ticketid: this.active.id, dismiss: true });
            } catch (e) {
                // Ignore: it will simply come back.
            }
            this.rateDialog.open = false;
        },
    },
});
