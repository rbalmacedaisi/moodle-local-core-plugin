const removeDiacriticAndLowerCase = (string) => {
    return string.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase()
}

Vue.component('scheduletable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">

                <!-- Bulk approve confirmation dialog -->
                <v-dialog v-model="confirmDialog" max-width="480">
                    <v-card>
                        <v-card-title class="text-h6">Aprobar periodo</v-card-title>
                        <v-card-text>
                            Confirma que desea inscribir todos los estudiantes y aprobar todas las clases
                            del periodo <strong>{{ selectedPeriodName }}</strong>?<br>
                            <span class="text-caption grey--text">Solo se procesaran clases aun no aprobadas.</span>
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn text @click="confirmDialog = false">Cancelar</v-btn>
                            <v-btn color="success" :loading="approving" @click="bulkApprove(false)">Confirmar</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>

                <!-- Over quota warning dialog -->
                <v-dialog v-model="overQuotaDialog" max-width="820">
                    <v-card>
                        <v-card-title class="text-h6">Advertencia de quorum</v-card-title>
                        <v-card-text>
                            Se detectaron {{ overQuotaClasses.length }} clases con mas de {{ quorumLimit }} estudiantes:
                            <v-simple-table dense class="mt-3">
                                <thead>
                                    <tr>
                                        <th>Clase</th>
                                        <th class="text-right">Estudiantes</th>
                                        <th class="text-right">Exceso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in overQuotaClasses" :key="row.classid">
                                        <td>{{ row.name }}</td>
                                        <td class="text-right">{{ row.candidates }}</td>
                                        <td class="text-right">{{ row.overflow }}</td>
                                    </tr>
                                </tbody>
                            </v-simple-table>
                            <div class="text-caption grey--text mt-3">
                                Puede continuar para aprobar e inscribir igualmente a todos los estudiantes.
                            </div>
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn text @click="overQuotaDialog = false">Cancelar</v-btn>
                            <v-btn color="warning" :loading="approving" @click="confirmOverQuotaAndContinue">Continuar</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>

                <!-- Result snackbar -->
                <v-snackbar v-model="snackbar" :timeout="6000" top :color="snackColor">
                    {{ snackMessage }}
                    <template v-slot:action="{ attrs }">
                        <v-btn text v-bind="attrs" @click="snackbar = false">Cerrar</v-btn>
                    </template>
                </v-snackbar>

                <v-data-table
                   :headers="headers"
                   :items="items"
                   class="elevation-1 paneltable"
                   :search="search"
                   dense
                   :custom-filter="tableFilter"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{lang.selection_schedules}}</v-toolbar-title>
                            <v-divider class="mx-4" inset vertical></v-divider>
                            <v-select
                                v-model="selectedPeriod"
                                :items="periods"
                                item-text="name"
                                item-value="id"
                                label="Periodo"
                                dense
                                outlined
                                hide-details
                                clearable
                                style="max-width:260px"
                                class="mr-3"
                            ></v-select>
                            <v-btn
                                color="success"
                                small
                                :disabled="!selectedPeriod || approving"
                                :loading="approving"
                                @click="confirmDialog = true"
                            >
                                <v-icon left small>mdi-check-all</v-icon>
                                Aprobar periodo
                            </v-btn>
                            <v-spacer></v-spacer>
                        </v-toolbar>

                        <v-row justify="start" class="ma-0 mr-3">
                            <v-col cols="4">
                                <v-text-field
                                   v-model="search"
                                   append-icon="mdi-magnify"
                                   :label="lang.search"
                                   hide-details
                                   outlined
                                   dense
                                ></v-text-field>
                            </v-col>
                        </v-row>
                    </template>
                    
                    <template v-slot:item.coursename="{ item }">
                        <v-list class="transparent">
                            <v-list-item>
                                <v-list-item-content>
                                    <v-list-item-title>{{ item.coursename }}</v-list-item-title>
                                    <v-list-item-subtitle class="text-caption" v-text="item.period"></v-list-item-subtitle>
                                    <v-list-item-subtitle v-if="item.tc ==1" class="text-caption">TC</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.numberclasses="{ item }">
                      <div class="d-flex flex-column">
                        {{item.numberclasses}}
                        <div class="d-flex">
                            <span
                              v-for="(schedule, index) in item.schedules"
                              :key="schedule.id"
                              class="rounded-circle mr-1"
                              style="width: 10px; height: 10px;display: inline-flex;"
                              :class="getColor(schedule)"
                            ></span>
                        </div>
                      </div>
                    </template>
                    
                    <template v-slot:item.users="{ item }">
                        {{ item.users }}
                    </template>
                    
                    <template v-slot:item.periods="{ item }"></template>
                    
                    <template v-slot:item.actions="{ item }">
                        <v-btn
                          outlined
                          color="primary"
                          small
                          class="rounded"
                          :href="'/local/grupomakro_core/pages/scheduleapproval.php?id=' + item.numberid + '&periodsid=' + item.periodIds"
                        >
                          {{lang.schedules}}
                        </v-btn>
                    </template>
                    <template v-slot:no-data>
                        <span >{{lang.nodata}}</span>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>
    `,
    data(){
        return{
            dialog: false,
            search: '',
            selectedPeriod: null,
            approving: false,
            confirmDialog: false,
            overQuotaDialog: false,
            overQuotaClasses: [],
            quorumLimit: 40,
            snackbar: false,
            snackMessage: '',
            snackColor: 'success',
            periods: window.periodsList || [],
            headers: [
                {
                    text: window.strings.course,
                    align: 'start',
                    sortable: false,
                    value: 'coursename',
                },
                {
                    text: window.strings.item_class,
                    sortable: false,
                    value: 'numberclasses',
                    align: 'center',
                },
                { text: window.strings.users, value: 'users',sortable: false, align: 'center', },
                { text: 'periods', value: 'period', sortable: false, class: 'd-none' },
                { text: window.strings.actions, value: 'actions', sortable: false, align: 'center',filterable: false },
            ],
            items: [],
        }
    },
    props:{},
    created(){
        this.getitems()
    }, 
    mounted(){},  
    methods:{
        // Retrieves data for the data table by making a GET request to a RESTful API.
        getitems(){
            // URL of the API to be used for data retrieval.
            const url = this.siteUrl;
           
            // Parameters required for making the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules_overview',
            };
            // Perform a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                .then(response => {
                    
                    // Parse the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.schedulesOverview)
                    
                    // Convert the object into an array of values.
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    // Add availability data for each instructor to the current instance's item array.
                    array.forEach((element)=>{
                        this.items.push({
                            numberid: element.courseId,
                            coursename: element.courseName,
                            numberclasses: element.numberOfClasses,
                            users: element.totalParticipants,
                            period: element.periodNames,
                            schedules: element.schedules,
                            periodIds: element.periodIds,
                            capacityColor: element.capacityColor,
                            capacityPercent: element.capacityPercent,
                            learningPlanId: element.learningPlanId,
                            learningPlanName: element.learningPlanNames,
                            remainingCapacity: element.remainingCapacity,
                            totalCapacity: element.totalCapacity,
                            tc: element.tc
                        })
                    })
                    
                    // Calculate the percentage capacity for each schedule and round to a whole number.
                    this.items.forEach((item) => {
                        item.schedules.forEach((schedule) => {
                            const percent = Math.round((schedule.preRegisteredStudents / schedule.classroomcapacity) * 100);
                            schedule.capacitypercentage = percent;
                        });
                    });
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });  
        },
        /**
         * Determines the color class based on the capacity percentage of an item.
         * @param '{Object} item' - The item containing capacity percentage information.
         * @returns '{string}' - The color class to apply based on the item's capacity percentage.
         */
        getColor (item) {
            // Check if the Vuetify theme is not dark.
            if(!this.$vuetify.theme.dark){
                if (item.capacitypercentage >= 70) return ' red accent-2'
                else if (item.capacitypercentage >= 50) return 'amber lighten-4'
                else return 'green accent-3'
            }else{
                // If the Vuetify theme is dark.
                if (item.capacitypercentage >= 70) return 'red accent-2'
                else if (item.capacitypercentage >= 50) return 'amber lighten-4'
                else return 'green accent-4'
            }
        },
        /**
         * Redirects to the schedule approval page for a specific item.
         * @param {Object} item - The item for which the schedule approval page should be displayed.
         */
        showschedules(item){
            // Redirects to the schedule approval page with the ID of the selected item.
            window.location = '/local/grupomakro_core/pages/scheduleapproval.php?id=' + item.id
        },
        tableFilter (value, search, item) {
            try{
                return removeDiacriticAndLowerCase(value.toString()).includes(removeDiacriticAndLowerCase(search))
            }catch (error){
                console.error(error)
            }
        },
        confirmOverQuotaAndContinue() {
            this.bulkApprove(true);
        },
        async bulkApprove(force = false) {
            if (!this.selectedPeriod) return;

            this.approving = true;
            if (!force) {
                this.confirmDialog = false;
            }
            this.overQuotaDialog = false;

            try {
                const res = await window.axios.post(
                    window.location.origin + '/local/grupomakro_core/ajax.php',
                    {
                        action: 'local_grupomakro_bulk_approve_period',
                        periodid: this.selectedPeriod,
                        force: force ? 1 : 0,
                        quorumlimit: this.quorumLimit,
                    },
                    { headers: { 'Content-Type': 'application/json' } }
                );

                const status = res.data?.status;
                const d = res.data?.data;

                if (status === 'warning' && d) {
                    this.overQuotaClasses = Array.isArray(d.over_quota_classes) ? d.over_quota_classes : [];
                    this.overQuotaDialog = this.overQuotaClasses.length > 0;
                    this.snackMessage = res.data?.message || 'Se detectaron clases por encima del quorum.';
                    this.snackColor = 'warning';
                    this.snackbar = !this.overQuotaDialog;
                    return;
                }

                if (status === 'success' && d) {
                    const errors = Array.isArray(d.errors) ? d.errors : [];
                    const errMsg = errors.length > 0 ? ` - ${errors.length} errores` : '';
                    const overQuotaMsg = (d.over_quota_count || 0) > 0 ? ` Sobre quorum: ${d.over_quota_count}.` : '';

                    this.snackMessage = `Aprobadas: ${d.approved} clases. Inscritos: ${d.enrolled_total ?? '?'} estudiantes. Omitidas: ${d.skipped}.${overQuotaMsg}${errMsg}`;
                    this.snackColor = errors.length > 0 ? 'warning' : 'success';
                    if (errors.length > 0) {
                        console.warn('Bulk approve errors:', errors);
                    }
                } else {
                    this.snackMessage = res.data?.message || 'Error desconocido';
                    this.snackColor = 'error';
                }

                this.snackbar = true;
            } catch(e) {
                this.snackMessage = 'Error: ' + (e.response?.data?.message || e.message);
                this.snackColor = 'error';
                this.snackbar = true;
            } finally {
                this.approving = false;
            }
        },
    },
    computed: {
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings
        },
        selectedPeriodName() {
            if (!this.selectedPeriod) return '';
            const p = this.periods.find(p => p.id == this.selectedPeriod);
            return p ? p.name : '';
        },
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php';
        },
        /** A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        }
    },
})

