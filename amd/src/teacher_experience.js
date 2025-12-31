/**
 * Teacher Experience Initialization Module
 * Converted to standard JS for better compatibility without AMD minification.
 */
window.TeacherExperience = {
    init: function (config) {
        console.log('Teacher Experience Initialized', config);

        // Register global variables for components
        window.wsUrl = config.wwwroot + '/local/grupomakro_core/ajax.php';
        window.wsStaticParams = { sesskey: config.userToken };
        window.userId = config.userId;

        // Register Vue Components
        if (window.TeacherDashboard) Vue.component('teacher-dashboard', window.TeacherDashboard);
        if (window.ManageClass) Vue.component('manage-class', window.ManageClass);
        if (window.ActivityCreationWizard) Vue.component('activity-wizard', window.ActivityCreationWizard);

        // Create Vue Application
        const mountPoint = document.getElementById('teacher-app');
        if (!mountPoint) {
            console.error('Mount point #teacher-app not found');
            return;
        }

        const app = new Vue({
            el: '#teacher-app',
            vuetify: new Vuetify(),
            template: `
                <v-app>
                    <v-main>
                        <v-fade-transition mode="out-in">
                            <teacher-dashboard 
                                v-if="currentPage === 'dashboard'"
                                @change-page="navigate"
                            ></teacher-dashboard>
                            
                            <manage-class 
                                v-if="currentPage === 'manage-class'"
                                :class-id="selectedClassId"
                                @back="currentPage = 'dashboard'"
                            ></manage-class>
                        </v-fade-transition>
                    </v-main>
                </v-app>
            `,
            data() {
                return {
                    currentPage: 'dashboard',
                    selectedClassId: null,
                    config: config
                };
            },
            methods: {
                navigate(payload) {
                    this.currentPage = payload.page;
                    this.selectedClassId = payload.id;
                }
            }
        });
    }
};
