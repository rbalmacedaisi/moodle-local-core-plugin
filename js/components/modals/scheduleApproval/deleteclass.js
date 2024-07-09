Vue.component('deleteclass',{
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              persistent
              max-width="450"
            >
                <v-card class="py-4">
                    <v-card-text class="text-subtitle-1 font-weight-medium text-center">
                        <div v-if="itemdelete.users > 0">
                            {{lang.deleteusersclass}}
                        </div>
                        <div v-else> 
                            {{lang.deleteclassMessage}}
                        </div>
                    </v-card-text>
                    
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           outlined
                           @click="dialog = false, $emit('close-delete')"
                           small
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                           color="primary"
                           @click="confirmDelete"
                           small
                        >
                            {{lang.accept}}
                      </v-btn>
                      <v-spacer></v-spacer>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        
            <v-dialog
              v-model="dialogconfirm"
              persistent
              max-width="600"
            >
                <v-card class="">
                    <v-card-title class="pb-0">
                        <span class="text-h6">{{lang.deletion_message}}</span>
                    </v-card-title>
                    <v-card-text class="pb-0">
                        <v-container>
                            <v-row justify="center">
                                <v-col
                                  cols="12"
                                  class="px-0"
                                >
                                    <v-textarea
                                       outlined
                                       name="input-7-4"
                                       :label="lang.write_reason"
                                       v-model="messageDelete"
                                       rows="3"
                                    ></v-textarea>
                                </v-col>
                            </v-row>
                        </v-container>
                    </v-card-text>
                    
                    <v-card-actions class="pb-4 px-6">
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           outlined
                           @click="dialog = false, $emit('close-delete')"
                           small
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                           color="primary"
                           @click="save"
                           small
                           :disabled="!messageDelete"
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data(){
      return{
        dialog: true,
        dialogconfirm: false,
        messageDelete: '',
        params: {}
      }
    },
    props:{
      itemdelete: Object
    },
    created(){},
    mounted(){},  
    methods:{
        // Prepares and initiates the deletion process for a class schedule, with optional confirmation.
        confirmDelete(){
            // Set the required parameters for the API call.
            this.params.wstoken = this.token
            this.params.moodlewsrestformat = 'json'
            this.params.wsfunction = 'local_grupomakro_delete_course_class_schedule'
            this.params.classId = this.itemdelete.clasId
            
            // Check if there are enrolled users or waiting users for the class.
            if(this.itemdelete.users > 0 || this.itemdelete.waitingusers > 0){
                // Display a confirmation dialog for deletion.
                this.dialogconfirm = true
            }else{
                // If no enrolled or waiting users, proceed with deletion without a message.
                this.params.deletionMessage = ''
                this.removeClas(this.params)
            }
        },
        // Initiates the removal of a class schedule with a specified deletion message.
        save(){
            this.params.wstoken = this.token
            this.params.moodlewsrestformat = 'json'
            this.params.wsfunction = 'local_grupomakro_delete_course_class_schedule'
            this.params.classId = this.itemdelete.clasId
            // Set the deletion message in the parameters.
            this.params.deletionMessage = this.messageDelete
            // Call the 'removeClas' method to remove the class schedule.
            this.removeClas(this.params)
        },
        /**
         * Sends an API request to remove a class schedule with the specified parameters.
         * @param '{Object} params' - The parameters required for the removal request.
         */
        removeClas(params){
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Reload the current page to reflect the changes after successful removal.
                    location.reload();
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });
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
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
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