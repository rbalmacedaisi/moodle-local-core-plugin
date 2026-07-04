/**
 * Failed Subjects Report — student detail drawer.
 *
 * Loaded by pages/failed_subjects_report.php. Defines the
 * <failed-subjects-drawer> component that shows contact info, plans
 * and the full list of historical failed/revalidating subjects for a
 * given Moodle userid.
 */
Vue.component('failed-subjects-drawer', {
    props: {
        userid: { type: Number, default: 0 },
        open:   { type: Boolean, default: false },
        sesskey: { type: String, default: '' }
    },
    data() {
        return {
            loading: false,
            error: null,
            detail: null
        };
    },
    watch: {
        open(v) {
            if (v && this.userid > 0) {
                this.fetchDetail();
            }
        },
        userid(v) {
            if (this.open && v > 0) {
                this.fetchDetail();
            }
        }
    },
    methods: {
        async fetchDetail() {
            if (this.userid <= 0) return;
            this.loading = true;
            this.error = null;
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_failed_subjects_student_detail',
                    args: { userid: this.userid },
                    sesskey: this.sesskey
                });
                if (r.data && r.data.status === 'success') {
                    this.detail = r.data.data;
                } else {
                    throw new Error((r.data && r.data.message) || 'Error');
                }
            } catch (e) {
                this.error = e.message || 'No se pudo cargar el detalle.';
                this.detail = null;
            } finally {
                this.loading = false;
            }
        },
        financialPillClass(code) {
            const c = (code || '').toLowerCase();
            if (c === 'al_dia') return 'fsr-pill-green';
            if (c === 'mora')   return 'fsr-pill-red';
            if (c === 'becado' || c === 'beca') return 'fsr-pill-blue';
            if (c === 'periodo_gracia') return 'fsr-pill-amber';
            return 'fsr-pill-grey';
        },
        financialLabel(code) {
            const c = (code || '').toLowerCase();
            if (c === 'al_dia') return 'Al día';
            if (c === 'mora')   return 'En mora';
            if (c === 'becado' || c === 'beca') return 'Becado';
            if (c === 'periodo_gracia') return 'Periodo de gracia';
            return code;
        },
        openProfile() {
            if (this.detail && this.detail.profile_url) {
                window.open(this.detail.profile_url, '_blank');
            }
        },
        close() {
            this.$emit('close');
        }
    },
    template: `
        <v-navigation-drawer
            v-model="open"
            :right="true"
            temporary
            width="480"
            class="fsr-drawer"
        >
            <v-toolbar flat dense color="primary" dark>
                <v-toolbar-title>
                    <v-icon left>mdi-account-circle</v-icon>
                    {{ detail ? detail.fullname : (loading ? 'Cargando...' : 'Detalle') }}
                </v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-toolbar>

            <v-card flat class="pa-4" tile>
                <div v-if="loading" class="fsr-loading">
                    <v-progress-circular indeterminate color="primary" size="32"></v-progress-circular>
                </div>
                <div v-else-if="error" class="red--text">{{ error }}</div>
                <div v-else-if="detail">
                    <h3 class="fsr-link" style="cursor:pointer" @click="openProfile">
                        {{ detail.fullname }}
                    </h3>
                    <div class="text-caption grey--text">
                        <span v-if="detail.idnumber">ID: {{ detail.idnumber }}</span>
                        <span v-if="detail.cedula"> &middot; Cédula: {{ detail.cedula }}</span>
                    </div>

                    <div style="margin-top:8px" v-if="detail.financial_status">
                        <span class="fsr-pill" :class="financialPillClass(detail.financial_status)">
                            {{ financialLabel(detail.financial_status) }}
                        </span>
                    </div>

                    <div class="fsr-drawer-section">
                        <h4>Planes de carrera</h4>
                        <div v-if="!detail.plans || detail.plans.length === 0" class="text-caption grey--text">
                            Sin planes activos.
                        </div>
                        <div v-for="p in detail.plans" :key="p.id" class="fsr-contact-row">
                            <v-icon small>mdi-bookmark-outline</v-icon>
                            <div>
                                <div><strong>{{ p.name }}</strong>
                                    <span v-if="p.status && p.status !== 'activo'" class="fsr-pill fsr-pill-amber" style="margin-left:6px">
                                        {{ p.status }}
                                    </span>
                                </div>
                                <div class="text-caption grey--text" v-if="p.currentperiodname">
                                    Cuatrimestre actual: {{ p.currentperiodname }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fsr-drawer-section">
                        <h4>Contacto</h4>
                        <div v-if="detail.contact_phone" class="fsr-contact-row">
                            <v-icon small>mdi-phone</v-icon>
                            <a :href="'tel:' + detail.contact_phone" class="fsr-link">{{ detail.contact_phone }}</a>
                        </div>
                        <div v-if="detail.contact_mobile" class="fsr-contact-row">
                            <v-icon small>mdi-cellphone</v-icon>
                            <a :href="'tel:' + detail.contact_mobile" class="fsr-link">{{ detail.contact_mobile }}</a>
                        </div>
                        <div v-if="detail.contact_email" class="fsr-contact-row">
                            <v-icon small>mdi-email-outline</v-icon>
                            <a :href="'mailto:' + detail.contact_email" class="fsr-link">{{ detail.contact_email }}</a>
                        </div>
                        <div v-if="detail.phone1 && detail.phone1 !== detail.contact_phone" class="fsr-contact-row">
                            <v-icon small>mdi-phone-classic</v-icon>
                            <span>Moodle: {{ detail.phone1 }}</span>
                        </div>
                        <div v-if="!detail.contact_phone && !detail.contact_mobile && !detail.contact_email"
                             class="text-caption grey--text">
                            Sin datos de contacto en Odoo.
                        </div>
                    </div>

                    <div class="fsr-drawer-section">
                        <h4>Asignaturas reprobadas / en reválida</h4>
                        <div v-if="!detail.history || detail.history.length === 0" class="text-caption grey--text">
                            Sin historial.
                        </div>
                        <table v-else class="fsr-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Asignatura</th>
                                    <th>Nota</th>
                                    <th>Plan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="h in detail.history" :key="h.progress_id">
                                    <td>
                                        <div class="fsr-truncate" :title="h.coursename">{{ h.coursename }}</div>
                                    </td>
                                    <td class="fsr-nowrap">
                                        <span class="fsr-grade" :class="h.grade > 0 ? 'fsr-sem-full' : ''">
                                            {{ h.grade > 0 ? h.grade.toFixed(1) : '—' }}
                                        </span>
                                    </td>
                                    <td class="fsr-nowrap text-caption">{{ h.planname || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fsr-drawer-section" style="text-align:center">
                        <v-btn color="primary" outlined :href="detail.profile_url" target="_blank">
                            <v-icon left>mdi-open-in-new</v-icon>
                            Abrir perfil en Moodle
                        </v-btn>
                    </div>
                </div>
            </v-card>
        </v-navigation-drawer>
    `
});
