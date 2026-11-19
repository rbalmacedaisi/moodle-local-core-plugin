/**
 * SupportAttach.js
 * Mesa de ayuda (20261001124): adjuntos del chat en Moodle (bandeja y Ayuda TI).
 *
 *  - <support-attach-picker>: boton "Adjuntar" (accept segun la configuracion;
 *    en el celular abre camara o galeria), vista previa, progreso, cancelar y
 *    quitar. Comprime las imagenes en el navegador (lado mayor 1920 px, calidad
 *    ~80 %); los videos no se comprimen: se valida el tamano antes de subir.
 *    Sube cada archivo a support_upload.php (sesion + sesskey) y guarda el
 *    draftitemid que luego se manda con send_message.
 *  - <support-attachments>: muestra los adjuntos de un mensaje: imagenes como
 *    miniaturas (clic = visor a pantalla completa), videos con reproductor
 *    (playsinline) y PDF como enlace.
 */
window.gmkSupportCompressImage = function (file) {
    const type = (file.type || '').toLowerCase();
    // GIF (animacion) y HEIC (el navegador no siempre lo decodifica) se envian tal cual.
    if (!/^image\/(jpeg|png|webp)$/.test(type)) return Promise.resolve(file);
    return new Promise(function (resolve) {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
            const max = 1920;
            const long = Math.max(img.naturalWidth, img.naturalHeight);
            const scale = long > max ? max / long : 1;
            const w = Math.round(img.naturalWidth * scale);
            const h = Math.round(img.naturalHeight * scale);
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; // PNG transparente -> fondo blanco en JPEG.
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            URL.revokeObjectURL(url);
            canvas.toBlob(function (blob) {
                if (!blob || (blob.size >= file.size && scale === 1)) {
                    resolve(file);
                    return;
                }
                const name = (file.name || 'imagen').replace(/\.[^.]+$/, '') + '.jpg';
                try {
                    resolve(new File([blob], name, { type: 'image/jpeg' }));
                } catch (e) {
                    blob.name = name;
                    resolve(blob);
                }
            }, 'image/jpeg', 0.8);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
};

window.gmkSupportFileKind = function (name, type) {
    const ext = (String(name || '').split('.').pop() || '').toLowerCase();
    if (/^(mp4|m4v|mov|webm)$/.test(ext) || /^video\//.test(type || '')) return 'video';
    if (ext === 'pdf') return 'file';
    return 'image';
};

window.gmkSupportSize = function (bytes) {
    if (!bytes) return '0 KB';
    if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    return (bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0) + ' MB';
};

Vue.component('support-attach-picker', {
    props: {
        limits: { type: Object, default: null },
        ticketid: { type: Number, default: 0 },
        disabled: { type: Boolean, default: false },
    },
    template: `
    <div v-if="limits && limits.enabled" class="gmk-attach">
      <input ref="input" type="file" class="d-none" :accept="accept" multiple @change="onPick">
      <div v-if="items.length" class="gmk-attach__strip mb-2">
        <div v-for="it in items" :key="it.key" class="gmk-attach__item" :class="{ 'gmk-attach__item--error': it.status === 'error' }">
          <img v-if="it.kind === 'image' && it.preview" :src="it.preview" alt="" class="gmk-attach__thumb">
          <video v-else-if="it.kind === 'video' && it.preview" :src="it.preview" muted playsinline preload="metadata" class="gmk-attach__thumb"></video>
          <div v-else class="gmk-attach__thumb d-flex align-center justify-center"><v-icon>{{ it.kind === 'video' ? 'mdi-filmstrip' : 'mdi-file-pdf-box' }}</v-icon></div>
          <div class="gmk-attach__meta">
            <div class="text-caption gmk-wrap">{{ it.name }}</div>
            <div class="text-caption text--secondary">{{ size(it.size) }}<span v-if="it.status === 'uploading'"> · {{ it.progress }} %</span></div>
            <div v-if="it.status === 'error'" class="text-caption error--text gmk-wrap">{{ it.error }}</div>
            <v-progress-linear v-if="it.status === 'uploading' || it.status === 'preparing'" :value="it.progress" :indeterminate="it.status === 'preparing'" height="4" rounded></v-progress-linear>
          </div>
          <v-btn icon small :aria-label="it.status === 'uploading' ? 'Cancelar subida' : 'Quitar adjunto'" @click="remove(it)"><v-icon small>mdi-close</v-icon></v-btn>
        </div>
      </div>
      <v-btn text small color="primary" :disabled="disabled || items.length >= limits.maxfiles" class="gmk-attach__btn px-2"
        @click="$refs.input.click()" aria-label="Adjuntar imagen, video o PDF">
        <v-icon left>mdi-paperclip</v-icon>Adjuntar
      </v-btn>
      <span class="text-caption text--secondary">hasta {{ limits.maxfiles }} · imagen {{ size(limits.imagebytes) }} · video {{ size(limits.videobytes) }}</span>
    </div>`,
    data() {
        return { items: [], draftitemid: 0, seq: 0 };
    },
    computed: {
        accept() {
            return this.limits && this.limits.accept ? this.limits.accept : 'image/*,video/*';
        },
        busy() {
            return this.items.some(i => i.status === 'uploading' || i.status === 'preparing');
        },
        failed() {
            return this.items.some(i => i.status === 'error');
        },
        count() {
            return this.items.filter(i => i.status === 'done').length;
        },
    },
    watch: {
        busy() { this.emitState(); },
        count() { this.emitState(); },
        failed() { this.emitState(); },
    },
    beforeDestroy() {
        this.items.forEach(i => { if (i.xhr) i.xhr.abort(); if (i.preview) URL.revokeObjectURL(i.preview); });
    },
    methods: {
        size(b) {
            return window.gmkSupportSize(b);
        },
        emitState() {
            this.$emit('state', { busy: this.busy, failed: this.failed, count: this.count, draftitemid: this.draftitemid });
        },
        reset() {
            this.items.forEach(i => { if (i.xhr) i.xhr.abort(); if (i.preview) URL.revokeObjectURL(i.preview); });
            this.items = [];
            this.draftitemid = 0;
            this.emitState();
        },
        async onPick(e) {
            const files = Array.prototype.slice.call(e.target.files || []);
            e.target.value = '';
            for (const file of files) {
                if (this.items.length >= this.limits.maxfiles) {
                    this.$emit('error', 'Puedes adjuntar como máximo ' + this.limits.maxfiles + ' archivos por mensaje.');
                    break;
                }
                await this.add(file);
            }
        },
        async add(file) {
            const kind = window.gmkSupportFileKind(file.name, file.type);
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            const item = { key: ++this.seq, name: file.name, kind: kind, size: file.size, progress: 0, status: 'preparing',
                error: '', preview: '', xhr: null, uploadedname: '' };
            this.items.push(item);
            if (this.limits.types.indexOf(ext) === -1 && !(kind === 'image' && /^image\/(jpeg|png|webp)$/.test(file.type))) {
                return this.fail(item, 'Tipo no permitido. Se aceptan: ' + this.limits.types.join(', ') + '.');
            }
            let blob = file;
            if (kind === 'image') {
                blob = await window.gmkSupportCompressImage(file);
            }
            item.name = blob.name || file.name;
            item.size = blob.size;
            const max = kind === 'video' ? this.limits.videobytes : this.limits.imagebytes;
            if (max && blob.size > max) {
                return this.fail(item, (kind === 'video' ? 'El video pesa ' : 'El archivo pesa ') + this.size(blob.size)
                    + '; el máximo es ' + this.size(max) + (kind === 'video' ? '. Recórtalo o grábalo más corto.' : '.'));
            }
            if (kind !== 'file') {
                item.preview = URL.createObjectURL(blob);
            }
            this.upload(item, blob);
        },
        fail(item, text) {
            item.status = 'error';
            item.error = text;
        },
        upload(item, blob) {
            const cfg = (window.M && window.M.cfg) || {};
            const form = new FormData();
            form.append('file', blob, item.name);
            form.append('draftitemid', String(this.draftitemid || 0));
            form.append('ticketid', String(this.ticketid || 0));
            form.append('sesskey', cfg.sesskey || '');
            const xhr = new XMLHttpRequest();
            item.xhr = xhr;
            item.status = 'uploading';
            xhr.open('POST', this.limits.uploadurl);
            xhr.upload.onprogress = (ev) => {
                if (ev.lengthComputable) item.progress = Math.round(ev.loaded * 100 / ev.total);
            };
            xhr.onload = () => {
                item.xhr = null;
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                if (data && data.ok) {
                    this.draftitemid = data.draftitemid;
                    item.uploadedname = data.filename;
                    item.progress = 100;
                    item.status = 'done';
                } else {
                    this.fail(item, (data && data.error) || (xhr.status === 413 ? 'El archivo supera el máximo del servidor.' : 'No se pudo subir el archivo.'));
                }
                this.emitState();
            };
            xhr.onerror = () => {
                item.xhr = null;
                this.fail(item, 'Se cortó la conexión. Quita el archivo y vuelve a intentarlo.');
            };
            xhr.send(form);
        },
        remove(item) {
            if (item.xhr) item.xhr.abort();
            if (item.preview) URL.revokeObjectURL(item.preview);
            this.items = this.items.filter(i => i !== item);
            if (item.status === 'done' && item.uploadedname && this.draftitemid) {
                const cfg = (window.M && window.M.cfg) || {};
                const form = new FormData();
                form.append('action', 'delete');
                form.append('draftitemid', String(this.draftitemid));
                form.append('filename', item.uploadedname);
                form.append('sesskey', cfg.sesskey || '');
                fetch(this.limits.uploadurl, { method: 'POST', body: form, credentials: 'same-origin' }).catch(() => {});
            }
            this.emitState();
        },
    },
});

Vue.component('support-attachments', {
    props: {
        items: { type: Array, default: () => [] },
        suffix: { type: String, default: '' },
    },
    template: `
    <div v-if="items && items.length" class="gmk-attachments">
      <template v-for="a in items">
        <a v-if="a.kind === 'image'" :key="a.id" href="#" class="gmk-attachments__img" @click.prevent="open(a)" :aria-label="'Ver imagen ' + a.filename">
          <img :src="a.url + suffix" :alt="a.filename" loading="lazy">
        </a>
        <video v-else-if="a.kind === 'video'" :key="a.id" class="gmk-attachments__video" controls playsinline preload="metadata"
          :src="a.url + suffix"></video>
        <a v-else :key="a.id" :href="a.url + suffix" target="_blank" rel="noopener" class="gmk-attachments__file">
          <v-icon small class="mr-1">mdi-file-pdf-box</v-icon>{{ a.filename }} · {{ size(a.filesize) }}
        </a>
      </template>
      <v-dialog v-model="viewer.open" fullscreen hide-overlay transition="fade-transition">
        <div class="gmk-viewer" @click.self="viewer.open = false">
          <v-btn icon dark class="gmk-viewer__close" aria-label="Cerrar" @click="viewer.open = false"><v-icon>mdi-close</v-icon></v-btn>
          <img v-if="viewer.item" :src="viewer.item.url + suffix" :alt="viewer.item.filename">
          <a v-if="viewer.item" :href="viewer.item.url + suffix + '&download=1'" class="gmk-viewer__dl white--text">Descargar</a>
        </div>
      </v-dialog>
    </div>`,
    data() {
        return { viewer: { open: false, item: null } };
    },
    methods: {
        size(b) {
            return window.gmkSupportSize(b);
        },
        open(a) {
            this.viewer = { open: true, item: a };
        },
    },
});

(function () {
    if (document.getElementById('gmk-attach-css')) return;
    const css = `
#gmk-app .gmk-attach__strip { display: flex; flex-direction: column; gap: 6px; }
#gmk-app .gmk-attach__item { display: flex; align-items: center; gap: 8px; border: 1px solid rgba(0,0,0,.12); border-radius: 8px; padding: 4px 4px 4px 6px; min-width: 0; }
#gmk-app .gmk-attach__item--error { border-color: #e53935; }
#gmk-app .gmk-attach__thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; background: #eceff1; flex: 0 0 48px; }
#gmk-app .gmk-attach__meta { flex: 1 1 auto; min-width: 0; }
#gmk-app .gmk-attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
#gmk-app .gmk-attachments__img img { display: block; width: 160px; max-width: 100%; height: 120px; object-fit: cover; border-radius: 8px; background: #eceff1; }
#gmk-app .gmk-attachments__video { width: 260px; max-width: 100%; border-radius: 8px; background: #000; }
#gmk-app .gmk-attachments__file { display: inline-flex; align-items: center; min-height: 36px; padding: 0 8px; border-radius: 8px; background: rgba(0,0,0,.05); overflow-wrap: anywhere; }
.gmk-viewer { position: fixed; inset: 0; background: rgba(0,0,0,.94); display: flex; align-items: center; justify-content: center;
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); }
.gmk-viewer img { max-width: 100%; max-height: 100%; object-fit: contain; }
.gmk-viewer__close { position: absolute !important; top: calc(8px + env(safe-area-inset-top)); right: calc(8px + env(safe-area-inset-right)); }
.gmk-viewer__dl { position: absolute; bottom: calc(16px + env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%); padding: 10px 16px; border-radius: 20px; background: rgba(255,255,255,.15); text-decoration: none; }
@media (min-width: 600px) {
  #gmk-app .gmk-attach__strip { flex-direction: row; flex-wrap: wrap; }
  #gmk-app .gmk-attach__item { width: 260px; }
}`;
    const style = document.createElement('style');
    style.id = 'gmk-attach-css';
    style.textContent = css;
    document.head.appendChild(style);
})();
