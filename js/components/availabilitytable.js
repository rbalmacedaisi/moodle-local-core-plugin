Vue.component('availabilitytable',{
    template: `
        
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                    :headers="headers"
                    :items="dessertsSorted"
                    class="elevation-1"
                    dense
                >
                    <template v-slot:top>
                        <v-toolbar
                            flat
                        >
                            <v-toolbar-title>Disponibilidad</v-toolbar-title>
                            <v-divider
                              class="mx-4"
                              inset
                              vertical
                            ></v-divider>
                            <span class="font-weight-bold">Cuatrimestre 1 - 2023</span>
                            <v-spacer></v-spacer>
                            <v-dialog
                              v-model="dialog"
                              max-width="800px"
                            >
                              <template v-slot:activator="{ on, attrs }">
                                <v-btn
                                  color="primary"
                                  dark
                                  class="mb-2"
                                  v-bind="attrs"
                                  v-on="on"
                                >
                                  Nuevo Item
                                </v-btn>
                              </template>
                              <v-card>
                                <v-card-title>
                                  <span class="text-h5">{{ formTitle }}</span>
                                </v-card-title>
                    
                                <v-card-text>
                                    <v-container>
                                        <v-row>
                                            <v-col
                                                cols="12"
                                                sm="6"
                                                md="6"
                                            >
                                                <v-select
                                                  :items="instructors"
                                                  label="Instructor"
                                                  outlined
                                                  dense
                                                  v-model="editedItem.name"
                                                ></v-select>
                                            </v-col>
                                            <v-col
                                              cols="12"
                                              sm="6"
                                              md="6"
                                            >
                                                <v-combobox
                                                  v-model="editedItem.every"
                                                  :items="daysOfWeek"
                                                  label="Días"
                                                  outlined
                                                  dense
                                                  hide-details
                                                  class="mr-2"
                                                  clearable
                                                  multiple
                                                ></v-combobox>
                                        
                                            </v-col>
                                            <v-col
                                                cols="12"
                                                sm="6"
                                                md="6"
                                            >
                                                <v-text-field
                                                    v-model="editedItem.from"
                                                    label="From"
                                                    type="time"
                                                    dense
                                                    outlined
                                                ></v-text-field>
                                            </v-col>
                                            <v-col
                                                cols="12"
                                                sm="6"
                                                md="6"
                                            >
                                               <v-text-field
                                                    v-model="editedItem.to"
                                                    label="To"
                                                    type="time"
                                                    dense
                                                    outlined
                                                ></v-text-field>
                                            </v-col>
                                        </v-row>
                                    </v-container>
                                </v-card-text>
                    
                                <v-card-actions>
                                  <v-spacer></v-spacer>
                                  <v-btn
                                    color="blue darken-1"
                                    text
                                    @click="close"
                                  >
                                    Cancel
                                  </v-btn>
                                  <v-btn
                                    color="blue darken-1"
                                    text
                                    @click="save"
                                  >
                                    Save
                                  </v-btn>
                                </v-card-actions>
                              </v-card>
                            </v-dialog>
                            <v-dialog v-model="dialogDelete" max-width="500px">
                              <v-card>
                                <v-card-title class="text-h5">Are you sure you want to delete this item?</v-card-title>
                                <v-card-actions>
                                  <v-spacer></v-spacer>
                                  <v-btn color="blue darken-1" text @click="closeDelete">Cancel</v-btn>
                                  <v-btn color="blue darken-1" text @click="deleteItemConfirm">OK</v-btn>
                                  <v-spacer></v-spacer>
                                </v-card-actions>
                              </v-card>
                            </v-dialog>
                        </v-toolbar>
                    </template>
                    
                    <template v-slot:item.instructorName="{ item }">
                        <v-list>
                          <v-list-item>
                            <v-list-item-avatar>
                              <img
                                :src="item.instructorPicture"
                                alt="John"
                              >
                            </v-list-item-avatar>
                
                            <v-list-item-content>
                              <v-list-item-title>{{item.instructorName}}</v-list-item-title>
                            </v-list-item-content>
                          </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.availability="{ item }">
                        <instructoravailability :data="item"></instructoravailability>
                    </template>
                    <template v-slot:item.to="{ item }">
                      <v-text-field
                        v-model="item.to"
                        type="time"
                        readonly
                        append-icon="mdi-clock-outline"
                        dense
                        class="tiemfield-to my-1"
                        style="width: 165px;"
                        :append-icon-size="16"
                        filled
                        hide-details
                      ></v-text-field>
                    </template>
                    <template v-slot:item.actions="{ item }">
                        <v-icon
                            small
                            class="mr-2"
                            @click="editItem(item)"
                        >
                            mdi-pencil
                        </v-icon>
                        <v-icon
                            small
                            @click="deleteItem(item)"
                        >
                            mdi-delete
                        </v-icon>
                    </template>
                    
                    <template v-slot:no-data>
                        <v-btn
                            color="primary"
                            @click="initialize"
                        >
                            Reset
                        </v-btn>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>
    `,
    data(){
        return{
        dialog: false,
          dialogDelete: false,
          headers: [
            {
              text: 'Instructor',
              align: 'start',
              sortable: false,
              value: 'instructorName',
            },
            { text: 'Disponibilidad', value: 'availability',sortable: false },
            { text: 'Actions', value: 'actions', sortable: false },
          ],
          desserts: [],
          editedIndex: -1,
          editedItem: {
            name: '',
            every: '',
            from: '',
            to: '',
          },
          defaultItem: {
            name: '',
            every: '',
            from: '',
            to: '',
          },
          instructors:undefined,
          daysOfWeek: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
          start: null,
            end: null,
        }
    },
    props:{
        
    },
    created(){
        this.initialize()
        this.instructors = window.instructorItems;
    },
    mounted(){
        
    },  
    methods:{
        initialize () {
            this.desserts = [
              {
                instructorName: 'Nataly Hoyos',
                instructorId: 1,
                instructorPicture: "https://cdn.vuetifyjs.com/images/john.jpg",
                disponibilityRecords:{
                  Lunes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ],
                  Martes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ]
                }
              },
              {
                instructorName: 'Ximena Rincon',
                instructorId: 2,
                instructorPicture: "https://cdn.vuetifyjs.com/images/john.jpg",
                disponibilityRecords:{
                  Lunes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ],
                  Martes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ]
                },
              },
            ]
        },

        editItem (item) {
            this.editedIndex = this.desserts.indexOf(item)
            this.editedItem = Object.assign({}, item)
            this.dialog = true
        },

        deleteItem (item) {
            this.editedIndex = this.desserts.indexOf(item)
            this.editedItem = Object.assign({}, item)
            this.dialogDelete = true
        },
        
        deleteItemConfirm () {
            this.desserts.splice(this.editedIndex, 1)
            this.closeDelete()
        },
        
        close () {
            this.dialog = false
            this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
            this.editedIndex = -1
            })
        },

        closeDelete () {
            this.dialogDelete = false
            this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
                this.editedIndex = -1
            })
        },
        
        save () {
            if (this.editedIndex > -1) {
                Object.assign(this.desserts[this.editedIndex], this.editedItem)
            } else {
                this.desserts.push(this.editedItem)
            }
            this.close()
        },
    },
    computed: {
        formTitle () {
            return this.editedIndex === -1 ? 'Nuevo Item' : 'Editar Item'
        },
        dessertsSorted() {
          return this.desserts.sort((a, b) => {
            if (a.name < b.name) return -1;
            if (a.name > b.name) return 1;
            return 0;
          });
        }
    },
    watch: {
        dialog (val) {
            val || this.close()
        },
        dialogDelete (val) {
            val || this.closeDelete()
        },
    },
})
