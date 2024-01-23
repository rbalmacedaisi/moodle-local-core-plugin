Vue.component('grademodal',{
    template: `
        <div>
            
            <v-dialog
              v-model="dialog"
              persistent
              max-width="500"
            >
                <v-card>
                    <v-card-title class="text-h5">{{ lang.grades }}</v-card-title>
                    
                    <v-card-subtitle class="pt-1 font-weight-bold">{{ dataStudent.name }}</v-card-subtitle>
                    
                    <div class="modlist">
                        <ul v-for="(career, careerIndex) in carresData.carrers" :key="careerIndex" class="modules-item-list">
                            <span class="font-weight-bold text--secondary text-subtitle-2">{{ career.career }}</span>
                            
                            <li v-for="(course, courseIndex) in career.courses" :key="courseIndex" class="item-list" @click="gradebook(course)">
                                <v-avatar size="35">
                                    <v-icon color="success">mdi-notebook-multiple</v-icon>
                                </v-avatar>
                                
                                <div class="list-item-info">
                                    <div class="list-item-info-text">
                                        <p class="ma-0 text-body-2">{{ course.coursename }}</p>
                                    </div>
                                </div>
                
                                <v-spacer></v-spacer>
                
                                <div class="grades d-flex pr-3">
                                    <span class="text-body-2">Nota: <span class="px-1">{{ course.grade }}</span></span>
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
                        class="rounded"
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
            dialog: false,
            carreData:{}
        };
    },
    props:{
        dataStudent: Object
    },
    created(){
        this.getpensum()
    },
    mounted(){},  
    methods:{
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
        },
        async getpensum() {
            const coursestc = []
            // Iterar sobre cada carrera y obtener los datos
            for (const element of this.dataStudent.carrers) {
                const data = await this.getcarrers(element.planid);
                // Actualizar la propiedad 'courses' en cada carrera
                element.courses = data;
            }
        
            // Imprimir el objeto dataStudent actualizado
            console.log(this.dataStudent);
            console.log('hola')
            this.dialog = true
        },
        async getcarrers(id) {
            try {
                // Define el objeto de parámetros requerido para la llamada a la API.
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_get_student_learning_plan_pensum',
                    userId: this.dataStudent.id,
                    learningPlanId: id
                };

                // Realiza una solicitud GET asincrónica a la URL especificada, pasando los parámetros como opciones de consulta.
                const response = await window.axios.get(this.siteUrl, { params });
        
                // Extrae los datos del pensum desde la respuesta.
                const data = JSON.parse(response.data.pensum);
                const dataArray = Object.values(data);
                
                
                // Filtra los cursos según si el periodName coincide con periods
                const filteredDataArray = dataArray.filter(course => course.periodName === this.dataStudent.carrers.find(career => career.planid === id).periods);
        
                // Extrae solo la propiedad 'courses' de cada objeto y aplánala
                const coursesArray = filteredDataArray.map(course => course.courses).flat();
        
                // Retorna el array de cursos directamente
                return coursesArray;
            } catch (error) {
                console.error(error);
            }
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
        carresData(){
            this.carreData = this.dataStudent
            return this.dataStudent
        }
    },
    
    
})