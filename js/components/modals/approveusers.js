Vue.component('approveusers',{
    template: `
      <div>
        <v-dialog
          v-model="dialog"
          persistent
          max-width="550"
        >
          <v-card class="pa-0">
            <div v-if="itemapprove.waitingusers == 0 && itemapprove.users <= itemapprove.quotas">
              <v-alert
                outlined
                type="success"
                text
                class="mb-0"
                dismissible
              >
                La lista de usuarios para esta clase ha sido aprobada.
                <template v-slot:close>
                  <v-btn icon  class="success--text v-btn--round" small @click="$emit('close-approve')">
                    <v-icon>mdi-close</v-icon>
                  </v-btn>
                </template>
              </v-alert>
            </div>
            
            <div v-if="itemapprove.users > itemapprove.quotas">
              <v-alert
                text
                type="error"
                icon="mdi-cloud-alert"
                prominent
                class="mb-0"
              >
                <span>La clase seleccionada supera el cupo máximo permitido. <br>¿Está seguro que desea aprobarla?</span>
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
            </div>
            
            <div v-if="itemapprove.users < 10">
               <v-alert
                text
                type="warning"
                icon="mdi-cloud-alert"
                prominent
                class="mb-0"
              >
                <span>La clase seleccionada no cuenta con el número mínimo de estudiantes permitidos. <br>¿Está seguro que desea aprobarla?</span>
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
                      @click="$emit('close-approve')"
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
          max-width="500"
        >
          <v-card class="py-4">
            <v-card-text >
              <v-row justify="center">
                <v-col
                  cols="12"
                  md="12"
                >
                  <v-textarea
                    name="input-7-4"
                    label="Escribe el motivo"
                    value=""
                    rows="2"
                  ></v-textarea>
                </v-col>
              </v-row>
            </v-card-text>
            <v-card-actions>
              <v-spacer></v-spacer>
              <v-btn
                color="primary"
                outlined
                small
                class="rounded"
              >
                {{lang.cancel}}
              </v-btn>
              <v-btn
                color="primary"
                small
                class="rounded"
                @click="dialogconfirm = false ,$emit('close-approve') "
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
        dialogconfirm: false
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
    },
    computed: {
      lang(){
        return window.strings
      },
    },
    
})