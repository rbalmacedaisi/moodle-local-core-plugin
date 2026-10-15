/**
 * TeacherMessages.js
 * Bandeja de conversaciones del docente con sus estudiantes (mensajeria
 * estudiante-docente, 20261001107). Un hilo por (clase, estudiante); el
 * servidor decide el acceso (local\messaging\manager).
 *
 * Usa el dispatcher generico de ajax.php: POST {action, args, sesskey} con
 * args como OBJETO (no JSON string) para que call_external_function reciba
 * los parametros tipados.
 */

Vue.component('teacher-messages', {
    props: {
        classId: { type: [Number, String], default: null },
        threadId: { type: [Number, String], default: null },
        config: { type: Object, default: () => ({}) }
    },
    template: `
        <v-container fluid class="pa-2 pa-md-4 gmk-teacher-messages">
            <v-card class="d-flex" style="height: calc(100vh - 140px); min-height: 480px; overflow: hidden;">

                <!-- Inbox -->
                <div v-show="!isMobile || !activeThread"
                     class="d-flex flex-column"
                     :style="{ width: isMobile ? '100%' : '360px', borderRight: isMobile ? 'none' : '1px solid rgba(0,0,0,0.12)' }">
                    <div class="pa-3">
                        <div class="d-flex align-center mb-2">
                            <v-icon color="primary" class="mr-2">mdi-message-text-outline</v-icon>
                            <span class="text-h6">Mensajes</span>
                            <v-spacer></v-spacer>
                            <v-btn small color="primary" depressed @click="openNewDialog">
                                <v-icon left small>mdi-pencil-plus</v-icon> Nuevo
                            </v-btn>
                        </div>
                        <v-select v-model="filterClassId" :items="classOptions" item-text="label" item-value="value"
                                  dense outlined hide-details label="Clase" class="mb-2" @change="loadInbox"></v-select>
                        <v-text-field v-model="search" dense outlined hide-details clearable
                                      prepend-inner-icon="mdi-magnify" placeholder="Buscar estudiante o clase"
                                      @input="debouncedInbox"></v-text-field>
                        <v-checkbox v-model="unreadOnly" dense hide-details label="Solo no leídos" class="mt-1"
                                    @change="loadInbox"></v-checkbox>
                    </div>
                    <v-divider></v-divider>
                    <div class="flex-grow-1" style="overflow-y: auto;">
                        <v-progress-linear v-if="loadingInbox" indeterminate color="primary"></v-progress-linear>
                        <div v-if="!loadingInbox && threads.length === 0" class="pa-6 text-center grey--text">
                            <v-icon large color="grey lighten-1">mdi-inbox-outline</v-icon>
                            <div class="mt-2">No hay conversaciones.</div>
                        </div>
                        <v-list two-line class="py-0">
                            <v-list-item v-for="t in threads" :key="t.id" @click="openThread(t.id)"
                                         :input-value="activeThread && activeThread.id === t.id">
                                <v-list-item-avatar color="blue lighten-4">
                                    <img v-if="counterpartOf(t).imageurl" :src="counterpartOf(t).imageurl">
                                    <span v-else class="blue--text text--darken-3">{{ initials(counterpartOf(t).fullname) }}</span>
                                </v-list-item-avatar>
                                <v-list-item-content>
                                    <v-list-item-title :class="{ 'font-weight-bold': t.unread > 0 }">
                                        {{ counterpartOf(t).fullname || 'Estudiante' }}
                                    </v-list-item-title>
                                    <v-list-item-subtitle class="caption">{{ t.classname }}</v-list-item-subtitle>
                                    <v-list-item-subtitle :class="{ 'font-weight-medium': t.unread > 0 }">
                                        <span v-if="t.lastsenderid === myId">Tú: </span>{{ t.lastmessage }}
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action class="align-self-start mt-3">
                                    <v-list-item-action-text>{{ shortDate(t.lastmessageat) }}</v-list-item-action-text>
                                    <v-chip v-if="t.unread > 0" x-small color="primary" class="mt-1">{{ t.unread }}</v-chip>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </div>
                </div>

                <!-- Conversation -->
                <div v-show="!isMobile || activeThread" class="d-flex flex-column flex-grow-1" style="min-width: 0;">
                    <template v-if="activeThread">
                        <div class="d-flex align-center pa-3">
                            <v-btn v-if="isMobile" icon class="mr-1" @click="closeThread"><v-icon>mdi-arrow-left</v-icon></v-btn>
                            <v-avatar size="36" color="blue lighten-4" class="mr-3">
                                <img v-if="counterpartOf(activeThread).imageurl" :src="counterpartOf(activeThread).imageurl">
                                <span v-else class="blue--text text--darken-3">{{ initials(counterpartOf(activeThread).fullname) }}</span>
                            </v-avatar>
                            <div style="min-width: 0;">
                                <div class="font-weight-medium text-truncate">{{ counterpartOf(activeThread).fullname }}</div>
                                <div class="caption grey--text text-truncate">{{ activeThread.classname }}</div>
                            </div>
                            <v-spacer></v-spacer>
                            <v-btn icon small title="Actualizar" @click="refreshThread(true)"><v-icon small>mdi-refresh</v-icon></v-btn>
                        </div>
                        <v-divider></v-divider>
                        <div ref="scroller" class="flex-grow-1 pa-3" style="overflow-y: auto;"
                             :style="{ background: $vuetify.theme.dark ? '#1a1a1a' : '#fafafa' }">
                            <div v-if="hasMore" class="text-center mb-2">
                                <v-btn small text color="primary" :loading="loadingOlder" @click="loadOlder">Ver mensajes anteriores</v-btn>
                            </div>
                            <div v-for="m in messages" :key="m.id" class="d-flex mb-2" :class="m.mine ? 'justify-end' : 'justify-start'">
                                <div class="px-3 py-2 rounded-lg" style="max-width: 75%;"
                                     :class="m.mine ? 'primary white--text' : ($vuetify.theme.dark ? 'grey darken-3' : 'white elevation-1')">
                                    <div v-if="!m.mine" class="caption font-weight-medium mb-1">{{ m.sendername }}</div>
                                    <div style="white-space: pre-wrap; word-break: break-word;">{{ m.body }}</div>
                                    <div class="text-right mt-1" style="font-size: 11px; opacity: 0.75;">{{ fullDate(m.timecreated) }}</div>
                                </div>
                            </div>
                            <div v-if="!loadingThread && messages.length === 0" class="text-center grey--text mt-6">
                                Aún no hay mensajes. Escribe el primero.
                            </div>
                        </div>
                        <v-divider></v-divider>
                        <div class="pa-3">
                            <v-alert v-if="activeThread.readonly" dense text type="info" class="mb-0">
                                Conversación de solo lectura: la clase ya cerró.
                            </v-alert>
                            <template v-else>
                                <v-alert v-if="sendError" dense text type="error" class="mb-2" dismissible @input="sendError = ''">{{ sendError }}</v-alert>
                                <div class="d-flex align-end">
                                    <v-textarea v-model="draft" outlined dense hide-details auto-grow rows="1" row-height="20"
                                                :counter="maxLength" :maxlength="maxLength"
                                                placeholder="Escribe un mensaje (Enter envía, Shift+Enter salto de línea)"
                                                @keydown.enter.exact.prevent="send"></v-textarea>
                                    <v-btn color="primary" class="ml-2" fab small depressed :loading="sending"
                                           :disabled="!draft || !draft.trim()" @click="send">
                                        <v-icon>mdi-send</v-icon>
                                    </v-btn>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div v-else class="d-flex flex-column align-center justify-center flex-grow-1 grey--text">
                        <v-icon size="64" color="grey lighten-1">mdi-forum-outline</v-icon>
                        <div class="mt-2">Selecciona una conversación o inicia una nueva.</div>
                    </div>
                </div>
            </v-card>

            <!-- New message dialog -->
            <v-dialog v-model="newDialog" max-width="520" scrollable>
                <v-card>
                    <v-card-title>Nuevo mensaje</v-card-title>
                    <v-card-text style="max-height: 60vh;">
                        <v-select v-model="newClassId" :items="contactClasses" item-text="classname" item-value="classid"
                                  label="Clase" outlined dense @change="loadStudents"></v-select>
                        <v-text-field v-if="newClassId" v-model="studentSearch" dense outlined hide-details clearable
                                      prepend-inner-icon="mdi-magnify" placeholder="Buscar estudiante" class="mb-2"></v-text-field>
                        <v-progress-linear v-if="loadingStudents" indeterminate></v-progress-linear>
                        <v-list dense v-if="newClassId">
                            <v-list-item v-for="s in filteredStudents" :key="s.id" @click="startWith(s)">
                                <v-list-item-avatar size="28" color="blue lighten-4">
                                    <img v-if="s.imageurl" :src="s.imageurl">
                                    <span v-else class="caption">{{ initials(s.fullname) }}</span>
                                </v-list-item-avatar>
                                <v-list-item-content><v-list-item-title>{{ s.fullname }}</v-list-item-title></v-list-item-content>
                                <v-list-item-action v-if="s.unread > 0"><v-chip x-small color="primary">{{ s.unread }}</v-chip></v-list-item-action>
                            </v-list-item>
                            <div v-if="!loadingStudents && filteredStudents.length === 0" class="pa-3 grey--text text-center">Sin estudiantes.</div>
                        </v-list>
                    </v-card-text>
                    <v-card-actions><v-spacer></v-spacer><v-btn text @click="newDialog = false">Cerrar</v-btn></v-card-actions>
                </v-card>
            </v-dialog>

            <v-snackbar v-model="snack.show" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
        </v-container>
    `,
    data() {
        return {
            myId: parseInt(window.userId, 10) || 0,
            maxLength: 4000,
            threads: [],
            filterClassId: this.classId ? parseInt(this.classId, 10) : 0,
            search: '',
            unreadOnly: false,
            loadingInbox: false,
            contactClasses: [],
            activeThread: null,
            messages: [],
            hasMore: false,
            loadingThread: false,
            loadingOlder: false,
            draft: '',
            sending: false,
            sendError: '',
            newDialog: false,
            newClassId: null,
            students: [],
            studentSearch: '',
            loadingStudents: false,
            snack: { show: false, text: '', color: 'error' },
            timers: [],
            searchTimer: null
        };
    },
    computed: {
        isMobile() {
            return this.$vuetify.breakpoint.smAndDown;
        },
        classOptions() {
            return [{ label: 'Todas mis clases', value: 0 }].concat(
                this.contactClasses.map(c => ({ label: c.classname, value: c.classid })));
        },
        filteredStudents() {
            const q = (this.studentSearch || '').toLowerCase().trim();
            return q ? this.students.filter(s => s.fullname.toLowerCase().includes(q)) : this.students;
        }
    },
    async created() {
        await Promise.all([this.loadInbox(), this.loadContacts()]);
        const initial = parseInt(this.threadId, 10);
        if (initial > 0) {
            this.openThread(initial);
        }
        this.timers.push(setInterval(() => { if (!document.hidden) this.loadInbox(true); }, 60000));
        this.timers.push(setInterval(() => { if (!document.hidden && this.activeThread) this.refreshThread(false); }, 15000));
    },
    beforeDestroy() {
        this.timers.forEach(clearInterval);
        clearTimeout(this.searchTimer);
    },
    methods: {
        async call(action, args) {
            const url = (window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php')) + '?action=' + action;
            const response = await axios.post(url, { action: action, args: args || {}, ...(window.wsStaticParams || {}) });
            const res = response.data || {};
            if (res.status !== 'success') {
                throw new Error(res.message || 'Error de servidor');
            }
            return res.data;
        },
        notifyError(e) {
            this.snack = { show: true, text: (e && e.message) || 'Error', color: 'error' };
        },
        async loadInbox(silent) {
            if (!silent) this.loadingInbox = true;
            try {
                const data = await this.call('local_grupomakro_msg_get_inbox', {
                    classid: this.filterClassId || 0,
                    unreadonly: !!this.unreadOnly,
                    search: this.search || '',
                    page: 0,
                    perpage: 100
                });
                this.threads = data.threads || [];
                this.$emit('unread-changed', data.unreadtotal || 0);
            } catch (e) {
                if (!silent) this.notifyError(e);
            } finally {
                this.loadingInbox = false;
            }
        },
        debouncedInbox() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.loadInbox(), 400);
        },
        async loadContacts() {
            try {
                const data = await this.call('local_grupomakro_msg_get_contacts', { classid: 0 });
                this.contactClasses = data.classes || [];
            } catch (e) {
                this.notifyError(e);
            }
        },
        async openThread(threadid) {
            this.loadingThread = true;
            this.sendError = '';
            this.draft = '';
            try {
                const data = await this.call('local_grupomakro_msg_get_messages', { threadid: threadid, beforeid: 0, limit: 30 });
                this.activeThread = data.thread;
                this.messages = data.messages || [];
                this.hasMore = !!data.hasmore;
                this.$emit('thread-changed', threadid);
                this.scrollToBottom();
                await this.markRead();
            } catch (e) {
                this.notifyError(e);
            } finally {
                this.loadingThread = false;
            }
        },
        closeThread() {
            this.activeThread = null;
            this.messages = [];
            this.$emit('thread-changed', null);
        },
        async refreshThread(manual) {
            if (!this.activeThread) return;
            const lastid = this.messages.length ? this.messages[this.messages.length - 1].id : 0;
            try {
                const data = await this.call('local_grupomakro_msg_get_messages', { threadid: this.activeThread.id, beforeid: 0, limit: 30 });
                this.activeThread = data.thread;
                const fresh = (data.messages || []).filter(m => m.id > lastid);
                if (fresh.length) {
                    this.messages = this.messages.concat(fresh);
                    this.scrollToBottom();
                    await this.markRead();
                }
            } catch (e) {
                if (manual) this.notifyError(e);
            }
        },
        async loadOlder() {
            if (!this.activeThread || !this.messages.length) return;
            this.loadingOlder = true;
            try {
                const data = await this.call('local_grupomakro_msg_get_messages', {
                    threadid: this.activeThread.id, beforeid: this.messages[0].id, limit: 30
                });
                this.messages = (data.messages || []).concat(this.messages);
                this.hasMore = !!data.hasmore;
            } catch (e) {
                this.notifyError(e);
            } finally {
                this.loadingOlder = false;
            }
        },
        async markRead() {
            if (!this.activeThread || !this.activeThread.unread) return;
            try {
                const data = await this.call('local_grupomakro_msg_mark_read', { threadid: this.activeThread.id, uptomessageid: 0 });
                this.activeThread.unread = 0;
                const row = this.threads.find(t => t.id === this.activeThread.id);
                if (row) row.unread = 0;
                this.$emit('unread-changed', data.unreadtotal || 0);
            } catch (e) {
                // Non critical.
            }
        },
        async send() {
            const body = (this.draft || '').trim();
            if (!body || this.sending || !this.activeThread) return;
            this.sending = true;
            this.sendError = '';
            try {
                const data = await this.call('local_grupomakro_msg_send', { threadid: this.activeThread.id, body: body });
                this.messages.push(data.message);
                this.activeThread = data.thread;
                this.draft = '';
                this.scrollToBottom();
                this.loadInbox(true);
            } catch (e) {
                this.sendError = e.message;
            } finally {
                this.sending = false;
            }
        },
        openNewDialog() {
            this.newDialog = true;
            this.studentSearch = '';
            if (!this.newClassId && this.filterClassId) {
                this.newClassId = this.filterClassId;
            }
            if (this.newClassId) this.loadStudents();
        },
        async loadStudents() {
            if (!this.newClassId) return;
            this.loadingStudents = true;
            this.students = [];
            try {
                const data = await this.call('local_grupomakro_msg_get_contacts', { classid: this.newClassId });
                const cls = (data.classes || []).find(c => c.classid === this.newClassId);
                this.students = cls ? cls.students : [];
            } catch (e) {
                this.notifyError(e);
            } finally {
                this.loadingStudents = false;
            }
        },
        async startWith(student) {
            try {
                let threadid = student.threadid;
                if (!threadid) {
                    const data = await this.call('local_grupomakro_msg_open_thread', { classid: this.newClassId, studentid: student.id });
                    threadid = data.thread.id;
                }
                this.newDialog = false;
                await this.openThread(threadid);
            } catch (e) {
                this.notifyError(e);
            }
        },
        counterpartOf(thread) {
            return (thread && thread.counterpart && thread.counterpart[0]) || { fullname: '', imageurl: '' };
        },
        initials(name) {
            const parts = (name || '').trim().split(/\s+/).filter(Boolean);
            return ((parts[0] || '').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase() || '?';
        },
        shortDate(ts) {
            if (!ts) return '';
            const d = new Date(ts * 1000);
            const now = new Date();
            if (d.toDateString() === now.toDateString()) {
                return d.toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString('es-PA', { day: '2-digit', month: 'short' });
        },
        fullDate(ts) {
            if (!ts) return '';
            return new Date(ts * 1000).toLocaleString('es-PA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.scroller;
                if (el) el.scrollTop = el.scrollHeight;
            });
        }
    }
});
