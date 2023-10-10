Vue.component('movestudents',{
    template: `
        <div>
            <v-dialog
              v-model="movedialog"
              max-width="600"
            >
                <v-card>
                    <v-card-title>{{lang.move_to}}</v-card-title>
                  
                    <v-divider class="my-0"></v-divider>
          
                    <v-card-text>
                        <v-list  subheader three-line>
                            <v-subheader class="text-h6">{{lang.classschedule}}</v-subheader>
                            <v-list-item-group
                              v-model="selectedClass"
                              color="primary"
                            >
                                <v-list-item
                                    v-for="folder in items"
                                    :key="folder.title"
                                     @click="newClassSelected(folder)"
                                >
                                    <v-list-item-avatar>
                                        <v-icon
                                          class="grey lighten-1"
                                          small
                                          :dark="!$vuetify.theme.isDark"
                                        >
                                            mdi-folder
                                        </v-icon>
                                    </v-list-item-avatar>
                            
                                    <v-list-item-content>
                                        <v-list-item-title v-text="folder.name"></v-list-item-title>
                                        <v-list-item-subtitle v-text="folder.days"></v-list-item-subtitle>
                                        <v-list-item-subtitle v-text="folder.start + ' a ' + folder.end"></v-list-item-subtitle>
                                    </v-list-item-content>
                            
                                    <v-list-item-action>
                                        <v-menu
                                          :close-on-content-click="false"
                                          :nudge-width="180"
                                          bottom
                                          left
                                          open-on-hover
                                        >
                                            <template v-slot:activator="{ on, attrs }">
                                                <v-btn
                                                  icon
                                                  v-bind="attrs"
                                                  v-on="on"
                                                >
                                                    <v-icon color="grey lighten-1">mdi-information</v-icon>
                                                </v-btn>
                                            </template>
                                  
                                            <v-card>
                                                <v-list dense>
                                                    <v-list-item>
                                                        <v-list-item-avatar>
                                                            <img
                                                              :src="folder.picture"
                                                              alt="profile"
                                                            >
                                                        </v-list-item-avatar>
                                    
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{folder.instructor}}</v-list-item-title>
                                                            <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                                                        </v-list-item-content>
                                                    </v-list-item>
                                                </v-list>
                                    
                                                <v-divider class="my-0"></v-divider>
                                    
                                                <v-list dense>
                                                    <v-list-item>
                                                        <v-list-item-icon>
                                                            <v-icon v-if="folder.type === 'Virtual'">mdi-desktop-mac</v-icon>
                                                            <v-icon v-else >mdi-account-group</v-icon>
                                                        </v-list-item-icon>
                                                        
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{folder.type}}</v-list-item-title>
                                                        </v-list-item-content>
                                                    </v-list-item>
                                      
                                                    <v-list-item>
                                                        <v-list-item-icon>
                                                            <v-icon>mdi-account-multiple-check</v-icon>
                                                        </v-list-item-icon>
                                                        
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{folder.users}}</v-list-item-title>
                                                        </v-list-item-content>
                                                    </v-list-item>
                                                </v-list>
                                            </v-card>
                                        </v-menu>
                                    </v-list-item-action>
                                </v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-card-text>
                
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           text
                           @click="movedialog = false, $emit('close')"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                           color="primary"
                           text
                           @click="saveclass"
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog> 
        </div>
    `,
    data(){
        return{
            movedialog: true,
            selectedClass: '',
            dataCourse: {},
            items: [],
            periodsIds: '',
            params: {}
        }
    },
    props:{
        classArray: Array,
        icondisabled: Boolean
    },
    created(){
        
    },
    mounted(){
        this.getClass()
    },  
    methods:{
        getClass(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            this.periodIds = periods
            console.log(periods)
            
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules',
                courseId: this.courseId,
                periodIds: periods
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response)
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.courseSchedules)
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    console.log(array[0])
                    
                    this.dataCourse.name = array[0].courseName
                    this.dataCourse.id = array[0].courseId
                    this.dataCourse.learningPlanIds = array[0].learningPlanIds
                    this.dataCourse.learningPlanNames = array[0].learningPlanNames
                    this.dataCourse.periodIds = array[0].periodIds
                    this.dataCourse.periodNames = array[0].periodNames
                    this.dataCourse.schedules = array[0].schedules
                    
                    // Add the availability data for each instructor to the current instance's item array.
                    this.dataCourse.schedules.forEach((element)=>{
                        if(element.approved == 0){
                            this.items.push({
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
                            })
                        }
                    })
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        newClassSelected(item){
            console.log(item)
            this.params.classid = item.id
        },
        saveclass(){
            console.log('entro')
            if(this.icondisabled){
                this.$emit('class-all-emit',this.params)
            }else{
                this.$emit('classmoveselected',this.params)
            }
        }
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
    
})