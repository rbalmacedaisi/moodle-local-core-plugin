/**
 * GroupGradeConfirmModal.js
 *
 * Modal que muestra la advertencia de re-calificacion grupal cuando el backend
 * ya tiene notas previas para miembros del grupo. El docente puede:
 *   - "Atras"    -> descartar, volver al QuickGrader sin guardar
 *   - "Continuar y sobrescribir" -> cerrar el modal y pedir al padre que
 *     reenvie con confirm=true
 *
 * El padre (QuickGrader) controla el flujo; este componente solo notifica
 * decisiones via emits.
 *
 * Introducido en 20261001057.
 */
Vue.component('group-grade-confirm-modal', {
    props: {
        visible: { type: Boolean, default: false },
        payload: { type: Object, default: () => ({}) } // respuesta del pre-flight
    },
    template: `
        <v-dialog :value="visible" persistent max-width="640">
            <v-card>
                <v-card-title class="orange--text text--darken-2">
                    <v-icon color="orange darken-2" class="mr-2">mdi-alert-circle-outline</v-icon>
                    Algunos estudiantes ya están calificados
                </v-card-title>
                <v-card-text>
                    <div class="mb-3 grey--text text--darken-2">
                        {{ payload.message || 'Hay notas previas que se sobrescribirán si continúas.' }}
                    </div>

                    <v-alert type="warning" dense text outlined class="mb-3">
                        Esta acción <b>sobrescribirá</b> las notas existentes de los estudiantes listados abajo.
                        La operación no se puede deshacer.
                    </v-alert>

                    <v-subheader class="px-0">Estudiantes que serán recalificados ({{ (payload.alreadygraded || []).length }})</v-subheader>
                    <v-list dense>
                        <v-list-item v-for="u in (payload.alreadygraded || [])" :key="u.userid">
                            <v-list-item-avatar>
                                <v-icon color="orange darken-2">mdi-account-circle</v-icon>
                            </v-list-item-avatar>
                            <v-list-item-content>
                                <v-list-item-title>{{ u.fullname }}</v-list-item-title>
                                <v-list-item-subtitle class="caption">{{ u.email }}</v-list-item-subtitle>
                            </v-list-item-content>
                            <v-chip small color="orange darken-2" dark>
                                Nota actual: {{ formatGrade(u.currentgrade) }}
                            </v-chip>
                        </v-list-item>
                    </v-list>

                    <v-divider class="my-3"></v-divider>

                    <v-subheader v-if="(payload.pendingmembers || []).length" class="px-0">
                        Estudiantes sin nota previa ({{ (payload.pendingmembers || []).length }})
                    </v-subheader>
                    <v-list v-if="(payload.pendingmembers || []).length" dense>
                        <v-list-item v-for="u in payload.pendingmembers" :key="u.userid">
                            <v-list-item-avatar>
                                <v-icon color="green">mdi-account-circle</v-icon>
                            </v-list-item-avatar>
                            <v-list-item-content>
                                <v-list-item-title>{{ u.fullname }}</v-list-item-title>
                                <v-list-item-subtitle class="caption">{{ u.email }}</v-list-item-subtitle>
                            </v-list-item-content>
                            <v-chip small color="green" dark>Sin nota previa</v-chip>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="$emit('cancel')">
                        <v-icon left>mdi-arrow-left</v-icon> Atrás
                    </v-btn>
                    <v-btn color="orange darken-2" dark @click="$emit('confirm')">
                        <v-icon left>mdi-check-bold</v-icon> Continuar y sobrescribir
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    methods: {
        formatGrade(g) {
            if (g === null || g === undefined) return '-';
            const n = parseFloat(g);
            return isNaN(n) ? '-' : n.toFixed(2);
        }
    }
});
