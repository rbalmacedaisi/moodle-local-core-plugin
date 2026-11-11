// Selector de asignaturas para Configuracion: filtro por carrera + busqueda escribiendo + seleccion multiple.
// Emite 'add' con [{ planid, courseid, name }]. Con require-career, la carrera es obligatoria (ajustes por carrera).
Vue.component('subject-picker', {
    template: `
    <div class="tl-picker">
        <v-select dense outlined hide-details class="tl-picker-career" :items="careerItems" item-text="text" item-value="value"
                  v-model="career" :label="requireCareer ? 'Carrera (obligatoria)' : 'Filtrar por carrera'"></v-select>
        <v-autocomplete dense outlined hide-details multiple small-chips deletable-chips clearable class="tl-picker-search"
                        :items="items" item-text="name" item-value="courseid" v-model="picked"
                        :disabled="requireCareer && !career" :search-input.sync="search" @change="search = ''"
                        :label="requireCareer && !career ? 'Elija primero la carrera' : 'Escriba para buscar asignaturas…'"
                        no-data-text="Sin resultados" :menu-props="{ maxHeight: 320 }">
            <template v-slot:item="{ item, attrs, on }">
                <v-list-item v-bind="attrs" v-on="on" dense>
                    <v-list-item-action class="my-0 mr-2"><v-simple-checkbox :value="attrs.inputValue" @input="on.click" :ripple="false"></v-simple-checkbox></v-list-item-action>
                    <v-list-item-content>
                        <v-list-item-title class="text-body-2">{{ item.name }}</v-list-item-title>
                        <v-list-item-subtitle v-if="!career && item.careers" class="text-caption">{{ item.careers }}</v-list-item-subtitle>
                        <v-list-item-subtitle v-else-if="item.note" class="text-caption">{{ item.note }}</v-list-item-subtitle>
                    </v-list-item-content>
                </v-list-item>
            </template>
        </v-autocomplete>
        <button class="tl-cfg-btn" :disabled="!picked.length" @click="add">Agregar{{ picked.length ? ' (' + picked.length + ')' : '' }}</button>
    </div>
    `,
    props: {
        careers: { type: Array, default: () => [] },          // [{ id, name, courses: [{ courseid, name }] }]
        exclude: { type: Array, default: () => [] },          // courseids ya elegidos (o 'planid|courseid' con require-career)
        requireCareer: { type: Boolean, default: false },
        notes: { type: Object, default: () => ({}) },         // courseid => texto (p. ej. "crédito 0")
    },
    data() {
        return { career: 0, picked: [], search: '' };
    },
    computed: {
        careerItems() {
            const list = this.careers.map(c => ({ value: c.id, text: this.shortPlan(c.name) })).sort((a, b) => a.text.localeCompare(b.text));
            return this.requireCareer ? list : [{ value: 0, text: 'Todas las carreras' }].concat(list);
        },
        items() {
            const ex = this.exclude.map(String);
            if (this.career) {
                const c = this.careers.find(x => x.id === this.career) || { courses: [] };
                return (c.courses || [])
                    .filter(x => !ex.includes(this.requireCareer ? this.career + '|' + x.courseid : String(x.courseid)))
                    .map(x => ({ courseid: x.courseid, name: x.name, note: this.notes[x.courseid] || '' }));
            }
            if (this.requireCareer) return [];
            const by = {};
            this.careers.forEach(c => (c.courses || []).forEach(x => {
                const e = by[x.courseid] || (by[x.courseid] = { courseid: x.courseid, name: x.name, plans: [] });
                e.plans.push(this.shortPlan(c.name));
            }));
            return Object.values(by).filter(x => !ex.includes(String(x.courseid)))
                .map(x => ({ courseid: x.courseid, name: x.name, careers: x.plans.join(' · '), note: this.notes[x.courseid] || '' }))
                .sort((a, b) => a.name.localeCompare(b.name));
        },
    },
    watch: {
        career() { this.picked = []; },
    },
    methods: {
        add() {
            const names = {};
            this.items.forEach(x => { names[x.courseid] = x.name; });
            this.$emit('add', this.picked.map(cid => ({ planid: this.career || 0, courseid: cid, name: names[cid] || ('Curso ' + cid) })));
            this.picked = [];
        },
        shortPlan(name) {
            let n = String(name || '').replace(/^T[ÉE]CNICO SUPERIOR EN\s+/i, '').replace(/^CURSO DE\s+/i, '');
            return n.charAt(0) + n.slice(1).toLowerCase();
        },
    },
});
