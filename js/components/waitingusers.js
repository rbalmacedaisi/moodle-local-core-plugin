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
              
                        <div v-if="totalSelectedUsers.length > 0"  class="px-3 mb-0 d-flex">
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
                                          @click="moveAllItems"
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
                                      @click="deleteUsers"
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
                              @delete-users="deleteUsers"
                              @move-item="moveAll"
                              :icondisabled="icondisabled"
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
        
            
            <movestudents v-if="movedialog" @classmoveselected="moveItem" @close="closeMove" @class-all-emit="classallemit" :icondisabled="icondisabled"></movestudents>
            <deleteusers v-if="deleteusers" @delete-users="deleteAvailabilityRecord" @close-delete="closedelete"></deleteusers>
        </v-row>
    `,
    data(){
        return{
            selectAll: false,
            movedialog: false,
            deleteusers: false,
            itemdelete: {},
            classArray: [],
            selectAllStudents: false,
            selected: [],
            totalSelectedUsers: [],
            indeterminate: false,
            totalStudent: 0,
            valuechecked: false,
            icondisabled: false,
            individualmoveclass: {},
            
        }
    },
    props:{},
    created(){
        this.getUsers()
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
                    console.log(data)
                
                    this.classArray = data
                    this.calculateTotalStudents()
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        moveAll(item){
            this.movedialog = true
            this.individualmoveclass = item
        },
        deleteUsers(item){
            this.deleteusers = true
            this.itemdelete = {}
        },
        deleteAvailabilityRecord(){
            // Create an object to store dynamic parameters.
            const params = {};
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.totalSelectedUsers.length; i++) {
              const student = this.totalSelectedUsers[i];
              params[`deletedStudents[${i}][studentId]`] = student.userid;
              params[`deletedStudents[${i}][classId]`] = student.classid;
            }
            
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_delete_student_from_class_schedule'
            
            this.deleteStudent(params)
        },
        deleteStudent(params){
            const url = this.siteUrl;
            console.log(params)
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response)
                    location.reload();
                    this.closedelete()
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        closedelete(){
            this.deleteusers = false
        },
        moveItem(item){
            console.log(item)
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_change_students_schedules',
                'movingStudents[0][studentId]': this.individualmoveclass.userid,
                'movingStudents[0][currentClassId]': this.individualmoveclass.classid,
                'movingStudents[0][newClassId]': item.classid
            };
            console.log(params)
            this.updateclassselected(params)
        },
        classallemit(item){
            // Create an object to store dynamic parameters.
            const params = {};
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.totalSelectedUsers.length; i++) {
              const student = this.totalSelectedUsers[i];
              params[`movingStudents[${i}][studentId]`] = student.userid;
              params[`movingStudents[${i}][currentClassId]`] = student.classid;
              params[`movingStudents[${i}][newClassId]`] = item.classid; 
            }
            
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_change_students_schedules'
            console.log(params)
            this.updateclassselected(params)
        },
        moveAllItems(){
            this.movedialog = true
        },
        updateclassselected(params){
            const url = this.siteUrl;
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response.data)
                    this.movedialog = false
                    location.reload();
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
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
            this.totalSelectedUsers.length < 1 ? this.icondisabled = false : this.icondisabled = true
            this.inputvalue()
        },
        calculateTotalStudents(){
            this.totalStudent = 0;
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
        },
        closeMove(){
            this.movedialog = false
            this.individualmoveclass = {}
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
            // Filter classes that have students in the queue.
            return this.classArray.filter(classData => {
                return Object.keys(classData.queue.queuedStudents).length > 0;
            });
        },
    },
    watch: {
        
    }
})