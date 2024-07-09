Vue.component('instructorcompetencies',{
    template: `
        <v-menu
          v-model="menu"
          :close-on-content-click="false"
          :nudge-width="200"
          offset-x
          left
          absolute
        >
            <template v-slot:activator="{ on, attrs }">
                <v-btn
                  color="secondary"
                  dark
                  v-bind="attrs"
                  v-on="on"
                  small
                  text
                >
                  Ver
                </v-btn>
            </template>
    
            <v-card max-height="350">
                <v-list>
                    <v-list-item>
                        <v-list-item-avatar>
                            <img :src="instructorData.instructorPicture" alt="picture">
                        </v-list-item-avatar>
        
                        <v-list-item-content>
                            <v-list-item-title>{{instructorData.instructorName}}</v-list-item-title>
                            <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
    
                <v-divider class="my-0"></v-divider>
    
                <v-list >
                    <template v-for="(skill,index) in skills">
                        <v-list-item :class="index % 2 === 0 ? 'even-item' : 'odd-item'" :key="index" style="border-bottom: 1px solid #b0b0b0;">
                            <v-list-item-title>{{skill}}</v-list-item-title>
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
                        {{ lang.close }}
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
        }
    },
    props:{
        instructorData:Object
    },
    computed:{
        skills(){
            return this.instructorData.skills
        },
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
