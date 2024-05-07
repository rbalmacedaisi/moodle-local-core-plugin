Vue.component('availableschedulesdialog',{
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              max-width="600"
            >
                <v-card>
                   <v-card-title>
                      {{ lang.move_to }}:
                      <template v-for="(title, index) in moveTitle">
                        <span :key="index">{{ title }}</span>
                        <!-- Añade una coma y un espacio si no es el último elemento -->
                        <span v-if="index < moveTitle.length - 1">, </span>
                      </template>
                    </v-card-title>
                    
                    <v-card-subtitle class="d-flex align-center mt-1">
                        {{ lang.current_location }}
                        
                        <v-btn
                          color="secondary"
                          class="rounded ml-2"
                          small
                        >
                            <v-icon left>
                                mdi-folder-account-outline
                            </v-icon>
                            {{schelduletitle}}
                        </v-btn>
                    </v-card-subtitle>
                    
                    <v-divider class="my-0"></v-divider>
                    
                    <v-card-text>
                        <v-list
                          subheader
                          three-line
                        >
                            <v-subheader class="text-h6">{{lang.classschedule}}</v-subheader>
                            <v-list-item-group
                                v-model="selectedClass"
                                color="primary"
                            >
                                <v-list-item
                                    v-for="schedule in schedules"
                                    :key="schedule.title"
                                    @click="newClassSelected(schedule)"
                                >
                                    <v-list-item-avatar>
                                        <v-icon
                                            class="grey lighten-1"
                                            small
                                            :dark="!$vuetify.theme.isDark"
                                        >
                                            mdi-folder
                                        </v-icon>
                                    </v-list-item-avatar>
                        
                                    <v-list-item-content>
                                        <v-list-item-title v-text="schedule.name"></v-list-item-title>
                                        <v-list-item-subtitle v-text="schedule.days"></v-list-item-subtitle>
                                        <v-list-item-subtitle v-text="schedule.start + ' a ' + schedule.end"></v-list-item-subtitle>
                                    </v-list-item-content>
                        
                                    <v-list-item-action>
                                        <v-menu
                                          :close-on-content-click="false"
                                          :nudge-width="180"
                                          bottom
                                          left
                                          open-on-hover
                                        >
                                            <template v-slot:activator="{ on, attrs }">
                                                <v-btn
                                                    icon
                                                    v-bind="attrs"
                                                    v-on="on"
                                                >
                                                    <v-icon color="grey lighten-1">mdi-information</v-icon>
                                                </v-btn>
                                            </template>
                                    
                                            <v-card>
                                                <v-list dense>
                                                    <v-list-item>
                                                        <v-list-item-avatar>
                                                            <img
                                                                :src="schedule.picture"
                                                                alt="profile"
                                                            >
                                                        </v-list-item-avatar>
                                        
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{schedule.instructor}}</v-list-item-title>
                                                            <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                                                        </v-list-item-content>
                                                  </v-list-item>
                                                </v-list>
                                    
                                                <v-divider class="my-0"></v-divider>
                                    
                                                <v-list dense>
                                                    <v-list-item>
                                                        <v-list-item-icon>
                                                            <v-icon v-if="schedule.type === 'Virtual'">mdi-desktop-mac</v-icon>
                                                            <v-icon v-else >mdi-account-group</v-icon>
                                                        </v-list-item-icon>
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{schedule.type}}</v-list-item-title>
                                                        </v-list-item-content>
                                                    </v-list-item>
                                        
                                                    <v-list-item>
                                                        <v-list-item-icon>
                                                            <v-icon>mdi-account-multiple-check</v-icon>
                                                        </v-list-item-icon>
                                                        <v-list-item-content>
                                                            <v-list-item-title>{{schedule.users}}</v-list-item-title>
                                                        </v-list-item-content>
                                                    </v-list-item>
                                                </v-list>
                                            </v-card>
                                        </v-menu>
                                    </v-list-item-action>
                                </v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-card-text>
                    
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           text
                           @click="closemovedialog"
                        >
                            {{lang.cancel}}
                        </v-btn>
                        
                        <v-btn
                           color="primary"
                           text
                           @click="saveClass"
                           class="d-none"
                        >
                            {{lang.save}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data(){
        return{
            dialog: true,
            selectedClass: '',
        }
    },
    props:{
        moveTitle: Array,
        schedules: Array,
        schelduletitle: String
    },
    created(){},
    mounted(){},  
    methods:{
        // Emits a 'close-move-dialog' event to close the move dialog.
        closemovedialog(){
            this.$emit('close-move-dialog')
        },
        saveClass(){
            // Emits a 'save-class' event to save the selected class.
            this.$emit('save-class')
        },
        /**
         * Emits a 'new-class' event with the selected schedule for a new class.
         * @param '{Object} schedule' - The selected schedule for the new class.
         */
        newClassSelected(schedule){
            this.$emit('new-class', schedule)
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
    },
    
})