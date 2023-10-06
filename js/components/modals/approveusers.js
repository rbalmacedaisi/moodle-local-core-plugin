Vue.component('approveusers',{
    template: `
      <div>
        <v-dialog
          v-model="dialog"
          persistent
          max-width="550"
        >
          <v-card class="pa-0">
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
            
            <!--<div >
              <v-alert
                text
                type="error"
                icon="mdi-cloud-alert"
                prominent
                class="mb-0"
              >
                <span>{{ lang.maximum_quota_message }} <br> {{ lang.want_to_approve }}</span>
                <v-divider
                  class="my-4 error"
                  style="opacity: 0.22"
                ></v-divider>
                <div class="d-flex justify-center">
                  <v-btn
                      color="error"
                      outlined
                      small
                      class="ma-2 rounded"
                      @click="$emit('close-approve')"
                    >
                      {{lang.cancel}}
                  </v-btn>
                  <v-btn
                    color="error"
                    outlined
                    small
                    class="ma-2 rounded"
                    @click="dialogconfirm = true"
                  >
                    {{lang.accept}}
                  </v-btn>
                </div>
              </v-alert>
            </div>-->
            
            <div v-if="itemapprove.quotas > itemapprove.users + itemapprove.waitingusers || itemapprove.quotas < itemapprove.users + itemapprove.waitingusers">
               <v-alert
                text
                type="warning"
                icon="mdi-cloud-alert"
                prominent
                class="mb-0"
              >
                <span>{{ lang.mminimum_quota_message }} <br> {{ lang.want_to_approve }}</span>
                
                <v-divider
                  class="my-4 warning"
                  style="opacity: 0.22"
                ></v-divider>
                
                <div class="d-flex justify-center">
                  <v-btn
                      color="warning"
                      outlined
                      small
                      class="ma-2 rounded"
                      @click="cancel"
                    >
                      {{lang.cancel}}
                  </v-btn>
                  <v-btn
                    color="warning"
                    outlined
                    small
                    class="ma-2 rounded"
                    @click="dialogconfirm = true"
                  >
                    {{lang.accept}}
                  </v-btn>
                </div>
              </v-alert>
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
                <span class="text-h5">Mensaje para Aprobación</span>
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
                      hint="Mensaje para justificar aprovación de horario."
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
                    text
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
      confirmApprove(){
        if(this.itemapprove.users > 0){
          this.dialogconfirm = true
        }
      },
      save(){
        this.$emit('send-message', this.messageAproval)
        this.$emit('close-approve')
      },
      cancel(){
        this.$emit('close-approve')
        this.messageAproval = ''
      }
    },
    computed: {
      lang(){
        return window.strings
      },
    },
})