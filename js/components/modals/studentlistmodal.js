Vue.component('student-list-modal', {
    template: `
        <v-dialog v-model="dialogVisible" max-width="900" persistent>
            <v-card class="rounded-lg">
                <v-card-title class="headline primary white--text d-flex align-center py-3 px-4">
                    <v-icon left color="white">mdi-account-group</v-icon>
                    <span>Estudiantes - {{ subperiodName }}</span>
                    <v-spacer></v-spacer>
                    <v-btn icon dark @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pa-0">
                    <v-progress-linear
                        v-if="loading"
                        indeterminate
                        color="primary"
                    ></v-progress-linear>

                    <v-alert v-if="errorMsg" type="error" outlined dismissible class="ma-4">
                        {{ errorMsg }}
                    </v-alert>

                    <v-alert v-if="!loading && students.length === 0" type="info" outlined class="ma-4">
                        No hay estudiantes en este bimestre.
                    </v-alert>

                    <v-data-table
                        v-if="!loading && students.length > 0"
                        :headers="headers"
                        :items="students"
                        :search="search"
                        class="elevation-1"
                        dense
                    >
                        <template v-slot:top>
                            <v-row align="center" class="px-4 py-2">
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        v-model="search"
                                        append-icon="mdi-magnify"
                                        label="Buscar estudiante..."
                                        hide-details
                                        outlined
                                        dense
                                        clearable
                                    ></v-text-field>
                                </v-col>
                                <v-col cols="12" sm="8" class="text-right">
                                    <span class="text-caption grey--text">
                                        {{ students.length }} estudiante(s)
                                    </span>
                                </v-col>
                            </v-row>
                        </template>

                        <template v-slot:item.username="{ item }">
                            <div class="font-weight-bold">{{ item.username }}</div>
                        </template>

                        <template v-slot:item.fullname="{ item }">
                            <div>{{ item.firstname }} {{ item.lastname }}</div>
                        </template>

                        <template v-slot:item.phone="{ item }">
                            <span v-if="item.phone">{{ item.phone }}</span>
                            <span v-else class="grey--text">—</span>
                        </template>

                        <template v-slot:item.status="{ item }">
                            <v-chip
                                x-small
                                :color="item.status === 'activo' ? 'success' : 'error'"
                                dark
                                label
                            >
                                {{ item.status }}
                            </v-chip>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <v-menu>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                        small
                                        color="primary"
                                        dark
                                        v-bind="attrs"
                                        v-on="on"
                                    >
                                        <v-icon left small>mdi-swap-horizontal</v-icon>
                                        Reasignar
                                        <v-icon right small>mdi-chevron-down</v-icon>
                                    </v-btn>
                                </template>
                                <v-list dense>
                                    <v-list-item
                                        v-for="period in availablePeriods"
                                        :key="period"
                                        @click="reassignPeriod(item, period)"
                                        :disabled="period === item.intake_period"
                                    >
                                        <v-list-item-title>
                                            <v-icon left small>mdi-calendar-arrow-right</v-icon>
                                            {{ period }}
                                            <v-chip v-if="period === item.intake_period" x-small color="success" class="ml-2" dark>Actual</v-chip>
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </template>
                    </v-data-table>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-3 grey lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn color="grey darken-1" text @click="close" class="font-weight-bold">
                        Cerrar
                    </v-btn>
                </v-card-actions>
            </v-card>

            <!-- Snackbar for success/error messages -->
            <v-snackbar
                v-model="snackbar.show"
                :color="snackbar.color"
                :timeout="3000"
                rounded="pill"
            >
                {{ snackbar.text }}
                <template v-slot:action="{ attrs }">
                    <v-btn icon v-bind="attrs" @click="snackbar.show = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </template>
            </v-snackbar>
        </v-dialog>
    `,
    props: {
        value: { type: Boolean, default: false },
        careerId: { type: Number, required: true },
        subperiodId: { type: Number, required: true },
        subperiodName: { type: String, default: '' },
        intakePeriod: { type: String, required: true },
    },
    data() {
        return {
            dialog: true,
            loading: false,
            errorMsg: '',
            students: [],
            availablePeriods: [],
            search: '',
            snackbar: {
                show: false,
                text: '',
                color: 'success',
            },
            headers: [
                { text: 'Identificación', value: 'username', align: 'start', sortable: true },
                { text: 'Nombre', value: 'fullname', sortable: true },
                { text: 'Teléfono', value: 'phone', sortable: false },
                { text: 'Estado', value: 'status', sortable: true },
                { text: 'Periodo Ingreso', value: 'intake_period', sortable: true },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'center' },
            ],
        };
    },
    computed: {
        dialogVisible: {
            get() { return this.value; },
            set(val) { this.$emit('input', val); }
        },
        wsUrl() {
            return window.location.origin + '/webservice/rest/server.php';
        },
        token() {
            return window.userToken;
        },
    },
    watch: {
        value(val) {
            if (val) {
                this.loadStudents();
            }
        }
    },
    methods: {
        async loadStudents() {
            this.loading = true;
            this.errorMsg = '';
            try {
                const response = await window.axios.get(this.wsUrl, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_get_students_by_subperiod',
                        moodlewsrestformat: 'json',
                        learningplanid: this.careerId,
                        subperiodid: this.subperiodId,
                        intake_period: this.intakePeriod,
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.errorMsg = 'Error del servidor: ' + (data.message || data.errorcode);
                    return;
                }
                this.students = data.students || [];
                this.availablePeriods = data.available_periods || [];
            } catch (e) {
                this.errorMsg = 'Error de conexión al cargar estudiantes.';
                console.error('[studentlistmodal] Error:', e);
            } finally {
                this.loading = false;
            }
        },

        async reassignPeriod(student, newPeriod) {
            if (student.intake_period === newPeriod) return;

            try {
                const response = await window.axios.post(this.wsUrl, null, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_reassign_student_intake_period',
                        moodlewsrestformat: 'json',
                        userid: student.userid,
                        new_intake_period: newPeriod,
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.showSnackbar('Error: ' + (data.message || data.errorcode), 'error');
                    return;
                }
                if (data.success) {
                    this.showSnackbar(`Estudiante reasignado a ${newPeriod}`, 'success');
                    // Update local student data
                    student.intake_period = newPeriod;
                    // Reload to get fresh data
                    await this.loadStudents();
                }
            } catch (e) {
                this.showSnackbar('Error al reasignar periodo.', 'error');
                console.error('[studentlistmodal] Reassign error:', e);
            }
        },

        showSnackbar(text, color) {
            this.snackbar.text = text;
            this.snackbar.color = color;
            this.snackbar.show = true;
        },

        close() {
            this.$emit('input', false);
            this.$emit('close');
        }
    },
});
