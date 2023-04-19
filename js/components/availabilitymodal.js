Vue.component('availabilitymodal',{
    template: `
        <v-dialog
          v-model="dialog"
          persistent
          max-width="800"
        >
            <v-card max-width="800">
                <v-card-title class="text-h5">
                  Horas Disponibles
                </v-card-title>
                <v-card-text>
                    <v-sheet height="600">
                        <v-calendar
                          color="primary"
                          type="day"
                          first-time="7"
                          interval-count="14"
                          locale="es"
                          :value="dayselected"
                        >
                          <template v-slot:interval="{ time,date,day,hour,minute}">
                            <div
                                class="h-100 blue white--text"
                                v-if="hoursFree.indexOf(time) !== -1"
                            >
                              Disponible {{time}}
                            </div>
                          </template>
                          
                            <template v-slot:day-label="{date,day}">
                                <v-btn
                                   small
                                   fab
                                   type="button"
                                   @click="toggleDayFree(date,day)"
                                   :class="(daysFree.indexOf(date.toString()) !== -1) ? 'success' : 'transparent elevation-0'"
                                   :style="(daysFree.indexOf(date.toString()) !== -1) ? 'event-pointer: none !important;' : ''"
                                   >
                                    <span class="v-btn__content">{{day}}</span>
                                </v-btn>
                                
                            </template>
                        </v-calendar>
                    </v-sheet>
                </v-card-text>
                <v-card-actions>
                  <v-spacer></v-spacer>
                  <v-btn
                    color="green darken-1"
                    text
                    @click="dialog = false,$emit('close-dialog')"
                  >
                    Cerrar
                  </v-btn>
                </v-card-actions>
          </v-card>
        </v-dialog>
    `,
    // props:['dayselected'],
    props:{
        dayselected:String
    },
    data(){
        return{
            dialog: true,
            hoursFree: [
                '08:00','09:30','10:00','11:00'
            ]
        }
    },
    created(){},
    computed:{
        dayLabel(){
          return new Date(this.dayselected).toLocaleDateString('en-US', { weekday: 'narrow' });    
        }
    },
    mounted () {
        
    },
    methods: {
        
    },
})