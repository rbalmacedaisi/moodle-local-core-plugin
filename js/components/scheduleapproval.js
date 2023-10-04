Vue.component('scheduleapproval',{
    template: `
         <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-card
                    class="mx-auto"
                    max-width="100%"
                  >
                    <v-card-title class="d-flex">
                        <div class="d-flex flex-column">
                            <span>{{lang.schedules}} - {{dataCourse.name}}</span>
                            <span class="text-caption text--secondary">{{dataCourse.periodNames}}</span>
                        </div>
                        
                        <v-spacer></v-spacer>
                        <v-btn
                          :color="$vuetify.theme.isDark ? 'primary' : 'secondary'"
                          class="mx-2 rounded text-capitalize"
                          small
                          :outlined="$vuetify.theme.isDark"
                          @click="userspage"
                        >
                          {{lang.users}}
                        </v-btn>
                        <v-btn
                          color="primary"
                          class="mx-2 rounded text-capitalize"
                          small
                        >
                          {{lang.approve_schedules}}
                        </v-btn>
                    </v-card-title>
                    
                    <v-divider class="my-0"></v-divider>
                
                    <v-card-text 
                       class="px-8 py-8 pb-10">
                        <v-row>
                            <v-col cols="12" sm="12" md="6" lg="4" xl="3" v-for="(item, index) in items" :key="item.id" >
                                <v-card
                                    class="rounded-md pa-2"
                                    :color="!$vuetify.theme.isDark ? 'grey lighten-5' : ''"
                                    outlined
                                    style="border-color: rgb(208,208,208) !important;"
                                    width="100%"
                                >
                                    <v-list flat color="transparent">
                                        <v-list-item>
                                            <v-list-item-avatar size="65">
                                              <img
                                                :src="item.picture" alt="picture" width="72"
                                              >
                                            </v-list-item-avatar>
                            
                                            <v-list-item-content>
                                              <v-list-item-title>{{item.instructor}}</v-list-item-title>
                                              <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                                            </v-list-item-content>
                            
                                            <v-list-item-action>
                                                <v-menu
                                                    bottom
                                                    left
                                                >
                                                    <template v-slot:activator="{ on, attrs }">
                                                      <v-btn
                                                        icon
                                                        v-bind="attrs"
                                                        v-on="on"
                                                      >
                                                        <v-icon>mdi-dots-horizontal</v-icon>
                                                      </v-btn>
                                                    </template>
                                        
                                                    <v-list dense>
                                                        <v-list-item-group  v-model="selectedItem" color="primary">
                                                            <v-list-item @click="scheduleSelected(item)">
                                                                <v-list-item-icon>
                                                                    <v-icon>mdi-account-check</v-icon>
                                                                </v-list-item-icon>
                                                                <v-list-item-content>
                                                                    <v-list-item-title>{{lang.registered_users}}</v-list-item-title>
                                                                </v-list-item-content>
                                                            </v-list-item>
                                                            
                                                            <v-list-item @click="waitinglist(item)">
                                                                <v-list-item-icon>
                                                                    <v-icon>mdi-account-clock</v-icon>
                                                                </v-list-item-icon>
                                                                <v-list-item-content>
                                                                    <v-list-item-title>{{lang.waitinglist}}</v-list-item-title>
                                                                </v-list-item-content>
                                                            </v-list-item>
                                                        </v-list-item-group>
                                                    </v-list>
                                                  </v-menu>
                                            </v-list-item-action>
                                      </v-list-item>
                                    </v-list>
                                
                                    <v-card-text class="pt-2">
                                        <div class="d-flex"> 
                                            <h5 class="mb-0 d-flex flex-column">{{item.name}}
                                                <small class="text--disabled text-subtitle-2">{{item.days}}</small>
                                            </h5>
                                            
                                            <v-spacer></v-spacer>
                                            
                                            <v-chip v-if="item.isApprove > 0"
                                              class="ma-2"
                                              label
                                              small
                                              color="success"
                                            >
                                              {{ lang.approved }}
                                            </v-chip>
                                        </div>
                                        
                                        <v-row class="mt-2">
                                            <v-col cols="6" class="py-2"> 
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.class_schedule }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.start + ' - ' + item.end }}</p>
                                            </v-col>
                                            
                                            <v-col cols="6" class="py-2"> 
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.class_type }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.type }}</p>
                                            </v-col>
                                            <v-col cols="6" class="py-2">
                                                <span class="d-block text--disabled  text-subtitle-2">{{ lang.quotas_enabled }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.quotas }}</p>
                                            </v-col>
                                            <v-col cols="6" class="py-2">
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.registered_users }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.users }}</p>
                                            </v-col>
                                            <v-col cols="6" class="py-2">
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.waitingusers }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.waitingusers }}</p>
                                            </v-col>
                                        </v-row>
                                    </v-card-text>
                                
                                    <v-card-actions class="justify-center">
                                        <v-btn
                                          class="ma-2 rounded text-capitalize"
                                          outlined
                                          color="secondary"
                                          small
                                          @click="showdelete(item)"
                                        >
                                            <v-icon>mdi-delete-forever-outline</v-icon>
                                            {{ lang.remove }}
                                        </v-btn>
                                        
                                        <v-btn
                                          class="ma-2 rounded text-capitalize"
                                          outlined
                                          color="primary"
                                          small
                                          @click="showapprove(item)"
                                          :disabled="item.users == 0"
                                        >
                                            <v-icon>mdi-account-multiple-check-outline</v-icon>
                                            {{ lang.approve_users }}
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
            
            <v-dialog
              v-model="dialog"
              max-width="800"
            >
                <v-card>
                    <v-card-title>
                        {{scheldule.title}}
                    </v-card-title>
                    
                    <v-card-subtitle>
                      {{scheldule.days}} {{scheldule.hours}}
                    </v-card-subtitle>
                    
                    <v-card-text>
                        <v-data-table
                            v-model="selected"
                            :headers="headers"
                            :items="users"
                            item-key="student"
                            show-select
                            dense
                            :items-per-page="50"
                            hide-default-footer
                        >
                            <template v-slot:top>
                                <h6>{{tabletitle}}</h6>
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
                                           @click="moveItem(item)"
                                        >
                                            mdi-folder-move-outline
                                        </v-icon>
                                    </template>
                                    <span>{{ lang.move_to }}</span>
                                </v-tooltip>
                                
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon 
                                           @click="deleteAvailabilityRecord(item)" 
                                           v-bind="attrs"
                                           v-on="on"
                                        >
                                            mdi-delete
                                        </v-icon>
                                    </template>
                                    <span>{{ lang.remove }}</span>
                                </v-tooltip>
                            </template>
                        </v-data-table>
                    </v-card-text>
                    
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           text
                           @click="close"
                        >
                            {{ lang.cancel }}
                        </v-btn>
                        <v-btn
                           color="primary"
                           text
                        >
                            {{ lang.save }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            
            <v-dialog
              v-model="movedialog"
              max-width="600"
            >
                <v-card>
                    <v-card-title>
                        {{ lang.move_to }} "{{moveTitle}}"
                    </v-card-title>
                    
                    <v-card-subtitle class="d-flex align-center mt-1">
                        {{ lang.current_location }}
                        
                        <v-btn
                          color="secondary"
                          class="rounded ml-2"
                          small
                        >
                            <v-icon left>
                                mdi-folder-account-outline
                            </v-icon>
                            {{scheldule.title}}
                        </v-btn>
                    </v-card-subtitle>
                    
                    <v-divider class="my-0"></v-divider>
                    
                    <v-card-text>
                        <v-list
                          subheader
                          three-line
                        >
                            <v-subheader class="text-h6">Clases</v-subheader>
                            <v-list-item-group
                                v-model="selectedClass"
                                color="primary"
                            >
                                <v-list-item
                                    v-for="folder in folders"
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
                           @click="movedialog = false"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        
                        <v-btn
                           color="primary"
                           text
                           @click="saveClass"
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            
            <v-dialog
              v-model="approvalReasonField"
              persistent
              max-width="600px"
            >
                <v-card>
                    <v-card-title>
                        <span class="text-h5">Mensaje para Aprobación</span>
                    </v-card-title>
                    <v-card-text class="pb-0">
                        <v-container>
                            <v-row>
                                <v-col
                                   cols="12"
                                   class="px-0"
                                >
                                    <v-textarea
                                      outlined
                                      name="message"
                                      label="Mensaje"
                                      v-model="messageAproval"
                                      hint="Mensaje para justificar aprovación de horario."
                                      rows="3"
                                    ></v-textarea>
                                </v-col>
                            </v-row>
                        </v-container>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           outlined
                           color="primary"
                           small
                           @click="approvalReasonField = false"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                            color="primary"
                            text
                            @click="sendMessage"
                            small
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            
            <deleteclass v-if="deleteclass" :itemdelete="itemdelete" @close-delete="closedelete"></deleteclass>
            <approveusers v-if="approveusers" :itemapprove="usersapprove" @close-approve="closeapprove"></approveusers>
        </v-row>
    `,
    data(){
        return{
            items:[],
            selectedItem: '',
            dialog: false,
            singleSelect: false,
            selected: [],
            headers: [
              {
                text: window.strings.student,
                align: 'start',
                sortable: false,
                value: 'student',
              },
              { text: window.strings.actions, value: 'actions', sortable: false },
            ],
            users: [],
            scheldule:{},
            movedialog: false,
            moveTitle: '',
            folders:[
            ],
            selectedClass: '',
            menu: false,
            tabletitle: '',
            deleteclass: false,
            itemdelete: {},
            approveusers: false,
            usersapprove: {},
            approved: false,
            dataCourse: {},
            schedulesAproveds: [],
            approvalReasonField: false,
            messageAproval: '',
            messagesOk: false,
            params: {},
            horariosparams:[]
        }
    },
    props:{},
    created(){
      this.getData()
    },
    mounted(){
        
    },  
    methods:{
        getData(){
            // Obtén la URL actual de la página
            var currentURL = window.location.href;
            
            // Obtén el valor del parámetro "id" de la URL actual
            var siteurl = new URL(currentURL);
            var id = siteurl.searchParams.get("id");
            
            // Obtén el valor del parámetro "periodsid35,33" de la URL actual
            var periods = siteurl.searchParams.get("periodsid");
            
            const url = this.siteUrl;
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
                            registeredusers: [],
                            waitinglist:[]
                        })
                    })
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        scheduleSelected(item){
            this.users = []
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_students_by_class_schedule',
                classId: item.clasId
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    const data = JSON.parse(response.data.classStudents)
                    console.log(data)
                    const preRegisteredStudents = data.preRegisteredStudents
                    
                    // Convertir los datos en un array
                    var dataArray = Object.values(preRegisteredStudents);
                    
                    // Ahora, dataArray es un array que contiene los objetos de usuario
                    console.log(dataArray);
                    
                    if(dataArray.length > 0){
                        dataArray.forEach((element) => {
                            this.users.push({
                                student: element.firstname + ' ' + element.lastname,
                                id: element.userid,
                                email: element.email,
                                img: element.profilePicture,
                                classid: element.classid,
                                //schedule: item.start + ' a ' + item.end,
                                //instructor: item.instructor,
                                //name: item.name,
                                /*quotas: item.quotas,
                                type: item.type,
                                users: item.users,
                                waitingusers: item.waitingusers,
                                days: item.days,
                                classid: item.id*/
                            })
                        })
                    }
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
    
            this.scheldule.title = item.name
            this.scheldule.days = item.days
            this.scheldule.hours = item.start + ' a ' + item.end
            this.dialog = true
        },
        waitinglist(item){
            console.log(item)
            this.users = []
            this.tabletitle = ''
            
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_students_by_class_schedule',
                classId: item.clasId
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    const data = JSON.parse(response.data.classStudents)
                    console.log(data)
                    const queuedStudents = data.queuedStudents
                    
                    // Convertir los datos en un array
                    var dataArray = Object.values(queuedStudents);
                    
                    // Ahora, dataArray es un array que contiene los objetos de usuario
                    console.log(dataArray);
                    
                    if(dataArray.length > 0){
                        dataArray.forEach((element) => {
                            this.users.push({
                                student: element.firstname + ' ' + element.lastname,
                                id: element.userid,
                                email: element.email,
                                img: element.profilePicture,
                                classid: element.classid,
                                //schedule: item.start + ' a ' + item.end,
                                //instructor: item.instructor,
                                //name: item.name,
                                /*quotas: item.quotas,
                                type: item.type,
                                users: item.users,
                                waitingusers: item.waitingusers,
                                days: item.days,
                                classid: item.id*/
                            })
                        })
                    }
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
            
            this.scheldule.title = item.name
            this.scheldule.days = item.days
            this.scheldule.hours = item.start + ' a ' + item.end
            this.tabletitle = 'Usuarios en Espera'
            this.dialog = true
        },
        close(){
            this.dialog = false
            this.users = []
            this.movedialog = false
            this.selectedItem = ''
        },
        moveItem(item){
            console.log(item)
            this.folders = []
            const index = this.selected.findIndex(selectedItem => selectedItem.student === item.student);
            if (index === -1) {
              this.selected.push(item);
            } else {
              //this.selected.splice(index, 1);
            }
            const id = item.classid
            this.items.forEach((element) => {
                if(element.id != id && element.isApprove  == 0){
                    this.folders.push(element)
                }
            })
            this.moveTitle = item.student
            
            this.movedialog = true
        },
        showdelete(item){
            this.itemdelete = item
            this.deleteclass = true
        },
        closedelete(){
            this.deleteclass = false
        },
        closeapprove(){
            this.approveusers = false
        },
        showapprove(item){
            this.params = {}
            console.log(item)
            this.messageAproval = ''
            this.schedulesAproveds.push(item)
            
            const url = this.siteUrl;
            this.params.wstoken = this.token
            this.params.moodlewsrestformat = 'json'
            this.params.wsfunction = 'local_grupomakro_approve_course_class_schedules'
            
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.schedulesAproveds.length; i++) {
              const schedule = this.schedulesAproveds[i];
              this.params[`approvingSchedules[${i}][classId]`] = schedule.clasId;
              this.schedulesAproveds[i].paramsid = schedule.clasId
              if(schedule.quotas > schedule.users + schedule.waitingusers  || schedule.quotas < schedule.users + schedule.waitingusers){
                this.approvalReasonField = true
              }else{
                this.approvalReasonField = false
                this.approvedClass(this.params)
              }
            }
            
           // 
           
            
        },
        sendMessage(){
            for (let i = 0; i < this.schedulesAproveds.length; i++) {
              const schedule = this.schedulesAproveds[i];
                //this.schedulesAproveds[i].paramsmesage = this.messageAproval
                this.params[`approvingSchedules[${i}][approvalMessage]`] = this.messageAproval; 
            }
            this.approvalReasonField = false
            this.approvedClass(this.params)
        },
        approvedClass(params){
            console.log(params)
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response)
                    this.approveusers =  true
                    setInterval(()=>{
                        location.reload();
                    },5000)
                    //
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        userspage(){
            window.location = '/local/grupomakro_core/pages/users.php'
        },
        newClassSelected(schedule){
            
            const url = this.siteUrl;
            // Create an object to store dynamic parameters.
            const params = {};
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.selected.length; i++) {
              const student = this.selected[i];
              params[`movingStudents[${i}][studentId]`] = student.id;
              params[`movingStudents[${i}][currentClassId]`] = student.classid;
              params[`movingStudents[${i}][newClassId]`] = schedule.clasId; 
            }
            
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_change_students_schedules'
            
            this.saveClass(params)
            
        },
        saveClass(params){
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response.data)
                    this.movedialog = false
                    this.dialog = false
                    location.reload();
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        deleteAvailabilityRecord(item){
            console.log(item)
            const url = this.siteUrl;
            // Create an object to store dynamic parameters.
            const params = {};
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.selected.length; i++) {
              const student = this.selected[i];
              params[`deletedStudents[${i}][studentId]`] = student.id;
              params[`deletedStudents[${i}][classId]`] = student.classid;
            }
            
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_delete_student_from_class_schedule'
            
            this.deleteStudent(params)
        },
        deleteStudent(params){
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response.data)
                    this.movedialog = false
                    this.dialog = false
                    location.reload();
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        }
    },
     computed: {
        // This method returns a validation rule function for use with vee-validate library.
        // The function takes a value as input and returns a boolean indicating whether the value is non-empty or not.
        requiredRule() {
          return (value) => !!value || 'Este campo es requerido';
        },
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
