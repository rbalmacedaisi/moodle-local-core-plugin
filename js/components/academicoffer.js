Vue.component('academicoffer',{
    template: `
        <div class="my-2 mx-0">
            <h3 class="mt-5">Oferta académica</h3>
            <v-row class="ma-0" align="center">
                <v-col cols="4" class="px-0">
                    <v-text-field
                       v-model="search"
                       append-icon="mdi-magnify"
                       :label="lang.search"
                       hide-details
                       outlined
                       dense
                    ></v-text-field>
                </v-col>
                <v-spacer></v-spacer>
                <v-btn color="primary" class="rounded">Gestionar Carreras</v-btn>
            </v-row>
            
            <ul class="list mt-6 mx-0 px-0">
                <li
                  class="learning-item mb-2"
                  v-for="(item, index) in filteredItems"
                  :key="index"
                >
                    <a href="" class="learning-link">
                        <div>
                            <div
                              class="rounded-xl pa-4 mb-3"
                              :style="calculateBackgroundColor(index)"
                            >
                                <v-avatar size="45">
                                    <img :src="item.img" alt="learningplan" />
                                </v-avatar>
                            </div>
                        </div>
                        
                        <div class="LearningPathsListItem-content" style="flex: 1 1">
                            <h5
                                class="LearningPathsListItem-content-title text--primary mb-0"
                                data-qa="list_path_title"
                            >
                                {{ item.learningplanName }}
                            </h5>
                            <p class="LearningPathsListItem-content-info text-body-2 text--secondary mb-0">
                                {{item.periods + " Cuatrimestres" + " | " + item.courses + " cursos"}}
                            </p>
                        </div>
                    </a>
                    <v-tooltip bottom>
                        <template v-slot:activator="{ on, attrs }">
                          <v-btn
                            v-bind="attrs"
                            v-on="on"
                            outlined
                            color="primary"
                            small
                            class="mx-6 rounded"
                            href="/local/grupomakro_core/pages/curriculum.php"
                            target="_blank"
                            >Ver Malla</v-btn
                          >
                        </template>
                        <span>Ver Historial Academico</span>
                    </v-tooltip>
                </li>
            </ul>
        </div>
    `,
    data(){
        return{
            search: '',
            items: [
                {
                    id: 1,
                    learningplanName: "TÉCNICO SUPERIOR EN TRIPULANTE DE CABINA",
                    img: "https://lxp-dev.soluttolabs.com/pluginfile.php/1/local_sc_learningplans/learningplan_image/20/fotomaqueta_01.png",
                    periods: 4,
                    courses: 26,
                    percentage: 15,
                    color: "#6cc3ef33",
                },
                {
                    id: 2,
                    learningplanName:
                    "TÉCNICO SUPERIOR EN LOGÍSTICA Y COMERCIO INTERNACIONAL",
                    img: "https://lxp-dev.soluttolabs.com/pluginfile.php/1/local_sc_learningplans/learningplan_image/20/fotomaqueta_01.png",
                    periods: 4,
                    courses: 26,
                    percentage: 30,
                    color: "#f7956433",
                },
                {
                    id: 3,
                    learningplanName:
                    "TÉCNICO SUPERIOR EN MECÁNICA DE EQUIPO PESADO",
                    img: "https://lxp-dev.soluttolabs.com/pluginfile.php/1/local_sc_learningplans/learningplan_image/20/fotomaqueta_01.png",
                    periods: 4,
                    courses: 25,
                    percentage: 30,
                    color: "#f7956433",
                },
                {
                    id: 4,
                    learningplanName:
                    "ELECTRICIDAD CON ÉNFASIS EN CENTRALES HIDROELÉCTRICAS",
                    img: "https://lxp-dev.soluttolabs.com/pluginfile.php/1/local_sc_learningplans/learningplan_image/20/fotomaqueta_01.png",
                    periods: 4,
                    courses: 23,
                    percentage: 30,
                    color: "#f7956433",
                },
                {
                    id: 5,
                    learningplanName:
                    "DISEÑO DE OBRAS CIVILES",
                    img: "https://lxp-dev.soluttolabs.com/pluginfile.php/1/local_sc_learningplans/learningplan_image/20/fotomaqueta_01.png",
                    periods: 4,
                    courses: 26,
                    percentage: 30,
                    color: "#f7956433",
                },
            ],
        }
    },
    created(){
        
    },  
    methods:{
        calculateBackgroundColor(index) {
            const colors = ["#FFF3E0", "#F3E5F5", "#E3F2FD", "#E0F2F1", "#FCE4EC"];
            const colorIndex = index % colors.length;
            
            return {
                background: colors[colorIndex]
            };
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
        filteredItems() {
            const searchTerm = this.search.toLowerCase();
            return this.items.filter(item =>
                item.learningplanName.toLowerCase().includes(searchTerm)
            );
        },
    },
})