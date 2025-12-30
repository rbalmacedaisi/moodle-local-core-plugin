Vue.component('studenttable', {
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="mb-4">
                 <v-card class="pa-4 d-flex align-center" outlined style="border-left: 5px solid #4CAF50;">
                    <div>
                        <div class="text-overline mb-0">Estudiantes Activos</div>
                        <div class="text-h4 font-weight-bold success--text">{{ activeUsers }}</div>
                    </div>
                    <v-spacer></v-spacer>
                    <v-icon size="48" color="success" class=" opacity-50">mdi-account-check</v-icon>
                 </v-card>
            </v-col>
            <v-col cols="12" class="py-0 px-0">
                <v-data-table
                    :headers="headers"
                    :items="students"
                    :options.sync="options"
                    :server-items-length="totalDesserts"
                    :loading="loading"
                    class="elevation-1"
                    :footer-props="{ 
                        'items-per-page-text': lang.students_per_page,
                        'items-per-page-options': [15],
                    }"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{ lang.students_list }}</v-toolbar-title>
                        </v-toolbar>
                        
                        <v-row justify="space-between" class="ma-0 mr-3 mb-2 align-center">
                            <v-col cols="4">
                                <v-text-field
                                   v-model="options.search"
                                   append-icon="mdi-magnify"
                                   :label="lang.search"
                                   hide-details
                                   outlined
                                   dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="auto" class="d-flex" style="gap: 8px;">
                                <v-btn v-if="isAdmin" color="secondary" @click="syncProgress" :loading="syncing" :disabled="syncing">
                                    <v-icon left>mdi-sync</v-icon>
                                    Sincronizar Progreso
                                </v-btn>
                                <v-btn v-if="isSuperAdmin" color="warning" @click="syncMigratedPeriods" :loading="syncing" :disabled="syncing">
                                    <v-icon left>mdi-account-arrow-right</v-icon>
                                    Asignar Periodos (Migrados)
                                </v-btn>
                                <v-btn color="primary" @click="openFilterDialog">
                                    <v-icon left>mdi-filter-variant</v-icon>
                                    Filtros y Exportar
                                </v-btn>
                            </v-col>
                        </v-row>
                        
                        <!-- Filter Dialog -->
                        <v-dialog v-model="filterDialog" max-width="500px">
                            <v-card>
                                <v-card-title class="headline grey lighten-2">
                                    Filtros Avanzados
                                </v-card-title>
                                <v-card-text class="pt-4">
                                    <v-select
                                        v-model="filters.planid"
                                        :items="plans"
                                        item-text="name"
                                        item-value="id"
                                        label="Carrera"
                                        outlined
                                        dense
                                        clearable
                                        @change="onPlanChange"
                                    ></v-select>
                                    <v-select
                                        v-model="filters.periodid"
                                        :items="availablePeriods"
                                        item-text="name"
                                        item-value="id"
                                        label="Cuatrimestre"
                                        outlined
                                        dense
                                        clearable
                                        :disabled="!filters.planid"
                                        :loading="loadingPeriods"
                                    ></v-select>
                                    <v-select
                                        v-model="filters.status"
                                        :items="['Activo', 'Inactivo', 'Suspendido', 'Graduado', 'Egreso']"
                                        label="Estado Estudiante"
                                        outlined
                                        dense
                                        clearable
                                    ></v-select>
                                </v-card-text>
                                <v-divider></v-divider>
                                <v-card-actions class="pa-4">
                                    <v-btn color="success" block class="mb-2" @click="exportConsolidatedGrades">
                                        <v-icon left>mdi-file-excel</v-icon>
                                        Exportar Notas Consolidadas
                                    </v-btn>
                                    <v-spacer></v-spacer>
                                </v-card-actions>
                                <v-card-actions class="pa-4 pt-0">
                                    <v-btn color="primary" @click="applyFilters">Aplicar a la Tabla</v-btn>
                                    <v-btn text @click="filterDialog = false">Cerrar</v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                        <v-row v-if="syncing || syncLog" class="ma-0 px-3 pb-2">
                             <v-col cols="12">
                                <v-alert dense outlined type="info" class="text-caption mb-0" style="white-space: pre-wrap; font-family: monospace; max-height: 150px; overflow-y: auto;">
                                    {{ syncLog }}
                                </v-alert>
                             </v-col>
                        </v-row>
                    </template>
                    
                    <template v-slot:item.name="{ item }">
                        <v-list class="transparent">
                            <v-list-item>
                                <v-list-item-avatar>
                                    <img
                                      :src="item.img"
                                      alt="picture-profile"
                                    >
                                </v-list-item-avatar>
    
                                <v-list-item-content>
                                    <v-list-item-title>{{item.name}}</v-list-item-title>
                                    <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.carrers="{ item }">
                        <v-list dense class="transparent">
                            <v-list-item v-for="(carrer, index) in item.carrers" :key="index" class="px-0">
                                <v-list-item-content class="py-0">
                                    <v-list-item-subtitle>{{carrer.career}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.periods="{ item }">
                        <v-list dense class="transparent">
                            <v-list-item v-for="(carrer, index) in item.carrers" :key="index" class="px-0">
                                <v-list-item-content class="py-0">
                                    <v-menu offset-y v-if="isAdmin" @input="(val) => val && loadPeriodsForPlan(item, carrer)">
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-btn text x-small class="px-0 text-none" v-bind="attrs" v-on="on" :loading="item.updatingPeriod === carrer.planid">
                                                {{ carrer.periodname }}
                                                <v-icon small right>mdi-chevron-down</v-icon>
                                            </v-btn>
                                        </template>
                                        <v-list dense max-height="300" class="overflow-y-auto">
                                            <v-list-item v-if="!carrer.availablePeriods">
                                                <v-list-item-title class="caption text-center gray--text">Cargando...</v-list-item-title>
                                            </v-list-item>
                                            <v-list-item v-for="p in carrer.availablePeriods" :key="p.id" @click="updateStudentPeriod(item, carrer, p)">
                                                <v-list-item-title :class="{'primary--text font-weight-bold': p.id == carrer.periodid}">{{ p.name }}</v-list-item-title>
                                            </v-list-item>
                                        </v-list>
                                    </v-menu>
                                    <v-list-item-subtitle v-else>{{ carrer.periodname }}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>

                    <template v-slot:item.subperiods="{ item }">
                        <div class="text-no-wrap font-weight-regular text-body-2">{{ item.subperiods || '--' }}</div>
                    </template>
                    
                    <template v-slot:item.revalidate="{ item }">
                        <revalidatestudents :studentsData="item"></revalidatestudents>
                    </template>
                    
                    <template v-slot:item.status="{ item }">
                        <v-chip :color="getColor(item.status)" dark small label class="text-uppercase text-caption font-weight-bold" style="letter-spacing: 0.05em !important;">
                             {{ item.status }}
                        </v-chip>
                    </template>
                    
                    <!-- NEW: Custom slot for ID Only -->
                    <template v-slot:item.documentnumber="{ item }">
                        <div v-if="item.documentnumber" class="text-body-2 font-weight-medium">
                            <v-icon small left class="mr-1">mdi-card-account-details</v-icon>
                            {{ item.documentnumber }}
                        </div>
                        <div class="caption text--disabled font-italic" v-else>
                            <v-icon small left class="mr-1">mdi-alert-circle-outline</v-icon>
                           (Sin ID)
                        </div>
                    </template>

                    <template v-slot:item.grade="{ item }">
                        <v-btn small color="primary" class="elevation-0 text-capitalize font-weight-bold" @click="gradeDialog(item)">
                             notas
                        </v-btn>
                    </template>
                
                    <template v-slot:no-data>
                        <v-btn text>{{ lang.there_no_data }}</v-btn>
                    </template>
                </v-data-table>
            </v-col>
            <grademodal v-if="studentsGrades"  :dataStudent="studentGradeSelected" @close-dialog="closeDialog"></grademodal>
            
        </v-row>
    `,
    data() {
        return {
            headers: [
                {
                    text: window.strings.name,
                    align: 'start',
                    sortable: false,
                    value: 'name',
                    width: '250px' // Ensure name has space
                },
                {
                    text: 'Identificación',
                    value: 'documentnumber',
                    sortable: false,
                    width: '150px'
                },
                {
                    text: window.strings.careers,
                    sortable: false,
                    value: 'carrers',
                },
                { text: window.strings.quarters, value: 'periods', sortable: false, width: '200px' },
                { text: 'Bloque', value: 'subperiods', sortable: false, width: '200px' },
                { text: window.strings.revalidation, value: 'revalidate', sortable: false, align: 'center', },
                { text: window.strings.state, value: 'status', sortable: false, },
                { text: 'Calificaciones', value: 'grade', sortable: false, },
            ],
            totalDesserts: 0,
            activeUsers: 0,
            syncing: false,
            syncLog: '',
            loading: true,
            options: {
                page: 1,
                itemsPerPage: 15,
                search: '',
            },
            students: [],
            studentsGrades: false,
            studentGradeSelected: {},

            // New Filter properties
            filterDialog: false,
            loadingPeriods: false,
            plans: [],
            availablePeriods: [],
            filters: {
                planid: null,
                periodid: null,
                status: null
            }
        }
    },
    created() {
        console.log('StudentTable Component Created');
    },
    watch: {
        options: {
            handler() {
                this.getDataFromApi()
            },
            deep: true,
        },
    },
    methods: {
        async getDataFromApi() {
            this.loading = true;
            try {
                const url = this.siteUrl;
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_get_student_info',
                    page: this.options.page,
                    resultsperpage: this.options.itemsPerPage,
                    search: this.options.search,
                    planid: this.filters.planid || 0,
                    periodid: this.filters.periodid || 0,
                    status: this.filters.status || '',
                };
                const response = await window.axios.get(url, { params });
                const data = JSON.parse(response.data.dataUsers);
                this.totalDesserts = response.data.totalResults
                this.activeUsers = response.data.activeUsers || 0;
                this.students = [];
                data.forEach((element) => {
                    this.students.push({
                        name: element.nameuser,
                        email: element.email,
                        id: element.userid,
                        documentnumber: element.documentnumber,
                        carrers: element.careers,
                        subperiods: element.subperiods, // Mapped Bloque
                        updatingPeriod: null,
                        revalidate: element.revalidate.length > 0 ? element.revalidate : '--',
                        status: element.status,
                        img: element.profileimage
                    });
                });
            } catch (error) {
                console.error("Error fetching student information:", error);
            } finally {
                this.loading = false;
            }
        },
        getChipStyle(item) {
            const theme = this.$vuetify.theme.dark ? "dark" : "light";
            const themeColors = {
                BgChip1: "#b5e8b8", TextChip1: "#143f34",
                BgChip2: "#F8F0E5", TextChip2: "#D1A55A",
                BgChip3: "#E8EAF6", TextChip3: "#3F51B4",
                BgChip4: "#F3BFBF", TextChip4: "#8F130A",
                BgChip5: "#B9C5D5", TextChip5: "#2F445E",
            };
            if (item.status === "Activo") return { background: themeColors.BgChip1, color: themeColors.TextChip1 };
            else if (item.status === "Inactivo") return { background: themeColors.BgChip2, color: themeColors.TextChip2 };
            else if (item.status === "Reingreso") return { background: themeColors.BgChip3, color: themeColors.TextChip3 };
            else if (item.status === "Suspendido") return { background: themeColors.BgChip4, color: themeColors.TextChip4 };
            return { background: themeColors.BgChip5, color: themeColors.TextChip5 };
        },
        gradeDialog(item) {
            this.studentsGrades = true;
            this.studentGradeSelected = item;
        },
        closeDialog() {
            this.studentsGrades = false;
            this.studentGradeSelected = {};
        },
        async openFilterDialog() {
            this.filterDialog = true;
            if (this.plans.length === 0) {
                try {
                    const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_get_plans`);
                    if (response.data.status === 'success') {
                        this.plans = response.data.plans;
                    }
                } catch (e) {
                    console.error("Error loading plans:", e);
                }
            }
        },
        async onPlanChange() {
            this.filters.periodid = null;
            this.availablePeriods = [];
            if (!this.filters.planid) return;

            this.loadingPeriods = true;
            try {
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_get_periods&planid=${this.filters.planid}`);
                if (response.data.status === 'success') {
                    this.availablePeriods = response.data.periods;
                }
            } catch (e) {
                console.error("Error loading periods:", e);
            } finally {
                this.loadingPeriods = false;
            }
        },
        applyFilters() {
            this.options.page = 1;
            this.getDataFromApi();
            this.filterDialog = false;
        },
        exportConsolidatedGrades() {
            let url = `${M.cfg.wwwroot}/local/grupomakro_core/pages/export_consolidated_grades.php?`;
            if (this.filters.planid) url += `planid=${this.filters.planid}&`;
            if (this.filters.periodid) url += `periodid=${this.filters.periodid}&`;
            if (this.filters.status) url += `status=${this.filters.status}&`;
            window.open(url, '_blank');
        },
        exportStudents() {
            window.open(window.location.origin + '/local/grupomakro_core/pages/export_students.php', '_blank');
        },
        getColor(status) {
            status = status ? status.toLowerCase() : '';
            if (status === 'activo') return 'success';
            if (status === 'inactivo' || status === 'suspendido' || status === 'retirado') return 'error';
            if (status === 'graduado' || status === 'egresado') return 'primary';
            return 'grey';
        },
        async pollLog(interval = 3000) {
            try {
                const logRes = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=get_sync_log`);
                if (logRes.data.status === 'success') {
                    this.syncLog = logRes.data.log;
                }
            } catch (e) {
                console.error('Error polling log:', e);
            }
        },
        async syncProgress() {
            this.syncing = true;
            this.syncLog = 'Iniciando...';
            const logInterval = setInterval(() => this.pollLog(), 3000);

            try {
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_sync_progress`);
                if (response.data.status === 'success') {
                    await this.getDataFromApi();
                    alert('Sincronización completada. ' + response.data.count + ' registros.');
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Error de ejecución.');
            } finally {
                clearInterval(logInterval);
                this.syncing = false;
                await this.pollLog();
            }
        },
        async syncMigratedPeriods() {
            if (!confirm('Esta acción recalculará los periodos de TODOS los estudiantes migrados basándose en el conteo de materias aprobadas. ¿Continuar?')) return;

            this.syncing = true;
            this.syncLog = 'Iniciando sincronización por bloques...';
            const logInterval = setInterval(() => this.pollLog(), 3000);

            let offset = 0;
            let finished = false;

            const syncBatch = async () => {
                try {
                    const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_sync_migrated_periods&offset=${offset}`);
                    if (response.data.status === 'success') {
                        offset = response.data.offset;
                        finished = response.data.finished;
                        if (!finished) {
                            await syncBatch(); // Regresive call for next batch
                        }
                    } else {
                        throw new Error(response.data.message || 'Error en bloque');
                    }
                } catch (error) {
                    console.error('Batch error:', error);
                    alert('Error en el proceso de sincronización: ' + error.message);
                    finished = true;
                }
            };

            try {
                await syncBatch();
                if (finished) {
                    alert('Sincronización completada con éxito.');
                    await this.getDataFromApi();
                }
            } catch (error) {
                console.error(error);
            } finally {
                clearInterval(logInterval);
                this.syncing = false;
                await this.pollLog();
            }
        },
        async loadPeriodsForPlan(student, carrer) {
            if (carrer.availablePeriods) return;
            try {
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_get_periods&planid=${carrer.planid}`);
                if (response.data.status === 'success') {
                    this.$set(carrer, 'availablePeriods', response.data.periods);
                }
            } catch (error) {
                console.error(error);
            }
        },
        async updateStudentPeriod(student, carrer, period) {
            if (carrer.periodid == period.id) return;
            if (!confirm(`¿Cambiar al estudiante al periodo: ${period.name}?`)) return;

            student.updatingPeriod = carrer.planid;
            try {
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_update_period&userid=${student.id}&planid=${carrer.planid}&periodid=${period.id}`);
                if (response.data.status === 'success') {
                    carrer.periodid = period.id;
                    carrer.periodname = period.name;
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('No se pudo actualizar el periodo.');
            } finally {
                student.updatingPeriod = null;
            }
        },
    },
    computed: {
        siteUrl() { return window.location.origin + '/webservice/rest/server.php' },
        lang() { return window.strings },
        token() { return window.userToken; },
        isAdmin() { return window.isAdmin || false; },
        isSuperAdmin() { return window.isSuperAdmin || false; },
    },
})