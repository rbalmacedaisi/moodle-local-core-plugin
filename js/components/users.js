Vue.component('users',{
    template: `
        <div>
            <v-row justify="center" class="my-2 mx-0 position-relative">
                <v-col cols="12" class="py-0">
                    <div class="d-flex">
                        <v-tabs
                          v-model="tab"
                          background-color="transparent"
                          color="primary"
                        >
                          <v-tab v-for="item in items" :key="item">
                            {{ item }}
                          </v-tab>
                        </v-tabs>
                    </div>
                </v-col>
            </v-row>
            
            <v-row>
                <v-col cols="12">
                    <section v-if="tab == 0" id="waitingusers" class="pb-10">
                        <waitingusers />
                    </section>
                    <section v-if="tab == 1" id="incompleteschedules" class="pb-10">
                        <incompleteschedules />
                    </section>
            
                  </v-col>
                </v-row>
        </div>
    `,
    data(){
        return{
            tab: null,
            items: [ "En espera", "Sin horario"],  
        }
    },
    props:{},
    created(){
    }, 
    mounted(){},  
    methods:{
      
    },
    computed: {
      lang(){
        return window.strings
      },
    },
    watch: {
      
    }
})