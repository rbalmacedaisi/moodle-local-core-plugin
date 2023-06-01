Vue.component('deleteusers',{
    template: `
      <div>
        <v-dialog
          v-model="dialog"
          persistent
          max-width="450"
        >
          <v-card class="py-4">
            <v-card-text class="text-subtitle-1 font-weight-medium text-center">
              <div> 
                ¿Está seguro que desea eliminar los usuarios seleccionados?
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
                small
              >
                {{lang.accept}}
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
    },
    computed: {
      lang(){
        return window.strings
      },
    },
    
})