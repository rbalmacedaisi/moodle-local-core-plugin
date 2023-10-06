Vue.component('waitingtable',{
    template: `
        <v-data-table
          v-model="selected"
          :headers="tableHeaders"
          :items="students"
          show-select
          dense
          :items-per-page="50"
          hide-default-footer
          class="check-table"
          @input="itemselect"
          @item-selected="checkItem"
        >
            <template v-slot:top>
                <div class="px-3">
                    <h6 class="mb-0 "> {{classData.className}}</h6>
                    <span> {{ classData.classDays + ' ' + classData.initHour + ' ' +  classData.endHour }}</span>
                </div>
            </template>
          
            <template v-slot:item.student="{ item }">
                <v-list class="transparent">
                    <v-list-item class="pl-0">
                        <v-list-item-avatar>
                            <img :src="item.profilePicture" alt="picture">
                        </v-list-item-avatar>

                        <v-list-item-content>
                            <v-list-item-title>{{item.student}}</v-list-item-title>
                            <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </template>
          
            <template v-slot:item.actions="{ item }">
                <v-tooltip bottom>
                    <template v-slot:activator="{ on, attrs }">
                        <v-icon
                         class="mr-2"
                         v-bind="attrs"
                         v-on="on"
                         @click="moveItem(item)"
                        >
                            mdi-folder-move-outline
                        </v-icon>
                    </template>
                    <span>Mover a</span>
                </v-tooltip>
              
                <v-tooltip bottom>
                    <template v-slot:activator="{ on, attrs }">
                        <v-icon 
                         @click="deleteAvailabilityRecord(item)" 
                         v-bind="attrs"
                         v-on="on"
                        >
                            mdi-trash-can-outline
                        </v-icon>
                    </template>
                    <span>{{lang.remove}}</span>
                </v-tooltip>
            </template>
        </v-data-table>
    `,
    data(){
        return{
            selected: []
        }
    },
    props: {
        classData: Object ,
        selectusers: Boolean
    },
    created(){
    }, 
    mounted(){},  
    methods:{
        itemselect(e){
            this.$emit('selection-changed', this.selected);
        },
        // Esta función se ejecutará cuando se marque o desmarque un elemento
        checkItem({ item, value }) {
          //console.log(value);
          
        },
    },
    computed: {
      lang(){
        return window.strings
      },
      students() {
            // Obtén la lista de estudiantes de la clase actual
            return Object.values(this.classData.queue.queuedStudents);
        },
        tableHeaders() {
            // Define las columnas de la tabla
            return [
                {
                    text: 'Estudiante',
                    align: 'start',
                    sortable: false,
                    value: 'student',
                },
                { text: 'Actions', value: 'actions', sortable: false },
            ];
        },
    },
    watch: {
        selectusers(value) {
            this.selected = value ? [...this.students] : [];
            //console.log('selected in watch:', this.selected); // Verificar la selección
            //this.$emit('selection-changed', this.selected);
        },
        selected() {
            //console.log(this.selected.length); // Verificar la selección
            //this.$emit('selection-changed');
            //this.$emit('selection-changed', this.selected);
        }
    }
})