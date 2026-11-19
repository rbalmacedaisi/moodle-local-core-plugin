# Pruebas: cartas con bandeja, ANS, alertas y vínculo con la mesa de ayuda (20261001130)

Ramas: plugin `feat/cartas-tickets-ans` (desde `origin/master` c20635cc) y portal `feat/cartas-tickets-ans` (desde `origin/isi-prod` 3f54d43).
Plan y decisiones: `PLAN_CARTAS_OPTIMIZACION.md`.

## 1. Lo que ya se verificó (sin tocar staging ni producción)

| Prueba | Resultado |
|---|---|
| `php -l` de todo lo tocado (PHP 8.2 local; sin sintaxis exclusiva de PHP 8) | OK |
| `install.xml` bien formado (6 tablas `gmk_letter_*` + `gmk_tkt_ticket.letterrequestid`) | OK |
| `node --check` de `LetterDesk.js`, `SupportDesk.js`, `SupportSettings.js` | OK |
| Arnés aislado de lógica pura: niveles del ANS (75/100/200 %), plazos por tipo sobre el general, pasos que ve el estudiante (física, con pago, rechazada), transiciones nuevas | 22/22 |
| `nuxt build` del portal | OK |
| Bandeja a 375 px con datos simulados: lista con semáforo, pestañas con contadores, detalle con la pregunta "¿Ya se gestionó?", diálogo de carga a pantalla completa, sin desborde horizontal | OK (se corrigió que diálogos y menús quedaban detrás del detalle fijo) |

## 2. Prueba automática con rollback (staging, con OK de Ramiro)

```
sudo -u www-data php /opt/moodle_staging/moodle/admin/cli/upgrade.php --non-interactive
sudo -u www-data php /opt/moodle_staging/moodle/local/grupomakro_core/cli/letters_selftest.php --student=<estudiante de prueba> [--staff=2732]
```

Todo dentro de una transacción que se revierte; compara conteos antes y después. No llama a Odoo. Recorre:
alta con responsable y plazo · duplicada rechazada · "Hablar de esta carta" (ticket ligado, asignado al responsable, reutilizado) · otro usuario no puede · "Escribir al estudiante" en el mismo ticket · carta visible en la bandeja de tickets (personal y estudiante) · bandeja: pestaña "Mías", búsqueda por #, detalle, acciones válidas, rechazar sin motivo no · nota interna · masiva simulada no cambia nada · ANS: por vencer → vencida → crítica, una alerta por nivel, sin repetir · métricas del dashboard (mías, equipo, por responsable) · carga: .exe rechazado, PDF cargado → lista para retirar, ANS reiniciado, copia a Odoo en cola, mensaje automático en el ticket, el estudiante la descarga · entregada → ticket resuelto · anterior a la bandeja: se asigna, alerta crítica, "Sí, ya se entregó" la cierra · el estudiante anula la suya (otro no) · pago: recordatorio a los 3 días (una vez), anulación a los 15, pago tardío reactiva y genera, webhook idempotente · duplicadas: se conserva la más reciente, simulación no cambia nada, anulación reversible · resumen por tipo.

Antes del upgrade, en producción (solo lectura, ya con el código desplegado en una copia o tras el push):
```
sudo -u www-data php /var/www/html/moodle/local/grupomakro_core/cli/letters_backlog.php
```
Lista lo que hará el upgrade con el atraso. Con los datos del 19-nov se espera: **15 duplicadas anuladas en silencio**, **14 anteriores** con la pregunta "¿ya se gestionó?" (todas críticas desde la primera pasada), **2 pagos anulados por 15 días** (la tercera era duplicada), equipo: Jean Remice, Veronica Rangel, Lizbeth Aizprua.

## 3. Pruebas manuales en producción (tras el push, con estudiante de prueba)

| # | Quién | Pasos | Esperado |
|---|---|---|---|
| 1 | Personal | Menú → "Bandeja de Cartas" (en el celular) | Abre `letter_desk.php`; pestañas con contadores; "Anteriores" muestra las 14 |
| 2 | Personal | Abrir una anterior → "Sí, ya se entregó" | Pasa a Entregada, sale de "Anteriores"; el estudiante recibe el aviso de entregada |
| 3 | Personal | Otra anterior → "No, gestionar ahora" → "Cargar carta" (PDF) → "Queda lista para retirar" | Estado Lista para retirar; el estudiante recibe aviso y ve dónde retirarla |
| 4 | Personal | Cargar un .docx en una carta digital → "El estudiante la descarga" | Lista para descargar; el estudiante descarga el Word desde el portal |
| 5 | Estudiante | Portal → Solicitudes de cartas → pedir práctica profesional | Tarjeta "La está preparando Secretaría Académica", pasos y fecha estimada; al personal le llega "Nueva solicitud de carta" |
| 6 | Estudiante | Pedir la misma otra vez | Aviso "Ya tienes esta carta en trámite"; no se crea |
| 7 | Estudiante | "Hablar de esta carta" → escribir | Abre la Mesa de ayuda en el ticket, con la tarjeta de la carta arriba; el ticket lo tiene el responsable de la carta |
| 8 | Personal | Bandeja de tickets | El ticket muestra "Carta #N · estado"; el chip abre la carta |
| 9 | Personal | Marcar la carta entregada | En el ticket aparece el mensaje automático y pasa a "resuelto" |
| 10 | Estudiante | Mesa de ayuda → Académico → "Necesito una carta o constancia" | Botón "Ir a Solicitudes de Cartas" abre la página en la misma pestaña |
| 11 | Personal | Seleccionar varias → "Entregadas" | Muestra cuántas cambian y cuáles se saltan; confirma; resultado por carta |
| 12 | Personal | Catálogo de cartas → editar práctica profesional | Campos de plazo (días hábiles) y "Dónde y cuándo se retira" |
| 13 | Gerencia / supervisores | Esperar la primera pasada de `letters_sla` | Un solo mensaje agrupado con las cartas críticas, no uno por carta |

## 4. Reversión

- Código: revertir el merge; las columnas nuevas quedan (aditivas, con valores por defecto) y no molestan al código anterior.
- Duplicadas anuladas: cada una tiene un evento `status_change` con `oldstatus` y `metadata.duplicate_of`; se pueden devolver a su estado con ese dato.
- Alertas: `set_config('letters_alerts_enabled', 0, 'local_grupomakro_core')` apaga la tarea sin desinstalar nada.
