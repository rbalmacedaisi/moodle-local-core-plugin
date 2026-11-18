# Pruebas: adjuntos en el chat de la mesa de ayuda

Pedido de Ramiro (18-nov-2026): "necesito que el sistema permita enviar imagenes y videos".

- Plugin: rama `feat/tickets-adjuntos` desde master `b891f9fe`, versión `20261001124`.
- Portal: rama `feat/tickets-adjuntos` desde `isi-prod` `36ef3f0`.

Staging (`/opt/moodle_staging`, base `isidb_staging`) se trata como producción: **los pasos marcados ✋ escriben y necesitan el OK explícito de Ramiro**.

## Cómo funciona

| Pieza | Qué hace |
|---|---|
| `support_upload.php` | Recibe UN archivo (POST multipart) del portal (`wstoken`) o de Moodle (sesión + `sesskey`). Valida el tipo real por los primeros bytes, el tamaño y la cantidad, y lo deja en el borrador del usuario (`user/draft`). Responde el `draftitemid`. También quita un archivo del borrador (`action=delete`). Contesta el preflight CORS del portal. |
| `send_message` / `create_ticket` | Parámetro nuevo `draftitemid`. El mensaje puede ir sin texto si lleva adjuntos. Vuelven a validar y pasan los archivos a la File API: contexto sistema, componente `local_grupomakro_core`, filearea `tkt_attachment`, itemid = id del mensaje. `gmk_tkt_message.hasattachments` = cantidad. Solo se puede adjuntar el borrador **propio**. |
| `support_file.php?id=` | Entrega el archivo con el mismo acceso que el ticket (solicitante y personal que puede verlo). Las notas internas nunca van al solicitante y Psicología sigue su regla de confidencialidad. Si no corresponde, devuelve 404. El portal agrega `&token=`. Soporta rangos (video) y objectfs (Cloudflare R2). |
| `get_ticket` / `get_catalog` | Devuelven `attachlimits` (tipos, `accept`, máximo por mensaje, bytes de imagen y video, URL de subida). |
| Avisos | Notificaciones, listas e indicador nunca llevan el archivo ni su nombre: "📎 Adjunto" o "🎬 Video". Los tickets confidenciales no llevan ni eso. |

Parámetros (Configuración → Parámetros):

| Parámetro | Valor inicial |
|---|---|
| `tkt_attach_maxmb` | 10 (imagen o PDF, MB) |
| `tkt_attach_video_maxmb` | 50 (video, MB) |
| `tkt_attach_maxfiles` | 4 por mensaje (0 = sin adjuntos) |
| `tkt_attach_types` | `jpg,jpeg,png,webp,gif,heic,mp4,mov,webm,pdf`. El upgrade reemplaza el valor de la carga inicial (`pdf,jpg,png,docx`) solo si nadie lo cambió. |

El máximo efectivo nunca supera el límite de subida del servidor (`get_max_upload_file_size`: PHP y `maxbytes` de Moodle).

En el teléfono:
- **Adjuntar** abre la cámara o la galería, con vista previa, barra de progreso y cancelar/quitar.
- Las imágenes se comprimen en el navegador (lado mayor 1920 px, JPEG ~80 %). GIF y HEIC van tal cual.
- Los videos no se comprimen. Si pasan del máximo, aparece un mensaje claro antes de subir.
- En el chat, las imágenes salen en miniatura y se abren en un visor a pantalla completa. Los videos tienen reproductor en línea (`playsinline`) y los PDF salen como enlace.

## 0. Antes de desplegar (solo lectura) ✋ para el SSH

Para videos de 50 MB, el servidor tiene que aceptar subidas de ese tamaño:

```bash
php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit'      # CLI
grep -E 'upload_max_filesize|post_max_size' /etc/php/7.4/fpm/php.ini    # PHP-FPM (web)
grep -R client_max_body_size /etc/nginx/ 2>/dev/null                    # si hay nginx
grep -R LimitRequestBody /etc/apache2/ 2>/dev/null                      # si es Apache
mysql -e "SELECT value FROM isidb.mdl_config WHERE name='maxbytes'"     # 0 = sin límite de Moodle
```

