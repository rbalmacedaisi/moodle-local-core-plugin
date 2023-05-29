Vue.component('availabilitytable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="items"
                   class="elevation-1 paneltable"
                   :search="search"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>Selección de Horarios</v-toolbar-title>
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
                    
                    <template v-slot:item.users="{ item }">
                      <v-chip
                        :color="getColor(item.users)"
                        small
                        light
                      >
                        {{ item.users }}
                      </v-chip>
                    </template>
                    
                    <template v-slot:item.actions="{ item }">
                        <v-btn
                          outlined
                          color="primary"
                          small
                          class="rounded"
                          @click="showschedules(item)"
                        >
                          Horarios
                        </v-btn>
                    </template>
                    <template v-slot:no-data>
                        <span >No hay datos</span>
                    </template>
                </v-data-table>
            </v-col>
           
        </v-row>
    `,
    data(){
        return{
            token: '33513bec0b3469194c7756c29bf9fb33',
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
                { text: 'Acciones', value: 'actions', sortable: false, align: 'center' },
            ],
            items: [
                {
                    id: 1,
                    coursename: 'Maquinaría',
                    numberclasses: 2,
                    users: 10,
                    period: 'Cuastrimestre 1'
                },
                {
                    id: 2,
                    coursename: 'Matemáticas',
                    numberclasses: 1,
                    users: 20,
                    period: 'Cuastrimestre 2'
                },
                {
                    id: 3,
                    coursename: 'Español',
                    numberclasses: 3,
                    users: 1,
                    period: 'Cuastrimestre 1'
                }
            ],
            dialog: false,
        }
    },
    props:{},
    created(){
    }, 
    mounted(){},  
    methods:{
        getColor (num) {
            if(!this.$vuetify.theme.dark){
                if (num >= 20) return 'green accent-3'
                else if (num >= 10) return 'amber lighten-4'
                else return 'red accent-2'
            }else{
                if (num >= 20) return 'green accent-4'
                else if (num >= 10) return 'amber lighten-4'
                else return 'red accent-2'
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
    },
    watch: {
        
    },
})