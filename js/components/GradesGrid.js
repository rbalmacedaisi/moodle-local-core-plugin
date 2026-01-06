Vue.component('grades-grid', {
    template: `
        <v-card flat>
            <v-card-text>
                <div v-if="loading" class="text-center pa-5">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <p>Cargando notas...</p>
                </div>
                
                <div v-else-if="error" class="red--text text-center">
                    {{ error }}
                    <v-btn text color="primary" @click="fetchGrades">Reintentar</v-btn>
                </div>

                <v-data-table
                    v-else
                    :headers="headers"
                    :items="students"
                    class="elevation-1"
                    :items-per-page="-1"
                    hide-default-footer
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>Libro de Calificaciones</v-toolbar-title>
                            <v-spacer></v-spacer>
                            <v-btn icon @click="fetchGrades">
                                <v-icon>mdi-refresh</v-icon>
                            </v-btn>
                        </v-toolbar>
                    </template>

                    <!-- Student Name Slot -->
                    <template v-slot:item.fullname="{ item }">
                        <div class="d-flex align-center">
                            <v-avatar size="32" :color="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-2'" class="mr-2">
                                <span class="white--text text-caption">{{ item.fullname.charAt(0) }}</span>
                            </v-avatar>
                            <div>
                                <div class="font-weight-bold">{{ item.fullname }}</div>
                                <div class="caption grey--text">{{ item.email }}</div>
                            </div>
                        </div>
                    </template>

                    <!-- Dynamic Grade Slots -->
                    <template v-for="col in gradeColumns" v-slot:[getSlotName(col.id)]="{ item }">
                        <div :key="col.id" class="text-center" :class="getGradeColor(item.grades[col.id])">
                            {{ formatGrade(item.grades[col.id]) }}
                        </div>
                    </template>
                </v-data-table>
            </v-card-text>
        </v-card>
    `,
    props: {
        classId: {
            type: [Number, String],
            required: true
        }
    },
    data() {
        return {
            loading: false,
            error: null,
            students: [],
            columns: []
        };
    },
    computed: {
        headers() {
            // Static headers (Student info)
            const staticHeaders = [
                { text: 'Estudiante', value: 'fullname', fixed: true, width: '250px' }
            ];

            // Dynamic headers (Grade items)
            const dynamicHeaders = this.columns.map(col => ({
                text: `${col.title} (${parseFloat(col.max_grade).toString()})`,
                value: `grades.${col.id}`,
                align: 'center',
                sortable: false
            }));

            return [...staticHeaders, ...dynamicHeaders];
        },
        gradeColumns() {
            return this.columns;
        }
    },
    watch: {
        classId: {
            immediate: true,
            handler(newVal) {
                if (newVal) {
                    this.fetchGrades();
                }
            }
        }
    },
    methods: {
        getSlotName(id) {
            return `item.grades.${id}`;
        },
        async fetchGrades() {
            this.loading = true;
            this.error = null;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_class_grades',
                    args: {
                        classid: this.classId
                    },
                    sesskey: window.Y.config.sesskey
                });

                if (response.data && response.data.status === 'success') {
                    this.columns = response.data.data.columns;
                    this.students = response.data.data.students;
                } else {
                    throw new Error(response.data.message || 'Error al cargar calificaciones');
                }
            } catch (err) {
                console.error(err);
                this.error = 'No se pudieron cargar las notas.';
            } finally {
                this.loading = false;
            }
        },
        formatGrade(grade) {
            if (grade === '-' || grade === null || grade === undefined) return '-';
            return parseFloat(grade).toFixed(1); // Standard decimal formatting
        },
        getGradeColor(grade) {
            if (grade === '-' || grade === null || grade === undefined) return '';
            const val = parseFloat(grade);
            if (val < 71) return 'red--text font-weight-bold'; // Failure threshold (example)
            return this.$vuetify.theme.dark ? 'white--text' : 'black--text';
        }
    }
});
