# Pruebas en staging: mesa de ayuda, fase 1

Rama del plugin: `feat/tickets-soporte-fase1` (versión `20261001120`).
Rama del portal: `feat/tickets-soporte-fase1` sobre `isi-prod` (repo `isi_moodle_lxp`).

Staging (`/opt/moodle_staging`, base `isidb_staging`) se trata como producción: **cada paso marcado ✋ escribe y necesita el OK explícito de Ramiro**.

Staging es **solo CLI** (wwwroot `staging.invalid`): ahí se prueba el upgrade, la carga inicial y toda la lógica con `cli/support_selftest.php`. Las pantallas (portal, bandeja y configuración) necesitan un Moodle con navegador: producción después del despliegue, con un estudiante de prueba, o un entorno local. Las secciones 2 a 5 describen esa prueba de pantallas; la sección 1-bis cubre lo mismo por CLI.

## 0. Antes de empezar (solo lectura)

1. Confirmar que los usuarios de la carga inicial existen y son quien se espera:
   ```sql
   SELECT id, firstname, lastname, email, deleted, suspended
     FROM mdl_user WHERE id IN (2732, 2912, 2737, 2765, 2756, 2886);
   ```
   Esperado: Jean Remice, Lizbeth Aizprua, Jorge Oviedo, Dulce Jurado, Esteban Montoya, Joyce Muñoz.
   Si alguno no existe, la carga lo omite y lo deja en el evento `config_seed` (`metadata.missingusers`).
2. Versión actual: `SELECT value FROM mdl_config_plugins WHERE plugin='local_grupomakro_core' AND name='version';` (debe ser menor que `20261001120`).
3. Ver que no haya otra rama pendiente con un número entre `20261001116` y `20261001119`: si se fusiona después de esta, sus pasos de upgrade no correrían.

## 1. Despliegue en staging ✋

1. Respaldo: `mysqldump isidb_staging` (o al menos `mdl_config_plugins`, `mdl_role`, `mdl_role_capabilities`, `mdl_role_assignments`).
2. Copiar el plugin de la rama a `/opt/moodle_staging/local/grupomakro_core`.
3. `sudo -u www-data php admin/cli/upgrade.php --non-interactive` y guardar la salida.
4. Revisar:
   - 13 tablas `mdl_gmk_tkt_*` creadas.
   - `SELECT code, name, external_url FROM mdl_gmk_tkt_area ORDER BY sortorder;` → académico, ti, bienestar, psicologia, finanzas (WhatsApp).
   - `SELECT c.name, u.firstname, u.lastname FROM mdl_gmk_tkt_agent a JOIN mdl_gmk_tkt_category c ON c.id=a.categoryid JOIN mdl_user u ON u.id=a.userid ORDER BY c.areaid, c.sortorder;`
   - Respaldos: Jorge → Jean y Lizbeth; Dulce → Joyce.
   - `gmk_tkt_person`: Joyce como gerencia. Rol `gmk_gerencia` creado y asignado a Joyce en contexto sistema.
   - 8 plantillas en `gmk_tkt_template`; parámetros `tkt_*` en `config_plugins`; `tkt_seeded` con fecha.
   - Correr el upgrade una segunda vez (o `admin/cli/upgrade.php` sin cambios): la carga **no** se repite.
5. Comparar `mdl_role_capabilities` antes y después: solo deben aparecer las capacidades `support_*`.

## 1-bis. Prueba automática con rollback ✋ (corre código en staging, no deja datos)

```bash
cd /opt/moodle_staging/local/grupomakro_core/cli
sudo -u www-data php support_selftest.php --student=<id de un estudiante de prueba>
```

Corre dentro de una sola transacción que siempre se revierte: crea tickets, conversa, resuelve, reabre, cierra, cancela,
prueba la cola con agentes ausentes, la confidencialidad de Psicología, el acceso cruzado y el límite de abiertos, e imprime
PASS/FAIL. Las notificaciones quedan en la cola de la transacción y se descartan al revertir. Lo único que cambia es el
contador AUTO_INCREMENT de las tablas `gmk_tkt_*`. Al final informa cuántos tickets le quedan al estudiante (debe ser 0).

## 2. Configuración (Ramiro o Joyce, en `pages/support_settings.php`) ✋

- Las 7 pestañas cargan sin error.
- Editar el horario de un área, guardar, recargar: se mantiene. Un tramo mal escrito (`8-17`) da error claro.
- Agregar y quitar un agente de prueba; desactivar un tema; editar una plantilla. Cada cambio aparece en la pestaña **Auditoría** con antes y después.
- Borrar un tema con tickets → lo rechaza y pide desactivarlo.

