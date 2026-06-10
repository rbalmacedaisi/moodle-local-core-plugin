Vue.component('career-cards', {
    template: `
    <div class="tl-page">
        <!-- HERO HEADER -->
        <div class="tl-hero">
            <div class="tl-hero-inner">
                <div class="tl-hero-icon">
                    <v-icon size="32" color="white">mdi-chart-timeline-variant-shimmer</v-icon>
                </div>
                <div class="tl-hero-text">
                    <h1 class="tl-hero-title">Línea de Tiempo de Estudiantes</h1>
                    <p class="tl-hero-sub">Visualiza, reclasifica y planifica el ciclo de vida académico de cada cohorte</p>
                </div>
                <div class="tl-hero-stats" v-if="!loading && careers.length > 0">
                    <div class="tl-hero-stat">
                        <div class="tl-hero-stat-num">{{ careers.length }}</div>
                        <div class="tl-hero-stat-lbl">Carreras</div>
                    </div>
                    <div class="tl-hero-stat">
                        <div class="tl-hero-stat-num">{{ totalStudents }}</div>
                        <div class="tl-hero-stat-lbl">Estudiantes</div>
                    </div>
                    <div class="tl-hero-stat">
                        <div class="tl-hero-stat-num">{{ avgRetention }}%</div>
                        <div class="tl-hero-stat-lbl">Retención</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TOOLBAR -->
        <div class="tl-toolbar">
            <div class="tl-search">
                <v-icon size="18" color="#94A3B8">mdi-magnify</v-icon>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar carrera por nombre..."
                    class="tl-search-input"
                />
                <button v-if="search" class="tl-search-clear" @click="search = ''">
                    <v-icon size="16" color="#94A3B8">mdi-close-circle</v-icon>
                </button>
            </div>
            <div class="tl-toolbar-actions">
                <v-btn
                    small
                    depressed
                    color="#6366F1"
                    dark
                    class="tl-btn-primary"
                    @click="loadCareers"
                    :loading="loading"
                >
                    <v-icon left size="16">mdi-refresh</v-icon>
                    Actualizar
                </v-btn>
            </div>
        </div>

        <!-- ERROR -->
        <v-alert v-if="errorMsg" type="error" outlined class="tl-alert mb-4" border="left">
            <div class="d-flex align-center">
                <v-icon left color="error">mdi-alert-circle</v-icon>
                {{ errorMsg }}
            </div>
        </v-alert>

        <!-- LOADING SKELETON -->
        <div v-if="loading" class="tl-grid">
            <div v-for="n in 6" :key="n" class="tl-card tl-card-skeleton">
                <div class="tl-skel" style="height: 80px; border-radius: 12px 12px 0 0;"></div>
                <div class="pa-4">
                    <div class="tl-skel mb-2" style="height: 18px; width: 70%;"></div>
                    <div class="tl-skel mb-3" style="height: 12px; width: 50%;"></div>
                    <div class="tl-skel mb-2" style="height: 32px; width: 100%;"></div>
                    <div class="tl-skel" style="height: 36px; width: 100%; border-radius: 8px;"></div>
                </div>
            </div>
        </div>

        <!-- EMPTY -->
        <div v-else-if="filteredCareers.length === 0" class="tl-empty">
            <div class="tl-empty-icon">
                <v-icon size="64" color="#CBD5E1">mdi-school-outline</v-icon>
            </div>
            <h3 class="tl-empty-title">{{ search ? 'Sin resultados' : 'Sin carreras registradas' }}</h3>
            <p class="tl-empty-sub">{{ search ? 'Intenta con otro término de búsqueda' : 'Cuando registres carreras aparecerán aquí' }}</p>
        </div>

        <!-- GRID -->
        <div v-else class="tl-grid">
            <div
                v-for="(career, index) in filteredCareers"
                :key="career.id"
                class="tl-card"
                :style="{ '--accent': palette[index % palette.length] }"
            >
                <div class="tl-card-accent"></div>
                <div class="tl-card-body">
                    <div class="tl-card-head">
                        <div class="tl-card-icon">
                            <v-icon size="22" color="white">mdi-school</v-icon>
                        </div>
                        <div class="tl-card-title-wrap">
                            <h3 class="tl-card-title">{{ career.name }}</h3>
                            <div class="tl-card-meta">
                                <span class="tl-meta-chip">
                                    <v-icon size="12" color="#6366F1">mdi-calendar-multiple</v-icon>
                                    {{ career.periodcount }} cuatrimestres
                                </span>
                                <span class="tl-meta-chip">
                                    <v-icon size="12" color="#6366F1">mdi-book-open-variant</v-icon>
                                    {{ career.coursecount }} cursos
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="tl-card-stats">
                        <div class="tl-stat">
                            <div class="tl-stat-num tl-stat-active">{{ career.active_count }}</div>
                            <div class="tl-stat-lbl">Activos</div>
                        </div>
                        <div class="tl-stat-divider"></div>
                        <div class="tl-stat">
                            <div class="tl-stat-num tl-stat-total">{{ career.total_enrolled }}</div>
                            <div class="tl-stat-lbl">Total</div>
                        </div>
                        <div class="tl-stat-divider"></div>
                        <div class="tl-stat">
                            <div class="tl-stat-num" :class="retentionClass(career)">
                                {{ career.total_enrolled > 0 ? Math.round((career.active_count / career.total_enrolled) * 100) : 0 }}%
                            </div>
                            <div class="tl-stat-lbl">Retención</div>
                        </div>
                    </div>

                    <div class="tl-progress">
                        <div
                            class="tl-progress-fill"
                            :style="{
                                width: (career.total_enrolled > 0 ? (career.active_count / career.total_enrolled) * 100 : 0) + '%',
                                background: retentionGradient(career)
                            }"
                        ></div>
                    </div>
                </div>

                <a
                    :href="careerPageUrl + '?career_id=' + career.id"
                    class="tl-card-action"
                >
                    <span>Ver línea de tiempo</span>
                    <v-icon size="16">mdi-arrow-right</v-icon>
                </a>
            </div>
        </div>
    </div>
    `,
    props: {
        careerPageUrl: { type: String, default: '' },
    },
    data() {
        return {
            search: '',
            loading: true,
            errorMsg: '',
            careers: [],
            palette: [
                '#6366F1', // indigo
                '#8B5CF6', // violet
                '#EC4899', // pink
                '#F59E0B', // amber
                '#10B981', // emerald
                '#06B6D4', // cyan
                '#F43F5E', // rose
                '#3B82F6', // blue
            ],
        };
    },
    computed: {
        filteredCareers() {
            if (!this.search) return this.careers;
            const q = this.search.toLowerCase();
            return this.careers.filter(c => c.name.toLowerCase().includes(q));
        },
        totalStudents() {
            return this.careers.reduce((sum, c) => sum + (c.active_count || 0), 0);
        },
        avgRetention() {
            if (!this.careers.length) return 0;
            const total = this.careers.reduce((sum, c) => {
                return sum + (c.total_enrolled > 0 ? (c.active_count / c.total_enrolled) * 100 : 0);
            }, 0);
            return Math.round(total / this.careers.length);
        },
        wsUrl() {
            return window.location.origin + '/webservice/rest/server.php';
        },
        token() {
            return window.userToken;
        },
    },
    created() {
        this.loadCareers();
    },
    methods: {
        async loadCareers() {
            this.loading = true;
            this.errorMsg = '';
            try {
                const response = await window.axios.get(this.wsUrl, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_get_student_timeline_careers',
                        moodlewsrestformat: 'json',
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.errorMsg = data.message || data.errorcode || 'Error desconocido';
                    return;
                }
                if (data && data.careers) {
                    this.careers = data.careers;
                }
            } catch (e) {
                this.errorMsg = 'Error de conexión al cargar las carreras.';
                console.error('[timeline] Error:', e);
            } finally {
                this.loading = false;
            }
        },
        retentionClass(career) {
            if (career.total_enrolled === 0) return 'tl-stat-muted';
            const pct = (career.active_count / career.total_enrolled) * 100;
            if (pct >= 70) return 'tl-stat-success';
            if (pct >= 40) return 'tl-stat-warning';
            return 'tl-stat-error';
        },
        retentionGradient(career) {
            if (career.total_enrolled === 0) return '#CBD5E1';
            const pct = (career.active_count / career.total_enrolled) * 100;
            if (pct >= 70) return 'linear-gradient(90deg, #10B981 0%, #34D399 100%)';
            if (pct >= 40) return 'linear-gradient(90deg, #F59E0B 0%, #FBBF24 100%)';
            return 'linear-gradient(90deg, #EF4444 0%, #F87171 100%)';
        },
    },
});
