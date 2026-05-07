Vue.component('teacher-student-table', {
    props: ['classId'],
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="mb-4">
                 <v-card class="pa-4 d-flex align-center" outlined style="border-left: 5px solid #4CAF50;">
                    <div>
                        <div class="text-overline mb-0">Estudiantes en clase</div>
                        <div class="text-h4 font-weight-bold success--text">{{ totalDesserts }}</div>
                        <div class="caption grey--text">Activos: {{ activeUsers }}</div>
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
                        <v-dialog v-model="filterDialog" max-width="450px">
                            <v-card>
                                <v-card-title class="headline" :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-2'">
                                    Filtros y Exportar
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
                                    <v-radio-group v-model="filters.exportType" label="Tipo de Exportación" row class="mt-2">
                                        <v-radio label="Asistencia (PDF)" value="attendance" color="error"></v-radio>
                                        <v-radio label="Notas (Excel)" value="grades" color="success"></v-radio>
                                        <v-radio label="Notas (PDF)" value="gradesPdf" color="teal"></v-radio>
                                    </v-radio-group>
                                </v-card-text>
                                <v-card-actions class="pa-4 flex-column" style="gap:8px">
                                    <v-btn
                                        color="primary"
                                        block
                                        :loading="exportingPdf || exportingGradesPdf"
                                        @click="filters.exportType === 'attendance' ? exportPdf() : (filters.exportType === 'grades' ? exportGrades() : exportGradesPdf())"
                                    >
                                        <v-icon left>{{ filters.exportType === 'attendance' ? 'mdi-file-pdf-box' : (filters.exportType === 'grades' ? 'mdi-file-excel' : 'mdi-file-table-box') }}</v-icon>
                                        {{ filters.exportType === 'attendance' ? 'Exportar PDF (Asistencia)' : (filters.exportType === 'grades' ? 'Exportar Excel (Notas)' : 'Exportar PDF (Notas)') }}
                                    </v-btn>
                                </v-card-actions>
                                <v-card-actions class="pa-4 pt-0">
                                    <v-btn color="primary" @click="applyFilters">Aplicar Filtros</v-btn>
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
                    
                    <template v-slot:item.careers="{ item }">
                        <div class="py-1">
                            <div v-for="(career, index) in item.careers" :key="index" class="mb-1">
                                <span class="caption font-weight-bold d-block" :class="$vuetify.theme.dark ? 'grey--text text--lighten-1' : 'grey--text text--darken-2'" style="line-height: 1.2;">
                                    {{ career.career }}
                                </span>
                            </div>
                        </div>
                    </template>
                    
                    <template v-slot:item.periods="{ item }">
                        <div class="py-1">
                             <div v-for="(career, index) in item.careers" :key="index" class="mb-1">
                                <v-menu offset-y v-if="isAdmin" @input="(val) => val && loadPeriodsForPlan(item, career)">
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn text x-small class="px-0 text-none" v-bind="attrs" v-on="on" :loading="item.updatingPeriod === career.planid">
                                            {{ career.periodname }}
                                            <v-icon small right>mdi-chevron-down</v-icon>
                                        </v-btn>
                                    </template>
                                    <v-list dense max-height="300" class="overflow-y-auto">
                                        <v-list-item v-if="!career.availablePeriods">
                                            <v-list-item-title class="caption text-center gray--text">Cargando...</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item v-for="p in career.availablePeriods" :key="p.id" @click="updateStudentPeriod(item, career, p)">
                                            <v-list-item-title :class="{'primary--text font-weight-bold': p.id == career.periodid}">{{ p.name }}</v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                                <span v-else class="text-body-2">{{ career.periodname }}</span>
                             </div>
                        </div>
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
                    
                    <template v-slot:item.phone="{ item }">
                         <div class="text-no-wrap text-body-2">
                             <v-icon small left color="primary" class="mr-1">mdi-phone</v-icon>
                             {{ item.phone }}
                         </div>
                    </template>

                    <template v-slot:item.absences="{ item }">
                         <v-btn small color="error" text class="font-weight-bold" @click="openAttendanceModal(item)">
                             <v-icon small left>mdi-calendar-remove</v-icon>
                             {{ item.absences }}
                         </v-btn>
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
            <grademodal v-if="studentsGrades" :class-id="classId" :dataStudent="studentGradeSelected" @close-dialog="closeDialog"></grademodal>
            
            <attendancemodal 
                v-if="showAttendanceModal" 
                :userid="selectedStudent.id" 
                :classid="classId" 
                :studentname="selectedStudent.name"
                @close="showAttendanceModal = false"
            ></attendancemodal>
            
        </v-row>
    `,
    data() {
        const lang = window.strings || {};
        const headers = [
            {
                text: lang.name || 'Nombre',
                align: 'start',
                sortable: false,
                value: 'name',
                width: '250px' // Ensure name has space
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
                value: 'careers',
            },
            { text: lang.period || 'Periodo', value: 'periods', sortable: false, width: '200px' },
            { text: 'Bloque', value: 'subperiods', sortable: false, width: '200px' },
            { text: 'Teléfono', value: 'phone', sortable: false, width: '150px' },
            { text: 'Inasistencias', value: 'absences', sortable: false, width: '120px' },
            { text: lang.status || 'Estado', value: 'status', sortable: false, },
        ];

        // Add Grade column if we are in a class context
        if (this.classId) {
            headers.push({
                text: lang.grades || 'Calificación',
                value: 'grade',
                sortable: false,
                align: 'right'
            });
        }

        return {
            headers: headers,
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
                withGrades: false,
                exportType: 'attendance'
            },
            careers: [],
            quarters: [],
            statusFilter: '',
            careerFilter: '',
            quarterFilter: '',
            filterDialog: false,
            exportingPdf: false,
            exportingGradesPdf: false,
            studentsGrades: false,
            studentGradeSelected: {},
            showAttendanceModal: false,
            selectedStudent: null
        };
    },
    computed: {
        lang() {
            return window.strings || {};
        },
        lang() {
            return window.strings || {};
        },
        siteUrl() { return window.location.origin + '/webservice/rest/server.php' },
        token() { return window.userToken; },
        isAdmin() {
            return true; // Simplified for now
        },
        isSuperAdmin() {
            return false; // Simplified for now
        }
    },
    created() {
        console.log('TeacherStudentTable Component Created');
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
        openAttendanceModal(item) {
            this.selectedStudent = item;
            this.showAttendanceModal = true;
        },
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
                                careers: element.careers,
                                subperiods: element.subperiods,
                                updatingPeriod: null,
                                revalidate: (element.revalidate && element.revalidate.length > 0) ? element.revalidate : '--',
                                status: element.status,
                                img: element.profileimage,
                                phone: element.phone,
                                absences: element.absences || 0,
                                currentgrade: element.currentgrade || '--'
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
            const themeColors = this.$vuetify.theme.dark ? {
                BgChip1: "#1b5e20", TextChip1: "#e8f5e9", // Dark Green / Light Green text
                BgChip2: "#3e2723", TextChip2: "#ffe0b2", // Dark Brown / Light Orange text
                BgChip3: "#1a237e", TextChip3: "#c5cae9", // Dark Indigo / Light Indigo text
                BgChip4: "#b71c1c", TextChip4: "#ffcdd2", // Dark Red / Light Red text
                BgChip5: "#263238", TextChip5: "#eceff1", // Dark slate / Light slate
            } : {
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
        exportPdf() {
            this.exportingPdf = true;
            const params = new URLSearchParams();
            params.set('sesskey', M.cfg.sesskey);
            if (this.classId)                                        params.set('classid',  this.classId);
            if (this.filters.planid   && this.filters.planid.length) params.set('planid',   this.filters.planid.join(','));
            if (this.filters.periodid && this.filters.periodid.length) params.set('periodid', this.filters.periodid.join(','));
            if (this.filters.status)                                 params.set('status',   this.filters.status);
            if (this.options.search)                                 params.set('search',   this.options.search);

            const url = `${M.cfg.wwwroot}/local/grupomakro_core/pages/export_class_pdf.php?${params.toString()}`;
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            setTimeout(() => { this.exportingPdf = false; }, 2000);
            this.filterDialog = false;
        },
        exportGrades() {
            let url = `${M.cfg.wwwroot}/local/grupomakro_core/pages/export_consolidated_grades.php?`;
            if (this.classId)                                            url += `classid=${this.classId}&`;
            if (this.filters.planid && this.filters.planid.length > 0)  url += `planid=${this.filters.planid.join(',')}&`;
            if (this.filters.periodid && this.filters.periodid.length)  url += `periodid=${this.filters.periodid.join(',')}&`;
            if (this.filters.status)                                     url += `status=${this.filters.status}&`;
            url += `withgrades=1`;
            this.exportingPdf = true;
            window.open(url, '_blank');
            setTimeout(() => { this.exportingPdf = false; }, 2000);
            this.filterDialog = false;
        },
        loadExternalScript(src) {
            return new Promise((resolve, reject) => {
                const selector = `script[data-gmk-src="${src}"]`;
                const existing = document.querySelector(selector);
                if (existing) {
                    if (existing.getAttribute('data-loaded') === '1') { resolve(); return; }
                    existing.addEventListener('load', () => resolve(), { once: true });
                    existing.addEventListener('error', () => reject(new Error('Script load error: ' + src)), { once: true });
                    return;
                }
                let originalDefine = null;
                if (typeof window.define === 'function' && window.define.amd) {
                    originalDefine = window.define;
                    window.define = undefined;
                }
                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.setAttribute('data-gmk-src', src);
                script.addEventListener('load', () => {
                    script.setAttribute('data-loaded', '1');
                    if (originalDefine) window.define = originalDefine;
                    resolve();
                }, { once: true });
                script.addEventListener('error', () => {
                    if (originalDefine) window.define = originalDefine;
                    script.remove();
                    reject(new Error('Script load error: ' + src));
                }, { once: true });
                document.head.appendChild(script);
            });
        },
        async ensurePdfLibrary() {
            if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) return;
            const sources = [
                'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            ];
            for (const src of sources) {
                try {
                    await this.loadExternalScript(src);
                    if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) return;
                } catch (e) { /* try next */ }
            }
            throw new Error('No se pudo inicializar jsPDF.');
        },
        async exportGradesPdf() {
            if (this.exportingGradesPdf) return;
            this.exportingGradesPdf = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');

                // ── 1. Fetch all students ─────────────────────────────────────
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_student_info');
                params.append('sesskey', M.cfg.sesskey);
                params.append('page', 1);
                params.append('resultsperpage', 500);
                params.append('search', this.options.search || '');
                params.append('planid', Array.isArray(this.filters.planid) ? this.filters.planid.join(',') : '');
                params.append('periodid', Array.isArray(this.filters.periodid) ? this.filters.periodid.join(',') : '');
                params.append('status', this.filters.status || '');
                params.append('classid', this.classId || 0);

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params,
                });
                const resJson = await res.json();

                let students = [];
                if (resJson.status === 'success' && resJson.data) {
                    let dataUsers = resJson.data.dataUsers;
                    if (typeof dataUsers === 'string') {
                        try { dataUsers = JSON.parse(dataUsers); } catch(e) { dataUsers = []; }
                    }
                    if (Array.isArray(dataUsers)) {
                        students = dataUsers.map(e => ({
                            id: e.userid,
                            name: e.nameuser || '--',
                            documentnumber: e.documentnumber || '--',
                        }));
                    }
                }

                if (!students.length) {
                    alert('No hay estudiantes para exportar.');
                    return;
                }

                // ── 2. Fetch each student's gradebook (parallel batches of 5) ─
                const fetchGb = async (userId) => {
                    try {
                        const r = await window.axios.get(url, { params: {
                            action: 'local_grupomakro_get_student_gradebook',
                            sesskey: M.cfg.sesskey,
                            userId: userId,
                            classId: this.classId,
                        }});
                        const payload = r && r.data ? r.data : {};
                        if (payload.status === 'success' && payload.data) {
                            const raw = payload.data.gradebook;
                            return {
                                gradebook: typeof raw === 'string' ? JSON.parse(raw) : (raw || []),
                                courseGrade: payload.data.course_grade != null ? Number(payload.data.course_grade) : null,
                            };
                        }
                    } catch(e) { /* ignore */ }
                    return { gradebook: [], courseGrade: null };
                };

                const BATCH = 5;
                const gbResults = new Array(students.length);
                for (let i = 0; i < students.length; i += BATCH) {
                    const slice = students.slice(i, i + BATCH);
                    const r = await Promise.all(slice.map(s => fetchGb(s.id)));
                    r.forEach((v, j) => { gbResults[i + j] = v; });
                }

                // ── 3. Discover activity columns from the first non-empty gradebook ──
                const allCols = []; // { id, name, weight_pct, category }
                for (const gbr of gbResults) {
                    if (gbr && gbr.gradebook && gbr.gradebook.length > 0) {
                        for (const catGroup of gbr.gradebook) {
                            for (const item of (catGroup.items || [])) {
                                allCols.push({
                                    id: item.id,
                                    name: item.name,
                                    weight_pct: item.weight_pct,
                                    category: catGroup.category,
                                });
                            }
                        }
                        break;
                    }
                }

                // ── 4. Build grade lookup: studentId → { gradeMap, courseGrade } ─
                const lookup = {};
                students.forEach((s, i) => {
                    const gbr = gbResults[i] || { gradebook: [], courseGrade: null };
                    const gmap = {};
                    for (const catGroup of (gbr.gradebook || [])) {
                        for (const item of (catGroup.items || [])) {
                            gmap[item.id] = item.grade;
                        }
                    }
                    lookup[s.id] = { gmap, courseGrade: gbr.courseGrade };
                });

                // ── 5. Generate PDF ───────────────────────────────────────────
                await this.ensurePdfLibrary();
                const jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                const pageW = doc.internal.pageSize.getWidth();   // 297
                const pageH = doc.internal.pageSize.getHeight();  // 210
                const ML = 8;
                const contentW = pageW - ML * 2;

                const colName  = 52;
                const colId    = 26;
                const colFinal = 20;
                const actAreaW = contentW - colName - colId - colFinal;
                const actColW  = allCols.length > 0
                    ? Math.max(13, Math.min(32, actAreaW / allCols.length))
                    : 20;
                const colsPerGroup = Math.max(1, Math.floor(actAreaW / actColW));
                const numGroups    = allCols.length > 0 ? Math.ceil(allCols.length / colsPerGroup) : 1;

                const trunc = (text, maxW) => {
                    const s = String(text || '');
                    if (doc.getTextWidth(s) <= maxW) return s;
                    let t = s;
                    while (t.length > 1 && doc.getTextWidth(t + '…') > maxW) t = t.slice(0, -1);
                    return t + '…';
                };

                // ── Column header renderer (category row + name/weight row) ──
                const drawColHeaders = (grpCols, yStart) => {
                    let y = yStart;
                    const actStartX = ML + colName + colId;

                    // Category grouping row (5mm tall)
                    doc.setFillColor(38, 50, 56);
                    doc.rect(ML, y, colName + colId, 5, 'F');

                    // Compute category spans
                    const catSpans = [];
                    let spanCat = grpCols.length > 0 ? grpCols[0].category : '';
                    let spanFrom = 0;
                    grpCols.forEach((col, idx) => {
                        if (col.category !== spanCat) {
                            catSpans.push({ cat: spanCat, from: spanFrom, to: idx - 1 });
                            spanCat = col.category;
                            spanFrom = idx;
                        }
                    });
                    if (grpCols.length > 0) catSpans.push({ cat: spanCat, from: spanFrom, to: grpCols.length - 1 });

                    catSpans.forEach(span => {
                        const sx = actStartX + span.from * actColW;
                        const sw = (span.to - span.from + 1) * actColW;
                        doc.setFillColor(69, 90, 100);
                        doc.rect(sx, y, sw, 5, 'F');
                        doc.setTextColor(255, 255, 255);
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(6);
                        doc.text(trunc(span.cat, sw - 2), sx + 1, y + 3.6);
                        doc.setDrawColor(100, 130, 140);
                        doc.line(sx, y, sx, y + 5);
                    });
                    // Final grade category cell
                    doc.setFillColor(13, 71, 161);
                    doc.rect(actStartX + grpCols.length * actColW, y, colFinal, 5, 'F');
                    y += 5;

                    // Name + weight sub-header row (8mm tall)
                    const subH = 8;
                    doc.setFillColor(55, 71, 79);
                    doc.rect(ML, y, colName, subH, 'F');
                    doc.setFillColor(69, 90, 100);
                    doc.rect(ML + colName, y, colId, subH, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(7.5);
                    doc.text('Estudiante', ML + 2, y + 5.5);
                    doc.text('Identificación', ML + colName + 2, y + 5.5);

                    let x = actStartX;
                    grpCols.forEach((col, idx) => {
                        const alt = idx % 2 === 0;
                        doc.setFillColor(alt ? 84 : 96, alt ? 110 : 125, alt ? 122 : 138);
                        doc.rect(x, y, actColW, subH, 'F');
                        doc.setTextColor(255, 255, 255);
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(6);
                        doc.text(trunc(col.name, actColW - 2), x + 1, y + 3.5);
                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(5.5);
                        doc.text(col.weight_pct > 0 ? col.weight_pct.toFixed(1) + '%' : '--', x + 1, y + 7);
                        doc.setDrawColor(100, 120, 130);
                        doc.line(x, y, x, y + subH);
                        x += actColW;
                    });
                    doc.setFillColor(21, 101, 192);
                    doc.rect(x, y, colFinal, subH, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(7.5);
                    doc.text('Final', x + 2, y + 5.5);

                    return y + subH; // return next y
                };

                // ── One page-group per activity column chunk ──────────────────
                for (let grpIdx = 0; grpIdx < numGroups; grpIdx++) {
                    if (grpIdx > 0) doc.addPage();

                    const grpCols = allCols.slice(grpIdx * colsPerGroup, (grpIdx + 1) * colsPerGroup);

                    // Header bar
                    doc.setFillColor(13, 71, 161);
                    doc.roundedRect(ML, 5, contentW, 13, 2, 2, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(11);
                    doc.text('Libro de Calificaciones', ML + 3, 11.5);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    const grpLabel = numGroups > 1 ? `  [Bloque ${grpIdx + 1}/${numGroups}]` : '';
                    doc.text(
                        `Generado: ${new Date().toLocaleString('es-PA')} — ${students.length} estudiantes${grpLabel}`,
                        ML + 3, 16.5
                    );

                    let y = drawColHeaders(grpCols, 21);

                    // ── Student rows ──────────────────────────────────────────
                    const rowH = 6;
                    students.forEach((student, sIdx) => {
                        if (y + rowH > pageH - ML) {
                            doc.addPage();
                            y = ML;
                            y = drawColHeaders(grpCols, y);
                        }

                        if (sIdx % 2 === 0) {
                            doc.setFillColor(245, 247, 250);
                            doc.rect(ML, y, contentW, rowH, 'F');
                        }

                        const sd = lookup[student.id] || { gmap: {}, courseGrade: null };
                        let x = ML;

                        doc.setTextColor(20, 20, 20);
                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(7);
                        doc.text(trunc(student.name, colName - 3), x + 2, y + 4);
                        x += colName;
                        doc.text(trunc(student.documentnumber, colId - 2), x + 1, y + 4);
                        x += colId;

                        grpCols.forEach(col => {
                            const grade = sd.gmap[col.id];
                            const gv = (grade !== null && grade !== undefined) ? parseFloat(grade) : NaN;
                            doc.setFontSize(7);
                            if (!isNaN(gv)) {
                                doc.setTextColor(gv >= 70 ? 27 : 183, gv >= 70 ? 94 : 28, gv >= 70 ? 32 : 28);
                                doc.setFont('helvetica', 'bold');
                                doc.text(String(grade), x + actColW / 2, y + 4, { align: 'center' });
                            } else {
                                doc.setTextColor(180, 180, 180);
                                doc.setFont('helvetica', 'normal');
                                doc.text('--', x + actColW / 2, y + 4, { align: 'center' });
                            }
                            doc.setDrawColor(200, 210, 215);
                            doc.line(x, y, x, y + rowH);
                            x += actColW;
                        });

                        // Final grade cell
                        const fg = sd.courseGrade;
                        if (fg !== null && fg !== undefined) {
                            const fgv = parseFloat(fg);
                            doc.setTextColor(fgv >= 70 ? 27 : 183, fgv >= 70 ? 94 : 28, fgv >= 70 ? 32 : 28);
                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(8);
                            doc.text(fgv.toFixed(1), x + colFinal / 2, y + 4, { align: 'center' });
                        } else {
                            doc.setTextColor(180, 180, 180);
                            doc.setFont('helvetica', 'normal');
                            doc.setFontSize(7);
                            doc.text('--', x + colFinal / 2, y + 4, { align: 'center' });
                        }

                        doc.setTextColor(20, 20, 20);
                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(7);
                        doc.setDrawColor(210, 215, 220);
                        doc.line(ML, y + rowH, ML + contentW, y + rowH);
                        y += rowH;
                    });
                }

                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                doc.save(`libro_calificaciones_${dateToken}.pdf`);
                this.filterDialog = false;
            } catch(error) {
                console.error('Error generating gradebook PDF:', error);
                alert('Error al generar el PDF de calificaciones.');
            } finally {
                this.exportingGradesPdf = false;
            }
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
                    const unlockedMsg = response.data.unlocked > 0
                        ? `\n${response.data.unlocked} cursos desbloqueados (prerrequisitos cumplidos).`
                        : '';
                    alert('Sincronización completada. ' + response.data.count + ' registros procesados.' + unlockedMsg);
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
        goToProfile(id) {
            window.open(`${M.cfg.wwwroot}/user/view.php?id=${id}`, '_blank');
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
