/**
 * Activity Creation Wizard Component
 * Created for Redesigning Teacher Experience
 */

const ActivityCreationWizard = {
    props: {
        classId: { type: Number, required: true },
        activityType: { type: String, required: true }, // 'bbb', 'assignment', 'resource'
        customLabel: { type: String, default: '' },
        editMode: { type: Boolean, default: false },
        editData: { type: Object, default: null }
    },
    template: `
        <v-dialog v-model="visible" max-width="600px" persistent>
            <v-card class="rounded-lg">
                <v-card-title class="headline font-weight-bold" :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-4'">
                    {{ editMode ? 'Editar' : 'Nueva' }} {{ activityLabel }}
                    <v-spacer></v-spacer>
                    <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text class="pa-6">
                    <v-form ref="form" v-model="valid">
                        <v-text-field
                            v-model="formData.name"
                            label="Nombre de la actividad"
                            outlined
                            dense
                            required
                            :rules="[v => !!v || 'El nombre es obligatorio']"
                        ></v-text-field>

                        <v-textarea
                            v-model="formData.intro"
                            label="Descripción / Instrucciones"
                            outlined
                            rows="3"
                        ></v-textarea>

                        <v-row v-if="isAssignment">
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.allowsubmissionsfromdate"
                                    label="Disponible desde"
                                    type="datetime-local"
                                    outlined
                                    dense
                                    hint="Fecha desde la que los estudiantes pueden enviar"
                                    persistent-hint
                                    :rules="[v => !v || !formData.duedate || v <= formData.duedate || 'Debe ser anterior a la fecha de entrega']"
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.duedate"
                                    label="Fecha de entrega"
                                    type="datetime-local"
                                    outlined
                                    dense
                                    hint="Fecha límite para enviar"
                                    persistent-hint
                                ></v-text-field>
                            </v-col>
                        </v-row>

                        <v-row v-if="isQuiz">
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.timeopen"
                                    label="Abrir cuestionario"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.timeclose"
                                    label="Cerrar cuestionario"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                        </v-row>

                        <!-- Calificacion grupal (introducida en 20261001057) -->
                        <v-card
                            v-if="isAssignment || isQuiz"
                            outlined
                            class="mt-3 mb-3 pa-3"
                            :color="formData.enableGroupGrading ? 'blue lighten-5' : ''"
                        >
                            <div class="d-flex align-center">
                                <v-icon color="primary" class="mr-2">mdi-account-group</v-icon>
                                <v-switch
                                    v-model="formData.enableGroupGrading"
                                    :label="'Permitir calificación grupal'"
                                    hide-details
                                    dense
                                    color="primary"
                                    class="mt-0 pt-0"
                                ></v-switch>
                                <v-spacer></v-spacer>
                                <v-chip v-if="formData.enableGroupGrading" x-small color="primary" dark>
                                    Habilitada
                                </v-chip>
                            </div>
                            <div v-if="formData.enableGroupGrading" class="mt-2">
                                <v-row>
                                    <v-col cols="12" sm="6">
                                        <v-select
                                            v-model="formData.groupMode"
                                            :items="groupModeOptions"
                                            label="Modo de agrupación"
                                            outlined
                                            dense
                                            hide-details
                                        ></v-select>
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <v-text-field
                                            v-model.number="formData.groupMaxmembers"
                                            label="Tamaño máximo por grupo"
                                            type="number"
                                            min="2"
                                            outlined
                                            dense
                                            hide-details
                                        ></v-text-field>
                                    </v-col>
                                </v-row>
                                <div class="text-caption grey--text mt-2 d-flex align-center">
                                    <v-icon x-small class="mr-1">mdi-information-outline</v-icon>
                                    <span v-if="formData.groupMode === 'open'">
                                        Los estudiantes se unen solos a un grupo desde el LXP hasta llenar el cupo.
                                    </span>
                                    <span v-else>
                                        El docente arma los grupos manualmente desde la sección Actividades de esta clase, usando el botón <v-icon x-small color="indigo darken-2">mdi-account-group</v-icon> sobre la actividad.
                                    </span>
                                </div>
                                <div class="text-caption grey--text mt-1">
                                    <span v-if="editMode">
                                        Una vez guardada la actividad, abra la
                                        sección <b>Actividades</b> de esta clase
                                        y use el botón <v-icon x-small color="indigo darken-2">mdi-account-group</v-icon>
                                        sobre la actividad para gestionar los grupos.
                                    </span>
                                    <span v-else>
                                        Después de crear la actividad, use el botón
                                        <v-icon x-small color="indigo darken-2">mdi-account-group</v-icon>
                                        sobre la actividad en la sección <b>Actividades</b>
                                        para crear y asignar grupos.
                                    </span>
                                </div>

                                <!-- Panel admin de grupos: solo visible en modo
                                     edicion + la actividad debe ser assign o quiz
                                     + el flag de group grading debe estar activo.
                                     Es el ActivityGroupsPanel que antes estaba
                                     huerfano (registrado como Vue.component pero
                                     nunca montado en ningun template).

                                     :cmid se pasa con parseInt porque el
                                     ActivityGroupsPanel declara el prop como
                                     Number y el bind automatico de Vue 2 sobre
                                     valores numericos puede llegar como string
                                     (ver [Vue warn]: Invalid prop cmid).

                                     IMPORTANTE: el v-if usa editData.enableGroupGrading
                                     (pasado por el padre) en vez de formData.enableGroupGrading
                                     porque data() se reinicializa en cada re-mount
                                     y formData arrancaria en false, ocultando
                                     el panel en el primer render. fetchActivityDetails
                                     sigue corriendo y sincroniza formData para
                                     consistencia. -->
                                <activity-groups-panel
                                    v-if="editMode && editData && editData.id && (editData.enableGroupGrading || formData.enableGroupGrading)"
                                    :cmid="parseInt(editData.id, 10)"
                                    :modname="activityType"
                                    :activity-name="formData.name"
                                    class="mt-4"
                                ></activity-groups-panel>
                            </div>
                        </v-card>

                        <!-- Tags Input -->
                        <v-combobox
                            ref="lessonTagInput"
                            v-model="formData.tags"
                            :search-input.sync="tagSearchInput"
                            :items="courseTags"
                            label="Etiqueta / Lección"
                            multiple
                            small-chips
                            deletable-chips
                            outlined
                            dense
                            hint="Seleccione o escriba una o varias lecciones"
                            persistent-hint
                            clearable
                            @change="normalizeLessonTagInput"
                        ></v-combobox>

                        <div v-if="isBBB" class="pa-4 rounded-lg mb-4" :class="$vuetify.theme.dark ? 'blue-grey darken-4' : 'blue lighten-5'">
                            <v-icon small color="blue" class="mr-2">mdi-information-outline</v-icon>
                            <span class="text-caption blue--text" :class="$vuetify.theme.dark ? 'text--lighten-2' : ''">
                                Se configurará automáticamente con los parámetros de este grupo y horario.
                            </span>
                        </div>

                        <div v-if="isForum" class="pa-4 rounded-lg mb-4" :class="$vuetify.theme.dark ? 'deep-purple darken-4' : 'deep-purple lighten-5'">
                            <v-icon small color="deep-purple" class="mr-2">mdi-forum-outline</v-icon>
                            <span class="text-caption deep-purple--text" :class="$vuetify.theme.dark ? 'text--lighten-2' : ''">
                                Se creara un foro general y puedes publicar el primer tema ahora.
                            </span>
                        </div>

                        <v-row v-if="isForum">
                            <v-col cols="12">
                                <v-switch
                                    v-model="formData.forumcreateinitial"
                                    label="Crear tema inicial al publicar"
                                    color="deep-purple"
                                    hide-details
                                ></v-switch>
                            </v-col>
                            <v-col cols="12" v-if="formData.forumcreateinitial">
                                <v-text-field
                                    v-model="formData.forumtopic"
                                    label="Titulo del tema inicial"
                                    outlined
                                    dense
                                    :rules="[v => !formData.forumcreateinitial || !!(v && v.trim()) || 'El titulo es obligatorio']"
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" v-if="formData.forumcreateinitial">
                                <v-textarea
                                    v-model="formData.forummessage"
                                    label="Mensaje del tema inicial"
                                    outlined
                                    rows="3"
                                    :rules="[v => !formData.forumcreateinitial || !!(v && v.trim()) || 'El mensaje es obligatorio']"
                                ></v-textarea>
                            </v-col>
                        </v-row>
                        <v-switch
                            v-if="editMode"
                            v-model="formData.visible"
                            label="Visible para estudiantes"
                            color="success"
                            :disabled="loadingDetails"
                            :loading="loadingDetails"
                        ></v-switch>

                        <!-- Archivos adjuntos -->
                        <div v-if="supportsFiles" class="mt-3">
                            <div class="caption grey--text mb-2">Archivos del material</div>
                            <input
                                ref="resourceFileInput"
                                type="file"
                                multiple
                                style="display:none"
                                @change="onResourceFilesSelected"
                            />
                            <div v-if="editMode && existingFiles.length > 0" class="mb-2">
                                <div class="caption grey--text mb-1">Archivos actuales:</div>
                                <span v-for="f in existingFiles" :key="f.filename" class="d-inline-flex align-center mr-2 mb-1">
                                    <v-chip
                                        small
                                        :color="filesToDelete.indexOf(f.filename) !== -1 ? 'red lighten-4' : ''"
                                        :close="filesToDelete.indexOf(f.filename) === -1"
                                        @click:close="markFileForDelete(f.filename)"
                                    >
                                        <v-icon left x-small>mdi-file</v-icon>
                                        <a :href="f.url" target="_blank" class="text-decoration-none black--text">{{ f.filename }}</a>
                                    </v-chip>
                                    <v-btn
                                        v-if="filesToDelete.indexOf(f.filename) !== -1"
                                        x-small text color="red"
                                        @click="unmarkFileForDelete(f.filename)"
                                    >deshacer</v-btn>
                                </span>
                            </div>
                            <div v-if="resourceFiles.length > 0" class="mb-2">
                                <div class="caption grey--text mb-1">Archivos a subir:</div>
                                <v-chip
                                    v-for="(f, idx) in resourceFiles"
                                    :key="idx"
                                    small
                                    :close="uploadingIndex === null || uploadingIndex !== idx"
                                    @click:close="removeResourceFile(idx)"
                                    class="mr-1 mb-1"
                                    :color="uploadedDrafts[idx] ? 'green lighten-5' : ''"
                                >
                                    <v-icon left x-small>{{ uploadedDrafts[idx] ? 'mdi-check-circle' : 'mdi-upload' }}</v-icon>
                                    {{ f.name }}
                                    <v-progress-circular v-if="uploadingIndex === idx" indeterminate size="14" width="2" class="ml-1"></v-progress-circular>
                                </v-chip>
                            </div>
                            <v-btn small outlined color="primary" @click="$refs.resourceFileInput.click()" :disabled="uploadingIndex !== null">
                                <v-icon left small>mdi-paperclip</v-icon>
                                {{ editMode ? 'Subir archivo nuevo' : 'Adjuntar archivos' }}
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer></v-spacer>
                    <v-btn text @click="close">Cancelar</v-btn>
                    <v-btn color="primary" depressed :loading="saving" @click="saveActivity" :disabled="!valid || uploadingIndex !== null">
                        <v-icon left small>
                            {{ editMode
                                ? 'mdi-content-save'
                                : ((isAssignment || isQuiz) && formData.enableGroupGrading
                                    ? 'mdi-account-group'
                                    : 'mdi-plus') }}
                        </v-icon>
                        {{ editMode
                            ? 'Guardar Cambios'
                            : ((isAssignment || isQuiz) && formData.enableGroupGrading
                                ? 'Crear y gestionar grupos'
                                : 'Crear Actividad') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    data() {
        return {
            visible: true,
            valid: false,
            saving: false,
            formData: {
                name: '',
                intro: '',
                duedate: '',
                allowsubmissionsfromdate: '',
                timeopen: '',
                timeclose: '',
                attempts: 1,
                tags: [],
                visible: true,
                guest: false,
                forumtopic: '',
                forummessage: '',
                forumcreateinitial: true,
                // Calificacion grupal (introducida en 20261001057)
                enableGroupGrading: false,
                groupMode: 'open',
                groupMaxmembers: 5
            },
            loadingDetails: false,
            tagSearchInput: '',
            courseTags: [],
            resourceFiles: [],
            uploadedDrafts: [],    // parallel array: { draftitemid, filename } per resourceFiles entry
            uploadDraftItemId: 0,
            uploadingIndex: null,  // index of file currently being uploaded, or null
            existingFiles: [],
            filesToDelete: []
        };
    },
    mounted() {
        if (this.editMode && this.editData) {
            this.fetchActivityDetails(this.editData.id);
        }
        this.fetchCourseTags();
    },
    computed: {
        activityLabel() {
            if (this.customLabel) return this.customLabel;
            const labels = {
                bbb: 'Sesión Virtual',
                bigbluebuttonbn: 'Sesión Virtual',
                assignment: 'Tarea',
                assign: 'Tarea',
                resource: 'Material',
                quiz: 'Cuestionario',
                forum: 'Foro'
            };
            return labels[this.activityType] || 'Actividad';
        },
        isAssignment() {
            return this.activityType === 'assignment' || this.activityType === 'assign';
        },
        isQuiz() {
            return this.activityType === 'quiz';
        },
        isForum() {
            return this.activityType === 'forum';
        },
        isBBB() {
            return this.activityType === 'bbb' || this.activityType === 'bigbluebuttonbn';
        },
        isResource() {
            return this.activityType === 'resource';
        },
        supportsFiles() {
            return this.activityType === 'resource' ||
                   this.activityType === 'assignment' || this.activityType === 'assign' ||
                   this.activityType === 'quiz' ||
                   this.activityType === 'forum';
        },
        groupModeOptions() {
            return [
                { text: 'Abierto (los estudiantes se unen)', value: 'open' },
                { text: 'Fijo (el docente arma)',            value: 'fixed' }
            ];
        }
    },
    methods: {
        close() {
            this.resourceFiles = [];
            this.uploadedDrafts = [];
            this.uploadDraftItemId = 0;
            this.existingFiles = [];
            this.filesToDelete = [];
            this.uploadingIndex = null;
            this.tagSearchInput = '';
            // Reset de los campos de calificacion grupal (20261001057)
            this.formData.enableGroupGrading = false;
            this.formData.groupMode = 'open';
            this.formData.groupMaxmembers = 5;
            this.$emit('close');
        },
        parseDatetimeLocalToTimestamp(value) {
            // Handle empty, null, undefined, or non-string values
            if (!value || typeof value !== 'string' || value.length === 0) {
                return 0;
            }

            const parts = value.split('T');
            if (parts.length !== 2) {
                return 0;
            }

            const dateParts = parts[0].split('-').map(function(part) {
                return parseInt(part, 10);
            });
            const timeParts = parts[1].split(':').map(function(part) {
                return parseInt(part, 10);
            });

            if (dateParts.length !== 3 || dateParts.some(Number.isNaN)) {
                return 0;
            }

            const year = dateParts[0];
            const monthIndex = dateParts[1] - 1;
            const day = dateParts[2];
            const hour = Number.isNaN(timeParts[0]) ? 0 : timeParts[0];
            const minute = Number.isNaN(timeParts[1]) ? 0 : timeParts[1];
            const localDate = new Date(year, monthIndex, day, hour, minute, 0, 0);

            if (Number.isNaN(localDate.getTime())) {
                return 0;
            }

            return Math.floor(localDate.getTime() / 1000);
        },
        formatTimestampForDatetimeLocal(timestamp) {
            const numericTimestamp = parseInt(timestamp, 10);
            if (!numericTimestamp) {
                return '';
            }

            const localDate = new Date(numericTimestamp * 1000);
            if (Number.isNaN(localDate.getTime())) {
                return '';
            }

            const year = localDate.getFullYear();
            const month = String(localDate.getMonth() + 1).padStart(2, '0');
            const day = String(localDate.getDate()).padStart(2, '0');
            const hours = String(localDate.getHours()).padStart(2, '0');
            const minutes = String(localDate.getMinutes()).padStart(2, '0');

            return `${year}-${month}-${day}T${hours}:${minutes}`;
        },
        normalizeLessonTagValue(raw) {
            if (raw === null || raw === undefined) {
                return '';
            }
            let value = '';
            if (Array.isArray(raw)) {
                for (let i = 0; i < raw.length; i++) {
                    const normalizedItem = this.normalizeLessonTagValue(raw[i]);
                    if (normalizedItem) {
                        value = normalizedItem;
                        break;
                    }
                }
            } else if (typeof raw === 'object') {
                value = String(raw.value || raw.text || raw.title || raw.name || '').trim();
            } else {
                value = String(raw).trim();
            }

            value = value.replace(/\s+/g, ' ').trim();
            if (!value) {
                return '';
            }

            const numericOnly = value.match(/^(\d{1,3})$/);
            if (numericOnly) {
                return 'Leccion ' + numericOnly[1];
            }

            const lessonPattern = value.match(/^lecci(?:o|\u00f3)n?\s*[-:]*\s*(\d{1,3})$/i);
            if (lessonPattern) {
                return 'Leccion ' + lessonPattern[1];
            }

            return value;
        },
        normalizeLessonTagInput(value) {
            const raw = value !== undefined ? value : this.formData.tags;
            let tags = [];

            if (Array.isArray(raw)) {
                // v-combobox with multiple=true emits the full chip list as an
                // array. Normalize each entry, drop empties and de-duplicate
                // so the v-model stays an array (otherwise Vuetify renders each
                // character of the assigned string as a separate chip).
                const seen = {};
                for (let i = 0; i < raw.length; i++) {
                    const normalized = this.normalizeLessonTagValue(raw[i]);
                    if (normalized && !seen[normalized]) {
                        seen[normalized] = true;
                        tags.push(normalized);
                    }
                }
            } else if (raw) {
                // Backward-compat path for callers that still pass a single value.
                const normalized = this.normalizeLessonTagValue(raw);
                if (normalized) {
                    tags.push(normalized);
                }
            }

            // In v-combobox, typed values can stay in search-input until blur/enter.
            const pending = this.normalizeLessonTagValue(this.tagSearchInput);
            if (pending && tags.indexOf(pending) === -1) {
                tags.push(pending);
                this.tagSearchInput = '';
            }

            this.formData.tags = tags;
            return tags;
        },
        /**
         * Build the tag payload sent to the backend from this.formData.tags.
         * Supports both the legacy single-value flow (string) and the new
         * multi-value flow (array of strings) used by the v-combobox with
         * multiple=true. Returns a comma-separated string so the existing
         * backend parser (gmk_ajax_extract_tags_from_request) keeps working
         * unchanged. Empty/whitespace tags are filtered out.
         */
        buildTagsPayload() {
            const self = this;
            const raw = this.formData.tags;
            let list = [];
            if (Array.isArray(raw)) {
                list = raw.slice();
            } else if (raw !== undefined && raw !== null && raw !== '') {
                list = [raw];
            }

            // Flush any pending text in the v-combobox search input that the
            // user typed but did not commit with Enter yet.
            if (this.tagSearchInput && String(this.tagSearchInput).trim() !== '') {
                const pending = this.normalizeLessonTagValue(this.tagSearchInput);
                if (pending && list.indexOf(pending) === -1) {
                    list.push(pending);
                }
            }

            const normalized = list
                .map(function(t) { return self.normalizeLessonTagValue(t); })
                .filter(function(t) { return !!t; });

            // De-duplicate while preserving order.
            const seen = {};
            const unique = [];
            normalized.forEach(function(t) {
                if (!seen[t]) { seen[t] = true; unique.push(t); }
            });

            return unique.join(',');
        },
        async saveActivity() {
            this.saving = true;
            try {
                const action = this.editMode
                    ? 'local_grupomakro_update_activity'
                    : 'local_grupomakro_create_express_activity';

                let response;

                const tagPayload = this.buildTagsPayload();
                const draftitemids = Array.from(new Set(
                    this.uploadedDrafts
                        .filter(function(d) { return !!d && !!d.draftitemid; })
                        .map(function(d) { return parseInt(d.draftitemid, 10) || 0; })
                        .filter(function(v) { return v > 0; })
                ));
                const duedate = this.parseDatetimeLocalToTimestamp(this.formData.duedate) || 0;
                const allowsubmissionsfromdate = this.parseDatetimeLocalToTimestamp(this.formData.allowsubmissionsfromdate) || 0;
                const timeopen = this.parseDatetimeLocalToTimestamp(this.formData.timeopen) || 0;
                const timeclose = this.parseDatetimeLocalToTimestamp(this.formData.timeclose) || 0;
                const args = this.editMode ? {
                    cmid: this.editData.id,
                    name: this.formData.name,
                    intro: this.formData.intro || '',
                    tags: tagPayload,
                    visible: this.formData.visible ? 1 : 0,
                    duedate: duedate,
                    allowsubmissionsfromdate: allowsubmissionsfromdate,
                    timeopen: timeopen,
                    timeclose: timeclose,
                    attempts: this.formData.attempts,
                    delete_files: this.filesToDelete,
                    draftitemids: draftitemids
                } : {
                    classid: this.classId,
                    type: this.activityType,
                    name: this.formData.name,
                    intro: this.formData.intro || '',
                    tags: tagPayload,
                    duedate: duedate,
                    allowsubmissionsfromdate: allowsubmissionsfromdate,
                    timeopen: timeopen,
                    timeclose: timeclose,
                    guest: this.formData.guest,
                    forumtopic: this.isForum ? (this.formData.forumtopic || this.formData.name || '') : '',
                    forummessage: this.isForum ? (this.formData.forummessage || this.formData.intro || '') : '',
                    forumcreateinitial: this.isForum ? (this.formData.forumcreateinitial ? 1 : 0) : 0,
                    draftitemids: draftitemids,
                    // Calificacion grupal (introducida en 20261001057)
                    enableGroupGrading: (this.isAssignment || this.isQuiz) && this.formData.enableGroupGrading ? 1 : 0,
                    groupMode: (this.isAssignment || this.isQuiz) && this.formData.enableGroupGrading
                                ? (this.formData.groupMode || 'open') : 'open',
                    groupMaxmembers: (this.isAssignment || this.isQuiz) && this.formData.enableGroupGrading
                                ? Math.max(2, parseInt(this.formData.groupMaxmembers, 10) || 5) : 5
                };
                response = await axios.post(window.wsUrl, {
                    action: action,
                    args: args,
                    ...window.wsStaticParams
                });

                const topStatus = response && response.data ? response.data.status : 'error';
                const nestedStatus = response && response.data && response.data.data ? response.data.data.status : null;
                const finalSuccess = topStatus === 'success' && (nestedStatus === null || nestedStatus === 'success');

                if (finalSuccess) {
                    const newCmid = parseInt((response.data && (response.data.cmid
                        || (response.data.data && response.data.data.cmid))) || 0, 10);
                    // Confirmar con el backend que el flag esta activo. El
                    // create_express_activity devuelve groupgrading.enabled
                    // cuando el flag se creo. Si por algun motivo el backend
                    // no lo creo (p.ej. corrida vieja, race condition), no
                    // debemos seguir el flujo de "gestionar grupos" porque
                    // el panel saldria con el alert "actividad no fue creada
                    // con la opcion". Confirma ANTES de emitir.
                    const groupgrading = (response.data && response.data.data && response.data.data.groupgrading)
                        || (response.data && response.data.groupgrading)
                        || null;
                    const backendEnabled = !!(groupgrading && (groupgrading.enabled === 1 || groupgrading.enabled === true));
                    const createdWithGroups = (this.isAssignment || this.isQuiz)
                        && (this.formData.enableGroupGrading || backendEnabled)
                        && !this.editMode
                        && newCmid > 0
                        && backendEnabled;

                    if (createdWithGroups) {
                        // Forzar que el flag este prendido en el form ANTES
                        // de emitir. Asi cuando el padre re-monte el wizard
                        // (por el cambio de :key), el v-if del panel evalua
                        // a true desde el primer render y el panel aparece
                        // sin parpadeo. fetchActivityDetails() confirmara
                        // despues leyendo el flag de la BD.
                        this.formData.enableGroupGrading = true;

                        // Workflow especial (20261001080): la actividad se creo
                        // con calificacion grupal habilitada y el docente no
                        // estaba editando. En vez de cerrar el wizard,
                        // pedimos al padre que lo reabra en modo edicion
                        // de la actividad recien creada. Asi el panel de
                        // grupos se monta automaticamente, sin obligar al
                        // docente a cerrar, reabrir, scrollear hasta el
                        // switch y recien ahi ver el panel.
                        this.$emit('created-with-groups', {
                            cmid: newCmid,
                            modname: this.activityType,
                            name: this.formData.name
                        });
                    } else {
                        this.$emit('success');
                        this.close();
                    }
                } else {
                    const backendMessage =
                        (response && response.data && response.data.message) ||
                        (response && response.data && response.data.data && response.data.data.message) ||
                        'Error desconocido';
                    alert('Error saving activity: ' + backendMessage);
                }
            } catch (error) {
                console.error('Error saving activity:', error);
                alert('Error de red al guardar actividad');
            } finally {
                this.saving = false;
            }
        },
        async fetchActivityDetails(cmid) {
            this.loadingDetails = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_activity_details',
                    args: { cmid: cmid },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    const act = response.data.activity;
                    this.formData.name = act.name;
                    this.formData.intro = this.stripHtml(act.intro);
                    // Keep ALL existing tags so multi-lesson associations
                    // survive an edit. Previously only the first tag was
                    // loaded into the form, which silently dropped any
                    // additional lesson associations on save.
                    const allTags = (act.tags && Array.isArray(act.tags) && act.tags.length > 0)
                        ? act.tags.map(t => this.normalizeLessonTagValue(t)).filter(Boolean)
                        : [];
                    this.formData.tags = allTags;
                    this.tagSearchInput = allTags[0] || '';
                    // Use visibleold (intended state) if available; fall back to visible.
                    // This avoids showing visible=false when the module was hidden by section cascade.
                    this.formData.visible = act.visibleold != null ? !!act.visibleold : !!act.visible;
                    this.formData.duedate = this.formatTimestampForDatetimeLocal(act.duedate);
                    this.formData.allowsubmissionsfromdate = this.formatTimestampForDatetimeLocal(act.allowsubmissionsfromdate);
                    this.formData.timeopen = this.formatTimestampForDatetimeLocal(act.timeopen);
                    this.formData.timeclose = this.formatTimestampForDatetimeLocal(act.timeclose);
                    this.formData.attempts = act.attempts || 1;

                    // Calificacion grupal (20261001080). Si la respuesta
                    // del backend trae los flags, los aplicamos al form.
                    // Sin esto, al re-montar el wizard en modo edicion
                    // (caso "Crear y gestionar grupos"), el formData
                    // arranca con enableGroupGrading=false porque data()
                    // se reinicializa al re-mount, y el <activity-groups-panel>
                    // no se renderiza (su v-if requiere enableGroupGrading=1).
                    if (act.enableGroupGrading === true || act.enableGroupGrading === 1) {
                        this.formData.enableGroupGrading = true;
                        this.formData.groupMode = (act.groupMode === 'fixed') ? 'fixed' : 'open';
                        const m = parseInt(act.groupMaxmembers, 10);
                        this.formData.groupMaxmembers = (m > 0) ? m : 5;
                    }

                    if (act.files && act.files.length > 0) {
                        this.existingFiles = act.files;
                    }
                }
            } catch (e) {
                console.error("Error loading details", e);
            } finally {
                this.loadingDetails = false;
            }
        },
        async fetchCourseTags() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_course_tags',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    const normalized = Array.isArray(response.data.tags)
                        ? response.data.tags.map(t => this.normalizeLessonTagValue(t)).filter(Boolean)
                        : [];
                    this.courseTags = Array.from(new Set(normalized));
                }
            } catch (error) {
                console.error('Error fetching tags:', error);
            }
        },
        stripHtml(html) {
            if (!html) return '';
            const tmp = document.createElement("DIV");
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || "";
        },
        async onResourceFilesSelected(event) {
            var selected = Array.from(event.target.files);
            event.target.value = '';
            var maxSize = 20 * 1024 * 1024; // 20MB
            for (var i = 0; i < selected.length; i++) {
                var f = selected[i];
                if (f.size > maxSize) {
                    alert('El archivo "' + f.name + '" pesa ' + (f.size / 1024 / 1024).toFixed(1) + ' MB y supera el límite de 20 MB.');
                    continue;
                }
                var idx = this.resourceFiles.length;
                this.resourceFiles.push(f);
                this.$set(this.uploadedDrafts, idx, null); // placeholder mientras sube
                this.uploadingIndex = idx;
                try {
                    var fd = new FormData();
                    fd.append('action', 'local_grupomakro_upload_draft_file');
                    fd.append('sesskey', window.wsStaticParams.sesskey);
                    if (this.uploadDraftItemId > 0) {
                        fd.append('draftitemid', String(this.uploadDraftItemId));
                    }
                    fd.append('file', f, f.name);
                    var resp = await axios.post(window.wsUrl, fd);
                    if (resp.data.status === 'success') {
                        this.uploadDraftItemId = parseInt(resp.data.draftitemid, 10) || this.uploadDraftItemId;
                        this.$set(this.uploadedDrafts, idx, { draftitemid: resp.data.draftitemid, filename: resp.data.filename });
                    } else {
                        alert('Error subiendo "' + f.name + '": ' + (resp.data.message || 'Error desconocido'));
                        this.resourceFiles.splice(idx, 1);
                        this.uploadedDrafts.splice(idx, 1);
                    }
                } catch (e) {
                    alert('Error de red subiendo "' + f.name + '": ' + e.message);
                    this.resourceFiles.splice(idx, 1);
                    this.uploadedDrafts.splice(idx, 1);
                } finally {
                    this.uploadingIndex = null;
                }
            }
        },
        removeResourceFile(idx) {
            this.resourceFiles.splice(idx, 1);
            this.uploadedDrafts.splice(idx, 1);
            if (this.resourceFiles.length === 0) {
                this.uploadDraftItemId = 0;
            }
        },
        markFileForDelete(filename) {
            if (this.filesToDelete.indexOf(filename) === -1) {
                this.filesToDelete.push(filename);
            }
        },
        unmarkFileForDelete(filename) {
            var idx = this.filesToDelete.indexOf(filename);
            if (idx !== -1) {
                this.filesToDelete.splice(idx, 1);
            }
        }
    }
};

Vue.component('activity-creation-wizard', ActivityCreationWizard);
window.ActivityCreationWizard = ActivityCreationWizard;
