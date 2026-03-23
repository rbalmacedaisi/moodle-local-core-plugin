/**
 * Teacher Experience Initialization Module
 * Converted to standard JS for better compatibility without AMD minification.
 */
window.TeacherExperience = {
    init: function (config) {
        console.log('Teacher Experience Initialized', config);

        const STORAGE_KEY = 'gmk_teacher_experience_state';
        const QUERY_KEYS = {
            page: 'gmk_page',
            classId: 'gmk_classid',
            cmid: 'gmk_cmid',
            tab: 'gmk_tab'
        };
        const ALLOWED_PAGES = ['dashboard', 'manage-class', 'grading', 'quiz-editor'];

        function normalizePositiveInt(value) {
            const parsed = parseInt(value, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        }

        function sanitizePage(value) {
            return ALLOWED_PAGES.includes(value) ? value : 'dashboard';
        }

        function sanitizeState(rawState) {
            const raw = rawState || {};
            const state = {
                page: sanitizePage(raw.page),
                classId: normalizePositiveInt(raw.classId),
                cmid: normalizePositiveInt(raw.cmid),
                tab: typeof raw.tab === 'string' ? raw.tab : ''
            };

            if (state.page === 'manage-class' && !state.classId) {
                state.page = 'dashboard';
            }

            if (state.page === 'quiz-editor') {
                if (!state.classId) {
                    state.page = 'dashboard';
                } else if (!state.cmid) {
                    state.page = 'manage-class';
                }
            }

            if (state.page !== 'manage-class') {
                state.tab = '';
            }

            return state;
        }

        function readStateFromUrl() {
            const params = new URLSearchParams(window.location.search);
            return sanitizeState({
                page: params.get(QUERY_KEYS.page) || 'dashboard',
                classId: params.get(QUERY_KEYS.classId),
                cmid: params.get(QUERY_KEYS.cmid),
                tab: params.get(QUERY_KEYS.tab) || ''
            });
        }

        function readStateFromStorage() {
            try {
                const raw = window.sessionStorage.getItem(STORAGE_KEY);
                if (!raw) {
                    return sanitizeState({ page: 'dashboard' });
                }
                return sanitizeState(JSON.parse(raw));
            } catch (error) {
                return sanitizeState({ page: 'dashboard' });
            }
        }

        function getInitialState() {
            const urlState = readStateFromUrl();
            if (urlState.page !== 'dashboard' || urlState.classId || urlState.cmid || urlState.tab) {
                return urlState;
            }
            return readStateFromStorage();
        }

        function persistStateToStorage(state) {
            try {
                window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            } catch (error) {
                // Ignore session storage errors silently.
            }
        }

        function persistStateToUrl(state) {
            const url = new URL(window.location.href);
            const params = url.searchParams;

            [QUERY_KEYS.page, QUERY_KEYS.classId, QUERY_KEYS.cmid, QUERY_KEYS.tab].forEach(key => {
                params.delete(key);
            });

            if (state.page && state.page !== 'dashboard') {
                params.set(QUERY_KEYS.page, state.page);
            }
            if (state.classId) {
                params.set(QUERY_KEYS.classId, String(state.classId));
            }
            if (state.cmid) {
                params.set(QUERY_KEYS.cmid, String(state.cmid));
            }
            if (state.tab) {
                params.set(QUERY_KEYS.tab, state.tab);
            }

            const nextUrl = url.pathname + (params.toString() ? '?' + params.toString() : '') + url.hash;
            window.history.replaceState({}, '', nextUrl);
        }

        const initialState = getInitialState();

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

                            <v-btn text small color="primary" class="mr-2 d-none d-md-flex" @click="setPage('dashboard')">
                                <v-icon left>mdi-view-dashboard</v-icon> Mi Inicio
                            </v-btn>
                            <v-btn text small color="orange" class="mr-2 d-none d-md-flex" @click="setPage('grading')">
                                <v-icon left>mdi-clipboard-check-outline</v-icon> Calificar
                            </v-btn>

                            <v-menu offset-y transition="slide-y-transition" content-class="gmk-user-menu-dropdown">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn text v-bind="attrs" v-on="on" class="text-none">
                                        <v-avatar size="32" color="blue darken-1" class="mr-2">
                                            <v-icon dark small>mdi-account</v-icon>
                                        </v-avatar>
                                        <span class="d-none d-sm-inline">{{ config.firstName || 'Mi Cuenta' }}</span>
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
                                :initial-tab="manageClassTab"
                                @back="setPage('dashboard')"
                                @change-page="navigate"
                                @state-change="handleManageClassStateChange"
                            ></manage-class>

                            <pending-grading-view
                                v-if="currentPage === 'grading'"
                                :config="config"
                            ></pending-grading-view>

                            <quiz-editor
                                v-if="currentPage === 'quiz-editor'"
                                :config="config"
                                :cmid="selectedCmid"
                                @back="navigate({ page: 'manage-class', id: selectedClassId, tab: manageClassTab || 'content' })"
                            ></quiz-editor>
                        </v-fade-transition>
                    </v-main>
                </v-app>
            `,
            data() {
                return {
                    currentPage: initialState.page || 'dashboard',
                    selectedClassId: initialState.classId || null,
                    selectedCmid: initialState.cmid || null,
                    manageClassTab: initialState.tab || '',
                    config: config
                };
            },
            created() {
                if (this.currentPage === 'manage-class' && !this.manageClassTab) {
                    this.manageClassTab = 'timeline';
                }
                this.persistNavigationState();
            },
            methods: {
                buildNavigationState() {
                    const page = sanitizePage(this.currentPage);
                    return sanitizeState({
                        page: page,
                        classId: (page === 'manage-class' || page === 'quiz-editor') ? this.selectedClassId : null,
                        cmid: page === 'quiz-editor' ? this.selectedCmid : null,
                        tab: page === 'manage-class' ? this.manageClassTab : ''
                    });
                },
                persistNavigationState() {
                    const state = this.buildNavigationState();
                    persistStateToStorage(state);
                    persistStateToUrl(state);
                },
                setPage(page) {
                    this.navigate({ page: page });
                },
                handleManageClassStateChange(payload) {
                    if (!payload || typeof payload.tab !== 'string' || !payload.tab) {
                        return;
                    }
                    if (this.manageClassTab === payload.tab) {
                        return;
                    }
                    this.manageClassTab = payload.tab;
                    this.persistNavigationState();
                },
                navigate(payload) {
                    const nextPayload = payload || {};
                    const nextPage = sanitizePage(nextPayload.page || 'dashboard');
                    const hasId = Object.prototype.hasOwnProperty.call(nextPayload, 'id');
                    const hasCmid = Object.prototype.hasOwnProperty.call(nextPayload, 'cmid');
                    const hasTab = Object.prototype.hasOwnProperty.call(nextPayload, 'tab');

                    this.currentPage = nextPage;

                    if (hasId) {
                        this.selectedClassId = normalizePositiveInt(nextPayload.id);
                    } else if (nextPage !== 'manage-class' && nextPage !== 'quiz-editor') {
                        this.selectedClassId = null;
                    }

                    if (hasCmid) {
                        this.selectedCmid = normalizePositiveInt(nextPayload.cmid);
                    } else if (nextPage !== 'quiz-editor') {
                        this.selectedCmid = null;
                    }

                    if (hasTab) {
                        this.manageClassTab = typeof nextPayload.tab === 'string' ? nextPayload.tab : '';
                    } else if (nextPage !== 'manage-class') {
                        this.manageClassTab = '';
                    }

                    if (nextPage === 'manage-class' && !this.manageClassTab) {
                        this.manageClassTab = 'timeline';
                    }

                    this.persistNavigationState();
                }
            }
        });
    }
};
