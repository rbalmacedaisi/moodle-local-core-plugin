Vue.component('dialogbulkmodal',{
    template: `
        <v-dialog v-model="show" max-width="500px" persistent>
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">{{modalTitle}}</v-card-title>
                <v-expansion-panels v-if="uploadBulkResults">
                    <v-expansion-panel
                        v-for="(result,document) in uploadBulkResults"
                        :key="document"
                    >
                        <v-expansion-panel-header>
                            {{document}}
                        </v-expansion-panel-header>
                        <v-expansion-panel-content>
                            {{parseMessage(result)}}
                        </v-expansion-panel-content>
                    </v-expansion-panel>
                </v-expansion-panels>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <div v-if="!uploadBulkResults">
                        <v-btn color="primary" v-show="!uploading" text @click="$emit('close')">{{lang.cancel}}</v-btn>
                        <v-btn color="primary" :loading="uploading" text @click="$emit('confirm')">{{lang.accept}}</v-btn>
                    </div>
                    <div v-else>
                        <v-btn color="primary" text @click="reload">{{lang.accept}}</v-btn>
                    </div>
                    <v-spacer></v-spacer>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    data(){return{}},
    props:{
      show:Boolean,
      uploadBulkResults:Object,
      uploading:Boolean
    },
    created(){
    },
    methods:{
        parseMessage(result){
            return result.status === -1? JSON.parse(result.message).join('\n'):result.message;
        },
        reload(){
            window.location.reload();
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
        modalTitle(){
            return this.uploadBulkResults?'Resultados carga masiva':'¿Desea cargar la lista de usuarios?'
        }
    },
})