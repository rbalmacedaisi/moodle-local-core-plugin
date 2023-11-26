Vue.component('studenttable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0 px-0">
                <v-data-table
                   :headers="headers"
                   :items="items"
                   class="elevation-1 paneltable"
                   dense
                   :search="search"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>Lista de estudiantes</v-toolbar-title>
                        </v-toolbar>
                        
                        <v-row justify="start" class="ma-0 mr-3 mb-2">
                            
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
                    
                    <template v-slot:item.name="{ item }">
                        <v-list class="transparent">
                            <v-list-item>
                                <v-list-item-avatar>
                                    <img
                                      src="https://cdn.vuetifyjs.com/images/john.jpg"
                                      alt="John"
                                    >
                                </v-list-item-avatar>
    
                                <v-list-item-content>
                                    <v-list-item-title>{{item.name}}</v-list-item-title>
                                    <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.carrers="{ item }">
                        <span>{{item.carrers}}</span>
                    </template>
                    
                    <template v-slot:item.revalidate="{ item }">
                        <v-chip v-if="item.revalidate"
                          color="#EC407A"
                          label
                          text-color="white"
                          small
                        >
                         
                          Revalida
                        </v-chip>
                    </template>
                    
                    <template v-slot:item.status="{ item }">
                        <v-chip
                          class="rounded-xl py-2 justify-center"
                          :style="getChipStyle(item)"
                          style="width: 95px; !important"
                          small
                        >
                          {{item.status}}
                        </v-chip>
                    </template>
                
                    <template v-slot:no-data>
                        <v-btn color="primary" text>No hay datos</v-btn>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>
    `,
    data(){
        return{
            search: '',
            headers: [
                {
                    text: 'Nombre',
                    align: 'start',
                    sortable: false,
                    value: 'name',
                },
                {
                    text: 'Carrera',
                    sortable: false,
                    value: 'carrers',
                },
                { text: 'Cuatrimestre', value: 'periods',sortable: false },
                { text: 'Revalida', value: 'revalidate',sortable: false},
                { text: 'Estado', value: 'status',sortable: false, },
            ],
            items: [
                {
                    name: 'Paulie Durber',
                    email: 'pdurber1c@gov.uk',
                    id: 1,
                    carrers: 'TÉCNICO SUPERIOR EN TRIPULANTE DE CABINA',
                    periods: 'I CUATRIMESTRE',
                    revalidate: true,
                    status: 'Activo'
                },
                {
                    name: 'Onfre Wind',
                    email: 'owind1b@yandex.ru',
                    id: 2,
                    carrers: 'TÉCNICO SUPERIOR EN LOGÍSTICA Y COMERCIO INTERNACIONAL',
                    periods: 'II CUATRIMESTRE',
                    revalidate: false,
                    status: 'Inactivo'
                },
                {
                    name: 'Karena Courtliff',
                    email: 'kcourtliff1a@bbc.co.uk',
                    id: 3,
                    carrers: 'TÉCNICO SUPERIOR EN MECÁNICA DE EQUIPO PESADO',
                    periods: 'I CUATRIMESTRE',
                    revalidate: false,
                    status: 'Reingreso'
                },
                {
                    name: 'Saunder Offner',
                    email: 'soffner19@mac.com',
                    id: 4,
                    carrers: 'TÉCNICO SUPERIOR EN MECÁNICA DE EQUIPO PESADO',
                    periods: 'I CUATRIMESTRE',
                    revalidate: true,
                    status: 'Suspendido'
                },
                {
                    name: 'Corrie Perot',
                    email: 'cperot18@goo.ne.jp',
                    id: 5,
                    carrers: 'ELECTRICIDAD CON ÉNFASIS EN CENTRALES HIDROELÉCTRICAS',
                    periods: 'II CUATRIMESTRE',
                    revalidate: false,
                    status: 'Aplazado'
                },
            ]
        }
    },
    created(){
        
    },  
    methods:{
        getChipStyle(item){
            const theme = this.$vuetify.theme.dark ? "dark" : "light";

            const themeColors = {
                    BgChip1: "#b5e8b8",
                    TextChip1: "#143f34",
                    BgChip2: "#F8F0E5",
                    TextChip2: "#D1A55A",
                    BgChip3: "#E8EAF6",
                    TextChip3: "#3F51B4",
                    BgChip4: "#F3BFBF",
                    TextChip4: "#8F130A",
                    BgChip5: "#B9C5D5",
                    TextChip5: "#2F445E",
             };

            if (item.status === "Activo") {
                return {
                    background: themeColors.BgChip1,
                    color: themeColors.TextChip1,
                };
            } else if (item.status === "Inactivo") {
                return {
                    background: themeColors.BgChip2,
                    color: themeColors.TextChip2,
                };
            } else if (item.status === "Reingreso") {
                return {
                    background: themeColors.BgChip3,
                    color: themeColors.TextChip3,
                };
            } else if (item.status === "Suspendido") {
                return {
                    background: themeColors.BgChip4,
                    color: themeColors.TextChip4,
                };
            }
            return {
                background: themeColors.BgChip5,
                color: themeColors.TextChip5,
            };// No se aplica ningún estilo por defecto
        }
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
})