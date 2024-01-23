Vue.component('curriculum',{
    template: `
        <div class="w-100 h-100 pb-16" v-resize="onResize">
            <v-container class="curriculum-container" style="max-width: 100% !important;">
                <v-row justify="center">
                    <v-col cols="12" sm="12" md="12" lg="12" xl="10">
                        <h2 class="px-4">Historial Académico</h2>
                        <span class="text-secondary px-4">{{lpName}}</span>
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
                                      :width="windowSize.x > 992 ? '300' : '100%'"
                                      height="115"
                                      outlined
                                      :style="getCardBorderStyle(item.period)"
                                      style="background: var(--v-background-base) !important"
                                      :href="'/course/view.php?id=' + course.courseId"
                                    >
                                        <v-card-title>
                                            <div class="text-body-2">{{ course.coursefullname }}</div>
                            
                                            <v-spacer></v-spacer>
                            
                                            <v-menu
                                              v-if="course.prerequisite_fullnames.length > 0 "
                                            >
                                                <template v-slot:activator="{ on: menu, attrs }">
                                                    <v-tooltip bottom>
                                                      <template v-slot:activator="{ on: tooltip }">
                                                        <v-icon
                                                          v-bind="attrs"
                                                          v-on="{ ...tooltip, ...menu }"
                                                          >mdi-book-lock-outline</v-icon
                                                        >
                                                      </template>
                                                      <span>{{ lang.prerequisites }}</span>
                                                    </v-tooltip>
                                                </template>
                            
                                                <v-list>
                                                    <v-list-item
                                                      v-for="(requerimen, index) in course.prerequisite_fullnames"
                                                      :key="index"
                                                    >
                                                        <v-list-item-avatar>
                                                            <v-icon large color="error">mdi-card</v-icon>
                                                        </v-list-item-avatar>
                                                        
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{ requerimen }}</v-list-item-title>
                                                        </v-list-item-content>
                                                        
                                                        <v-list-item-action>
                                                            <v-btn icon>
                                                                <v-icon color="error">mdi-lock-outline</v-icon>
                                                            </v-btn>
                                                        </v-list-item-action>
                                                    </v-list-item>
                                                </v-list>
                                            </v-menu>
                                        </v-card-title>
                                        
                                        <v-card-subtitle class="pb-3"> {{ course.courseshortname }}</v-card-subtitle>
                                        
                                        <v-card-actions :style="getCardActionsStyle(item.period)" style="bottom: 0px; position: absolute; width: 100%;">
                                            <span>Créditos: <b>{{ course.credits }}</b></span>
                                            
                                            <v-spacer></v-spacer>
                                            
                                            <div class="d-flex">
                                                <b>{{ lang.hours }}:</b>
                                                <div class="d-flex ml-1 px-2 rounded-pill" :style="getCardBorderStyle(item.period)" style="border-width: 0.5px; border-style: solid;">
                                                    <span class="mr-1">T: {{course.teoricalHours}}</span>
                                                    <span>P: {{course.practicalHours}}</span>
                                                </div>
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
            items: [],
            lpId: null,
            lpName: '',
            windowSize: {
                x: 0,
                y: 0,
            },
        }
    },
    created(){
        this.getCurriculum()
    },
    mounted() {
        this.onResize();
    },
    methods:{
        /**
         * Retrieves curriculum data for a learning plan from the Moodle API.
         *
         * This method makes a GET request to a specified API endpoint, fetches
         * curriculum data for a learning plan, and processes the response to
         * populate the component's data properties.
         *
         * @method getCurriculum
         * @async
         * @throws {Error} Throws an error if the API request fails.
         * @returns {Promise<void>} A Promise that resolves when the API request is successful.
         *
         * @example
         * // Call the getCurriculum method to fetch and process curriculum data.
         * getCurriculum();
         */
        getCurriculum(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);
            
            // Get the value of the "periodsid" parameter from the current URL.
            var id = siteurl.searchParams.get("lp_id");
            
            // Set the learning plan ID in the component's data.
            this.lpId = id
            
            // Define the API request URL.
            const url = this.siteUrl;
            
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_learning_plan_pensum',
                learningPlanId: this.lpId,
            };
            
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Parse the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.pensum)
                    console.log(data)
                    // Extract learning plan details.
                    const learningPlanArray = Object.values(data.learningPlan);
                    this.lpName = data.learningPlanName
                    
                    // Populate the items array with schedule data.
                    learningPlanArray.forEach((element)=>{
                        this.items.push({
                            period: element.periodName,
                            id: element.periodId,
                            status: "activo",
                            courses: element.courses
                        })
                    })
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        /**
         * The getCardBorderStyle method calculates and returns the border style for a card based on the specified period.
         *
         * @function
         * @name getCardBorderStyle
         * @memberof YourComponent
         * @param {string} period - The period for which the border style is calculated.
         * @returns {Object} - An object containing the borderColor property for the card border style.
         *
         * @example
         * // Call this method to get the border style for a card based on the specified period.
         * const borderStyle = getCardBorderStyle("I CUATRIMESTRE");
         * // Example result: { borderColor: "#214745" }
         */
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
            
            // Determine the border color based on the specified period and theme.
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
            
            // Return an empty object if no specific style is applied.
            return {};
        },
        /**
         * Determines the border style for a curriculum card based on the specified period.
         *
         * This method calculates and returns the border color for a curriculum card
         * based on the theme (light or dark) and the specified period.
         *
         * @method getCardBorderStyle
         * @param {string} period - The period for which the border color is determined.
         * @returns {Object} An object containing the border color for the specified period.
         *
         * @example
         * // Call the getCardBorderStyle method to get the border style for a specific period.
         * const borderStyle = getCardBorderStyle("I CUATRIMESTRE");
         * // Example result: { borderColor: "#214745" }
         */
        getCardActionsStyle(period) {
            // Determine the current theme (light or dark) using Vuetify's theme.
            const theme = this.$vuetify.theme.dark ? "dark" : "light";
            
            // Define theme-specific colors for curriculum card borders.
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
            
            // Determine the border color based on the specified period.
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
            
            // Default: No specific border style is applied.
            return {};
        },
        /**
         * Determines the color for a curriculum card based on the specified period and theme.
         *
         * This method calculates and returns the background color for a curriculum card
         * based on the theme (light or dark) and the specified period.
         *
         * @method getColor
         * @param {string} period - The period for which the color is determined.
         * @returns {string} The background color for the specified period.
         *
         * @example
         * // Call the getColor method to get the color for a specific period.
         * const cardColor = getColor("I CUATRIMESTRE");
         * // Example result: "#b0d4cd"
         */
        getColor(period) {
            // Check if the current theme is dark or light using Vuetify's theme.
            if (this.$vuetify.theme.dark) {
                // Dark theme colors based on the specified period.
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
                // Light theme colors based on the specified period.
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
        /**
         * Updates the component's state with the current width and height of the window.
         *
         * This method is triggered when the window is resized and updates the
         * `windowSize` property with the new width and height.
         *
         * @method onResize
         * @returns {void} This method does not return any value.
         *
         * @example
         * // Attach the onResize method to the window resize event.
         * window.addEventListener("resize", onResize);
         */
        onResize() {
            // Update the component's state with the current window width and height.
            this.windowSize = { x: window.innerWidth, y: window.innerHeight };
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
})