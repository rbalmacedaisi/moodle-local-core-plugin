/**
 * SupportApi.js
 * Mesa de ayuda (20261001120): llamada a los WS local_grupomakro_support_*
 * desde las paginas de Moodle por /lib/ajax/service.php, que exige la sesskey
 * de la sesion (proteccion CSRF) y corre como el usuario conectado.
 */
window.gmkSupportCall = async function (methodname, args) {
    const cfg = (window.M && window.M.cfg) || {};
    const url = (cfg.wwwroot || window.location.origin) + '/lib/ajax/service.php?sesskey='
        + encodeURIComponent(cfg.sesskey || '') + '&info=' + encodeURIComponent(methodname);
    const response = await axios.post(url, [{ index: 0, methodname: methodname, args: args || {} }]);
    const result = Array.isArray(response.data) ? response.data[0] : null;
    if (!result) {
        const err = response.data && (response.data.error || response.data.message);
        throw new Error(err || 'Respuesta vacía del servidor');
    }
    if (result.error) {
        const ex = result.exception || {};
        throw new Error(ex.message || ex.errorcode || 'Error del servidor');
    }
    return result.data;
};

window.gmkSupportFormat = {
    date(ts) {
        if (!ts) return '';
        return new Date(ts * 1000).toLocaleString('es-PA', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
        });
    },
    waiting(minutes) {
        if (!minutes) return '';
        if (minutes < 60) return minutes + ' min';
        const h = Math.floor(minutes / 60);
        if (h < 48) return h + ' h ' + (minutes % 60) + ' min';
        return Math.floor(h / 24) + ' d';
    },
    semaphoreColor(s) {
        return { green: 'green', green_warn: 'light-green', yellow: 'amber', orange: 'orange', red: 'red' }[s] || 'grey';
    },
    statusColor(s) {
        return {
            nuevo: 'red', asignado: 'deep-orange', en_atencion: 'blue', esperando_estudiante: 'purple',
            resuelto: 'green', reabierto: 'pink', cerrado: 'grey', cancelado: 'blue-grey',
        }[s] || 'grey';
    },
    priorityLabel(p) {
        return ['Baja', 'Normal', 'Alta'][p] || 'Normal';
    },
};

/**
 * Estilos de la mesa de ayuda en Moodle, mobile first (pedido de Ramiro,
 * 18-nov-2026): la base es para 360-400 px y el escritorio se agrega con
 * min-width. Alcance: #gmk-app de las paginas support_* (los dialogos de
 * Vuetify se montan dentro de ese mismo v-app).
 *  - Botones y pestanas de al menos 44 px; campos con fuente de 16 px (iOS no hace zoom).
 *  - Bandeja: lista a ancho completo; el ticket abierto ocupa la pantalla.
 *  - Tablas .gmk-stack: tarjetas apiladas en el celular (etiquetas en data-label).
 *  - Nada de scroll horizontal.
 */
