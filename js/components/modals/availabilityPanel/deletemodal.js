Vue.component('deletemodal',{
    template: `
        <v-dialog v-model="dialog" max-width="500px">
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center pt-5">{{lang.delete_available}}</v-card-title>
                <v-card-subtitle class="pt-1 d-flex justify-center">{{lang.delete_available_confirm}}</v-card-subtitle>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" text @click="dialogDelete">{{ lang.cancel }}</v-btn>
                    <v-btn color="primary" text @click="confirmDelete">{{ lang.accept }}</v-btn>
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
        dialogDelete(){
            this.$emit('dialog-delete')
        },
        confirmDelete(){
            this.$emit('confirm-delete')
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