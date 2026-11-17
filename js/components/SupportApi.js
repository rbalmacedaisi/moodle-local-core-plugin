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
