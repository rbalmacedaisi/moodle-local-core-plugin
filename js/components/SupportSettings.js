/**
 * SupportSettings.js
 * Configuracion de la mesa de ayuda (20261001120, §12-bis del plan): areas con
 * horario habil, temas, agentes, respaldos, supervisores y gerencia, mensajes
 * automaticos y parametros. Cada guardado devuelve la configuracion completa.
 */
Vue.component('support-user-picker', {
    props: { value: { type: [Number, String], default: null }, label: { type: String, default: 'Persona' } },
    template: `
      <v-autocomplete :value="value" @change="$emit('input', $event)" :items="items" item-text="label" item-value="id"
        :search-input.sync="query" :loading="loading" no-filter outlined dense hide-no-data clearable
        :label="label" placeholder="Escribe nombre, correo o cédula"></v-autocomplete>`,
    data() { return { items: [], query: '', loading: false, timer: null }; },
    watch: {
        query(q) {
            clearTimeout(this.timer);
            if (!q || q.length < 2) return;
            this.timer = setTimeout(async () => {
                this.loading = true;
                try {
                    const data = await window.gmkSupportCall('local_grupomakro_support_search_users', { query: q });
                    this.items = (data.users || []).map(u => ({ id: u.id, label: u.fullname + ' · ' + u.email + ' (id ' + u.id + ')' }));
                } catch (e) {
                    this.items = [];
                } finally {
                    this.loading = false;
                }
            }, 350);
        },
    },
});

