/**
 * support_badge.js
 * Mesa de ayuda, fase 2 (20261001121, PLAN_TICKETS_SOPORTE.md §8.2, §8.4, §8.5).
 *
 * Lo inyecta local_grupomakro_core_before_footer() en todas las paginas de
 * Moodle, solo para el personal de la mesa de ayuda y para docentes. Sin
 * dependencias (no carga Vue). Un unico servicio: local_grupomakro_support_get_badge.
 *
 * Personal:
 *  - Pildora abajo a la derecha: Sin asignar / Mios / Vencidos y Disponible/Ausente.
 *  - Avisos que no se cierran solos, con "Abrir" y "Tomar". "Ocultar" solo los
 *    esconde en esta pagina: vuelven en la siguiente hasta el acuse.
 *  - Modo incisivo segun el perfil que manda el servidor (ya apagado si la
 *    persona esta ausente o fuera de horario):
 *      capa 1 bloqueo a pantalla completa (Abrir / Tomar / Posponer, maximo N);
 *      capa 2 sonido cada 30 s + titulo y favicon parpadeando;
 *      capa 3 Notification API con requireInteraction.
 *  - Sondeo cada `poll` s solo con la pestana visible; en segundo plano, cada
 *    60 s y solo si el perfil tiene sonido o notificaciones del sistema (capas 2
 *    y 3 existen justo para la pestana en segundo plano).
 * Docente: boton "Ayuda TI" y aviso persistente cuando TI responde.
 *
 * Privacidad: el servidor nunca manda el texto de los mensajes; los tickets
 * confidenciales llegan como "Ticket confidencial".
 */