(function () {
    if (document.getElementById('gmk-sup-css')) {
        return;
    }
    const css = `
#gmk-app { overflow-x: hidden; max-width: 100%; }
#gmk-app .gmk-sup { max-width: 100%; }
#gmk-app .gmk-wrap { white-space: normal !important; overflow-wrap: anywhere; word-break: break-word; }
#gmk-app .gmk-min0 { min-width: 0; }
#gmk-app .v-btn:not(.v-btn--icon):not(.v-btn--fab) { min-height: 44px; }
#gmk-app .v-btn.v-btn--icon, #gmk-app .v-btn.v-btn--fab { min-width: 44px; min-height: 44px; }
#gmk-app .v-tab { min-height: 44px; }
#gmk-app .v-chip.v-chip--clickable { min-height: 36px; }
#gmk-app .v-list-item { min-height: 48px; }
#gmk-app input, #gmk-app textarea, #gmk-app .v-select__selection { font-size: 16px !important; }
#gmk-app .gmk-bubble { max-width: 86%; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }

/* Bandeja: base = celular */
#gmk-app .gmk-desk { display: flex; flex-direction: column; }
#gmk-app .gmk-desk__list { width: 100%; display: flex; flex-direction: column; min-width: 0; }
#gmk-app .gmk-desk__chat { display: none; flex-direction: column; min-width: 0; }
#gmk-app .gmk-desk--open .gmk-desk__list { display: none; }
#gmk-app .gmk-desk--open .gmk-desk__chat {
  display: flex; position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 1045; background: #fff;
  padding-top: env(safe-area-inset-top); padding-left: env(safe-area-inset-left); padding-right: env(safe-area-inset-right);
}
#gmk-app .gmk-desk__messages { flex: 1 1 auto; overflow-y: auto; background: rgba(0,0,0,0.02); -webkit-overflow-scrolling: touch; }
#gmk-app .gmk-desk__composer { padding-bottom: calc(8px + env(safe-area-inset-bottom)) !important; background: #fff; }
#gmk-app .gmk-modes .v-slide-group__content { padding: 0 0 4px; }
#gmk-app .gmk-desk-tabs .v-slide-group__prev, #gmk-app .gmk-desk-tabs .v-slide-group__next { min-width: 0; flex-basis: 0; }

/* Tablas que se apilan como tarjetas */
#gmk-app .gmk-stack table, #gmk-app .gmk-stack tbody, #gmk-app .gmk-stack tr, #gmk-app .gmk-stack td { display: block; width: 100%; }
#gmk-app .gmk-stack thead { display: none; }
#gmk-app .gmk-stack tr { border: 1px solid rgba(0,0,0,0.12); border-radius: 8px; margin-bottom: 10px; padding: 6px 12px; }
#gmk-app .gmk-stack tbody tr:hover { background: transparent !important; }
#gmk-app .gmk-stack td { height: auto !important; border: 0 !important; padding: 4px 0 !important; text-align: left !important;
  display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px; overflow-wrap: anywhere; }
#gmk-app .gmk-stack td::before { content: attr(data-label); flex: 0 0 38%; max-width: 38%; font-weight: 500; color: rgba(0,0,0,0.6); font-size: 13px; }
#gmk-app .gmk-stack td[data-label=""]::before { display: none; }
#gmk-app .gmk-stack td[data-label=""] { justify-content: flex-end; }
#gmk-app .gmk-stack .v-input { min-width: 0; flex: 1 1 140px; max-width: 100% !important; }

@media (min-width: 600px) {
  #gmk-app .gmk-stack table { display: table; width: 100%; }
  #gmk-app .gmk-stack tbody, #gmk-app .gmk-stack tr, #gmk-app .gmk-stack td { width: auto; }
  #gmk-app .gmk-stack thead { display: table-header-group; }
  #gmk-app .gmk-stack tbody { display: table-row-group; }
  #gmk-app .gmk-stack tr { display: table-row; border: 0; margin: 0; padding: 0; border-radius: 0; }
  #gmk-app .gmk-stack td { display: table-cell; padding: 0 8px !important; border-bottom: thin solid rgba(0,0,0,0.12) !important; height: 40px !important; }
  #gmk-app .gmk-stack td::before { content: none; }
  #gmk-app .gmk-stack td.text-right, #gmk-app .gmk-stack td[data-label=""] { text-align: right !important; }
}

@media (min-width: 960px) {
  #gmk-app .v-btn:not(.v-btn--icon):not(.v-btn--fab) { min-height: 0; }
  #gmk-app .v-btn.v-btn--icon, #gmk-app .v-btn.v-btn--fab { min-width: 0; min-height: 0; }
  #gmk-app .v-list-item { min-height: 40px; }
  #gmk-app input, #gmk-app textarea, #gmk-app .v-select__selection { font-size: 14px !important; }
  #gmk-app .gmk-bubble { max-width: 78%; }
  #gmk-app .gmk-desk-head { display: flex; align-items: center; gap: 12px; }
  #gmk-app .gmk-desk-head > .d-flex { flex: 1 1 auto; }
  #gmk-app .gmk-desk-search { max-width: 340px; }
  #gmk-app .gmk-desk { flex-direction: row; height: calc(100vh - 220px); min-height: 560px; border: 1px solid rgba(0,0,0,0.12); border-radius: 4px; overflow: hidden; }
  #gmk-app .gmk-desk__list, #gmk-app .gmk-desk--open .gmk-desk__list { display: flex; width: 420px; flex: 0 0 420px; border-right: 1px solid rgba(0,0,0,0.12); }
  #gmk-app .gmk-desk__scroll { flex: 1 1 auto; overflow-y: auto; }
  #gmk-app .gmk-desk__chat, #gmk-app .gmk-desk--open .gmk-desk__chat { display: flex; position: static; flex: 1 1 auto; z-index: auto; padding: 0; }
  #gmk-app .gmk-desk__composer { padding-bottom: 12px !important; }
}`;
    const style = document.createElement('style');
    style.id = 'gmk-sup-css';
    style.textContent = css;
    document.head.appendChild(style);
})();

/**
 * Pone data-label (texto del encabezado de su columna) en cada celda de las
 * tablas .gmk-stack de `root`, para mostrarlas como tarjetas en el celular.
 */
window.gmkSupportLabelTables = function (root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('.gmk-stack table').forEach(function (table) {
        const heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
            return (th.textContent || '').trim();
        });
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            Array.prototype.forEach.call(tr.children, function (td, i) {
                const label = heads[i] || '';
                if (td.getAttribute('data-label') !== label) td.setAttribute('data-label', label);
            });
        });
    });
};
