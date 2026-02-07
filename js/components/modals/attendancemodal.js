Vue.component('attendancemodal', {
    template: `
        <v-dialog v-model="dialog" max-width="700" persistent>
            <v-card class="rounded-lg overflow-hidden">
                <v-card-title class="headline error white--text d-flex align-center py-3 px-4">
                    <v-icon left color="white">mdi-calendar-check</v-icon>
                    <span>Asistencia Detallada: {{ studentname }}</span>
                    <v-spacer></v-spacer>
                    <v-btn icon dark @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pa-0">
                    <v-progress-linear
                        v-if="loading"
                        indeterminate
                        color="error"
                    ></v-progress-linear>

                    <div v-if="!loading && details.length === 0" class="pa-10 text-center grey--text">
                        <v-icon large color="grey lighten-2">mdi-calendar-blank</v-icon>
                        <div class="mt-2">No se encontraron sesiones de asistencia registradas para esta clase.</div>
                    </div>

                    <v-simple-table v-if="!loading && details.length > 0" fixed-header height="450px" dense>
                        <template v-slot:default>
                            <thead>
                                <tr>
                                    <th class="text-left py-3">Sesión / Fecha</th>
                                    <th class="text-center py-3">Estado</th>
                                    <th class="text-left py-3">Descripción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="session in details" :key="session.id" :class="{'grey lighten-4': session.is_absence}">
                                    <td class="py-2">
                                        <div class="font-weight-bold text-body-2">{{ session.date }}</div>
                                        <div class="caption grey--text">{{ session.time }}</div>
                                    </td>
                                    <td class="text-center">
                                        <v-chip 
                                            small 
                                            :color="getStatusColor(session)" 
                                            :dark="!!getStatusColor(session)"
                                            class="font-weight-bold text-caption"
                                            label
                                        >
                                            {{ session.status }}
                                        </v-chip>
                                    </td>
                                    <td class="text-body-2 py-2">
                                        {{ session.description || '--' }}
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                    </v-simple-table>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-3 grey lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn color="grey darken-1" text @click="close" class="font-weight-bold">
                        Cerrar
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props: {
        userid: { type: [Number, String], required: true },
        classid: { type: [Number, String], required: true },
        studentname: { type: String, default: '' }
    },
    data() {
        return {
            dialog: true,
            loading: true,
            details: []
        };
    },
    created() {
        this.fetchAttendanceDetails();
    },
    methods: {
        async fetchAttendanceDetails() {
            this.loading = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_student_attendance_details');
                params.append('sesskey', M.cfg.sesskey);
                params.append('userId', this.userid);
                params.append('classId', this.classid);

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params
                });

                const res = await response.json();
                if (res.status === 'success') {
                    this.details = res.details;
                } else {
                    console.error('Error fetching attendance details:', res.message);
                }
            } catch (error) {
                console.error('Attendance fetch error:', error);
            } finally {
                this.loading = false;
            }
        },
        getStatusColor(session) {
            if (session.is_absence) return 'error';
            if (session.status === 'Sin registrar') return 'grey lighten-2';
            if (session.grade > 0) return 'success';
            return 'warning';
        },
        close() {
            this.$emit('close');
        }
    }
});
