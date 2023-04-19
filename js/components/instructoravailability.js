Vue.component('instructoravailability',{
    template: `
        <v-menu
          v-model="menu"
          :close-on-content-click="false"
          :nudge-width="200"
          offset-x
        >
          <template v-slot:activator="{ on, attrs }">
            <v-btn
              color="primary"
              dark
              v-bind="attrs"
              v-on="on"
              small
              text
            >
              Ver Disponibilidad
            </v-btn>
          </template>
    
          <v-card>
            <v-list>
              <v-list-item>
                <v-list-item-avatar>
                  <img
                    :src="data.instructorPicture"
                    alt="John"
                  >
                </v-list-item-avatar>
    
                <v-list-item-content>
                  <v-list-item-title>{{data.instructorName}}</v-list-item-title>
                  <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                </v-list-item-content>
              </v-list-item>
            </v-list>
    
            <v-divider class="my-0"></v-divider>
    
            <v-list >
              <template v-for="(item, index) in items">
                <v-list-item :class="index % 2 === 0 ? 'even-item' : 'odd-item'"
                  :key="index">
                  <v-list-item-title>{{item}}</v-list-item-title>
                </v-list-item>
                <v-divider
                  v-if="index < items.length - 1"
                  :key="index"
                  class="my-1"
                ></v-divider>
              </template>
            </v-list>
    
            <v-card-actions>
              <v-spacer></v-spacer>
              <v-btn
                color="primary"
                text
                @click="menu = false"
              >
                Cerrar
              </v-btn>
            </v-card-actions>
          </v-card>
        </v-menu>  
    `,
    data(){
        return{
        
            fav: true,
            menu: false,
            message: false,
            hints: true,
            items:[]
        }
    },
    props:['data'],
    created(){
      this.getData()
    },
    mounted(){
        
    },  
    methods:{
      getData(){
        const data = this.data
        for (let day in data.disponibilityRecords) {
          console.log(`${day}: ${data.disponibilityRecords[day].join(' - ')}`);
        
          this.items.push(
            `${day}: ${data.disponibilityRecords[day].join(' - ')}`
          )
        }
      }
    },
})
