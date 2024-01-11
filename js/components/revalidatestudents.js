Vue.component('revalidatestudents',{
    template: `
    <div>
        <v-menu
          v-model="menu"
          :close-on-content-click="false"
          :nudge-width="200"
          offset-x
          left
          v-if="Array.isArray(studentsData.revalidate) && studentsData.revalidate.length > 0"
        >
            <template v-slot:activator="{ on, attrs }">
                <v-btn
                  color="#EC407A"
                  dark
                  text-color="white"
                  small
                  v-bind="attrs"
                  v-on="on"
                  class="rounded"
                >
                  {{ lang.revalidation }}
                </v-btn>
            </template>
    
            <v-card>
                <v-list>
                    <v-list-item>
                        <v-list-item-avatar>
                          <img
                            :src="studentsData.img"
                            alt="picture"
                          >
                        </v-list-item-avatar>
            
                        <v-list-item-content>
                          <v-list-item-title>{{ studentsData.name }}</v-list-item-title>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
    
                <v-divider class="my-0"></v-divider>
    
                <v-list dense>
                    
                    <template v-for="(revalid,index) in courses">
                        <v-list-item :class="index % 2 === 0 ? 'even-item' : 'odd-item'" :key="index" style="border-bottom: 1px solid rgba(0,0,0,.12);">
                            <v-list-item-title>{{revalid.coursename}}</v-list-item-title>
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
        
        <span v-else class="d-block text-center"> {{ studentsData.revalidate }} </span>
        </div>
    `,
    data(){
        return{
            fav: true,
            menu: false,
        }
    },
    props:{
        studentsData: Object
    },
    computed:{
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings
        },
        courses(){
            return this.studentsData.revalidate
        }
    },
})
