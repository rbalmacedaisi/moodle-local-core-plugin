const removeDiacriticAndLowerCase = (string) => {
    return string.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase()
}

Vue.component('scheduletable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
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
                            <v-divider
                              class="mx-4"
                              inset
                              vertical
                            ></v-divider>
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
                        <div class="d-flex>">
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
            dialog: false,
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
            return removeDiacriticAndLowerCase(value.toString()).includes(removeDiacriticAndLowerCase(search))
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