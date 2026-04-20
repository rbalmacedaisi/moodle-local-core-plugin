/**
 * Manage Class Component
 * Created for Redesigning Teacher Experience
 */

const ManageClass = {
    props: {
        classId: {
            type: [Number, String],
            required: true
        },
        config: {
            type: Object,
            default: () => ({})
        },
        initialTab: {
            type: String,
            default: ''
        }
    },
    template: `
        <v-container fluid class="pa-4 pt-0 h-100">
            <!-- Header section -->
            <v-row class="py-4 border-bottom sticky-header" :class="$vuetify.theme.dark ? '' : 'white'">
                <v-col cols="12" class="d-flex align-center">
                    <v-btn icon @click="$emit('back')" class="mr-2">
                        <v-icon>mdi-arrow-left</v-icon>
                    </v-btn>
                    <div>
                        <div class="text-caption grey--text">{{ classDetails.course_shortname }}</div>
                        <h1 class="text-h5 font-weight-bold mb-0">{{ classDetails.name }}</h1>
                    </div>
                    <v-spacer></v-spacer>
                    <v-chip dark :color="classDetails.type === 1 ? 'blue' : 'green'">
                        {{ classDetails.typelabel || (classDetails.type === 1 ? 'Virtual' : 'Presencial') }}
                    </v-chip>
                </v-col>
            </v-row>

            <!-- Navigation Tabs -->
            <v-row>
                <v-col cols="12" class="py-0">
                    <v-tabs v-model="activeTab" :background-color="$vuetify.theme.dark ? '' : 'white'" color="primary" grow>
                        <v-tab v-for="tab in tabs" :key="tab.id" :href="'#' + tab.id">
                            <v-icon left small v-if="tab.icon">{{ tab.icon }}</v-icon>
                            {{ tab.name }}
                        </v-tab>
                    </v-tabs>
                </v-col>
            </v-row>

            <!-- Tab Content -->
            <v-row class="mt-4">
                <v-col cols="12">
                    <v-tabs-items v-model="activeTab" class="transparent">
                        
                        <!-- Timeline Tab -->
                        <v-tab-item value="timeline">
                            <v-card flat class="transparent">
                                <v-timeline dense align-top class="mx-4">
                                    <v-timeline-item
                                        v-for="(session, index) in timeline"
                                        :key="session.id"
                                        color="blue"
                                        small
                                        fill-dot
                                    >
                                        <v-card class="rounded-xl shadow-sm elevation-1 mb-2">
                                            <v-card-title class="text-subtitle-1 font-weight-bold pb-1">
                                                Sesi&oacute;n {{ index + 1 }}
                                                <v-spacer></v-spacer>
                                                <v-chip x-small outlined color="blue">
                                                    {{ ((classDetails.typelabel || 'Sesi\\u00f3n') + '').toUpperCase() }}
                                                </v-chip>
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex align-center grey--text text--darken-2">
                                                    <v-icon small class="mr-2">mdi-calendar-clock</v-icon>
                                                    <span class="font-weight-medium">{{ formatDate(session.startdate) }}</span>
                                                </div>
                                                <div class="mt-2 caption blue--text text--darken-1">
                                                    <v-icon x-small color="blue darken-1" class="mr-1">mdi-information</v-icon>
                                                    El acceso se habilita a la hora del evento.
                                                </div>
                                                <!-- Guest Link -->
                                                <div v-if="session.guest_url" class="mt-2 text-caption">
                                                    <v-btn x-small text color="blue darken-2" class="px-0" @click="copyGuestLink(session.guest_url)">
                                                        <v-icon x-small left>mdi-link-variant</v-icon> Copiar enlace de invitado
                                                    </v-btn>
                                                </div>
                                            </v-card-text>
                                            <v-divider></v-divider>
                                            <v-divider></v-divider>
                                            <v-card-actions :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-5'">
                                                <v-btn
                                                    small
                                                    depressed
                                                    color="blue"
                                                    class="rounded-lg px-4 white--text"
                                                    @click="enterSession(session)"
                                                    :disabled="!isSessionActive(session) || enteringSessionId === session.id"
                                                    :loading="enteringSessionId === session.id"
                                                >
                                                    <v-icon left x-small>mdi-video</v-icon>
                                                    Entrar
                                                </v-btn>
                                                
                                                <v-spacer></v-spacer>
                                                
                                                <!-- Attendance QR Button -->
                                                <v-btn
                                                    v-if="session.attendance && session.attendance.has_qr"
                                                    small
                                                    depressed
                                                    color="teal darken-1"
                                                    class="rounded-lg white--text"
                                                    @click="showQR(session)"
                                                    :disabled="!isSessionActive(session)"
                                                >
                                                    <v-icon left small>mdi-qrcode</v-icon> QR
                                                </v-btn>

                                            </v-card-actions>
                                        </v-card>
                                    </v-timeline-item>
                                </v-timeline>
                                <div v-if="loadingTimeline" class="text-center pa-4">
                                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                    <div class="caption grey--text mt-2">Cargando sesiones...</div>
                                </div>
                                <v-alert v-if="!loadingTimeline && timeline.length === 0" type="info" text class="ma-4 rounded-xl">
                                    No hay sesiones programadas para esta clase.
                                </v-alert>
                            </v-card>
                        </v-tab-item>


                        
                        <!-- Roster Tab -->
                        <v-tab-item value="roster">
                            <teacher-student-table v-if="loadedTabs.roster" :class-id="classId"></teacher-student-table>
                        </v-tab-item>

                        <!-- COMPLETED: Grading Tab -->
                        <v-tab-item value="tasks">
                            <pending-grading-view 
                                v-if="loadedTabs.tasks"
                                :class-id="classId" 
                                :class-name="classDetails.name"
                            ></pending-grading-view>
                        </v-tab-item>

                        <!-- Grades Tab -->
                        <v-tab-item value="grades">
                            <grades-grid v-if="loadedTabs.grades" :class-id="classId"></grades-grid>
                        </v-tab-item>

                        <!-- Activities Tab -->
                        <v-tab-item value="content">
                             <v-card v-if="loadedTabs.content" flat class="transparent pa-4">
                                <v-expansion-panels multiple hover>
                                    <v-expansion-panel v-for="(group, name) in groupedActivities" :key="name" class="mb-2 rounded-lg transparent-panel">
                                        <v-expansion-panel-header :class="$vuetify.theme.dark ? 'grey darken-3' : 'blue-grey lighten-5'">
                                            <div class="d-flex align-center">
                                                <v-icon left :color="name === 'General' ? 'blue-grey' : 'primary'">{{ name === 'General' ? 'mdi-folder-outline' : 'mdi-tag' }}</v-icon>
                                                <span class="font-weight-bold text-subtitle-1">{{ name }}</span>
                                                <v-chip x-small class="ml-2" :color="$vuetify.theme.dark ? 'grey darken-1' : 'white'">{{ group.length }}</v-chip>
                                            </div>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content :class="$vuetify.theme.dark ? '' : 'white'">
                                            <v-list two-line>
                                                <template v-for="(activity, i) in group">
                                                    <v-list-item :key="activity.id">
                                                        <v-list-item-avatar>
                                                            <v-img :src="activity.modicon"></v-img>
                                                        </v-list-item-avatar>
                                                        <v-list-item-content>
                                                            <v-list-item-title class="font-weight-medium">{{ activity.name }}</v-list-item-title>
                                                            <v-list-item-subtitle class="text-caption grey--text">{{ activity.modname }}</v-list-item-subtitle>
                                                        </v-list-item-content>
                                                        <v-list-item-action class="d-flex flex-row">
                                                            <v-tooltip bottom v-if="activity.modname === 'quiz'">
                                                                <template v-slot:activator="{ on, attrs }">
                                                                    <v-btn icon small color="primary" class="mr-2" @click.stop="openQuizQuestions(activity)" v-bind="attrs" v-on="on">
                                                                        <v-icon>mdi-format-list-checks</v-icon>
                                                                    </v-btn>
                                                                </template>
                                                                <span>Gestionar Preguntas</span>
                                                            </v-tooltip>
                                                            <v-tooltip bottom v-if="activity.modname === 'forum'">
                                                                <template v-slot:activator="{ on, attrs }">
                                                                    <v-btn icon small color="indigo" class="mr-2" @click.stop="openForumActivity(activity)" v-bind="attrs" v-on="on">
                                                                        <v-icon>mdi-forum-outline</v-icon>
                                                                    </v-btn>
                                                                </template>
                                                                <span>Gestionar foro</span>
                                                            </v-tooltip>
                                                            <v-tooltip bottom v-if="canDeleteActivity(activity)">
                                                                <template v-slot:activator="{ on, attrs }">
                                                                    <v-btn
                                                                        icon
                                                                        small
                                                                        color="error"
                                                                        class="mr-2"
                                                                        :loading="!!activity._deleting"
                                                                        :disabled="!!activity._deleting"
                                                                        @click.stop="deleteActivity(activity)"
                                                                        v-bind="attrs"
                                                                        v-on="on"
                                                                    >
                                                                        <v-icon>mdi-delete-outline</v-icon>
                                                                    </v-btn>
                                                                </template>
                                                                <span>Eliminar actividad</span>
                                                            </v-tooltip>
                                                            <v-btn icon small @click.stop="openEditActivity(activity)"><v-icon color="grey lighten-1">mdi-pencil</v-icon></v-btn>
                                                        </v-list-item-action>
                                                    </v-list-item>
                                                    <v-divider v-if="i < group.length - 1" :key="'div-' + i" inset></v-divider>
                                                </template>
                                            </v-list>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                                <v-alert v-if="Object.keys(groupedActivities).length === 0" type="info" text class="ma-4 rounded-xl">
                                    No hay actividades creadas a&uacute;n. Usa el bot&oacute;n + para a&ntilde;adir una.
                                </v-alert>
                             </v-card>
                        </v-tab-item>

                        <!-- Avisos Tab -->
                        <v-tab-item value="notices">
                            <v-card v-if="loadedTabs.notices" flat class="transparent pa-4">

                                <!-- New Notice Form (expandable) -->
                                <v-expand-transition>
                                    <v-card v-if="showNoticeForm" class="mb-4 rounded-xl elevation-2">
                                        <v-card-title class="text-subtitle-1 font-weight-bold">
                                            <v-icon left color="amber darken-2">mdi-bullhorn</v-icon>
                                            Nuevo Aviso
                                        </v-card-title>
                                        <v-card-text>
                                            <v-text-field
                                                v-model="newNoticeSubject"
                                                label="Asunto"
                                                outlined dense
                                                :disabled="postingNotice"
                                            ></v-text-field>
                                            <v-textarea
                                                v-model="newNoticeMessage"
                                                label="Mensaje"
                                                outlined dense rows="4"
                                                :disabled="postingNotice"
                                            ></v-textarea>

                                            <!-- File attachments -->
                                            <div class="mt-2">
                                                <div class="caption grey--text mb-1">Adjuntos (opcional)</div>
                                                <input
                                                    ref="noticeFileInput"
                                                    type="file"
                                                    multiple
                                                    style="display:none"
                                                    @change="onNoticeFilesSelected"
                                                />
                                                <div class="d-flex align-center flex-wrap gap-2">
                                                    <v-btn small outlined color="grey" :disabled="postingNotice" @click="$refs.noticeFileInput.click()">
                                                        <v-icon left small>mdi-paperclip</v-icon> Adjuntar archivos
                                                    </v-btn>
                                                    <v-chip
                                                        v-for="(f, idx) in noticeFiles" :key="idx"
                                                        small close
                                                        @click:close="removeNoticeFile(idx)"
                                                        class="ml-1"
                                                    >
                                                        <v-icon left x-small>mdi-file</v-icon>{{ f.name }}
                                                    </v-chip>
                                                </div>
                                            </div>

                                            <v-alert v-if="noticeError" type="error" dense text class="mt-3">{{ noticeError }}</v-alert>
                                        </v-card-text>
                                        <v-card-actions>
                                            <v-btn text @click="showNoticeForm = false; noticeError = ''; noticeFiles = []">Cancelar</v-btn>
                                            <v-spacer></v-spacer>
                                            <v-btn color="amber darken-2" dark depressed
                                                :loading="postingNotice"
                                                :disabled="!newNoticeSubject.trim() || !newNoticeMessage.trim()"
                                                @click="postNotice"
                                            >
                                                <v-icon left>mdi-send</v-icon> Publicar
                                            </v-btn>
                                        </v-card-actions>
                                    </v-card>
                                </v-expand-transition>

                                <!-- Toolbar -->
                                <div class="d-flex align-center mb-3">
                                    <span class="text-subtitle-2 grey--text">{{ notices.length }} aviso(s) publicado(s)</span>
                                    <v-spacer></v-spacer>
                                    <v-btn color="amber darken-2" dark small depressed
                                        v-if="!showNoticeForm"
                                        @click="showNoticeForm = true; newNoticeSubject = ''; newNoticeMessage = ''; noticeFiles = []"
                                    >
                                        <v-icon left small>mdi-plus</v-icon> Nuevo Aviso
                                    </v-btn>
                                </div>

                                <!-- Loading -->
                                <div v-if="loadingNotices" class="text-center pa-4">
                                    <v-progress-circular indeterminate color="amber"></v-progress-circular>
                                </div>

                                <!-- No forum found -->
                                <v-alert v-else-if="!noticesForum" type="warning" text class="rounded-xl">
                                    No se encontr&oacute; el foro de avisos para este curso.
                                </v-alert>

                                <!-- Empty state -->
                                <v-alert v-else-if="notices.length === 0 && !loadingNotices" type="info" text class="rounded-xl">
                                    No hay avisos publicados a&uacute;n. Usa "Nuevo Aviso" para comunicarte con tus estudiantes.
                                </v-alert>

                                <!-- Notice Cards -->
                                <v-card
                                    v-for="notice in notices" :key="notice.id"
                                    class="mb-3 rounded-xl elevation-1"
                                >
                                    <v-card-title class="text-subtitle-1 font-weight-bold pb-1">
                                        <v-icon left small color="amber darken-2">mdi-bullhorn</v-icon>
                                        {{ notice.subject }}
                                        <v-spacer></v-spacer>
                                        <v-btn icon small color="red lighten-1" :loading="notice._deleting" @click="deleteNotice(notice)">
                                            <v-icon small>mdi-delete</v-icon>
                                        </v-btn>
                                    </v-card-title>
                                    <v-card-text>
                                        <div class="body-2" v-html="notice.message"></div>
                                        <!-- Attachments -->
                                        <div v-if="notice.attachments && notice.attachments.length > 0" class="mt-2">
                                            <div class="caption grey--text mb-1">Adjuntos:</div>
                                            <div v-for="att in notice.attachments" :key="att.filename" class="d-inline-block mr-2 mb-1">
                                                <v-btn x-small outlined color="primary" :href="att.url" target="_blank">
                                                    <v-icon left x-small>mdi-download</v-icon>{{ att.filename }}
                                                </v-btn>
                                            </div>
                                        </div>
                                        <div class="mt-2 caption grey--text">
                                            {{ notice.author }} ? {{ formatNoticeDate(notice.timemodified) }}
                                        </div>
                                    </v-card-text>
                                </v-card>

                            </v-card>
                        </v-tab-item>

                    </v-tabs-items>
                </v-col>
            </v-row>

            <!-- Floating Action Button for adding activities -->
            <v-speed-dial v-model="fab" bottom right fixed direction="top" transition="slide-y-reverse-transition">
                <template v-slot:activator>
                    <v-btn v-model="fab" color="primary" dark fab large>
                        <v-icon v-if="fab">mdi-close</v-icon>
                        <v-icon v-else>mdi-plus</v-icon>
                    </v-btn>
                </template>

                <!-- New Options -->
                <v-tooltip left>
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn fab dark small color="deep-purple" @click="addActivity('quiz')" v-bind="attrs" v-on="on">
                            <v-icon>mdi-checkbox-marked-circle-outline</v-icon>
                        </v-btn>
                    </template>
                    <span>Cuestionario</span>
                </v-tooltip>

                <v-tooltip left>
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn fab dark small color="teal" @click="addActivity('forum')" v-bind="attrs" v-on="on">
                            <v-icon>mdi-forum</v-icon>
                        </v-btn>
                    </template>
                    <span>Foro</span>
                </v-tooltip>

                <v-tooltip left>
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn fab dark small color="green" @click="addActivity('assignment')" v-bind="attrs" v-on="on">
                            <v-icon>mdi-file-document-edit</v-icon>
                        </v-btn>
                    </template>
                    <span>Tarea / Asignación</span>
                </v-tooltip>

                <v-tooltip left>
                     <template v-slot:activator="{ on, attrs }">
                        <v-btn fab dark small color="orange" @click="addActivity('resource')" v-bind="attrs" v-on="on">
                            <v-icon>mdi-file-download</v-icon>
                        </v-btn>
                    </template>
                    <span>Material / Recurso</span>
                </v-tooltip>

                <v-tooltip left>
                     <template v-slot:activator="{ on, attrs }">
                        <v-btn fab dark small color="grey darken-2" @click="openActivitySelector" v-bind="attrs" v-on="on">
                            <v-icon>mdi-dots-horizontal</v-icon>
                        </v-btn>
                    </template>
                    <span>Otras Actividades</span>
                </v-tooltip>

            </v-speed-dial>
            
            <!-- Generic Activity Selector Dialog -->
            <v-dialog v-model="showActivitySelector" max-width="500px">
                <v-card class="rounded-lg">
                    <v-card-title class="headline" :class="$vuetify.theme.dark ? '' : 'grey lighten-5'">
                        Seleccionar Actividad
                        <v-spacer></v-spacer>
                        <v-btn icon @click="showActivitySelector = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text class="pa-0">
                        <v-list v-if="!isLoadingModules">
                            <v-list-item v-for="module in availableModules" :key="module.name" @click="selectModule(module)">
                                <v-list-item-content>
                                    <v-list-item-title class="font-weight-medium">{{ module.label }}</v-list-item-title>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-icon color="grey lighten-1">mdi-chevron-right</v-icon>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                        <div v-else class="text-center pa-4">
                             <v-progress-circular indeterminate color="primary"></v-progress-circular>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
            
            <activity-creation-wizard 
                v-if="showActivityWizard" 
                :key="'activity-wizard-' + activityWizardKey"
                :class-id="parseInt(classId)" 
                :activity-type="newActivityType"
                :custom-label="customActivityLabel"
                :edit-mode="isEditing"
                :edit-data="editActivityData"
                @close="closeActivityWizard"
                @success="onActivityCreated"
            ></activity-creation-wizard>

            <quiz-creation-wizard
                v-if="showQuizWizard"
                :visible="showQuizWizard"
                :class-id="parseInt(classId)"
                @close="showQuizWizard = false"
                @success="onActivityCreated"
            ></quiz-creation-wizard>

            <v-snackbar v-model="snackbar" :timeout="3000" color="success">
                {{ snackbarText }}
                <template v-slot:action="{ attrs }">
                    <v-btn text v-bind="attrs" @click="snackbar = false">Cerrar</v-btn>
                </template>
            </v-snackbar>

            <!-- QR Dialog -->
            <v-dialog v-model="qrDialog" max-width="500px" persistent>
                <v-card v-if="currentQR" class="text-center pa-4">
                    <v-card-title class="justify-center text-h5">
                        C&oacute;digo de Asistencia
                    </v-card-title>
                    <v-card-text>
                        <div class="d-flex justify-center my-4">
                            <div style="background: white; padding: 12px; border-radius: 8px; display: inline-block;">
                                <div v-html="currentQR.html"></div>
                            </div>
                        </div>
                        <div class="text-h4 font-weight-bold primary--text" v-if="currentQR.password">
                            {{ currentQR.password }}
                        </div>
                        <div class="caption mt-2" v-if="currentQR.rotate">
                            El c&oacute;digo rota autom&aacute;ticamente.
                            <div class="mt-2" v-if="qrSecondsLeft > 0">
                                <v-progress-circular
                                    :rotate="-90"
                                    :size="40"
                                    :width="4"
                                    :value="(qrSecondsLeft / qrTotalSeconds) * 100"
                                    color="primary"
                                >
                                    {{ qrSecondsLeft }}
                                </v-progress-circular>
                                <div class="caption mt-1">Actualizando en {{ qrSecondsLeft }}s</div>
                            </div>
                        </div>
                    </v-card-text>
                    <v-card-actions class="justify-center">
                        <v-btn color="primary" text @click="closeQRDialog">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
                <v-card v-else class="text-center pa-5">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                </v-card>
            </v-dialog>

            <v-dialog v-model="forumManagerDialog" max-width="1200px" persistent>
                <v-card class="rounded-lg">
                    <v-card-title class="headline" :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-4'">
                        Gestion de foro
                        <span class="ml-2 text-subtitle-2 grey--text" v-if="forumManagerActivity && forumManagerActivity.name">
                            {{ forumManagerActivity.name }}
                        </span>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="closeForumManagerDialog"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>

                    <v-card-text class="pa-0">
                        <v-container fluid class="pa-4">
                            <v-alert v-if="forumManagerError" type="error" text class="mb-3">
                                {{ forumManagerError }}
                            </v-alert>

                            <v-row>
                                <v-col cols="12" md="4" class="pr-md-2">
                                    <v-btn small color="indigo" dark depressed class="mb-2" @click="forumManagerShowNewTopic = !forumManagerShowNewTopic">
                                        <v-icon left small>mdi-plus</v-icon>
                                        Nuevo tema
                                    </v-btn>

                                    <v-expand-transition>
                                        <v-card v-if="forumManagerShowNewTopic" outlined class="mb-3">
                                            <v-card-text>
                                                <v-text-field
                                                    v-model="forumManagerNewSubject"
                                                    label="Titulo"
                                                    outlined
                                                    dense
                                                    :disabled="forumManagerPostingDiscussion"
                                                ></v-text-field>
                                                <v-textarea
                                                    v-model="forumManagerNewMessage"
                                                    label="Mensaje"
                                                    outlined
                                                    rows="4"
                                                    :disabled="forumManagerPostingDiscussion"
                                                ></v-textarea>
                                                <v-btn
                                                    color="indigo"
                                                    dark
                                                    small
                                                    depressed
                                                    :loading="forumManagerPostingDiscussion"
                                                    :disabled="!forumManagerNewSubject.trim() || !forumManagerNewMessage.trim()"
                                                    @click="createForumDiscussion"
                                                >
                                                    Publicar tema
                                                </v-btn>
                                            </v-card-text>
                                        </v-card>
                                    </v-expand-transition>

                                    <v-card outlined>
                                        <v-list dense class="py-0" style="max-height: 55vh; overflow:auto;">
                                            <v-list-item
                                                v-for="disc in forumDiscussions"
                                                :key="disc.id"
                                                @click="selectForumDiscussion(disc)"
                                                :class="(selectedForumDiscussionId === disc.id) ? 'blue lighten-5' : ''"
                                            >
                                                <v-list-item-content>
                                                    <v-list-item-title class="text-body-2 font-weight-bold">{{ disc.subject }}</v-list-item-title>
                                                    <v-list-item-subtitle class="caption">{{ disc.author }} - {{ formatForumDate(disc.timemodified) }}</v-list-item-subtitle>
                                                    <v-list-item-subtitle class="caption grey--text text--darken-1">{{ disc.preview }}</v-list-item-subtitle>
                                                </v-list-item-content>
                                                <v-list-item-action>
                                                    <v-chip x-small>{{ disc.replies }}</v-chip>
                                                </v-list-item-action>
                                            </v-list-item>
                                            <v-list-item v-if="forumDiscussions.length === 0 && !forumManagerLoading">
                                                <v-list-item-content>
                                                    <v-list-item-title class="caption grey--text">No hay temas en este foro.</v-list-item-title>
                                                </v-list-item-content>
                                            </v-list-item>
                                        </v-list>
                                    </v-card>
                                </v-col>

                                <v-col cols="12" md="8" class="pl-md-2">
                                    <v-card outlined class="mb-3">
                                        <v-card-title class="text-subtitle-1 font-weight-bold">
                                            {{ selectedForumDiscussion ? selectedForumDiscussion.subject : 'Selecciona un tema' }}
                                        </v-card-title>
                                        <v-divider></v-divider>
                                        <v-card-text style="max-height: 48vh; overflow:auto;">
                                            <div v-if="forumManagerLoading" class="text-center py-6">
                                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                            </div>
                                            <div v-else-if="forumPosts.length === 0" class="caption grey--text">
                                                No hay comentarios para este tema.
                                            </div>
                                            <v-card v-for="post in forumPosts" :key="post.id" class="mb-2" outlined>
                                                <v-card-text>
                                                    <div class="d-flex align-center mb-2">
                                                        <strong class="mr-2">{{ post.author }}</strong>
                                                        <span class="caption grey--text">{{ formatForumDate(post.created) }}</span>
                                                    </div>
                                                    <div v-html="post.message"></div>
                                                </v-card-text>
                                            </v-card>
                                        </v-card-text>
                                    </v-card>

                                    <v-card outlined>
                                        <v-card-text>
                                            <v-textarea
                                                v-model="forumManagerReplyMessage"
                                                label="Escribe un comentario"
                                                outlined
                                                rows="3"
                                                :disabled="forumManagerPostingReply || !selectedForumDiscussionId"
                                            ></v-textarea>
                                            <v-btn
                                                color="primary"
                                                depressed
                                                :loading="forumManagerPostingReply"
                                                :disabled="!selectedForumDiscussionId || !forumManagerReplyMessage.trim()"
                                                @click="createForumReply"
                                            >
                                                Comentar
                                            </v-btn>
                                        </v-card-text>
                                    </v-card>
                                </v-col>
                            </v-row>
                        </v-container>
                    </v-card-text>
                </v-card>
            </v-dialog>

        </v-container>
    `,
    components: {
        // Global components: teacher-student-table, grades-grid, pending-grading-view, attendance-panel
    },
    data() {
        return {
            activeTab: 'timeline', // Changed to string to match new tab IDs
            fab: false,
            classDetails: {
                name: '',
                course_shortname: '',
                type: 0
            },
            tabs: [
                { id: 'timeline', name: 'Sesiones', icon: 'mdi-calendar-clock' },
                { id: 'roster', name: 'Estudiantes', icon: 'mdi-account-group' },
                { id: 'tasks', name: 'Por Calificar', icon: 'mdi-clipboard-check' },
                { id: 'grades', name: 'Calificaciones', icon: 'mdi-grid' },
                { id: 'content', name: 'Actividades', icon: 'mdi-folder-open' },
                { id: 'notices', name: 'Avisos', icon: 'mdi-bullhorn' }
            ],
            loadedTabs: {
                timeline: true,
                roster: false,
                tasks: false,
                grades: false,
                content: false,
                notices: false
            },
            timeline: [],
            loadingTimeline: false,
            timelineLoaded: false,
            activities: [],
            loadingActivities: false,
            activitiesLoaded: false,
            notices: [],
            loadingNotices: false,
            noticesLoaded: false,
            noticesForum: true,
            showNoticeForm: false,
            newNoticeSubject: '',
            newNoticeMessage: '',
            postingNotice: false,
            noticeError: '',
            noticeFiles: [],
            showActivityWizard: false,
            showQuizWizard: false,
            newActivityType: '',
            showActivitySelector: false,
            availableModules: [],
            isLoadingModules: false,
            activityWizardKey: 0,
            customActivityLabel: '',
            editActivityData: null,
            isEditing: false,
            snackbar: false,
            snackbarText: '',
            // Enter session loading state
            enteringSessionId: null,
            // QR / Attendance Data
            qrDialog: false,
            currentQR: null,
            currentSession: null,
            loadingQR: false,
            qrTimer: null,
            qrSecondsLeft: 0,
            qrTotalSeconds: 30,
            forumManagerDialog: false,
            forumManagerLoading: false,
            forumManagerError: '',
            forumManagerActivity: null,
            forumDiscussions: [],
            selectedForumDiscussionId: 0,
            forumPosts: [],
            forumManagerShowNewTopic: false,
            forumManagerNewSubject: '',
            forumManagerNewMessage: '',
            forumManagerReplyMessage: '',
            forumManagerPostingDiscussion: false,
            forumManagerPostingReply: false
        };
    },
    computed: {
        groupedActivities() {
            if (!this.activities || !Array.isArray(this.activities)) {
                return {};
            }
            // Build groups - General first so it always appears at the top
            const groups = { 'General': [] };
            this.activities.forEach(activity => {
                const tags = (activity.tags && activity.tags.length > 0) ? activity.tags : ['General'];
                tags.forEach(tag => {
                    if (!groups[tag]) groups[tag] = [];
                    groups[tag].push(activity);
                });
            });
            // Remove General if empty
            if (groups['General'].length === 0) delete groups['General'];
            return groups;
        },
        selectedForumDiscussion() {
            if (!this.selectedForumDiscussionId) {
                return null;
            }
            return this.forumDiscussions.find(d => parseInt(d.id, 10) === parseInt(this.selectedForumDiscussionId, 10)) || null;
        }
    },
    mounted() {
        this.applyInitialTab(this.initialTab);
        this.fetchClassDetails();
        this.ensureTabLoaded(this.activeTab);
    },
    watch: {
        activeTab(newTab) {
            this.ensureTabLoaded(newTab);
            this.$emit('state-change', { tab: newTab });
        },
        initialTab(newTab) {
            this.applyInitialTab(newTab);
        }
    },
    methods: {
        isValidTab(tabId) {
            return this.tabs.some(tab => tab.id === tabId);
        },
        applyInitialTab(tabId) {
            if (typeof tabId !== 'string' || tabId === '') {
                return;
            }
            const normalized = tabId.replace(/^#/, '');
            if (!this.isValidTab(normalized)) {
                return;
            }
            if (normalized !== this.activeTab) {
                this.activeTab = normalized;
            }
        },
        ensureTabLoaded(tabId) {
            if (!tabId || this.loadedTabs[tabId]) {
                if (tabId === 'timeline' && !this.timelineLoaded && !this.loadingTimeline) {
                    this.fetchTimeline();
                }
                return;
            }

            this.$set(this.loadedTabs, tabId, true);

            if (tabId === 'content') {
                this.fetchActivities();
            } else if (tabId === 'notices') {
                this.fetchNotices();
            } else if (tabId === 'timeline' && !this.timelineLoaded) {
                this.fetchTimeline();
            }
        },
        async fetchClassDetails() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_teacher_dashboard_data',
                    args: { userid: window.userId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    const cls = response.data.data.active_classes.find(c => c.id === this.classId);
                    if (cls) this.classDetails = cls;
                }
            } catch (error) {
                console.error('Error fetching class details:', error);
            }
        },
        async fetchTimeline(force = false) {
            if (!this.config || !this.config.wwwroot) {
                console.error('ManageClass: Config or wwwroot missing', this.config);
                this.loadingTimeline = false;
                return;
            }
            if (this.loadingTimeline) {
                return;
            }
            if (this.timelineLoaded && !force) {
                return;
            }
            this.loadingTimeline = true;
            try {
                let sessions = [];
                let attSessions = [];

                // 1) Primary source: attendance sessions (more reliable for mixed classes).
                try {
                    const attendanceResp = await axios.post(
                        this.config.wwwroot + '/local/grupomakro_core/ajax.php',
                        new URLSearchParams({
                            action: 'local_grupomakro_get_attendance_sessions',
                            classid: this.classId
                        })
                    );
                    const attendanceRoot = attendanceResp?.data || {};
                    const attendancePayload = (attendanceRoot && typeof attendanceRoot === 'object' && attendanceRoot.data && !Array.isArray(attendanceRoot.data))
                        ? attendanceRoot.data
                        : attendanceRoot;
                    const attendanceStatus = attendancePayload?.status || attendanceRoot?.status;
                    if (attendanceStatus === 'success') {
                        attSessions = attendancePayload.sessions || attendanceRoot.sessions || [];
                    } else {
                        console.warn(
                            'attendance_sessions returned non-success',
                            attendanceRoot?.message || attendancePayload?.message || attendanceRoot
                        );
                    }
                } catch (attendanceErr) {
                    console.error('attendance_sessions request failed', attendanceErr);
                }

                // 2) Secondary source: calendar details (best effort only, never blocks timeline rendering).
                try {
                    const timelineResp = await axios.post(window.wsUrl, {
                        action: 'local_grupomakro_get_class_details',
                        args: { classid: this.classId },
                        ...window.wsStaticParams
                    });
                    const timelineRoot = timelineResp?.data || {};
                    const timelinePayload = (timelineRoot && typeof timelineRoot === 'object' && timelineRoot.data && !Array.isArray(timelineRoot.data))
                        ? timelineRoot.data
                        : timelineRoot;
                    const timelineStatus = timelinePayload?.status || timelineRoot?.status;
                    if (timelineStatus === 'success') {
                        sessions = timelinePayload.sessions || timelineRoot.sessions || [];
                        const payloadClass = timelinePayload?.class || timelineRoot?.class;
                        if (payloadClass && !this.classDetails.name) {
                            this.classDetails = { ...this.classDetails, ...payloadClass };
                        }
                    } else {
                        console.warn(
                            'class_details returned non-success',
                            timelineRoot?.message || timelinePayload?.message || timelineRoot
                        );
                    }
                } catch (timelineErr) {
                    console.error('class_details request failed', timelineErr);
                }

                // Canonical timeline source: attendance sessions.
                // This avoids empty timelines when calendar events are inconsistent (especially in mixed classes).
                const timelineCandidates = Array.isArray(sessions)
                    ? sessions.map(s => ({ ...s, _matched: false }))
                    : [];
                let mergedTimeline = [];

                if (Array.isArray(attSessions) && attSessions.length > 0) {
                    mergedTimeline = attSessions.map((att, idx) => {
                        let best = null;
                        let bestDiff = Number.MAX_SAFE_INTEGER;

                        timelineCandidates.forEach(t => {
                            if (t._matched) return;
                            const diff = Math.abs((parseInt(t.startdate, 10) || 0) - (parseInt(att.sessdate, 10) || 0));
                            if (diff < bestDiff) {
                                bestDiff = diff;
                                best = t;
                            }
                        });

                        // Tolerance for manual reschedules / timezone drifts.
                        if (best && bestDiff <= 7200) {
                            best._matched = true;
                        } else {
                            best = null;
                        }

                        const start = parseInt(att.sessdate, 10) || parseInt(best?.startdate, 10) || 0;
                        const duration = parseInt(att.duration, 10) || 3600;

                        return {
                            id: best?.id ?? ('att_' + att.id),
                            name: best?.name || `Sesion ${idx + 1}`,
                            description: att.description || best?.description || 'Sesion programada',
                            startdate: start,
                            enddate: start + duration,
                            type: 'virtual',
                            bbb_cmid: att.bbb_cmid || best?.bbb_cmid || 0,
                            join_url: att.join_url || best?.join_url || '',
                            guest_url: att.guest_url || best?.guest_url || '',
                            attendance: att
                        };
                    });
                } else {
                    mergedTimeline = timelineCandidates.map(t => ({ ...t, attendance: null }));
                }

                // Keep unmatched event rows (if any).
                timelineCandidates
                    .filter(t => !t._matched)
                    .forEach(t => mergedTimeline.push({ ...t, attendance: t.attendance || null }));

                mergedTimeline.sort((a, b) => (parseInt(a.startdate, 10) || 0) - (parseInt(b.startdate, 10) || 0));

                this.timeline = mergedTimeline;
                this.timelineLoaded = true;

            } catch (error) {
                console.error('Error fetching timeline/attendance:', error);
            } finally {
                this.loadingTimeline = false;
            }
        },
        async showQR(session) {
            if (!session.attendance) return;

            this.currentSession = session;
            this.loadingQR = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_session_qr');
                params.append('sessionid', session.attendance.id);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                console.log('QR Response Payload:', response.data);

                if (response.data && response.data.status === 'success') {
                    this.currentQR = response.data;
                    this.currentSession = session; // Store the session for rotation
                    this.qrDialog = true;

                    // Handle Rotation
                    // console.log('QR Loop Check:', this.currentQR.rotate);
                    if (this.currentQR.rotate) {
                        console.log('Starting Rotation...');
                        this.startQRRotation(this.currentQR.rotate_interval);
                    }
                } else {
                    console.error('QR Logic Error:', response.data);
                    this.snackbarText = (response.data && response.data.message) ? response.data.message : 'Error al obtener QR (Respuesta invalida)';
                    this.snackbarColor = 'error';
                    this.snackbar = true;
                }
            } catch (e) {
                console.error(e);
                this.snackbarText = 'Error de conexion';
                this.snackbar = true;
            } finally {
                this.loadingQR = false;
            }
        },
        startQRRotation(intervalSeconds = 10) {
            console.log('startQRRotation called');
            if (this.qrTimer) clearInterval(this.qrTimer);
            const parsed = parseInt(intervalSeconds, 10);
            this.qrTotalSeconds = (!isNaN(parsed) && parsed > 1) ? parsed : 10;
            this.qrSecondsLeft = this.qrTotalSeconds;

            this.qrTimer = setInterval(() => {
                this.qrSecondsLeft--;
                // console.log('Timer:', this.qrSecondsLeft);
                if (this.qrSecondsLeft <= 0) {
                    clearInterval(this.qrTimer);
                    // Refresh if dialog open
                    if (this.qrDialog && this.currentSession) {
                        this.showQR(this.currentSession);
                    }
                }
            }, 1000);
        },
        closeQRDialog() {
            this.qrDialog = false;
            if (this.qrTimer) clearInterval(this.qrTimer);
            this.currentSession = null;
        },


        async fetchActivities(force = false) {
            if (this.loadingActivities) {
                return;
            }
            if (this.activitiesLoaded && !force) {
                return;
            }
            this.loadingActivities = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_all_activities',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    // console.log('Fetch Activities Success:', response.data.activities);
                    this.activities = response.data.activities;
                    this.activitiesLoaded = true;
                } else {
                    console.warn('Fetch Activities Failed:', response.data);
                }
            } catch (error) {
                console.error('Error fetching activities:', error);
            } finally {
                this.loadingActivities = false;
            }
        },
        getSessionColor(session) {
            const now = new Date();
            const sessionDate = new Date(parseInt(session.startdate) * 1000);
            if (sessionDate < now) return 'grey lighten-1';
            if (this.isNextSession(session)) return 'primary';
            return 'grey lighten-3';
        },
        isNextSession(session) {
            return false;
        },
        isSessionActive(session) {
            const now = new Date().getTime() / 1000;
            const start = parseInt(session.startdate);
            let end = parseInt(session.enddate);

            if (!end || isNaN(end)) {
                if (session.timeduration) {
                    end = start + parseInt(session.timeduration);
                } else {
                    end = start + 3600; // Default 1 hour
                }
            }

            // Window: 15 mins before start -> End time
            return now >= (start - 900) && now <= end;
        },
        formatDate(timestamp) {
            if (!timestamp) return 'No programada';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString('es', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        async enterSession(session) {
            const cmid = parseInt(session?.bbb_cmid || 0, 10) || 0;
            const attendanceSessionId = (session && session.attendance && session.attendance.id)
                ? parseInt(session.attendance.id, 10) || 0
                : 0;

            this.enteringSessionId = session.id;
            try {
                const params = new URLSearchParams({
                    action: 'local_grupomakro_get_bbb_join_url',
                    cmid: String(cmid || 0),
                    classid: String(this.classId || 0)
                });
                if (attendanceSessionId > 0) {
                    params.append('sessionid', String(attendanceSessionId));
                }

                const resp = await axios.post(
                    this.config.wwwroot + '/local/grupomakro_core/ajax.php',
                    params
                );
                const data = resp.data || {};
                if (data && data.debug) {
                    console.warn('BBB join debug', data.debug);
                }
                const resolvedUrl = (data && data.join_url) ? data.join_url : '';
                if (resolvedUrl) {
                    window.open(resolvedUrl, '_blank');
                } else {
                    alert((data && data.message) ? data.message : 'No se pudo obtener el enlace de la reunion.');
                }
            } catch (e) {
                console.error('enterSession error', e);
                alert('Error al conectar con el servidor.');
            } finally {
                this.enteringSessionId = null;
            }
        },
        openQuizQuestions(activity) {
            // Use SPA navigation instead of new window
            this.$emit('change-page', {
                page: 'quiz-editor',
                cmid: activity.id,
                id: this.classId, // Ensure we keep track of current class
                tab: this.activeTab
            });
        },
        openForumActivity(activity) {
            if (!activity || !activity.id) {
                alert('No se encontro el foro.');
                return;
            }
            this.forumManagerActivity = activity;
            this.forumManagerDialog = true;
            this.forumManagerError = '';
            this.forumManagerShowNewTopic = false;
            this.forumManagerNewSubject = '';
            this.forumManagerNewMessage = '';
            this.forumManagerReplyMessage = '';
            this.forumDiscussions = [];
            this.forumPosts = [];
            this.selectedForumDiscussionId = 0;
            this.fetchForumActivityData();
        },
        closeForumManagerDialog() {
            this.forumManagerDialog = false;
            this.forumManagerActivity = null;
            this.forumManagerError = '';
            this.forumManagerShowNewTopic = false;
            this.forumManagerNewSubject = '';
            this.forumManagerNewMessage = '';
            this.forumManagerReplyMessage = '';
            this.forumDiscussions = [];
            this.forumPosts = [];
            this.selectedForumDiscussionId = 0;
        },
        async fetchForumActivityData() {
            if (!this.forumManagerActivity || !this.forumManagerActivity.id) {
                return;
            }
            this.forumManagerLoading = true;
            this.forumManagerError = '';
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_forum_activity_data',
                    args: {
                        classid: parseInt(this.classId, 10),
                        cmid: parseInt(this.forumManagerActivity.id, 10)
                    },
                    ...window.wsStaticParams
                });
                if (response.data.status !== 'success') {
                    throw new Error(response.data.message || 'No se pudo cargar el foro.');
                }
                this.forumDiscussions = Array.isArray(response.data.discussions) ? response.data.discussions : [];
                if (this.forumDiscussions.length > 0) {
                    await this.selectForumDiscussion(this.forumDiscussions[0]);
                } else {
                    this.selectedForumDiscussionId = 0;
                    this.forumPosts = [];
                }
            } catch (e) {
                this.forumManagerError = e && e.message ? e.message : 'Error cargando foro.';
            } finally {
                this.forumManagerLoading = false;
            }
        },
        async selectForumDiscussion(discussion) {
            if (!discussion || !discussion.id || !this.forumManagerActivity) {
                return;
            }
            this.selectedForumDiscussionId = parseInt(discussion.id, 10);
            this.forumManagerLoading = true;
            this.forumManagerError = '';
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_forum_discussion_posts',
                    args: {
                        classid: parseInt(this.classId, 10),
                        cmid: parseInt(this.forumManagerActivity.id, 10),
                        discussionid: parseInt(discussion.id, 10)
                    },
                    ...window.wsStaticParams
                });
                if (response.data.status !== 'success') {
                    throw new Error(response.data.message || 'No se pudieron cargar los comentarios.');
                }
                this.forumPosts = Array.isArray(response.data.posts) ? response.data.posts : [];
            } catch (e) {
                this.forumManagerError = e && e.message ? e.message : 'Error cargando comentarios.';
            } finally {
                this.forumManagerLoading = false;
            }
        },
        async createForumDiscussion() {
            if (!this.forumManagerActivity || !this.forumManagerActivity.id) {
                return;
            }
            const subject = (this.forumManagerNewSubject || '').trim();
            const message = (this.forumManagerNewMessage || '').trim();
            if (!subject || !message) {
                return;
            }
            this.forumManagerPostingDiscussion = true;
            this.forumManagerError = '';
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_forum_discussion',
                    args: {
                        classid: parseInt(this.classId, 10),
                        cmid: parseInt(this.forumManagerActivity.id, 10),
                        subject: subject,
                        message: message
                    },
                    ...window.wsStaticParams
                });
                if (response.data.status !== 'success') {
                    throw new Error(response.data.message || 'No se pudo crear el tema.');
                }
                this.forumManagerNewSubject = '';
                this.forumManagerNewMessage = '';
                this.forumManagerShowNewTopic = false;
                await this.fetchForumActivityData();
                if (response.data.discussionid) {
                    const created = this.forumDiscussions.find(d => parseInt(d.id, 10) === parseInt(response.data.discussionid, 10));
                    if (created) {
                        await this.selectForumDiscussion(created);
                    }
                }
                this.snackbarText = 'Tema publicado correctamente.';
                this.snackbar = true;
            } catch (e) {
                this.forumManagerError = e && e.message ? e.message : 'Error creando tema.';
            } finally {
                this.forumManagerPostingDiscussion = false;
            }
        },
        async createForumReply() {
            if (!this.forumManagerActivity || !this.forumManagerActivity.id || !this.selectedForumDiscussionId) {
                return;
            }
            const message = (this.forumManagerReplyMessage || '').trim();
            if (!message) {
                return;
            }
            this.forumManagerPostingReply = true;
            this.forumManagerError = '';
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_forum_reply',
                    args: {
                        classid: parseInt(this.classId, 10),
                        cmid: parseInt(this.forumManagerActivity.id, 10),
                        discussionid: parseInt(this.selectedForumDiscussionId, 10),
                        message: message
                    },
                    ...window.wsStaticParams
                });
                if (response.data.status !== 'success') {
                    throw new Error(response.data.message || 'No se pudo publicar el comentario.');
                }
                this.forumManagerReplyMessage = '';
                const selected = this.selectedForumDiscussion;
                if (selected) {
                    await this.selectForumDiscussion(selected);
                }
                await this.fetchForumActivityData();
                this.snackbarText = 'Comentario publicado correctamente.';
                this.snackbar = true;
            } catch (e) {
                this.forumManagerError = e && e.message ? e.message : 'Error publicando comentario.';
            } finally {
                this.forumManagerPostingReply = false;
            }
        },
        formatForumDate(timestamp) {
            if (!timestamp) return '';
            return new Date(parseInt(timestamp, 10) * 1000).toLocaleString('es', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        canDeleteActivity(activity) {
            if (!activity || !activity.modname) return false;
            return activity.modname !== 'attendance' && activity.modname !== 'bigbluebuttonbn';
        },
        async deleteActivity(activity) {
            if (!this.canDeleteActivity(activity)) {
                return;
            }
            if (!confirm(`\\u00bfEliminar la actividad "${activity.name}"? Esta accion no se puede deshacer.`)) {
                return;
            }
            this.$set(activity, '_deleting', true);
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_delete_activity',
                    args: { cmid: activity.id, classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.activities = this.activities.filter(a => a.id !== activity.id);
                    this.snackbarText = response.data.message || 'Actividad eliminada correctamente.';
                    this.snackbar = true;
                    await this.fetchTimeline(true);
                } else {
                    alert(response.data.message || 'No se pudo eliminar la actividad.');
                    this.$set(activity, '_deleting', false);
                }
            } catch (error) {
                console.error('Error deleting activity:', error);
                alert('Error de conexion al eliminar actividad.');
                this.$set(activity, '_deleting', false);
            }
        },
        closeActivityWizard() {
            this.showActivityWizard = false;
            this.isEditing = false;
            this.editActivityData = null;
            this.newActivityType = '';
            this.customActivityLabel = '';
        },
        addActivity(type, label = '') {
            if (type === 'quiz') {
                this.closeActivityWizard();
                this.showQuizWizard = true;
            } else {
                this.closeActivityWizard();
                this.activityWizardKey += 1;
                this.newActivityType = type;
                this.customActivityLabel = label;
                this.showActivityWizard = true;
            }
        },
        async fetchAvailableModules() {
            this.isLoadingModules = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_available_modules',
                    args: {},
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.availableModules = response.data.modules;
                }
            } catch (e) { console.error(e); }
            finally { this.isLoadingModules = false; }
        },
        openActivitySelector() {
            this.showActivitySelector = true;
            if (this.availableModules.length === 0) {
                this.fetchAvailableModules();
            }
        },
        selectModule(module) {
            this.showActivitySelector = false;
            this.addActivity(module.name, module.label);
        },
        goToCourse() {
            // Redirect to standard course page in editing mode to add other activities
            if (this.classDetails.corecourseid) {
                window.open(`${window.M.cfg.wwwroot}/course/view.php?id=${this.classDetails.corecourseid}`, '_blank');
            } else {
                alert('ID del curso no disponible.');
            }
        },
        onActivityCreated() {
            this.fetchTimeline(true);
            this.fetchActivities(true); // Refresh activities list
            this.closeActivityWizard();
        },
        openEditActivity(activity) {
            this.closeActivityWizard();
            this.activityWizardKey += 1;
            this.isEditing = true;
            this.editActivityData = activity;
            this.newActivityType = activity.modname; // Needed for wizard type context
            this.customActivityLabel = activity.name; // Temporary till loaded
            this.showActivityWizard = true;
        },
        async fetchNotices(force = false) {
            if (this.loadingNotices) {
                return;
            }
            if (this.noticesLoaded && !force) {
                return;
            }
            this.loadingNotices = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_forum_posts',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.notices = response.data.posts || [];
                    this.noticesForum = response.data.forum_found !== false;
                    this.noticesLoaded = true;
                }
            } catch (e) {
                console.error('Error fetching notices:', e);
            } finally {
                this.loadingNotices = false;
            }
        },
        onNoticeFilesSelected(event) {
            const selected = Array.from(event.target.files || []);
            selected.forEach(f => this.noticeFiles.push(f));
            // Reset input so same file can be re-added if removed
            event.target.value = '';
        },
        removeNoticeFile(idx) {
            this.noticeFiles.splice(idx, 1);
        },
        async postNotice() {
            this.postingNotice = true;
            this.noticeError = '';
            try {
                // Use FormData to support file attachments (multipart/form-data)
                const fd = new FormData();
                fd.append('action', 'local_grupomakro_post_forum_announcement');
                fd.append('sesskey', window.wsStaticParams.sesskey);
                fd.append('classid', this.classId);
                fd.append('subject', this.newNoticeSubject);
                fd.append('message', this.newNoticeMessage);
                this.noticeFiles.forEach((f, i) => fd.append('attachment_' + i, f, f.name));

                const response = await axios.post(window.wsUrl, fd);
                if (response.data.status === 'success') {
                    this.showNoticeForm = false;
                    this.newNoticeSubject = '';
                    this.newNoticeMessage = '';
                    this.noticeFiles = [];
                    await this.fetchNotices(true);
                } else {
                    this.noticeError = response.data.message || 'Error al publicar el aviso.';
                }
            } catch (e) {
                this.noticeError = 'Error de conexion al publicar.';
                console.error(e);
            } finally {
                this.postingNotice = false;
            }
        },
        async deleteNotice(notice) {
            if (!confirm('\\u00bfEliminar el aviso "' + notice.subject + '"? Esta accion no se puede deshacer.')) return;
            this.$set(notice, '_deleting', true);
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_delete_forum_discussion',
                    args: { discussionid: notice.id },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.notices = this.notices.filter(n => n.id !== notice.id);
                } else {
                    alert(response.data.message || 'Error al eliminar el aviso.');
                    this.$set(notice, '_deleting', false);
                }
            } catch (e) {
                alert('Error de conexion al eliminar.');
                this.$set(notice, '_deleting', false);
            }
        },
        formatNoticeDate(timestamp) {
            if (!timestamp) return '';
            return new Date(timestamp * 1000).toLocaleDateString('es', {
                day: 'numeric', month: 'long', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        },
        copyGuestLink(url) {
            navigator.clipboard.writeText(url).then(() => {
                this.snackbarText = 'Enlace de invitado copiado al portapapeles';
                this.snackbar = true;
            }).catch(err => {
                console.error('Error al copiar:', err);
                alert('No se pudo copiar el enlace.');
            });
        }
    }
};

window.ManageClass = ManageClass;
