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
                <v-btn href="/local/sc_learningplans/index.php" color="primary" class="rounded">{{ lang.manage_careers }}</v-btn>
            </v-row>
            
            <ul class="list mt-6 mx-0 px-0">
                <li
                  class="learning-item mb-2"
                  v-for="(item, index) in filteredItems"
                  :key="index"
                >
                    <a :href="'/local/grupomakro_core/pages/curriculum.php?lp_id='+ item.id" class="learning-link">
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
                                {{item.periods + ' ' + lang.quarters + " | " + item.courses + ' ' + lang.courses}}
                            </p>
                        </div>
                    </a>
                        
                    <v-btn
                      outlined
                      color="primary"
                      small
                      class="mx-6 rounded"
                      :href="'/local/grupomakro_core/pages/curriculum.php?lp_id='+ item.id"
                      target="_blank"
                    >
                        {{ lang.see_curriculum }}
                    </v-btn>
                </li>
            </ul>
        </div>
    `,
    data(){
        return{
            search: '',
            items: [],
        }
    },
    created(){
        this.getOfferAcademic()
    },  
    methods:{
        /**
         * Fetches academic program offerings from the Moodle API and populates the component's items array.
         *
         * @method getOfferAcademic
         * @async
         * @throws {Error} Throws an error if the API request fails.
         * @returns {Promise<void>} A Promise that resolves when the API request is successful.
         *
         * @example
         * // Call the getOfferAcademic method to fetch and process academic program offerings.
         * getOfferAcademic();
         */
        getOfferAcademic(){
            // URL for the API request.
            const url = this.siteUrl;
            
            // Create an object with the parameters required for the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_learning_plan_list'
            };
            
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Parse the JSON data returned from the API.
                    const carrers = JSON.parse(response.data.learningPlans)
                    
                    // Iterate through the retrieved data and populate the items array.
                    carrers.forEach((element) => {
                        this.items.push({
                            id:element.id,
                            learningplanName: element.name,
                            img: element.imageUrl ? element.imageUrl : window.defaultImage,
                            periods: element.periodCount,
                            courses: element.courseCount
                        })
                    })
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error("Error fetching academic program offerings:", error);
            });
        },
        /**
         * Calculates the background color based on the provided index.
         *
         * This method takes an index and determines the background color from a predefined
         * array of colors. The calculated color is returned as an object with a 'background' property.
         *
         * @method calculateBackgroundColor
         * @param {number} index - The index used to determine the background color.
         * @returns {Object} An object containing the calculated background color.
         *
         * @example
         * // Call the calculateBackgroundColor method to get the background color for a specific index.
         * const backgroundColor = calculateBackgroundColor(3);
         * // Example result: { background: "#E0F2F1" }
         */
        calculateBackgroundColor(index) {
            // Predefined array of colors for background.
            const colors = ["#FFF3E0", "#F3E5F5", "#E3F2FD", "#E0F2F1", "#FCE4EC"];
            
            // Calculate the color index based on the provided index.
            const colorIndex = index % colors.length;
            
            // Return an object with the calculated background color.
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
        /**
         * Returns a filtered list of items based on the search term.
         *
         * This computed property filters the 'items' array based on the provided
         * search term. It performs a case-insensitive search on the 'learningplanName'
         * property of each item and returns the filtered list.
         *
         * @computed filteredItems
         * @returns {Array} An array containing the items that match the search term.
         *
         * @example
         * // Access the filteredItems computed property to get items matching the search term.
         * const filteredResults = filteredItems;
         * // Example result: [{ id: 1, learningplanName: "Computer Science", ... }, ...]
         */
        filteredItems() {
            // Convert the search term to lowercase for case-insensitive comparison.
            const searchTerm = this.search.toLowerCase();
            
            // Use the Array.filter method to filter items based on the search term.
            return this.items.filter(item =>
                item.learningplanName.toLowerCase().includes(searchTerm)
            );
        },
        /**
         * Computed property that returns the approved image stored in the global 'aprovedImg'.
         */
        img(){
            return window.defaultImage
        }
    },
})