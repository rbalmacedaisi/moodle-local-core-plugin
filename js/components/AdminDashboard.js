/**
 * AdminDashboard.js
 * Dashboard administrativo "Mis pendientes" (20261001129). Mobile first:
 * una columna en el celular, dos desde 768 px y tres desde 1264 px.
 * Datos: WS local_grupomakro_dashboard_get (gmkSupportCall, SupportApi.js).
 * Se refresca solo cada 2 minutos mientras la pestana esta visible.
 */
(function () {
    if (document.getElementById('gmk-dash-css')) {
        return;
    }
    const css = `
#gmk-app .gmk-dash { max-width: 1400px; margin: 0 auto; }
#gmk-app .gmk-dash-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
@media (min-width: 768px) { #gmk-app .gmk-dash-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1264px) { #gmk-app .gmk-dash-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
#gmk-app .gmk-dash-card { display: flex; flex-direction: column; min-width: 0; border-left: 4px solid #9e9e9e; }
#gmk-app .gmk-dash-card--l1 { border-left-color: #ffb300; }
#gmk-app .gmk-dash-card--l2 { border-left-color: #e53935; }
#gmk-app .gmk-dash-card--l3 { border-left-color: #6a1b9a; }
#gmk-app .gmk-dash-card--l0 { border-left-color: #43a047; }
#gmk-app .gmk-dash-num { font-size: 28px; font-weight: 500; line-height: 1; }
#gmk-app .gmk-dash-item { display: flex; gap: 10px; align-items: flex-start; padding: 8px 4px; border-top: 1px solid rgba(0,0,0,0.06);
  text-decoration: none; color: inherit; min-height: 48px; }
#gmk-app .gmk-dash-item:hover { background: rgba(0,0,0,0.03); }
#gmk-app .gmk-dash-dot { flex: 0 0 12px; width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; }
#gmk-app .gmk-dash-text { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
#gmk-app .gmk-dash-title { font-size: 14px; font-weight: 500; }
#gmk-app .gmk-dash-sub { font-size: 12px; color: rgba(0,0,0,0.6); }
#gmk-app .gmk-dash-due { font-size: 12px; font-weight: 500; }
#gmk-app .gmk-dash-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
#gmk-app .gmk-dash-toggle { display: flex; width: 100%; }
#gmk-app .gmk-dash-toggle .v-btn { flex: 1 1 0; min-height: 44px; text-transform: none; letter-spacing: normal; }
@media (min-width: 600px) { #gmk-app .gmk-dash-toggle { width: auto; } }
#gmk-app .gmk-dash-totals { display: flex; flex-wrap: wrap; gap: 6px; }
`;
    const style = document.createElement('style');
    style.id = 'gmk-dash-css';
    style.textContent = css;
    document.head.appendChild(style);
})();

