Vue.component('studenttable', {
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0 px-0">
                <v-data-table
                    :headers="headers"
                    :items="students"
                    :options.sync="options"
                    :server-items-length="totalDesserts"
                    :loading="loading"
                    class="elevation-1"
                    :footer-props="{ 
                        'items-per-page-text': lang.students_per_page,
                        'items-per-page-options': [15],
                    }"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{ lang.students_list }}</v-toolbar-title>
                        </v-toolbar>
                        
                        <v-row justify="space-between" class="ma-0 mr-3 mb-2 align-center">
                            <v-col cols="4">
                                <v-text-field
                                   v-model="options.search"
                                   append-icon="mdi-magnify"
                                   :label="lang.search"
                                   hide-details
                                   outlined
                                   dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="auto">
                                <v-btn color="primary" @click="exportStudents">
                                    <v-icon left>mdi-file-export</v-icon>
                                    Exportar
                                </v-btn>
                            </v-col>
                        </v-row>
                    </template>
                    
                    <template v-slot:item.name="{ item }">
                        <v-list class="transparent">
                            <v-list-item>
                                <v-list-item-avatar>
                                    <img
                                      :src="item.img"
                                      alt="picture-profile"
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
                        <v-list dense class="transparent">
                            <v-list-item v-for="(carrer, index) in item.carrers" :key="index" class="px-0">
                                <v-list-item-content class="py-0">
                                    <v-list-item-subtitle>{{carrer.career}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.periods="{ item }">
                        <v-list dense class="transparent">
                            <v-list-item v-for="(periods, index) in item.periods" :key="index" class="px-0">
                                <v-list-item-content class="py-0">
                                    <v-list-item-subtitle>{{periods}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>

                    <template v-slot:item.subperiods="{ item }">
                        <v-list dense class="transparent">
                            <v-list-item v-for="(subperiod, index) in item.subperiods" :key="index" class="px-0">
                                <v-list-item-content class="py-0">
                                    <v-list-item-subtitle>{{subperiod}}</v-list-item-subtitle>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.revalidate="{ item }">
                        <revalidatestudents :studentsData="item"></revalidatestudents>
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
                    
                    <template v-slot:item.grade="{ item }">
                        <v-btn  class="rounded" color="info" width="60" x-small @click="gradeDialog(item)">Notas</v-btn>
                    </template>
                
                    <template v-slot:no-data>
                        <v-btn text>{{ lang.there_no_data }}</v-btn>
                    </template>
                </v-data-table>
            </v-col>
            <grademodal v-if="studentsGrades"  :dataStudent="studentGradeSelected" @close-dialog="closeDialog"></grademodal>
            
        </v-row>
    `,
    data() {
        return {
            headers: [
                {
                    text: window.strings.name,
                    align: 'start',
                    sortable: false,
                    value: 'name',
                },
                {
                    text: window.strings.careers,
                    sortable: false,
                    value: 'carrers',
                },
                { text: window.strings.quarters, value: 'periods', sortable: false },
                { text: 'Bloque', value: 'subperiods', sortable: false },
                { text: window.strings.revalidation, value: 'revalidate', sortable: false, align: 'center', },
                { text: window.strings.state, value: 'status', sortable: false, },
                { text: 'Calificaciones', value: 'grade', sortable: false, },
            ],
            totalDesserts: 0,
            loading: true,
            options: {
                page: 1,
                itemsPerPage: 15,
                search: '',
            },
            students: [],
            studentsGrades: false,
            studentGradeSelected: {},
        }
    },
    created() {
        console.log('StudentTable Component Created');
        console.log('Current Headers:', this.headers);
    },
    watch: {
        options: {
            handler() {
                this.getDataFromApi()
            },
            deep: true,
        },
    },
    methods: {
        /**
         * Fetches student information from the Moodle API and updates the component's state.
         *
         * This method makes an asynchronous GET request to the specified API endpoint,
         * retrieves student information based on specified options, and updates the
         * component's state with the fetched data.
         *
         * @method getDataFromApi
         * @async
         * @throws {Error} Throws an error if the API request fails.
         * @returns {Promise<void>} A Promise that resolves when the API request is successful.
         *
         * @example
         * // Call the getDataFromApi method to fetch student information from the API.
         * getDataFromApi();
         */
        async getDataFromApi() {
            // Set loading to true to indicate that data is being fetched.
            this.loading = true;
            try {
                // Define the API request URL.
                const url = this.siteUrl;

                // Create an object with the parameters required for the API call.
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_get_student_info',
                    page: this.options.page,
                    resultsperpage: this.options.itemsPerPage,
                    search: this.options.search,
                };

                // Make an asynchronous GET request to the specified URL, passing the parameters as query options.
                const response = await window.axios.get(url, { params });

                // Parse the JSON data returned from the API.
                const data = JSON.parse(response.data.dataUsers);
                console.log('API Response DataUsers:', data);
                if (data.length > 0) {
                    console.log('First User Data Sample:', data[0]);
                    console.log('Check keys:', Object.keys(data[0]));
                    if ('subperiods' in data[0]) {
                        console.log('Subperiods found:', data[0].subperiods);
                    } else {
                        console.error('Subperiods KEY MISSING in response!');
                    }
                }
                // Update the component's state with the fetched data.
                this.totalDesserts = response.data.totalResults
                this.students = [];

                // Iterate through the retrieved data and populate the students array.
                data.forEach((element) => {
                    this.students.push({
                        name: element.nameuser,
                        email: element.email,
                        id: element.userid,
                        carrers: element.careers,
                        id: element.userid,
                        carrers: element.careers,
                        periods: element.periods,
                        subperiods: element.subperiods,
                        revalidate: element.revalidate.length > 0 ? element.revalidate : '--',
                        status: element.status,
                        img: element.profileimage
                    });
                });
            } catch (error) {
                // Log any errors to the console in case of a request failure.
                console.error("Error fetching student information:", error);
            } finally {
                // Set loading to false to indicate that data fetching is complete.
                this.loading = false;
            }
        },
        /**
         * Determines the style (background color and text color) for a chip based on the status of an item.
         *
         * This method calculates and returns the chip style based on the theme (light or dark) and the status of the item.
         *
         * @method getChipStyle
         * @param {Object} item - The item for which the chip style is determined.
         * @returns {Object} An object containing the background and text colors for the chip.
         *
         * @example
         * // Call the getChipStyle method to get the chip style for a specific item.
         * const chipStyle = getChipStyle({ status: "Activo" });
         * // Example result: { background: "#b5e8b8", color: "#143f34" }
         */
        getChipStyle(item) {
            // Determine the current theme (light or dark) using Vuetify's theme.
            const theme = this.$vuetify.theme.dark ? "dark" : "light";

            // Define theme-specific colors for chip styles.
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

            // Determine the chip style based on the item's status.
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
            // Default: Apply a generic style for items with other statuses.
            return {
                background: themeColors.BgChip5,
                color: themeColors.TextChip5,
            };
        },
        /**
         * The gradeDialog method opens a dialog or section for displaying student grades based on the provided item.
         *
         * @function
         * @name gradeDialog
         * @memberof YourComponent
         *
         * @param {Object} item - The item representing a student or course for which grades will be displayed.
         *
         * @example
         * // Call this method when you want to display grades for a specific student or course.
         * gradeDialog(item);
         */
        gradeDialog(item) {
            // Set the studentsGrades property to true to open the dialog or section.
            this.studentsGrades = true

            // Store the selected item for further processing or display.
            this.studentGradeSelected = item
        },
        /**
         * The closeDialog method closes the dialog or section that displays student grades.
         *
         * @function
         * @name closeDialog
         * @memberof YourComponent
         *
         * @example
         * // Call this method when you want to close the dialog or section displaying student grades.
         * closeDialog();
         */
        closeDialog() {
            // Set the studentsGrades property to false to close the dialog or section.
            this.studentsGrades = false

            // Reset the selected student or course information.
            this.studentGradeSelected = {}
        }
        exportStudents() {
            // Redirect to the export script
            window.open(window.location.origin + '/local/grupomakro_core/pages/export_students.php', '_blank');
        }
    },
    computed: {
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl() {
            return window.location.origin + '/webservice/rest/server.php'
        },
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang() {
            return window.strings
        },
        /**
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token() {
            return window.userToken;
        },
    },
})