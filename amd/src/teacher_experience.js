/**
 * Teacher Experience Initialization Module
 * Converted to standard JS for better compatibility without AMD minification.
 */
window.TeacherExperience = {
    init: function (config) {
        console.log('Teacher Experience Initialized', config);

        // Globalize basic config for components
        window.strings = config.strings || {};
        window.userToken = config.userToken;
        window.siteUrl = config.wwwroot + '/webservice/rest/server.php';
        window.wwwroot = config.wwwroot;
        window.wsUrl = config.wwwroot + '/local/grupomakro_core/ajax.php';
        window.wsStaticParams = { sesskey: config.userToken };
        window.userId = config.userId;

        // Register Vue Components
        if (window.TeacherDashboard) Vue.component('teacher-dashboard', window.TeacherDashboard);
        if (window.ManageClass) Vue.component('manage-class', window.ManageClass);
        if (window.ActivityCreationWizard) Vue.component('activity-wizard', window.ActivityCreationWizard);
        if (window.studenttable) Vue.component('studenttable', window.studenttable);

        if (window.PendingGradingView) Vue.component('pending-grading-view', window.PendingGradingView);
        if (window.QuickGrader) Vue.component('quick-grader', window.QuickGrader);
        if (window.QuizEditor) Vue.component('quiz-editor', window.QuizEditor);

        // Create Vue Application
        const mountPoint = document.getElementById('teacher-app');
        if (!mountPoint) {
            console.error('Mount point #teacher-app not found');
            return;
        }

        // Check for Moodle Dark Mode
        // Checks for 'dark-mode' class on body or 'data-bs-theme=dark' (Moodle 4.x)
        const isDarkMode = document.body.classList.contains('dark-mode') ||
            document.documentElement.getAttribute('data-bs-theme') === 'dark' ||
            window.matchMedia('(prefers-color-scheme: dark)').matches; // Optional fallback

        console.log('Teacher Experience: Dark Mode detected?', isDarkMode);

        const app = new Vue({
            el: '#teacher-app',
            vuetify: new Vuetify({
                theme: { dark: isDarkMode }
            }),
            template: `
                <v-app :style="{ background: $vuetify.theme.dark ? '#121212' : '#f5f5f5' }">
                    <!-- Enhanced Top Header -->
                    <v-app-bar app :color="$vuetify.theme.dark ? '#1E1E1E' : 'white'" :elevation="1" :dark="$vuetify.theme.dark" :light="!$vuetify.theme.dark">
                        <v-container class="pa-0 d-flex align-center">
                            <v-img 
                                v-if="config.logoUrl" 
                                :src="config.logoUrl" 
                                max-height="40" 
                                max-width="120" 
                                contain 
                                class="mr-4"
                            ></v-img>
                            <v-toolbar-title class="font-weight-bold d-none d-sm-block" :class="$vuetify.theme.dark ? 'grey--text text--lighten-1' : 'grey--text text--darken-2'">
                                ISI - Portal Docente
                            </v-toolbar-title>
                            
                            <v-spacer></v-spacer>

                            <v-btn text small color="primary" class="mr-2 d-none d-md-flex" @click="currentPage = 'dashboard'">
                                <v-icon left>mdi-view-dashboard</v-icon> Mi Inicio
                            </v-btn>
                            <v-btn text small color="orange" class="mr-2 d-none d-md-flex" @click="currentPage = 'grading'">
                                <v-icon left>mdi-clipboard-check-outline</v-icon> Calificar
                            </v-btn>

                            <v-menu offset-y transition="slide-y-transition" content-class="gmk-user-menu-dropdown">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn text v-bind="attrs" v-on="on" class="text-none">
                                        <v-avatar size="32" color="blue darken-1" class="mr-2">
                                            <v-icon dark small>mdi-account</v-icon>
                                        </v-avatar>
                                        <span class="d-none d-sm-inline">Mi Cuenta</span>
                                        <v-icon right small>mdi-chevron-down</v-icon>
                                    </v-btn>
                                </template>
                                <v-list dense>
                                    <v-divider></v-divider>
                                    <v-list-item :href="config.wwwroot + '/local/grupomakro_core/pages/teacher_profile.php'">
                                        <v-list-item-icon><v-icon small>mdi-account-cog</v-icon></v-list-item-icon>
                                        <v-list-item-title>Mi Perfil</v-list-item-title>
                                    </v-list-item>
                                    <v-list-item :href="config.logoutUrl">
                                        <v-list-item-icon><v-icon small color="red">mdi-logout</v-icon></v-list-item-icon>
                                        <v-list-item-title class="red--text">Cerrar Sesión</v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </v-container>
                    </v-app-bar>

                    <v-main :class="$vuetify.theme.dark ? '' : 'grey lighten-5'">
                        <v-fade-transition mode="out-in">
                            <teacher-dashboard 
                                v-if="currentPage === 'dashboard'"
                                @change-page="navigate"
                            ></teacher-dashboard>
                            
                            <manage-class 
                                v-if="currentPage === 'manage-class'"
                                :class-id="selectedClassId"
                                :config="config"
                                @back="currentPage = 'dashboard'"
                                @change-page="navigate"
                            ></manage-class>

                            <pending-grading-view
                                v-if="currentPage === 'grading'"
                                :config="config"
                            ></pending-grading-view>

                            <quiz-editor
                                v-if="currentPage === 'quiz-editor'"
                                :config="config"
                                :cmid="selectedCmid"
                                @back="navigate({page: 'manage-class', id: selectedClassId})"
                            ></quiz-editor>
                        </v-fade-transition>
                    </v-main>
                </v-app>
            `,
            data() {
                return {
                    currentPage: 'dashboard',
                    selectedClassId: null,
                    selectedCmid: null,
                    config: config
                };
            },
            methods: {
                navigate(payload) {
                    // console.log('Navigating to:', payload);
                    this.currentPage = payload.page;
                    if (payload.id) this.selectedClassId = payload.id;
                    if (payload.cmid) this.selectedCmid = payload.cmid;
                }
            }
        });
    }
};
