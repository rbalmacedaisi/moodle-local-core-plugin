Vue.component('eventdialog',{
    template: `
        <v-dialog max-width="450" v-model="dialogconfirm" persistent> 
            <v-card class="card-dialog" height="100%">
                <v-card-text class="pa-6">
                    <span class="d-block text-center text-subtitle-2">Su solicitud ha sido enviada.En breve recibirá una respuesta.</span>
                    <div class="mt-5 d-flex justify-center">
                        <v-btn outlined class="text-capitalize" color="#e5b751" @click="hidendialog">Continuar</v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
    `,
    data(){
        return{
          dialogconfirm: true
        }
    },
    methods:{
        hidendialog(){
            this.dialogconfirm = false
            this.$emit('hiden-dialog')
        }
    },
})