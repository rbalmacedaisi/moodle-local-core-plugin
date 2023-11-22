Vue.component('errormodal',{
    template: `
        <v-dialog v-model="show" max-width="500px" @click:outside="$emit('close')">
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">Error</v-card-title>
                <v-card-subtitle class="pt-1 d-flex justify-center text-center">{{ message }}</v-card-subtitle>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" text @click="$emit('close')">{{ lang.accept }}</v-btn>
                    <v-spacer></v-spacer>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props:{
      show:Boolean,
      message: String
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
            return window.strings
        },
    },
})