Vue.component('admin-dashboard', {
    props: { config: { type: Object, default: () => ({}) } },
    data() {
        return {
            scope: this.config.scope === 'team' ? 'team' : 'mine',
            data: null,
            loading: false,
            error: '',
            expanded: {},
            timer: null,
            seq: 0,
        };
    },
    computed: {
        activeCards() {
            return this.data ? this.data.cards.filter(c => c.counts.total > 0 || c.error) : [];
        },
        clearCards() {
            return this.data ? this.data.cards.filter(c => c.counts.total === 0 && !c.error) : [];
        },
        overdue() {
            return this.data ? this.data.totals.breached + this.data.totals.critical : 0;
        },
        greeting() {
            const h = new Date().getHours();
            const g = h < 12 ? 'Buenos días' : (h < 19 ? 'Buenas tardes' : 'Buenas noches');
            return g + (this.data && this.data.firstname ? ', ' + this.data.firstname : '');
        },
        updated() {
            return this.data ? new Date(this.data.generated * 1000).toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' }) : '';
        },
    },
    created() {
        this.load(false);
        this.timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.load(false, true);
            }
        }, 120000);
    },
    beforeDestroy() {
        clearInterval(this.timer);
    },
    methods: {
        async load(refresh, quiet) {
            if (!quiet) {
                this.loading = true;
            }
            this.error = '';
            // Only the latest request may paint: an auto-refresh that comes back after a change
            // of view must not overwrite it.
            const seq = ++this.seq;
            try {
                const res = await window.gmkSupportCall('local_grupomakro_dashboard_get', { scope: this.scope, refresh: !!refresh });
                if (seq !== this.seq) {
                    return;
                }
                this.data = JSON.parse(res.json);
                this.scope = this.data.scope;
            } catch (e) {
                if (seq === this.seq) {
                    this.error = e.message || 'No se pudieron cargar los pendientes.';
                }
            } finally {
                if (seq === this.seq) {
                    this.loading = false;
                }
            }
        },
        setScope(s) {
            if (s && s !== this.scope) {
                this.scope = s;
                this.expanded = {};
                this.load(false);
            }
        },
        worst(card) {
            if (card.counts.critical) return 3;
            if (card.counts.breached) return 2;
            if (card.counts.warning) return 1;
            return 0;
        },
        color(level) {
            return ['#43a047', '#ffb300', '#e53935', '#6a1b9a'][level] || '#9e9e9e';
        },
        shown(card) {
            return this.expanded[card.key] ? card.items : card.items.slice(0, 3);
        },
        toggle(card) {
            this.$set(this.expanded, card.key, !this.expanded[card.key]);
        },
        span(seconds) {
            const m = Math.round(Math.abs(seconds) / 60);
            if (m < 60) return m + ' min';
            const h = Math.round(m / 60);
            if (h < 48) return h + ' h';
            return Math.round(h / 24) + ' días';
        },
        dueText(item) {
            if (!item.due) return '';
            const diff = item.due - Math.floor(Date.now() / 1000);
            if (item.level >= 2) {
                return diff < 0 ? 'Vencido hace ' + this.span(diff) : 'Vencido';
            }
            return diff > 0 ? 'Vence en ' + this.span(diff) : 'Vence ahora';
        },
        summary(card) {
            const out = [];
            if (card.counts.critical) out.push({ text: card.counts.critical + ' crítico(s)', color: '#6a1b9a' });
            if (card.counts.breached) out.push({ text: card.counts.breached + ' vencido(s)', color: '#e53935' });
            if (card.counts.warning) out.push({ text: card.counts.warning + ' por vencer', color: '#b28704' });
            if (!out.length && card.counts.total) out.push({ text: 'En plazo', color: '#2e7d32' });
            if (card.external) out.push({ text: 'ANS propio de la bandeja', color: '' });
            return out;
        },
        levelLabel(level) {
            return ['En plazo', 'Por vencer', 'Vencido', 'Crítico'][level] || '';
        },
    },
    template: `
<div class="gmk-dash pa-2 pa-sm-4">
  <v-card outlined class="mb-3 pa-3">
    <div class="gmk-dash-head">
      <div class="gmk-min0" style="flex: 1 1 220px;">
        <div class="text-h6 gmk-wrap">{{ greeting }}</div>
        <div class="gmk-dash-sub" v-if="data">
          <span v-if="data.totals.total === 0">No tienes pendientes. ¡Todo al día!</span>
          <span v-else>Tienes {{ data.totals.total }} pendiente(s){{ scope === 'team' ? ' en tu equipo' : '' }}.</span>
          · Actualizado {{ updated }}
        </div>
      </div>
      <div class="d-flex align-center" style="gap: 4px;">
        <v-btn icon :loading="loading" @click="load(true)" title="Actualizar" aria-label="Actualizar"><v-icon>mdi-refresh</v-icon></v-btn>
        <v-btn v-if="data && data.settingsurl" icon :href="data.settingsurl" title="Configuración" aria-label="Configuración"><v-icon>mdi-cog-outline</v-icon></v-btn>
      </div>
    </div>
    <div class="gmk-dash-totals mt-2" v-if="data && data.totals.total">
      <v-chip small color="purple darken-2" dark v-if="data.totals.critical">{{ data.totals.critical }} crítico(s)</v-chip>
      <v-chip small color="red darken-1" dark v-if="data.totals.breached">{{ data.totals.breached }} vencido(s)</v-chip>
      <v-chip small color="amber darken-1" v-if="data.totals.warning">{{ data.totals.warning }} por vencer</v-chip>
    </div>
    <div class="d-flex flex-wrap align-center mt-3" style="gap: 8px;">
      <v-btn-toggle v-if="data && data.canteam" :value="scope" @change="setScope" mandatory dense class="gmk-dash-toggle">
        <v-btn value="mine"><v-icon left small>mdi-account</v-icon>Lo mío</v-btn>
        <v-btn value="team"><v-icon left small>mdi-account-group</v-icon>Mi equipo</v-btn>
      </v-btn-toggle>
      <v-btn v-if="data && data.teacherurl" text color="primary" :href="data.teacherurl"><v-icon left>mdi-school</v-icon>Mi panel docente</v-btn>
    </div>
  </v-card>

  <v-alert v-if="error" type="error" outlined dense class="gmk-wrap">{{ error }}</v-alert>

  <div v-if="loading && !data" class="text-center pa-8"><v-progress-circular indeterminate color="primary"></v-progress-circular></div>

  <div v-if="data" class="gmk-dash-grid">
    <v-card v-for="card in activeCards" :key="card.key" outlined :class="['gmk-dash-card', 'gmk-dash-card--l' + worst(card)]">
      <div class="d-flex align-center pa-3" style="gap: 10px;">
        <v-avatar size="40" :color="color(worst(card))"><v-icon dark>{{ card.icon }}</v-icon></v-avatar>
        <div class="gmk-min0" style="flex: 1 1 auto;">
          <div class="subtitle-1 font-weight-medium gmk-wrap">{{ card.label }}</div>
          <div class="gmk-dash-sub">
            <template v-for="(part, i) in summary(card)"><span v-if="i"> · </span><span :key="part.text" :style="{ color: part.color }">{{ part.text }}</span></template>
          </div>
        </div>
        <div class="gmk-dash-num">{{ card.counts.total }}<span v-if="card.truncated">+</span></div>
      </div>
      <v-alert v-if="card.error" type="warning" dense text class="mx-3 gmk-wrap">{{ card.error }}</v-alert>
      <div class="px-3">
        <a v-for="item in shown(card)" :key="item.ref" class="gmk-dash-item" :href="item.url">
          <span class="gmk-dash-dot" :style="{ background: color(item.level) }" :title="levelLabel(item.level)"></span>
          <span class="gmk-dash-text">
            <div class="gmk-dash-title">{{ item.title }}</div>
            <div class="gmk-dash-sub">{{ item.subtitle }}</div>
            <div class="gmk-dash-sub" v-if="scope === 'team' && item.ownername"><v-icon x-small>mdi-account</v-icon> {{ item.ownername }}</div>
            <div class="gmk-dash-due" :style="{ color: item.level >= 2 ? color(item.level) : 'rgba(0,0,0,0.6)' }" v-if="dueText(item)">{{ dueText(item) }}</div>
          </span>
        </a>
      </div>
      <v-spacer></v-spacer>
      <div class="d-flex flex-wrap pa-2" style="gap: 4px;">
        <v-btn v-if="card.items.length > 3" text small @click="toggle(card)">{{ expanded[card.key] ? 'Ver menos' : 'Ver más urgentes' }}</v-btn>
        <v-spacer></v-spacer>
        <v-btn color="primary" depressed :href="card.url">Abrir bandeja<v-icon right>mdi-arrow-right</v-icon></v-btn>
      </div>
    </v-card>
  </div>

  <v-card v-if="data && clearCards.length" outlined class="mt-3 pa-3">
    <div class="gmk-dash-sub mb-2"><v-icon small color="green">mdi-check-circle</v-icon> Al día</div>
    <div class="d-flex flex-wrap" style="gap: 6px;">
      <v-chip v-for="card in clearCards" :key="card.key" small outlined :href="card.url">
        <v-icon left small>{{ card.icon }}</v-icon>{{ card.label }}
      </v-chip>
    </div>
  </v-card>

  <div v-if="data" class="gmk-dash-sub text-center mt-3 gmk-wrap">ANS en horario administrativo: {{ data.hours }}.</div>
</div>`,
});
