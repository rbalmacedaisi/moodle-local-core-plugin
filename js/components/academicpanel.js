Vue.component('academicpanel',{
    template: `
        <v-container fluid style="max-width: 100% !important;" class="w-100">
            <v-row justify="center" class="my-2 mx-0 ">
                <v-col cols="12" class="py-0 px-0">
                    <div class="panel-content">
                        <v-card v-for="(item, index) in  items" :key="index" 
                           class="pa-4 d-flex align-center rounded-lg text-decoration-none" 
                           outlined 
                           style="column-gap: 0.5rem;"
                           :href="item.url"
                           :target="item.name == 'Calendario'? '_blank' : ''"
                        >
                            <div class="pa-2 rounded-lg" :style="{background: item.iconColor}">
                                <v-icon :color="item.iconText">{{item.icon}}</v-icon>
                            </div>
                            <h4 class="mb-0">{{item.name}}</h4>
                        </v-card>
                    </div>
                </v-col>
            </v-row>
            
            <v-row class="mx-0">
                <v-col clos="12" class="py-0 px-0">
                    <v-tabs
                      v-model="tab"
                      background-color="transparent"
                      color="primary"
                      style="border-bottom: 1px solid #afafaf;}"
                    >
                        <v-tab
                          v-for="item in tabs"
                          :key="item"
                        >
                            {{ item }}
                        </v-tab>
                    </v-tabs>
                </v-col>
            </v-row>
            
            <v-row class="mx-0">
                <v-col cols="12" class="py-0 px-0">
                    <section v-if="tab == 0" id="studentinfo">
                      <studenttable></studenttable>
                    </section>
                    <section v-if="tab == 1" id="carrersinfo">
                      <academicoffer></academicoffer>
                    </section>
                </v-col>
            </v-row>  
        </v-container>
    `,
    data(){
        return{
            items:[
                {
                    name: 'Calendario',
                    icon: 'mdi-calendar-text',
                    iconColor: '#F4B4DB',
                    iconText: '#492053',
                    url: '/local/grupomakro_core/pages/academiccalendar.php'
                },
                {
                    name: 'Clases',
                    icon: 'mdi-school',
                    iconColor: '#A7D6F5',
                    iconText: '#133962',
                    url: '/local/grupomakro_core/pages/classmanagement.php'
                },
                {
                    name: 'Horarios',
                    icon: 'mdi-calendar-clock',
                    iconColor: '#FABFA2',
                    iconText: '#59402e',
                    url: '/local/grupomakro_core/pages/schedulepanel.php'
                },
                {
                    name: 'Instructores',
                    icon: 'mdi-clipboard-account',
                    iconColor: '#B5E8B8',
                    iconText: '#143f34',
                    url: '/local/grupomakro_core/pages/availabilitypanel.php'
                },
            ],
            tab: null,
            tabs: ['Estudiantes', 'oferta académica'],
            dialogCalendario: false
        }
    },
    created(){
    },  
    methods:{
        
    },
    computed: {
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings
        },
        /**
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        },
    },
    watch: {
    }
})