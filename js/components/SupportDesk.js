/**
 * SupportDesk.js
 * Bandeja del personal de la mesa de ayuda (20261001120, §8.1 del plan).
 * Montado por pages/support_desk.php. El servidor decide que tickets ve cada
 * quien (local\support\manager); aqui solo se pinta.
 */
Vue.component('support-desk', {
    props: { config: { type: Object, default: () => ({}) } },
    template: `
    <v-container fluid class="gmk-sup pa-2 pa-md-4">
      <div class="gmk-desk-head mb-2 mb-md-3">
        <div class="d-flex align-center">
          <v-icon color="primary" class="mr-2">mdi-lifebuoy</v-icon>
          <span class="text-subtitle-1 text-sm-h6 font-weight-medium mr-2 text-no-wrap">Mesa de ayuda</span>
          <v-spacer></v-spacer>
          <v-switch v-model="available" inset hide-details dense class="mt-0 mr-1" color="success"
            :label="available ? 'Disponible' : 'Ausente'" @change="setAvailability"></v-switch>
          <v-btn v-if="config.settingsurl" icon :href="config.settingsurl" title="Configuración" aria-label="Configuración">
            <v-icon>mdi-cog</v-icon></v-btn>
        </div>
        <v-text-field v-model="search" dense outlined hide-details clearable prepend-inner-icon="mdi-magnify"
          placeholder="Cédula, nombre, número o asunto" class="gmk-desk-search mt-2 mt-md-0"
          aria-label="Buscar solicitudes por cédula, nombre, número o asunto"
          @input="debouncedList" @click:clear="clearSearch"></v-text-field>
      </div>

      <div class="gmk-desk" :class="{ 'gmk-desk--open': !!active }">
        <!-- Lista: ancho completo en el celular, columna de 420 px en escritorio -->
        <div class="gmk-desk__list">
          <!-- Vistas: tarjetas con contador (3 por fila), la activa resaltada -->
          <div class="gmk-views pa-2" role="tablist" aria-label="Vistas de la bandeja">
            <button v-for="t in visibleTabs" :key="t.key" type="button" role="tab" class="gmk-view"
              :class="viewClass(t.key)" :aria-selected="String(!searching && tab === t.key)" @click="selectTab(t.key)">
              <span class="gmk-view__count">{{ count(t.key) }}</span>
              <span class="gmk-view__label"><v-icon x-small class="mr-1" :color="iconColor(t.key)">{{ t.icon }}</v-icon>{{ t.label }}</span>
            </button>
          </div>
          <!-- Buscando: en todas las solicitudes visibles -->
          <div v-if="searching" class="gmk-searchbar px-3 py-2 d-flex align-center">
            <v-icon small class="mr-2">mdi-magnify</v-icon>
            <span class="gmk-min0 flex-grow-1 text-body-2">
              {{ total }} resultado{{ total === 1 ? '' : 's' }} en todas tus solicitudes (abiertas y cerradas)
            </span>
            <v-btn text small color="primary" class="px-1" @click="clearSearch">Limpiar</v-btn>
          </div>
          <div v-else-if="search && search.trim().length > 0 && search.trim().length < searchmin" class="px-3 py-1 text-caption text--secondary">
            Escribe al menos {{ searchmin }} caracteres para buscar.
          </div>
          <!-- Filtros rápidos: área y semáforo (toca otra vez para quitarlo) -->
          <div v-if="areaOptions.length > 1 || showLevels" class="gmk-quick px-2 pb-2">
            <template v-if="areaOptions.length > 1">
              <v-chip v-for="a in areaOptions" :key="'a' + a.id" small class="mr-1" :outlined="areaid !== a.id"
                :color="areaid === a.id ? 'primary' : ''" :input-value="areaid === a.id" @click="toggleArea(a.id)">
                {{ a.name }}<span class="ml-1 font-weight-bold">{{ a.count }}</span>
              </v-chip>
            </template>
            <span v-if="areaOptions.length > 1 && showLevels" class="gmk-quick__sep" aria-hidden="true"></span>
            <template v-if="showLevels">
              <v-chip v-for="l in levelOptions" :key="'l' + l.key" small class="mr-1" :outlined="level !== l.key"
                :color="level === l.key ? l.color : ''" :dark="level === l.key" @click="toggleLevel(l.key)"
                :title="l.help">
                <v-icon x-small left :color="level === l.key ? 'white' : l.color">mdi-circle</v-icon>{{ l.label }}
                <span class="ml-1 font-weight-bold">{{ levels[l.key] || 0 }}</span>
              </v-chip>
            </template>
          </div>
          <v-divider></v-divider>
          <v-progress-linear v-if="loadingList" indeterminate color="primary"></v-progress-linear>
          <div class="gmk-desk__scroll">
            <div v-if="!loadingList && tickets.length === 0" class="pa-6 text-center text--secondary">
              <v-icon large>mdi-inbox-outline</v-icon>
              <p class="mt-2 mb-0">{{ emptyText }}</p>
              <v-btn v-if="areaid || level" text small color="primary" class="mt-1" @click="clearFilters">Quitar filtros</v-btn>
            </div>
            <v-list two-line class="py-0">
              <template v-for="t in tickets">
                <v-list-item :key="t.id" @click="openTicket(t.id)" :input-value="active && active.id === t.id" class="px-3">
                  <v-list-item-avatar size="14" class="mr-3 align-self-start mt-4">
                    <v-icon small :color="fmt.semaphoreColor(t.semaphore)">mdi-circle</v-icon>
                  </v-list-item-avatar>
                  <v-list-item-content>
                    <v-list-item-title :class="{ 'font-weight-bold': t.unread > 0 }" class="gmk-wrap">
                      <v-icon v-if="t.confidential" x-small color="purple" class="mr-1">mdi-lock</v-icon>
                      {{ t.number }} · {{ t.requestername }}
                    </v-list-item-title>
                    <v-list-item-subtitle v-if="searching && t.requesteridnumber" class="text-caption">
                      <v-icon x-small class="mr-1">mdi-card-account-details-outline</v-icon>Cédula {{ t.requesteridnumber }}
                    </v-list-item-subtitle>
                    <v-list-item-subtitle>{{ t.areaname }} / {{ t.categoryname }}</v-list-item-subtitle>
                    <v-list-item-subtitle class="text-caption">
                      <v-chip x-small label :color="fmt.statusColor(t.status)" dark class="mr-1">{{ t.statuslabel }}</v-chip>
                      <v-chip v-if="t.priority === 2" x-small label color="red" outlined class="mr-1">Alta</v-chip>
                      <span v-if="t.assignedname">{{ t.assignedname }}</span><span v-else class="red--text">Sin asignar</span>
                    </v-list-item-subtitle>
                  </v-list-item-content>
                  <v-list-item-action class="align-self-start mt-3 ml-2">
                    <v-list-item-action-text :title="t.semaphore !== 'none' ? 'Minutos hábiles de espera (reloj: ' + (fmt.waiting(t.waitingclock) || '0 min') + ')' : ''">{{ fmt.waiting(t.waitingminutes) || shortDate(t.lastmessageat || t.timecreated) }}</v-list-item-action-text>
                    <v-chip v-if="t.unread > 0" x-small color="pink" dark class="mt-1">{{ t.unread }}</v-chip>
                  </v-list-item-action>
                </v-list-item>
                <v-divider :key="'d' + t.id"></v-divider>
              </template>
            </v-list>
            <div v-if="total > tickets.length" class="pa-2 text-caption text-center text--secondary">
              Mostrando {{ tickets.length }} de {{ total }}. Usa la búsqueda para acotar.
            </div>
          </div>
        </div>

        <!-- Ticket: pantalla completa en el celular (botón volver), panel derecho en escritorio -->
        <div class="gmk-desk__chat">
          <template v-if="active">
            <div class="gmk-desk-bar px-1 px-md-3 pt-1 pt-md-3">
              <div class="d-flex align-center">
                <v-btn v-if="isMobile" icon @click="closeTicket" aria-label="Volver a la lista"><v-icon>mdi-arrow-left</v-icon></v-btn>
                <div class="gmk-min0 flex-grow-1">
                  <div class="d-flex align-center flex-wrap" style="gap: 4px 8px;">
                    <span class="text-subtitle-1 text-md-h6 font-weight-medium">{{ active.number }}</span>
                    <v-chip small label :color="fmt.statusColor(active.status)" dark>{{ active.statuslabel }}</v-chip>
                    <v-chip v-if="active.confidential" small label color="purple" dark><v-icon x-small left>mdi-lock</v-icon>Confidencial</v-chip>
                    <v-chip v-if="active.semaphore !== 'none'" small label outlined :color="fmt.semaphoreColor(active.semaphore)"
                      :title="'Solo cuenta el horario hábil. En reloj: ' + (fmt.waiting(active.waitingclock) || '0 min')">
                      <v-icon x-small left>mdi-timer-sand</v-icon>{{ fmt.waiting(active.waitingminutes) || '0 min' }} hábiles
                    </v-chip>
                  </div>
                </div>
                <v-btn icon title="Actualizar" aria-label="Actualizar" @click="refreshActive(true)"><v-icon>mdi-refresh</v-icon></v-btn>
                <!-- Celular: todas las acciones en un menú -->
                <v-menu v-if="isMobile" offset-y left>
                  <template v-slot:activator="{ on }">
                    <v-btn icon v-on="on" aria-label="Acciones del ticket"><v-icon>mdi-dots-vertical</v-icon></v-btn>
                  </template>
                  <v-list>
                    <v-list-item v-if="can('take')" @click="act('take')"><v-list-item-icon><v-icon>mdi-hand-back-right</v-icon></v-list-item-icon><v-list-item-title>Tomar</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('assign')" @click="openAssign"><v-list-item-icon><v-icon>mdi-account-switch</v-icon></v-list-item-icon><v-list-item-title>Reasignar</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('unassign')" @click="act('unassign')"><v-list-item-icon><v-icon>mdi-tray-arrow-up</v-icon></v-list-item-icon><v-list-item-title>Devolver a la cola</v-list-item-title></v-list-item>
                    <v-list-item v-if="active.canwrite" @click="mode = 'askinfo'"><v-list-item-icon><v-icon>mdi-help-circle-outline</v-icon></v-list-item-icon><v-list-item-title>Pedir información</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('resolve')" @click="confirmAct('resolve', '¿Marcar como resuelto? El estudiante confirmará si se resolvió.')"><v-list-item-icon><v-icon color="success">mdi-check-circle</v-icon></v-list-item-icon><v-list-item-title>Resolver</v-list-item-title></v-list-item>
                    <v-list-item v-if="can('close')" @click="confirmAct('close', '¿Cerrar el ticket sin esperar la confirmación del estudiante?')"><v-list-item-icon><v-icon>mdi-close-circle-outline</v-icon></v-list-item-icon><v-list-item-title>Cerrar</v-list-item-title></v-list-item>
                    <template v-if="can('set_priority')">
                      <v-subheader>Prioridad (ahora: {{ fmt.priorityLabel(active.priority) }})</v-subheader>
                      <v-list-item v-for="p in [0, 1, 2]" :key="'p' + p" :disabled="p === active.priority" @click="act('set_priority', { priority: p })">
                        <v-list-item-icon><v-icon>mdi-flag-outline</v-icon></v-list-item-icon><v-list-item-title>{{ fmt.priorityLabel(p) }}</v-list-item-title></v-list-item>
                    </template>
                    <v-list-item v-if="can('set_category')" @click="openCategory"><v-list-item-icon><v-icon>mdi-tag-outline</v-icon></v-list-item-icon><v-list-item-title>Cambiar tema</v-list-item-title></v-list-item>
                    <v-list-item @click="showHistory = !showHistory"><v-list-item-icon><v-icon>mdi-history</v-icon></v-list-item-icon><v-list-item-title>{{ showHistory ? 'Ocultar' : 'Ver' }} historial</v-list-item-title></v-list-item>
                    <v-list-item @click="showInfo = !showInfo"><v-list-item-icon><v-icon>mdi-account-details-outline</v-icon></v-list-item-icon><v-list-item-title>{{ showInfo ? 'Ocultar' : 'Ver' }} datos del solicitante</v-list-item-title></v-list-item>
                  </v-list>
                </v-menu>
              </div>
              <div class="text-subtitle-2 mt-1 px-2 px-md-0 gmk-wrap">{{ active.subject }}</div>
              <div v-if="!isMobile || showInfo" class="text-caption text--secondary px-2 px-md-0 gmk-wrap">
                {{ active.requestername }}<span v-if="active.requesteridnumber"> · {{ active.requesteridnumber }}</span>
                <span v-if="active.requesteremail"> · {{ active.requesteremail }}</span>
                · {{ active.areaname }} / {{ active.categoryname }}
                · Atiende: <b>{{ active.assignedname || 'Sin asignar' }}</b>
                · Creado {{ fmt.date(active.timecreated) }}
                <span v-if="previoustickets"> · {{ previoustickets }} ticket(s) anteriores</span>
              </div>
              <div v-else class="text-caption text--secondary px-2 gmk-wrap">
                {{ active.requestername }} · Atiende: <b>{{ active.assignedname || 'Sin asignar' }}</b>
              </div>
              <!-- Celular: la acción principal a la vista; el resto en el menú -->
              <div v-if="isMobile && (can('take') || can('resolve'))" class="px-2 pt-2">
                <v-btn v-if="can('take')" block color="primary" depressed :loading="busy === 'take'" @click="act('take')">
                  <v-icon left>mdi-hand-back-right</v-icon>Tomar</v-btn>
                <v-btn v-else block outlined color="success" :loading="busy === 'resolve'"
                  @click="confirmAct('resolve', '¿Marcar como resuelto? El estudiante confirmará si se resolvió.')">
                  <v-icon left>mdi-check-circle</v-icon>Resolver</v-btn>
              </div>
              <!-- Escritorio: botones en línea -->
              <div v-if="!isMobile" class="mt-2 d-flex flex-wrap" style="gap: 6px;">
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
            <v-divider class="mt-2"></v-divider>
            <div v-if="showHistory" class="pa-3 grey lighten-4 gmk-wrap" style="max-height: 180px; overflow-y: auto;">
              <div v-for="e in events" :key="e.id" class="text-caption">
                <b>{{ fmt.date(e.timecreated) }}</b> · {{ e.actorname }} · {{ e.description }}
              </div>
            </div>
            <div ref="scroller" class="gmk-desk__messages pa-2 pa-md-3">
              <div v-if="hasMore" class="text-center mb-2">
                <v-btn small text color="primary" :loading="loadingOlder" @click="loadOlder">Ver mensajes anteriores</v-btn>
              </div>
              <div v-for="m in messages" :key="m.id" class="d-flex mb-2"
                   :class="m.senderrole === 'system' ? 'justify-center' : (m.senderrole === 'student' ? 'justify-start' : 'justify-end')">
                <div v-if="m.senderrole === 'system'" class="text-caption text--secondary px-3 py-1 rounded grey lighten-3 gmk-bubble">
                  <v-icon x-small class="mr-1">mdi-robot-outline</v-icon>{{ m.body }}
                </div>
                <div v-else class="px-3 py-2 rounded-lg gmk-bubble"
                     :class="m.internal ? 'amber lighten-4' : (m.senderrole === 'student' ? 'white elevation-1' : 'blue lighten-5')">
                  <div class="text-caption font-weight-medium mb-1">
                    {{ m.sendername }}<span v-if="m.internal" class="ml-1 amber--text text--darken-4">· Nota interna (el estudiante no la ve)</span>
                  </div>
                  <div v-if="m.body">{{ m.body }}</div>
                  <support-attachments :items="m.attachments"></support-attachments>
                  <div class="text-right mt-1" style="font-size: 11px; opacity: .7;">{{ fmt.date(m.timecreated) }}</div>
                </div>
              </div>
            </div>
            <v-divider></v-divider>
            <div class="gmk-desk__composer pa-2 pa-md-3">
              <v-alert v-if="!active.canwrite" dense text type="info" class="mb-0">El ticket está {{ active.statuslabel.toLowerCase() }}; no admite mensajes.</v-alert>
              <template v-else>
                <v-chip-group v-model="mode" mandatory active-class="primary--text" class="gmk-modes">
                  <v-chip value="reply" filter outlined>Responder</v-chip>
                  <v-chip value="askinfo" filter outlined>Pedir información</v-chip>
                  <v-chip value="note" filter outlined>Nota interna</v-chip>
                </v-chip-group>
                <v-alert v-if="sendError" dense text type="error" class="mb-2" dismissible @input="sendError = ''">{{ sendError }}</v-alert>
                <support-attach-picker ref="picker" :key="'pk' + active.id" :limits="attachlimits" :ticketid="active.id"
                  :disabled="sending" @state="attach = $event" @error="sendError = $event"></support-attach-picker>
                <div class="d-flex align-end">
                  <v-textarea v-model="draft" outlined dense hide-details auto-grow rows="1" maxlength="4000"
                    :background-color="mode === 'note' ? 'amber lighten-5' : ''"
                    :placeholder="placeholder" @keydown.enter.ctrl.prevent="send"></v-textarea>
                  <v-btn color="primary" class="ml-2" fab small depressed :loading="sending || attach.busy" aria-label="Enviar"
                    :disabled="!canSend" @click="send"><v-icon>mdi-send</v-icon></v-btn>
                </div>
                <div class="text-caption text--secondary mt-1"><span class="d-none d-md-inline">Ctrl+Enter envía. </span>{{ modeHelp }}</div>
              </template>
            </div>
          </template>
          <div v-else class="d-flex flex-column align-center justify-center flex-grow-1 text--secondary pa-6 text-center">
            <v-icon size="64">mdi-lifebuoy</v-icon>
            <p class="mt-2">Selecciona un ticket de la lista.</p>
          </div>
        </div>
      </div>

      <v-dialog v-model="assignDialog.open" max-width="480" :fullscreen="$vuetify.breakpoint.xsOnly" scrollable>
        <v-card>
          <v-card-title>Reasignar {{ active && active.number }}</v-card-title>
          <v-card-text>
            <v-select v-model="assignDialog.categoryid" :items="assignDialog.categories" item-text="label" item-value="id"
              label="Tema" outlined dense hide-details class="mb-1" @change="loadAssignable"></v-select>
            <p v-if="active && assignDialog.categoryid !== active.categoryid" class="text-caption orange--text text--darken-3 mb-2">
              El ticket pasará a {{ assignDialog.categoryLabel }}: queda en el historial y el estudiante recibe un mensaje automático.
            </p>
            <v-progress-linear v-if="assignDialog.loading" indeterminate></v-progress-linear>
            <v-list dense>
              <v-list-item v-for="u in assignDialog.users" :key="u.id" @click="assignTo(u.id)"
                :disabled="active && u.id === active.assignedto && assignDialog.categoryid === active.categoryid">
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

      <v-dialog v-model="categoryDialog.open" max-width="480" :fullscreen="$vuetify.breakpoint.xsOnly">
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
            // Orden pensado para el día a día: lo mío, lo que nadie tomó y lo vencido primero.
            allTabs: [
                { key: 'mine', label: 'Míos', icon: 'mdi-account' },
                { key: 'unassigned', label: 'Sin asignar', icon: 'mdi-account-question' },
                { key: 'overdue', label: 'Vencidos', icon: 'mdi-alarm' },
                { key: 'area', label: 'Mi área', icon: 'mdi-account-group' },
                { key: 'all', label: 'Todos', icon: 'mdi-inbox-multiple' },
                { key: 'closed', label: 'Cerrados', icon: 'mdi-check-all' },
            ],
            levelOptions: [
                { key: 'red', label: 'Rojo', color: 'red', help: 'Superó el tiempo de respuesta' },
                { key: 'orange', label: 'Naranja', color: 'orange', help: 'Cerca de vencer' },
                { key: 'yellow', label: 'Amarillo', color: 'amber', help: 'Esperando más de lo ideal' },
                { key: 'green', label: 'Verde', color: 'green', help: 'En tiempo' },
            ],
            tab: 'mine',
            areaid: 0,
            level: '',
            areas: [],
            levels: {},
            searchmin: 2,
            searching: false,
            counts: {},
            me: { supervisor: false, available: true, manage: false, reassign: false },
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
            showInfo: false,
            attachlimits: null,
            attach: { busy: false, failed: false, count: 0, draftitemid: 0 },
            draft: '',
            mode: 'reply',
            sending: false,
            sendError: '',
            busy: '',
            assignDialog: { open: false, loading: false, users: [], categoryid: 0, categories: [] },
            categoryDialog: { open: false, categoryid: 0, items: [] },
            snack: { show: false, text: '', color: 'error' },
            timers: [],
            searchTimer: null,
            areaname: '',
        };
    },
    computed: {
        visibleTabs() {
            return this.allTabs.filter(t => t.key !== 'all' || this.me.supervisor || this.me.reassign);
        },
        showLevels() {
            // Semáforo: solo en vistas de abiertos (en Vencidos todo es rojo).
            return !this.searching && ['mine', 'unassigned', 'area', 'all'].indexOf(this.tab) !== -1;
        },
        areaOptions() {
            const list = this.areas.slice();
            if (this.areaid && !list.some(a => a.id === this.areaid)) {
                list.push({ id: this.areaid, name: this.areaname || 'Área', count: 0 });
            }
            return list;
        },
        emptyText() {
            if (this.searching) return 'No encontramos solicitudes con esa búsqueda.';
            if (this.areaid || this.level) return 'No hay solicitudes con estos filtros.';
            return {
                mine: 'No tienes solicitudes asignadas abiertas.',
                unassigned: 'No hay solicitudes sin asignar.',
                overdue: 'No hay solicitudes vencidas.',
                area: 'No hay solicitudes abiertas en tu área.',
                all: 'No hay solicitudes abiertas.',
                closed: 'No hay solicitudes cerradas.',
            }[this.tab] || 'No hay solicitudes.';
        },
        isMobile() {
            return this.$vuetify.breakpoint.smAndDown;
        },
        canSend() {
            const text = !!(this.draft && this.draft.trim());
            return !this.sending && !this.attach.busy && !this.attach.failed && (text || this.attach.count > 0);
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
        // Vista inicial: la del enlace (?tab= desde el indicador flotante), si no la
        // última que usó esta persona en este navegador, si no "Míos".
        const keys = this.allTabs.map(t => t.key);
        const remembered = this.storage('get');
        if (keys.indexOf(this.config.tab) !== -1) {
            this.tab = this.config.tab;
        } else if (keys.indexOf(remembered) !== -1) {
            this.tab = remembered;
        }
        await this.loadList();
        if (this.config.ticketid) {
            await this.openTicket(parseInt(this.config.ticketid, 10));
        }
        this.timers.push(setInterval(() => { if (!document.hidden) this.loadList(true); }, 30000));
        this.timers.push(setInterval(() => { if (!document.hidden && this.active) this.refreshActive(false); }, 15000));
    },
    created() {
        this.onPopState = () => {
            if (this.active && !(window.history.state && window.history.state.gmkTicket)) {
                this.active = null;
                this.showHistory = false;
                this.showInfo = false;
            }
        };
        window.addEventListener('popstate', this.onPopState);
    },
    beforeDestroy() {
        window.removeEventListener('popstate', this.onPopState);
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
        viewClass(key) {
            return {
                'gmk-view--active': !this.searching && this.tab === key,
                'gmk-view--alert': (key === 'unassigned' || key === 'overdue') && this.count(key) > 0,
                'gmk-view--zero': !this.count(key),
            };
        },
        iconColor(key) {
            if (!this.searching && this.tab === key) return 'white';
            return (key === 'unassigned' || key === 'overdue') && this.count(key) > 0 ? 'red' : '';
        },
        storage(op, value) {
            // Recordar la última vista (solo comodidad: si el navegador no deja, se ignora).
            const k = 'gmkSupportDeskTab:' + (this.config.userid || 0);
            try {
                if (op === 'set') window.localStorage.setItem(k, value);
                else return window.localStorage.getItem(k);
            } catch (e) {
                return null;
            }
            return null;
        },
        selectTab(key) {
            const same = this.tab === key && !this.searching;
            this.tab = key;
            this.storage('set', key);
            if (this.searching || (this.search && this.search.trim())) {
                this.search = '';
                this.searching = false;
            }
            if (!this.showLevels) this.level = '';
            if (!same) this.tickets = [];
            this.loadList();
        },
        toggleArea(id) {
            const a = this.areas.find(x => x.id === id);
            this.areaname = a ? a.name : this.areaname;
            this.areaid = this.areaid === id ? 0 : id;
            this.loadList();
        },
        toggleLevel(key) {
            this.level = this.level === key ? '' : key;
            this.loadList();
        },
        clearFilters() {
            this.areaid = 0;
            this.level = '';
            this.loadList();
        },
        clearSearch() {
            this.search = '';
            this.searching = false;
            clearTimeout(this.searchTimer);
            this.loadList();
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
        closeTicket() {
            if (window.history.state && window.history.state.gmkTicket) {
                window.history.back(); // onPopState cierra.
                return;
            }
            this.active = null;
            this.showHistory = false;
            this.showInfo = false;
        },
        debouncedList() {
            clearTimeout(this.searchTimer);
            const q = (this.search || '').trim();
            // Menos de N caracteres: no se consulta (salvo para volver a la vista si se borró la búsqueda).
            if (q.length > 0 && q.length < this.searchmin && !this.searching) return;
            this.searchTimer = setTimeout(() => this.loadList(), 400);
        },
        async loadList(silent) {
            if (!silent) this.loadingList = true;
            try {
                const q = (this.search || '').trim();
                const data = await this.call('desk_list', {
                    tab: this.tab, search: q.length >= this.searchmin ? q : '', page: 0, perpage: 100,
                    areaid: this.areaid, level: this.showLevels || !q ? this.level : '',
                });
                if ((this.search || '').trim() !== q) return; // Llegó tarde: ya se escribió otra cosa.
                const counts = {};
                (data.counts || []).forEach(c => { counts[c.tab] = c.count; });
                this.counts = counts;
                this.me = data.me;
                this.available = data.me.available;
                this.searchmin = data.searchmin || 2;
                this.searching = data.tab === 'search';
                if (!this.searching && data.tab !== this.tab) {
                    this.tab = data.tab; // El servidor corrigió la vista (p. ej. "Todos" sin permiso).
                }
                this.areas = data.areas || [];
                const levels = {};
                (data.levels || []).forEach(l => { levels[l.level] = l.count; });
                this.levels = levels;
                this.tickets = data.tickets || [];
                this.total = data.total || 0;
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
                if (this.isMobile && !(window.history.state && window.history.state.gmkTicket)) {
                    // Celular: el boton "atras" del telefono vuelve a la lista, no sale de la pagina.
                    window.history.pushState({ gmkTicket: id }, '');
                }
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
            if (data.attachlimits) this.attachlimits = data.attachlimits;
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
            if (!this.canSend || !this.active) return;
            this.sending = true;
            this.sendError = '';
            try {
                const data = await this.call('send_message', {
                    ticketid: this.active.id, body: body,
                    internal: this.mode === 'note', askinfo: this.mode === 'askinfo',
                    draftitemid: this.attach.count > 0 ? this.attach.draftitemid : 0,
                });
                this.draft = '';
                this.mode = 'reply';
                if (this.$refs.picker) this.$refs.picker.reset();
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
            this.assignDialog = { open: true, loading: true, users: [], categoryid: this.active.categoryid, categories: [], categoryLabel: '' };
            try {
                const data = await this.call('get_assignable', { ticketid: this.active.id, categoryid: 0 });
                this.assignDialog.users = data.users || [];
                // Confidential topics are only offered for tickets that already are confidential.
                this.assignDialog.categories = (data.categories || [])
                    .filter(c => !c.confidential || this.active.confidential || c.id === this.active.categoryid)
                    .map(c => ({ id: c.id, label: c.areaname + ' / ' + c.name + (c.confidential ? ' (confidencial)' : '') }));
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.assignDialog.loading = false;
            }
        },
        async loadAssignable() {
            const cat = this.assignDialog.categories.find(c => c.id === this.assignDialog.categoryid);
            this.assignDialog.categoryLabel = cat ? cat.label : '';
            this.assignDialog.loading = true;
            this.assignDialog.users = [];
            try {
                const data = await this.call('get_assignable', { ticketid: this.active.id,
                    categoryid: this.assignDialog.categoryid === this.active.categoryid ? 0 : this.assignDialog.categoryid });
                this.assignDialog.users = data.users || [];
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.assignDialog.loading = false;
            }
        },
        async assignTo(userid) {
            this.assignDialog.open = false;
            const move = this.assignDialog.categoryid && this.assignDialog.categoryid !== this.active.categoryid;
            await this.act('assign', { targetuserid: userid, categoryid: move ? this.assignDialog.categoryid : 0 });
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
