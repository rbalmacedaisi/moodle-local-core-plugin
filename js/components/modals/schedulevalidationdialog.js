Vue.component('schedulevalidationdialog',{
    template: `
        <div>
            <v-dialog v-model="showDialog" persistent width="500">
                <v-card>
                    <v-card-title>
                        {{ lang.approval_message_title }}
                    </v-card-title>
                    
                    <v-card-text class="pb-0">
                        <span class="mb-3">La clase <b>{{ bulkConfirmationDialog.title }}</b> {{lang.no_users_message}}</span>
                        <v-textarea 
                           v-model="message" 
                           outlined
                           name="message"
                           :hint="lang.aproved_message_hinit"
                           rows="3"
                           :label="lang.write_reason"
                           class="mt-3"
                        >
                        </v-textarea>
                    </v-card-text>
                    
                    <v-card-actions class="py-3">
                        <v-spacer></v-spacer>
                        <v-btn
                           outlined
                           color="primary"
                           small
                           @click="close"
                        >
                            {{ lang.cancel }}
                        </v-btn>
                        <v-btn 
                           color="primary"
                           small
                           @click="saveMessage"
                        >
                            {{ lang.save }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data(){
        return{
            showDialog: true,
            message: ''
        }
    },
    props:{
        bulkConfirmationDialog: Object()
    },
    created(){},
    mounted(){
    },  
    methods:{
        // Emits a 'save-message' event with the current message to save it.
        saveMessage(){
            this.$emit('save-message', this.message)
        },
        // Emits a 'close-showdialog' event to close the dialog.
        close(){
            this.$emit('close-showdialog')
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
    },
    
})