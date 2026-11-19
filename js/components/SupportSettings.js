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
          <v-tab>Supervisores y gerencia</v-tab><v-tab>Mensajes automáticos</v-tab><v-tab>Problemas comunes</v-tab><v-tab v-if="config.assistant">Asistente en la nube</v-tab><v-tab>Avisos y escalamiento</v-tab><v-tab>Parámetros</v-tab><v-tab>Auditoría</v-tab>
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

          <!-- Common problems (respuestas guiadas) -->
          <v-tab-item>
            <v-card-text>
              <p class="text--secondary">Antes de abrir una solicitud, la persona elige su problema y ve la respuesta con sus botones. Si se le soluciona, no se abre ticket. Si no, la solicitud se abre con el tema y el problema como asunto, el agente ve una nota interna y se le pide una captura o un video. Formato de la respuesta: una línea «1. …» por paso, «- …» para viñetas y **negrita**.</p>
              <div v-for="a in ticketAreas" :key="'faq' + a.id" class="mb-5">
                <div class="d-flex align-center mb-1">
                  <span class="text-subtitle-1 font-weight-medium">{{ a.name }}</span>
                  <v-spacer></v-spacer>
                  <v-btn small text color="primary" @click="editFaq({ areaid: a.id })"><v-icon left small>mdi-plus</v-icon>Problema</v-btn>
                </div>
                <p v-if="!faqsOf(a.id).length" class="text-caption text--secondary mb-0">Sin problemas comunes.</p>
                <v-card v-for="f in faqsOf(a.id)" :key="f.id" outlined class="mb-2 pa-3">
                  <div class="d-flex align-start">
                    <div class="flex-grow-1 gmk-min0">
                      <div class="font-weight-medium gmk-wrap">{{ f.problem }}</div>
                      <div class="text-caption text--secondary gmk-wrap">
                        {{ audienceLabel(f.audience) }} · {{ f.categoryid ? topicName(f.categoryid) : 'Primer tema del área' }}
                        · {{ f.links.length }} botón(es) · orden {{ f.sortorder }}
                        · <span :class="f.active ? 'success--text' : 'red--text'">{{ f.active ? 'Activo' : 'Inactivo' }}</span>
                      </div>
                      <div class="text-caption">Vistas {{ f.views }} · Se solucionó {{ f.solved }} · Siguió con el problema {{ f.notsolved }}</div>
                    </div>
                    <v-btn small text color="primary" @click="editFaq(f)">Editar</v-btn>
                  </div>
                </v-card>
              </div>
            </v-card-text>
          </v-tab-item>

          <!-- Weekly assistant (20261001126) -->
          <v-tab-item v-if="config.assistant">
            <v-card-text>
              <support-assistant ref="assistant" :areas="s.areas" :categories="s.categories" @edit="editFromSuggestion"
                @changed="load" @notify="notify"></support-assistant>
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

      <!-- Common problem dialog -->
      <v-dialog v-model="faqDialog.open" max-width="760" scrollable :fullscreen="$vuetify.breakpoint.xsOnly">
        <v-card v-if="faqDialog.row">
          <v-card-title class="gmk-wrap">{{ faqDialog.aiSugg ? 'Sugerencia del asistente: ' : '' }}{{ faqDialog.row.id ? 'Editar problema común' : 'Nuevo problema común' }} · {{ areaName(faqDialog.row.areaid) }}</v-card-title>
          <v-card-text>
            <v-text-field v-model="faqDialog.row.problem" label="Problema (lo que la persona elige)" outlined dense counter="255" maxlength="255"></v-text-field>
            <v-textarea v-model="faqDialog.row.answer" label="Respuesta" outlined auto-grow rows="5"
              hint="Una línea «1. …» por paso, «- …» para viñetas, **negrita**." persistent-hint></v-textarea>
            <div class="text-caption text--secondary mt-3 mb-1">Vista previa</div>
            <div class="gmk-faq-preview pa-3 mb-3" v-html="faqRender(faqDialog.row.answer)"></div>
            <div class="font-weight-medium mb-1">Botones con enlace</div>
            <div v-for="(l, i) in faqDialog.row.links" :key="i" class="gmk-faq-link mb-2">
              <v-select v-model="l.kind" :items="linkKinds" label="Tipo" outlined dense hide-details class="gmk-faq-link__kind"></v-select>
              <v-text-field v-model="l.label" label="Texto del botón" outlined dense hide-details class="gmk-faq-link__label"></v-text-field>
              <v-text-field v-model="l.url" label="Enlace https" outlined dense hide-details class="gmk-faq-link__url"></v-text-field>
              <v-btn icon aria-label="Quitar botón" @click="faqDialog.row.links.splice(i, 1)"><v-icon>mdi-delete-outline</v-icon></v-btn>
            </div>
            <v-btn small text color="primary" class="mb-3" :disabled="faqDialog.row.links.length >= 6"
              @click="faqDialog.row.links.push({ kind: 'web', label: '', url: '' })"><v-icon left small>mdi-plus</v-icon>Botón</v-btn>
            <v-row dense>
              <v-col cols="12" sm="6"><v-select v-model="faqDialog.row.audience" :items="audiences" label="Lo ven" outlined dense></v-select></v-col>
              <v-col cols="12" sm="6"><v-select v-model="faqDialog.row.categoryid" :items="faqTopics(faqDialog.row.areaid)" item-text="label" item-value="id"
                label="Tema si no se soluciona" outlined dense></v-select></v-col>
              <v-col cols="6"><v-text-field v-model="faqDialog.row.sortorder" type="number" label="Orden" outlined dense></v-text-field></v-col>
              <v-col cols="6"><v-switch v-model="faqDialog.row.active" :true-value="1" :false-value="0" label="Activo" dense class="mt-1"></v-switch></v-col>
            </v-row>
          </v-card-text>
          <v-card-actions class="flex-wrap">
            <v-btn v-if="faqDialog.row.id && !faqDialog.aiSugg" text color="error" @click="removeFaq">Borrar</v-btn>
            <v-spacer></v-spacer>
            <v-btn text @click="faqDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed @click="saveFaq">{{ faqDialog.aiSugg ? 'Añadir' : 'Guardar' }}</v-btn>
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
            faqDialog: { open: false, row: null, aiSugg: 0 },
            audiences: [{ value: 'all', text: 'Estudiantes y docentes' }, { value: 'student', text: 'Solo estudiantes' },
                { value: 'teacher', text: 'Solo docentes' }],
            linkKinds: [{ value: 'web', text: 'Web' }, { value: 'appstore', text: 'App Store' }, { value: 'playstore', text: 'Play Store' }],
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
        faqsOf(areaid) {
            return (this.s && this.s.faqs ? this.s.faqs : []).filter(f => f.areaid === areaid);
        },
        audienceLabel(a) {
            return { all: 'Estudiantes y docentes', student: 'Solo estudiantes', teacher: 'Solo docentes' }[a] || a;
        },
        faqTopics(areaid) {
            return [{ id: 0, label: 'Primer tema del área' }].concat(this.topicsOf(areaid).map(c => ({ id: c.id, label: c.name })));
        },
        editFaq(f) {
            const row = Object.assign({ id: 0, categoryid: 0, problem: '', answer: '', audience: 'all', sortorder: 10, active: 1, links: [] },
                JSON.parse(JSON.stringify(f)));
            this.faqDialog = { open: true, row: row, aiSugg: 0 };
        },
        /** "Editar y añadir" de una sugerencia del asistente: mismo dialogo, guarda por ai_action. */
        editFromSuggestion(sg) {
            const copy = x => JSON.parse(JSON.stringify(x));
            let row = { id: 0, areaid: sg.areaid, categoryid: sg.categoryid || 0, problem: sg.title, answer: sg.body,
                audience: sg.audience || 'all', sortorder: 50, active: 1, links: copy(sg.links || []) };
            if (sg.kind === 'faq_improve') {
                const f = (this.s.faqs || []).find(x => x.id === sg.faqid);
                if (f) {
                    row = Object.assign(copy(f), { problem: sg.title || f.problem, answer: sg.body,
                        links: sg.links && sg.links.length ? copy(sg.links) : copy(f.links) });
                }
            }
            this.faqDialog = { open: true, row: row, aiSugg: sg.id };
        },
        async saveFaq() {
            const r = this.faqDialog.row;
            const payload = {
                id: r.id || 0, areaid: r.areaid, categoryid: r.categoryid || 0, problem: r.problem, answer: r.answer,
                audience: r.audience, sortorder: parseInt(r.sortorder, 10) || 0, active: r.active ? 1 : 0,
                links: r.links.filter(l => (l.label || '').trim() || (l.url || '').trim()),
            };
            if (this.faqDialog.aiSugg) {
                try {
                    const res = await window.gmkSupportCall('local_grupomakro_support_ai_action', {
                        id: this.faqDialog.aiSugg, op: 'add', payload: JSON.stringify(payload),
                    });
                    if (this.$refs.assistant) this.$refs.assistant.setData(res);
                    this.faqDialog.open = false;
                    this.notify('Añadido');
                    await this.load();
                } catch (e) {
                    this.notify(e.message, 'error');
                }
                return;
            }
            const ok = await this.save('faq', payload);
            if (ok) this.faqDialog.open = false;
        },
        async removeFaq() {
            if (!(await this.confirm('¿Borrar este problema común? Se pierden sus contadores. Para ocultarlo sin perderlos, desactívalo.'))) return;
            const ok = await this.save('faq', { id: this.faqDialog.row.id }, 'delete');
            if (ok) this.faqDialog.open = false;
        },
        faqRender(text) {
            // Misma regla que faq::render() en el servidor (texto escapado).
            const esc = t => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const bold = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            let html = '';
            let list = '';
            let para = [];
            const flush = () => { if (para.length) { html += '<p>' + para.join('<br>') + '</p>'; para = []; } };
            const close = () => { if (list) { html += '</' + list + '>'; list = ''; } };
            String(text || '').trim().split(/\r?\n/).forEach(raw => {
                const line = raw.trim();
                let m;
                if (!line) { flush(); close(); return; }
                if ((m = line.match(/^\d+[.)]\s+(.*)$/))) { flush(); if (list !== 'ol') { close(); html += '<ol>'; list = 'ol'; } html += '<li>' + bold(m[1]) + '</li>'; return; }
                if ((m = line.match(/^[-•]\s+(.*)$/))) { flush(); if (list !== 'ul') { close(); html += '<ul>'; list = 'ul'; } html += '<li>' + bold(m[1]) + '</li>'; return; }
                close();
                para.push(bold(line));
            });
            flush();
            close();
            return html;
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