Vue.component('support-settings', {
    props: { config: { type: Object, default: () => ({}) } },
    template: `
    <v-container fluid class="gmk-sup pa-2 pa-md-4">
      <div class="d-flex align-center mb-3">
        <v-icon color="primary" class="mr-2">mdi-cog-outline</v-icon>
        <span class="text-subtitle-1 text-sm-h6 font-weight-medium">Configuración<span class="d-none d-sm-inline"> de la mesa de ayuda</span></span>
        <v-spacer></v-spacer>
        <v-btn small outlined color="primary" :href="config.deskurl" aria-label="Ir a la bandeja"><v-icon :left="!$vuetify.breakpoint.xsOnly" small>mdi-lifebuoy</v-icon><span class="d-none d-sm-inline">Ir a la bandeja</span></v-btn>
      </div>
      <v-progress-linear v-if="loading" indeterminate></v-progress-linear>
      <v-card outlined v-if="s">
        <v-tabs v-model="tab" show-arrows>
          <v-tab>Áreas</v-tab><v-tab>Temas y agentes</v-tab><v-tab>Respaldos</v-tab>
          <v-tab>Supervisores y gerencia</v-tab><v-tab>Mensajes automáticos</v-tab><v-tab>Avisos y escalamiento</v-tab><v-tab>Parámetros</v-tab><v-tab>Auditoría</v-tab>
        </v-tabs>
        <v-divider></v-divider>
        <v-tabs-items v-model="tab">
          <!-- Areas -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Lo primero que elige el estudiante. Un área con enlace externo (Finanzas) no abre ticket: muestra un botón con el enlace y un mensaje inicial (admite {estudiante} y {cedula}).</p>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Orden</th><th>Área</th><th>Horario hábil</th><th>Enlace externo</th><th>Activa</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="a in s.areas" :key="a.id">
                    <td>{{ a.sortorder }}</td><td><v-icon small class="mr-1">{{ a.icon }}</v-icon>{{ a.name }} <span class="text--secondary">({{ a.code }})</span></td>
                    <td class="text-caption">{{ a.hourstext || '—' }}</td>
                    <td class="text-caption">{{ a.external_url || '—' }}</td>
                    <td><v-icon small :color="a.active ? 'success' : 'grey'">{{ a.active ? 'mdi-check' : 'mdi-close' }}</v-icon></td>
                    <td class="text-right"><v-btn x-small text color="primary" @click="editArea(a)">Editar</v-btn></td>
                  </tr>
                </tbody>
              </v-simple-table>
              <v-btn small depressed color="primary" class="mt-3" @click="editArea(null)"><v-icon left small>mdi-plus</v-icon>Nueva área</v-btn>
            </v-card-text>
          </v-tab-item>

          <!-- Topics and agents -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Los temas confidenciales solo los ven sus agentes, el asignado y gerencia. "Reparto automático" asigna al agente disponible con menos tickets abiertos.</p>
              <div v-for="a in ticketAreas" :key="a.id" class="mb-5">
                <div class="text-subtitle-1 font-weight-medium mb-1">{{ a.name }}</div>
                <v-simple-table dense class="gmk-stack">
                  <thead><tr><th>Tema</th><th>Agentes</th><th>Reparto</th><th>Activo</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="c in topicsOf(a.id)" :key="c.id">
                      <td>{{ c.name }} <v-icon v-if="c.confidential" x-small color="purple">mdi-lock</v-icon></td>
                      <td>
                        <v-chip v-for="g in agentsOf(c.id)" :key="g.id" small class="mr-1 mb-1" close @click:close="removeAgent(g)"
                          @click="editAgent(g)" :color="g.available ? '' : 'grey lighten-2'">
                          {{ g.fullname }}<span v-if="g.max_open" class="ml-1 text--secondary">(máx {{ g.max_open }})</span>
                          <v-icon v-if="!g.can_auto_assign" x-small class="ml-1" title="No recibe reparto automático">mdi-hand-back-left-off</v-icon>
                        </v-chip>
                        <v-btn x-small text color="primary" @click="addAgent(c)"><v-icon x-small>mdi-plus</v-icon> agente</v-btn>
                      </td>
                      <td>{{ c.autoassign ? 'Automático' : 'Manual (cola)' }}</td>
                      <td><v-icon small :color="c.active ? 'success' : 'grey'">{{ c.active ? 'mdi-check' : 'mdi-close' }}</v-icon></td>
                      <td class="text-right"><v-btn x-small text color="primary" @click="editTopic(c)">Editar</v-btn></td>
                    </tr>
                  </tbody>
                </v-simple-table>
                <v-btn x-small text color="primary" @click="editTopic({ areaid: a.id })"><v-icon x-small left>mdi-plus</v-icon>Nuevo tema</v-btn>
              </div>

              <v-divider class="my-4"></v-divider>
              <div class="text-subtitle-1 font-weight-medium mb-1">Horario hábil por agente</div>
              <p class="text--secondary">Con un ticket asignado, el semáforo, el escalamiento, el plazo de acuse y el modo incisivo cuentan solo las horas hábiles de su agente. Sin horario propio, el agente trabaja el horario del área del ticket. Si el asignado está fuera de su horario y el área está abierta, el ticket pasa a otro agente del tema o a su respaldo que esté en horario.</p>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Agente</th><th>Horario</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="h in s.agenthours" :key="h.userid">
                    <td>{{ h.fullname }}</td>
                    <td class="text-caption">
                      <span v-if="h.hours">{{ h.hourstext }}</span>
                      <span v-else class="text--secondary">Hereda del área: {{ h.inherits || '—' }}</span>
                    </td>
                    <td class="text-right">
                      <v-btn x-small text color="primary" @click="editAgentHours(h)">Editar</v-btn>
                      <v-btn v-if="h.hours" x-small text color="error" @click="save('agenthours', { userid: h.userid, hours: '' })">Usar el del área</v-btn>
                    </td>
                  </tr>
                </tbody>
              </v-simple-table>
            </v-card-text>
          </v-tab-item>

          <!-- Backups -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Si todos los agentes de un tema están ausentes o llenos, el ticket pasa a sus respaldos. Sin respaldo queda en la cola del área, visible para los supervisores.</p>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Agente</th><th>Respaldo</th><th>Temas</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="b in s.backups" :key="b.id">
                    <td>{{ b.fullname }}</td><td>{{ b.backupname }}</td>
                    <td>{{ b.categoryid ? topicName(b.categoryid) : 'Todos sus temas' }}</td>
                    <td class="text-right"><v-btn x-small text color="error" @click="remove('backup', b.id, 'el respaldo')">Quitar</v-btn></td>
                  </tr>
                </tbody>
              </v-simple-table>
              <v-row class="mt-3" dense>
                <v-col cols="12" md="4"><support-user-picker v-model="newBackup.userid" label="Agente ausente"></support-user-picker></v-col>
                <v-col cols="12" md="4"><support-user-picker v-model="newBackup.backupuserid" label="Respaldo"></support-user-picker></v-col>
                <v-col cols="12" md="3"><v-select v-model="newBackup.categoryid" :items="topicItems" item-text="label" item-value="id" outlined dense label="Temas"></v-select></v-col>
                <v-col cols="12" md="1"><v-btn depressed color="primary" :disabled="!newBackup.userid || !newBackup.backupuserid" @click="saveBackup">Agregar</v-btn></v-col>
              </v-row>
            </v-card-text>
          </v-tab-item>

          <!-- Persons -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Supervisores por rol: Secretaría Académica, Dirección Académica, Psicología y Bienestar (capacidad support_supervise). Aquí se agregan personas sueltas. Gerencia recibe escalamientos y ve los tickets confidenciales.</p>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Tipo</th><th>Persona</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="p in s.persons" :key="p.id">
                    <td>{{ p.kind === 'gerencia' ? 'Gerencia' : 'Supervisor' }}</td><td>{{ p.fullname }}</td>
                    <td class="text-right"><v-btn x-small text color="error" @click="remove('person', p.id, 'a ' + p.fullname)">Quitar</v-btn></td>
                  </tr>
                </tbody>
              </v-simple-table>
              <v-row class="mt-3" dense>
                <v-col cols="12" md="3"><v-select v-model="newPerson.kind" :items="[{ value: 'supervisor', text: 'Supervisor' }, { value: 'gerencia', text: 'Gerencia' }]" outlined dense label="Tipo"></v-select></v-col>
                <v-col cols="12" md="6"><support-user-picker v-model="newPerson.userid"></support-user-picker></v-col>
                <v-col cols="12" md="2"><v-btn depressed color="primary" :disabled="!newPerson.userid" @click="savePerson">Agregar</v-btn></v-col>
              </v-row>
            </v-card-text>
          </v-tab-item>

          <!-- Templates -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Aparecen en el chat como mensajes del sistema. Variables: {{ s.variables.join(' ') }}.</p>
              <div v-for="t in s.templates" :key="t.id" class="mb-4">
                <div class="d-flex align-center">
                  <span class="font-weight-medium">{{ t.name }}</span>
                  <v-spacer></v-spacer>
                  <v-switch v-model="t.enabled" :true-value="1" :false-value="0" dense hide-details class="mt-0 mr-2" label="Activo"></v-switch>
                  <v-btn x-small depressed color="primary" @click="save('template', { id: t.id, body: t.body, enabled: t.enabled })">Guardar</v-btn>
                </div>
                <v-textarea v-model="t.body" outlined dense auto-grow rows="2" hide-details class="mt-1"></v-textarea>
              </div>
            </v-card-text>
          </v-tab-item>

          <!-- Alerts and escalation (phase 2) -->
          <v-tab-item>
            <v-card-text v-if="esc && inc">
              <div class="text-subtitle-1 font-weight-medium">Escalera de escalamiento</div>
              <p class="text--secondary mb-2">Se cuenta en minutos hábiles del área desde que el solicitante espera una respuesta del personal. Los umbrales están en Parámetros (verde {{ cfg('tkt_sla_green') }}, amarillo {{ cfg('tkt_sla_yellow') }}, naranja {{ cfg('tkt_sla_orange') }}, rojo {{ cfg('tkt_sla_red') }} min). En rojo, el aviso a gerencia se repite cada {{ cfg('tkt_red_repeat') }} min hábiles.</p>
              <v-simple-table dense class="gmk-stack mb-2">
                <thead><tr><th>Al llegar a</th><th v-for="a in escActions" :key="a.key" class="text-center" :title="a.help">{{ a.label }}</th></tr></thead>
                <tbody>
                  <tr v-for="l in escLevels" :key="l.n">
                    <td>{{ l.label }} ({{ cfg(l.cfg) }} min)</td>
                    <td v-for="a in escActions" :key="a.key" class="text-center">
                      <v-simple-checkbox :value="!!esc[l.n][a.key]" @input="esc[l.n][a.key] = $event ? 1 : 0"></v-simple-checkbox>
                    </td>
                  </tr>
                </tbody>
              </v-simple-table>
              <v-btn small depressed color="primary" @click="saveJson('tkt_escalation', esc)">Guardar escalera</v-btn>

              <v-divider class="my-5"></v-divider>
              <div class="text-subtitle-1 font-weight-medium">Modo incisivo: perfiles</div>
              <p class="text--secondary mb-2">Solo actúa si la persona está Disponible y dentro del horario hábil de su área; si no, solo queda la píldora y la campanita. El aviso se apaga únicamente al abrir o tomar el ticket. Las notificaciones nunca muestran el texto del mensaje (Psicología: "Ticket confidencial").</p>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Perfil</th><th>Bloqueo a pantalla completa</th><th>Sonido</th><th>Tono</th><th>Título y favicon</th><th>Notificación del sistema</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(p, code) in inc.profiles" :key="code">
                    <td><v-text-field v-model="p.label" dense hide-details style="min-width: 150px;"></v-text-field><span class="text-caption text--secondary">{{ code }}</span></td>
                    <td><v-select v-model="p.lock" :items="lockItems" dense hide-details style="min-width: 190px;"></v-select></td>
                    <td><v-simple-checkbox :value="!!p.sound" @input="p.sound = $event ? 1 : 0"></v-simple-checkbox></td>
                    <td><v-select v-model="p.soundname" :items="['alarma', 'campana', 'pitido']" dense hide-details style="width: 110px;"></v-select></td>
                    <td><v-simple-checkbox :value="!!p.title" @input="p.title = $event ? 1 : 0"></v-simple-checkbox></td>
                    <td><v-simple-checkbox :value="!!p.osnotify" @input="p.osnotify = $event ? 1 : 0"></v-simple-checkbox></td>
                    <td><v-btn x-small text color="error" @click="removeProfile(code)">Quitar</v-btn></td>
                  </tr>
                </tbody>
              </v-simple-table>
              <div class="d-flex align-center mt-2">
                <v-text-field v-model="newProfile" label="Código del perfil nuevo" dense outlined hide-details style="max-width: 260px;"></v-text-field>
                <v-btn small text color="primary" class="ml-2" @click="addProfile">Agregar perfil</v-btn>
              </div>

              <div class="text-subtitle-1 font-weight-medium mt-5">¿Quién usa cada perfil?</div>
              <p class="text--secondary mb-2">Gana el perfil por persona; si no tiene, el más fuerte de las áreas donde es agente; si no es agente, el de administradores (administradores del sitio y supervisores); si no, el perfil por defecto.</p>
              <v-row dense>
                <v-col v-for="a in ticketAreas" :key="a.id" cols="12" sm="6" md="3">
                  <v-select :value="inc.areas[a.code] || ''" @change="setAreaProfile(a.code, $event)" :items="profileItems(true)" :label="'Agentes de ' + a.name" outlined dense hide-details></v-select>
                </v-col>
                <v-col cols="12" sm="6" md="3"><v-select v-model="inc.admins" :items="profileItems(false)" label="Administradores y supervisores" outlined dense hide-details></v-select></v-col>
                <v-col cols="12" sm="6" md="3"><v-select v-model="inc.others" :items="profileItems(false)" label="Por defecto" outlined dense hide-details></v-select></v-col>
              </v-row>
              <div class="text-subtitle-2 mt-4">Por persona</div>
              <v-simple-table dense class="gmk-stack">
                <tbody>
                  <tr v-for="(code, uid) in inc.users" :key="uid">
                    <td>{{ userName(uid) }}</td>
                    <td><v-select v-model="inc.users[uid]" :items="profileItems(false)" dense hide-details style="max-width: 240px;"></v-select></td>
                    <td class="text-right"><v-btn x-small text color="error" @click="removeUserProfile(uid)">Quitar</v-btn></td>
                  </tr>
                </tbody>
              </v-simple-table>
              <v-row dense class="mt-2" align="center">
                <v-col cols="12" md="6"><support-user-picker v-model="newUserProfile.userid" label="Persona"></support-user-picker></v-col>
                <v-col cols="8" md="4"><v-select v-model="newUserProfile.code" :items="profileItems(false)" label="Perfil" outlined dense hide-details></v-select></v-col>
                <v-col cols="4" md="2"><v-btn small text color="primary" @click="addUserProfile">Agregar</v-btn></v-col>
              </v-row>
              <v-btn small depressed color="primary" class="mt-3" @click="saveJson('tkt_incisive', inc)">Guardar perfiles</v-btn>
            </v-card-text>
          </v-tab-item>

          <!-- Parameters -->
          <v-tab-item>
            <v-card-text>
              <v-row dense>
                <v-col v-for="(c, name) in plainConfig" :key="name" cols="12" md="6">
                  <div class="d-flex align-center">
                    <v-text-field v-model="c.value" :label="c.label" :hint="'Por defecto: ' + c.default" persistent-hint
                      outlined dense :type="c.type === 'int' ? 'number' : 'text'"></v-text-field>
                    <v-btn small text color="primary" class="ml-1 mb-4" @click="save('config', { name: name, value: c.value })">Guardar</v-btn>
                  </div>
                </v-col>
              </v-row>
            </v-card-text>
          </v-tab-item>

          <!-- Audit -->
          <v-tab-item>
            <v-card-text>
              <v-simple-table dense class="gmk-stack">
                <thead><tr><th>Cuándo</th><th>Quién</th><th>Cambio</th><th>Antes</th><th>Después</th></tr></thead>
                <tbody>
                  <tr v-for="e in s.audit" :key="e.id">
                    <td class="text-no-wrap">{{ fmt.date(e.timecreated) }}</td><td>{{ e.actorname }}</td><td>{{ e.eventtype }}</td>
                    <td class="text-caption" style="max-width: 280px; word-break: break-all;">{{ e.oldvalue }}</td>
                    <td class="text-caption" style="max-width: 280px; word-break: break-all;">{{ e.newvalue }}</td>
                  </tr>
                </tbody>
              </v-simple-table>
            </v-card-text>
          </v-tab-item>
        </v-tabs-items>
      </v-card>

      <!-- Area dialog -->
      <v-dialog v-model="areaDialog.open" max-width="720" :fullscreen="$vuetify.breakpoint.xsOnly" scrollable>
        <v-card v-if="areaDialog.row">
          <v-card-title>{{ areaDialog.row.id ? 'Editar área' : 'Nueva área' }}</v-card-title>
          <v-card-text>
            <v-row dense>
              <v-col cols="12" md="6"><v-text-field v-model="areaDialog.row.name" label="Nombre" outlined dense></v-text-field></v-col>
              <v-col cols="12" md="3"><v-text-field v-model="areaDialog.row.code" label="Código" outlined dense :disabled="!!areaDialog.row.id"></v-text-field></v-col>
              <v-col cols="12" md="3"><v-text-field v-model="areaDialog.row.sortorder" type="number" label="Orden" outlined dense></v-text-field></v-col>
              <v-col cols="12"><v-text-field v-model="areaDialog.row.description" label="Descripción para el estudiante" outlined dense></v-text-field></v-col>
              <v-col cols="12" md="6"><v-text-field v-model="areaDialog.row.icon" label="Ícono (mdi-...)" outlined dense></v-text-field></v-col>
              <v-col cols="12" md="3"><v-switch v-model="areaDialog.row.active" :true-value="1" :false-value="0" label="Activa" dense></v-switch></v-col>
              <v-col cols="12" md="3"><v-switch v-model="areaDialog.row.teacher_visible" :true-value="1" :false-value="0" label="Para docentes" dense></v-switch></v-col>
            </v-row>
            <div class="font-weight-medium mb-1">Horario hábil</div>
            <p class="text-caption text--secondary mb-2">Tramos por día, separados por coma: 08:00-12:00, 13:00-17:00. Vacío = cerrado.</p>
            <v-row dense>
              <v-col v-for="d in days" :key="d.n" cols="12" md="6">
                <v-text-field v-model="areaDialog.hours[d.n]" :label="d.label" outlined dense placeholder="cerrado"></v-text-field>
              </v-col>
            </v-row>
            <div class="font-weight-medium mb-1">Enlace externo (no abre ticket)</div>
            <v-row dense>
              <v-col cols="12" md="7"><v-text-field v-model="areaDialog.row.external_url" label="URL https (vacío = abre tickets)" outlined dense></v-text-field></v-col>
              <v-col cols="12" md="5"><v-text-field v-model="areaDialog.row.external_label" label="Texto del botón" outlined dense></v-text-field></v-col>
              <v-col cols="12"><v-textarea v-model="areaDialog.row.external_message" label="Mensaje inicial" rows="2" auto-grow outlined dense></v-textarea></v-col>
            </v-row>
          </v-card-text>
          <v-card-actions>
            <v-btn v-if="areaDialog.row.id" text color="error" @click="removeArea">Borrar</v-btn>
            <v-spacer></v-spacer>
            <v-btn text @click="areaDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed @click="saveArea">Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Topic dialog -->
      <v-dialog v-model="topicDialog.open" max-width="560" :fullscreen="$vuetify.breakpoint.xsOnly">
        <v-card v-if="topicDialog.row">
          <v-card-title>{{ topicDialog.row.id ? 'Editar tema' : 'Nuevo tema' }}</v-card-title>
          <v-card-text>
            <v-select v-model="topicDialog.row.areaid" :items="ticketAreas" item-text="name" item-value="id" label="Área" outlined dense></v-select>
            <v-text-field v-model="topicDialog.row.name" label="Nombre" outlined dense></v-text-field>
            <v-text-field v-model="topicDialog.row.description" label="Descripción para el estudiante" outlined dense></v-text-field>
            <v-row dense>
              <v-col cols="6"><v-text-field v-model="topicDialog.row.sortorder" type="number" label="Orden" outlined dense></v-text-field></v-col>
              <v-col cols="6"><v-select v-model="topicDialog.row.priority_default" :items="[{ value: 0, text: 'Baja' }, { value: 1, text: 'Normal' }, { value: 2, text: 'Alta' }]" label="Prioridad inicial" outlined dense></v-select></v-col>
            </v-row>
            <v-switch v-model="topicDialog.row.confidential" :true-value="1" :false-value="0" label="Confidencial" dense hide-details></v-switch>
            <v-switch v-model="topicDialog.row.autoassign" :true-value="1" :false-value="0" label="Reparto automático" dense hide-details></v-switch>
            <v-switch v-model="topicDialog.row.active" :true-value="1" :false-value="0" label="Activo" dense hide-details></v-switch>
          </v-card-text>
          <v-card-actions>
            <v-btn v-if="topicDialog.row.id" text color="error" @click="removeTopic">Borrar</v-btn>
            <v-spacer></v-spacer>
            <v-btn text @click="topicDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed @click="saveTopic">Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Agent dialog -->
      <v-dialog v-model="agentDialog.open" max-width="520" :fullscreen="$vuetify.breakpoint.xsOnly">
        <v-card v-if="agentDialog.row">
          <v-card-title>{{ agentDialog.row.id ? agentDialog.row.fullname : 'Agregar agente a ' + topicName(agentDialog.row.categoryid) }}</v-card-title>
          <v-card-text>
            <support-user-picker v-if="!agentDialog.row.id" v-model="agentDialog.row.userid"></support-user-picker>
            <v-text-field v-model="agentDialog.row.max_open" type="number" label="Tope de tickets abiertos (0 = sin tope)" outlined dense></v-text-field>
            <v-switch v-model="agentDialog.row.can_auto_assign" :true-value="1" :false-value="0" label="Recibe reparto automático" dense hide-details></v-switch>
            <v-switch v-if="agentDialog.row.id" v-model="agentDialog.row.available" :true-value="1" :false-value="0" label="Disponible (vale para todos sus temas)" dense hide-details></v-switch>
          </v-card-text>
          <v-card-actions><v-spacer></v-spacer>
            <v-btn text @click="agentDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :disabled="!agentDialog.row.userid" @click="saveAgent">Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Agent hours dialog -->
      <v-dialog v-model="agentHoursDialog.open" max-width="640" :fullscreen="$vuetify.breakpoint.xsOnly" scrollable>
        <v-card v-if="agentHoursDialog.row">
          <v-card-title>Horario de {{ agentHoursDialog.row.fullname }}</v-card-title>
          <v-card-text>
            <p class="text-caption text--secondary mb-2">Tramos por día, separados por coma: 08:00-12:00, 13:00-17:00. Vacío = no trabaja ese día. Todo vacío = vuelve al horario del área.</p>
            <v-row dense>
              <v-col v-for="d in days" :key="d.n" cols="12" md="6">
                <v-text-field v-model="agentHoursDialog.hours[d.n]" :label="d.label" outlined dense placeholder="no trabaja"></v-text-field>
              </v-col>
            </v-row>
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="agentHoursDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed @click="saveAgentHours">Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <v-snackbar v-model="snack.show" :color="snack.color" timeout="5000">{{ snack.text }}</v-snackbar>
    </v-container>
    `,
    data() {
        return {
            fmt: window.gmkSupportFormat,
            s: null,
            loading: false,
            tab: 0,
            days: [{ n: 1, label: 'Lunes' }, { n: 2, label: 'Martes' }, { n: 3, label: 'Miércoles' }, { n: 4, label: 'Jueves' },
                { n: 5, label: 'Viernes' }, { n: 6, label: 'Sábado' }, { n: 7, label: 'Domingo' }],
            areaDialog: { open: false, row: null, hours: {} },
            agentHoursDialog: { open: false, row: null, hours: {} },
            topicDialog: { open: false, row: null },
            agentDialog: { open: false, row: null },
            newBackup: { userid: null, backupuserid: null, categoryid: 0 },
            newPerson: { kind: 'supervisor', userid: null },
            snack: { show: false, text: '', color: 'success' },
            esc: null,
            inc: null,
            newProfile: '',
            newUserProfile: { userid: null, code: null },
            escLevels: [
                { n: '1', label: 'Verde con aviso', cfg: 'tkt_sla_green' }, { n: '2', label: 'Amarillo', cfg: 'tkt_sla_yellow' },
                { n: '3', label: 'Naranja', cfg: 'tkt_sla_orange' }, { n: '4', label: 'Rojo', cfg: 'tkt_sla_red' },
            ],
            escActions: [
                { key: 'agent', label: 'Avisar al agente', help: 'Al asignado; si no hay, a los agentes del tema' },
                { key: 'student', label: 'Mensaje al solicitante', help: 'Plantilla "20 min sin respuesta"' },
                { key: 'supervisors', label: 'Avisar a supervisores', help: 'Solo los que pueden ver el ticket' },
                { key: 'gerencia', label: 'Avisar a gerencia', help: 'En rojo se repite' },
                { key: 'assign', label: 'Asignar si está en cola', help: 'Al agente disponible con menos carga' },
                { key: 'priority', label: 'Prioridad alta', help: '' },
                { key: 'requeue', label: 'A la cola si el agente está ausente', help: '' },
            ],
            lockItems: [
                { value: 'none', text: 'No bloquear' }, { value: 'orange', text: 'Solo naranja o rojo' }, { value: 'all', text: 'Siempre' },
            ],
        };
    },
    computed: {
        plainConfig() {
            const out = {};
            if (!this.s) return out;
            Object.keys(this.s.config).forEach(k => { if (this.s.config[k].type !== 'json') out[k] = this.s.config[k]; });
            return out;
        },
        ticketAreas() {
            return this.s ? this.s.areas.filter(a => !a.external_url) : [];
        },
        topicItems() {
            if (!this.s) return [];
            return [{ id: 0, label: 'Todos sus temas' }].concat(this.s.categories.map(c => ({ id: c.id, label: this.areaName(c.areaid) + ' / ' + c.name })));
        },
    },
    mounted() {
        this.load();
        // Celular: etiquetas de columna para mostrar las tablas como tarjetas.
        // Un observer cubre tambien las pestanas que Vuetify pinta al abrirlas.
        let pending = null;
        this.labelObserver = new MutationObserver(() => {
            if (pending) return;
            pending = setTimeout(() => {
                pending = null;
                window.gmkSupportLabelTables(this.$el);
            }, 50);
        });
        this.labelObserver.observe(this.$el, { childList: true, subtree: true });
    },
    beforeDestroy() {
        if (this.labelObserver) this.labelObserver.disconnect();
    },
    methods: {
        notify(text, color) {
            this.snack = { show: true, text: text, color: color || 'success' };
        },
        apply(data) {
            this.s = JSON.parse(data.json);
            try {
                this.esc = JSON.parse(this.s.config.tkt_escalation.value);
                const inc = JSON.parse(this.s.config.tkt_incisive.value);
                if (Array.isArray(inc.users)) inc.users = {};
                if (Array.isArray(inc.areas)) inc.areas = {};
                this.inc = inc;
            } catch (e) {
                this.esc = null;
                this.inc = null;
            }
        },
        cfg(name) {
            return this.s && this.s.config[name] ? this.s.config[name].value : '';
        },
        profileItems(withNone) {
            const items = Object.keys(this.inc ? this.inc.profiles : {}).map(code => ({ value: code, text: this.inc.profiles[code].label + ' (' + code + ')' }));
            return withNone ? [{ value: '', text: 'Sin perfil propio' }].concat(items) : items;
        },
        setAreaProfile(code, value) {
            if (value) this.$set(this.inc.areas, code, value); else this.$delete(this.inc.areas, code);
        },
        addProfile() {
            const code = (this.newProfile || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
            if (!code || this.inc.profiles[code]) return;
            this.$set(this.inc.profiles, code, { label: code, lock: 'none', sound: 1, title: 1, osnotify: 1, soundname: 'campana' });
            this.newProfile = '';
        },
        removeProfile(code) {
            if (Object.keys(this.inc.profiles).length <= 1) return;
            this.$delete(this.inc.profiles, code);
        },
        addUserProfile() {
            if (!this.newUserProfile.userid || !this.newUserProfile.code) return;
            this.$set(this.inc.users, String(this.newUserProfile.userid), this.newUserProfile.code);
            this.newUserProfile = { userid: null, code: null };
        },
        removeUserProfile(uid) {
            this.$delete(this.inc.users, uid);
        },
        userName(uid) {
            const n = this.s && this.s.usernames ? this.s.usernames[uid] : null;
            return n || ('Usuario ' + uid);
        },
        saveJson(name, value) {
            return this.save('config', { name: name, value: JSON.stringify(value) });
        },
        async load() {
            this.loading = true;
            try {
                this.apply(await window.gmkSupportCall('local_grupomakro_support_settings_get', {}));
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.loading = false;
            }
        },
        async save(section, payload, op) {
            try {
                this.apply(await window.gmkSupportCall('local_grupomakro_support_settings_save', {
                    section: section, op: op || 'save', payload: JSON.stringify(payload),
                }));
                this.notify('Guardado');
                return true;
            } catch (e) {
                this.notify(e.message, 'error');
                return false;
            }
        },
        async confirm(text) {
            if (this.$swal) {
                const r = await this.$swal.fire({ text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'Cancelar' });
                return r.isConfirmed;
            }
            return window.confirm(text);
        },
        async remove(section, id, what) {
            if (await this.confirm('¿Quitar ' + what + '?')) {
                await this.save(section, { id: id }, 'delete');
            }
        },
        areaName(id) {
            const a = this.s.areas.find(x => x.id === id);
            return a ? a.name : '';
        },
        topicName(id) {
            const c = this.s && this.s.categories.find(x => x.id === id);
            return c ? this.areaName(c.areaid) + ' / ' + c.name : '';
        },
        topicsOf(areaid) {
            return this.s.categories.filter(c => c.areaid === areaid);
        },
        agentsOf(catid) {
            return this.s.agents.filter(g => g.categoryid === catid);
        },
        editArea(a) {
            const row = a ? Object.assign({}, a) : { name: '', code: '', description: '', icon: 'mdi-help-circle-outline', sortorder: 0,
                active: 1, teacher_visible: 0, external_url: '', external_label: '', external_message: '', hours: {} };
            const hours = {};
            this.days.forEach(d => {
                const ranges = (row.hours && row.hours[d.n]) || [];
                hours[d.n] = ranges.map(r => r[0] + '-' + r[1]).join(', ');
            });
            this.areaDialog = { open: true, row: row, hours: hours };
        },
        editAgentHours(h) {
            const hours = {};
            this.days.forEach(d => {
                const ranges = (h.hours && h.hours[d.n]) || [];
                hours[d.n] = ranges.map(r => r[0] + '-' + r[1]).join(', ');
            });
            this.agentHoursDialog = { open: true, row: h, hours: hours };
        },
        async saveAgentHours() {
            let hours;
            try {
                hours = this.hoursToJson(this.agentHoursDialog.hours);
            } catch (e) {
                this.notify(e.message, 'error');
                return;
            }
            const empty = Object.keys(hours).length === 0;
            const ok = await this.save('agenthours', { userid: this.agentHoursDialog.row.userid, hours: empty ? '' : JSON.stringify(hours) });
            if (ok) this.agentHoursDialog.open = false;
        },
        hoursToJson(source) {
            const src = source || this.areaDialog.hours;
            const out = {};
            for (const d of this.days) {
                const text = (src[d.n] || '').trim();
                if (!text) continue;
                out[d.n] = text.split(',').map(part => {
                    const m = part.trim().match(/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/);
                    if (!m) throw new Error(d.label + ': "' + part.trim() + '" no tiene el formato 08:00-17:00');
                    return [m[1], m[2]];
                });
            }
            return out;
        },
        async saveArea() {
            let hours;
            try {
                hours = this.hoursToJson();
            } catch (e) {
                this.notify(e.message, 'error');
                return;
            }
            const r = this.areaDialog.row;
            const ok = await this.save('area', {
                id: r.id || 0, code: r.code, name: r.name, description: r.description, icon: r.icon, sortorder: r.sortorder,
                active: r.active, teacher_visible: r.teacher_visible, hours: JSON.stringify(hours),
                external_url: r.external_url, external_label: r.external_label, external_message: r.external_message,
            });
            if (ok) this.areaDialog.open = false;
        },
        async removeArea() {
            if (await this.confirm('¿Borrar el área? Si tiene temas o tickets no se puede: desactívala.')) {
                if (await this.save('area', { id: this.areaDialog.row.id }, 'delete')) this.areaDialog.open = false;
            }
        },
        editTopic(c) {
            this.topicDialog = { open: true, row: Object.assign({ id: 0, name: '', description: '', sortorder: 0, priority_default: 1,
                confidential: 0, autoassign: 1, active: 1, sla_first_response_min: 20 }, c) };
        },
        async saveTopic() {
            if (await this.save('category', this.topicDialog.row)) this.topicDialog.open = false;
        },
        async removeTopic() {
            if (await this.confirm('¿Borrar el tema? Si tiene tickets no se puede: desactívalo.')) {
                if (await this.save('category', { id: this.topicDialog.row.id }, 'delete')) this.topicDialog.open = false;
            }
        },
        addAgent(c) {
            this.agentDialog = { open: true, row: { id: 0, categoryid: c.id, userid: null, max_open: 0, can_auto_assign: 1 } };
        },
        editAgent(g) {
            this.agentDialog = { open: true, row: Object.assign({}, g) };
        },
        async saveAgent() {
            const r = this.agentDialog.row;
            const original = r.id ? this.s.agents.find(g => g.id === r.id) : null;
            let ok = await this.save('agent', { id: r.id, categoryid: r.categoryid, userid: r.userid, max_open: r.max_open,
                can_auto_assign: r.can_auto_assign });
            if (ok && original && original.available !== r.available) {
                ok = await this.save('presence', { userid: r.userid, available: r.available });
            }
            if (ok) this.agentDialog.open = false;
        },
        async removeAgent(g) {
            await this.remove('agent', g.id, g.fullname + ' de este tema');
        },
        async saveBackup() {
            if (await this.save('backup', this.newBackup)) this.newBackup = { userid: null, backupuserid: null, categoryid: 0 };
        },
        async savePerson() {
            if (await this.save('person', this.newPerson)) this.newPerson = { kind: 'supervisor', userid: null };
        },
    },
});
