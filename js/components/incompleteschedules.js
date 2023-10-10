Vue.component('incompleteschedules',{
    template: `
      <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="users"
                   class="elevation-1 paneltable"
                   :search="search"
                   dense
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{lang.users}}</v-toolbar-title>
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
                    
                    <template v-slot:item.student="{ item }">
                        <v-list class="transparent">
                            <v-list-item class="pl-0">
                                <v-list-item-avatar>
                                    <img :src="item.img" alt="picture">
                                </v-list-item-avatar>
    
                                <v-list-item-content>
                                    <v-list-item-title>{{item.student}}</v-list-item-title>
                                    <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                      
                    <template v-slot:item.actions="{ item }">
                        <v-tooltip bottom>
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon
                                   class="mr-2"
                                   v-bind="attrs"
                                   v-on="on"
                                   @click="addschedule(item)"
                                >
                                    mdi-calendar-arrow-right
                                </v-icon>
                            </template>
                            <span>{{ lang.add_schedules }}</span>
                        </v-tooltip>
                    </template>
                  
                    <template v-slot:no-data>
                        <span >{{lang.nodata}}</span>
                    </template>
                </v-data-table>
            </v-col>
            
            <v-dialog
              v-model="dialog"
              max-width="400px"
            >
                <v-card>
                    <v-card-title>
                        <span class="text-h5">{{ lang.add_schedules }}</span>
                    </v-card-title>
    
                    <v-card-text>
                        <v-row>
                            <v-col cols="12">
                                <v-list
                                   flat
                                   three-line
                                >
                                    <v-list-item-group
                                       v-model="settings"
                                       active-class=""
                                    >
                                        <v-list-item v-for="item in schedules" :key="item.id" >
                                            <template v-slot:default="{ active}" >
                                                <v-list-item-action>
                                                    <v-checkbox :input-value="active" @change="handleCheckboxChange(item)"></v-checkbox>
                                                </v-list-item-action>
                                                <v-list-item-content>
                                                    <v-list-item-title>{{item.name}}</v-list-item-title>
                                                    <v-list-item-subtitle>{{item.days}}</v-list-item-subtitle>
                                                    <v-list-item-subtitle>{{item.start + ' - ' + item.end}}</v-list-item-subtitle>
                                                </v-list-item-content>
                                            </template>
                                        </v-list-item>
                                    </v-list-item-group>
                                </v-list>
                            </v-col>
                        </v-row>
                    </v-card-text>
                    
                    <v-divider class="my-0"></v-divider>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           text
                           @click="dialog = false"
                        >
                            {{ lang.cancel }}
                        </v-btn>
                        <v-btn
                           color="primary" text
                           @click="save"
                        >
                            {{ lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-row>
    `,
    data(){
      return{
        headers: [
            {
                text: 'Estudiante',
                align: 'start',
                sortable: false,
                value: 'student',
            },
            { text: 'Actions', value: 'actions', sortable: false },
        ],
        users: [],
        deleteusers: false,
        itemdelete: {},
        search: '',
        menu: false,
        itemselected: {},
        schedules: [
        ],
        dialog: false,
        settings: [],
        items: [],
        dataCourse: {},
        selectedItems: [],
      }
    },
    props:{},
    created(){
        this.getStudent()
        this.getschedules()
    }, 
    mounted(){},  
    methods:{
        getStudent(){
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_scheduleless_students',
                courseId: this.courseId,
                periodIds: periods
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.schedulelessStudents)
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    
                    array.forEach((element)=>{
                        this.users.push({
                            id: element.id,
                            img: element.profilePicture,
                            student: element.firstname + ' ' + element.lastname,
                            email: element.email,
                        })
                    })
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });  
        },
        getschedules(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules',
                courseId: this.courseId,
                periodIds: periods,
                skipApproved: 1
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.courseSchedules)
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    this.dataCourse.name = array[0].courseName
                    this.dataCourse.id = array[0].courseId
                    this.dataCourse.learningPlanIds = array[0].learningPlanIds
                    this.dataCourse.learningPlanNames = array[0].learningPlanNames
                    this.dataCourse.periodIds = array[0].periodIds
                    this.dataCourse.periodNames = array[0].periodNames
                    this.dataCourse.schedules = array[0].schedules
                    
                    // Add the availability data for each instructor to the current instance's item array.
                    this.dataCourse.schedules.forEach((element)=>{
                        this.schedules.push({
                            id: element.id,
                            name: element.name,
                            days: element.classDaysString,
                            start: element.inithourformatted,
                            end: element.endhourformatted,
                            instructor: element.instructorName,
                            type: element.typelabel,
                            picture: element.instructorProfileImage,
                            quotas: element.classroomcapacity,
                            users: element.preRegisteredStudents,
                            waitingusers: element.queuedStudents,
                            isApprove: element.approved,
                            clasId: element.id,
                            selected: false
                        })
                    })
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        addschedule(item){
            this.itemselected = item
            this.dialog = true
        },
        save(){
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_student_class_enrol',
                userId: this.itemselected.id,
                classId: this.selectedItems[0].clasId,
                forceQueue: 0
            };
            
            const url = this.siteUrl;
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response.data)
                    this.dialog = false
                    location.reload();
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        updateSettings() {
            this.settings = this.items.filter(item => item.selected);
        },
        handleCheckboxChange(item) {
            item.selected = !item.selected;
            const index = this.selectedItems.findIndex((selectedItem) => selectedItem.id === item.id);

            if (index === -1 && item.selected) {
              this.selectedItems.push(item);
            } else if (index !== -1 && !item.selected) {
              this.selectedItems.splice(index, 1);
            }
        },
    },
    computed: {
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
        lang(){
            return window.strings
        },
        token(){
            return window.userToken;
        },
        courseId(){
            return window.courseid;
        }
    },
    watch: {
    
    }
})