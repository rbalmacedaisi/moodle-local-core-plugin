/**
 * LetterDesk.js
 * Cartas (20261001130): bandeja del personal, mobile first. Montado por
 * pages/letter_desk.php. El servidor (local\letters\desk) decide que acciones
 * valen para cada carta; aqui solo se pintan. Usa gmkSupportCall y los estilos
 * de SupportApi.js (lista a ancho completo en el celular, detalle a pantalla
 * completa; dos paneles en escritorio).
 */
Vue.component('letter-desk', {
    props: { config: { type: Object, default: () => ({}) } },
    template: `
    <v-container fluid class="gmk-sup pa-2 pa-md-4">
      <div class="gmk-desk-head mb-2 mb-md-3">
        <div class="d-flex align-center">
          <v-icon color="primary" class="mr-2">mdi-email-newsletter</v-icon>
          <span class="text-subtitle-1 text-sm-h6 font-weight-medium mr-2 text-no-wrap">Bandeja de cartas</span>
          <v-spacer></v-spacer>
          <v-btn icon :color="hasFilters ? 'primary' : ''" aria-label="Filtros" title="Filtros" @click="filtersOpen = !filtersOpen">
            <v-badge :value="hasFilters" dot color="primary"><v-icon>mdi-filter-variant</v-icon></v-badge></v-btn>
          <v-btn icon aria-label="Seleccionar varias" title="Seleccionar varias" :color="selecting ? 'primary' : ''"
            @click="toggleSelecting"><v-icon>mdi-checkbox-multiple-marked-outline</v-icon></v-btn>
          <v-btn v-if="config.catalogurl" icon :href="config.catalogurl" title="Catálogo de cartas" aria-label="Catálogo de cartas">
            <v-icon>mdi-cog</v-icon></v-btn>
        </div>
        <v-text-field v-model="search" dense outlined hide-details clearable prepend-inner-icon="mdi-magnify"
          placeholder="Nombre, cédula, correo o #número" class="gmk-desk-search mt-2 mt-md-0"
          @input="debouncedList"></v-text-field>
      </div>

      <v-expand-transition>
        <v-card v-if="filtersOpen" outlined class="pa-3 mb-2">
          <v-row dense>
            <v-col cols="12" sm="6" md="3">
              <v-select v-model="filters.lettertypeid" :items="typeItems" label="Tipo de carta" outlined dense hide-details
                @change="list(0)"></v-select></v-col>
            <v-col cols="12" sm="6" md="3">
              <v-select v-model="filters.status" :items="statusItems" label="Estado" outlined dense hide-details
                @change="list(0)"></v-select></v-col>
            <v-col cols="12" sm="6" md="3">
              <v-select v-model="filters.assignedto" :items="ownerItems" label="Responsable" outlined dense hide-details
                @change="list(0)"></v-select></v-col>
            <v-col cols="6" sm="3" md="1">
              <v-text-field v-model="filters.from" type="date" label="Desde" outlined dense hide-details @change="list(0)"></v-text-field></v-col>
            <v-col cols="6" sm="3" md="1">
              <v-text-field v-model="filters.to" type="date" label="Hasta" outlined dense hide-details @change="list(0)"></v-text-field></v-col>
            <v-col cols="12" md="1" class="d-flex align-center">
              <v-btn text small color="primary" @click="clearFilters">Limpiar</v-btn></v-col>
          </v-row>
        </v-card>
      </v-expand-transition>

      <div :class="['gmk-desk', active ? 'gmk-desk--open' : '']">
        <div class="gmk-desk__list">
          <v-tabs v-model="tabIndex" show-arrows class="gmk-desk-tabs flex-grow-0" @change="onTab">
            <v-tab v-for="t in tabs" :key="t.key" class="px-2">
              {{ t.label }}
              <v-chip x-small class="ml-1" :color="tabColor(t.key)" :dark="count(t.key) > 0 && ['overdue','legacy','unassigned'].indexOf(t.key) !== -1">{{ count(t.key) }}</v-chip>
            </v-tab>
          </v-tabs>
          <v-alert v-if="tab === 'legacy'" dense text type="info" class="ma-2 mb-0 text-body-2">
            Solicitudes anteriores a esta bandeja. Confirma en cada una si ya se gestionó:
            <strong>Sí, ya se entregó</strong> la cierra; <strong>No, gestionar ahora</strong> la deja en tu lista.
          </v-alert>
          <div v-if="selecting" class="d-flex align-center flex-wrap px-2 py-1 grey lighten-4" style="gap: 6px;">
            <v-checkbox :input-value="allSelected" hide-details dense class="mt-0" @change="selectAll"></v-checkbox>
            <span class="text-caption">{{ selected.length }} seleccionadas</span>
            <v-spacer></v-spacer>
            <v-menu offset-y>
              <template v-slot:activator="{ on, attrs }">
                <v-btn small color="primary" depressed :disabled="!selected.length" v-bind="attrs" v-on="on">
                  Acción<v-icon right small>mdi-menu-down</v-icon></v-btn>
              </template>
              <v-list dense>
                <v-list-item v-for="b in bulkActions" :key="b.key" @click="startBulk(b.key)">
                  <v-list-item-icon class="mr-2"><v-icon small>{{ b.icon }}</v-icon></v-list-item-icon>
                  <v-list-item-title>{{ b.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
          </div>
          <v-progress-linear v-if="loading" indeterminate height="2"></v-progress-linear>
          <div class="gmk-desk__scroll">
            <div v-if="!loading && !items.length" class="pa-6 text-center text--secondary">
              <v-icon large class="mb-2">mdi-email-check-outline</v-icon><div>No hay cartas aquí.</div>
            </div>
            <v-list two-line class="py-0">
              <template v-for="r in items">
                <v-list-item :key="r.id" :input-value="active && active.id === r.id" @click="selecting ? toggle(r.id) : open(r.id)">
                  <v-list-item-action v-if="selecting" class="mr-2">
                    <v-checkbox :input-value="selected.indexOf(r.id) !== -1" hide-details dense @click.stop="toggle(r.id)"></v-checkbox>
                  </v-list-item-action>
                  <div :class="['gmk-sla-dot', 'gmk-sla-' + r.sla_level]" :title="slaLabel(r.sla_level)"></div>
                  <v-list-item-content class="gmk-min0">
                    <v-list-item-title class="gmk-wrap">
                      <span class="font-weight-medium">#{{ r.id }} · {{ r.typename }}</span>
                    </v-list-item-title>
                    <v-list-item-subtitle class="gmk-wrap">{{ r.studentname }}<span v-if="r.documentnumber"> · {{ r.documentnumber }}</span></v-list-item-subtitle>
                    <v-list-item-subtitle class="text-caption gmk-wrap">
                      <v-chip x-small label :color="statusColor(r.status)" dark class="mr-1">{{ r.statuslabel }}</v-chip>
                      <v-chip v-if="r.legacy" x-small label color="amber darken-2" dark class="mr-1">¿Ya se gestionó?</v-chip>
                      <v-chip v-if="r.ticketid && r.ticketopen" x-small label outlined color="indigo" class="mr-1">
                        <v-icon x-small left>mdi-chat</v-icon>{{ r.ticketnumber }}<span v-if="r.ticketunread"> · {{ r.ticketunread }}</span></v-chip>
                      <span v-if="r.assignedname">{{ r.assignedname }}</span><span v-else-if="isOpenStatus(r.status)" class="red--text">Sin asignar</span>
                    </v-list-item-subtitle>
                  </v-list-item-content>
                  <v-list-item-action class="align-self-start mt-3 ml-2">
                    <v-list-item-action-text>{{ r.waiting || shortDate(r.timemodified) }}</v-list-item-action-text>
                    <v-list-item-action-text v-if="r.due_at" :class="r.sla_level >= 2 ? 'red--text' : ''">
                      vence {{ shortDate(r.due_at) }}</v-list-item-action-text>
                  </v-list-item-action>
                </v-list-item>
                <v-divider :key="'d' + r.id"></v-divider>
              </template>
            </v-list>
            <div v-if="total > items.length" class="pa-2 text-center">
              <v-btn text small color="primary" :loading="loading" @click="more">Ver más ({{ items.length }} de {{ total }})</v-btn>
            </div>
          </div>
        </div>

        <!-- Detalle: pantalla completa en el celular, panel derecho en escritorio -->
        <div class="gmk-desk__chat">
          <template v-if="active">
            <div class="gmk-desk-bar px-1 px-md-3 pt-1 pt-md-3">
              <div class="d-flex align-center">
                <v-btn v-if="isMobile" icon @click="close" aria-label="Volver a la lista"><v-icon>mdi-arrow-left</v-icon></v-btn>
                <div class="gmk-min0 flex-grow-1">
                  <div class="d-flex align-center flex-wrap" style="gap: 4px 8px;">
                    <span class="text-subtitle-1 text-md-h6 font-weight-medium">#{{ active.id }} · {{ active.typename }}</span>
                    <v-chip small label :color="statusColor(active.status)" dark>{{ active.statuslabel }}</v-chip>
                    <v-chip v-if="active.due_at" small label outlined :color="slaColor(active.sla_level)">
                      <v-icon x-small left>mdi-timer-sand</v-icon>{{ slaLabel(active.sla_level) }} · vence {{ fullDate(active.due_at) }}</v-chip>
                  </div>
                  <div class="text-caption text--secondary gmk-wrap">
                    {{ active.deliverymode === 'fisica' ? 'Física' : 'Digital' }}<span v-if="active.cost > 0"> · {{ money(active.cost) }}</span>
                    · solicitada {{ fullDate(active.timecreated) }}
                    <span v-if="active.waiting"> · {{ active.waiting }} en este estado</span>
                  </div>
                </div>
                <v-btn v-if="!isMobile" icon @click="close" aria-label="Cerrar"><v-icon>mdi-close</v-icon></v-btn>
              </div>
            </div>
            <v-divider class="mt-2"></v-divider>
            <div class="gmk-desk__messages pa-2 pa-md-4">
              <v-progress-linear v-if="detailLoading" indeterminate height="2" class="mb-2"></v-progress-linear>

              <v-alert v-if="active.legacy && can('handled')" outlined type="warning" prominent class="mb-3">
                <div class="font-weight-medium mb-1">¿Esta carta ya se gestionó?</div>
                <div class="text-body-2 mb-3">Es anterior a la bandeja. Si ya se entregó por fuera del sistema, ciérrala; si no, gestiónala ahora.</div>
                <div class="d-flex flex-wrap" style="gap: 8px;">
                  <v-btn color="success" depressed :loading="busy === 'handled'" @click="act('handled')">
                    <v-icon left>mdi-check</v-icon>Sí, ya se entregó</v-btn>
                  <v-btn outlined color="primary" :loading="busy === 'keep'" @click="act('keep')">No, gestionar ahora</v-btn>
                </div>
              </v-alert>

              <div class="d-flex flex-wrap mb-3" style="gap: 8px;">
                <v-btn v-if="can('upload')" color="primary" depressed @click="openUpload">
                  <v-icon left>mdi-upload</v-icon>Cargar carta (PDF/Word)</v-btn>
                <v-btn v-if="can('ready_pickup')" color="blue" dark depressed :loading="busy === 'ready_pickup'" @click="askReason('ready_pickup')">
                  <v-icon left>mdi-hand-extended-outline</v-icon>Lista para retirar</v-btn>
                <v-btn v-if="can('delivered')" color="success" depressed :loading="busy === 'delivered'" @click="askReason('delivered')">
                  <v-icon left>mdi-check-all</v-icon>Entregada</v-btn>
                <v-btn v-if="can('take')" outlined :loading="busy === 'take'" @click="act('take')">
                  <v-icon left>mdi-account-arrow-left</v-icon>Tomar</v-btn>
                <v-menu offset-y>
                  <template v-slot:activator="{ on, attrs }">
                    <v-btn outlined v-bind="attrs" v-on="on">Más<v-icon right>mdi-menu-down</v-icon></v-btn>
                  </template>
                  <v-list dense>
                    <v-list-item v-if="can('assign')" @click="assignDialog = true"><v-list-item-title>Asignar a…</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('message')" @click="messageDialog = true"><v-list-item-title>Escribir al estudiante</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('note')" @click="noteDialog = true"><v-list-item-title>Nota interna</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('generate')" @click="act('generate')"><v-list-item-title>Generar desde la plantilla</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('reject')" @click="askReason('reject')"><v-list-item-title class="red--text">Rechazar…</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('cancel')" @click="askReason('cancel')"><v-list-item-title class="red--text">Anular…</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('delete')" @click="remove"><v-list-item-title class="red--text">Eliminar definitivamente</v-list-item-title></v-list-item>
                  </v-list>
                </v-menu>
              </div>

              <v-card outlined class="mb-3">
                <v-card-text class="text-body-2">
                  <div class="d-flex flex-wrap" style="gap: 4px 16px;">
                    <div><span class="text--secondary">Estudiante:</span> <strong>{{ active.studentname }}</strong></div>
                    <div v-if="active.documentnumber"><span class="text--secondary">Cédula:</span> {{ active.documentnumber }}</div>
                    <div v-if="active.email" class="gmk-wrap"><span class="text--secondary">Correo:</span> {{ active.email }}</div>
                    <div><span class="text--secondary">Responsable:</span> {{ active.assignedname || 'Sin asignar' }}</div>
                    <div v-if="active.financial"><span class="text--secondary">Estado financiero:</span> {{ active.financial }}</div>
                    <div v-if="active.invoice_number"><span class="text--secondary">Factura:</span> {{ active.invoice_number }}</div>
                  </div>
                  <div v-if="active.careers" class="mt-2" style="white-space: pre-line;"><span class="text--secondary">Carrera:</span> {{ active.careers }}</div>
                  <div v-if="active.observation" class="mt-2 gmk-faq-answer pa-2 gmk-wrap" style="white-space: pre-wrap;">
                    <span class="text--secondary">Observación del estudiante:</span> {{ active.observation }}</div>
                  <div v-if="active.rejection_reason" class="mt-2 red--text">Motivo de rechazo: {{ active.rejection_reason }}</div>
                  <div v-if="active.cancel_reason" class="mt-2 red--text">Motivo de anulación: {{ active.cancel_reason }}</div>
                </v-card-text>
              </v-card>

              <v-card outlined class="mb-3">
                <v-card-title class="text-subtitle-2 py-2">Conversación</v-card-title>
                <v-card-text class="text-body-2">
                  <template v-if="active.ticketid">
                    <a :href="config.supporturl + '?ticket=' + active.ticketid">{{ active.ticketnumber }}</a>
                    · {{ active.ticketstatus }}<span v-if="active.ticketunread"> · {{ active.ticketunread }} sin leer</span>
                  </template>
                  <span v-else class="text--secondary">Sin ticket. "Escribir al estudiante" abre uno ligado a esta carta.</span>
                </v-card-text>
              </v-card>

              <v-card outlined class="mb-3">
                <v-card-title class="text-subtitle-2 py-2">Documentos</v-card-title>
                <v-list dense class="py-0">
                  <v-list-item v-for="d in active.documents" :key="d.id" :href="fileUrl(d.id)" target="_blank">
                    <v-list-item-icon class="mr-2"><v-icon>{{ /\.pdf$/i.test(d.filename) ? 'mdi-file-pdf-box' : 'mdi-file-word-box' }}</v-icon></v-list-item-icon>
                    <v-list-item-content class="gmk-min0">
                      <v-list-item-title class="gmk-wrap">v{{ d.version }} · {{ d.filename }}</v-list-item-title>
                      <v-list-item-subtitle>{{ d.source === 'uploaded' ? 'Cargada' : 'Generada' }} · {{ fullDate(d.time) }} · {{ Math.round(d.size / 1024) }} KB<span v-if="!d.odoo"> · aún no en Odoo</span></v-list-item-subtitle>
                    </v-list-item-content>
                  </v-list-item>
                  <v-list-item v-if="!active.documents || !active.documents.length">
                    <v-list-item-content class="text--secondary">Sin documentos.</v-list-item-content>
                  </v-list-item>
                </v-list>
              </v-card>

              <v-card outlined>
                <v-card-title class="text-subtitle-2 py-2">Historial</v-card-title>
                <v-timeline dense align-top class="pr-2">
                  <v-timeline-item v-for="(h, i) in active.history" :key="i" small :color="h.type === 'status_change' ? 'primary' : (h.type === 'sla_level' ? 'red' : 'grey')">
                    <div class="text-body-2 gmk-wrap">{{ h.text }}</div>
                    <div class="text-caption text--secondary">{{ h.actor }} · {{ fullDate(h.time) }}</div>
                  </v-timeline-item>
                </v-timeline>
              </v-card>
            </div>
          </template>
          <div v-else class="d-none d-md-flex align-center justify-center fill-height text--secondary pa-6">
            Elige una carta de la lista.
          </div>
        </div>
      </div>

      <!-- Cargar carta -->
      <v-dialog v-model="uploadDialog" max-width="520" :fullscreen="isMobile">
        <v-card>
          <v-card-title>Cargar carta</v-card-title>
          <v-card-text>
            <v-file-input v-model="uploadFile" accept=".pdf,.docx,.doc" outlined dense show-size
              :label="'Archivo PDF o Word (máx. ' + (config.maxmb || 10) + ' MB)'"></v-file-input>
            <div class="text-body-2 mb-1">Después de cargarla:</div>
            <v-radio-group v-model="uploadNext" class="mt-0" hide-details>
              <v-radio v-if="nextAllowed('available')" value="available" label="El estudiante la descarga desde el portal (se cierra la solicitud)"></v-radio>
              <v-radio v-if="nextAllowed('pickup')" value="pickup" label="Queda lista para retirar (carta física)"></v-radio>
              <v-radio v-if="nextAllowed('delivered')" value="delivered" label="Ya se entregó (se cierra la solicitud)"></v-radio>
            </v-radio-group>
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="uploadDialog = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :disabled="!uploadFile" :loading="busy === 'upload'" @click="upload">Cargar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Motivo / comentario -->
      <v-dialog v-model="reasonDialog.open" max-width="520" :fullscreen="isMobile">
        <v-card>
          <v-card-title>{{ reasonTitle }}</v-card-title>
          <v-card-text>
            <div v-if="reasonDialog.required" class="d-flex flex-wrap mb-2" style="gap: 6px;">
              <v-chip v-for="(r, i) in reasons" :key="i" small outlined @click="reasonDialog.text = r">{{ r }}</v-chip>
            </div>
            <v-textarea v-model="reasonDialog.text" outlined auto-grow rows="2" counter="1000"
              :label="reasonDialog.required ? 'Motivo (lo ve el estudiante)' : 'Comentario (opcional)'"></v-textarea>
            <div v-if="reasonDialog.bulk" class="text-body-2">
              Se aplicará a <strong>{{ reasonDialog.bulk.done.length }}</strong> carta(s).
              <span v-if="reasonDialog.bulk.skipped.length">Se saltan {{ reasonDialog.bulk.skipped.length }}: {{ skippedText(reasonDialog.bulk) }}</span>
            </div>
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="reasonDialog.open = false">Cancelar</v-btn>
            <v-btn :color="['reject', 'cancel'].indexOf(reasonDialog.action) !== -1 ? 'red' : 'primary'" dark depressed
              :disabled="reasonDialog.required && !reasonDialog.text.trim()" :loading="!!busy" @click="confirmReason">Confirmar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Asignar -->
      <v-dialog v-model="assignDialog" max-width="460" :fullscreen="isMobile">
        <v-card>
          <v-card-title>{{ bulkAssign ? 'Asignar ' + selected.length + ' carta(s) a…' : 'Asignar a…' }}</v-card-title>
          <v-list>
            <v-list-item v-for="u in assignable" :key="u.id" @click="assignTo(u.id)">
              <v-list-item-content><v-list-item-title>{{ u.fullname }}</v-list-item-title>
                <v-list-item-subtitle>{{ u.load }} carta(s) abiertas a su cargo</v-list-item-subtitle></v-list-item-content>
            </v-list-item>
          </v-list>
          <v-card-actions><v-spacer></v-spacer><v-btn text @click="assignDialog = false; bulkAssign = false">Cancelar</v-btn></v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Nota interna / mensaje al estudiante -->
      <v-dialog v-model="noteDialog" max-width="520" :fullscreen="isMobile">
        <v-card>
          <v-card-title>Nota interna</v-card-title>
          <v-card-text><v-textarea v-model="noteText" outlined auto-grow rows="3" counter="2000"
            label="Solo la ve el personal"></v-textarea></v-card-text>
          <v-card-actions><v-spacer></v-spacer><v-btn text @click="noteDialog = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :disabled="!noteText.trim()" :loading="busy === 'note'" @click="saveNote">Guardar</v-btn></v-card-actions>
        </v-card>
      </v-dialog>
      <v-dialog v-model="messageDialog" max-width="520" :fullscreen="isMobile">
        <v-card>
          <v-card-title>Escribir al estudiante</v-card-title>
          <v-card-text>
            <div class="text-body-2 mb-2 text--secondary">Se envía por la mesa de ayuda, en el ticket de esta carta (se abre uno si no hay).</div>
            <v-textarea v-model="messageText" outlined auto-grow rows="3" counter="4000" label="Mensaje"></v-textarea></v-card-text>
          <v-card-actions><v-spacer></v-spacer><v-btn text @click="messageDialog = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :disabled="!messageText.trim()" :loading="busy === 'message'" @click="sendMessage">Enviar</v-btn></v-card-actions>
        </v-card>
      </v-dialog>

      <v-snackbar v-model="snack.show" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
    </v-container>`,
    data() {
        return {
            tabs: [
                { key: 'mine', label: 'Mías' }, { key: 'unassigned', label: 'Sin asignar' },
                { key: 'overdue', label: 'Vencidas' }, { key: 'pickup', label: 'Por retirar' },
                { key: 'legacy', label: 'Anteriores' }, { key: 'open', label: 'Abiertas' },
                { key: 'closed', label: 'Cerradas' }, { key: 'all', label: 'Todas' },
            ],
            tabIndex: 0, tab: 'mine', counts: {}, items: [], total: 0, page: 0, loading: false,
            search: '', filtersOpen: false,
            filters: { lettertypeid: 0, status: '', assignedto: -1, from: '', to: '' },
            types: [], assignable: [],
            active: null, detailLoading: false, busy: '',
            selecting: false, selected: [],
            uploadDialog: false, uploadFile: null, uploadNext: 'available',
            reasonDialog: { open: false, action: '', text: '', required: false, bulk: null },
            assignDialog: false, bulkAssign: false,
            noteDialog: false, noteText: '', messageDialog: false, messageText: '',
            snack: { show: false, text: '', color: 'success' },
            width: window.innerWidth, timer: null,
        };
    },
    computed: {
        isMobile() { return this.width < 960; },
        hasFilters() {
            const f = this.filters;
            return !!(f.lettertypeid || f.status || f.assignedto !== -1 || f.from || f.to);
        },
        typeItems() { return [{ value: 0, text: 'Todos' }].concat(this.types.map(t => ({ value: t.id, text: t.name }))); },
        statusItems() {
            return [{ value: '', text: 'Todos' }, { value: 'pendiente_gestion', text: 'En gestión' },
                { value: 'pendiente_pago', text: 'Pendiente de pago' }, { value: 'pagada', text: 'Pagada' },
                { value: 'pendiente_recoleccion', text: 'Lista para retirar' }, { value: 'generada_digital', text: 'Lista para descargar' },
                { value: 'entregada', text: 'Entregada' }, { value: 'rechazada', text: 'Rechazada' }, { value: 'cancelada', text: 'Anulada' }];
        },
        ownerItems() {
            return [{ value: -1, text: 'Todos' }, { value: 0, text: 'Sin asignar' }]
                .concat(this.assignable.map(u => ({ value: u.id, text: u.fullname })));
        },
        reasons() { return (this.active && this.active.reasons) || []; },
        reasonTitle() {
            return { reject: 'Rechazar solicitud', cancel: 'Anular solicitud', delivered: 'Marcar entregada',
                ready_pickup: 'Lista para retirar', handled: 'Ya se gestionó' }[this.reasonDialog.action] || 'Confirmar';
        },
        allSelected() { return this.items.length > 0 && this.selected.length === this.items.length; },
        bulkActions() {
            return [
                { key: 'take', label: 'Tomarlas', icon: 'mdi-account-arrow-left' },
                { key: 'assign', label: 'Asignar a…', icon: 'mdi-account-switch' },
                { key: 'ready_pickup', label: 'Listas para retirar', icon: 'mdi-hand-extended-outline' },
                { key: 'delivered', label: 'Entregadas', icon: 'mdi-check-all' },
                { key: 'handled', label: 'Ya se gestionaron (anteriores)', icon: 'mdi-check' },
                { key: 'cancel', label: 'Anular…', icon: 'mdi-close-circle-outline' },
            ];
        },
    },
    methods: {
        call(method, args) { return window.gmkSupportCall(method, args); },
        notify(text, color) { this.snack = { show: true, text, color: color || 'success' }; },
        fail(e) { this.notify((e && e.message) || 'Error', 'red'); },
        count(key) { return this.counts[key] || 0; },
        tabColor(key) {
            if (!this.count(key)) return '';
            return { overdue: 'red', legacy: 'amber darken-2', unassigned: 'deep-orange' }[key] || '';
        },
        statusColor(s) {
            return { solicitada: 'grey', pendiente_pago: 'orange', pagada: 'teal', pendiente_gestion: 'indigo',
                pendiente_recoleccion: 'blue', generada_digital: 'green', entregada: 'green darken-2',
                rechazada: 'red', cancelada: 'blue-grey' }[s] || 'grey';
        },
        isOpenStatus(s) { return ['solicitada', 'pendiente_pago', 'pagada', 'pendiente_gestion', 'pendiente_recoleccion'].indexOf(s) !== -1; },
        slaLabel(l) { return ['En plazo', 'Por vencer', 'Vencida', 'Crítica'][l] || 'En plazo'; },
        slaColor(l) { return ['green', 'amber darken-2', 'deep-orange', 'red'][l] || 'green'; },
        shortDate(ts) {
            if (!ts) return '';
            return new Date(ts * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short' });
        },
        fullDate(ts) { return window.gmkSupportFormat.date(ts); },
        money(v) { return new Intl.NumberFormat('es-PA', { style: 'currency', currency: 'USD' }).format(Number(v || 0)); },
        can(a) { return !!(this.active && this.active.actions && this.active.actions.indexOf(a) !== -1); },
        nextAllowed(n) {
            if (!this.active) return false;
            const s = this.active.status;
            if (n === 'pickup') return ['pendiente_gestion', 'pagada', 'pendiente_recoleccion'].indexOf(s) !== -1;
            if (n === 'delivered') return ['pendiente_gestion', 'pagada', 'pendiente_recoleccion', 'generada_digital'].indexOf(s) !== -1;
            return ['solicitada', 'pendiente_gestion', 'pagada', 'generada_digital'].indexOf(s) !== -1;
        },
        fileUrl(docid) {
            return this.config.fileurl + '?sesskey=' + encodeURIComponent(this.config.sesskey) + '&requestid='
                + this.active.id + '&docid=' + docid + '&inline=1';
        },
        toTs(d, end) { return d ? Math.floor(new Date(d + (end ? 'T23:59:59' : 'T00:00:00')).getTime() / 1000) : 0; },
        async list(page) {
            this.loading = true;
            this.page = page || 0;
            try {
                const res = await this.call('local_grupomakro_letters_desk_list', {
                    tab: this.tab, search: this.search || '', lettertypeid: this.filters.lettertypeid || 0,
                    status: this.filters.status || '', assignedto: this.filters.assignedto,
                    from: this.toTs(this.filters.from), to: this.toTs(this.filters.to, true), page: this.page, perpage: 30,
                });
                this.items = this.page > 0 ? this.items.concat(res.items) : res.items;
                this.total = res.total;
                const counts = {};
                res.counts.forEach(c => { counts[c.tab] = c.count; });
                this.counts = counts;
                this.types = res.types;
                this.assignable = res.assignable;
                this.selected = this.selected.filter(id => this.items.some(r => r.id === id));
            } catch (e) {
                this.fail(e);
            } finally {
                this.loading = false;
            }
        },
        more() { this.list(this.page + 1); },
        debouncedList() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.list(0), 350);
        },
        clearFilters() {
            this.filters = { lettertypeid: 0, status: '', assignedto: -1, from: '', to: '' };
            this.list(0);
        },
        onTab(i) {
            if (!this.tabs[i] || (this.tabs[i].key === this.tab && this.loading)) return;
            this.tab = this.tabs[i].key;
            this.selected = [];
            this.list(0);
        },
        async open(id) {
            this.detailLoading = true;
            if (!this.active || this.active.id !== id) {
                const row = this.items.find(r => r.id === id);
                this.active = row ? Object.assign({ history: [], documents: [], reasons: [] }, row) : { id, actions: [], history: [], documents: [] };
            }
            try {
                this.active = await this.call('local_grupomakro_letters_desk_get', { requestid: id });
                const url = new URL(window.location.href);
                url.searchParams.set('request', id);
                window.history.replaceState(null, '', url.toString());
            } catch (e) {
                this.fail(e);
            } finally {
                this.detailLoading = false;
            }
        },
        close() {
            this.active = null;
            const url = new URL(window.location.href);
            url.searchParams.delete('request');
            window.history.replaceState(null, '', url.toString());
        },
        async refreshAfter(row) {
            if (row) {
                const i = this.items.findIndex(r => r.id === row.id);
                if (i !== -1) this.$set(this.items, i, row);
            }
            if (this.active) await this.open(this.active.id);
            this.list(0);
        },
        async act(action, extra) {
            if (!this.active) return;
            this.busy = action;
            try {
                const res = await this.call('local_grupomakro_letters_desk_action', Object.assign({
                    requestid: this.active.id, action, targetuserid: 0, reason: '', note: '', body: '' }, extra || {}));
                this.notify({ take: 'Ahora es tuya', assign: 'Asignada', generate: 'Documento generado', handled: 'Cerrada: ya se había gestionado',
                    keep: 'Queda para gestionarla', ready_pickup: 'Lista para retirar: se avisó al estudiante',
                    delivered: 'Marcada como entregada', reject: 'Rechazada', cancel: 'Anulada', note: 'Nota guardada',
                    message: 'Mensaje enviado' }[action] || 'Listo');
                if (res.deleted) {
                    this.close();
                    this.list(0);
                } else {
                    await this.refreshAfter(res.request);
                }
                return res;
            } catch (e) {
                this.fail(e);
            } finally {
                this.busy = '';
            }
        },
        askReason(action) {
            const required = ['reject', 'cancel'].indexOf(action) !== -1;
            this.reasonDialog = { open: true, action, text: '', required, bulk: null };
        },
        async confirmReason() {
            const d = this.reasonDialog;
            if (d.bulk) {
                await this.runBulk(d.action, { reason: d.text });
            } else {
                await this.act(d.action, { reason: d.text });
            }
            this.reasonDialog.open = false;
        },
        openUpload() {
            this.uploadFile = null;
            this.uploadNext = this.nextAllowed('available') && !(this.active.deliverymode === 'fisica' && this.nextAllowed('pickup'))
                ? 'available' : (this.nextAllowed('pickup') ? 'pickup' : 'delivered');
            this.uploadDialog = true;
        },
        async upload() {
            if (!this.uploadFile || !this.active) return;
            this.busy = 'upload';
            try {
                const form = new FormData();
                form.append('sesskey', this.config.sesskey);
                form.append('requestid', this.active.id);
                form.append('next', this.uploadNext);
                form.append('file', this.uploadFile);
                const res = await axios.post(this.config.fileurl, form).catch(err => {
                    throw new Error((err.response && err.response.data && err.response.data.error) || err.message);
                });
                if (!res.data || !res.data.success) throw new Error((res.data && res.data.error) || 'No se pudo cargar');
                this.uploadDialog = false;
                this.notify('Carta cargada');
                await this.refreshAfter(res.data.request);
            } catch (e) {
                this.fail(e);
            } finally {
                this.busy = '';
            }
        },
        async assignTo(uid) {
            this.assignDialog = false;
            if (this.bulkAssign) {
                this.bulkAssign = false;
                await this.runBulk('assign', { targetuserid: uid }, true);
                return;
            }
            await this.act('assign', { targetuserid: uid });
        },
        async saveNote() {
            await this.act('note', { note: this.noteText });
            this.noteText = '';
            this.noteDialog = false;
        },
        async sendMessage() {
            const res = await this.act('message', { body: this.messageText });
            if (res) {
                this.messageText = '';
                this.messageDialog = false;
            }
        },
        async remove() {
            const ok = window.Swal
                ? (await Swal.fire({ title: '¿Eliminar definitivamente?', text: 'Se borran la solicitud, su historial y sus documentos. No se puede deshacer. Para casi todo, usa "Anular".',
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#d33' })).isConfirmed
                : window.confirm('¿Eliminar definitivamente? No se puede deshacer.');
            if (ok) await this.act('delete');
        },
        toggleSelecting() {
            this.selecting = !this.selecting;
            this.selected = [];
        },
        toggle(id) {
            const i = this.selected.indexOf(id);
            if (i === -1) this.selected.push(id); else this.selected.splice(i, 1);
        },
        selectAll(v) { this.selected = v ? this.items.map(r => r.id) : []; },
        skippedText(b) {
            return b.skipped.slice(0, 5).map(s => '#' + s.id + ' (' + s.reason + ')').join(', ') + (b.skipped.length > 5 ? '…' : '');
        },
        async startBulk(action) {
            if (action === 'assign') {
                this.bulkAssign = true;
                this.assignDialog = true;
                return;
            }
            try {
                // Simulacion primero: cuantas cambian y cuales se saltan.
                const sim = await this.call('local_grupomakro_letters_desk_bulk', { requestids: this.selected, action,
                    targetuserid: 0, reason: '', dryrun: true });
                if (!sim.done.length) {
                    this.notify('Ninguna de las seleccionadas admite esa acción. ' + this.skippedText(sim), 'orange');
                    return;
                }
                this.reasonDialog = { open: true, action, text: '', required: action === 'cancel', bulk: sim };
            } catch (e) {
                this.fail(e);
            }
        },
        async runBulk(action, extra, simulateFirst) {
            this.busy = 'bulk';
            try {
                if (simulateFirst) {
                    const sim = await this.call('local_grupomakro_letters_desk_bulk', Object.assign({ requestids: this.selected,
                        action, targetuserid: 0, reason: '', dryrun: true }, extra || {}));
                    if (!sim.done.length) {
                        this.notify('Ninguna de las seleccionadas admite esa acción.', 'orange');
                        return;
                    }
                }
                const res = await this.call('local_grupomakro_letters_desk_bulk', Object.assign({ requestids: this.selected,
                    action, targetuserid: 0, reason: '', dryrun: false }, extra || {}));
                let msg = res.done.length + ' carta(s) actualizadas';
                if (res.skipped.length) msg += ', ' + res.skipped.length + ' saltadas';
                if (res.failed.length) msg += ', ' + res.failed.length + ' con error: ' + res.failed.map(f => '#' + f.id + ' ' + f.reason).join('; ');
                this.notify(msg, res.failed.length ? 'orange' : 'success');
                this.selected = [];
                this.list(0);
                if (this.active) this.open(this.active.id);
            } catch (e) {
                this.fail(e);
            } finally {
                this.busy = '';
            }
        },
        onResize() { this.width = window.innerWidth; },
    },
    async mounted() {
        window.addEventListener('resize', this.onResize);
        if (!document.getElementById('gmk-letters-css')) {
            const style = document.createElement('style');
            style.id = 'gmk-letters-css';
            style.textContent = '#gmk-app .gmk-sla-dot { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 10px; margin-right: 10px; align-self: center; }'
                + '#gmk-app .gmk-sla-0 { background: #4caf50; } #gmk-app .gmk-sla-1 { background: #ffa000; }'
                + '#gmk-app .gmk-sla-2 { background: #f4511e; } #gmk-app .gmk-sla-3 { background: #d50000; box-shadow: 0 0 0 3px rgba(213,0,0,.2); }'
                // En el celular el detalle es fijo (z-index 1045): dialogos, menus y avisos van por encima.
                + '#gmk-app .v-overlay--active { z-index: 1099 !important; }'
                + '#gmk-app .v-dialog__content--active { z-index: 1100 !important; }'
                + '#gmk-app .menuable__content__active { z-index: 1101 !important; }'
                + '#gmk-app .v-snack { z-index: 1102 !important; }';
            document.head.appendChild(style);
        }
        const t = this.tabs.findIndex(x => x.key === this.config.tab);
        if (t !== -1) {
            this.tabIndex = t;
            this.tab = this.tabs[t].key;
        }
        await this.list(0);
        if (!this.config.tab && !this.items.length && (this.counts.unassigned || this.counts.legacy)) {
            // Nada mio: abro donde hay trabajo.
            const k = this.counts.legacy ? 'legacy' : 'unassigned';
            this.tabIndex = this.tabs.findIndex(x => x.key === k);
            this.tab = k;
            await this.list(0);
        }
        if (this.config.requestid) this.open(this.config.requestid);
    },
    beforeDestroy() { window.removeEventListener('resize', this.onResize); },
});
