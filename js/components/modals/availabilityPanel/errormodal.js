Vue.component('errormodal',{
    template: `
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">Error</v-card-title>
                <v-card-subtitle class="pt-1 d-flex justify-center text-center">{{ Message }}</v-card-subtitle>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" text @click="closeDialogError">{{ lang.accept }}</v-btn>
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
      Message: String
    },
    created(){
    },
    methods:{
        closeDialogError(){
            this.$emit('close-dialog-error')
        },
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