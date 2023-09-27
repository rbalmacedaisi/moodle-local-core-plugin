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
                      <v-chip
                        color="primary"
                        small
                        light
                      >
                        {{ item.users }}
                      </v-chip>
                    </template>
                    
                    <template v-slot:item.periods="{ item }">
                      
                    </template>
                    
                    <template v-slot:item.actions="{ item }">
                        <v-btn
                          outlined
                          color="primary"
                          small
                          class="rounded"
                          :href="'/local/grupomakro_core/pages/scheduleapproval.php?id=' + item.id"
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
                    text: 'Curso',
                    align: 'start',
                    sortable: false,
                    value: 'coursename',
                },
                {
                    text: 'Clases',
                    sortable: false,
                    value: 'numberclasses',
                    align: 'center',
                },
                { text: 'Usuarios', value: 'users',sortable: false, align: 'center', },
                { text: 'periods', value: 'period', sortable: false, class: 'd-none' },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'center' },
                
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
        getitems(){
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules_overview',
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    //console.log(response)
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.schedulesOverview)
                    //console.log(data)
                    
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    // Add the availability data for each instructor to the current instance's item array.
                    array.forEach((element)=>{
                        this.items.push({
                            //id: element.courseId,
                            coursename: element.courseName,
                            numberclasses: element.numberOfClasses,
                            users: element.totalParticipants,
                            period: element.periodName,
                            schedules: element.schedules,
                            periodId: element.periodId,
                            capacityColor: element.capacityColor,
                            capacityPercent: element.capacityPercent,
                            learningPlanId: element.learningPlanId,
                            learningPlanNames: element.learningPlanNames,
                            remainingCapacity: element.remainingCapacity,
                            totalCapacity: element.totalCapacity
                        })
                    })
                    
                    this.items.forEach((item) => {
                        item.schedules.forEach((schedule) => {
                            // Calculate the percentage and round to a whole number.
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
        getColor (item) {
            console.log(item)
            
            if(!this.$vuetify.theme.dark){
                if (item.capacitypercentage >= 70) return ' red accent-2'
                else if (item.capacitypercentage >= 50) return 'amber lighten-4'
                else return 'green accent-3'
            }else{
                if (item.capacitypercentage >= 70) return 'red accent-2'
                else if (item.capacitypercentage >= 50) return 'amber lighten-4'
                else return 'green accent-4'
            }
        },
        showschedules(item){
            window.location = '/local/grupomakro_core/pages/scheduleapproval.php?id=' + item.id
        }
    },
    computed: {
        lang(){
            return window.strings
        },
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php';
        },
        token(){
            return window.userToken;
        }
    },
    watch: {
        
    },
})