Para videos de 50 MB hacen falta `upload_max_filesize ≥ 50M` y `post_max_size ≥ 55M` en PHP-FPM, y `client_max_body_size ≥ 55m` si hay nginx delante. Cloudflare (plan gratuito) acepta hasta 100 MB por petición.

Si el servidor permite menos, la página informa el máximo real y los videos más grandes se rechazan con un mensaje claro. Subir esos límites requiere un OK aparte de Ramiro (es configuración del servidor).

## 1. Despliegue en staging ✋

1. Respaldo de `mdl_config_plugins` (plugin `local_grupomakro_core`).
2. Upgrade a `20261001124`. No hay tablas nuevas.
3. `SELECT value FROM mdl_config_plugins WHERE plugin='local_grupomakro_core' AND name='tkt_attach_types';` → los tipos nuevos.

## 2. Autotest con rollback ✋

```bash
sudo -u www-data php local/grupomakro_core/cli/support_selftest.php --student=<id de prueba>
```

La sección **Adjuntos** cubre:
- **Validación:** png, mp4 y pdf válidos por su contenido. Se rechazan un `.exe`, un texto renombrado a `.png`, un tipo no habilitado, una imagen o un video más grandes que el máximo y más archivos de los permitidos por mensaje. El reconocimiento de mov, webm, gif, webp, heic y jpg.
- **Envío:** el estudiante manda imagen y video sin texto, el borrador queda vacío y el chat los exporta con enlace a `support_file.php`. Los avisos y la lista dicen "🎬 Video" / "📎 Adjunto".
- **Permisos:** el archivo lo descargan el solicitante y el agente, pero no otro usuario. El de una nota interna no lo descarga el solicitante. No se puede usar el borrador de otro usuario. En Psicología, un agente académico no lo descarga.

Todo se revierte. Los bytes de los archivos de prueba pueden quedar huérfanos en `filedir`; Moodle los limpia en su tarea de archivos.

## 3. Pantallas ✋ (en el teléfono, con un estudiante y un agente de prueba)

| # | Paso | Esperado |
|---|---|---|
| 1 | Portal → Ayuda → una solicitud → **Adjuntar** → cámara. | Foto con vista previa y progreso. Al enviar, aparece como miniatura; al tocarla se abre a pantalla completa con "Descargar". |
| 2 | Adjuntar un video corto de la galería. | Sube con progreso y se reproduce dentro del chat (sin saltar a pantalla completa en iPhone). |
| 3 | Adjuntar un video de más de 50 MB. | Antes de subir: "El video pesa X MB; el máximo es 50 MB…". El botón Enviar queda bloqueado hasta quitarlo. |
| 4 | Cancelar una subida en curso con la ✕. | Se detiene; el resto sigue. |
| 5 | Enviar 5 archivos (máximo 4). | "Puedes adjuntar como máximo 4…". |
| 6 | Nueva solicitud con una captura. | El ticket se crea con la imagen en el primer mensaje. |
| 7 | Bandeja (agente): el adjunto del estudiante. | Lo ve. La campanita y el correo dicen "📎 Adjunto" o "🎬 Video", sin el archivo. |
| 8 | Agente responde con una imagen y una nota interna con un PDF. | El estudiante ve la imagen y no ve la nota ni puede abrir su PDF (si copia el enlace: 404). |
| 9 | Copiar la URL de un adjunto y abrirla con la sesión de otro estudiante. | 404. |
| 10 | Docente en Ayuda TI: adjuntar captura y video. | Igual que el estudiante. |
| 11 | Configuración → Parámetros: poner `tkt_attach_maxfiles` en 0. | Desaparece "Adjuntar" (portal y Moodle). Restaurar a 4. |
