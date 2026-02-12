Vue.component('studenttable', {
    props: ['classId'],
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
                                <v-btn v-if="isSuperAdmin" color="purple" dark @click="syncFinancialBulk" :loading="syncing" :disabled="syncing">
                                    <v-icon left>mdi-cash-sync</v-icon>
                                    Actualizar Financiero (Lote)
                                </v-btn>
                                <v-btn v-if="isSuperAdmin" color="info" dark @click="openPeriodModal">
                                    <v-icon left>mdi-calendar-sync</v-icon>
                                    Gestión Periodos
                                </v-btn>
                                <v-btn color="primary" @click="openFilterDialog">
                                    <v-icon left>mdi-filter-variant</v-icon>
                                    Filtros y Exportar
                                </v-btn>
                            </v-col>
                        </v-row>
                        


                        <!-- Period Management Dialog -->
                        <v-dialog v-model="periodModal" max-width="700px">
                            <v-card>
                                <v-card-title class="headline grey lighten-2">
                                    Gestión Masiva de Periodos
                                </v-card-title>
                                <v-card-text class="pt-4">
                                    <v-row>
                                        <v-col cols="12">
                                            <v-alert type="info" dense text>
                                                Utilice esta herramienta para actualizar el bimestre (periodo) de los estudiantes masivamente.
                                                <br>1. Exporte la plantilla (se aplican los filtros actuales).
                                                <br>2. Modifique la columna 'Bloque'.
                                                <br>3. Importe el archivo modificado.
                                            </v-alert>
                                        </v-col>
                                        <v-col cols="12" class="text-center">
                                            <v-btn color="primary" outlined @click="exportPeriodTemplate">
                                                <v-icon left>mdi-download</v-icon>
                                                Descargar Plantilla Excel
                                            </v-btn>
                                        </v-col>
                                        <v-col cols="12">
                                            <v-divider class="my-3"></v-divider>
                                            <div class="text-subtitle-1 mb-2">Importar Actualización (Excel)</div>
                                            <v-file-input
                                                v-model="periodImportFile"
                                                accept=".xlsx, .xls"
                                                label="Seleccionar archivo Excel modificado"
                                                outlined
                                                dense
                                            ></v-file-input>
                                        </v-col>
                                    </v-row>
                                    
                                    <v-expand-transition>
                                        <div v-if="periodImportLog">
                                            <v-alert type="info" outlined class="text-caption" style="white-space: pre-wrap; font-family: monospace; max-height: 200px; overflow-y: auto;">
                                                {{ periodImportLog }}
                                            </v-alert>
                                        </div>
                                    </v-expand-transition>

                                </v-card-text>
                                <v-divider></v-divider>
                                <v-card-actions class="pa-4">
                                    <v-btn text @click="periodModal = false">Cerrar</v-btn>
                                    <v-spacer></v-spacer>
                                    <v-btn color="success" @click="importPeriodFile" :disabled="!periodImportFile || syncing" :loading="syncing">
                                        <v-icon left>mdi-upload</v-icon>
                                        Procesar Importación
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                        
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
                                        label="Carreras"
                                        outlined
                                        dense
                                        clearable
                                        multiple
                                        chips
                                        deletable-chips
                                        @change="onPlanChange"
                                    ></v-select>
                                    <v-select
                                        v-model="filters.periodid"
                                        :items="availablePeriods"
                                        item-text="name"
                                        item-value="id"
                                        label="Cuatrimestres"
                                        outlined
                                        dense
                                        clearable
                                        multiple
                                        chips
                                        deletable-chips
                                        :disabled="!filters.planid || filters.planid.length === 0"
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
                                    <v-select
                                        v-if="isAdmin"
                                        v-model="filters.financialStatus"
                                        :items="['al_dia', 'mora', 'becado', 'convenio', 'sin_contrato_o_usuario']"
                                        label="Estado Financiero"
                                        outlined
                                        dense
                                        clearable
                                    ></v-select>
                                    <v-switch
                                        v-model="filters.withGrades"
                                        label="Incluir Notas en Exportación"
                                        color="success"
                                        dense
                                        hide-details
                                    ></v-switch>
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
                         <div class="d-flex align-center py-2">
                             <v-avatar size="40" class="mr-3" style="cursor: pointer;" @click="goToProfile(item.id)">
                                 <img :src="item.img" alt="profile">
                             </v-avatar>
                             <div>
                                 <div class="font-weight-medium text-body-2">{{ item.name }}</div>
                                 <div class="caption grey--text">{{ item.email }}</div>
                             </div>
                         </div>
                    </template>
                    
                    <template v-slot:item.carrers="{ item }">
                        <div class="py-1">
                            <div v-for="(carrer, index) in item.carrers" :key="index" class="mb-1">
                                <span class="caption font-weight-bold d-block grey--text text--darken-2" style="line-height: 1.2;">
                                    {{ carrer.career }}
                                </span>
                            </div>
                        </div>
                    </template>
                    
                    <template v-slot:item.periods="{ item }">
                        <div class="py-1">
                             <div v-for="(carrer, index) in item.carrers" :key="index" class="mb-1">
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
                                <span v-else class="text-body-2">{{ carrer.periodname }}</span>
                             </div>
                        </div>
                    </template>

                    <template v-slot:item.subperiods="{ item }">
                        <div class="py-1">
                             <div v-for="(carrer, index) in item.carrers" :key="index" class="mb-1">
                                <v-menu offset-y v-if="isAdmin" @input="(val) => val && loadSubperiodsForPlan(item, carrer)">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn text x-small class="px-0 text-none" v-bind="attrs" v-on="on" :loading="item.updatingSubperiod === carrer.planid">
                                            {{ carrer.subperiodname || item.subperiods || '--' }}
                                            <v-icon small right>mdi-chevron-down</v-icon>
                                        </v-btn>
                                    </template>
                                    <v-list dense max-height="300" class="overflow-y-auto">
                                        <v-list-item v-if="!carrer.availableSubperiods">
                                            <v-list-item-title class="caption text-center gray--text">Cargando...</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item v-for="sp in (carrer.availableSubperiods || []).filter(s => s.periodid == carrer.periodid)" :key="sp.id" @click="updateStudentSubperiod(item, carrer, sp)">
                                            <v-list-item-title :class="{'primary--text font-weight-bold': sp.name == (carrer.subperiodname || item.subperiods)}">
                                                {{ sp.name }}
                                            </v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                                <span v-else class="text-body-2">{{ carrer.subperiodname || item.subperiods || '--' }}</span>
                             </div>
                        </div>
                    </template>

                    <template v-slot:item.academic_period="{ item }">
                        <div class="py-1">
                             <div v-for="(carrer, index) in item.carrers" :key="index" class="mb-1">
                                <v-menu offset-y v-if="isAdmin" @input="(val) => val && loadAcademicPeriods()">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn text x-small class="px-0 text-none" v-bind="attrs" v-on="on" :loading="item.updatingAcademicPeriod">
                                            {{ item.academicperiodname }}
                                            <v-icon small right>mdi-chevron-down</v-icon>
                                        </v-btn>
                                    </template>
                                    <v-list dense max-height="300" class="overflow-y-auto">
                                        <v-list-item v-if="loadingAcademicPeriods">
                                            <v-list-item-title class="caption text-center gray--text">Cargando...</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item v-for="ap in allAcademicPeriods" :key="ap.id" @click="updateStudentAcademicPeriod(item, carrer, ap)">
                                            <v-list-item-title :class="{'primary--text font-weight-bold': ap.id == item.academicperiodid}">
                                                {{ ap.name }}   
                                                <small v-if="ap.status == 1" class="success--text ml-1">(Activo)</small>
                                            </v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                                <span v-else class="text-body-2">{{ item.academicperiodname }}</span>
                             </div>
                        </div>
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

                    <template v-slot:item.financial_status="{ item }">
                         <div class="d-flex align-center">
                            <v-tooltip bottom v-if="item.financial_reason">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-chip v-bind="attrs" v-on="on" :color="getFinancialColor(item.financial_status)" dark small label 
                                        class="text-uppercase text-caption font-weight-bold mr-2"
                                        style="letter-spacing: 0.05em !important;"
                                    >
                                         {{ item.financial_status === 'none' ? 'PENDIENTE' : item.financial_status }}
                                    </v-chip>
                                </template>
                                <span>{{ item.financial_reason }}</span>
                            </v-tooltip>
                            <v-chip v-else :color="getFinancialColor(item.financial_status)" dark small label 
                                class="text-uppercase text-caption font-weight-bold mr-2"
                                style="letter-spacing: 0.05em !important;"
                            >
                                 {{ item.financial_status === 'none' ? 'PENDIENTE' : item.financial_status }}
                            </v-chip>

                            <v-btn icon small color="primary" @click="updateFinancialStatus(item)" :loading="item.updatingFinancial">
                                <v-icon small>mdi-refresh</v-icon>
                            </v-btn>
                         </div>
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
            plans: [], // Initialized
            availablePeriods: [],
            loadingPeriods: false,
            filters: {
                planid: [],
                periodid: [],
                status: '',
                financialStatus: '',
                withGrades: false
            },
            careers: [],
            quarters: [],
            statusFilter: '',
            careerFilter: '',
            quarterFilter: '',
            filterDialog: false,
            studentsGrades: false,
            studentGradeSelected: {},
            periodModal: false,
            periodImportFile: null,
            periodImportLog: '',
            allAcademicPeriods: [], // Global list
            loadingAcademicPeriods: false,
        };
    },
    computed: {
        siteUrl() { return window.location.origin + '/webservice/rest/server.php' },
        lang() { return window.strings || {} },
        token() { return window.userToken; },
        isAdmin() { return window.isAdmin || false; },
        isSuperAdmin() { return window.isSuperAdmin || false; },
        headers() {
            const lang = this.lang;
            const headers = [
                {
                    text: lang.name || 'Nombre',
                    align: 'start',
                    sortable: false,
                    value: 'name',
                    width: '250px'
                },
                {
                    text: lang.document || 'Identificación',
                    value: 'documentnumber',
                    sortable: false,
                    width: '150px'
                },
                {
                    text: lang.career || 'Carrera',
                    sortable: false,
                    value: 'carrers',
                },
                { text: lang.period || 'Nivel', value: 'periods', sortable: false, width: '150px' },
                { text: 'Bloque', value: 'subperiods', sortable: false, width: '150px' },
                { text: 'Periodo Lectivo', value: 'academic_period', sortable: false, width: '200px' },
                { text: lang.status || 'Estado', value: 'status', sortable: false, },
            ];

            if (this.isAdmin) {
                headers.push({
                    text: 'Estado Financiero',
                    value: 'financial_status',
                    sortable: false,
                    width: '160px'
                });
            }

            headers.push({
                text: lang.grades || 'Calificación',
                value: 'grade',
                sortable: false,
                align: 'right'
            });

            return headers;
        },
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
        classId: {
            handler() {
                this.getDataFromApi();
            },
            immediate: true
        }
    },
    methods: {
        async getDataFromApi() {
            this.loading = true;
            try {
                // Use the WS URL (ajax.php) defined globally or fallback
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');

                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_student_info');
                params.append('sesskey', M.cfg.sesskey);
                params.append('page', this.options.page);
                params.append('resultsperpage', this.options.itemsPerPage);
                params.append('search', this.options.search || '');

                const planid = Array.isArray(this.filters.planid) ? this.filters.planid.join(',') : '';
                const periodid = Array.isArray(this.filters.periodid) ? this.filters.periodid.join(',') : '';

                params.append('planid', planid);
                params.append('periodid', periodid);
                params.append('status', this.filters.status || '');
                params.append('financial_status', this.filters.financialStatus || '');
                params.append('classid', this.classId || 0);

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: params
                });

                const res = await response.json();

                if (res.errorcode) {
                    throw new Error(res.message);
                }

                if (res.status === 'success' && res.data) {
                    let dataUsers = res.data.dataUsers;
                    if (typeof dataUsers === 'string') {
                        try {
                            dataUsers = JSON.parse(dataUsers);
                        } catch (e) {
                            console.warn("Failed to parse dataUsers string", e);
                            dataUsers = [];
                        }
                    }

                    this.activeUsers = res.data.activeUsers || 0;
                    this.totalDesserts = res.data.totalResults;

                    this.students = [];
                    if (Array.isArray(dataUsers)) {
                        dataUsers.forEach((element) => {
                            this.students.push({
                                name: element.nameuser,
                                email: element.email,
                                id: element.userid,
                                documentnumber: element.documentnumber,
                                carrers: element.careers,
                                subperiods: element.subperiods,
                                financial_reason: element.financial_reason || '',
                                updatingFinancial: false,
                                academicperiodid: element.academicperiodid,
                                academicperiodname: element.academicperiodname || '--',
                                updatingAcademicPeriod: false,
                                updatingPeriod: null,
                                revalidate: (element.revalidate && element.revalidate.length > 0) ? element.revalidate : '--',
                                status: element.status,
                                img: element.profileimage,
                                currentgrade: element.currentgrade || '--',
                                financial_status: element.financial_status || 'none',
                            });
                        });
                    }
                } else if (res.message) {
                    throw new Error(res.message);
                }

            } catch (error) {
                console.error('Error fetching student information:', error);
                this.syncLog = 'Error fetching data: ' + error.message;
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
            // Filter out periods that don't belong to the selected plans anymore
            if (this.filters.periodid.length > 0) {
                // This is a bit complex since we don't know which period belongs to which plan easily without re-fetching
                // or having a mapping. For simplicity, we'll clear it or filter if we have metadata.
                // For now, let's just clear it to avoid invalid selections.
                this.filters.periodid = [];
            }

            this.availablePeriods = [];
            if (!this.filters.planid || this.filters.planid.length === 0) return;

            this.loadingPeriods = true;
            try {
                // Fetch periods for ALL selected plans
                const promises = this.filters.planid.map(id =>
                    axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_get_periods&planid=${id}`)
                );
                const results = await Promise.all(promises);

                let allPeriods = [];
                results.forEach((response, index) => {
                    if (response.data.status === 'success') {
                        const planName = this.plans.find(p => p.id == this.filters.planid[index])?.name || '';
                        const periods = response.data.periods.map(p => ({
                            ...p,
                            name: `${p.name} (${planName})`
                        }));
                        allPeriods = [...allPeriods, ...periods];
                    }
                });
                this.availablePeriods = allPeriods;
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
            if (this.filters.planid && this.filters.planid.length > 0) {
                url += `planid=${this.filters.planid.join(',')}&`;
            }
            if (this.filters.periodid && this.filters.periodid.length > 0) {
                url += `periodid=${this.filters.periodid.join(',')}&`;
            }
            if (this.filters.status) url += `status=${this.filters.status}&`;
            if (this.filters.financialStatus) url += `financial_status=${this.filters.financialStatus}&`;
            url += `withgrades=${this.filters.withGrades ? 1 : 0}`;
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
        async updateStudentSubperiod(item, carrer, subperiod) {
            console.log('Update Subperiod Item:', item, 'Career:', carrer, 'Subperiod:', subperiod);

            const userId = item.userid || item.id;
            if (!userId) {
                alert("Error: No se encontró el ID del estudiante (userid/id). Revise la consola.");
                return;
            }

            this.$set(item, 'updatingSubperiod', carrer.planid);
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_update_subperiod');
                params.append('sesskey', M.cfg.sesskey);
                params.append('userid', userId);
                params.append('planid', carrer.planid);
                params.append('subperiodid', subperiod.id);

                const response = await axios.post(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, params);

                if (response.data.status === 'success') {
                    // Update UI
                    carrer.subperiodname = subperiod.name;
                    // Also update parent item.subperiods if it's the main one
                    if (item.carrers.length === 1 || item.carrers[0] === carrer) {
                        item.subperiods = subperiod.name;
                    }
                    // Ideally reload data to sync Period too if changed, but for responsiveness we update label
                    this.$toast ? this.$toast.success(response.data.message) : alert(response.data.message);
                    this.getDataFromApi(); // Refresh to ensure Period/Bloque sync
                } else {
                    this.$toast ? this.$toast.error(response.data.message) : alert(response.data.message);
                }
            } catch (error) {
                console.error(error);
            } finally {
                this.$set(item, 'updatingSubperiod', false);
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
                student.updatingPeriod = false;
            }
        },
        async loadAcademicPeriods() {
            if (this.allAcademicPeriods.length > 0) return;
            this.loadingAcademicPeriods = true;
            try {
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_get_all_academic_periods`);
                if (response.data.status === 'success') {
                    this.allAcademicPeriods = response.data.data;
                }
            } catch (error) {
                console.error("Error loading academic periods", error);
            } finally {
                this.loadingAcademicPeriods = false;
            }
        },
        async updateStudentAcademicPeriod(student, carrer, academicPeriod) {
            if (student.academicperiodid == academicPeriod.id) return;
            if (!confirm(`¿Cambiar periodo lectivo a: ${academicPeriod.name}?`)) return;

            this.$set(student, 'updatingAcademicPeriod', true);
            try {
                // We use carrer.planid to ensure we target the right enrollment if multiple exist, 
                // though usually a student has one active plan.
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_update_academic_period&userid=${student.id}&planid=${carrer.planid}&academicperiodid=${academicPeriod.id}`);

                if (response.data.status === 'success') {
                    student.academicperiodid = academicPeriod.id;
                    student.academicperiodname = academicPeriod.name;
                    this.$toast ? this.$toast.success(response.data.message) : alert(response.data.message);
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Error de conexión');
            } finally {
                this.$set(student, 'updatingAcademicPeriod', false);
            }
        },
        async loadSubperiodsForPlan(item, carrer) {
            if (carrer.availableSubperiods && carrer.availableSubperiods.length > 0) return;

            this.$set(carrer, 'loadingSubperiods', true); // Optional naming

            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_plan_subperiods');
                params.append('sesskey', M.cfg.sesskey);
                params.append('planid', carrer.planid);

                // Use axios via global wrapper or direct if available, standardizing on M.cfg.wwwroot
                const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, { params: params });

                if (response.data.status === 'success') {
                    this.$set(carrer, 'availableSubperiods', response.data.subperiods);
                } else {
                    console.error("Failed to load subperiods", response.data);
                    this.$set(carrer, 'availableSubperiods', []); // Empty array to stop loading spinner
                }
            } catch (error) {
                console.error("Error loading subperiods", error);
                this.$set(carrer, 'availableSubperiods', []); // Empty array on error
            } finally {
                this.$set(carrer, 'loadingSubperiods', false);
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
        goToProfile(id) {
            window.open(`${M.cfg.wwwroot}/user/view.php?id=${id}`, '_blank');
        },
        getFinancialColor(status) {
            status = status ? status.toLowerCase() : '';
            if (status === 'al_dia' || status === 'up_to_date') return 'success';
            if (status === 'mora' || status === 'arrears') return 'error';
            if (status === 'becado' || status === 'scholarship') return 'info';
            return 'grey lighten-1';
        },
        async updateFinancialStatus(item) {
            item.updatingFinancial = true;
            try {
                // Using the external API we created
                const params = new URLSearchParams();
                params.append('sesskey', M.cfg.sesskey);
                params.append('action', 'local_grupomakro_update_student_status');
                params.append('userid', item.id);

                const response = await axios.post(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, params);

                if (response.data.status === 'success') {
                    // Determine if we should reload or just alert
                    // Reloading silently to show new status
                    await this.getDataFromApi();
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('No se pudo actualizar el estado financiero.');
            } finally {
                item.updatingFinancial = false;
            }
        },
        async syncFinancialBulk() {
            if (!confirm('Esta acción actualizará el estado financiero de TODOS los estudiantes en bloques de 50. Puede tardar varios minutos. ¿Continuar?')) return;

            this.syncing = true;
            this.syncLog = 'Iniciando actualización masiva financiera...';
            let finished = false;
            let totalUpdated = 0;
            let consecutiveZeroUpdates = 0;

            const syncBatch = async () => {
                try {
                    const response = await axios.get(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php?action=local_grupomakro_sync_financial_bulk`);

                    if (response.data.status === 'success') {
                        const result = response.data.data;
                        const updatedCount = result.updated || 0;

                        if (updatedCount > 0) {
                            totalUpdated += updatedCount;
                            this.syncLog = `Actualizados: ${totalUpdated} estudiantes... Continuado...`;
                            consecutiveZeroUpdates = 0;
                            await syncBatch(); // Continue next batch
                        } else {
                            // If 0 updated, maybe everyone is up to date or no users found needing update
                            // To be safe, we stop if we hit 0 or if message says so
                            this.syncLog = `Proceso finalizado. Total actualizados: ${totalUpdated}.`;
                            finished = true;
                        }
                    } else {
                        throw new Error(response.data.message || 'Error desconocido del servidor');
                    }
                } catch (error) {
                    console.error('Bulk sync error:', error);
                    this.syncLog += '\nError: ' + error.message;
                    finished = true; // Stop on error
                    alert('El proceso se detuvo por un error: ' + error.message);
                }
            };

            try {
                await syncBatch();
                if (finished) {
                    await this.getDataFromApi();
                    alert(`Actualización masiva completada. Se actualizaron ${totalUpdated} registros.`);
                }
            } finally {
                this.syncing = false;
            }
        },
        openPeriodModal() {
            this.periodModal = true;
            this.periodImportLog = '';
            this.periodImportFile = null;
        },
        exportPeriodTemplate() {
            let url = `${M.cfg.wwwroot}/local/grupomakro_core/pages/export_student_periods.php?`;

            const p = new URLSearchParams();
            if (this.filters.planid && this.filters.planid.length > 0) p.append('planid', this.filters.planid.join(','));
            if (this.filters.periodid && this.filters.periodid.length > 0) p.append('periodid', this.filters.periodid.join(','));
            if (this.filters.status) p.append('status', this.filters.status);
            if (this.options.search) p.append('search', this.options.search);

            window.open(url + p.toString(), '_blank');
        },
        async importPeriodFile() {
            if (!this.periodImportFile) return;

            this.syncing = true;
            this.periodImportLog = 'Subiendo y procesando archivo...';

            // Construct FormData for Upload
            const formData = new FormData();
            formData.append('action', 'local_grupomakro_bulk_update_periods_excel');
            formData.append('sesskey', M.cfg.sesskey);
            formData.append('import_file', this.periodImportFile);

            try {
                const response = await axios.post(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                });

                if (response.data.status === 'success') {
                    this.periodImportLog = response.data.message + "\n\n" + (response.data.log || '');
                    // Refresh data
                    await this.getDataFromApi();
                } else {
                    this.periodImportLog = "Error: " + (response.data.message || 'Error desconocido');
                }
            } catch (err) {
                console.error(err);
                this.periodImportLog = "Error en la carga/proceso: " + err.message;
            } finally {
                this.syncing = false;
                // Don't clear log immediately so user can see it
            }
        }
    }
})