## 3. Flujo completo con un estudiante de prueba ✋

Usar un estudiante de prueba (no uno real) en el entorno con navegador.

| # | Paso | Esperado |
|---|---|---|
| 1 | Portal: aparece el botón flotante **Ayuda** abajo a la derecha. | No aparece en `/soporte` ni en el reproductor de cursos. |
| 2 | Ayuda → Nueva solicitud → Finanzas. | No crea ticket; botón de WhatsApp a `wa.me/50768920543` con nombre y cédula (campo `documentnumber`). |
| 3 | Académico → Notas → descripción → Enviar. | Número `TKT-2026-0001`; chat con el mensaje del estudiante y el automático de creación con el agente (Jean o Lizbeth, el de menos abiertos). |
| 4 | Crear una segunda solicitud académica. | Va al otro agente (reparto equilibrado). |
| 5 | Crear fuera de horario (o con un área con horario vacío para hoy). | Llega el mensaje automático "fuera del horario de atención". |
| 6 | Bandeja (agente asignado): pestaña **Míos**. | Ve el ticket con semáforo; Jorge, Esteban y Dulce **no** lo ven salvo como supervisores. |
| 7 | Agente: Nota interna. | El estudiante no la ve ni en el chat ni en la vista previa de la lista. |
| 8 | Agente: Responder. | Estado "En atención"; el estudiante ve "En atención", número rojo en el botón Ayuda; notificación `support_reply`. |
| 9 | Agente: Pedir información. | Estado "Esperando estudiante" / "Te pedimos información". |
| 10 | Estudiante responde. | Vuelve a "En atención"; notificación al agente. |
| 11 | Agente: Resolver. | El estudiante ve "¿Se resolvió tu caso?". |
| 12 | Estudiante: **No**. | "Reabierta", prioridad alta, mismo agente. |
| 13 | Agente responde y resuelve; estudiante: **Sí**. | "Cerrada"; ya no admite mensajes; el historial muestra todos los pasos. |
| 14 | Crear y cancelar antes de que respondan. | "Cancelada". Con respuesta del personal ya no ofrece cancelar. |
| 15 | Resolver o cerrar sin ninguna respuesta del personal. | Lo rechaza ("no se puede cerrar sin al menos una respuesta"). |
| 16 | Límites: 6.º ticket abierto. | Rechazado (máximo 5). |

## 4. Asignación, ausencias y cola ✋

- Jean y Lizbeth en **Ausente** (interruptor de la bandeja) → un ticket académico queda en **Sin asignar** (pestaña en rojo) y llega `support_new_ticket` a los agentes del tema.
- Jorge ausente → un ticket de Asistencia va a Jean o Lizbeth (respaldo).
- Esteban ausente → un ticket de TI queda en la cola (sin respaldo).
- Otro agente pulsa **Tomar** en la cola; un no supervisor no puede tomar uno ya asignado.
- Supervisor (Secretaría): pestaña **Todos**, **Reasignar** a otro agente; queda en el historial.

## 5. Confidencialidad (Psicología) ✋

- Ticket de Psicología: lo ve Dulce y Joyce. **No** lo ven Secretaría, Bienestar ni un administrador del sitio sin rol de gerencia (la capacidad se evalúa sin "doanything").
- Notificaciones de ese ticket: dicen "Ticket confidencial", sin texto del mensaje.
- Cambiar el tema de un ticket confidencial a uno académico: sigue confidencial.

## 6. Seguridad (sin escribir datos de otros)

- Con el token del estudiante A, llamar `local_grupomakro_support_get_ticket` con el id de un ticket del estudiante B → "No tienes acceso".
- Ningún WS acepta `userid`: la identidad es siempre la de la sesión/token.
- `desk_list`, `desk_action`, `settings_*` con el token de un estudiante → rechazados.

## 7. Cierre automático ✋

- Bajar temporalmente `tkt_close_hours` a 1, dejar un ticket en "Esperando estudiante", y correr
  `php admin/cli/scheduled_task.php --execute='\local_grupomakro_core\task\support_autoclose'` pasada la hora.
  Esperado: cerrado con el evento "sin respuesta del solicitante". Restaurar `tkt_close_hours` a 24.

## 8. Limpieza ✋

Borrar los tickets de prueba (`gmk_tkt_ticket`, `_message`, `_read`, `_event` con esos ids) o restaurar el respaldo, según prefiera Ramiro.
