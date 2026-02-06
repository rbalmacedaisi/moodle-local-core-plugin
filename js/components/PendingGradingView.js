/**
 * PendingGradingView.js
 * Component to view and manage pending grading tasks.
 * Can be used globally or scoped to a class.
 */

Vue.component('pending-grading-view', {
    props: {
        classId: { type: [Number, String], default: 0 }, // 0 = Global
        className: { type: String, default: '' }
    },
    template: `
        <v-card flat class="h-100 pending-grading-view">
            <v-card-title class="headline" :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-4'">
                <v-icon large left color="orange">mdi-clipboard-check-outline</v-icon>
                Gestión de Calificaciones
                <span v-if="className" class="ml-2 subtitle-1 grey--text"> - {{ className }}</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="fetchTasks" :loading="loading">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-card-title>

            <v-tabs v-model="activeTab" color="primary" grow @change="fetchTasks">
                <v-tab> Pendientes </v-tab>
                <v-tab> Histórico </v-tab>
            </v-tabs>
            
            <v-card-text class="pa-4">
                <!-- Summary Stats -->
                <v-row class="mb-4">
                    <v-col cols="12" sm="4">
                        <v-card outlined class="d-flex align-center pa-3" :color="$vuetify.theme.dark ? 'grey darken-4' : 'orange lighten-5'">
                            <v-icon color="orange" size="36" class="mr-3">mdi-file-document-edit-outline</v-icon>
                            <div>
                                <div class="caption grey--text">{{ activeTab === 0 ? 'Total Pendientes' : 'Total Calificados' }}</div>
                                <div class="text-h5 font-weight-bold" :class="$vuetify.theme.dark ? 'white--text' : 'orange--text text--darken-3'">
                                    {{ activeTab === 0 ? totalPending : tasks.length }}
                                </div>
                            </div>
                        </v-card>
                    </v-col>
                    
                    <v-col cols="12" sm="8">
                        <v-text-field
                            v-model="search"
                            append-icon="mdi-magnify"
                            label="Buscar tareas..."
                            single-line
                            hide-details
                            outlined
                            dense
                            :background-color="$vuetify.theme.dark ? '' : 'white'"
                        ></v-text-field>
                    </v-col>
                </v-row>

                <!-- Data Table -->
                <v-data-table
                    :headers="headers"
                    :items="tasks"
                    :search="search"
                    :loading="loading"
                    :items-per-page="10"
                    class="elevation-1"
                    :class="$vuetify.theme.dark ? '' : 'white'"
                >
                    <template v-slot:item.studentname="{ item }">
                        <div class="d-flex align-center py-2">
                             <v-avatar size="32" class="mr-2">
                                <img v-if="item.studentavatar" :src="item.studentavatar" alt="avatar">
                                <span v-else class="white--text headline">{{ item.studentname.charAt(0) }}</span>
                             </v-avatar>
                             <div>
                                <div class="font-weight-medium">{{ item.studentname }}</div>
                                <div class="caption grey--text">{{ item.studentemail }}</div>
                             </div>
                        </div>
                    </template>

                    <template v-slot:item.assignmentname="{ item }">
                        <div class="py-2">
                            <div class="font-weight-bold text-subtitle-2">
                                <v-icon small class="mr-1" :color="item.modname === 'quiz' ? 'deep-purple' : 'primary'">
                                    {{ item.modname === 'quiz' ? 'mdi-help-box' : 'mdi-file-document' }}
                                </v-icon>
                                {{ item.assignmentname }}
                            </div>
                            <div class="caption grey--text" v-if="!classId">
                                <v-icon x-small class="mr-1">mdi-school</v-icon>
                                {{ item.coursename }}
                            </div>
                            <div class="caption red--text mt-1" v-if="isOverdue(item.duedate)">
                                <v-icon x-small color="red">mdi-clock-alert</v-icon> 
                                Vencida: {{ formatDate(item.duedate) }}
                            </div>
                            <div class="caption green--text mt-1" v-else>
                                Vence: {{ formatDate(item.duedate) }}
                            </div>
                        </div>
                    </template>

                    <template v-slot:item.submissiontime="{ item }">
                        <span class="text-caption font-weight-medium">
                            Enviado: {{ formatDate(item.submissiontime, true) }}
                        </span>
                    </template>

                    <template v-slot:item.actions="{ item }">
                        <v-btn small :color="activeTab === 0 ? 'primary' : 'secondary'" depressed @click="openQuickGrader(item)">
                            <v-icon left small>{{ activeTab === 0 ? 'mdi-check-circle-outline' : 'mdi-eye' }}</v-icon>
                            {{ activeTab === 0 ? 'Calificar' : 'Revisar' }}
                        </v-btn>
                    </template>
                    
                    <template v-slot:no-data>
                         <div class="pa-4 text-center grey--text">
                             <v-icon size="48" color="grey lighten-1">mdi-check-all</v-icon>
                             <div class="mt-2">{{ activeTab === 0 ? '¡Todo al día! No tienes actividades pendientes por calificar.' : 'No se encontraron actividades en el historial.' }}</div>
                         </div>
                    </template>
                </v-data-table>
            </v-card-text>

            <quick-grader 
                v-if="showGrader" 
                :task.sync="selectedTask" 
                :all-tasks="tasks"
                @close="closeGrader"
                @grade-saved="onGradeSaved"
            ></quick-grader>
        </v-card>
    `,
    data() {
        return {
            loading: false,
            tasks: [],
            search: '',
            showGrader: false,
            selectedTask: null,
            activeTab: 0
        };
    },
    computed: {
        totalPending() {
            return this.tasks.length;
        },
        headers() {
            return [
                { text: 'Estudiante', value: 'studentname', width: '25%' },
                { text: 'Actividad', value: 'assignmentname' },
                { text: 'Fecha Envío', value: 'submissiontime', width: '20%' },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'end', width: '150px' },
            ];
        }
    },
    mounted() {
        this.fetchTasks();
    },
    watch: {
        classId() {
            this.fetchTasks();
        }
    },
    methods: {
        async fetchTasks() {
            this.loading = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_pending_grading',
                    classid: this.classId,
                    status: this.activeTab === 0 ? 'pending' : 'history',
                    sesskey: M.cfg.sesskey
                });

                console.log('[GMK] Pending Grading Response:', response.data);
                if (response.data.status === 'success') {
                    this.tasks = response.data.tasks;
                } else {
                    console.error('Error loading tasks:', response.data.message);
                }
            } catch (e) {
                console.error('Network Error:', e);
            } finally {
                this.loading = false;
            }
        },
        formatDate(timestamp, full = false) {
            if (!timestamp) return '-';
            const date = new Date(timestamp * 1000);
            return full ? date.toLocaleString() : date.toLocaleDateString();
        },
        isOverdue(duedate) {
            if (!duedate) return false;
            return (Date.now() / 1000) > duedate;
        },
        openQuickGrader(item) {
            this.selectedTask = item;
            this.showGrader = true;
        },
        closeGrader() {
            this.showGrader = false;
            this.selectedTask = null;
        },
        onGradeSaved(taskId) {
            // Remove the graded task from the list locally to avoid reload
            this.tasks = this.tasks.filter(t => t.id !== taskId);

            // If the quick grader moves to next task automatically, 
            // the QuickGrader component handles the selection update.
            // But we need to sync our list state if we want the background list to update.
        }
    }
});


