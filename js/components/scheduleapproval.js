Vue.component('scheduleapproval',{
    template: `
         <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-card
                    class="mx-auto"
                    max-width="100%"
                  >
                    <v-card-title class="d-flex">
                        Horarios - Maquinaría
                        <v-spacer></v-spacer>
                        <v-btn
                          :color="$vuetify.theme.isDark ? 'primary' : 'secondary'"
                          class="mx-2 rounded text-capitalize"
                          small
                          :outlined="$vuetify.theme.isDark"
                          @click="waitingpage"
                        >
                          usuarios en espera
                        </v-btn>
                        <v-btn
                          color="primary"
                          class="mx-2 rounded text-capitalize"
                          small
                        >
                          Aprobar Horarios
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
                                                                <v-list-item-title>Usuarios Inscritos</v-list-item-title>
                                                            </v-list-item-content>
                                                        </v-list-item>
                                                        <v-list-item @click="waitinglist(item)">
                                                            <v-list-item-icon>
                                                                <v-icon>mdi-account-clock</v-icon>
                                                            </v-list-item-icon>
                                                            <v-list-item-content>
                                                                <v-list-item-title>Lista de Espera</v-list-item-title>
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
                                        <v-chip v-if="item.isApprove"
                                          class="ma-2"
                                          label
                                          small
                                          color="success"
                                        >
                                          Aprovado
                                        </v-chip>
                                    </div>
                                    <v-row class="mt-2">
                                        <v-col cols="6" class="py-2"> 
                                            <span class="d-block text--disabled text-subtitle-2"> Horario de Clase</span>
                                            <p class="text-subtitle-2 font-weight-medium mb-0">{{item.start + ' - ' + item.end}}</p>
                                        </v-col>
                                        
                                        <v-col cols="6" class="py-2"> 
                                            <span class="d-block text--disabled text-subtitle-2"> Tipo de Clase</span>
                                            <p class="text-subtitle-2 font-weight-medium mb-0">{{item.type}}</p>
                                        </v-col>
                                        <v-col cols="6" class="py-2">
                                            <span class="d-block text--disabled  text-subtitle-2"> Cupos Habilitados</span>
                                            <p class="text-subtitle-2 font-weight-medium mb-0">{{item.quotas}}</p>
                                        </v-col>
                                        <v-col cols="6" class="py-2">
                                            <span class="d-block text--disabled text-subtitle-2"> Usuarios Inscritos</span>
                                            <p class="text-subtitle-2 font-weight-medium mb-0">{{item.users}}</p>
                                        </v-col>
                                        <v-col cols="6" class="py-2">
                                            <span class="d-block text--disabled text-subtitle-2"> Usuarios en Espera</span>
                                            <p class="text-subtitle-2 font-weight-medium mb-0">{{item.waitingusers}}</p>
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
                                      Eliminar
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
                                    
                                      Aprobar Usuarios
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
                                    <span>Mover a</span>
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
                                    <span>{{lang.remove}}</span>
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
            
            <v-dialog
              v-model="movedialog"
              max-width="600"
            >
                <v-card>
                    <v-card-title>
                        Mover "{{moveTitle}}"
                    </v-card-title>
                    <v-card-subtitle class="d-flex align-center mt-1">
                        Ubicación actual:
                        
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
            
            <deleteclass v-if="deleteclass" :itemdelete="itemdelete" @close-delete="closedelete"></deleteclass>
            <approveusers v-if="approveusers" :itemapprove="usersapprove" @close-approve="closeapprove"></approveusers>
        </v-row>
    `,
    data(){
        return{
            items:[
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
                    isApprove: true,
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
                    ]
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
                    isApprove: false,
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
                    isApprove: false,
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
                    isApprove: false,
                    registeredusers:[],
                    waitinglist: []
                }
            ],
            selectedItem: '',
            dialog: false,
            singleSelect: false,
            selected: [],
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
            approved: false
        }
    },
    props:['data'],
    created(){
      this.getData()
    },
    mounted(){
        
    },  
    methods:{
        getData(){
            const data = this.data
        },
        scheduleSelected(item){
            console.log(item)
            this.users = []
            if(item.registeredusers.length > 0){
                item.registeredusers.forEach((element) => {
                    this.users.push({
                        student: element.fullname,
                        id: element.userid,
                        email: element.email,
                        img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png',
                        schedule: item.start + ' a ' + item.end,
                        instructor: item.instructor,
                        name: item.name,
                        quotas: item.quotas,
                        type: item.type,
                        users: item.users,
                        waitingusers: item.waitingusers,
                        days: item.days,
                        classid: item.id
                    })
                })
            }
            this.scheldule.title = item.name
            this.scheldule.days = item.days
            this.scheldule.hours = item.start + ' a ' + item.end
            this.dialog = true
        },
        waitinglist(item){
            this.users = []
            this.tabletitle = ''
            if(item.waitinglist.length > 0){
                item.waitinglist.forEach((element) => {
                    this.users.push({
                        student: element.fullname,
                        id: element.userid,
                        email: element.email,
                        img: 'https://berrydashboard.io/vue/assets/avatar-4-3b96be4a.png',
                        schedule: item.start + ' a ' + item.end,
                        instructor: item.instructor,
                        name: item.name,
                        quotas: item.quotas,
                        type: item.type,
                        users: item.users,
                        waitingusers: item.waitingusers,
                        days: item.days,
                        classid: item.id
                    })
                })
            }
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
        showdelete(item){
            console.log(item)
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
            this.approveusers =  true
            this.usersapprove = item
        },
        waitingpage(){
            window.location = '/local/grupomakro_core/pages/waitingusers.php'
        },
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
    },
})
