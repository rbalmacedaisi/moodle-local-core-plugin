let data = (id) => {
    console.log(id)
};
const app = new Vue({
    el: '#app',
    vuetify: new Vuetify(),
    
    data: {
      today: new Date().toISOString().substr(0,10),
      focus: new Date().toISOString().substr(0,10),
      type: 'week',
      typeToLabel: {
        month: 'Mes',
        week: 'Week',
        day: 'Day',
      },
      start: null,
      end: null,
      selectedEvent: {},
      selectedElement: null,
      selectedOpen: false,
      events: [],
      name: null,
      details: null,
      color: '#1976D2',
      dialog: false,
      currentlyEditing: null,
      items:[
        {id: 1, text: 'Artur R. Mendoza', value: 'Artur R. Mendoza'},
        {id: 2, text: 'Jorge N. Woods', value: 'Jorge N. Woods'},
        {id: 3, text: 'George R. Mendoza', value: 'George R. Mendoza'},
      ],
      select: [],
    },
    mounted () {
      this.$refs.calendar.checkChange();
    },
    created(){
      this.getEvents();
    },
    methods: {
      getEvents(){
        const data = [
          {
            name: 'Maquinaría',
            instructor: 'Artur R. Mendoza',
            details: 'Virtual',
            color: '#E5B751',
            start: '2023-03-13 09:15',
            end: '2023-03-13 11:30',
            categories: 'Artur R. Mendoza'
          },
          {
            name: 'Soldadura',
            instructor: 'Jorge N. Woods',
            details: 'Virtual',
            color: '#064377',
            start: '2023-03-15 15:00',
            end: '2023-03-15 17:00',
            categories: 'Jorge N. Woods'
          },
          {
            name: 'Maquinaría',
            instructor: 'George R. Mendoza',
            details: 'Presencial',
            color: '#0a4807',
            start: '2023-03-16 15:00',
            end: '2023-03-16 17:00',
            categories: 'George R. Mendoza'
          },
        ]
        data.forEach((element) => {
          this.events.push({
            name: element.name,
            details: element.details,
            start: element.start,
            end: element.end,
            color: element.color,
            instructor: element.instructor,
          })
        })
      },
      viewDay ({ date }) {
        this.focus = date
        this.type = 'day'
      },
      setToday () {
        this.focus = this.today
      },
      prev () {
        this.$refs.calendar.prev()
      },
      next () {
        this.$refs.calendar.next()
      },
      getEventColor (event) {
        return event.color
      },
      showEvent ({ nativeEvent, event }) {
        const open = () => {
          this.selectedEvent = event
          this.selectedElement = nativeEvent.target
          setTimeout(() => this.selectedOpen = true, 10)
        }

        if (this.selectedOpen) {
          this.selectedOpen = false
          setTimeout(open, 10)
        } else {
          open()
        }

        nativeEvent.stopPropagation()
      },
      updateRange ({ start, end }) {
        // You could load events from an outside source (like database) now that we have the start and end dates on the calendar
        this.start = start
        this.end = end
      },
    }
  })