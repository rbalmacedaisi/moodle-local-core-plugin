Vue.component('waitingusers',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-card class="overflow-hidden">
                    <v-app-bar
                      absolute
                      elevate-on-scroll
                      scroll-target="#scrolling-techniques-7"
                      app
                      max-height="60"
                    >
                        <v-toolbar-title>{{lang.waitinglists}}</v-toolbar-title>
        
                        <v-spacer></v-spacer>
              
                        <div v-if="totalSelectedUsers.length > 1"  class="px-3 mb-0 d-flex">
                            <v-spacer></v-spacer>
                            
                            <v-tooltip bottom>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      :color="!$vuetify.theme.isDark ? 'secondary' : 'primary'"
                                      icon
                                      v-bind="attrs"
                                      v-on="on"
                                      large
                                      @click="moveAll"
                                    >
                                        <v-icon
                                          v-bind="attrs"
                                          v-on="on"
                                          @click="moveItem(item)"
                                        >
                                            mdi-folder-move-outline
                                        </v-icon>
                                    </v-btn>
                                </template>
                                <span>{{lang.move_to}}</span>
                            </v-tooltip>
                    
                            <v-tooltip bottom>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      :color="!$vuetify.theme.isDark ? 'secondary' : 'primary'"
                                      icon
                                      v-bind="attrs"
                                      v-on="on"
                                      large
                                      @click="deleteAll"
                                    >
                                        <v-icon
                                          v-bind="attrs"
                                          v-on="on"
                                        >
                                            mdi-trash-can-outline
                                        </v-icon>
                                    </v-btn>
                                </template>
                                <span>{{ lang.remove }}</span>
                            </v-tooltip>
                        </div>
                    </v-app-bar>
            
                    <v-sheet
                      id="scrolling-techniques-7"
                      class="overflow-y-auto px-0"
                      max-height="700"
                    >
                        <v-card-text class="mt-10">
                            <div class="px-0 mb-2">
                                <v-checkbox 
                                  v-model="selectAll" 
                                  :label="lang.selectall" 
                                  id="selectall" class="px-3" 
                                  hide-details 
                                  :indeterminate="totalSelectedUsers.length > 0 && totalSelectedUsers.length < totalStudent"
                                  :input-value="valuechecked"
                                 ></v-checkbox>
                            </div>
                            
                            <waitingtable
                              v-for="(classData, index) in filteredClassArray"
                              :key="index"
                              :classData="classData"
                              class="mb-8"
                              :selectusers="selectAll"
                              @selection-changed="updateTotalSelected"
                              ref="waitingtable"
                            ></waitingtable>
                        </v-card-text>
                    </v-sheet>
          
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text>
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn color="primary" text>
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        
            <v-dialog
              v-model="movedialog"
              max-width="600"
            >
                <v-card>
                    <v-card-title>Mover a:</v-card-title>
                  
                    <v-divider class="my-0"></v-divider>
          
                    <v-card-text>
                        <v-list  subheader three-line>
                            <v-subheader class="text-h6">Clases</v-subheader>
                            <v-list-item-group
                              v-model="selectedClass"
                              color="primary"
                            >
                                <v-list-item
                                    v-for="folder in folders"
                                    :key="folder.title"
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
                           @click="movedialog = false"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                           color="primary"
                           text
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog> 
        
            <deleteusers v-if="deleteusers" :itemdelete="itemdelete" @close-delete="closedelete"></deleteusers>
        </v-row>
    `,
    data(){
        return{
            selectAll: false,
            folders:[
                {
                    id: 1,
                    name: 'Introducción',
                    days: 'Lunes - Miércoles',
                    start: "10:00 am",
                    end: "12:00 pm",
                    instructor: "John Leider",
                    type: "Presencial",
                    picture: 'https://berrydashboard.io/vue/assets/avatar-1-8ab8bc8e.png',
                    quotas: 30,
                    users: 20,
                    waitingusers: 0,
                    registeredusers:[
                        {
                            userid: 20,
                            fullname: 'Andres Mejia',
                            email: 'andresmejia@gmail.com'
                        },
                        {
                            userid: 21,
                            fullname: 'Alejandro Rios',
                            email: 'alejandrorios@gmail.com'
                        },
                        {
                            userid: 22,
                            fullname: 'Ana Garcia',
                            email: 'anagarcia@gmail.com'
                        }
                    ],
                    waitinglist: []
                },
                {
                    id: 2,
                    name: 'Introducción',
                    days: 'Martes - Jueves',
                    start: "07:00 am",
                    end: "09:00 am",
                    instructor: "Ximena Rincon",
                    type: "Virtual",
                    picture: 'https://berrydashboard.io/vue/assets/avatar-3-7182280e.png',
                    quotas: 30,
                    users: 32,
                    waitingusers: 5,
                    registeredusers:[
                        {
                            userid: 20,
                            fullname: 'Andres Mejia',
                            email: 'andresmejia@gmail.com'
                        },
                        {
                            userid: 21,
                            fullname: 'Alejandro Rios',
                            email: 'alejandrorios@gmail.com'
                        },
                        {
                            userid: 22,
                            fullname: 'Ana Garcia',
                            email: 'anagarcia@gmail.com'
                        }
                    ],
                    waitinglist: [
                        {
                            userid: 23,
                            fullname: 'Ismael Mejia',
                            email: 'ismaelmejia@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 24,
                            fullname: 'Ivan Morales',
                            email: 'ivanmorales@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 25,
                            fullname: 'John Morales',
                            email: 'john@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 26,
                            fullname: 'Laura Londoño',
                            email: 'lauralondoño@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 27,
                            fullname: 'Marcela Toro',
                            email: 'marcelatoro@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                    ]
                },
                {
                    id: 3,
                    name: 'Introducción',
                    days: 'Jueves - Viernes ',
                    start: "07:00 am",
                    end: "09:00 am",
                    instructor: "Luz Lopez",
                    type: "Virtual",
                    picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                    quotas: 30,
                    users: 1,
                    waitingusers: 5,
                    registeredusers:[
                        {
                            userid: 20,
                            fullname: 'Andres Mejia',
                            email: 'andresmejia@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                    ],
                    waitinglist: [
                        {
                            userid: 23,
                            fullname: 'Ismael Mejia',
                            email: 'ismaelmejia@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 24,
                            fullname: 'Ivan Morales',
                            email: 'ivanmorales@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 25,
                            fullname: 'John Morales',
                            email: 'john@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 26,
                            fullname: 'Laura Londoño',
                            email: 'lauralondoño@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                        {
                            userid: 27,
                            fullname: 'Marcela Toro',
                            email: 'marcelatoro@gmail.com',
                            img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png'
                        },
                    ]
                },
                {
                    id: 4,
                    name: 'Introducción',
                    days: 'Sabado',
                    start: "07:00 am",
                    end: "09:00 am",
                    instructor: "Luz Lopez",
                    type: "Presencial",
                    picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                    quotas: 30,
                    users: 0,
                    waitingusers: 0,
                    registeredusers:[],
                    waitinglist: []
                }
            ],
            movedialog: false,
            deleteusers: false,
            itemdelete: {},
            selectedClass: '',
            classArray: [],
            selectAllStudents: false,
            selected: [],
            totalSelectedUsers: [],
            indeterminate: false,
            totalStudent: 0,
            valuechecked: false
        }
    },
    props:{},
    created(){
        this.getUsers()
        this.getschedules()
    }, 
    mounted(){
    },  
    methods:{
        getUsers(){
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_class_schedules_queues',
                courseId: this.courseId,
                periodIds: this.periodsid
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    //console.log(response)
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.courseSchedulesQueues)
                    //console.log(data)
                
                    this.classArray = data
                    this.calculateTotalStudents()
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        getschedules(){
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules',
                courseId: this.courseId,
                periodIds: this.periodsid
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
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        moveAll(){
            this.movedialog = true
        },
        deleteAll(){
            this.deleteusers = true
            this.itemdelete = {}
        },
        closedelete(){
            this.deleteusers = false
        },
        moveItem(item){
            console.log(item)
            this.folders = []
            const index = this.selected.findIndex(selectedItem => selectedItem.student === item.student);
            if (index === -1) {
                this.selected.push(item);
            } else {
                this.selected.splice(index, 1);
            }
            const id = item.classid
            this.items.forEach((element) => {
                if(element.id != id){
                    console.log(element)
                    this.folders.push(element)
                }
            })
            this.moveTitle = item.student
        
            this.movedialog = true
        },
        deleteAvailabilityRecord(item){
        },
        updateTotalSelected(e) {
            //console.log(this.$refs.waitingtable)
            this.totalSelectedUsers = []
            //console.log(e)
            this.$refs.waitingtable.forEach((element) => {
                //console.log(element.selected)
                element.selected.forEach((item) => {
                    this.totalSelectedUsers.push(item)
                })
            })
            this.inputvalue()
        },
        calculateTotalStudents(){
            this.totalStudent = 0
            ;
            this.classArray.forEach((clase) => {
              if (clase.queue && clase.queue.queuedStudents) {
                this.totalStudent += Object.keys(clase.queue.queuedStudents).length;
              }
            });
        },
        inputvalue(){
            if(this.totalSelectedUsers.length > 0 && this.totalSelectedUsers.length == this.totalStudent){
                this.valuechecked = true
                this.selectAll = true
            }else if(this.totalSelectedUsers.length > 0 && this.totalSelectedUsers.length < this.totalStudent){
                this.valuechecked = false
            }else if(this.totalSelectedUsers.length == 0 ){
                this.valuechecked = false
                this.selectAll = false
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
        },
        periodsid(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            return periods
        },
        filteredClassArray() {
          // Filtra las clases que tienen estudiantes en la cola
          return this.classArray.filter(classData => {
            return Object.keys(classData.queue.queuedStudents).length > 0;
          });
        },
    },
    watch: {
        inputvalue(value){
            console.log(value)
        }
    }
})