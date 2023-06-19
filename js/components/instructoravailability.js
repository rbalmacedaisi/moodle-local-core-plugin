Vue.component('instructoravailability',{
    template: `
        <v-menu
          v-model="menu"
          :close-on-content-click="false"
          :nudge-width="200"
          bottom
          left
          offset-y
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
                  Horarios
                </v-btn>
            </template>
    
            <v-card max-height="350">
                <v-list>
                    <v-list-item>
                        <v-list-item-avatar>
                            <img :src="data.instructorPicture" alt="John">
                        </v-list-item-avatar>
        
                        <v-list-item-content>
                            <v-list-item-title>{{data.instructorName}}</v-list-item-title>
                            <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
    
                <v-divider class="my-0"></v-divider>
    
                <v-list>
                    <template v-for="(item, index) in items">
                        <v-list-item :class="index % 2 === 0 ? 'even-item' : 'odd-item'" :key="item.id" style="border-bottom: 1px solid #b0b0b0;">
                            <v-list-item-title>{{item.text}}</v-list-item-title>
                        </v-list-item>
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
            var flat = 20
            for (let day in data.disponibilityRecords) {
                flat++
                this.items.push({
                    text: `${day}: ${data.disponibilityRecords[day].join(' - ')}`,
                    id: flat
                })
            }
        }
    },
})
