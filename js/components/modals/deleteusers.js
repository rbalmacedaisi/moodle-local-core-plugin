Vue.component('deleteusers',{
    template: `
        <div>
            <v-dialog
              v-model="show"
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
                          @click="$emit('close')"
                          small
                        >
                            {{lang.cancel}}
                        </v-btn>
                        <v-btn
                          color="primary"
                          small
                          @click="$emit('confirm')"
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
        return{};
    },
    props:{
        show:Boolean
    },
    created(){},
    mounted(){},  
    methods:{},
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