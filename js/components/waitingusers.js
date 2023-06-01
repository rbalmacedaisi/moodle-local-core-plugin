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
        
              <v-toolbar-title>Listas de espera</v-toolbar-title>
        
              <v-spacer></v-spacer>
              
              <div v-if="selected.length > 1" class="px-3 mb-0 d-flex">
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
                  <span>Mover a</span>
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
                  <span>Eliminar</span>
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
                  <v-checkbox v-model="selectAll" label="Seleccionar todo" id="selectall" class="px-3" hide-details></v-checkbox>
                </div>
                <v-data-table
                  v-model="selected"
                  :headers="headers"
                  :items="users"
                  item-key="student"
                  show-select
                  dense
                  :items-per-page="50"
                  hide-default-footer
                  class="check-table"
                >
                  <template v-slot:top>
                    <div class="px-3">
                      <h6 class="mb-0 "> Introducción</h6>
                      <span>Martes - Jueves 07:00 am a 09:00 am</span>
                    </div>
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
                          mdi-trash-can-outline
                        </v-icon>
                      </template>
                      <span>{{lang.remove}}</span>
                    </v-tooltip>
                  </template>
                </v-data-table>
                
                <v-data-table
                  v-model="selected"
                  :headers="headers"
                  :items="users2"
                  item-key="student"
                  show-select
                  dense
                  :items-per-page="50"
                  hide-default-footer
                  class="check-table mt-3"
                >
                  <template v-slot:top>
                    <h6 class="mb-0 px-3"> Introducción</h6>
                    <span class="px-3">Jueves - Viernes 07:00 am a 09:00 am</span>
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
                          mdi-trash-can-outline
                        </v-icon>
                      </template>
                      <span>{{lang.remove}}</span>
                    </v-tooltip>
                  </template>
                </v-data-table>
              </v-card-text>
            </v-sheet>
          
            <v-card-actions>
              <v-spacer></v-spacer>
              <v-btn
               color="primary"
               text
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
        </v-col>
        
        <v-dialog
          v-model="movedialog"
          max-width="600"
        >
          <v-card>
            <v-card-title>
              Mover a:
            </v-card-title>
              
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
        
        <deleteusers v-if="deleteusers" :itemdelete="itemdelete" @close-delete="closedelete"></deleteusers>
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
        users: [
          {
            id: 1,
            img: 'https://cdn.vuetifyjs.com/images/lists/1.jpg',
            student: 'Jason Oner',
            email: 'jasononer@gmail.com',
          },
          {
            id: 2,
            img: 'https://cdn.vuetifyjs.com/images/lists/2.jpg',
            student: 'Mike Carlson',
            email: 'mikecarlson@gmail.com',
          },
          {
            id: 3,
            img: 'https://cdn.vuetifyjs.com/images/lists/3.jpg',
            student: 'Cindy Baker',
            email: 'cindybaker@gmail.com',
          },
          {
            id: 4,
            img: 'https://cdn.vuetifyjs.com/images/lists/4.jpg',
            student: 'Ali Connors',
            email: 'aliconnors@gmail.com',
          },
        ],
        users2: [
          {
            id: 5,
            img: 'https://cdn.vuetifyjs.com/images/lists/1.jpg',
            student: 'Sergio Oner',
            email: 'sergiooner@gmail.com',
          },
          {
            id: 6,
            img: 'https://cdn.vuetifyjs.com/images/lists/2.jpg',
            student: 'Charls Carlson',
            email: 'charlscarlson@gmail.com',
          },
          {
            id: 7,
            img: 'https://cdn.vuetifyjs.com/images/lists/3.jpg',
            student: 'Ingri Baker',
            email: 'ingribaker@gmail.com',
          },
          {
            id: 8,
            img: 'https://cdn.vuetifyjs.com/images/lists/4.jpg',
            student: 'Ana Connors',
            email: 'anaconnors@gmail.com',
          },
        ],
        selected: [],
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
        selectedClass: ''
      }
    },
    props:{},
    created(){
    }, 
    mounted(){},  
    methods:{
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
        /*this.folders = []
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
        
        this.movedialog = true*/
      },
      deleteAvailabilityRecord(item){
        
      }
    },
    computed: {
      lang(){
        return window.strings
      },
    },
    watch: {
      selectAll(value) {
        // Al cambiar el estado del checkbox de selección general
        // Se actualiza la selección de elementos en ambas tablas
        this.selected = value ? [...this.users, ...this.users2] : [];
      }
    }
})