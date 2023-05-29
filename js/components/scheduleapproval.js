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
                          color="error"
                          class="mx-2 rounded text-capitalize"
                          small
                        >
                          usuarios en espera
                        </v-btn>
                        <v-btn
                          color="success"
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
                                                        <v-list-item>
                                                            <v-list-item-content>
                                                                <v-list-item-title>Usuarios Inscritos</v-list-item-title>
                                                            </v-list-item-content>
                                                        </v-list-item>
                                                        <v-list-item>
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
                                    <h5 class="mb-0">{{item.name}}</h5>
                                    <small class="text--disabled text-subtitle-2">{{item.days}}</small>
                                    
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
                                    </v-row>
                                </v-card-text>
                                
                                <v-card-actions class="justify-center">
                                    <v-btn
                                      class="ma-2 rounded text-capitalize"
                                      outlined
                                      color="error"
                                      small
                                    >
                                        <v-icon>mdi-delete-forever-outline</v-icon>
                                      Eliminar
                                    </v-btn>
                                    <v-btn
                                      class="ma-2 rounded text-capitalize"
                                      outlined
                                      color="success"
                                      small
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
                    users: 3
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
                    users: 5
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
                    users: 1
                }
            ],
            selectedItem: '',
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
        }
    },
})
