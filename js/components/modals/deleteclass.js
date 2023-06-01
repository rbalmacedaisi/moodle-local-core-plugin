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
                La clase seleccionada tiene estudiantes inscritos. <br> ¿Está seguro que desea eliminarla?
              </div>
              <div v-else> 
                ¿Está seguro que desea eliminar la clase seleccionada?
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
          max-width="500"
        >
          <v-card class="py-6">
            
            <v-card-text >
              <v-row justify="center">
                <v-col
                  cols="12"
                  md="10"
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
                {{lang.save}}
              </v-btn>
              <v-spacer></v-spacer>
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
      itemdelete: Object
    },
    created(){
    },
    mounted(){
    },  
    methods:{
      confirmDelete(){
        if(this.itemdelete.users > 0){
          this.dialogconfirm = true
        }
      }  
    },
    computed: {
      lang(){
        return window.strings
      },
    },
    
})