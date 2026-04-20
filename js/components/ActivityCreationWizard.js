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
                            <v-col cols="12">
                                <v-text-field
                                    v-model="formData.duedate"
                                    label="Fecha de entrega"
                                    type="datetime-local"
                                    outlined
                                    dense
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

                        <!-- Tags Input -->
                        <v-combobox
                            ref="lessonTagInput"
                            v-model="formData.tags"
                            :search-input.sync="tagSearchInput"
                            :items="courseTags"
                            label="Etiqueta / Lección"
                            outlined
                            dense
                            hint="Seleccione o escriba el nombre de la lección"
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
                        {{ editMode ? 'Guardar Cambios' : 'Crear Actividad' }}
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
                timeopen: '',
                timeclose: '',
                attempts: 1,
                tags: '',
                visible: true,
                guest: false,
                forumtopic: '',
                forummessage: '',
                forumcreateinitial: true
            },
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
        if (this.isAssignment) {
            this.fetchGradeCategories();
        }
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
            this.$emit('close');
        },
        parseDatetimeLocalToTimestamp(value) {
            if (!value || typeof value !== 'string') {
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
            const normalized = this.normalizeLessonTagValue(
                value !== undefined ? value : this.formData.tags
            );
            if (normalized) {
                this.formData.tags = normalized;
                this.tagSearchInput = normalized;
                return normalized;
            }

            // In v-combobox, typed values can stay in search-input until blur/enter.
            const pending = this.normalizeLessonTagValue(this.tagSearchInput);
            this.formData.tags = pending;
            this.tagSearchInput = pending;
            return pending;
        },
        async saveActivity() {
            this.saving = true;
            try {
                const action = this.editMode
                    ? 'local_grupomakro_update_activity'
                    : 'local_grupomakro_create_express_activity';

                let response;

                const normalizedTag = this.normalizeLessonTagInput();
                const draftitemids = Array.from(new Set(
                    this.uploadedDrafts
                        .filter(function(d) { return !!d && !!d.draftitemid; })
                        .map(function(d) { return parseInt(d.draftitemid, 10) || 0; })
                        .filter(function(v) { return v > 0; })
                ));
                const duedate = this.parseDatetimeLocalToTimestamp(this.formData.duedate);
                const timeopen = this.parseDatetimeLocalToTimestamp(this.formData.timeopen);
                const timeclose = this.parseDatetimeLocalToTimestamp(this.formData.timeclose);
                const args = this.editMode ? {
                    cmid: this.editData.id,
                    name: this.formData.name,
                    intro: this.formData.intro || '',
                    tags: normalizedTag,
                    visible: this.formData.visible ? 1 : 0,
                    duedate: duedate,
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
                    tags: normalizedTag,
                    duedate: duedate,
                    timeopen: timeopen,
                    timeclose: timeclose,
                    guest: this.formData.guest,
                    forumtopic: this.isForum ? (this.formData.forumtopic || this.formData.name || '') : '',
                    forummessage: this.isForum ? (this.formData.forummessage || this.formData.intro || '') : '',
                    forumcreateinitial: this.isForum ? (this.formData.forumcreateinitial ? 1 : 0) : 0,
                    draftitemids: draftitemids
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
                    this.$emit('success');
                    this.close();
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
                    this.formData.tags = this.normalizeLessonTagValue(
                        (act.tags && act.tags.length > 0) ? act.tags[0] : ''
                    );
                    this.tagSearchInput = this.formData.tags;
                    this.formData.visible = act.visible;
                    this.formData.duedate = this.formatTimestampForDatetimeLocal(act.duedate);
                    this.formData.timeopen = this.formatTimestampForDatetimeLocal(act.timeopen);
                    this.formData.timeclose = this.formatTimestampForDatetimeLocal(act.timeclose);
                    this.formData.attempts = act.attempts || 1;

                    if (act.files && act.files.length > 0) {
                        this.existingFiles = act.files;
                    }

                }
            } catch (e) {
                console.error("Error loading details", e);
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
