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
                            {{lang.deleteusersmessage}}
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
                          @click="deleteuser"
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
    props:{},
    created(){
    },
    mounted(){},  
    methods:{
        // Emits a 'delete-users' event to initiate the user deletion process.
        deleteuser(){
            // Emit the 'delete-users' event to trigger the user deletion process.
            this.$emit('delete-users')
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