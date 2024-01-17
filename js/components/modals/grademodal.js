Vue.component('grademodal',{
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              persistent
              max-width="450"
            >
                <v-card>
                    <v-card-title class="text-h5">{{ lang.grades }}</v-card-title>
                    
                    <v-card-subtitle class="pt-1">{{ dataStudent.name }}</v-card-subtitle>
                    
                    <div class="modlist">
                        <ul class="modules-item-list">
                            <li v-for="(grade, index) in grades" :key="index" class="item-list" @click="gradebook(grade)">
                                <v-avatar size="35">
                                  <v-icon color="success">mdi-notebook-multiple</v-icon>
                                </v-avatar>
                                <div class="list-item-info">
                                    <div class="list-item-info-text">
                                        <p class="ma-0 text-body-2">{{ grade.coursename }}</p>
                                    </div>
                                </div>
                                
                                <v-spacer></v-spacer>
                                
                                <div class="grades d-flex pr-3">
                                    <span class="text-body-2">Nota: <span class="px-1">{{ grade.grade }}</span></span>
                                </div>
                            </li>
                        </ul>
                    </div>
                    
                    <v-divider class="my-0"></v-divider>
                    
                    <v-card-actions>
                      <v-spacer></v-spacer>
            
                      <v-btn
                        color="green darken-1"
                        text
                        @click="close"
                      >
                        {{ lang.close }}
                      </v-btn>
                    </v-card-actions>
                  </v-card>
            </v-dialog>
        </div>
    `,
    data(){
        return{
            dialog: true,
            grades: []
        };
    },
    props:{
        dataStudent: Object
    },
    created(){
        this.getDataGrades()
    },
    mounted(){},  
    methods:{
        /**
         * The getDataGrades method is responsible for fetching grades data for a specific student from the Moodle API.
         * It retrieves the grades for each course, including the course ID, grade, raw grade, and course name.
         *
         * @async
         * @function
         * @name getDataGrades
         * @memberof YourComponent
         * @returns {Promise<void>}
         *
         * @throws {Error} If an error occurs during the API request.
         *
         * @example
         * // This method is typically called when you need to fetch and display grades data for a student.
         * await getDataGrades();
         */
        async getDataGrades() {
            try {
                // Define the API request URL.
                const url = this.siteUrl;
                
                // Create an object with the parameters required for the API call.
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'gradereport_overview_get_course_grades',
                    userid: this.dataStudent.id,
                };
                
                // Make an asynchronous GET request to the specified URL, passing the parameters as query options.
                const response = await window.axios.get(url, { params });
                
                // Extract the grades data from the response.
                const data = response.data.grades;
                
                // Iterate over the grades data and populate the 'grades' array.
                data.forEach((element) => {
                    this.grades.push({
                        courseid: element.courseid,
                        grade: element.grade,
                        rawgrade: element.rawgrade,
                        coursename: ''
                    });
                });
                
                // Create parameters for a new API request to get course information based on course IDs.
                const paramsStudent = {};
                for (let index = 0; index < this.grades.length; index++) {
                    const element = this.grades[index];
                    paramsStudent[`options[ids][${index}]`] = element.courseid
                }
                
                // Set common parameters for the API request.
                paramsStudent.wstoken = this.token;
                paramsStudent.moodlewsrestformat = 'json';
                paramsStudent.wsfunction = 'core_course_get_courses';
                
                // Call the courseInfo method to get additional information for each course.
                this.courseInfo(paramsStudent)
            } catch (error) {
                // Log any errors that occur during the API request.
                console.error(error);
                throw new Error('An error occurred during the grades data fetching process.');
            }
        },
        /**
         * The courseInfo method is responsible for fetching additional course information for each course in the 'grades' array.
         * It updates the 'coursename' property for each grade based on the corresponding course information.
         *
         * @async
         * @function
         * @name courseInfo
         * @memberof YourComponent
         * @param {Object} params - The parameters required for the API request to get course information.
         * @throws {Error} If an error occurs during the API request.
         * @returns {Promise<void>}
         *
         * @example
         * // This method is typically called after fetching grades data to get additional information for each course.
         * const paramsStudent = {
         *   wstoken: 'yourAuthToken',
         *   moodlewsrestformat: 'json',
         *   wsfunction: 'core_course_get_courses',
         *   // Add other necessary parameters as needed for the API request.
         * };
         * await courseInfo(paramsStudent);
         */
        async courseInfo(params){
            try {
                // Make an asynchronous GET request to the specified URL, passing the parameters as query options.
                const response = await window.axios.get(url, { params });
                
                // Extract course information from the API response.
                const coursesinfo = response.data
                
                // Iterate over the grades and update the 'coursename' property based on the corresponding course information.
                this.grades.forEach((grade) => {
                  // Find the corresponding course in coursesInfo based on course ID.
                  const matchingCourse = coursesinfo.find((course) => course.id === grade.courseid);
                
                  // If a matching course is found, update the 'coursename' property.
                  if (matchingCourse) {
                    grade.coursename = matchingCourse.fullname;
                  }
                });         
            } catch (error) {
                // Log any errors that occur during the API request.
                console.error(error);
                throw new Error('An error occurred during the course information fetching process.');
            }
        },
        /**
         * The gradebook method redirects the user to the gradebook page for a specific course.
         *
         * @function
         * @name gradebook
         * @memberof YourComponent
         * @param {Object} item - The course information object containing the 'courseid'.
         *
         * @example
         * // This method is typically called when the user wants to view the gradebook for a specific course.
         * const courseInfo = {
         *   courseid: 123, // Replace with the actual course ID.
         *   // Add other necessary information as needed.
         * };
         * gradebook(courseInfo);
         */
        gradebook(item){
            // Construct the URL for the gradebook page using the course ID.
            const gradebookUrl = `/grade/report/grader/index.php?id=${item.courseid}`;
            
            // Redirect the user to the gradebook page.
            window.location = gradebookUrl;
        },
        /**
         * The close method closes the dialog and emits an event to notify the parent component.
         *
         * @function
         * @name close
         * @memberof YourComponent
         *
         * @example
         * // This method is typically called when the user wants to close the dialog.
         * close();
         */
        close(){
            // Close the dialog.
            this.dialog = false
            
            // Emit an event to notify the parent component about the dialog closure.
            this.$emit('close-dialog')
        }
    },
    computed: {
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
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
    },
    
})