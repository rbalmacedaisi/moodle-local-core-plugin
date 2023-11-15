Vue.component('approveusers',{
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              persistent
              max-width="400"
            >
                <v-card class="pa-4">
                
                    <div v-if="itemapprove.quotas == itemapprove.users + itemapprove.waitingusers">
                        <v-alert
                          outlined
                          type="success"
                          text
                          class="mb-0"
                          icon="mdi-clock-fast"
                          prominent
                        >
                            {{ lang.message_approved }}
                        </v-alert>
                    </div>
                
                    <div v-if="itemapprove.quotas > itemapprove.users + itemapprove.waitingusers || itemapprove.quotas < itemapprove.users + itemapprove.waitingusers">
                        
                            <span class="d-flex text-center">{{ lang.mminimum_quota_message }} <br> {{ lang.want_to_approve }}</span>
                        
                            <v-divider class="mb-4 mt-4"></v-divider>
                        
                            <div class="d-flex justify-center">
                                <v-btn
                                  color="warning"
                                  outlined
                                  small
                                  class="mx-2 rounded"
                                  @click="cancel"
                                >
                                    {{lang.cancel}}
                                </v-btn>
                                
                                <v-btn
                                  color="warning"
                                  outlined
                                  small
                                  class="mx-2 rounded"
                                  @click="dialogconfirm = true"
                                >
                                    {{lang.accept}}
                                </v-btn>
                            </div>
                        
                    </div>
                </v-card>
            </v-dialog>
        
            <v-dialog
              v-model="dialogconfirm"
              persistent
              max-width="600px"
            >
                <v-card>
                    <v-card-title>
                        <span class="text-h5">{{lang.approval_message_title}}</span>
                    </v-card-title>
                    <v-card-text class="pb-0">
                        <v-container>
                            <v-row>
                                <v-col
                                  cols="12"
                                  class="px-0"
                                >
                                    <v-textarea
                                      outlined
                                      name="message"
                                      :label="lang.write_reason"
                                      v-model="messageAproval"
                                      :hint="lang.aproved_message_hinit"
                                      rows="3"
                                    ></v-textarea>
                                </v-col>
                            </v-row>
                        </v-container>
                    </v-card-text>
                
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           outlined
                           color="primary"
                           small
                           @click="cancel"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                            color="primary"
                            @click="save"
                            small
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
        messageAproval: ''
      }
    },
    props:{
      itemapprove: Object
    },
    created(){
    },
    mounted(){
    },  
    methods:{
        /**
         * Opens the confirmation dialog for approving with a message if there are users to approve.
         * If there are users to approve, sets `dialogconfirm` to `true`.
         */
        confirmApprove(){
            // Check if there are users to approve.
            if(this.itemapprove.users > 0){
                this.dialogconfirm = true
            }
        },
        /**
         * Saves the approval message and emits an event to send the message to the parent component.
         * Additionally, emits an event to close the approval dialog.
         */
        save(){
            // Emit an event to send the approval message to the parent component.
            this.$emit('send-message', this.messageAproval)
            
            // Emit an event to close the approval dialog.
            this.$emit('close-approve')
        },
        /**
         * Cancels the approval process and emits an event to close the approval dialog.
         * Resets the approval message.
         */
        cancel(){
            // Emit an event to close the approval dialog.
            this.$emit('close-approve')
            
            // Reset the approval message.
            this.messageAproval = ''
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