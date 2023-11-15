Vue.component('userslist',{
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              max-width="800"
            >
                <v-card>
                    <v-card-text>
                        <v-data-table
                            v-model="selected"
                            :headers="headers"
                            :items="students"
                            dense
                            :items-per-page="50"
                            hide-default-footer
                        >
                            <template v-slot:top>
                                <h6 class="pt-6" >{{lang.users}}</h6>
                            </template>
                            
                            <template v-slot:item.student="{ item }">
                                <v-list class="transparent">
                                    <v-list-item class="pl-0">
                                        <v-list-item-avatar>
                                            <img :src="item.img" alt="picture">
                                        </v-list-item-avatar>
            
                                        <v-list-item-content>
                                            <v-list-item-title>{{item.student}}</v-list-item-title>
                                            <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                                        </v-list-item-content>
                                    </v-list-item>
                                </v-list>
                            </template>
                        </v-data-table>
                    </v-card-text>
                    
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
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
            selected: [],
            headers: [
                {
                    text: window.strings.student,
                    align: 'start',
                    sortable: false,
                    value: 'student',
                },
            ],
            students: [],
        }
    },
    props:{
        classId: String,
    },
    created(){
        
    },
    mounted(){
        this.getClass()
    },  
    methods:{
        // Retrieves the list of students enrolled in a specific class schedule from the API.
        getClass(){
            const url = this.siteUrl;
            
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_students_by_class_schedule',
                classId: this.classId,
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.classStudents)
                    const arrayEntries = Object.entries(data.enroledStudents);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    // Add the schedule data to the items array.
                    array.forEach((element)=>{
                        this.students.push({
                            student: element.firstname + ' ' + element.lastname,
                            id: element.userid,
                            email: element.email,
                            img: element.profilePicture,
                            classid: element.classid,
                        })
                    })
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        // Emits the 'close-list' event to close the list or dialog.
        close(){
            // Emit the 'close-list' event to request the closure of the list or dialog.
            this.$emit('close-list')
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
         * A computed property that returns the course ID from the 'window.courseid' variable.
         *
         * @returns '{string}' - The course ID.
         */
        courseId(){
            return window.courseid;
        }
    },
    
})