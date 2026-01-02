/**
 * Teacher Profile Component
 * Allows teachers to edit their personal information and change their password.
 */

const TeacherProfile = {
    template: `
        <v-app>
            <v-main class="grey lighten-5">
                <v-container class="pa-4" style="max-width: 900px;">
                    <!-- Header -->
                    <div class="d-flex align-center mb-6">
                        <v-btn icon color="primary" class="mr-3" @click="goBack">
                            <v-icon>mdi-arrow-left</v-icon>
                        </v-btn>
                        <div>
                            <h1 class="text-h4 font-weight-bold primary--text">{{ lang.myprofile || 'Mi Perfil' }}</h1>
                            <div class="text-subtitle-1 grey--text">Gestiona tu información personal y seguridad</div>
                        </div>
                    </div>

                    <v-row>
                <v-col cols="12" md="4">
                    <v-card class="rounded-xl elevation-2 text-center pa-6">
                        <v-avatar size="120" color="grey lighten-2" class="mb-4">
                            <v-img v-if="userPictureUrl" :src="userPictureUrl"></v-img>
                            <span v-else class="text-h3 grey--text text--darken-2 font-weight-bold">{{ userInitials }}</span>
                        </v-avatar>
                        <h2 class="text-h5 font-weight-bold mb-1">{{ userFullname }}</h2>
                        <div class="text-body-2 grey--text mb-4">{{ profile.email }}</div>
                        <v-divider class="my-4"></v-divider>
                        <v-container class="pa-0">
                            <!-- Just using userPictureUrl for consistency -->
                        </v-container>
                        <div class="text-caption grey--text text--lighten-1">ID de Usuario: {{ userId }}</div>
                    </v-card>
                </v-col>

                        <!-- Right Column: Forms -->
                        <v-col cols="12" md="8">
                            <v-card class="rounded-xl elevation-2">
                                <v-tabs v-model="activeTab" background-color="transparent" color="primary" grow>
                                    <v-tab><v-icon left>mdi-account</v-icon> Información Personal</v-tab>
                                    <v-tab><v-icon left>mdi-lock</v-icon> Seguridad</v-tab>
                                </v-tabs>

                                <v-tabs-items v-model="activeTab">
                                    
                                    <!-- Personal Info Tab -->
                                    <v-tab-item>
                                        <v-card-text class="pa-6">
                                            <v-form ref="profileForm" v-model="profileValid">
                                                <v-row>
                                                    <v-col cols="12" sm="6">
                                                        <v-text-field
                                                            v-model="profile.firstname"
                                                            label="Nombre(s)"
                                                            outlined
                                                            dense
                                                            :rules="[rules.required]"
                                                        ></v-text-field>
                                                    </v-col>
                                                    <v-col cols="12" sm="6">
                                                        <v-text-field
                                                            v-model="profile.lastname"
                                                            label="Apellido(s)"
                                                            outlined
                                                            dense
                                                            :rules="[rules.required]"
                                                        ></v-text-field>
                                                    </v-col>
                                                    <v-col cols="12">
                                                        <v-text-field
                                                            v-model="profile.email"
                                                            label="Correo Electrónico"
                                                            outlined
                                                            dense
                                                            :rules="[rules.required, rules.email]"
                                                        ></v-text-field>
                                                    </v-col>
                                                    <v-col cols="12">
                                                        <v-text-field
                                                            v-model="profile.phone1"
                                                            label="Teléfono / Móvil"
                                                            outlined
                                                            dense
                                                        ></v-text-field>
                                                    </v-col>
                                                    <v-col cols="12">
                                                        <v-textarea
                                                            v-model="profile.description"
                                                            label="Descripción / Biografía"
                                                            outlined
                                                            rows="4"
                                                            hint="Breve descripción sobre ti que será visible para tus estudiantes."
                                                        ></v-textarea>
                                                    </v-col>
                                                </v-row>
                                                <div class="d-flex justify-end mt-4">
                                                    <v-btn 
                                                        color="primary" 
                                                        large 
                                                        class="px-6 rounded-lg font-weight-bold"
                                                        :loading="savingProfile"
                                                        :disabled="!profileValid"
                                                        @click="updateProfile"
                                                    >
                                                        Guardar Cambios
                                                    </v-btn>
                                                </div>
                                            </v-form>
                                        </v-card-text>
                                    </v-tab-item>

                                    <!-- Security Tab -->
                                    <v-tab-item>
                                        <v-card-text class="pa-6">
                                            <v-alert type="info" text dense class="mb-6" border="left" colored-border>
                                                La contraseña debe tener al menos 8 caracteres, al menos 1 dígito(s), al menos 1 minúscula(s), al menos 1 mayúscula(s), al menos 1 caracter(es) no alfanuméricos.
                                            </v-alert>

                                            <v-form ref="securityForm" v-model="securityValid">
                                                <v-text-field
                                                    v-model="security.currentPassword"
                                                    label="Contraseña Actual"
                                                    outlined
                                                    dense
                                                    :type="showPass1 ? 'text' : 'password'"
                                                    :append-icon="showPass1 ? 'mdi-eye' : 'mdi-eye-off'"
                                                    @click:append="showPass1 = !showPass1"
                                                    :rules="[rules.required]"
                                                ></v-text-field>

                                                <v-text-field
                                                    v-model="security.newPassword"
                                                    label="Nueva Contraseña"
                                                    outlined
                                                    dense
                                                    :type="showPass2 ? 'text' : 'password'"
                                                    :append-icon="showPass2 ? 'mdi-eye' : 'mdi-eye-off'"
                                                    @click:append="showPass2 = !showPass2"
                                                    :rules="[rules.required, rules.min8]"
                                                ></v-text-field>

                                                <v-text-field
                                                    v-model="security.confirmPassword"
                                                    label="Confirmar Nueva Contraseña"
                                                    outlined
                                                    dense
                                                    :type="showPass3 ? 'text' : 'password'"
                                                    :append-icon="showPass3 ? 'mdi-eye' : 'mdi-eye-off'"
                                                    @click:append="showPass3 = !showPass3"
                                                    :rules="[rules.required, rules.passwordMatch]"
                                                ></v-text-field>

                                                <div class="d-flex justify-end mt-4">
                                                    <v-btn 
                                                        color="error" 
                                                        large 
                                                        class="px-6 rounded-lg font-weight-bold"
                                                        :loading="savingSecurity"
                                                        :disabled="!securityValid"
                                                        @click="changePassword"
                                                    >
                                                        <v-icon left>mdi-lock-reset</v-icon> Actualizar Contraseña
                                                    </v-btn>
                                                </div>
                                            </v-form>
                                        </v-card-text>
                                    </v-tab-item>

                                </v-tabs-items>
                            </v-card>
                        </v-col>
                    </v-row>

                    <!-- Snackbar for notifications -->
                    <v-snackbar v-model="snackbar.show" :color="snackbar.color" top right timeout="4000">
                        {{ snackbar.text }}
                        <template v-slot:action="{ attrs }">
                            <v-btn text v-bind="attrs" @click="snackbar.show = false">Cerrar</v-btn>
                        </template>
                    </v-snackbar>

                </v-container>
            </v-main>
        </v-app>
    `,
    data() {
        return {
            loading: false,
            activeTab: 0,
            userId: 0,
            userFullname: '',
            logoUrl: '',
            userPictureUrl: '', // Add this
            dashboardUrl: '',

            // Profile Data
            profileValid: false,
            savingProfile: false,
            profile: {
                firstname: '',
                lastname: '',
                email: '',
                phone1: '',
                description: ''
            },

            // Security Data
            securityValid: false,
            savingSecurity: false,
            showPass1: false,
            showPass2: false,
            showPass3: false,
            security: {
                currentPassword: '',
                newPassword: '',
                confirmPassword: ''
            },

            // UI State
            snackbar: {
                show: false,
                text: '',
                color: 'success'
            },
            lang: {},

            // Validation Rules
            rules: {
                required: v => !!v || 'Este campo es obligatorio',
                email: v => /.+@.+\..+/.test(v) || 'El correo debe ser válido',
                min8: v => v && v.length >= 8 || 'Mínimo 8 caracteres',
                passwordMatch: v => v === this.security.newPassword || 'Las contraseñas no coinciden'
            }
        };
    },
    computed: {
        userInitials() {
            if (!this.profile.firstname && !this.profile.lastname) return 'U';
            return ((this.profile.firstname[0] || '') + (this.profile.lastname[0] || '')).toUpperCase();
        }
    },
    methods: {
        initialize(config) {
            this.userId = config.userId;
            this.userFullname = config.userFullname;
            this.logoUrl = config.logoUrl;
            this.userPictureUrl = config.userPictureUrl || ''; // Initialize from config
            this.dashboardUrl = config.dashboardUrl;
            this.lang = config.strings || {};

            // Pre-fill profile
            this.profile.firstname = config.userFirstname || '';
            this.profile.lastname = config.userLastname || '';
            this.profile.email = config.userEmail || '';
            this.profile.phone1 = config.userPhone || '';
            this.profile.description = config.userDescription || '';
        },

        goBack() {
            window.location.href = this.dashboardUrl;
        },

        showNotification(text, color = 'success') {
            this.snackbar.text = text;
            this.snackbar.color = color;
            this.snackbar.show = true;
        },

        async callMoodleAPI(functionName, args = {}) {
            const params = new URLSearchParams();
            params.append('wstoken', this.getCookie('MoodleSession') || window.MoodleSession); // Fallback usually handled by wrapper but plain ajax uses session
            // Actually, for internal ajax we usually use standard moodle_url wrapper in lib or check sesskey.
            // But here we are using the external service via AJAX presumably, similar to Dashboard. 
            // In Dashboard we used direct axios calls to service.php or similar? 
            // Let's check how Dashboard does it. 
            // Dashboard typically relies on Moodle's "service.php" or "ajax.php".
            // Let's use the local_grupomakro_core/service.php pattern if it exists, or standard lib ajax.
            // Wait, Moodle standard AJAX usually requires a session key.

            // Re-checking TeacherDashboard pattern:
            // It uses `M.util.get_string` etc. but for data it calls `M.cfg.wwwroot + '/local/grupomakro_core/ajax.php'`.
            // So we will stick to that pattern.

            const endpoint = `${window.location.origin}/local/grupomakro_core/ajax.php`;

            // Add wsfunction and standard params
            const payload = {
                wsfunction: functionName,
                moodlewsrestformat: 'json',
                ...args
            };

            // We need to pass args as query params or body? ajax.php usually expects GET or POST params.
            // Let's assume standard axios POST.

            try {
                const response = await axios.get(endpoint, { params: payload });
                if (response.data.exception) {
                    throw new Error(response.data.message);
                }
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async updateProfile() {
            if (!this.$refs.profileForm.validate()) return;

            this.savingProfile = true;
            try {
                const response = await this.callMoodleAPI('local_grupomakro_update_teacher_profile', {
                    userid: this.userId,
                    firstname: this.profile.firstname,
                    lastname: this.profile.lastname,
                    email: this.profile.email,
                    phone1: this.profile.phone1,
                    description: this.profile.description
                });

                if (response.status) {
                    this.showNotification('Perfil actualizado correctamente');
                    // Update full name display
                    this.userFullname = `${this.profile.firstname} ${this.profile.lastname}`;
                } else {
                    this.showNotification(response.message || 'Error al actualizar', 'error');
                }
            } catch (error) {
                console.error(error);
                this.showNotification(error.message || 'Error de conexión', 'error');
            } finally {
                this.savingProfile = false;
            }
        },

        async changePassword() {
            if (!this.$refs.securityForm.validate()) return;

            this.savingSecurity = true;
            try {
                const response = await this.callMoodleAPI('local_grupomakro_change_teacher_password', {
                    userid: this.userId,
                    currentpassword: this.security.currentPassword,
                    newpassword: this.security.newPassword
                });

                if (response.status) {
                    this.showNotification('Contraseña actualizada. Por favor inicia sesión de nuevo.', 'success');
                    // Optional: Logout user or clear form
                    this.$refs.securityForm.reset();
                    setTimeout(() => {
                        // window.location.reload(); // Or let them match
                    }, 2000);
                } else {
                    this.showNotification(response.message || 'No se pudo cambiar la contraseña', 'error');
                }
            } catch (error) {
                console.error(error);
                if (error.message.includes('invalidcurrentpassword')) {
                    this.showNotification('La contraseña actual es incorrecta', 'error');
                } else {
                    this.showNotification(error.message || 'Error al cambiar contraseña. Verifica que cumpla los requisitos.', 'error');
                }
            } finally {
                this.savingSecurity = false;
            }
        }
    }
};

// Global Init Function
window.TeacherProfileApp = {
    init: function (config) {
        new Vue({
            el: '#teacher-profile-app',
            vuetify: new Vuetify(),
            render: h => h(TeacherProfile),
            mounted() {
                // Access the component instance to initialize data
                this.$children[0].initialize(config);
            }
        });
    }
};
