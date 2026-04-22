Vue.component('career-cards', {
    template: `
    <v-container fluid class="pa-4">
        <v-row align="center" class="mb-4">
            <v-col cols="12" md="6">
                <h2 class="text-h5 font-weight-bold">
                    <v-icon large class="mr-2" color="primary">mdi-chart-timeline-variant</v-icon>
                    Línea de Tiempo Estudiantes
                </h2>
                <p class="text--secondary mb-0 mt-1">Progreso académico por carrera y periodo de ingreso</p>
            </v-col>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="search"
                    append-icon="mdi-magnify"
                    label="Buscar carrera..."
                    hide-details
                    outlined
                    dense
                    clearable
                ></v-text-field>
            </v-col>
        </v-row>

        <v-row v-if="loading">
            <v-col cols="12" class="text-center py-10">
                <v-progress-circular indeterminate color="primary" size="50"></v-progress-circular>
                <p class="mt-4 text--secondary">Cargando carreras...</p>
            </v-col>
        </v-row>

        <v-row v-else-if="filteredCareers.length === 0">
            <v-col cols="12" class="text-center py-10">
                <v-icon x-large color="grey lighten-2">mdi-school-outline</v-icon>
                <p class="mt-4 text--secondary">No se encontraron carreras.</p>
            </v-col>
        </v-row>

        <v-row v-else>
            <v-col
                v-for="(career, index) in filteredCareers"
                :key="career.id"
                cols="12" sm="6" md="4"
            >
                <v-card
                    outlined
                    hover
                    class="career-card pa-2"
                    style="border-radius: 12px; transition: box-shadow .2s;"
                >
                    <v-card-text class="pb-0">
                        <v-row align="center" no-gutters>
                            <v-col cols="auto" class="mr-3">
                                <div
                                    class="career-icon-wrap"
                                    :style="{ background: iconColors[index % iconColors.length] }"
                                >
                                    <v-icon size="28" color="white">mdi-school</v-icon>
                                </div>
                            </v-col>
                            <v-col>
                                <div class="text-subtitle-1 font-weight-bold text--primary" style="line-height:1.3">
                                    {{ career.name }}
                                </div>
                                <div class="text-caption text--secondary mt-1">
                                    <v-chip x-small color="primary" outlined class="mr-1">
                                        {{ career.periodcount }} cuatrimestres
                                    </v-chip>
                                    <v-chip x-small outlined class="mr-1">
                                        {{ career.coursecount }} cursos
                                    </v-chip>
                                </div>
                            </v-col>
                        </v-row>

                        <v-divider class="my-3"></v-divider>

                        <v-row no-gutters class="text-center mb-2">
                            <v-col cols="6">
                                <div class="text-h5 font-weight-bold" :style="{ color: iconColors[index % iconColors.length] }">
                                    {{ career.active_count }}
                                </div>
                                <div class="text-caption text--secondary">Activos</div>
                            </v-col>
                            <v-col cols="6">
                                <div class="text-h5 font-weight-bold grey--text text--darken-1">
                                    {{ career.total_enrolled }}
                                </div>
                                <div class="text-caption text--secondary">Total matriculados</div>
                            </v-col>
                        </v-row>

                        <v-progress-linear
                            :value="career.total_enrolled > 0 ? (career.active_count / career.total_enrolled) * 100 : 0"
                            :color="iconColors[index % iconColors.length]"
                            background-color="grey lighten-3"
                            rounded
                            height="6"
                            class="mb-2"
                        ></v-progress-linear>
                        <div class="text-caption text--secondary text-right mb-1">
                            {{ career.total_enrolled > 0 ? Math.round((career.active_count / career.total_enrolled) * 100) : 0 }}% retención
                        </div>
                    </v-card-text>

                    <v-card-actions class="pt-0 px-4 pb-3">
                        <v-btn
                            block
                            depressed
                            :color="iconColors[index % iconColors.length]"
                            dark
                            class="rounded-lg"
                            :href="baseUrl + '?career_id=' + career.id"
                        >
                            <v-icon left small>mdi-chart-timeline-variant</v-icon>
                            Ver Línea de Tiempo
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
    `,
    props: {
        baseUrl: { type: String, default: 'student_timeline_career.php' },
        wstoken: { type: String, default: '' },
        wwwroot: { type: String, default: '' },
    },
    data() {
        return {
            search: '',
            loading: true,
            careers: [],
            iconColors: [
                '#1976D2', // azul
                '#388E3C', // verde
                '#F57C00', // naranja
                '#7B1FA2', // morado
                '#0097A7', // cyan
                '#C62828', // rojo
                '#5D4037', // café
                '#455A64', // azul-gris
            ],
        };
    },
    computed: {
        filteredCareers() {
            if (!this.search) return this.careers;
            const q = this.search.toLowerCase();
            return this.careers.filter(c => c.name.toLowerCase().includes(q));
        },
    },
    created() {
        this.loadCareers();
    },
    methods: {
        async loadCareers() {
            this.loading = true;
            try {
                const res = await axios.post(
                    this.wwwroot + '/webservice/rest/server.php',
                    new URLSearchParams({
                        wstoken: this.wstoken,
                        wsfunction: 'local_grupomakro_get_student_timeline_careers',
                        moodlewsrestformat: 'json',
                    })
                );
                if (res.data && res.data.careers) {
                    this.careers = res.data.careers;
                }
            } catch (e) {
                console.error('Error cargando carreras:', e);
            } finally {
                this.loading = false;
            }
        },
    },
});
