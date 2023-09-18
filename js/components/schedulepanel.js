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
                              v-for="n in item.numberclasses"
                              :key="n"
                              class="rounded-circle mr-1"
                              style="width: 10px; height: 10px;display: inline-flex;"
                              :class="getColor(item.users)"
                            ></span>
                        </div>
                      </div>
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
                { text: 'periods', value: 'period', sortable: false, class: 'd-none' },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'center' },
                
            ],
            items: [
                {
                    id: 1,
                    coursename: 'Ingles 1',
                    numberclasses: 5,
                    users: 4,
                    period: 'Cuatrimestre 1'
                },
                {
                    id: 2,
                    coursename: 'Matemáticas 1',
                    numberclasses: 5,
                    users: 3,
                    period: 'Cuatrimestre 1'
                },
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
                if (num >= 20) return ' red accent-2'
                else if (num >= 10) return 'amber lighten-4'
                else return 'green accent-3'
            }else{
                if (num >= 20) return 'red accent-2'
                else if (num >= 10) return 'amber lighten-4'
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
    },
    watch: {
        
    },
})