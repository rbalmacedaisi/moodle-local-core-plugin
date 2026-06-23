/* global Vue, axios, Swal, strings */
(function () {
    'use strict';

    /**
     * Diploma-grade font catalog. Each entry is a curated Google Font (or
     * core PDF font) suitable for academic certificates. The browser renders
     * the dropdown in the actual font so the editor can see the look, but
     * the PDF itself is rendered with the closest TCPDF core font
     * (see renderer's FONT_MAP). To use the real TTF in the PDF, drop the
     * .ttf into lib/tcpdf/fonts/ and register it in the renderer's map.
     *
     * Grouped by style family so the dropdown reads top-to-bottom from
     * neutral sans/serif up to the most decorative scripts.
     */
    const FONT_OPTIONS = [
        // Core / safe fallbacks (these ARE rendered exactly as named in TCPDF)
        { text: 'Helvetica (sans)', value: 'helvetica', family: '"Helvetica Neue", Arial, sans-serif', tag: 'sans' },
        { text: 'Times (serif)',     value: 'times',     family: '"Times New Roman", Times, serif',         tag: 'serif' },
        { text: 'Courier (mono)',   value: 'courier',   family: '"Courier New", Courier, monospace',      tag: 'mono' },

        // Neutral workhorses
        { text: 'Open Sans',      value: 'opensans',      family: '"Open Sans", sans-serif',           tag: 'sans' },
        { text: 'Roboto',         value: 'roboto',         family: 'Roboto, sans-serif',               tag: 'sans' },
        { text: 'Lato',           value: 'lato',           family: 'Lato, sans-serif',                 tag: 'sans' },
        { text: 'Montserrat',     value: 'montserrat',     family: 'Montserrat, sans-serif',           tag: 'sans' },
        { text: 'Poppins',        value: 'poppins',        family: 'Poppins, sans-serif',              tag: 'sans' },
        { text: 'Raleway',        value: 'raleway',        family: 'Raleway, sans-serif',              tag: 'sans' },
        { text: 'Oswald',         value: 'oswald',         family: 'Oswald, sans-serif',               tag: 'sans' },

        // Editorial serifs (formal, diploma-look)
        { text: 'Playfair Display', value: 'playfairdisplay', family: '"Playfair Display", serif',     tag: 'serif' },
        { text: 'Cormorant Garamond', value: 'cormorantgaramond', family: '"Cormorant Garamond", serif', tag: 'serif' },
        { text: 'EB Garamond',    value: 'ebgaramond',     family: '"EB Garamond", serif',            tag: 'serif' },
        { text: 'Libre Baskerville', value: 'librebaskerville', family: '"Libre Baskerville", serif', tag: 'serif' },
        { text: 'Lora',           value: 'lora',           family: 'Lora, serif',                    tag: 'serif' },
        { text: 'Merriweather',   value: 'merriweather',   family: 'Merriweather, serif',            tag: 'serif' },
        { text: 'Cinzel',         value: 'cinzel',         family: 'Cinzel, serif',                  tag: 'serif' },
        { text: 'Cinzel Decorative', value: 'cinzeldecorative', family: '"Cinzel Decorative", serif', tag: 'serif' },
        { text: 'Marcellus',      value: 'marcellus',      family: 'Marcellus, serif',               tag: 'serif' },
        { text: 'Italiana',       value: 'italiana',       family: 'Italiana, serif',                tag: 'serif' },
        { text: 'Bodoni Moda',    value: 'bodoni',         family: '"Bodoni Moda", serif',           tag: 'serif' },
        { text: 'Abril Fatface',  value: 'abrilfatface',   family: '"Abril Fatface", serif',         tag: 'serif' },
        { text: 'Garamond',       value: 'garamond',       family: 'Garamond, serif',                tag: 'serif' },
        { text: 'Petit Formal Script', value: 'petitformalscript', family: '"Petit Formal Script", cursive', tag: 'script' },

        // Script / calligraphy (the dramatic look for the recipient name)
        { text: 'Great Vibes',    value: 'greatvibes',     family: '"Great Vibes", cursive',          tag: 'script' },
        { text: 'Pinyon Script',  value: 'pinyonscript',   family: '"Pinyon Script", cursive',        tag: 'script' },
        { text: 'Allura',         value: 'allura',         family: 'Allura, cursive',                tag: 'script' },
        { text: 'Tangerine',      value: 'tangerine',      family: 'Tangerine, cursive',             tag: 'script' },
        { text: 'Sacramento',     value: 'sacramento',     family: 'Sacramento, cursive',            tag: 'script' },
        { text: 'Alex Brush',     value: 'alexbrush',      family: '"Alex Brush", cursive',           tag: 'script' },
        { text: 'Dancing Script', value: 'dancingscript',  family: '"Dancing Script", cursive',       tag: 'script' },
        { text: 'Pacifico',       value: 'pacifico',       family: 'Pacifico, cursive',              tag: 'script' },
        { text: 'Parisienne',     value: 'parisienne',     family: 'Parisienne, cursive',            tag: 'script' },
        { text: 'Mr De Haviland', value: 'mrdehaviland',   family: '"Mr De Haviland", cursive',      tag: 'script' },
        { text: 'Italianno',      value: 'italianno',      family: 'Italianno, cursive',             tag: 'script' },
        { text: 'Mrs Saint Delafield', value: 'mrssaintdelafield', family: '"Mrs Saint Delafield", cursive', tag: 'script' },
        { text: 'Bilbo',          value: 'bilbo',          family: 'Bilbo, cursive',                 tag: 'script' },
        { text: 'Rouge Script',   value: 'rougescript',    family: '"Rouge Script", cursive',        tag: 'script' },
        { text: 'Allison Script', value: 'allisonscript',  family: '"Allison Script", cursive',      tag: 'script' },
        { text: 'La Belle Aurore', value: 'labelleaurore', family: '"La Belle Aurore", cursive',     tag: 'script' },
        { text: 'Halimun',        value: 'halimun',        family: 'Halimun, cursive',               tag: 'script' }
    ];

    // Quick lookup so the font-items list view can show each entry in its
    // own CSS font-family. The dropdown <select> rendered by v-select will
    // use the same `family` via a custom item slot.
    const FONT_BY_VALUE = {};
    FONT_OPTIONS.forEach(function (f) { FONT_BY_VALUE[f.value] = f; });

    // Map CSS font-family -> Google Fonts API family name so we can build
    // a single <link> that loads every face we offer. The catalog keys
    // (e.g. "Great Vibes") map 1:1 to the Google Fonts family.
    function googleFamilyFor(item) {
        // item.text is the human label; the family stack is already in
        // item.family. Strip everything except the first quoted font name.
        var m = /"([^"]+)"/.exec(item.family || '');
        return m ? m[1] : '';
    }
    function buildGoogleFontsHref() {
        var families = [];
        var seen = {};
        FONT_OPTIONS.forEach(function (f) {
            var name = googleFamilyFor(f);
            if (!name || seen[name]) return;
            seen[name] = true;
            // Request the most useful weights per family. The API accepts
            // a colon-separated list per family.
            families.push(name.replace(/ /g, '+') + ':400,500,600,700,900');
        });
        return 'https://fonts.googleapis.com/css?family=' + families.join('|') + '&display=swap';
    }
    const GOOGLE_FONTS_HREF = buildGoogleFontsHref();


    const TYPE_OPTIONS = [
        { text: 'Variable del estudiante', value: 'variable' },
        { text: 'Texto personalizado', value: 'custom' },
        { text: 'Texto estático', value: 'static' },
        { text: 'Código QR', value: 'qr' }
    ];

    function getCsrfToken() {
        if (typeof window.M !== 'undefined' && window.M.cfg && window.M.cfg.sesskey) {
            return window.M.cfg.sesskey;
        }
        const meta = document.querySelector('input[name="sesskey"]');
        return meta ? meta.value : '';
    }

    function axiosInstance() {
        const inst = axios.create({
            baseURL: (window.location.origin || '') + '/local/grupomakro_core/ajax.php',
            headers: { 'Content-Type': 'application/json' }
        });
        inst.interceptors.request.use((cfg) => {
            cfg.headers['X-Requested-With'] = 'XMLHttpRequest';
            return cfg;
        });
        return inst;
    }

    Vue.component('diplomatemplates', {
        template: `
            <v-container fluid style="max-width: 100% !important;" class="pa-0">
                <v-row class="ma-0">
                    <v-col cols="12" class="py-2">
                        <div class="d-flex align-center" style="gap: 12px;">
                            <h2 class="mb-0">{{ strings.diploma_templates || 'Plantillas de Diplomas' }}</h2>
                            <v-spacer></v-spacer>
                            <v-btn color="primary" dark @click="newTemplate">
                                <v-icon left>mdi-plus</v-icon>
                                {{ strings.new_template || 'Nueva plantilla' }}
                            </v-btn>
                        </div>
                    </v-col>
                </v-row>

                <v-row class="ma-0">
                    <!-- Left column: template list -->
                    <v-col cols="12" md="3" class="py-1">
                        <v-card outlined>
                            <v-list dense>
                                <v-list-item v-if="loadingTemplates">
                                    <v-list-item-content>
                                        <v-progress-circular indeterminate size="20" width="2"></v-progress-circular>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item v-else-if="!templates.length">
                                    <v-list-item-content>
                                        <span class="grey--text">{{ strings.no_templates }}</span>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item
                                    v-for="t in templates"
                                    :key="t.id"
                                    @click="loadTemplate(t)"
                                    :class="{'v-list-item--active': selected && selected.id === t.id}"
                                    two-line
                                >
                                    <v-list-item-content>
                                        <v-list-item-title>{{ t.name }}</v-list-item-title>
                                        <v-list-item-subtitle>
                                            {{ t.width_mm }} × {{ t.height_mm }} mm • {{ t.active ? strings.active : strings.inactive }}
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                    <v-list-item-action>
                                        <v-btn icon small @click.stop="duplicate(t)">
                                            <v-icon small>mdi-content-copy</v-icon>
                                        </v-btn>
                                        <v-btn icon small @click.stop="confirmDelete(t)">
                                            <v-icon small color="error">mdi-delete</v-icon>
                                        </v-btn>
                                    </v-list-item-action>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-col>

                    <!-- Right column: editor -->
                    <v-col cols="12" md="9" class="py-1">
                        <v-card v-if="!selected" outlined class="pa-6 text-center grey--text">
                            <v-icon size="48" color="grey lighten-1">mdi-file-document-outline</v-icon>
                            <p class="mt-3 mb-0">{{ strings.no_templates }}</p>
                        </v-card>

                        <div v-else>
                            <!-- Meta header -->
                            <v-card outlined class="pa-3 mb-3">
                                <v-row dense>
                                    <v-col cols="12" md="4">
                                        <v-text-field v-model="selected.name" :label="strings.template_name" outlined dense hide-details></v-text-field>
                                    </v-col>
                                    <v-col cols="12" md="4">
                                        <v-select v-model="selected.orientation" :items="[{text: strings.orient_landscape, value: 'landscape'},{text: strings.orient_portrait, value: 'portrait'}]" :label="strings.orientation" outlined dense hide-details @change="onOrientationChange"></v-select>
                                    </v-col>
                                    <v-col cols="12" md="2">
                                        <v-switch v-model="activeSwitch" :label="strings.active" color="primary" hide-details></v-switch>
                                    </v-col>
                                    <v-col cols="12" md="2" class="d-flex align-center justify-end">
                                        <v-btn color="primary" @click="saveTemplate" :loading="saving" small>
                                            <v-icon left small>mdi-content-save</v-icon>
                                            {{ strings.save_template }}
                                        </v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>

                            <!-- Background upload -->
                            <v-card outlined class="pa-3 mb-3">
                                <div class="d-flex align-center" style="gap: 12px; flex-wrap: wrap;">
                                    <div>
                                        <strong>{{ strings.background }}</strong>
                                        <div class="grey--text" style="font-size: 12px;">{{ strings.background_help }}</div>
                                    </div>
                                    <v-spacer></v-spacer>
                                    <input ref="bgInput" type="file" accept="image/*" style="display:none" @change="onBgSelected" />
                                    <v-btn small color="primary" outlined @click="$refs.bgInput.click()">
                                        <v-icon left small>mdi-upload</v-icon>
                                        {{ selected.background_filename ? strings.replace_background : strings.upload_background }}
                                    </v-btn>
                                    <span v-if="selected.background_filename" class="grey--text">{{ selected.background_filename }}</span>
                                    <span v-else class="grey--text">{{ strings.no_background }}</span>
                                </div>
                            </v-card>

                            <!-- Canvas + sidebar -->
                            <v-row dense>
                                <v-col cols="12" md="9">
                                    <div class="dpl-canvas-wrap" ref="canvasWrap">
                                        <div class="dpl-canvas"
                                             ref="canvas"
                                             :style="canvasStyle"
                                             @mousedown.self="canvasMouseDown"
                                             @click.self="selectedFieldId = null">
                                            <div
                                                v-for="f in selected.fields"
                                                :key="f.localId"
                                                :data-fid="f.localId"
                                                class="dpl-field"
                                                :class="{'selected': selectedFieldId === f.localId}"
                                                :style="fieldStyle(f)"
                                                @mousedown.stop="startDrag($event, f)"
                                                @click.stop="selectField(f.localId)"
                                            >
                                                <span class="label">
                                                    {{ fieldShortLabel(f) }}
                                                    <span class="del" @mousedown.stop @click.stop="removeField(f.localId)">×</span>
                                                </span>
                                                <span class="dpl-handle tl" @mousedown.stop="startResize($event, f, 'tl')"></span>
                                                <span class="dpl-handle tr" @mousedown.stop="startResize($event, f, 'tr')"></span>
                                                <span class="dpl-handle bl" @mousedown.stop="startResize($event, f, 'bl')"></span>
                                                <span class="dpl-handle br" @mousedown.stop="startResize($event, f, 'br')"></span>
                                                <span class="dpl-handle rotate" @mousedown.stop="startRotate($event, f)"></span>
                                                <div class="dpl-field-content" :style="contentStyle(f)">
                                                    <span v-if="f.field_type === 'qr'" class="dpl-field-qr">QR</span>
                                                    <span v-else>{{ fieldPreview(f) }}</span>
                                                </div>
                                            </div>
                                            <div v-if="!selected.fields.length" class="dpl-empty">
                                                {{ strings.no_fields }}
                                            </div>
                                        </div>
                                    </div>
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-card outlined class="pa-3 mb-2">
                                        <strong>{{ strings.add_element }}</strong>
                                        <v-btn-toggle v-model="addType" mandatory dense color="primary" class="mt-2 d-flex flex-wrap" style="gap: 4px;">
                                            <v-btn small :value="'variable'" @click="addElement('variable')">{{ strings.type_variable }}</v-btn>
                                            <v-btn small :value="'custom'" @click="addElement('custom')">{{ strings.type_custom }}</v-btn>
                                            <v-btn small :value="'static'" @click="addElement('static')">{{ strings.type_static }}</v-btn>
                                            <v-btn small :value="'qr'" @click="addElement('qr')">{{ strings.type_qr }}</v-btn>
                                        </v-btn-toggle>
                                    </v-card>

                                    <v-card v-if="currentField" outlined class="pa-3">
                                        <v-select v-if="currentField.field_type === 'variable'" v-model="currentField.variable_code" :items="variableItems" :label="strings.variable" outlined dense></v-select>
                                        <v-textarea v-if="currentField.field_type === 'custom'" v-model="currentField.custom_text" :label="strings.custom_text" outlined dense rows="3" :hint="strings.custom_hint || 'Usa {{variable_code}} para insertar valores'" persistent-hint></v-textarea>
                                        <v-text-field v-if="currentField.field_type === 'static'" v-model="currentField.static_text" :label="strings.static_text" outlined dense></v-text-field>

                                        <v-row dense class="mt-1">
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.x_mm" :label="strings.position_x" outlined dense suffix="mm"></v-text-field>
                                            </v-col>
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.y_mm" :label="strings.position_y" outlined dense suffix="mm"></v-text-field>
                                            </v-col>
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.width_mm" :label="strings.size_width" outlined dense suffix="mm"></v-text-field>
                                            </v-col>
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.height_mm" :label="strings.size_height" outlined dense suffix="mm"></v-text-field>
                                            </v-col>
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.rotation" :label="strings.rotation" outlined dense :suffix="strings.rotation_deg"></v-text-field>
                                            </v-col>
                                            <v-col cols="6">
                                                <v-text-field type="number" v-model.number="currentField.z_index" :label="strings.z_index" outlined dense></v-text-field>
                                            </v-col>
                                        </v-row>

                                        <template v-if="currentField.field_type !== 'qr'">
                                            <v-select v-model="currentField.font_family" :items="fontItems" :label="strings.font" outlined dense>
                                                <template slot="selection" slot-scope="data">
                                                    <span :style="{ fontFamily: fontFamilyCss(data.item) }">{{ data.item.text }}</span>
                                                </template>
                                                <template slot="item" slot-scope="data">
                                                    <div :style="{ fontFamily: fontFamilyCss(data.item), padding: '6px 2px', width: '100%' }">
                                                        <div :style="{ fontSize: '24px', lineHeight: '1.1', fontWeight: '700', letterSpacing: data.item.tag === 'script' ? '0' : '1px', textTransform: data.item.tag === 'serif' ? 'uppercase' : 'none' }">{{ data.item.text }}</div>
                                                        <div :style="{ fontSize: '11px', opacity: '0.55', marginTop: '3px', textTransform: 'uppercase', letterSpacing: '1.5px', fontFamily: 'inherit' }">
                                                            <span v-if="data.item.tag === 'script'">Calligraphic script</span>
                                                            <span v-else-if="data.item.tag === 'serif'">Editorial serif</span>
                                                            <span v-else-if="data.item.tag === 'mono'">Monospace</span>
                                                            <span v-else>Sans-serif</span>
                                                        </div>
                                                    </div>
                                                </template>
                                            </v-select>
                                            <v-row dense>
                                                <v-col cols="6">
                                                    <v-text-field type="number" v-model.number="currentField.font_size" :label="strings.font_size" outlined dense suffix="pt"></v-text-field>
                                                </v-col>
                                                <v-col cols="6">
                                                    <v-select v-model="currentField.font_weight" :items="[{text: strings.weight_normal, value: 'normal'},{text: strings.weight_bold, value: 'bold'}]" :label="strings.font_weight" outlined dense></v-select>
                                                </v-col>
                                                <v-col cols="6">
                                                    <v-text-field type="color" v-model="currentField.font_color" :label="strings.font_color" outlined dense></v-text-field>
                                                </v-col>
                                                <v-col cols="6">
                                                    <v-select v-model="currentField.align" :items="[{text: strings.align_left, value: 'left'},{text: strings.align_center, value: 'center'},{text: strings.align_right, value: 'right'}]" :label="strings.align" outlined dense></v-select>
                                                </v-col>
                                                <v-col cols="12">
                                                    <v-text-field type="number" step="0.1" v-model.number="currentField.line_height" :label="strings.line_height" outlined dense></v-text-field>
                                                </v-col>
                                            </v-row>
                                        </template>
                                    </v-card>
                                </v-col>
                            </v-row>
                        </div>
                    </v-col>
                </v-row>
            </v-container>
        `,
        data() {
            return {
                http: axiosInstance(),
                loadingTemplates: false,
                saving: false,
                templates: [],
                selected: null,
                selectedFieldId: null,
                dragState: null,
                addType: 'variable',
                variableItems: [],
                fontItems: FONT_OPTIONS,
                typeItems: TYPE_OPTIONS,
                nextLocalId: 1,
                pixelRatio: 3.2, // CSS px per mm: A4 landscape (297mm) -> 950px
                // Dynamic scale factor so the canvas always fits the wrap
                // even on narrow viewports. Mouse coords are divided by
                // this factor in drag/resize/rotate handlers to keep the
                // internal mm coordinates correct.
                canvasScale: 1
            };
        },
        computed: {
            strings() {
                return window.strings || {};
            },
            currentField() {
                if (!this.selected || !this.selectedFieldId) {
                    return null;
                }
                return this.selected.fields.find(f => f.localId === this.selectedFieldId) || null;
            },
            activeSwitch: {
                get() {
                    return !!(this.selected && this.selected.active);
                },
                set(v) {
                    if (this.selected) {
                        this.selected.active = v ? 1 : 0;
                    }
                }
            },
            canvasStyle() {
                if (!this.selected) {
                    return {};
                }
                return {
                    width: (this.selected.width_mm * this.pixelRatio) + 'px',
                    height: (this.selected.height_mm * this.pixelRatio) + 'px',
                    backgroundImage: this.selected.background_url ? 'url("' + this.selected.background_url + '")' : 'none',
                    // Dynamic CSS scale so the canvas always fits the
                    // wrap without scrollbar. The drag/resize/rotate
                    // handlers divide mouse deltas by this number.
                    transform: 'scale(' + this.canvasScale + ')',
                    transformOrigin: '0 0'
                };
            },
            /**
             * Compute a scale factor that fits the canvas inside the wrap
             * width. Returns 1 (no scale) when the canvas already fits.
             */
            computeCanvasScale() {
                if (!this.selected || !this.$refs.canvasWrap) {
                    return 1;
                }
                var wrap = this.$refs.canvasWrap;
                // 16px padding on each side of the wrap.
                var available = wrap.clientWidth - 32;
                if (available <= 50) { return 1; }
                var natural = this.selected.width_mm * this.pixelRatio;
                if (natural <= 0) { return 1; }
                if (natural <= available) { return 1; }
                return available / natural;
            }
        watch: {
            selected: {
                handler(t) {
                    if (t && t.fields) {
                        // Ensure each field has a localId and computed pixel sizes (mm * ratio).
                        t.fields.forEach(f => {
                            if (!f.localId) {
                                f.localId = this.nextLocalId++;
                            }
                        });
                    }
                    // Re-fit the canvas if the new template has different
                    // width_mm/height_mm than the previous one.
                    var self = this;
                    this.$nextTick(function () { self.recomputeCanvasScale(); });
                },
                deep: true
            }
        },
        mounted() {
            this.loadTemplates();
            this.loadVariables();
            this.setupCanvasResizeObserver();
            // First-pass scale once the layout has settled.
            var self = this;
            this.$nextTick(function () { self.recomputeCanvasScale(); });
        },
        beforeDestroy() {
            if (this._canvasResizeObserver) {
                try { this._canvasResizeObserver.disconnect(); } catch (e) {}
                this._canvasResizeObserver = null;
            }
        },
        methods: {
            setupCanvasResizeObserver() {
                // Re-scale the canvas whenever the wrap size changes
                // (window resize, sidebar toggle, etc.).
                if (typeof ResizeObserver === 'undefined') {
                    // Fallback: re-scale on every animation frame for
                    // the first 5s after mount.
                    var frames = 0;
                    var self = this;
                    function tick() {
                        self.recomputeCanvasScale();
                        if (++frames < 300) { requestAnimationFrame(tick); }
                    }
                    requestAnimationFrame(tick);
                    return;
                }
                if (!this.$refs.canvasWrap) { return; }
                var self = this;
                var ro = new ResizeObserver(function () { self.recomputeCanvasScale(); });
                ro.observe(this.$refs.canvasWrap);
                // Also observe the wrap's offsetParent so we react to
                // column reflows (md=8 vs md=9).
                if (this.$refs.canvasWrap.parentElement) {
                    ro.observe(this.$refs.canvasWrap.parentElement);
                }
                this._canvasResizeObserver = ro;
            },
            recomputeCanvasScale() {
                var next = this.computeCanvasScale();
                // Snap to 3 decimals to avoid re-rendering on floating noise.
                next = Math.round(next * 1000) / 1000;
                if (next !== this.canvasScale) {
                    this.canvasScale = next;
                }
                // Resize the wrap to exactly the scaled canvas so the
                // wrap does not overflow its parent column. The
                // canvas itself keeps its natural width/height in CSS
                // pixels; the CSS transform scales it visually.
                var wrap = this.$refs.canvasWrap;
                if (wrap && this.selected) {
                    var w = this.selected.width_mm * this.pixelRatio * this.canvasScale;
                    var h = this.selected.height_mm * this.pixelRatio * this.canvasScale;
                    wrap.style.width = Math.ceil(w) + 'px';
                    wrap.style.height = Math.ceil(h) + 'px';
                }
            },
            async loadTemplates() {
                this.loadingTemplates = true;
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_list_templates' });
                    if (res.data && res.data.status === 'success') {
                        this.templates = res.data.templates || [];
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    this.notifyError(e.message || e);
                } finally {
                    this.loadingTemplates = false;
                }
            },
            async loadVariables() {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_list_variables' });
                    if (res.data && res.data.status === 'success') {
                        this.variableItems = (res.data.variables || []).map(v => ({ text: v.label, value: v.code }));
                    }
                } catch (e) { /* non-fatal */ }
            },
            newTemplate() {
                this.selected = {
                    id: 0,
                    name: this.strings.new_template || 'Nueva plantilla',
                    description: '',
                    orientation: 'landscape',
                    width_mm: 297,
                    height_mm: 210,
                    active: 1,
                    background_url: '',
                    background_filename: '',
                    background_mimetype: '',
                    fields: []
                };
                this.selectedFieldId = null;
            },
            async loadTemplate(t) {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_get_template', id: t.id });
                    if (res.data && res.data.status === 'success') {
                        const data = res.data.template;
                        data.fields = (data.fields || []).map(f => ({ ...f, localId: this.nextLocalId++ }));
                        this.selected = data;
                        this.selectedFieldId = null;
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    this.notifyError(e.message || e);
                }
            },
            async duplicate(t) {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_duplicate_template', id: t.id });
                    if (res.data && res.data.status === 'success') {
                        this.notifyOk(res.data.message);
                        await this.loadTemplates();
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) { this.notifyError(e.message || e); }
            },
            confirmDelete(t) {
                Swal.fire({
                    title: this.strings.delete_template,
                    text: this.strings.delete_confirm,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: this.strings.delete_template,
                    cancelButtonText: this.strings.cancel
                }).then(r => {
                    if (r.isConfirmed) this.deleteTemplate(t);
                });
            },
            async deleteTemplate(t) {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_delete_template', id: t.id });
                    if (res.data && res.data.status === 'success') {
                        this.notifyOk(res.data.message);
                        if (this.selected && this.selected.id === t.id) {
                            this.selected = null;
                        }
                        await this.loadTemplates();
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) { this.notifyError(e.message || e); }
            },
            async saveTemplate() {
                if (!this.selected) return;
                this.saving = true;
                try {
                    const payload = JSON.parse(JSON.stringify(this.selected));
                    // Drop transient keys before sending.
                    payload.fields = (payload.fields || []).map(f => {
                        const c = { ...f };
                        delete c.localId;
                        return c;
                    });
                    delete payload.background_url;
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_save_template',
                        payload: JSON.stringify(payload)
                    });
                    if (res.data && res.data.status === 'success') {
                        const tpl = res.data.template;
                        tpl.fields = (tpl.fields || []).map(f => ({ ...f, localId: this.nextLocalId++ }));
                        this.selected = tpl;
                        this.notifyOk(res.data.message || this.strings.save_ok);
                        await this.loadTemplates();
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    this.notifyError(e.message || e);
                } finally {
                    this.saving = false;
                }
            },
            onOrientationChange() {
                if (!this.selected) return;
                if (this.selected.orientation === 'portrait') {
                    this.selected.width_mm = 210;
                    this.selected.height_mm = 297;
                } else {
                    this.selected.width_mm = 297;
                    this.selected.height_mm = 210;
                }
            },
            async onBgSelected(e) {
                const file = e.target.files && e.target.files[0];
                if (!file || !this.selected || !this.selected.id) {
                    this.notifyError('Guarde primero la plantilla antes de subir el fondo.');
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    this.notifyError('La imagen supera 5 MB. Use una versión optimizada.');
                    e.target.value = '';
                    return;
                }
                // Read the file as base64 inside a JSON payload. This avoids
                // all the multipart/boundary/CORS pitfalls that plagued the
                // original FormData approach (axios vs PHP disagreement on
                // the boundary in the Content-Type header).
                try {
                    const dataUrl = await this.readFileAsDataUrl(file);
                    const base64 = dataUrl.split(',')[1] || '';
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_upload_background',
                        id: this.selected.id,
                        filename: file.name,
                        mimetype: file.type || 'image/png',
                        contentbase64: base64
                    });
                    if (res.data && res.data.status === 'success') {
                        const bg = res.data.background;
                        this.selected.background_url = bg.url + '?v=' + Date.now();
                        this.selected.background_filename = bg.filename;
                        this.selected.background_mimetype = bg.mimetype;
                        this.notifyOk('Imagen de fondo actualizada');
                    } else {
                        const msg = (res.data && res.data.message) || 'Error desconocido';
                        console.error('[diplomatemplates] upload error', res.data);
                        throw new Error(msg);
                    }
                } catch (err) {
                    var servermsg = '';
                    if (err && err.response && err.response.data) {
                        try { servermsg = JSON.stringify(err.response.data); } catch (e) {}
                    }
                    console.error('[diplomatemplates] upload threw', err, servermsg);
                    this.notifyError((err && err.message) || err || 'Error de red');
                } finally { e.target.value = ''; }
            },
            readFileAsDataUrl(file) {
                return new Promise((resolve, reject) => {
                    var r = new FileReader();
                    r.onload = function () { resolve(r.result); };
                    r.onerror = function () { reject(r.error || new Error('FileReader error')); };
                    r.readAsDataURL(file);
                });
            },
            addElement(type) {
                if (!this.selected) {
                    this.newTemplate();
                }
                const f = {
                    localId: this.nextLocalId++,
                    templateid: this.selected.id || 0,
                    field_type: type,
                    variable_code: type === 'variable' ? 'fullname' : '',
                    custom_text: type === 'custom' ? '{{fullname}}' : '',
                    static_text: type === 'static' ? 'Texto fijo' : '',
                    x_mm: 30,
                    y_mm: 30 + (this.selected.fields.length * 18),
                    width_mm: type === 'qr' ? 30 : 80,
                    height_mm: type === 'qr' ? 30 : 14,
                    rotation: 0,
                    font_family: 'helvetica',
                    font_size: 14,
                    font_weight: 'normal',
                    font_color: '#000000',
                    align: 'center',
                    line_height: 1.2,
                    z_index: this.selected.fields.length
                };
                this.selected.fields.push(f);
                this.selectedFieldId = f.localId;
            },
            removeField(localId) {
                if (!this.selected) return;
                this.selected.fields = this.selected.fields.filter(f => f.localId !== localId);
                if (this.selectedFieldId === localId) this.selectedFieldId = null;
            },
            selectField(localId) { this.selectedFieldId = localId; },
            fieldStyle(f) {
                return {
                    left: (f.x_mm * this.pixelRatio) + 'px',
                    top: (f.y_mm * this.pixelRatio) + 'px',
                    width: (f.width_mm * this.pixelRatio) + 'px',
                    height: (f.height_mm * this.pixelRatio) + 'px',
                    transform: f.rotation ? 'rotate(' + f.rotation + 'deg)' : ''
                };
            },
            contentStyle(f) {
                // Use the real CSS font-family (resolved from the font
                // catalog) so the on-canvas preview matches the selected
                // Google Font. The PDF is still rendered with the closest
                // TCPDF core font (see renderer FONT_MAP).
                var item = FONT_BY_VALUE[f.font_family] || { family: 'helvetica, sans-serif' };
                return {
                    fontFamily: item.family,
                    fontSize: (f.font_size * this.pixelRatio * 0.35) + 'px',
                    fontWeight: f.font_weight || 'normal',
                    color: f.font_color || '#000',
                    textAlign: f.align || 'center',
                    lineHeight: (f.line_height || 1.2)
                };
            },
            fieldShortLabel(f) {
                if (f.field_type === 'variable') return 'VAR: ' + (f.variable_code || '?');
                if (f.field_type === 'custom') return 'TXT: ' + ((f.custom_text || '').slice(0, 18));
                if (f.field_type === 'static') return 'FIX: ' + ((f.static_text || '').slice(0, 18));
                if (f.field_type === 'qr') return 'QR';
                return '?';
            },
            fontFamilyCss(item) {
                // Resolve the CSS font-family for a font-select item, falling
                // back to the configured family and finally to a generic stack.
                if (!item) return 'serif';
                if (item.family) return item.family;
                return 'serif';
            },
            fieldPreview(f) {
                if (f.field_type === 'variable') {
                    const v = (this.variableItems.find(v => v.value === f.variable_code) || {}).text || f.variable_code;
                    return '[' + v + ']';
                }
                if (f.field_type === 'custom') return f.custom_text || '';
                if (f.field_type === 'static') return f.static_text || '';
                return '';
            },
            canvasMouseDown() { this.selectedFieldId = null; },
            startDrag(e, f) {
                if (e.target.classList.contains('dpl-handle')) return;
                this.selectField(f.localId);
                const startX = e.clientX, startY = e.clientY;
                const ox = f.x_mm, oy = f.y_mm;
                // ratio converts mm -> CSS px. We also divide by the
                // current canvasScale so mouse deltas (in viewport px)
                // are converted back to mm correctly when the canvas
                // is being visually scaled down to fit the wrap.
                const ratio = this.pixelRatio / this.canvasScale;
                this.dragState = { kind: 'move', f, startX, startY, ox, oy, ratio };
                window.addEventListener('mousemove', this.onDrag);
                window.addEventListener('mouseup', this.endDrag);
                e.preventDefault();
            },
            startResize(e, f, corner) {
                this.selectField(f.localId);
                const startX = e.clientX, startY = e.clientY;
                const ow = f.width_mm, oh = f.height_mm;
                const ratio = this.pixelRatio / this.canvasScale;
                this.dragState = { kind: 'resize', f, startX, startY, ow, oh, ratio, corner };
                window.addEventListener('mousemove', this.onDrag);
                window.addEventListener('mouseup', this.endDrag);
                e.preventDefault();
            },
            startRotate(e, f) {
                this.selectField(f.localId);
                // Compute angle from element center to mouse.
                const rect = e.target.parentElement.getBoundingClientRect();
                const cx = rect.left + rect.width / 2;
                const cy = rect.top + rect.height / 2;
                const startAngle = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI;
                const initial = f.rotation || 0;
                this.dragState = { kind: 'rotate', f, cx, cy, startAngle, initial };
                window.addEventListener('mousemove', this.onDrag);
                window.addEventListener('mouseup', this.endDrag);
                e.preventDefault();
            },
            onDrag(e) {
                if (!this.dragState) return;
                const s = this.dragState;
                if (s.kind === 'move') {
                    const dx = (e.clientX - s.startX) / s.ratio;
                    const dy = (e.clientY - s.startY) / s.ratio;
                    s.f.x_mm = Math.max(0, +(s.ox + dx).toFixed(2));
                    s.f.y_mm = Math.max(0, +(s.oy + dy).toFixed(2));
                } else if (s.kind === 'resize') {
                    const dx = (e.clientX - s.startX) / s.ratio;
                    const dy = (e.clientY - s.startY) / s.ratio;
                    let nw = s.ow, nh = s.oh;
                    if (s.corner === 'br') { nw = s.ow + dx; nh = s.oh + dy; }
                    if (s.corner === 'tr') { nw = s.ow + dx; nh = s.oh - dy; s.f.y_mm = Math.max(0, +(s.f.y_mm + dy).toFixed(2)); }
                    if (s.corner === 'bl') { nw = s.ow - dx; nh = s.oh + dy; s.f.x_mm = Math.max(0, +(s.f.x_mm + dx).toFixed(2)); }
                    if (s.corner === 'tl') { nw = s.ow - dx; nh = s.oh - dy; s.f.x_mm = Math.max(0, +(s.f.x_mm + dx).toFixed(2)); s.f.y_mm = Math.max(0, +(s.f.y_mm + dy).toFixed(2)); }
                    s.f.width_mm = Math.max(5, +nw.toFixed(2));
                    s.f.height_mm = Math.max(5, +nh.toFixed(2));
                } else if (s.kind === 'rotate') {
                    const current = Math.atan2(e.clientY - s.cy, e.clientX - s.cx) * 180 / Math.PI;
                    const delta = current - s.startAngle;
                    s.f.rotation = Math.round((s.initial + delta) * 10) / 10;
                }
            },
            endDrag() {
                window.removeEventListener('mousemove', this.onDrag);
                window.removeEventListener('mouseup', this.endDrag);
                this.dragState = null;
            },
            notifyOk(msg) {
                Swal.fire({ toast: true, icon: 'success', title: msg || 'OK', position: 'top-end', showConfirmButton: false, timer: 2400 });
            },
            notifyError(msg) {
                Swal.fire({ icon: 'error', title: 'Error', text: String(msg || '') });
            }
        }
    });

    // Mount the Vue app on the #gmk-app element emitted by diplomatemplates.php.
    // This must run after the component is registered so <diplomatemplates>
    // resolves to the registered component definition.
    function mountDiplomaTemplates() {
        var root = document.getElementById('gmk-app');
        if (!root) { return; }
        // Avoid double-mount (HMR / navigation edge cases).
        if (root.__vue_app__) { return; }
        var app = new Vue({
            el: root,
            vuetify: new Vuetify({ theme: { dark: false } })
        });
        root.__vue_app__ = app;
        if (window.console && console.log) {
            console.log('[grupomakro_core] diplomatemplates mounted');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountDiplomaTemplates);
    } else {
        mountDiplomaTemplates();
    }
})();
