Vue.component('uploadcalendarmodal',{
    template: `
        <v-dialog v-model="show" max-width="500px" persistent>
            <v-card>
                <v-card-title class="text-subtitle-1 d-flex justify-center">{{lang.upload_modal_title}}</v-card-title>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <div>
                        <v-btn color="primary" v-show="!uploading" text @click="$emit('close')">{{lang.cancel}}</v-btn>
                        <v-btn color="primary" :loading="uploading" text @click="$emit('confirm')">{{lang.accept}}</v-btn>
                    </div>
                    <v-spacer></v-spacer>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props:{
      show:Boolean,
      uploading:Boolean
    },
    data(){
        return{
            lang:window.strings
    }}
})