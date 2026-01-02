/**
 * Manage Class Component
 * Created for Redesigning Teacher Experience
 */

const ManageClass = {
    props: {
        classId: {
            type: [Number, String],
            required: true
        }
    },
    template: `
        <v-container fluid class="pa-4 pt-0 grey lighten-5 h-100">
            <!-- Header section -->
            <v-row class="white py-4 border-bottom sticky-header">
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
                    <v-tabs v-model="activeTab" background-color="white" color="primary" grow>
                        <v-tab v-for="tab in tabs" :key="tab.id">
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
                        <v-tab-item>
                            <v-card flat class="transparent">
                                <v-timeline dense align-top class="mx-4">
                                    <v-timeline-item
                                        v-for="(session, index) in timeline"
                                        :key="session.id"
                                        :color="session.type === 'virtual' ? 'blue' : 'green'"
                                        small
                                        fill-dot
                                    >
                                        <v-card class="rounded-xl shadow-sm elevation-1 mb-2">
                                            <v-card-title class="text-subtitle-1 font-weight-bold pb-1">
                                                Sesión {{ index + 1 }}
                                                <v-spacer></v-spacer>
                                                <v-chip x-small outlined :color="session.type === 'virtual' ? 'blue' : 'green'">
                                                    {{ session.type === 'virtual' ? 'VIRTUAL' : 'PRESENCIAL' }}
                                                </v-chip>
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex align-center grey--text text--darken-2">
                                                    <v-icon small class="mr-2">mdi-calendar-clock</v-icon>
                                                    <span class="font-weight-medium">{{ formatDate(session.startdate) }}</span>
                                                </div>
                                                <div v-if="session.type === 'virtual'" class="mt-2 caption blue--text text--darken-1">
                                                    <v-icon x-small color="blue darken-1" class="mr-1">mdi-information</v-icon>
                                                    El acceso se habilita a la hora del evento.
                                                </div>
                                            </v-card-text>
                                            <v-divider></v-divider>
                                            <v-card-actions class="grey lighten-5">
                                                <v-btn 
                                                    small 
                                                    depressed 
                                                    :color="session.type === 'virtual' ? 'blue' : 'green'" 
                                                    dark 
                                                    class="rounded-lg px-4" 
                                                    @click="enterSession(session)"
                                                    :disabled="session.type === 'virtual' && !isSessionActive(session)"
                                                >
                                                    <v-icon left x-small>{{ session.type === 'virtual' ? 'mdi-video' : 'mdi-qrcode' }}</v-icon>
                                                    {{ session.type === 'virtual' ? 'Entrar' : 'Asistencia' }}
                                                </v-btn>
                                                    <v-icon left x-small>{{ session.type === 'virtual' ? 'mdi-video' : 'mdi-qrcode' }}</v-icon>
                                                    {{ session.type === 'virtual' ? 'Entrar' : 'Asistencia' }}
                                                </v-btn>
                                            </v-card-actions>
                                        </v-card>
                                    </v-timeline-item>
                                </v-timeline>
                                <v-alert v-if="timeline.length === 0" type="info" text class="ma-4 rounded-xl">
                                    No hay sesiones programadas para esta clase.
                                </v-alert>
                            </v-card>
                        </v-tab-item>

                        <!-- Roster Tab -->
                        <v-tab-item>
                            <teacher-student-table :class-id="classId"></teacher-student-table>
                        </v-tab-item>

                        <!-- Grades Tab -->
                        <v-tab-item>
                            <grades-grid :class-id="classId"></grades-grid>
                        </v-tab-item>

                        <!-- Activities Tab -->
                        <v-tab-item>
                             <v-card flat class="transparent pa-4">
                                <v-expansion-panels multiple hover>
                                    <v-expansion-panel v-for="(group, name) in groupedActivities" :key="name" class="mb-2 rounded-lg transparent-panel">
                                        <v-expansion-panel-header class="blue-grey lighten-5">
                                            <div class="d-flex align-center">
                                                <v-icon left color="primary">mdi-label</v-icon> 
                                                <span class="font-weight-bold text-subtitle-1">{{ name }}</span>
                                                <v-chip x-small class="ml-2" color="white">{{ group.length }}</v-chip>
                                            </div>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content class="white">
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
                                                        <v-list-item-action>
                                                            <v-btn icon small @click.stop="openEditActivity(activity)"><v-icon color="grey lighten-1">mdi-pencil</v-icon></v-btn>
                                                            <v-btn icon small :href="activity.url" target="_blank"><v-icon color="grey lighten-1">mdi-open-in-new</v-icon></v-btn>
                                                        </v-list-item-action>
                                                    </v-list-item>
                                                    <v-divider v-if="i < group.length - 1" :key="'div-' + i" inset></v-divider>
                                                </template>
                                            </v-list>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                                <v-alert v-if="Object.keys(groupedActivities).length === 0" type="info" text class="ma-4 rounded-xl">
                                    No hay actividades creadas aún. Usa el botón + para añadir una.
                                </v-alert>
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
                    <v-card-title class="headline grey lighten-5">
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
                :class-id="parseInt(classId)" 
                :activity-type="newActivityType"
                :custom-label="customActivityLabel"
                :edit-mode="isEditing"
                :edit-data="editActivityData"
                @close="showActivityWizard = false"
                @success="onActivityCreated"
            ></activity-creation-wizard>

        </v-container>
    `,
    data() {
        return {
            activeTab: 0,
            fab: false,
            classDetails: {
                name: '',
                course_shortname: '',
                type: 0
            },
            tabs: [
                { id: 0, name: 'Timeline', icon: 'mdi-timeline-clock' },
                { id: 1, name: 'Estudiantes', icon: 'mdi-account-group' },
                { id: 2, name: 'Notas', icon: 'mdi-star' },
                { id: 3, name: 'Actividades', icon: 'mdi-view-grid-outline' }
            ],
            timeline: [],
            activities: [],
            showActivityWizard: false,
            newActivityType: '',
            showActivitySelector: false,
            availableModules: [],
            availableModules: [],
            isLoadingModules: false,
            customActivityLabel: '',
            editActivityData: null,
            isEditing: false
        };
    },
    computed: {
        groupedActivities() {
            const groups = {};
            console.log('Calculating groupedActivities', this.activities);
            if (!this.activities || !Array.isArray(this.activities)) {
                console.warn('Activities is not an array:', this.activities);
                return {};
            }
            this.activities.forEach(activity => {
                const tags = (activity.tags && activity.tags.length > 0) ? activity.tags : ['General'];
                console.log('Activity:', activity.name, 'Tags:', tags);
                tags.forEach(tag => {
                    if (!groups[tag]) groups[tag] = [];
                    groups[tag].push(activity);
                });
            });
            console.log('Grouped Activities:', groups);
            return groups;
        }
    },
    mounted() {
        this.fetchClassDetails();
        this.fetchTimeline();
        this.fetchActivities();
    },
    methods: {
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
        async fetchTimeline() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_class_details',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.timeline = response.data.data.sessions;
                }
            } catch (error) {
                console.error('Error fetching timeline:', error);
            }
        },
        async fetchActivities() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_all_activities',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    console.log('Fetch Activities Success:', response.data.activities);
                    this.activities = response.data.activities;
                } else {
                    console.warn('Fetch Activities Failed:', response.data);
                }
            } catch (error) {
                console.error('Error fetching activities:', error);
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
            // Simple logic for highlighting the upcoming session
            return false;
        },
        isSessionActive(session) {
            const now = new Date().getTime() / 1000;
            // Allow entry 15 mins before
            return now >= (session.startdate - 900);
        },
        formatDate(timestamp) {
            if (!timestamp) return 'No programada';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString(undefined, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        enterSession(session) {
            if (session.type === 'virtual') {
                if (session.join_url) {
                    window.open(session.join_url, '_blank');
                } else {
                    alert('El enlace a la sesión virtual no está disponible.');
                }
            } else {
                // Logic to open attendance manager
            }
        },
        addActivity(type, label = '') {
            this.newActivityType = type;
            this.customActivityLabel = label;
            this.showActivityWizard = true;
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
            this.fetchTimeline();
            this.fetchActivities(); // Refresh activities list
            this.isEditing = false;
        },
        openEditActivity(activity) {
            this.isEditing = true;
            this.editActivityData = activity;
            this.newActivityType = activity.modname; // Needed for wizard type context
            this.customActivityLabel = activity.name; // Temporary till loaded
            this.showActivityWizard = true;
        }
    }
};

window.ManageClass = ManageClass;
