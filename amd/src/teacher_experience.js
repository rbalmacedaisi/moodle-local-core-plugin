define(['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {
    return {
        init: function (config) {
            console.log('Teacher Experience Initialized', config);

            // Create Vue Application
            const app = new Vue({
                el: '#teacher-app',
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
