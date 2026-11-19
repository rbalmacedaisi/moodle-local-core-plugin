# Pruebas: problemas comunes (respuestas guiadas)

Pedido de Ramiro (18-nov-2026): armar para TI un flujo donde la persona va eligiendo su problema y ve la respuesta. Si no se soluciona, se abre la solicitud pidiendo una captura o un video. Debe ser configurable para agregar más respuestas a problemas comunes de cada área.

- Plugin: rama `feat/tickets-problemas-comunes`, versión `20261001125`. Sale de `feat/tickets-adjuntos` (`eebd6c6e`, 124) porque usa los adjuntos; se despliega después de esa o junto con ella.
- Portal: rama `feat/tickets-problemas-comunes`, sale de `feat/tickets-adjuntos` (`97d4d9b`).

Staging se trata como producción: **los pasos ✋ necesitan el OK explícito de Ramiro**.

## Cómo funciona

| Pieza | Qué hace |
|---|---|
| Tabla `gmk_tkt_faq` | Por área (y opcionalmente con un tema): problema, respuesta, botones con enlace, público (estudiantes, docentes o ambos), orden, activo, y contadores de vistas, "se solucionó" y "siguió con el problema". |
| Configuración → **Problemas comunes** | Lista por área en tarjetas, con sus contadores. Al editar: problema, respuesta con vista previa, botones (Web, App Store, Play Store; solo enlaces `https`, máximo 6), público, tema si no se soluciona, orden y activo. Cada cambio queda en **Auditoría** (`config_faq`). En el celular el diálogo ocupa toda la pantalla. |
| Formato de la respuesta | Una línea «1. …» por paso (lista numerada), «- …» para viñetas y `**negrita**`. El servidor la convierte a HTML con todo el texto escapado (`faq::render`). |
| Portal (`/soporte` → Nueva solicitud) | Si el área tiene problemas comunes, primero aparece la lista y, al final, «Mi problema es otro». Al elegir uno se ven la respuesta y los botones, y la pregunta «¿Se solucionó?». **Sí:** no se crea ticket y se da las gracias. **No:** se abre el formulario con el tema (el del problema o el primero del área) y el asunto = el problema, con la invitación a adjuntar una captura o un video resaltada. |
| Ayuda TI (docentes) | El mismo flujo, solo con los problemas para docentes (o para ambos) de Soporte Tecnológico. |
| Ticket creado tras «No» | El agente ve una nota interna: «Ya probó la solución sugerida: …». El estudiante no la ve. Queda el evento `faq_tried`. |
| Métricas | Cada respuesta abierta suma una vista; «Sí» y «No» suman en sus contadores, para el tablero de la fase 3. |

### Carga inicial (Soporte Tecnológico)

1. **«No me puedo conectar a mi clase virtual: no abre la videoconferencia cuando le doy «Unirme»»** (estudiantes y docentes). Pide usar Google Chrome, con botones a la App Store, a la Play Store y a google.com/chrome. Dice dónde volver a entrar: estudiantes en students.isi.edu.pa y docentes en lms.isi.edu.pa.
2. **«No sé cómo buscar mi clase virtual»** (**solo estudiantes**). Da el paso a paso con los nombres reales del portal, revisados en el código:
   - el menú del usuario → «Mi Horario»;
   - la clase → «Actividad»;
   - «Sesiones Virtuales» → «Unirse a Videoconferencia»;
   - la otra opción: en Inicio, «Cursos Iniciados» o «Planes de Aprendizaje»;
   - los estados de espera: «¡Casi llegamos!» y «Esperando al anfitrión».

   La propuesta original decía «Mis carreras» y «esperando al docente»; en el portal se llaman distinto. Lo dejé solo para estudiantes porque los docentes entran por Moodle, no por el portal. Si Ramiro quiere una versión para docentes, se agrega en la configuración.

## 1. Despliegue en staging ✋

1. Después de (o junto con) la rama de adjuntos (`20261001124`).
2. Upgrade a `20261001125`: crea `mdl_gmk_tkt_faq` y carga los 2 problemas en el área `ti`, solo si la tabla está vacía.
3. `SELECT id, problem, audience, active FROM mdl_gmk_tkt_faq;` → 2 filas.

## 2. Autotest con rollback ✋

`sudo -u www-data php local/grupomakro_core/cli/support_selftest.php --student=<id de prueba>`

La sección **Problemas comunes** comprueba:
- la carga inicial de TI y los 3 botones de Chrome;
- que el catálogo del estudiante traiga los problemas para estudiantes y no los de docentes;
- el HTML seguro (texto escapado, listas y negrita);
- el rechazo de enlaces que no son `https`;
- los contadores de vistas, «sí» y «no»;
- que un estudiante no pueda contar un problema solo para docentes;
- que tras «No» el ticket tenga la nota interna y el estudiante no la vea;
- que un problema de otra área no se anote.

## 3. Pantallas ✋ (teléfono)

| # | Paso | Esperado |
|---|---|---|
| 1 | Portal → Ayuda → Nueva solicitud → Soporte Tecnológico. | Lista de problemas + «Mi problema es otro». |
| 2 | Elegir «No me puedo conectar…». | Respuesta con pasos y los 3 botones; cada uno abre la tienda o la web de Chrome. |
| 3 | «Sí, se solucionó». | Pantalla de agradecimiento; no se crea ticket; en Configuración sube «Se solucionó». |
| 4 | Repetir y elegir «No, sigo con el problema». | Formulario con el tema de TI, el asunto con el problema, el aviso «ya probaste la solución» y «Adjuntar» resaltado. Al enviar, el agente ve la nota interna. |
| 5 | «Mi problema es otro». | Temas del área y formulario normal, con la invitación a adjuntar captura o video. |
| 6 | La flecha de volver en cada paso. | Vuelve a la respuesta o a la lista sin perder el área. |
| 7 | Docente → Ayuda TI → Nueva solicitud. | Solo ve «No me puedo conectar…» (no el paso a paso del portal) y el mismo flujo. |
| 8 | Configuración → Problemas comunes → agregar uno en Académico con 2 pasos y un botón. | Vista previa correcta. Aparece en el portal en Académico. Un enlace `http://` se rechaza con un mensaje claro. Auditoría registra el cambio. |
| 9 | Desactivar un problema. | Ya no aparece. Los contadores se conservan. |
