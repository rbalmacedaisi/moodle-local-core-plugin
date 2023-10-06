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
                    <v-card-title>
                        <span class="text-h5">Mensaje para Eliminación</span>
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
                    
                    <v-card-actions class="pb-3">
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
    created(){
    },
    mounted(){},  
    methods:{
        confirmDelete(){
            this.params.wstoken = this.token
            this.params.moodlewsrestformat = 'json'
            this.params.wsfunction = 'local_grupomakro_delete_course_class_schedule'
            this.params.classId = this.itemdelete.clasId
            
            if(this.itemdelete.users > 0 || this.itemdelete.waitingusers > 0){
                this.dialogconfirm = true
            }else{
                this.params.deletionMessage = ''
                this.removeClas(this.params)
            }
        },
        save(){
            this.params.deletionMessage = this.messageDelete
            this.removeClas(this.params)
        },
        removeClas(params){
            const url = this.siteUrl;
            console.log(this.params)
            
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    console.log(response)
                    location.reload();
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        }
    },
    computed: {
        lang(){
            return window.strings
        },
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
    },
    
})