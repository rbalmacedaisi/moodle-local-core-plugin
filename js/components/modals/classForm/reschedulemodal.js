Vue.component('reschedulemodal',{
    template: `
        <v-dialog v-model="show" max-width="500px" width="600px" persistent>
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">{{dialogTitle}}</v-card-title>
                <v-card-subtitle v-if="!loading" class="pt-1 pb-0 d-flex justify-center text-center">{{ rescheduleMessage }}</v-card-subtitle>
                <v-card-subtitle v-if="!loading" class="d-flex pt-1 justify-center text-center font-weight-medium">¿Deseas continuar?</v-card-subtitle>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn @click="$emit('close')" v-if="!loading" class="ma-2" small text color="secondary">{{lang.cancel}}</v-btn>
                    <v-btn @click="$emit('confirm')" :loading="loading" class="ma-2" small text color="primary">{{lang.accept}}</v-btn>
                    <v-spacer></v-spacer>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props:{
      show:Boolean,
      message: String,
      loading:Boolean
    },
    data(){return {}},
    created(){},
    methods:{},
    computed: {
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings;
        },
        dialogTitle(){
            return this.loading? 'Reprogramando...':'Aviso';
        },
        rescheduleMessage(){
            return this.message?this.message:'No se detectaron conflictos con el nuevo horario.'
        }
    },
})