(function () {
    'use strict';
    if (window.gmkSupportBadgeLoaded) {
        return;
    }
    window.gmkSupportBadgeLoaded = true;

    var cfg = window.gmkSupportBadgeConfig || {};
    var M = window.M || {};
    var wwwroot = (M.cfg && M.cfg.wwwroot) || window.location.origin;
    var sesskey = (M.cfg && M.cfg.sesskey) || '';

    var state = {
        data: null,
        timer: null,
        soundTimer: null,
        blinkTimer: null,
        hidden: {},          // alert keys hidden on this page
        notified: loadSet('gmkTktNotified'),
        seenKeys: null,      // keys present on the previous poll
        originalTitle: document.title,
        originalIcon: null,
        audio: null,
        dashOpen: false,
        lockKey: null,
        busy: false
    };

    function loadSet(name) {
        try {
            return JSON.parse(window.sessionStorage.getItem(name) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function saveSet(name, obj) {
        try {
            window.sessionStorage.setItem(name, JSON.stringify(obj));
        } catch (e) {
            // Private mode: fine.
        }
    }

    function call(methodname, args) {
        var url = wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(sesskey)
            + '&info=' + encodeURIComponent(methodname);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify([{index: 0, methodname: methodname, args: args || {}}])
        }).then(function (r) {
            return r.json();
        }).then(function (resp) {
            var res = Array.isArray(resp) ? resp[0] : null;
            if (!res) {
                throw new Error((resp && (resp.error || resp.message)) || 'Sin respuesta');
            }
            if (res.error) {
                throw new Error((res.exception && res.exception.message) || 'Error del servidor');
            }
            return res.data;
        });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (attrs[k] === null || attrs[k] === undefined) {
                return;
            }
            if (k === 'class') {
                node.className = attrs[k];
            } else if (k === 'text') {
                node.textContent = attrs[k];
            } else if (k.indexOf('on') === 0) {
                node.addEventListener(k.substring(2), attrs[k]);
            } else {
                node.setAttribute(k, attrs[k]);
            }
        });
        (children || []).forEach(function (c) {
            if (c) {
                node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
            }
        });
        return node;
    }

    function waiting(min) {
        min = parseInt(min, 10) || 0;
        if (min < 60) {
            return min + ' min';
        }
        var h = Math.floor(min / 60);
        return h + ' h' + (min % 60 ? ' ' + (min % 60) + ' min' : '');
    }

    var KIND = {
        'new': 'Ticket nuevo sin asignar',
        'assigned': 'Se te asignó un ticket',
        'message': 'Mensaje nuevo del solicitante',
        'overdue': 'Ticket atrasado'
    };
    var COLOR = {green: '#43a047', green_warn: '#9ccc65', yellow: '#fbc02d', orange: '#fb8c00', red: '#e53935'};

    function deskUrl(params) {
        var q = Object.keys(params || {}).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return cfg.deskurl + (q ? '?' + q : '');
    }

    function myUrl(params) {
        var q = Object.keys(params || {}).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return cfg.myurl + (q ? '?' + q : '');
    }

    // ------------------------------------------------------------------ styles
    function injectStyles() {
        // Mobile first: base para 360-400 px; el escritorio se agrega con min-width.
        var css = ''
            + '.gmk-tkt-root{position:fixed;right:calc(8px + env(safe-area-inset-right));bottom:calc(8px + env(safe-area-inset-bottom));'
            + 'z-index:1040;display:flex;flex-direction:column;align-items:flex-end;gap:8px;'
            + 'font:15px/1.35 Roboto,"Segoe UI",Arial,sans-serif;max-width:calc(100vw - 16px - env(safe-area-inset-left) - env(safe-area-inset-right))}'
            + '.gmk-tkt-pill{display:flex;align-items:center;background:#263238;color:#fff;border-radius:24px;'
            + 'box-shadow:0 4px 14px rgba(0,0,0,.3);overflow:hidden;max-width:100%}'
            + '.gmk-tkt-pill a,.gmk-tkt-pill button{color:#fff;text-decoration:none;background:none;border:0;cursor:pointer;'
            + 'min-height:44px;min-width:40px;padding:0 9px;font:inherit;display:flex;align-items:center;justify-content:center;gap:5px;white-space:nowrap}'
            + '.gmk-tkt-pill a:hover,.gmk-tkt-pill button:hover,.gmk-tkt-pill a:focus-visible,.gmk-tkt-pill button:focus-visible{background:rgba(255,255,255,.14)}'
            + '.gmk-tkt-label{display:none}'
            + '.gmk-tkt-n{background:rgba(255,255,255,.18);border-radius:10px;padding:0 7px;font-weight:600;min-width:22px;text-align:center}'
            + '.gmk-tkt-n.hot{background:#e53935}'
            + '.gmk-tkt-dot{width:10px;height:10px;border-radius:50%;display:inline-block}'
            + '.gmk-tkt-pulse{animation:gmkTktPulse 1.2s infinite}'
            + '@keyframes gmkTktPulse{0%{box-shadow:0 0 0 0 rgba(229,57,53,.8)}70%{box-shadow:0 0 0 14px rgba(229,57,53,0)}100%{box-shadow:0 0 0 0 rgba(229,57,53,0)}}'
            + '@media (prefers-reduced-motion:reduce){.gmk-tkt-pulse{animation:none;box-shadow:0 0 0 3px #e53935}}'
            + '.gmk-tkt-toast{background:#fff;color:#212121;border-left:6px solid #e53935;border-radius:8px;'
            + 'box-shadow:0 6px 18px rgba(0,0,0,.28);padding:10px 12px;width:100%;box-sizing:border-box;overflow-wrap:anywhere}'
            + '.gmk-tkt-toast b{display:block;margin-bottom:2px}'
            + '.gmk-tkt-toast .meta,.gmk-tkt-more{color:#555;font-size:13px}'
            + '.gmk-tkt-more{background:#fff;border-radius:16px;padding:4px 12px;box-shadow:0 2px 8px rgba(0,0,0,.2)}'
            + '.gmk-tkt-actions{display:flex;gap:6px;margin-top:8px;justify-content:flex-end;flex-wrap:wrap}'
            + '.gmk-tkt-btn{border:0;border-radius:6px;min-height:44px;padding:0 14px;cursor:pointer;font:500 15px Roboto,Arial,sans-serif;'
            + 'background:#1976d2;color:#fff}'
            + '.gmk-tkt-btn.alt{background:#eceff1;color:#263238}'
            + '.gmk-tkt-btn.warn{background:#e53935}'
            + '.gmk-tkt-btn[disabled]{opacity:.45;cursor:not-allowed}'
            + '.gmk-tkt-dash{background:#fff;color:#212121;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.28);'
            + 'padding:10px 12px;width:100%;box-sizing:border-box;font-size:14px}'
            + '.gmk-tkt-dash table{width:100%;border-collapse:collapse}.gmk-tkt-dash td,.gmk-tkt-dash th{padding:4px;text-align:right}'
            + '.gmk-tkt-dash td:first-child,.gmk-tkt-dash th:first-child{text-align:left}'
            + '.gmk-tkt-lock{position:fixed;top:0;right:0;bottom:0;left:0;z-index:100000;background:rgba(183,28,28,.96);color:#fff;display:flex;'
            + 'align-items:center;justify-content:center;font:16px/1.4 Roboto,"Segoe UI",Arial,sans-serif;overflow-y:auto;'
            + 'padding:calc(16px + env(safe-area-inset-top)) calc(16px + env(safe-area-inset-right)) calc(16px + env(safe-area-inset-bottom)) calc(16px + env(safe-area-inset-left))}'
            + '.gmk-tkt-lock .box{max-width:560px;width:100%;text-align:center;overflow-wrap:anywhere}'
            + '.gmk-tkt-lock h2{font-size:22px;margin:0 0 8px;color:#fff}'
            + '.gmk-tkt-lock .num{font-size:19px;font-weight:700;margin:8px 0}'
            + '.gmk-tkt-lock .gmk-tkt-actions{flex-direction:column;align-items:stretch;margin-top:22px}'
            + '.gmk-tkt-lock .gmk-tkt-btn{font-size:16px;min-height:52px;width:100%}'
            + '.gmk-tkt-lock .gmk-tkt-btn.alt{background:#fff;color:#b71c1c}'
            + '.gmk-tkt-modal{position:fixed;top:0;right:0;bottom:0;left:0;z-index:99990;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;'
            + 'justify-content:center;font:16px/1.4 Roboto,"Segoe UI",Arial,sans-serif}'
            + '.gmk-tkt-modal .box{background:#fff;color:#212121;border-radius:12px 12px 0 0;width:100%;overflow:hidden;'
            + 'padding-bottom:env(safe-area-inset-bottom)}'
            + '.gmk-tkt-modal .head{background:#1565c0;color:#fff;padding:12px 16px;font-size:18px;font-weight:500}'
            + '.gmk-tkt-modal .body{padding:14px 16px}'
            + '.gmk-tkt-modal .gmk-tkt-actions{padding:0 16px 14px}'
            + '.gmk-tkt-modal .gmk-tkt-btn{flex:1 1 auto}'
            + '.gmk-tkt-help{background:#1565c0 !important}'
            + '@media (min-width:600px){'
            + '.gmk-tkt-root{right:calc(16px + env(safe-area-inset-right));bottom:calc(16px + env(safe-area-inset-bottom));font-size:14px}'
            + '.gmk-tkt-label{display:inline}'
            + '.gmk-tkt-pill a,.gmk-tkt-pill button{min-height:38px;padding:0 11px}'
            + '.gmk-tkt-toast,.gmk-tkt-dash{width:340px}'
            + '.gmk-tkt-btn{min-height:34px;font-size:13px;padding:0 12px}'
            + '.gmk-tkt-lock h2{font-size:28px}.gmk-tkt-lock .num{font-size:22px}'
            + '.gmk-tkt-lock .gmk-tkt-actions{flex-direction:row;justify-content:center;align-items:center}'
            + '.gmk-tkt-lock .gmk-tkt-btn{width:auto;min-height:48px;padding:0 20px}'
            + '.gmk-tkt-modal{align-items:center;padding:16px}'
            + '.gmk-tkt-modal .box{max-width:480px;border-radius:8px;padding-bottom:0}'
            + '.gmk-tkt-modal .gmk-tkt-btn{flex:0 0 auto}'
            + '}';
        document.head.appendChild(el('style', {id: 'gmk-tkt-style', text: css}));
    }

    // ----------------------------------------------------------------- sound
    var SOUNDS = {
        alarma: [[880, 0, 0.18], [660, 0.22, 0.18], [880, 0.44, 0.18], [660, 0.66, 0.18]],
        campana: [[1046, 0, 0.5], [784, 0.25, 0.7]],
        pitido: [[1000, 0, 0.15], [1000, 0.3, 0.15]]
    };

    function audioCtx() {
        if (!state.audio) {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return null;
            }
            state.audio = new Ctx();
        }
        return state.audio;
    }

    function unlockAudio() {
        var ctx = audioCtx();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function beep(name) {
        var ctx = audioCtx();
        if (!ctx) {
            return;
        }
        if (ctx.state === 'suspended') {
            // Browsers keep audio blocked until the first click/key on the page.
            ctx.resume().catch(function () {});
        }
        (SOUNDS[name] || SOUNDS.campana).forEach(function (n) {
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            o.type = 'sine';
            o.frequency.value = n[0];
            var t0 = ctx.currentTime + n[1];
            g.gain.setValueAtTime(0.0001, t0);
            g.gain.exponentialRampToValueAtTime(0.35, t0 + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + n[2]);
            o.connect(g);
            g.connect(ctx.destination);
            o.start(t0);
            o.stop(t0 + n[2] + 0.05);
        });
    }

    // --------------------------------------------------------- title / favicon
    function iconLink() {
        return document.querySelector('link[rel~="icon"]');
    }

    function setFaviconDot(on) {
        var link = iconLink();
        if (!link) {
            link = el('link', {rel: 'icon', href: wwwroot + '/favicon.ico'});
            document.head.appendChild(link);
        }
        if (state.originalIcon === null) {
            state.originalIcon = link.href;
        }
        if (!on) {
            if (link.href !== state.originalIcon) {
                link.href = state.originalIcon;
            }
            return;
        }
        if (link.dataset.gmkDot === '1') {
            return;
        }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            try {
                var c = document.createElement('canvas');
                c.width = c.height = 32;
                var x = c.getContext('2d');
                x.drawImage(img, 0, 0, 32, 32);
                x.fillStyle = '#e53935';
                x.beginPath();
                x.arc(23, 9, 9, 0, 2 * Math.PI);
                x.fill();
                link.href = c.toDataURL('image/png');
                link.dataset.gmkDot = '1';
            } catch (e) {
                link.href = redDotIcon();
                link.dataset.gmkDot = '1';
            }
        };
        img.onerror = function () {
            link.href = redDotIcon();
            link.dataset.gmkDot = '1';
        };
        img.src = state.originalIcon;
    }

    function clearFaviconDot() {
        var link = iconLink();
        if (link && link.dataset.gmkDot === '1') {
            link.href = state.originalIcon;
            link.dataset.gmkDot = '';
        }
    }

    function redDotIcon() {
        return 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32">'
            + '<circle cx="16" cy="16" r="14" fill="#e53935"/></svg>');
    }

    function startBlink(text) {
        stopBlink();
        var on = false;
        state.blinkTimer = setInterval(function () {
            on = !on;
            document.title = on ? text : state.originalTitle;
        }, 1000);
    }

    function stopBlink() {
        if (state.blinkTimer) {
            clearInterval(state.blinkTimer);
            state.blinkTimer = null;
            document.title = state.originalTitle;
        }
    }

    // ---------------------------------------------------------- OS notification
    function osNotify(alert) {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }
        try {
            var n = new Notification(alert.number + ' · ' + alert.areaname, {
                body: (KIND[alert.kind] || 'Ticket') + ' · ' + (alert.who || alert.categoryname) + ' · espera '
                    + waiting(alert.waitingminutes),
                tag: 'gmk-tkt-' + alert.key,
                requireInteraction: true
            });
            n.onclick = function () {
                window.focus();
                window.location.href = deskUrl({ticket: alert.ticketid});
                n.close();
            };
        } catch (e) {
            // Some browsers only allow notifications from a service worker.
        }
    }

    function askPermission() {
        if (!('Notification' in window)) {
            return;
        }
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(render);
        } else if (Notification.permission === 'denied') {
            window.alert('Las notificaciones del sistema están bloqueadas para este sitio. Actívalas desde el candado '
                + 'de la barra de direcciones (Notificaciones: Permitir) y recarga la página.');
        }
    }

    // ------------------------------------------------------------- actions
    function openTicket(a) {
        window.location.href = deskUrl({ticket: a.ticketid});
    }

    function takeTicket(a) {
        if (state.busy) {
            return;
        }
        state.busy = true;
        call('local_grupomakro_support_desk_action', {
            ticketid: a.ticketid, action: 'take', targetuserid: 0, priority: -1, categoryid: 0
        }).then(function () {
            window.location.href = deskUrl({ticket: a.ticketid});
        }).catch(function (e) {
            state.busy = false;
            window.alert(e.message);
            refresh();
        });
    }

    function snooze(a) {
        if (state.busy) {
            return;
        }
        state.busy = true;
        call('local_grupomakro_support_snooze', {ticketid: a.ticketid}).then(function () {
            state.busy = false;
            refresh();
        }).catch(function (e) {
            state.busy = false;
            window.alert(e.message);
        });
    }

    function setAvailable(value) {
        call('local_grupomakro_support_set_availability', {available: !!value}).then(refresh).catch(function (e) {
            window.alert(e.message);
        });
    }

    // ------------------------------------------------------------- render
    var root = null;

    function render() {
        var d = state.data;
        if (!root) {
            root = el('div', {'class': 'gmk-tkt-root', id: 'gmk-tkt-root', 'aria-live': 'polite'});
            document.body.appendChild(root);
        }
        root.innerHTML = '';
        removeLock();
        if (!d) {
            return;
        }
        var staff = d.staff;
        var alerts = staff ? staff.alerts.filter(function (a) {
            return !state.hidden[a.key];
        }) : [];

        // Toasts (staff).
        if (staff) {
            var shown = alerts.filter(function (a) {
                return !(a.lock && !a.snoozed && staff.profile.lock !== 'none'); // Those go to the lock.
            });
            // Celular: un solo aviso a la vez para no tapar la pagina; escritorio hasta 4.
            var max = window.matchMedia && window.matchMedia('(min-width: 600px)').matches ? 4 : 1;
            shown.slice(0, max).forEach(function (a) {
                root.appendChild(toast(a, staff));
            });
            if (shown.length > max) {
                root.appendChild(el('a', {'class': 'gmk-tkt-more', href: deskUrl({tab: 'mine'}),
                    text: '+' + (shown.length - max) + ' aviso(s) más'}));
            }
            if (state.dashOpen && staff.dashboard) {
                root.appendChild(dashboard(staff.dashboard));
            }
        }

        // Teacher: replies and ratings.
        var req = d.requester;
        if (req && !cfg.onmy) {
            renderRequesterModal(req);
        }

        var bar = el('div', {'class': 'gmk-tkt-pill' + (staff && staff.alerts.length ? ' gmk-tkt-pulse' : '')});
        if (req && req.teacher) {
            var unread = (req.replies || []).filter(function (r) {
                return r.unread > 0;
            }).length;
            bar.appendChild(el('a', {href: cfg.myurl, 'class': 'gmk-tkt-help', title: 'Abrir una solicitud a Soporte Tecnológico'}, [
                el('span', {text: '🛟'}), el('span', {'class': 'gmk-tkt-label', text: 'Ayuda TI'}),
                unread ? el('span', {'class': 'gmk-tkt-n hot', text: String(unread)}) : null
            ]));
        }
        if (staff) {
            bar.appendChild(el('button', {
                title: staff.available ? 'Disponible: clic para marcarte ausente' : 'Ausente: clic para marcarte disponible',
                'aria-label': staff.available ? 'Disponible (marcarme ausente)' : 'Ausente (marcarme disponible)',
                onclick: function () {
                    setAvailable(!staff.available);
                }
            }, [el('span', {'class': 'gmk-tkt-dot', style: 'background:' + (staff.available ? '#66bb6a' : '#9e9e9e')}),
                el('span', {'class': 'gmk-tkt-label', text: staff.available ? 'Disponible' : 'Ausente'})]));
            bar.appendChild(counter('Sin asignar', staff.unassigned, 'unassigned', staff.unassigned > 0));
            bar.appendChild(counter('Míos', staff.mine, 'mine', staff.mineunread > 0,
                staff.mineunread ? staff.mineunread + ' nuevos' : ''));
            bar.appendChild(counter('Vencidos', staff.overdue, 'overdue', staff.overdue > 0));
            if (staff.profile.osnotify && 'Notification' in window && Notification.permission !== 'granted') {
                bar.appendChild(el('button', {
                    title: 'Activar las notificaciones del sistema para no perder tickets',
                    onclick: askPermission
                }, [el('span', {text: '🔔'}), el('span', {'class': 'gmk-tkt-label', text: 'Activar avisos'})]));
            }
            if (staff.dashboard) {
                bar.appendChild(el('button', {
                    title: 'Totales por área', 'aria-label': 'Totales por área', 'aria-expanded': state.dashOpen ? 'true' : 'false',
                    onclick: function () {
                        state.dashOpen = !state.dashOpen;
                        render();
                    }
                }, [el('span', {text: state.dashOpen ? '▾' : '▴'})]));
            }
        }
        if (bar.childNodes.length) {
            root.appendChild(bar);
        }
        reserveSpace(bar.childNodes.length ? bar : null);

        // Lock (layer 1).
        if (staff && staff.profile.lock !== 'none') {
            var lockable = staff.alerts.filter(function (a) {
                return a.lock && !a.snoozed;
            });
            if (lockable.length) {
                showLock(lockable[0], lockable.length, staff);
            }
        }
        applyLayers();
    }

    /**
     * Leaves room at the end of the page so the pill never covers the last
     * content (buttons at the bottom of a form, the footer).
     */
    var basePadding = null;
    function reserveSpace(bar) {
        if (basePadding === null) {
            basePadding = parseFloat(window.getComputedStyle(document.body).paddingBottom) || 0;
        }
        var need = bar ? bar.offsetHeight + 24 : 0;
        document.body.style.paddingBottom = (basePadding + need) + 'px';
    }

    function counter(label, value, tab, hot, extra) {
        // Celular: solo el numero (rojo si hay novedades); escritorio: etiqueta y detalle.
        return el('a', {href: deskUrl({tab: tab}), title: label + (extra ? ' (' + extra + ')' : ''),
            'aria-label': label + ': ' + value + (extra ? ', ' + extra : '')}, [
            el('span', {'class': 'gmk-tkt-label', text: label}),
            el('span', {'class': 'gmk-tkt-n' + (hot ? ' hot' : ''), text: String(value)}),
            extra ? el('span', {'class': 'gmk-tkt-label', text: extra}) : null
        ]);
    }

    function toast(a, staff) {
        var color = COLOR[a.semaphore] || '#e53935';
        return el('div', {'class': 'gmk-tkt-toast', style: 'border-left-color:' + color, role: 'alert'}, [
            el('b', {text: (KIND[a.kind] || 'Ticket') + ' · ' + a.number}),
            el('div', {'class': 'meta', text: a.areaname + ' / ' + a.categoryname + ' · ' + (a.who || '')
                + (a.requesterrole === 'teacher' ? ' (docente)' : '')}),
            el('div', {'class': 'meta', text: 'Espera ' + waiting(a.waitingminutes) + ' hábiles'}),
            el('div', {'class': 'gmk-tkt-actions'}, [
                el('button', {'class': 'gmk-tkt-btn alt', text: 'Ocultar', title: 'Vuelve a aparecer en la próxima página hasta que lo abras o lo tomes',
                    onclick: function () {
                        state.hidden[a.key] = true;
                        render();
                    }}),
                a.cantake ? el('button', {'class': 'gmk-tkt-btn alt', text: 'Tomar', onclick: function () {
                    takeTicket(a);
                }}) : null,
                el('button', {'class': 'gmk-tkt-btn', text: 'Abrir', onclick: function () {
                    openTicket(a);
                }})
            ])
        ]);
    }

    function dashboard(dash) {
        var rows = (dash.areas || []).map(function (a) {
            return el('tr', {}, [el('td', {text: a.name}), el('td', {text: String(a.open)}),
                el('td', {text: String(a.unassigned)}), el('td', {text: String(a.overdue)})]);
        });
        return el('div', {'class': 'gmk-tkt-dash'}, [
            el('b', {text: 'Mesa de ayuda en vivo'}),
            el('table', {}, [el('tr', {}, [el('th', {text: 'Área'}), el('th', {text: 'Abiertos'}), el('th', {text: 'Sin asignar'}),
                el('th', {text: 'Vencidos'})])].concat(rows)),
            dash.topagent ? el('div', {'class': 'meta', style: 'margin-top:6px',
                text: 'Más pendientes: ' + dash.topagent.name + ' (' + dash.topagent.open + ')'}) : null
        ]);
    }

    // ------------------------------------------------------------- lock
    var lockNode = null;

    function removeLock() {
        if (lockNode) {
            lockNode.remove();
            lockNode = null;
            document.removeEventListener('keydown', trapKeys, true);
        }
    }

    function trapKeys(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
        }
        if (e.key === 'Tab' && lockNode) {
            var f = lockNode.querySelectorAll('button:not([disabled])');
            if (!f.length) {
                return;
            }
            var first = f[0];
            var last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            } else if (!lockNode.contains(document.activeElement)) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function showLock(a, total, staff) {
        var left = Math.max(0, staff.snoozemax - (a.snoozes || 0));
        var openBtn = el('button', {'class': 'gmk-tkt-btn alt', text: 'Abrir chat', onclick: function () {
            openTicket(a);
        }});
        lockNode = el('div', {'class': 'gmk-tkt-lock', role: 'alertdialog', 'aria-modal': 'true',
            'aria-label': 'Ticket pendiente de atención'}, [
            el('div', {'class': 'box'}, [
                el('h2', {text: KIND[a.kind] || 'Ticket pendiente'}),
                el('div', {'class': 'num', text: a.number + ' · ' + a.areaname}),
                el('div', {text: a.categoryname + ' · ' + (a.who || '') + (a.requesterrole === 'teacher' ? ' (docente)' : '')}),
                el('div', {text: 'Esperando ' + waiting(a.waitingminutes) + ' hábiles'
                    + (total > 1 ? ' · hay ' + total + ' tickets pendientes' : '')}),
                el('div', {'class': 'gmk-tkt-actions'}, [
                    openBtn,
                    a.cantake ? el('button', {'class': 'gmk-tkt-btn alt', text: 'Tomar', onclick: function () {
                        takeTicket(a);
                    }}) : null,
                    el('button', {'class': 'gmk-tkt-btn warn', text: left > 0
                        ? 'Posponer ' + staff.snoozeminutes + ' min (quedan ' + left + ')' : 'Ya no se puede posponer',
                    disabled: left > 0 ? null : 'disabled', onclick: function () {
                        if (left > 0) {
                            snooze(a);
                        }
                    }})
                ])
            ])
        ]);
        // Clicking outside does nothing on purpose.
        document.body.appendChild(lockNode);
        document.addEventListener('keydown', trapKeys, true);
        setTimeout(function () {
            openBtn.focus();
        }, 50);
    }

    // ------------------------------------------------- requester (teacher) modal
    var reqNode = null;

    function renderRequesterModal(req) {
        if (reqNode) {
            reqNode.remove();
            reqNode = null;
        }
        var seenWaiting = loadSet('gmkTktWaitingSeen');
        var reply = (req.replies || []).filter(function (r) {
            return r.unread > 0 || (r.waiting && !seenWaiting[r.id]);
        })[0];
        if (reply) {
            reqNode = el('div', {'class': 'gmk-tkt-modal', role: 'alertdialog', 'aria-modal': 'true'}, [
                el('div', {'class': 'box'}, [
                    el('div', {'class': 'head', text: reply.waiting && !reply.unread ? 'Te pedimos información' : 'Nueva respuesta'}),
                    el('div', {'class': 'body', text: reply.areaname + (reply.waiting && !reply.unread
                        ? ' espera tu respuesta en la solicitud ' : ' respondió tu solicitud ') + reply.number + '.'}),
                    el('div', {'class': 'gmk-tkt-actions'}, [el('button', {'class': 'gmk-tkt-btn', text: 'Ver respuesta',
                        onclick: function () {
                            seenWaiting[reply.id] = 1;
                            saveSet('gmkTktWaitingSeen', seenWaiting);
                            window.location.href = myUrl({ticket: reply.id});
                        }})])
                ])
            ]);
            document.body.appendChild(reqNode);
            return;
        }
        var rating = (req.ratings || [])[0];
        if (rating) {
            reqNode = el('div', {'class': 'gmk-tkt-modal', role: 'dialog', 'aria-modal': 'true'}, [
                el('div', {'class': 'box'}, [
                    el('div', {'class': 'head', text: '¿Cómo te atendimos?'}),
                    el('div', {'class': 'body', text: 'Tu solicitud ' + rating.number + ' (' + rating.areaname
                        + ') fue cerrada. Califica la atención: toma menos de un minuto.'}),
                    el('div', {'class': 'gmk-tkt-actions'}, [
                        el('button', {'class': 'gmk-tkt-btn alt', text: 'Ahora no', onclick: function () {
                            call('local_grupomakro_support_rate_ticket', {ticketid: rating.id, dismiss: true}).then(refresh)
                                .catch(function () {
                                    reqNode.remove();
                                });
                        }}),
                        el('button', {'class': 'gmk-tkt-btn', text: 'Calificar', onclick: function () {
                            window.location.href = myUrl({ticket: rating.id, calificar: 1});
                        }})
                    ])
                ])
            ]);
            document.body.appendChild(reqNode);
        }
    }

    // ------------------------------------------------------------- layers 2-3
    function applyLayers() {
        var staff = state.data && state.data.staff;
        var alerts = staff ? staff.alerts : [];
        var keys = {};
        alerts.forEach(function (a) {
            keys[a.key] = a;
        });
        var fresh = alerts.filter(function (a) {
            return !state.seenKeys || !state.seenKeys[a.key];
        });
        state.seenKeys = keys;

        if (!staff || !alerts.length) {
            stopSound();
            stopBlink();
            clearFaviconDot();
            return;
        }
        var p = staff.profile;
        if (p.title) {
            var area = alerts[0].areaname || 'Mesa de ayuda';
            startBlink('🔴 (' + alerts.length + ') ' + (alerts.length > 1 ? 'Tickets pendientes' : (KIND[alerts[0].kind] || 'Ticket'))
                + ' – ' + area);
            setFaviconDot(true);
        } else {
            stopBlink();
            clearFaviconDot();
        }
        if (p.sound) {
            if (fresh.length) {
                beep(p.soundname);
            }
            if (!state.soundTimer) {
                state.soundTimer = setInterval(function () {
                    var s = state.data && state.data.staff;
                    if (s && s.alerts.length && s.profile.sound) {
                        beep(s.profile.soundname);
                    } else {
                        stopSound();
                    }
                }, 30000);
            }
        } else {
            stopSound();
        }
        if (p.osnotify) {
            fresh.forEach(function (a) {
                if (!state.notified[a.key]) {
                    state.notified[a.key] = Date.now();
                    osNotify(a);
                }
            });
            saveSet('gmkTktNotified', state.notified);
        }
    }

    function stopSound() {
        if (state.soundTimer) {
            clearInterval(state.soundTimer);
            state.soundTimer = null;
        }
    }

    // ------------------------------------------------------------- polling
    function refresh() {
        return call('local_grupomakro_support_get_badge', {}).then(function (res) {
            state.data = JSON.parse(res.json);
            render();
            schedule();
        }).catch(function () {
            schedule(true);
        });
    }

    function schedule(error) {
        clearTimeout(state.timer);
        var d = state.data;
        var poll = ((d && d.poll) || 30) * 1000;
        var p = d && d.staff && d.staff.profile;
        var background = p && (p.sound || p.osnotify);
        var delay;
        if (document.hidden) {
            if (!background) {
                return; // Resumes on visibilitychange.
            }
            delay = Math.max(poll, 60000);
        } else {
            delay = poll;
        }
        if (error) {
            delay = Math.max(delay, 60000);
        }
        state.timer = setTimeout(refresh, delay);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refresh();
        } else {
            schedule();
        }
    });
    ['click', 'keydown'].forEach(function (ev) {
        document.addEventListener(ev, unlockAudio, {once: true, capture: true});
    });

    function start() {
        injectStyles();
        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
