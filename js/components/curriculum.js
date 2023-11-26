Vue.component('curriculum',{
    template: `
        <div class="w-100 h-100 pb-16">
            <v-container>
                <v-row justify="center">
                    <v-col cols="12" sm="10">
                        <h2 class="px-4">Historial Académico</h2>
                        <span class="text-secondary px-4">TÉCNICO SUPERIOR EN TRIPULANTE DE CABINA</span>
                        <div class="mt-5">
                            <div v-for="(item, index) in items" :key="index" class="period-content"> 
                                <h6 class="period-title">
                                    <span :style="getCardBorderStyle(item.period)">{{ item.period }}</span>
                                </h6>
                                
                                <div class="course-content">
                                    <v-card
                                      v-for="(course, index) in item.courses"
                                      :key="course.id"
                                      class="my-3 v-card-border-w rounded-lg position-relative"
                                      max-width="100%"
                                      
                                      outlined
                                      :style="getCardBorderStyle(item.period)"
                                      style="background: var(--v-background-base) !important"
                                    >
                                        <v-card-title>
                                            <div class="text-body-1">{{ course.name }}</div>
                            
                                            <v-spacer></v-spacer>
                            
                                            <v-menu
                                              v-if="course.requirements.length > 0 "
                                            >
                                                <template v-slot:activator="{ on: menu, attrs }">
                                                    <v-tooltip bottom>
                                                      <template v-slot:activator="{ on: tooltip }">
                                                        <v-icon
                                                          v-if="course.status == 'No Disponible'"
                                                          v-bind="attrs"
                                                          v-on="{ ...tooltip, ...menu }"
                                                          >mdi-book-lock-outline</v-icon
                                                        >
                                                        <v-icon
                                                          v-if="course.status != 'No Disponible'"
                                                          v-bind="attrs"
                                                          v-on="{ ...tooltip, ...menu }"
                                                          >mdi-book-lock-open-outline</v-icon
                                                        >
                                                      </template>
                                                      <span>Prerrequisitos</span>
                                                    </v-tooltip>
                                                </template>
                            
                                                <v-list>
                                                    <v-list-item
                                                      v-for="(requerimen, index) in course.requirements"
                                                      :key="index"
                                                    >
                                                        <v-list-item-avatar>
                                                            <v-icon large :color="requerimen.color">mdi-card</v-icon>
                                                        </v-list-item-avatar>
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{ requerimen.name }}</v-list-item-title>
                                                        </v-list-item-content>
                                                        <v-list-item-action>
                                                            <v-btn v-if="requerimen.status == 'complete'" icon>
                                                                <v-icon color="success">mdi-lock-open-outline</v-icon>
                                                            </v-btn>
                                                            <v-btn v-else icon>
                                                                <v-icon color="error">mdi-lock-outline</v-icon>
                                                            </v-btn>
                                                        </v-list-item-action>
                                                    </v-list-item>
                                                </v-list>
                                            </v-menu>
                                        </v-card-title>
                                        <v-card-subtitle class="pb-3"> {{ course.code }}</v-card-subtitle>
                                        
                                        <v-card-actions :style="getCardActionsStyle(item.period)">
                                            <span>Créditos: <b>{{ course.credit }}</b></span>
                                            <v-spacer></v-spacer>
                                            
                                            <div class="d-flex">
                                                <b>Horas:</b>
                                                <span class="mr-1">T: 32</span>
                                                <span>P: 32</span>
                                            </div>
                                        </v-card-actions>
                                    </v-card>
                                </div>
                            </div>
                        </div>
                    </v-col>
                </v-row>
            </v-container>
        </div>
    `,
    data(){
        return{
            items: [
                {
                    period: "I CUATRIMESTRE",
                    id: 1,
                    status: "activo",
                    courses: [
                        {
                            name: "Matemática 1",
                            credit: 2,
                            progress: 100,
                            grade: "100",
                            status: "Aprobada",
                            requirements: [],
                            id: 20,
                            color: "#BBDEFB",
                            code: 'MATE-I',
                            modules: [
                                {
                                    modname: "1 Actividad Matemáticas",
                                    id: 30,
                                    grade: 10,
                                },
                                {
                                    modname: "2 Actividad Matemáticas",
                                    id: 31,
                                    grade: 10,
                                },
                                {
                                    modname: "3 Actividad Matemáticas",
                                    id: 32,
                                    grade: 10,
                                },
                                {
                                    modname: "4 Actividad Matemáticas",
                                    id: 33,
                                    grade: 10,
                                },
                                {
                                    modname: "5 Actividad Matemáticas",
                                    id: 34,
                                    grade: 10,
                                },
                            ],
                        },
                        {
                            name: "Inglés I",
                            credit: 3,
                            progress: 100,
                            grade: "100",
                            status: "Aprobada",
                            requirements: [],
                            id: 22,
                            color: "#7986CB",
                            code: 'INGL-I',
                            modules: [
                                {
                                    modname: "1 Actividad Inglés",
                                    id: 35,
                                    grade: 10,
                                },
                                {
                                    modname: "2 Actividad Inglés",
                                    id: 36,
                                    grade: 10,
                                },
                                {
                                    modname: "3 Actividad Inglés",
                                    id: 37,
                                    grade: 10,
                                },
                                {
                                    modname: "4 Actividad Inglés",
                                    id: 38,
                                    grade: 10,
                                },
                                {
                                    modname: "5 Actividad Inglés",
                                    id: 39,
                                    grade: 10,
                                },
                            ],
                        },
                        {
                            name: "Informática Aplicada",
                            credit: 3,
                            progress: 100,
                            grade: "30",
                            status: "Reprobada",
                            requirements: [],
                            id: 23,
                            color: "#dbdbdb",
                            code: 'INFO',
                            modules: [],
                        },
                        {
                            name: "Expresión Oral y Escrita",
                            credit: 2,
                            progress: 100,
                            grade: "30",
                            status: "Reprobada",
                            requirements: [],
                            id: 53,
                            color: "#dbdbdb",
                            code: 'EOES-I',
                            modules: [],
                        },
                    ],
                },
                {
                    period: "II CUATRIMESTRE",
                    id: 2,
                    status: "activo",
                    courses: [
                        {
                            name: "Legislación Aeronáutica",
                            credit: 4,
                            progress: 10,
                            grade: "10",
                            status: "Cursando",
                            requirements: [],
                            id: 24,
                            color: "#9575CD",
                            code: 'LAER',
                            modules: [],
                        },
                        {
                            name: "Meteorología Básica",
                            credit: 3,
                            progress: 0,
                            grade: "0",
                            status: "Disponible",
                            requirements: [],
                            id: 25,
                            color: "#FF5252",
                            code: 'MBAS',
                            modules: [],
                        },
                        {
                            name: "Inglés II",
                            credit: 3,
                            progress: 100,
                            grade: "0",
                            status: "Reprobada",
                            requirements: [
                                {
                                    name: "Inglés I",
                                    status: "complete",
                                    color: "#7986CB",
                                },
                            ],
                            id: 26,
                            color: "#b0b0b0",
                            code: 'INGL-II',
                            modules: [],
                        },
                        {
                            name: "Historia de Panamá",
                            credit: 2,
                            progress: 100,
                            grade: "0",
                            status: "Reprobada",
                            requirements: [
                                
                            ],
                            id: 56,
                            color: "#b0b0b0",
                            code: 'HIST',
                            modules: [],
                        },
                    ],
                },
                {
                    period: "III CUATRIMESTRE",
                    id: 3,
                    status: "inactivo",
                    courses: [
                        {
                            name: "Inglés III",
                            credit: 3,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [
                                {
                                    name: "Inglés I",
                                    status: "complete",
                                    color: "#7986CB",
                                },
                                {
                                    name: "Inglés II",
                                    status: "incomplete",
                                    color: "#7986CB",
                                },
                            ],
                            id: 27,
                            color: "#EC407A",
                            code: 'INGL-III',
                            modules: [],
                        },
                        {
                            name: "Servicio a Bordo",
                            credit: 6,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 28,
                            color: "#E040FB",
                            code: 'SBOR',
                            modules: [],
                        },
                        {
                            name: "Gestión Empresarial",
                            credit: 5,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 29,
                            color: "#1DE9B6",
                            code: 'GEMP',
                            modules: [],
                        },
                        {
                            name: "Gestión de Recursos",
                            credit: 5,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 59,
                            color: "#1DE9B6",
                            code: 'GREC',
                            modules: [],
                        },
                    ],
                },
                {
                    period: "IV CUATRIMESTRE",
                    id: 3,
                    status: "inactivo",
                    courses: [
                        {
                            name: "Desarrollo de la personalidad",
                            credit: 4,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 47,
                            color: "#EC407A",
                            code: 'DPER',
                            modules: [],
                        },
                        {
                            name: "Natación",
                            credit: 4,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 48,
                            color: "#E040FB",
                            code: 'NATA',
                            modules: [],
                        },
                        {
                            name: "Supervivencia y Contraincendios",
                            credit: 10,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 29,
                            color: "#1DE9B6",
                            code: 'SCON',
                            modules: [],
                        },
                        {
                            name: "Práctica Profesional",
                            credit: 10,
                            progress: 0,
                            grade: "0",
                            status: "No Disponible",
                            requirements: [],
                            id: 69,
                            color: "#1DE9B6",
                            code: 'PRPR',
                            modules: [],
                        },
                    ],
                },
            ],
        }
    },
    created(){
    },  
    methods:{
        getCardBorderStyle(period) {
            const theme = this.$vuetify.theme.dark ? "dark" : "light";

            const themeColors = {
                dark: {
                    curriculumBorderCard1: "#214745",
                    curriculumBorderCard2: "#602f51",
                    curriculumBorderCard3: "#463a69",
                    curriculumBorderCard4: "#20455a",
                },
                light: {
                    curriculumBorderCard1: "#259e98",
                    curriculumBorderCard2: "#b2007b",
                    curriculumBorderCard3: "#3307b2",
                    curriculumBorderCard4: "#0077ba",
                },
             };

            if (period === "I CUATRIMESTRE") {
                return {
                    borderColor: themeColors[theme].curriculumBorderCard1,
                };
            } else if (period === "II CUATRIMESTRE") {
                return { borderColor: themeColors[theme].curriculumBorderCard2 };
            } else if (period === "III CUATRIMESTRE") {
                return { borderColor: themeColors[theme].curriculumBorderCard3 };
            } else if (period === "IV CUATRIMESTRE") {
                return { borderColor: themeColors[theme].curriculumBorderCard4 };
            }
            return {}; // No se aplica ningún estilo de borde por defecto
        },
        getCardActionsStyle(period) {
            const theme = this.$vuetify.theme.dark ? "dark" : "light";

            const themeColors = {
                dark: {
                    curriculumBgCard1: "#172327",
                    curriculumTextCard1: "#b0d4cd",
        
                    curriculumBgCard2: "#281d2b",
                    curriculumTextCard2: "#ecc1d8",
        
                    curriculumBgCard3: "#212030",
                    curriculumTextCard3: "#d2c6f7",
        
                    curriculumBgCard4: "#17232d",
                    curriculumTextCard4: "#acd2e8",
                },
                light: {
                    curriculumBgCard1: "#e5f4f1",
                    curriculumTextCard1: "#2ca58d",
        
                    curriculumBgCard2: "#fce8ef",
                    curriculumTextCard2: "#eb408d",
        
                    curriculumBgCard3: "#ebe6f5",
                    curriculumTextCard3: "#5e35b1",
        
                    curriculumBgCard4: "#E3F2FD",
                    curriculumTextCard4: "#0077ba",
                },
            };

            if (period === "I CUATRIMESTRE") {
                return {
                    background: themeColors[theme].curriculumBgCard1,
                    color: themeColors[theme].curriculumTextCard1,
                };
            } else if (period === "II CUATRIMESTRE") {
                return {
                    background: themeColors[theme].curriculumBgCard2,
                    color: themeColors[theme].curriculumTextCard2,
                };
            } else if (period === "III CUATRIMESTRE") {
                return {
                    background: themeColors[theme].curriculumBgCard3,
                    color: themeColors[theme].curriculumTextCard3,
                };
            } else if (period === "IV CUATRIMESTRE") {
                return {
                    background: themeColors[theme].curriculumBgCard4,
                    color: themeColors[theme].curriculumTextCard4,
                };
            }

            return {};
        },
        getColor(period) {
            if (this.$vuetify.theme.dark) {
                if (period === "I CUATRIMESTRE") {
                    return "#b0d4cd";
                } else if (period === "II CUATRIMESTRE") {
                    return "#ecc1d8";
                } else if (period === "III CUATRIMESTRE") {
                    return "#d2c6f7";
                } else if (period === "IV CUATRIMESTRE") {
                    return "#acd2e8";
                }
            } else {
                if (period === "I CUATRIMESTRE") {
                    return "#2ca58d";
                } else if (period === "II CUATRIMESTRE") {
                    return "#eb408d";
                } else if (period === "III CUATRIMESTRE") {
                    return "#5e35b1";
                } else if (period === "IV CUATRIMESTRE") {
                    return "#0077ba";
                }
            }
        },
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