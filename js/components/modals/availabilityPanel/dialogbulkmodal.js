Vue.component('dialogbulkmodal',{
    template: `
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">¿Desea cargar la lista de usuarios?</v-card-title>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" text @click="cancelUploadBulkDisponibilities">{{lang.cancel}}</v-btn>
                    <v-btn color="primary" text @click="uploadDisponibilities">{{lang.accept}}</v-btn>
                    <v-spacer></v-spacer>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    data(){
      return{
        dialog: true,
      }
    },
    props:{
      
    },
    created(){
    },
    methods:{
        cancelUploadBulkDisponibilities(){
            this.$emit('cancel-upload')
        },
        uploadDisponibilities(){
            this.$emit('upload-disponibilities')
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