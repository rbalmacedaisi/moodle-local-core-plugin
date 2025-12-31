define(['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {
    return {
        init: function (config) {
            console.log('Teacher Experience Initialized', config);

            // Register global variables for components
            window.wsUrl = config.wwwroot + '/local/grupomakro_core/ajax.php';
            window.wsStaticParams = { sesskey: config.userToken };
            window.userId = config.userId;

            // Register Vue Components (Loaded via teacher_dashboard.php)
            if (window.TeacherDashboard) Vue.component('teacher-dashboard', window.TeacherDashboard);
            if (window.ManageClass) Vue.component('manage-class', window.ManageClass);
            if (window.ActivityCreationWizard) Vue.component('activity-wizard', window.ActivityCreationWizard);

            // Create Vue Application
            const app = new Vue({
                el: '#teacher-app',
                vuetify: new Vuetify(),
                template: `
                    <v-app>
                        <v-main>
                            <teacher-dashboard 
                                v-if="currentPage === 'dashboard'"
                                @change-page="navigate"
                            ></teacher-dashboard>
                            
                            <manage-class 
                                v-if="currentPage === 'manage-class'"
                                :class-id="selectedClassId"
                                @back="currentPage = 'dashboard'"
                            ></manage-class>
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
});
