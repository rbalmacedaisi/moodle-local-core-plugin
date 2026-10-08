# Informe Ejecutivo del LMS del Instituto Superior de Ingeniería (ISI Panamá)

| Campo | Valor |
|---|---|
| **Documento** | INFORME_EJECUTIVO.md |
| **Versión** | 1.0 |
| **Fecha de emisión** | Octubre 2026 |
| **Sistema** | Fork de Moodle `local_grupomakro_core` + LXP Vue + ERP Odoo + Pasarela Express |
| **Versión del plugin referenciada** | 20261001008+ (post-PR8) |
| **Audiencia** | Dirección Académica, Registros, Secretaría Académica, Soporte TI, Coordinación de Bienestar, Contabilidad |
| **Clasificación** | Uso interno |
| **Anexos vinculados** | `role-matrix.md`, `role-matrix-quickref.md`, `Procedimiento_Revalidas.md`, `ARCHITECTURE.md` |

---

## Tabla de contenido

0. [Resumen ejecutivo](#0-resumen-ejecutivo)
1. [Estructuración académica](#1-estructuración-académica)
2. [Roles, alcance operativo y permisos](#2-roles-alcance-operativo-y-permisos)
3. [Procedimientos académicos del estudiante](#3-procedimientos-académicos-del-estudiante)
4. [Asistencia](#4-asistencia)
5. [Calificaciones](#5-calificaciones)
6. [Diplomas y certificados](#6-diplomas-y-certificados)
7. [Cartas y constancias](#7-cartas-y-constancias)
8. [Perspectiva del docente](#8-perspectiva-del-docente)
9. [Perspectiva del estudiante](#9-perspectiva-del-estudiante)
10. [Operación diaria](#10-operación-diaria)

**Anexos**:
- [Anexo A — Roles y capacidades (referencia técnica)](#anexo-a)
- [Anexo B — Procedimiento formal de reválidas (referencia)](#anexo-b)
- [Anexo C — Arquitectura técnica (referencia)](#anexo-c)
- [Anexo D — Personas y roles asignados](#anexo-d)
- [Anexo E — Glosario](#anexo-e)
- [Anexo F — Queries para obtener cifras en vivo](#anexo-f)
- [Anexo G — Pensum completo de las carreras (datos en vivo)](#anexo-g)

---

## 0. Resumen ejecutivo

El Instituto Superior de Ingeniería (ISI Panamá) opera su plataforma educativa sobre un **fork propio de Moodle** complementado con tres sistemas satélite: el **ERP Odoo** (que es la fuente canónica de carreras, estudiantes, facturación y estados financieros), un **LXP en Vue** (la interfaz personalizada que ven los estudiantes en `lms.isi.edu.pa/students`) y un **servidor Express** que actúa como pasarela segura entre el LXP, Moodle y Odoo.

El fork —denominado internamente `local_grupomakro_core`— es la capa que contiene **toda la lógica académica específica del instituto**: las carreras, los planes de estudio, las prelaciones, la asistencia con QR, la grabación automática de clases por BigBlueButton, las reválidas, los módulos independientes, las homologaciones, los retiros, los diplomas y la bitácora académica. Esta lógica **no existe en Moodle estándar**; es desarrollo propio que el instituto ha ido acumulando desde 2022.

### 0.1. Cinco palancas operativas

Cualquier directivo debe familiarizarse con cinco procesos centrales:

1. **Admisión y matrícula**: el contacto nace en Odoo, se sincroniza a Moodle con la carrera, el periodo y la jornada.
2. **Planificación académica**: cada periodo se arma una oferta de clases (carrera × asignatura × jornada × horario × docente × aula), validando disponibilidad docente, capacidad de aula y prelaciones.
3. **Operación diaria de clase**: el docente proyecta el QR de asistencia, graba la sesión por BigBlueButton, califica tareas y cuestionarios, identifica elegibles para reválida.
4. **Cierre del periodo**: se consolidan las notas, se gestionan las reválidas y los módulos pendientes, se generan diplomas a los estudiantes elegibles.
5. **Gestión documental**: cartas, constancias, contratos institucionales, diplomas, verificaciones.

### 0.2. Cifras operativas (placeholders — ver Anexo F para obtener en vivo)

| Indicador | Valor | Cómo obtenerlo |
|---|---|---|
| Estudiantes activos (matriculados en plan y sin estado de retiro) | `__N_ESTUDIANTES_ACTIVOS__` | Anexo F, query 1 |
| Docentes con al menos una clase activa en el periodo vigente | `__N_DOCENTES_ACTIVOS__` | Anexo F, query 2 |
| Periodos académicos vigentes | `__N_PERIODOS_VIGENTES__` | Anexo F, query 3 |
| Clases activas en el periodo vigente | `__N_CLASES_ACTIVAS__` | Anexo F, query 4 |
| Carreras en catálogo | 12 | Catálogo fijo |
| Roles operativos definidos | 7 (1 nativo + 6 custom) | Catálogo fijo |
| Personas con rol asignado | 11 | Anexo D |

### 0.3. Principios rectores de la plataforma

- **Una sola fuente de verdad por concepto**: Odoo es dueño del dato demográfico y financiero; Moodle es dueño del dato académico (calificaciones, asistencia, trayectoria); la sincronización entre ambos es por webhooks firmados y nunca por replicación ciega.
- **Las decisiones académicas críticas quedan registradas**: cada cambio de nota, cada anulación, cada homologación, cada aplazamiento o retiro tiene una fila de auditoría con fecha, autor y motivo.
- **El dinero precede al acceso**: si un estudiante está en mora con la administración, no puede escanear QR ni ser evaluado. Si paga, el sistema lo libera en cuestión de minutos.
- **El docente nunca otorga presente**: la presencia solo se gana por QR del estudiante o por presencia confirmada en la sesión virtual de BigBlueButton. Esto elimina las planillas de asistencia fraguadas.

### 0.4. Mapa de capítulos

| Cap | Tema | Pregunta que responde |
|---|---|---|
| 1 | Estructuración académica | ¿Cómo está organizado un plan de estudios y qué reglas sigue? |
| 2 | Roles | ¿Quién puede hacer qué en la plataforma? |
| 3 | Procedimientos académicos | ¿Cómo funciona una reválida, un módulo, una homologación, un retiro? |
| 4 | Asistencia | ¿Cómo se controla la asistencia y qué pasa si un estudiante falta? |
| 5 | Calificaciones | ¿Cómo se calcula la nota final de un estudiante? |
| 6 | Diplomas | ¿Cuándo y cómo se genera un diploma? |
| 7 | Cartas | ¿Cómo pide un estudiante una constancia y cómo se entrega? |
| 8 | Vista del docente | ¿Qué ve y qué hace un docente en la plataforma? |
| 9 | Vista del estudiante | ¿Qué ve y qué hace un estudiante en el LXP? |
| 10 | Operación diaria | ¿Cómo se opera la plataforma día a día? |

> **Ver también**: `docs/ARCHITECTURE.md` para el detalle técnico de la integración entre los cuatro sistemas; `docs/role-matrix.md` y `docs/role-matrix-quickref.md` para el detalle de capacidades por rol; `docs/Procedimiento_Revalidas.md` para el procedimiento formal completo de reválidas.

---

## 1. Estructuración académica

### 1.1. Catálogo de carreras

El instituto ofrece **12 carreras técnicas superiores** registradas ante la DNCES/DNES. Cada carrera tiene un nombre, una duración en cuatrimestres, una resolución oficial de aprobación, un costo total y reglas de descuento y financiamiento.

| # | Carrera | Cuatrimestres | Resolución | Costo total (USD) | Costo de matrícula | Desc. carrera completa | Desc. cuatrimestre completo | Mora |
|---|---|---|---|---|---|---|---|---|
| 1 | Técnico Superior en Azafata Profesional de Vuelo Comercial | 3 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 2 | Técnico Superior en Soldadura | 4 | (DNCES) | 7 400 | 150 | 20 % | 10 % | 10 % |
| 3 | Técnico Superior en Logística Integral | 4 | (DNCES) | 2 700 | 150 | 20 % | 10 % | 10 % |
| 4 | Asistente de Ingeniería Civil | 3 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 5 | Mecánica de Equipo Pesado | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 6 | Seguridad y Mantenimiento | 4 | (DNCES) | 5 300 | 150 | 20 % | 10 % | 10 % |
| 7 | Diseño y Obras Civiles | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 8 | Topografía | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 9 | Asistente de Odontología | 6 | (DNCES) | 5 300 | 150 | 20 % | 10 % | 10 % |
| 10 | Electricidad con énfasis en Hidroeléctricas | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 11 | Medio Ambiente y Cuencas Hidrográficas | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |
| 12 | Acuicultura | 4 | (DNCES) | 3 700 | 150 | 20 % | 10 % | 10 % |

**Reglas financieras uniformes** (aplican a las 12 carreras):

- **Costo de matrícula**: USD 150 (pago único al inicio).
- **Costo de cuatrimestre** = costo total / número de cuatrimestres.
- **Descuento por pago de carrera completa (de contado)**: 20 % sobre el costo total.
- **Descuento por pago de cuatrimestre completo (al contado)**: 10 % sobre el costo de ese cuatrimestre.
- **Mora por incumplimiento**: 10 % por defecto; configurable por carrera.
- **Cuotas**: el plan de pagos estándar es **quincenal** (8 cuotas por cuatrimestre) o **mensual** (4 cuotas por cuatrimestre).

El catálogo de carreras vive en Odoo como fuente canónica y se refleja en Moodle. Cuando un estudiante es admitido, su `carrera` se mapea a un `plan de estudios` en Moodle que contiene la malla curricular.

### 1.2. Planes de estudio y malla curricular

Un **plan de estudio** es la traducción operativa de una carrera a un recorrido cursable. Cada plan tiene:

- **Niveles / cuatrimestres**: el plan se divide en N niveles (3 para Azafata, 6 para Odontología, 4 para la mayoría). Un estudiante avanza de un nivel al siguiente solo cuando ha aprobado las asignaturas críticas de su nivel actual.
- **Asignaturas por nivel**: cada nivel agrupa entre 4 y 8 asignaturas. Cada asignatura tiene un nombre, un código, horas de teoría, horas de práctica, créditos y, opcionalmente, una lista de prelaciones.
- **Asignaturas obligatorias vs electivas**: las obligatorias son requisito de graduación; las electivas complementan el índice académico pero no bloquean la graduación.

**Reglas operativas**:
- Un estudiante nuevo entra al primer nivel de su plan con todas sus asignaturas en estado **Disponible**.
- Al cerrar un periodo, si aprobó las obligatorias de su nivel, su nivel actual avanza en uno y las nuevas asignaturas pasan a estado **Disponible**.
- Si reprobó alguna obligatoria, repite solo esa asignatura en el siguiente periodo (no repite el nivel completo), pero su nivel general no avanza.

### 1.3. Prelaciones (requisitos entre asignaturas)

Una **prelación** indica que para cursar la asignatura B, es obligatorio haber aprobado (o al menos intentado) la asignatura A. La regla operativa es:

> Una prelación se considera satisfecha cuando la asignatura prerequisito está en estado **Aprobada** o **Reprobada**. No se considera satisfecha si la asignatura prerequisito está en estado **Cursando** o **Disponible** (pero el estudiante nunca la ha cursado).

Esto significa que un estudiante que reprobó una prelación **puede** cursar la asignatura que depende de ella; el bloqueo aplica solo a quien nunca la ha intentado. La lógica detrás de esta regla es que reprobar no es razón suficiente para detener la trayectoria del estudiante, sino que debe repetir la materia en el siguiente periodo.

**Ejemplo práctico**: para cursar *Cálculo II* se requiere haber cursado *Cálculo I*. Un estudiante que aprobó Cálculo I puede cursar Cálculo II sin restricciones. Un estudiante que reprobó Cálculo I también puede cursar Cálculo II (debe repetir Cálculo I en paralelo o después). Un estudiante que nunca cursó Cálculo I **no** puede cursar Cálculo II hasta haberla cursado al menos una vez.

### 1.4. Carga horaria

Cada asignatura tiene tres dimensiones de carga:

| Dimensión | Significado | Ejemplo (Cálculo I) |
|---|---|---|
| **Horas teóricas (HT)** | Horas de clase presencial/virtual dedicadas a teoría | 48 h |
| **Horas prácticas (HP)** | Horas de clase dedicadas a práctica, taller o laboratorio | 32 h |
| **Créditos** | Unidad ponderada que alimenta el índice académico | 4 |

La carga total por asignatura (HT + HP) determina la intensidad semanal. La carga total por nivel (suma de créditos de todas las asignaturas obligatorias) determina si un estudiante es de tiempo completo.

**Jornada**:

| Jornada | Horario | Aplica a |
|---|---|---|
| Diurna | 07:00 – 18:00 | Carreras regulares |
| Nocturna | 18:00 – 22:00 | Carreras regulares |
| Sabatina | 07:00 – 17:00 (sábados) | Modalidad ejecutiva |

Los parámetros del planificador de horarios son **globales** a la plataforma (no se pueden cambiar por periodo), con valores por defecto:

- Duración estándar de sesión: 120 minutos.
- Intervalo entre sesiones: 30 minutos.
- Ventana de almuerzo (no se programan clases): 12:00 – 13:00.
- Horario operativo total: 07:00 – 22:00.

Los feriados y excepciones se gestionan como un calendario centralizado, no por periodo. Si el instituto declara un día como no laborable, el planificador lo respeta automáticamente al armar nuevos horarios.

**Fuente canónica de la intensidad horaria por asignatura**: la información que el planificador consulta para decidir cuántas horas a la semana dicta cada materia vive en la tabla **`gmk_subject_loads`** (módulo de planificación). Cada fila tiene `subjectname`, `total_hours` (carga total esperada en el periodo; valor por defecto histórico 64 h) e `intensity` (horas semanales; 0 = asignatura en catálogo pero no dictada en el periodo actual). Desde la migración del 9 de septiembre de 2026 (PR 20260909000) esta configuración es **global** a la plataforma: las filas con `academicperiodid = 0` son las activas; las filas con `academicperiodid > 0` se conservan como histórico. El **Anexo G, sección G.0.1** lista las 155 asignaturas del catálogo con su carga y su intensidad actual; el hallazgo relevante es que solo **19 de 155** están activas en el periodo vigente (las 136 restantes están en catálogo pero no se dictan).

### 1.5. Periodos académicos

La plataforma maneja **dos conceptos de periodo** que es importante no confundir:

- **Periodo lectivo institucional** (en Moodle se identifica como `2026-I`, `2026-II`, `2026-III`, `2026-IV`, `2026-V`): es el contenedor calendario al que se alinean **todas** las actividades académicas, financieras y administrativas. El año tiene cinco periodos lectivos. Este periodo tiene fecha de inicio, fecha de fin, fechas de inducción, fechas de bimestres, fechas de exámenes y fechas de cierre.
- **Nivel del estudiante** (en Moodle: `Nivel 1`, `Nivel 2`, etc.): es el progreso del estudiante **dentro de su plan**. Este concepto es individual: el estudiante A puede estar en Nivel 2 mientras el estudiante B de la misma carrera está en Nivel 3.

**Regla operativa**: la cohorte de un estudiante se determina por su nivel y por el periodo lectivo en que se matriculó. Dos estudiantes de la misma carrera que ingresaron en el mismo periodo lectivo pertenecen a la misma cohorte y avanzan juntos de nivel, siempre que aprueben.

### 1.6. Calendario académico, feriados y bimestres

Cada periodo lectivo tiene un calendario que define:

- **Fecha de inducción**: día en que se abren las clases del periodo.
- **Bimestre 1** (fecha de inicio y fin): primer bloque del periodo.
- **Bimestre 2** (fecha de inicio y fin): segundo bloque del periodo.
- **Ventana de exámenes finales**: cuándo se aplican los exámenes.
- **Ventana de carga de notas**: cuándo el docente debe terminar de calificar.
- **Ventana de reválidas**: cuándo se programan y aplican las evaluaciones de recuperación.
- **Fecha de cierre del periodo**: cierre administrativo.

Los **feriados** se almacenan en un calendario centralizado y se restan automáticamente de la programación de clases. Si un feriado cae en día de clase, esa sesión se cancela y el docente debe reprogramarla.

### 1.7. Clases (grupos)

Una **clase** es la instancia operativa de una asignatura en un periodo lectivo específico. Es lo que el docente "ve" en su dashboard. Una clase tiene:

- **Asignatura** (la materia que se imparte).
- **Docente titular** (responsable de la calificación, asistencia y firma del acta).
- **Docente de apoyo** (opcional; recibe las mismas capacidades que el titular pero con rol secundario).
- **Tipo**: Presencial, Virtual o Mixta.
- **Jornada**: Diurna, Nocturna o Sabatina.
- **Horario semanal**: días y horas de la semana (codificado como una máscara binaria L/M/M/J/V/S/D).
- **Aula** (solo presencial/mixta): capacidad por defecto 40 estudiantes.
- **Periodo lectivo** (institucional).
- **Plan de estudios** al que pertenece.
- **Fechas de inicio y fin** de la clase (pueden no coincidir con las fechas del periodo).
- **Estado**: Borrador → Aprobada → Activa → Cerrada.

**Asistencia automática al aula**: cuando una clase se aprueba, se crea automáticamente un grupo (en Moodle, un "grupo" lógico) con todos los estudiantes matriculados. La asistencia se mide por sesión.

**Sesiones de clase**: una clase se compone de N sesiones. Cada sesión tiene:
- Fecha y hora de inicio/fin.
- Estado (`Programada`, `Tomada`, `Cancelada`, `Es reválida`).
- Un módulo de asistencia asociado.
- Un módulo de BigBlueButton asociado (para clases virtuales o mixtas).
- Una relación entre ambos módulos para que la presencia en BBB alimente automáticamente la asistencia.

### 1.8. Docentes y disponibilidad

Cada docente tiene un **perfil de disponibilidad** que indica en qué bloques de la semana puede impartir clase. La disponibilidad se almacena como una máscara de tiempo similar a la de las clases.

**Reglas de asignación de docente a clase**:
1. El docente debe estar disponible en el bloque horario de la clase (sin choque con otra clase suya).
2. El docente no debe tener otra clase en el mismo bloque en el mismo rango de fechas.
3. El docente debe pertenecer al plan de estudios de la asignatura (o tener autorización del Director Académico).
4. El docente debe tener su estado personal **Activo** (no suspendido, no retirado).
5. Al asignar un docente, el sistema valida estas cuatro reglas automáticamente. Si alguna falla, la asignación es rechazada con un mensaje que explica el motivo exacto.

> **Ver también**: para el detalle técnico de las 79 tablas que componen la estructura académica, consultar `docs/ARCHITECTURE.md` secciones 1–3.

---

## 2. Roles, alcance operativo y permisos

La plataforma distingue dos tipos de actores: el **personal administrativo** (con roles fijos definidos en la plataforma) y el **cuerpo docente** (con roles estándar de Moodle otorgados clase por clase). Los estudiantes son el tercer actor.

### 2.1. Roles del personal administrativo

Hay **7 roles** definidos. Tres son para el cuerpo directivo/super-admin (`manager`, `gmk_director_academico`, `gmk_soporte_ti`) y cuatro son operativos especializados (`gmk_secretaria_academica`, `gmk_registros_academicos`, `gmk_bienestar`, `gmk_psicologo`).

#### 2.1.1. `manager` (super-administrador técnico)

- **Quién lo usa**: máximo 2-3 personas con responsabilidad técnica sobre la plataforma.
- **Alcance**: acceso total. Puede modificar cualquier dato, crear o eliminar usuarios, ejecutar scripts administrativos, manipular la base de datos, gestionar roles, ver logs sensibles.
- **Restricción**: este rol **no es operativo**. Quien lo tiene puede romper el sistema por error. Por eso se limita a 2-3 personas.
- **No es un cargo de gestión académica**, es un rol técnico para resolver emergencias y mantener el sistema.

#### 2.1.2. `gmk_director_academico` (Director Académico)

- **Propósito**: liderazgo académico del instituto, decisiones estructurales, supervisión de reválidas, homologaciones, módulos, cierres de periodo.
- **Decisiones que toma**:
  - Crea reválidas **fuera de la ventana institucional** cuando hay justificación válida (reválida extemporánea).
  - **Anula** movimientos académicos ya registrados (cuando un cambio de nota debe revertirse por error o por orden superior).
  - Aprueba o rechaza solicitudes de homologación masiva.
  - Cierra periodos académicos.
  - Aprueba o rechaza cohortes especiales (aplazamientos con excepciones, retiros con saldos pendientes).
- **Decisiones que NO toma**: no genera diplomas (lo hace Registros), no inscribe estudiantes (lo hace Secretaría), no edita asistencia (lo hace el docente o Bienestar), no emite cartas.
- **Vista principal**: Panel del Director (KPIs financieros, cohortes, alertas).

#### 2.1.3. `gmk_secretaria_academica` (Secretaría Académica)

- **Propósito**: operación diaria académica. Es el equipo que ejecuta la planificación día a día.
- **Lo que opera**:
  - Crea y edita clases (asignatura, docente, horario, aula).
  - Aprueba o rechaza horarios de clase.
  - Gestiona disponibilidad de docentes.
  - Importa usuarios y notas masivamente.
  - Marca asistencia manualmente (en casos excepcionales).
  - Cambia el estado académico de un estudiante (aplazar, retirar, reactivar) en el LMS.
  - Crea módulos independientes.
  - Gestiona los archivos de soporte para corrección de asistencia.
- **Lo que NO hace**: no anula movimientos ya registrados (solo el Director), no crea reválidas extemporáneas (solo el Director), no modifica diplomas directamente, no accede a las páginas de debug técnico.

#### 2.1.4. `gmk_registros_academicos` (Registros Académicos)

- **Propósito**: gestión documental, certificados, contratos, exportaciones regulatorias.
- **Lo que opera**:
  - Catálogo de tipos de carta (constancias, certificados de estudio, cartas de buena conducta, etc.).
  - Bandeja de solicitudes de cartas (aprueba, rechaza, asigna costo).
  - Órdenes y contratos individuales de matrícula.
  - Gestión de instituciones con convenio.
  - Contratos institucionales.
  - Reporte de créditos académicos.
  - Análisis financiero agregado.
  - Generación y revocación de diplomas.
  - Plantillas de diplomas.
  - Exportaciones regulatorias de datos.
- **Lo que NO hace**: no crea ni edita clases, no gestiona horarios, no toma asistencia, no entra a páginas de debug.

#### 2.1.5. `gmk_soporte_ti` (Soporte TI)

- **Propósito**: integración con sistemas externos (Odoo, Express, BigBlueButton), depuración de problemas técnicos, reset de sesiones.
- **Lo que opera**:
  - Páginas internas de debug (más de 70 páginas: diagnóstico de matrículas, corrección de datos, reparación de clases, migración, etc.).
  - Reset de sesiones BigBlueButton cuando una grabación queda huérfana o una sala se pierde.
  - Monitoreo de la sincronización financiera con Odoo.
  - Inspección de la cola de webhooks fallidos (cuando Odoo no logra notificar un pago a Moodle).
  - Configuración de parámetros técnicos de bypass financiero y grace periods.
  - Visor de logs de sincronización.
- **Lo que NO hace**: no modifica contenido académico, no opera la UI de gestión, no atiende estudiantes ni docentes.

#### 2.1.6. `gmk_bienestar` (Coordinador de Bienestar Estudiantil)

- **Propósito**: gestión integral del módulo de Bienestar y apoyo transversal en asistencia.
- **Lo que opera**:
  - Panel de Bienestar: eventos, convenios con empresas, partners, evaluaciones docentes post-sesión.
  - Agenda psicológica (puede ver y gestionar citas).
  - Anuncios broadcast para estudiantes (filtrados por carrera y grupo).
  - **Modificación de registros de asistencia** desde la interfaz nativa de asistencia: cuando un estudiante tiene justificación válida (certificado médico, cita legal, etc.), Bienestar puede cambiar su falta injustificada a falta justificada subiendo el soporte correspondiente (PDF, JPG o PNG, máximo 10 MB).
- **Lo que NO hace**: no crea actividades de asistencia nuevas (eso es del docente al crear la clase), no accede al gradebook nativo, no genera diplomas, no entra a debug.

#### 2.1.7. `gmk_psicologo` (Psicólogo/a)

- **Propósito**: gestión exclusiva de la agenda de citas psicológicas.
- **Lo que opera**:
  - Panel de agenda psicológica: ve los slots publicados, las citas agendadas por los estudiantes, los estados (solicitada, confirmada, cancelada, asistida, no asistida).
  - Confirma o rechaza solicitudes de cita.
  - Publica slots de disponibilidad recurrente.
- **Lo que NO hace**: nada más. No ve eventos, no ve convenios, no ve asistencia, no ve calificaciones. Es el rol más pequeño a propósito, para proteger la confidencialidad de la consulta psicológica.

### 2.2. Cuerpo docente

El docente **no tiene un rol `gmk_*` propio**. Usa los roles estándar de Moodle (`teacher` y `editingteacher`) que se le asignan en cada curso donde enseña. Esto significa que un docente puede tener permisos distintos en cada clase según lo que la administración le haya delegado.

**Capacidades típicas de un docente en una clase donde es titular**:

- Crear, editar y eliminar actividades: tareas, cuestionarios, foros, materiales, sesiones de BigBlueButton.
- Calificar tareas y cuestionarios.
- Tomar asistencia manualmente (solo registra faltas justificadas, injustificadas y retrasos; **nunca marca presente** — esa es la regla institucional).
- Proyectar el QR de asistencia en la sala de clase.
- Entrar a la sesión de BigBlueButton como moderador.
- Programar reválidas para los estudiantes elegibles de su clase.
- Publicar avisos en el foro de la clase.
- Ver el listado de sus estudiantes con su información académica relevante (calificaciones, asistencia, prelaciones, estado financiero parcial).

**Capacidades típicas de un docente de apoyo**: las mismas que el titular, pero figura como secundario. Recibe las mismas notificaciones, tiene acceso al mismo gradebook, pero la responsabilidad formal sobre el acta de cierre sigue siendo del titular.

**Lo que el docente NO puede hacer** (incluso siendo titular):
- Modificar el horario de la clase (eso es de Secretaría).
- Cambiar el aula asignada.
- Modificar el plan de estudios o la malla curricular.
- Crear o cerrar clases.
- Dar de baja a un estudiante.
- Anular una nota ya registrada (solo el Director puede).
- Crear una reválida fuera de la ventana institucional (solo el Director).
- Ver datos sensibles que no sean de sus estudiantes (no puede navegar libremente por la base de estudiantes del instituto).

### 2.3. Estudiantes

El estudiante tiene el rol estándar de Moodle (`student`) que se le asigna en cada curso donde está matriculado. Además, en el LXP tiene acceso a:

- Su información personal y académica.
- El contenido de las clases en las que está matriculado.
- Sus calificaciones por actividad, por curso y su índice académico acumulado.
- Su historial de asistencia con conteo de faltas.
- Sus solicitudes de cartas y constancias.
- Sus solicitudes de reválidas y módulos.
- El módulo de Bienestar (convenios, eventos, citas de psicología, carnet digital).
- La mensajería estándar para comunicarse con sus docentes.
- Los anuncios institucionales filtrados por su carrera y cohorte.

**Lo que el estudiante NO puede hacer**:
- Ver el contenido de clases en las que no está matriculado.
- Ver las calificaciones de otros estudiantes.
- Modificar sus propias notas o asistencia.
- Saltarse la verificación financiera (si está en mora, no puede acceder al contenido aunque esté matriculado).
- Acceder a ninguna herramienta administrativa o de debug.

### 2.4. Procedimiento de asignación de roles

El procedimiento formal para asignar un rol a una persona nueva es:

1. La Dirección Académica aprueba la asignación.
2. Soporte TI (o un `manager`) accede al panel de administración de roles.
3. Asigna el rol en el contexto "Sistema" (es decir, a nivel global, no en un curso específico).
4. Se purgan las cachés de Moodle.
5. La persona puede ahora ver el menú y las opciones asociadas a su rol.
6. Si la persona deja el cargo, se le retira el rol (no se elimina la cuenta, para preservar el historial académico).

> **Ver también**: `docs/role-matrix.md` y `docs/role-matrix-quickref.md` para el detalle exhaustivo de capacidades por rol, mapeo de páginas por rol, mapeo de Web Services por rol, y procedimientos de troubleshooting.

---

## 3. Procedimientos académicos del estudiante

Este capítulo describe los seis procedimientos que un estudiante vive a lo largo de su trayectoria en el instituto.

### 3.1. Admisión y matrícula

**Trigger**: un nuevo estudiante es admitido en el instituto (Decisión de la Dirección Académica).

**Flujo**:

1. **En Odoo**: el operador crea el contacto (con cédula, nombre, datos de contacto), le asigna la carrera y el periodo de matrícula, le genera la matrícula (orden de cobro) y registra el pago de matrícula.
2. **Sincronización Odoo → Moodle**: al confirmar el pago, Odoo crea automáticamente el usuario en Moodle con la cédula como nombre de usuario, le asigna el rol de estudiante en el plan de estudios correspondiente, le crea la primera fila de progreso por cada asignatura del primer nivel y le da acceso al LXP.
3. **Notificación al estudiante**: Odoo envía un correo de bienvenida con las credenciales de acceso al LXP.

**Tiempo objetivo**: la sincronización es automática y se completa en menos de 5 minutos. Si pasa más tiempo sin que el estudiante pueda entrar, hay un problema que escalan a Soporte TI.

### 3.2. Calificaciones (resumen)

El detalle del sistema de calificaciones está en el capítulo 5. Para entender los procedimientos académicos siguientes es importante conocer:

- **Rango de aprobación**: nota **mayor a 70.9** (estricto, una nota 70.0 es reprobada).
- **Rango de aprobación consolidada (reválida)**: 71 (es la nota mínima que queda registrada cuando un estudiante aprueba una reválida).
- **Rango de reválida**: nota entre 60.0 y 70.9 **y** asignatura sin horas prácticas.
- **Estados académicos por asignatura** (los 8 estados posibles):

| Código | Estado | Significado |
|---|---|---|
| 0 | No disponible | La asignatura aún no es cursable (futura o bloqueada) |
| 1 | Disponible | La asignatura puede cursarse en el siguiente periodo |
| 2 | Cursando | El estudiante está matriculado en la asignatura en el periodo actual |
| 3 | Completada | La asignatura terminó pero su nota final aún no se consolidó |
| 4 | Aprobada | Nota final > 70.9 — cuenta para graduación |
| 5 | Reprobada | Nota final ≤ 70.9 sin posibilidad de reválida, o reprobada tras reválida |
| 6 | Pendiente de reválida | Nota 60.0–70.9 sin horas prácticas, el docente aún no la ha programado |
| 7 | En reválida | La asignatura tiene una reválida programada y en curso |
| 99 | Migración pendiente | Estado técnico transitorio para registros antiguos que requieren revisión |

### 3.3. Reválidas

**Definición**: una reválida es una evaluación adicional que se ofrece a un estudiante que, habiendo cursado una asignatura teórica, obtuvo una nota entre 60.0 y 70.9 (es decir, reprobó por estrecho margen). Si la aprueba, la asignatura queda aprobada con nota 71 (la nota mínima de aprobación). Si la reprueba, la asignatura queda reprobada con la nota original.

**Elegibilidad** (reglas institucionales estrictas):

- La asignatura debe ser **teórica** (0 horas prácticas).
- La nota final integrada debe estar en el rango **60.0 – 70.9** (inclusive ambos extremos).
- El docente debe haber calificado **todas** las actividades y los pesos deben sumar exactamente 100 %.
- Debe existir una **ventana institucional de reválidas** abierta en el calendario académico.

**Flujo** (resumen ejecutivo — para el detalle formal ver `docs/Procedimiento_Revalidas.md`):

1. **Docente prepara**: califica todas las actividades, ajusta los pesos a 100 %.
2. **Sistema identifica elegibles**: la plataforma marca automáticamente a los estudiantes en la banda 60.0–70.9 (sin horas prácticas) con un distintivo "Reválida".
3. **Docente programa**: selecciona a los estudiantes que presentarán, la plataforma crea automáticamente una sesión virtual (BigBlueButton) la semana siguiente al cierre del grupo y genera la factura en Odoo.
4. **Estudiante paga**: la factura llega al LXP con un enlace de pago. Al pagar, Odoo notifica a Moodle automáticamente.
5. **Docente evalúa**: en la fecha programada, el estudiante presenta la evaluación. El docente registra la nota.
6. **Consolidación**: si la nota es > 70.9, la asignatura queda aprobada con nota 71. Si es ≤ 70.9, queda reprobada con la nota original.

**Reválida extemporánea**: el Director Académico puede crear una reválida fuera de la ventana institucional cuando hay justificación válida (motivo de al menos 20 caracteres y máximo 500). Las reglas de elegibilidad se mantienen idénticas, solo se salta la validación de ventana.

**Regla de auditoría**: el estudiante elegible que el docente **no** marca para reválida queda, al cierre del grupo, como reprobado con su nota original. **No existe el estado "Pendiente de reválida indefinido"**: la gestión de reválidas debe completarse antes del cierre del grupo.

### 3.4. Módulos independientes

**Definición**: un módulo independiente es un curso corto (de 1 a 4 semanas) que se ofrece fuera del calendario regular, típicamente en periodos vacacionales, para que un estudiante pueda recuperar una asignatura reprobada sin esperar al siguiente periodo lectivo.

**Diferencias con una clase regular**:

| Aspecto | Clase regular | Módulo independiente |
|---|---|---|
| Modalidad | Presencial, virtual o mixta | Virtual asíncrona |
| Inscripción | Vía planificación, sin pago previo | Vía solicitud + pago previo obligatorio |
| Plazo | Periodo lectivo completo | 25 días desde la inscripción (configurable) |
| Costo | Incluido en la matrícula | Costo adicional por módulo |
| Cohorte | Múltiples estudiantes | Típicamente individual o pequeño grupo |
| Aprobación | Cierra al final del periodo | Cierra al entregar la actividad evaluada |

**Flujo**:

1. **Estudiante solicita** desde el LXP: elige la asignatura, elige el tipo de módulo (tronco común o materia especializada), confirma la solicitud.
2. **Sistema genera factura** en Odoo por el costo correspondiente.
3. **Estudiante paga** la factura.
4. **Sistema habilita inscripción**: al confirmarse el pago, la plataforma crea automáticamente la clase-módulo (virtual asíncrona), inscribe al estudiante y le da un plazo de 25 días para completar las actividades.
5. **Estudiante entrega**: sube sus actividades, el docente las califica.
6. **Cierre**: si aprueba, la nota se incorpora a su pensum en la asignatura correspondiente.

**Reglas de control**:
- Si el estudiante ya está inscrito en un módulo activo de la misma asignatura, no puede solicitar otro hasta cerrar el primero.
- Si el estudiante está retirado o aplazado, no puede solicitar módulos.
- Si pasan 30 días sin pago, la solicitud expira automáticamente (cron cada hora).
- Si pasan 25 días sin entrega, la inscripción vence y la asignatura queda como no cursada.

### 3.5. Homologaciones

**Definición**: una homologación es el reconocimiento de que una asignatura cursada en un plan de estudios **origen** (por ejemplo, la carrera de Soldadura) equivale a una asignatura de un plan de estudios **destino** (por ejemplo, otra carrera técnica). Al homologar, la asignatura destino se marca como aprobada con la nota que obtuvo el estudiante en la origen.

**Tipos de homologación** que el sistema soporta:

| Tipo | Cuándo aplica |
|---|---|
| **Suficiencia** | El estudiante aprobó un examen de suficiencia externo (por ejemplo, en otra institución) y la Dirección Académica lo reconoce |
| **Migración** | Cambio administrativo de un estudiante de un plan a otro (reestructuración de la oferta) |
| **Homologación** | Reconocimiento de equivalencia entre planes cuando el estudiante cambia de carrera |
| **Práctica** | Reconocimiento de experiencia laboral o práctica profesional como equivalente a una asignatura práctica |

**Flujo del gestor de homologaciones**:

1. **Director Académico define las reglas** origen→destino en el gestor. Cada regla es un par único de (plan origen, asignatura origen, plan destino, asignatura destino) y un tipo.
2. **Director Académico previsualiza**: la plataforma escanea todos los estudiantes inscritos en ambos planes y muestra los que tienen la asignatura origen aprobada pero no tienen la destino aprobada, proponiendo la homologación automática.
3. **Director Académico aplica**: la plataforma genera la homologación para todos los estudiantes propuestos, registra la nota de la origen en la destino, deja constancia del tipo y del autor.
4. **Auditoría**: cada homologación queda registrada con fecha, autor y observación. Es reversible solo por el Director Académico (quien debe dejar un motivo de al menos 20 caracteres).

**Regla importante**: si la asignatura destino ya estaba aprobada o ya estaba homologada, el sistema la salta (evita re-homologar). La homologación nunca baja una nota ya registrada.

### 3.6. Retiros y aplazamientos

**Definiciones**:

- **Aplazamiento** (`aplazado`): el estudiante pausa su trayectoria por un periodo lectivo completo, con la intención de retomar en el siguiente. Sus clases activas se cierran, su nivel no avanza, y al retomar vuelve al mismo nivel que cursaba.
- **Retiro** (`retirado`): el estudiante abandona el instituto. Sus clases se cierran, su nivel no avanza. Para volver, debe pasar por un proceso de readmisión.
- **Reactivación**: el estudiante que estaba aplazado o retirado decide volver. Se reactiva su matrícula y, si es viable, se le matricula en un nuevo periodo.

**Requisitos previos** (validaciones automáticas antes de procesar el cambio):

| Acción | Requisito |
|---|---|
| Aplazar | Tener un periodo lectivo activo al cual aplazar; no tener facturas en mora con un saldo superior al de un cuatrimestre |
| Retirar | Estar a paz y salvo con la administración (todas las cuotas del cuatrimestre pagadas, salvo el caso de primer cuatrimestre donde se requiere matrícula + primera cuota) |
| Reactivar | Haber sido aplazado, retirado, suspendido o desertor previamente |

**Flujo** (estudiante):

1. **Wizard de 5 pasos en el LXP**: motivo (libre, ≥ 10 caracteres), período objetivo (en caso de aplazamiento), confirmación, revisión, envío.
2. **Sistema muestra resumen**: cursos activos que se cerrarán, facturas pendientes (si las hay, se listan), impacto financiero.
3. **Estudiante confirma** con un checkbox de "Entiendo las consecuencias".
4. **Plataforma ejecuta el cambio**: cierra las clases activas, cambia el estado en la base académica del estudiante, registra la fecha, motivo y autor en la bitácora de historial académico.
5. **Sincronización con Odoo**: la plataforma notifica al ERP para que ajuste el plan de pagos del estudiante (reprograma vencimientos en caso de aplazamiento, cancela el contrato en caso de retiro).
6. **Notificación al estudiante**: recibe un correo de confirmación con el resumen del cambio.

**Trazabilidad**: cada cambio queda registrado en la bitácora de historial académico con tipo (aplazo / retiro / renovación), motivo, fecha, autor, y snapshot del estado anterior. La Dirección Académica puede ver esta bitácora desde el panel del estudiante.

### 3.7. Estado del estudiante (más allá de académico)

Un estudiante puede estar en uno de estos estados agregados, que el sistema usa para controlar el acceso:

| Estado | Significado | Acceso al LXP | Acceso al contenido | Puede matricularse |
|---|---|---|---|---|
| **Activo** | Estudiante regular con matrícula vigente | Sí | Sí | Sí |
| **Aplazado** | Pausó su trayectoria | Sí (modo lectura) | Limitado | No, hasta reactivar |
| **Retirado** | Abandonó el instituto | Sí (modo lectura) | No | No, hasta reactivar |
| **Suspendido** | Suspensión disciplinaria o administrativa | No | No | No |
| **Desertor** | No se reinscribió al siguiente periodo sin aviso | Limitado | Limitado | No |
| **Inactivo** | Estado técnico (mora prolongada o inasistencias) | Bloqueado temporalmente | Bloqueado | No |
| **Egresado** | Completó todas las asignaturas, en proceso de graduación | Sí | Sí (modo lectura) | No |
| **Graduado** | Recibió diploma | Sí (modo lectura) | Limitado | No |

**Reglas que ponen a un estudiante en inactividad**:
- Mora prolongada con la administración (el estudiante recibe múltiples avisos antes).
- Acumulación de inasistencias que supere el umbral en **todas** sus clases activas simultáneamente.
- Suspensión disciplinaria decidida por la Dirección.

**Reactivación automática**: si el estudiante paga su deuda, el sistema lo reactiva automáticamente en cuestión de minutos. Si fue suspendido por inasistencias y luego regulariza su asistencia, también se reactiva.

> **Ver también**: `docs/Procedimiento_Revalidas.md` para el procedimiento formal completo de reválidas; capítulo 4 de este informe para el detalle de cómo se controla la inactividad por asistencia.

---

## 4. Asistencia

La asistencia es uno de los procesos más críticos y más automatizados de la plataforma. La regla de oro es:

> **El docente nunca otorga presente. La presencia se gana por QR o por presencia confirmada en la sesión virtual de BigBlueButton.**

### 4.1. Política general

- **Estados posibles por sesión para un estudiante**:
  - **Presente** (P): el estudiante estuvo en clase. Se asigna automáticamente por QR o por BBB.
  - **Tarde** (R, "retraso"): el estudiante llegó después del inicio pero dentro de la ventana de tolerancia. Sigue contando como asistencia a efectos de porcentaje.
  - **Falta justificada** (FJ): el estudiante faltó con justificación válida (certificado médico, cita legal, etc.).
  - **Falta injustificada** (FI): el estudiante faltó sin justificación.

- **Asistencia del docente**: el docente registra manualmente las FJ, FI y R, pero **no registra P**. La P solo la asigna el sistema.

- **Soporte obligatorio**: cuando Bienestar o un operador modifican una marcación, deben subir un soporte (PDF, JPG o PNG, máximo 10 MB). El sistema conserva el soporte en el repositorio y lo muestra en el historial de marcaciones de la sesión.

### 4.2. Flujo del QR de asistencia

**Generación del QR (docente)**:

1. En su dashboard, el docente abre la clase que está dictando.
2. Pulsa "Proyectar QR". La plataforma genera un código QR rotativo (un token firmado con una vigencia corta, por defecto 40 segundos).
3. El QR se muestra en una pantalla grande en la sala de clase. Cada vez que el token vence, se regenera automáticamente.
4. El docente no necesita hacer nada más durante la clase; el QR sigue rotando.

**Escaneo del QR (estudiante)**:

1. El estudiante abre la LXP en su celular.
2. Escanea el QR de la pantalla con la cámara (la LXP detecta automáticamente que es un QR de asistencia).
3. La LXP envía la marcación al sistema.
4. El sistema valida en menos de 2 segundos:
   - Que el estudiante esté matriculado en esa clase.
   - Que esté al día con la administración (si está en mora, se rechaza y se le redirige a una pantalla de restricción de pago).
   - Que esté físicamente en el instituto (geofencing por IP registrada; las IPs válidas se registran desde el dashboard del docente).
   - Que la sesión esté dentro de la ventana de auto-registro (no se puede marcar asistencia antes ni mucho después).
   - Que el token del QR sea válido y vigente.
5. Si todo es correcto, se registra la asistencia y se redirige al estudiante a una pantalla de confirmación.
6. Si algo falla, se le muestra un mensaje claro del motivo (no en clase, en mora, QR vencido, etc.).

**Beneficios del diseño**:
- Elimina la planilla de asistencia manual (no se puede fraguar).
- Elimina la posibilidad de marcar asistencia por un compañero (cada token es único por sesión y por QR visible).
- Detecta automáticamente a los estudiantes en mora y los bloquea de tomar asistencia (motivación para pagar).

### 4.3. Flujo de BigBlueButton (clases virtuales y mixtas)

**Inicio de la sesión**:
1. La clase tiene un módulo de BigBlueButton asociado, creado automáticamente al aprobar la clase.
2. Cuando llega la hora de la sesión, el docente hace clic en "Entrar a la sala" desde su dashboard o desde la pantalla de la sesión.
3. La sala se abre en una nueva pestaña con el docente como moderador.
4. Los estudiantes entran desde su horario en el LXP.

**Grabación obligatoria**:
- La plataforma activa la grabación automática al iniciar la sesión. El docente no puede detenerla.
- La grabación se almacena en el servidor de BigBlueButton y queda disponible como material de la clase.
- Los estudiantes que no pudieron asistir en vivo pueden ver la grabación después.
- Si un estudiante ve la grabación completa, puede auto-marcar su asistencia (con confirmación de que la vio).

**Marcado automático de presencia por BBB**:
- La plataforma hace polling cada 2 minutos del servidor de BigBlueButton para saber quién está conectado a cada reunión.
- Si un estudiante estuvo conectado ≥ 70 % del tiempo de la sesión, se le marca como **Presente**.
- Si estuvo entre 40 % y 70 %, se le marca como **Tarde**.
- Si estuvo menos de 40 %, no se le asigna presencia (queda como pendiente; el docente decide).

### 4.4. Marcado manual por el docente

El docente solo usa el marcado manual para registrar:
- **Faltas justificadas** (FJ) cuando el estudiante trae un soporte al final de la clase.
- **Faltas injustificadas** (FI) para quienes no asistieron y no trajeron soporte.
- **Retrasos** (R) para quienes llegaron después del inicio.

El docente puede corregir su propia marcación ("deshacer mi marca") mientras la sesión no haya sido procesada por el sistema de alertas.

### 4.5. Marcado por Bienestar (operador)

Cuando un estudiante se acerca **después** de la clase con una justificación (certificado médico, etc.), Bienestar puede:
1. Acceder al panel de asistencia de la clase.
2. Buscar al estudiante.
3. Cambiar su marcación (de FI a FJ, por ejemplo), adjuntando el soporte obligatorio.
4. La marcación queda registrada con la fecha, el autor (Bienestar) y el archivo de soporte adjunto.

Bienestar también puede:
- Levantar un bloqueo de clase manualmente (si un estudiante fue bloqueado y la situación se resolvió).
- Recalcular el estado de asistencia de un estudiante después de una corrección.
- Cambiar el estado académico del estudiante (aplazar, retirar, reactivar) directamente desde el panel.

### 4.6. Bloqueo por inasistencias

**Regla institucional** (configurable por la Dirección Académica, por defecto umbral de 3 ausencias):

- Un estudiante recibe una **alerta informativa** en el LXP cuando acumula 1 falta en una clase.
- Recibe un **popup de advertencia** (no se puede cerrar fácilmente) cuando acumula 2 faltas, indicando que a la siguiente perderá el acceso.
- Es **bloqueado** de esa clase cuando acumula 3 faltas, perdiendo el acceso al contenido y a las actividades de esa clase.
- El sistema evalúa la regla global de inactividad: si **todas** las clases activas del estudiante superan el umbral simultáneamente, el estudiante pasa a estado **Inactivo** en la plataforma (no puede entrar al LXP hasta regularizar).

**Excepciones**:
- Si el estudiante estaba en mora y regulariza, se reactiva automáticamente.
- Si el estudiante fue exento por Bienestar o la Dirección (por ejemplo, en una emergencia institucional), se levanta el bloqueo.
- El sistema lleva un registro histórico de cada transición (alerta, bloqueo, reactivación) con fecha y motivo.

### 4.7. Sesiones de reválida

Las sesiones de BigBlueButton creadas para aplicar una reválida están marcadas con un flag especial y **no cuentan** para el cálculo de asistencia. Esto es importante porque:
- Una reválida típicamente ocurre la semana siguiente al cierre del grupo.
- Si contara, el estudiante vería incrementado su conteo de faltas sin haber faltado realmente.
- El flag se aplica automáticamente al crear la sesión desde el flujo de reválida, pero puede ajustarse manualmente si hay un caso especial.

### 4.8. Reportes de asistencia

- **Por clase**: la Secretaría Académica puede descargar un PDF con la matriz completa de asistencia de una clase (estudiantes × sesiones).
- **Por estudiante**: el estudiante ve su conteo de faltas por clase en su dashboard y en la pantalla de la clase.
- **Por cohorte**: el Director Académico puede ver el consolidado de asistencia por cohorte, identificando tendencias.
- **Por periodo**: el reporte consolidado del periodo permite identificar patrones (qué asignaturas tienen mayor ausentismo, qué docentes tienen mayor adherencia).

---

## 5. Calificaciones

### 5.1. Escala oficial de calificaciones

La plataforma usa una escala de letras con puntos ponderados para el índice académico.

| Letra | Rango numérico | Concepto | Puntos (escala 0-3) | Puntos (escala 0-4) | Color en pantalla |
|---|---|---|---|---|---|
| A | 91 – 100 | Sobresaliente | 3.0 | 4.0 | Verde |
| B | 81 – 90 | Buena | 2.0 | 3.0 | Verde |
| C | 71 – 80 | Regular | 1.0 | 2.0 | Verde |
| D | 61 – 70 | No satisface | 0.0 | 1.0 | Ámbar |
| F | 0 – 60 | Fracasa | 0.0 | 0.0 | Rojo |

**Regla institucional de aprobación** (estricta):
- Una nota **mayor a 70.9** aprueba la asignatura.
- Una nota entre **60.0 y 70.9** es susceptible de reválida **solo si la asignatura no tiene horas prácticas**.
- Una nota **menor a 60.0** reprueba la asignatura sin posibilidad de reválida.

**Regla de consolidación**:
- Cuando un estudiante aprueba una reválida, la nota final registrada es **71** (la nota mínima de aprobación), independientemente de la nota que haya sacado en la reválida.
- Esto homogeniza las notas de reválida aprobadas para que no aparezcan como outliers en el índice académico.

**Escala de índice académico**:
- El índice académico se calcula como un promedio ponderado por créditos de los puntos obtenidos en cada asignatura.
- La escala por defecto es 0-3 (donde 3.0 es el máximo posible). Algunas carreras técnicas usan escala 0-4 (donde 4.0 es el máximo).
- La escala es configurable por plan de estudios.

### 5.2. Libro de calificaciones del docente

El **libro de calificaciones** es la herramienta central del docente para evaluar. Permite:

- **Crear actividades** (tareas, cuestionarios, foros evaluables, asistencia, etc.) con su peso porcentual sobre la nota final.
- **Editar actividades** existentes (cambiar fechas, agregar/quitar puntos, modificar instrucciones).
- **Calificar** a los estudiantes por actividad.
- **Configurar categorías**: las actividades se agrupan en categorías (por ejemplo, "Parciales", "Tareas", "Proyecto final") con sus propios pesos.
- **Ver la nota ponderada** de cada estudiante en tiempo real.
- **Ver el estado de cada estudiante** (Aprobado, Reprobado, Pendiente de reválida, etc.) y los elegibles para reválida.

**Regla institucional de pesos**:
- La suma de los pesos de todas las actividades y categorías **debe ser exactamente 100 %**.
- Si los pesos no suman 100 %, el sistema no permite programar reválidas (advierte al docente con un banner rojo).
- Si pasan 21 días desde el inicio de la clase y los pesos siguen sin sumar 100 %, el sistema envía una alerta al docente y al docente de apoyo.

### 5.3. Cálculo del ponderado

La nota final de un estudiante en una clase se calcula con la siguiente fórmula:

> **Nota final = Σ (nota de la actividad / nota máxima de la actividad) × peso de la actividad**

Por ejemplo, si una clase tiene tres actividades:
- Examen parcial: 30 % de peso, nota máxima 100, estudiante sacó 80.
- Tareas: 30 % de peso, nota máxima 100, estudiante sacó 90.
- Proyecto final: 40 % de peso, nota máxima 100, estudiante sacó 70.

La nota final sería: (80/100 × 30) + (90/100 × 30) + (70/100 × 40) = 24 + 27 + 28 = **79**.

El resultado se redondea a **1 decimal** (79.0 en el ejemplo).

**Importante**:
- La asistencia cuenta como una actividad más con su propio peso (configurado por el docente, típicamente entre 5 % y 10 %).
- La nota de la asistencia **no se calcula por porcentaje de faltas** sino por la fórmula estándar: (sesiones asistidas / sesiones totales) × peso.

### 5.4. Cálculo del índice académico acumulado

El índice académico se calcula como:

> **Índice = Σ (puntos de la letra × créditos de la asignatura) / Σ (créditos de las asignaturas cursadas)**

Por ejemplo, si un estudiante cursó 4 asignaturas con créditos (4, 3, 4, 3) y obtuvo letras (B, A, C, B), en escala 0-3 su índice sería:
- B = 2 puntos, A = 3 puntos, C = 1 punto, B = 2 puntos
- (2×4 + 3×3 + 1×4 + 2×3) / (4+3+4+3) = (8+9+4+6) / 14 = 27 / 14 = **1.93**

### 5.5. Importación masiva de notas

Para las carreras que aún usan el sistema externo Q10 para algunas calificaciones, la Secretaría Académica puede:

1. Descargar un archivo Excel con el formato esperado por la plataforma.
2. Llenarlo con las calificaciones de los estudiantes (una fila por estudiante-asignatura).
3. Subirlo a la plataforma.
4. El sistema valida el archivo y, si todo es correcto, importa las notas aplicando automáticamente las reglas:
   - Nota ≥ 71 → marca la asignatura como Aprobada.
   - Nota entre 60.0 y 70.9 → marca como Reprobada (queda elegible para reválida solo si no tiene horas prácticas).
   - Nota < 60 → marca como Reprobada.
   - El estado del Excel se sobrescribe con la clasificación automática (para evitar errores humanos).

### 5.6. Bitácora de movimientos académicos

Cada vez que cambia una nota, se registra un movimiento en la bitácora con:
- Fecha y hora del cambio.
- Autor del cambio (docente, Secretaría, Director, sistema).
- Motivo del cambio (cuando aplica).
- Valor anterior y valor nuevo.
- Origen del cambio (cierre de clase, reválida, homologación, importación, etc.).

La bitácora es **inmutable**: una vez escrito, un movimiento no se borra. Si se necesita revertir, el Director Académico crea un nuevo movimiento de "anulación" que marca el original como anulado (sin eliminarlo), con la justificación obligatoria.

Esta bitácora permite:
- Auditar quién cambió qué nota y cuándo.
- Resolver disputas de calificaciones.
- Justificar cambios ante autoridades regulatorias.

### 5.7. Notas de módulos independientes

Cuando un estudiante completa un módulo independiente, su nota se incorpora automáticamente a la asignatura correspondiente en su pensum, con un origen identificable (cierre de módulo). Esto permite que un módulo aprobado "mueva" la nota reprobada anterior hacia arriba si es mejor.

### 5.8. Notas de homologación

Las homologaciones registran su propia nota en la asignatura destino, con origen "homologación". El sistema toma la mejor nota histórica del estudiante para cada asignatura: si la nota de la homologación es mejor que la nota que ya tenía (por ejemplo, porque viene de un origen donde sacó 85), gana la homologación. Si es peor (por ejemplo, 65), gana la nota original (porque 65 no es aprobatoria, pero 65 sí era aprobatoria en el origen).

**Reversión**: si se revierte una homologación, la nota de la asignatura destino vuelve a 0 y el estado vuelve a "Disponible" para que el estudiante la curse normalmente.

---

## 6. Diplomas y certificados

### 6.1. Requisitos de elegibilidad

Un estudiante es **elegible para diploma** cuando cumple **simultáneamente**:

1. Está en estado **Activo** o **Egresado** (no retirado, no suspendido).
2. Ha aprobado **todas** las asignaturas obligatorias de su plan de estudios.
3. No tiene un diploma previo generado para ese plan (los diplomas no se duplican).
4. Para los certificados individuales por curso: la asignatura específica debe estar aprobada y el curso debe estar marcado como elegible para certificación.

### 6.2. Tipos de certificación

La plataforma soporta dos tipos de certificación:

| Tipo | Qué certifica | Requisito |
|---|---|---|
| **Diploma de graduación** | Finalización completa de la carrera | 100 % de obligatorias aprobadas |
| **Certificado por curso individual** | Aprobación de una asignatura específica | La asignatura aprobada y marcada como elegible para certificación |

Los certificados por curso individual se usan para que un estudiante que abandona la carrera pueda certificar las asignaturas que sí aprobó, útil para transferencias a otras instituciones o para revalidaciones externas.

### 6.3. Plantillas y bundles

Un **diploma** se genera a partir de una **plantilla** que define:
- Tamaño y orientación (típicamente A4 horizontal).
- Imagen de fondo (sello, marco institucional, escudo).
- Variables a imprimir: nombre del estudiante, cédula, carrera, fecha, número de diploma, etc.
- Posición exacta de cada variable sobre el diploma (coordenadas en milímetros, fuente, tamaño, color, alineación).

Un **bundle** es un grupo de plantillas que comparten un **prefijo externo** y un **contador consecutivo común**. Esto permite que, si el instituto tiene un consecutivo externo para diplomas de una carrera, todas las plantillas asociadas a ese bundle hereden el mismo prefijo y se numeren correlativamente. La administración define los bundles según sus procesos regulatorios.

### 6.4. Flujo de generación (operador de Registros)

1. **Operador abre el panel de diplomas**.
2. **Filtra por carrera** y por estado (solo pendientes vs. todos los elegibles).
3. **Revisa la lista de estudiantes elegibles** (la plataforma muestra: nombre, cédula, carrera, índice académico, fecha de última asignatura aprobada).
4. **Selecciona los estudiantes** a los que generará diploma.
5. **Elige la plantilla** a usar.
6. **Pulsa "Generar"**. La plataforma:
   - Asigna un número consecutivo único (siguiendo el contador del bundle si aplica).
   - Genera el PDF con la plantilla y los datos del estudiante.
   - Almacena el PDF en el repositorio institucional.
   - Genera un **token de verificación** único (URL pública para que cualquier persona pueda verificar la autenticidad del diploma sin necesidad de login).
   - Registra la generación en la bitácora con fecha, autor, plantilla y datos del estudiante.
7. **El diploma se descarga** automáticamente y el operador puede imprimirlo o enviarlo al estudiante.

### 6.5. Verificación pública

Cada diploma generado tiene una URL única del tipo:

> `https://lms.isi.edu.pa/local/grupomakro_core/pages/diploma_verify.php?token=<token-de-64-caracteres>`

Esta URL es **pública** (no requiere login). Cuando alguien accede a ella, la plataforma muestra:
- Una pantalla con el sello institucional.
- El nombre del estudiante, la carrera y la fecha de emisión.
- El estado del diploma (**Válido** o **Revocado**).

Si el token no existe o fue revocado, la plataforma muestra un mensaje genérico "Diploma no encontrado" **sin distinguir** entre token inválido y diploma revocado, para evitar que un atacante use la plataforma como oráculo para saber qué tokens son válidos.

### 6.6. Revocación

Un diploma puede ser revocado cuando:
- Se descubre que se otorgó con información fraudulenta.
- La Dirección Académica lo determina por una causa justificada.
- El diploma original se perdió y se emitió uno nuevo (el viejo se revoca).

La revocación la hace el operador de Registros o el Director Académico. Debe dejar un motivo obligatorio. Una vez revocado, el diploma sigue apareciendo en la URL de verificación pero con el estado "Revocado" en lugar de "Válido".

### 6.7. Certificados por curso individual

El flujo es idéntico al de diplomas de graduación, pero:
- Se generan por asignatura específica.
- La asignatura debe estar en la lista de cursos elegibles para certificación (gestionable por el operador).
- El número consecutivo puede ser de un bundle diferente al de los diplomas de graduación.

---

## 7. Cartas y constancias

### 7.1. Catálogo de tipos de carta

El catálogo lo gestiona Registros Académicos. Cada tipo de carta tiene:
- **Nombre** (ej. "Constancia de estudio", "Carta de buena conducta", "Certificado de calificaciones").
- **Descripción** (qué acredita, para qué sirve).
- **Costo** (en USD, puede ser cero para cartas gratuitas).
- **Requisitos** (qué información debe traer el estudiante, qué soporte debe adjuntar).
- **Plantilla** (qué variables se imprimen en la carta, formato).
- **Tiempo de procesamiento** estimado.

Los tipos de carta que típicamente ofrece el instituto son:
- Constancia de estudio simple.
- Constancia de estudio con calificaciones.
- Constancia de horario.
- Carta de buena conducta.
- Carta de no sancionado.
- Certificado de calificaciones completo.
- Carta de retiro (para los estudiantes que se retiran).
- Carta dirigida a terceros (carta personalizada para empleadores, embajadas, etc.).

### 7.2. Solicitud por el estudiante

**Flujo en el LXP** (wizard de 3 pasos):

1. **Paso 1 — Tipo de carta**: el estudiante elige del catálogo. Ve el costo, los requisitos y el tiempo de procesamiento.
2. **Paso 2 — Datos y soporte**: completa la información específica (destinatario, motivo, datos adicionales) y sube los soportes requeridos (cédula escaneada, comprobante de pago, etc.).
3. **Paso 3 — Confirmación**: revisa el resumen, confirma y envía.

**Después del envío**:
1. La plataforma genera la solicitud en la bandeja de Registros.
2. Registros la revisa y, si requiere pago, genera la factura en Odoo.
3. La factura llega al LXP del estudiante con un enlace de pago.
4. El estudiante paga.
5. Odoo notifica automáticamente a Moodle al confirmarse el pago.
6. Registros genera el PDF de la carta con los datos del estudiante.
7. El PDF queda adjunto a la factura en Odoo y disponible en el LXP del estudiante.

### 7.3. Tiempos de procesamiento

- **Solicitudes sin pago** (cartas gratuitas): se procesan típicamente en 24-48 horas hábiles.
- **Solicitudes con pago confirmado**: se procesan en 24 horas hábiles tras la confirmación del pago.
- **Solicitudes urgentes**: si el instituto ofrece un servicio express, el estudiante paga un recargo y la carta se procesa en 4-6 horas hábiles.

---

## 8. Perspectiva del docente

### 8.1. Entrada y dashboard

Cuando un docente inicia sesión, la plataforma lo redirige automáticamente a su dashboard. El dashboard muestra:

- **Tres tarjetas de resumen** en la parte superior:
  - Cursos activos: número de clases que está dictando en el periodo actual.
  - Estudiantes: total de estudiantes matriculados en sus clases.
  - Tareas pendientes: número de actividades por calificar acumuladas.

- **Tarjetas por clase** (una por clase que dicta): muestran el nombre de la asignatura, el horario, el aula, el número de estudiantes, y la próxima sesión. Si hay ponderaciones incompletas en alguna clase, una franja roja lo indica con un enlace directo para corregirlas.

- **Calendario** con todas las sesiones programadas (de todas sus clases) en vista mes/semana/día. Las sesiones de clase, las grabaciones BBB y los eventos académicos aparecen con códigos de color distintos.

- **Listado de próximas sesiones** con los siguientes 7 días, para tener visibilidad rápida de la agenda.

### 8.2. Gestión de una clase (pestaña por clase)

Al hacer clic en una clase, el docente entra a la vista de gestión de esa clase. Esta vista tiene **7 pestañas**:

| Pestaña | Qué hace |
|---|---|
| **Sesiones** | Línea de tiempo de todas las sesiones (pasadas, presentes, futuras). Permite ver el estado de cada sesión, entrar a la sala BBB, generar el QR de asistencia, reprogramar o cancelar sesiones. |
| **Estudiantes** | Listado de estudiantes matriculados con su información académica. Permite ver el detalle de cada uno (calificaciones, asistencia, prelaciones, estado). |
| **Por calificar** | Listado consolidado de tareas y cuestionarios pendientes de calificar, con enlace directo al calificador rápido. |
| **Calificaciones** | Libro de calificaciones completo. Configura actividades, pesos, categorías. Ve la nota ponderada de cada estudiante en tiempo real. Identifica elegibles para reválida. |
| **Actividades** | Listado de actividades existentes. Permite crear nuevas actividades (tareas, cuestionarios, foros, sesiones BBB, etc.) mediante un wizard. |
| **Avisos** | Foro de novedades de la clase. El docente publica anuncios (fechas importantes, cambios de horario, etc.) y los estudiantes pueden comentar. |
| **Asistencias** | Matriz editable de asistencia. El docente puede marcar FJ, FI y R, pero **nunca P** (la presencia la asigna el sistema). Ve también la alerta de inactivación si un estudiante se acerca al umbral. |

### 8.3. Crear actividades

El docente puede crear 6 tipos de actividades:

1. **Tarea** (`assignment`): subida de archivos y/o respuesta en línea. Tiene fecha de apertura, fecha de cierre (con hora), nota máxima, instrucciones, archivos adjuntos de soporte.
2. **Cuestionario** (`quiz`): banco de preguntas configurable. Tiene fecha de apertura, fecha de cierre, tiempo límite, número de intentos permitidos, método de calificación (primer intento, último intento, mejor intento, promedio).
3. **Foro** (`forum`): espacio de discusión. Puede ser de novedades (solo el docente publica), de discusión general (todos publican) o de preguntas y respuestas.
4. **BigBlueButton** (`bbb`): sala virtual. Se crea con horario programado y grabación automática.
5. **Asistencia** (`attendance`): sesiones con marcación por QR.
6. **Material** (`resource` o `page`): archivos para descargar, enlaces, páginas HTML con contenido.

El wizard de creación guía al docente paso a paso según el tipo elegido.

### 8.4. Calificar (ruta individual)

**Ruta 1: Calificador rápido** (recomendado para tareas y cuestionarios):
1. El docente entra a "Por calificar".
2. Ve la lista de actividades pendientes de calificar en todas sus clases.
3. Hace clic en una actividad.
4. Ve todas las entregas en una grilla compacta.
5. Ingresa la nota de cada estudiante (con teclado, puede tabular rápido).
6. Pulsa "Guardar" y pasa a la siguiente actividad.

**Ruta 2: Libro de calificaciones** (recomendado para configuración y vista panorámica):
1. El docente entra a la pestaña "Calificaciones".
2. Ve la lista de estudiantes con su nota ponderada en cada categoría.
3. Puede expandir cada categoría para ver las notas por actividad.
4. Puede hacer clic en una actividad para calificarla individualmente.
5. Puede ver el detalle de un estudiante haciendo clic en su nombre (se abre el panel académico del estudiante con todas sus calificaciones de todas las clases).

### 8.5. Calificar (ruta grupal)

Para actividades en grupo (por ejemplo, un proyecto de equipo), el docente puede:
1. Abrir la actividad en el calificador.
2. Identificar los grupos formados.
3. Ingresar una nota grupal (se aplica a todos los miembros).
4. Si un miembro del grupo tuvo un rendimiento muy diferente, puede sobrescribir su nota individual.

### 8.6. Marcar asistencia

El docente marca asistencia **solo** para registrar:
- **FJ** (falta justificada): cuando el estudiante trae un soporte al final de la clase (certificado médico, cita legal, etc.).
- **FI** (falta injustificada): cuando el estudiante no asistió y no trajo soporte.
- **R** (retraso): cuando el estudiante llegó después del inicio.

**Nunca** marca **P** (presente). La P la asigna automáticamente el sistema (por QR o por BBB).

Si el docente marca una asistencia por error, puede **deshacer su marca** siempre que la sesión no haya sido procesada por el sistema de alertas.

### 8.7. Proyectar el QR de asistencia

1. En la pestaña "Sesiones" de la clase, el docente hace clic en la sesión actual (o en el botón "Proyectar QR" del dashboard).
2. La plataforma genera un QR rotativo en una ventana de pantalla completa.
3. El docente proyecta esa ventana en la pantalla grande de la sala de clase.
4. El QR se regenera automáticamente cada 40 segundos (para evitar que un estudiante lo capture con el celular y marque a sus compañeros desde fuera).
5. El docente no necesita hacer nada más; la marcación es automática.

### 8.8. Grabar la clase (BigBlueButton)

1. Cuando llega la hora de la sesión, el docente hace clic en "Entrar a la sala virtual" desde la sesión correspondiente.
2. La sala se abre en una nueva pestaña. El docente entra como **moderador** (puede silenciar, expulsar, gestionar la grabación, etc.).
3. La grabación se inicia **automáticamente** al entrar. El docente no puede detenerla.
4. Al finalizar la clase, el docente cierra la sala. La grabación queda almacenada en el servidor.
5. Los estudiantes pueden ver la grabación desde su horario, dentro de la misma sesión.
6. Los estudiantes que ven la grabación completa pueden auto-marcar su asistencia (con confirmación de que la vieron).

### 8.9. Programar y eliminar sesiones

El docente puede **copiar** una sesión (crear hasta 20 sesiones adicionales con la misma configuración), **eliminar** una sesión (con justificación), o **reprogramar** una sesión (cambiar fecha/hora). Cada acción tiene validaciones:
- **Copiar**: verifica que no haya conflicto con sesiones existentes.
- **Eliminar**: si ya hay marcaciones de asistencia, requiere confirmación explícita. Siempre deja un respaldo en formato JSON.
- **Reprogramar**: actualiza el calendario, la grabación BBB, y la relación entre ambos.

### 8.10. Información del estudiante que ve el docente

Al hacer clic en un estudiante (desde la pestaña "Estudiantes" o desde el calificador), el docente ve el **panel académico del estudiante**, que muestra:

- **Datos básicos**: nombre, cédula, carrera, cohorte, jornada.
- **Pensum completo**: todas las asignaturas del plan con su estado, nota y letra.
- **Calificaciones en esta clase**: detalle por actividad.
- **Asistencia en esta clase**: sesiones totales, asistidas, faltas (con desglose de FJ/FI/R).
- **Estado de prelaciones**: qué asignaturas tiene disponibles para cursar y cuáles están bloqueadas por prelación.
- **Historial académico**: cambios de estado (aplazos, retiros, reactivaciones) con fecha y motivo.
- **Reválidas pendientes**: si el estudiante es elegible para reválida en esta clase u otras.
- **Información financiera parcial**: indicador visual de si el estudiante está al día o en mora (sin mostrar el detalle de la deuda, por privacidad).

**Lo que el docente NO ve del estudiante**:
- Datos financieros detallados (montos, facturas, planes de pago).
- Historial médico o de citas de psicología.
- Información de otros estudiantes.
- Datos de contacto personal más allá de los necesarios para la comunicación académica.

### 8.11. Comunicación con el estudiante

El docente tiene **tres canales** para comunicarse con el estudiante:

1. **Mensajería interna de Moodle**: aparece como un ícono de sobre en la cabecera. Permite conversaciones uno-a-uno con cualquier estudiante. El estudiante recibe las notificaciones en su LXP.
2. **Foro de la clase**: el docente publica avisos en la pestaña "Avisos" de la clase. Los estudiantes pueden comentar. Es el canal ideal para anuncios generales (cambio de horario, recordatorio de fecha de examen, etc.).
3. **Chat dentro del BigBlueButton**: durante la sesión virtual, el docente puede responder preguntas por chat o por voz a los estudiantes presentes.

---

## 9. Perspectiva del estudiante

### 9.1. Entrada y dashboard

Cuando un estudiante inicia sesión, la plataforma lo redirige al LXP (`lms.isi.edu.pa/students`). El dashboard muestra, según aplique:

- **Saludo personalizado** con su nombre.
- **Puntos de gamificación** (un contador de XP que sube a medida que completa actividades y mantiene buena asistencia).
- **Cuatro accesos rápidos circulares**:
  - Cursos (cantidad de cursos en curso).
  - Tiempo dedicado (horas estudiadas estimadas).
  - Mi horario (con un badge si tiene sesiones próximas).
  - Bienestar.

- **Módulos activos pendientes** (si tiene módulos independientes en curso): una tarjeta por cada módulo, con el conteo regresivo de días restantes (rojo si ya venció, naranja si quedan 3 días o menos).

- **Cursos en curso**: tarjetas con imagen, nombre, barra de progreso, ícono de inasistencias (si tiene), botón para entrar.

- **Cursos completados**: pestañas separadas, mismo formato de tarjeta.

- **Categorías** (si la plataforma las usa para agrupar cursos): rejilla de categorías con contador de cursos.

### 9.2. Entrar a un curso

Al hacer clic en un curso, el estudiante ve:
- **Menú lateral** con todos los temas/módulos del curso (colapsable).
- **Contenido del tema seleccionado** (actividades, materiales, foros, etc.) en el panel principal.
- **Botón de marcar como completado** (para actividades que el estudiante puede auto-completar).
- **Temporizador de tiempo dedicado** (registra el tiempo que el estudiante pasa en la actividad).
- **Navegación** entre actividades (anterior/siguiente).

### 9.3. Subir tareas

Para entregar una tarea, el estudiante:
1. Abre la tarea desde el contenido del curso.
2. Lee las instrucciones y los archivos adjuntos que dejó el docente.
3. Sube sus archivos (uno o varios, según las instrucciones) o escribe su respuesta en línea.
4. Agrega un comentario opcional.
5. Pulsa "Entregar".
6. Si la tarea tiene fecha de cierre y quedan días, puede re-entregar y la nueva versión reemplaza la anterior.
7. Si la fecha de cierre ya pasó, la plataforma le indica que la entrega está cerrada.
8. Si tiene una **excepción de fecha** individual (por ejemplo, una prórroga médica aprobada por el docente), la fecha de cierre efectiva se ajusta automáticamente.

### 9.4. Responder cuestionarios

Para responder un cuestionario, el estudiante:
1. Abre el cuestionario desde el contenido del curso.
2. Lee las instrucciones.
3. Pulsa "Iniciar intento" (si el cuestionario está dentro de la ventana de apertura).
4. Responde las preguntas (la plataforma puede mezclar el orden, mostrar una por una, etc., según la configuración del docente).
5. Puede guardar respuestas intermedias y continuar después (si el cuestionario lo permite).
6. Cuando termina, pulsa "Enviar todo y terminar".
7. Si supera el tiempo límite, la plataforma envía automáticamente lo que haya respondido.
8. Si quedan intentos, puede volver a iniciar (respetando las reglas del docente: primer intento, mejor intento, etc.).
9. Cuando el docente califica, el estudiante ve su nota en el libro de calificaciones y, si el docente dejó retroalimentación, la puede leer.

### 9.5. Ver calificaciones

El estudiante tiene acceso a su **libro de calificaciones personal** desde el menú del curso o desde su perfil. Ve:
- **Por cada actividad**: nombre, nota obtenida, nota máxima, porcentaje, peso en la nota final, contribución ponderada, retroalimentación del docente (si la hay).
- **Por cada categoría**: total ponderado de la categoría.
- **Nota final del curso**: la nota ponderada total con su letra y color.
- **Índice académico acumulado**: el índice global del estudiante con todas las asignaturas cursadas y su acumulado.

Si una asignatura es **elegible para reválida**, aparece un indicador especial con un botón para acceder al detalle.

### 9.6. Asistencia

**Marcar asistencia por QR** (ya descrito en 4.2):
- El estudiante escanea el QR proyectado por el docente.
- Si todo es correcto, ve una pantalla de confirmación.
- Si está en mora, ve una pantalla de restricción de pago con instrucciones para regularizar.

**Ver sus faltas**:
- En cada curso, junto al nombre, aparece un ícono de información si tiene 1 o 2 faltas, con un tooltip que le avisa que a la siguiente podría perder el acceso.
- En el dashboard, un popup persistente le avisa cuando llega a 2 faltas (no se cierra fácilmente hasta que pulse "Entiendo").
- Si llega a 3 faltas, es **bloqueado** del curso. Al intentar entrar, ve una pantalla que le indica que debe acercarse al área académica para revisar su caso.

### 9.7. Reválidas

Si el estudiante es elegible para una reválida, la plataforma le muestra:
- Un **popup persistente en el dashboard** con el nombre de la asignatura, la fecha de la sesión BBB, el costo y un enlace de pago.
- Una opción para "No volver a mostrar este mensaje" (lo silencia para esa reválida específica, pero vuelve a aparecer si se programa una nueva).
- Una página dedicada "Mis reválidas" donde puede ver el detalle de cada una (pagada, pendiente, aprobada, reprobada).
- Al hacer clic en "Pagar", es redirigido a la plataforma de pago. Al pagar, Odoo notifica a Moodle y el estado cambia a "Pagada".
- En la fecha programada, el estudiante entra a la sesión BBB (desde el popup, desde su horario, o desde el enlace en la página de reválidas).
- Después de la sesión, el docente registra la nota. El estudiante ve el resultado en su panel académico: "Aprobó reválida (71)" o "Reprobó reválida".

### 9.8. Módulos independientes

**Solicitar un módulo**:
1. Desde el dashboard, el estudiante ve sus módulos activos. Si no tiene, puede explorar el catálogo de módulos disponibles.
2. Elige la asignatura y el tipo (tronco común / materia especializada).
3. Confirma la solicitud.
4. La plataforma genera una factura en Odoo.
5. El estudiante paga desde su LXP.
6. Al confirmarse el pago, se crea la clase-módulo y se inscribe al estudiante con un plazo de 25 días.

**Trabajar en el módulo**:
- El estudiante accede al contenido desde su dashboard.
- Entrega las actividades según las instrucciones del docente.
- El docente califica.
- Si aprueba, la nota se incorpora a su pensum.

**Si vence el plazo sin entrega**:
- La inscripción se marca como vencida.
- La asignatura queda como no cursada.
- El estudiante puede volver a solicitarla (pagando de nuevo).

### 9.9. Solicitar cartas y constancias

**Wizard de 3 pasos** (ya descrito en 7.2):
1. Elige el tipo de carta.
2. Completa los datos y sube los soportes.
3. Confirma y envía.

**Después**:
- Ve el estado de su solicitud en "Mis solicitudes" (en revisión, pendiente de pago, en proceso, lista, rechazada).
- Si requiere pago, ve el enlace de pago.
- Cuando la carta está lista, recibe una notificación y puede descargarla desde su LXP.

### 9.10. Solicitar aplazamiento o retiro

**Wizard de 5 pasos**:
1. **Aviso**: la plataforma le informa el impacto del cambio (clases que se cerrarán, requisitos financieros).
2. **Datos personales**: confirma su información.
3. **Motivo**: elige una categoría (económica, laboral, personal, salud, residencia, otra) y escribe el detalle.
4. **Pago** (en algunos casos): si tiene saldo pendiente, la plataforma le muestra las opciones (paz y salvo, cambio de carrera, transferencia de derechos).
5. **Confirmación**: revisa el resumen y envía.

**Después**:
- La plataforma muestra el resultado de la solicitud.
- Si es aprobada, el estudiante ve el cambio reflejado en su estado académico.
- Si es rechazada, recibe una notificación con el motivo.

### 9.11. Bienestar

El estudiante tiene una sección de Bienestar en su LXP con **5 entradas principales**:

1. **Convenios**: lista de empresas con descuentos y beneficios para estudiantes del instituto (por ejemplo, restaurantes, librerías, gimnasios con descuento).
2. **Eventos**: lista de eventos institucionales próximos (ferias, conferencias, actividades deportivas, talleres). El estudiante puede registrarse a los que le interesen.
3. **Mis inscripciones**: lista de eventos a los que ya se inscribió.
4. **Psicología**: agenda de citas con el psicólogo del instituto. El estudiante puede ver los slots disponibles, solicitar una cita, y ver el estado de sus citas (solicitada, confirmada, asistida, no asistida).
5. **Mi carnet**: carnet digital con un QR único que el estudiante puede usar para identificarse en eventos del instituto.

Adicionalmente, después de cada clase, puede aparecer un **popup de evaluación docente** (5 estrellas + comentario opcional) que el estudiante completa para dar retroalimentación sobre la sesión.

### 9.12. Anuncios institucionales

El estudiante recibe **anuncios broadcast** que la administración o Bienestar envía a su carrera o cohorte. Los anuncios aparecen como un modal persistente en el LXP hasta que el estudiante los lee y los cierra. Algunos anuncios requieren acuse de recibo (con un checkbox "Entendido") y la plataforma lleva estadísticas de qué estudiantes los reconocieron.

### 9.13. Comunicación con el docente

El estudiante tiene **tres canales** para comunicarse con su docente:

1. **Mensajería interna**: un ícono de sobre en la cabecera del LXP. Abre conversaciones uno-a-uno con cualquier docente. El docente recibe las notificaciones en Moodle.
2. **Foro de la clase**: dentro de cada curso, si la clase tiene un foro, el estudiante puede crear discusiones, comentar y responder. El docente responde en el mismo foro.
3. **Chat dentro del BigBlueButton**: durante la sesión virtual, el estudiante puede participar por chat o por voz (si el docente le da permiso).

---

## 10. Operación diaria

### 10.1. Tareas programadas (cron)

La plataforma ejecuta automáticamente las siguientes tareas de mantenimiento:

| Tarea | Frecuencia | Qué hace |
|---|---|---|
| Sincronización financiera con Odoo | Cada 6 horas | Refresca el estado financiero (al día / en mora) de los estudiantes desde Odoo |
| Expiración de módulos pendientes de pago | Cada hora | Marca como vencidas las solicitudes de módulo que no se pagaron en 30 días |
| Auditoría de BigBlueButton | Cada 6 horas | Detecta sesiones BBB con problemas (huérfanas, sin relación con asistencia) y las repara o notifica al docente |
| Marcado automático de BBB como asistencia | Cada 2 minutos | Para cada sesión BBB activa, registra quién está conectado y aplica las reglas de presencia (≥70 % = P, 40-70 % = R, <40 % = sin asignar) |
| Verificación de conexiones Odoo-Moodle | Cada hora | Comprueba que la conexión con el ERP esté activa y sin errores |
| Drenado de colas de webhooks | Cada 3-5 minutos | Reintenta los webhooks que fallaron en el envío Odoo → Moodle |
| Cierre automático de sesiones de asistencia pasadas | Continuo | Las sesiones que terminaron sin marcación del docente se cierran automáticamente con los estudiantes sin marcación como FJ (configurable) |
| Alertas de inasistencias | Diaria | Detecta estudiantes que han superado el umbral de faltas y aplica la lógica de bloqueo o inactivación |

### 10.2. Cohortes y cierres de periodo

Al final de cada periodo lectivo, la Secretaría Académica ejecuta un **cierre de periodo** que:

1. **Consolida las notas** de todas las clases del periodo.
2. **Aplica las reglas de aprobación/reprobación** según las notas finales.
3. **Promueve de nivel** a los estudiantes que aprobaron todas las obligatorias de su nivel actual.
4. **Repite automáticamente** las asignaturas reprobadas (sin reinscripción manual; el estudiante queda en la misma cohorte para esas asignaturas).
5. **Cierra el periodo** en el calendario académico.
6. **Emite las alertas de reválida** a los docentes de las asignaturas con estudiantes elegibles.
7. **Genera el reporte consolidado** del periodo (aprobados, reprobados, reválidas, índices académicos).

El Director Académico es el único que puede autorizar el cierre de un periodo (los demás roles no tienen esa capacidad).

### 10.3. Reportes institucionales

El Director Académico tiene acceso a un panel de reportes con:

- **KPIs financieros**: total facturado vs. cobrado, tasa de mora, proyección de ingresos del siguiente periodo.
- **KPIs académicos**: tasa de aprobación por carrera, tasa de reprobación por asignatura, índice académico promedio por cohorte.
- **Brechas de demanda académica**: qué carreras tienen más demanda insatisfecha, qué asignaturas no se pudieron abrir por falta de docente o de aula.
- **Dashboard de reválidas**: cuántas reválidas se programaron, cuántas se pagaron, cuántas se aprobaron.
- **Reporte de reprobados**: estudiantes con asignaturas reprobadas en el periodo, con opción de reinscripción forzada con sobrecupo.
- **Reporte de créditos académicos**: distribución de créditos por carrera y cohorte.

### 10.4. Mantenimiento técnico

Las tareas de mantenimiento técnico que Soporte TI ejecuta periódicamente son:

- **Inspección de logs de sincronización** para detectar webhooks fallidos.
- **Reparación de sesiones BBB huérfanas** (cuando una grabación queda sin relación con la asistencia).
- **Reparación de clases con problemas** (cuando una clase no se creó correctamente, no se asignó docente, no tiene horario, etc.).
- **Re-sincronización de datos Odoo-Moodle** cuando se detecta una inconsistencia.
- **Reparación del campo de módulos BBB** cuando se desincroniza de la tabla de relaciones.
- **Limpieza de la cola de planificación** (filas que apuntan a clases borradas).
- **Verificación de la salud de la sincronización financiera** con Odoo.

### 10.5. Indicadores de salud de la plataforma

El equipo de Soporte TI monitorea continuamente:

- **Latencia de respuesta del LXP** (debe ser < 2 segundos en pantallas principales).
- **Tasa de error de webhooks** (si supera 5 % sostenido, hay un problema con Odoo o Express).
- **Tasa de error en sesiones BBB** (si supera 1 %, hay un problema con el servidor de BigBlueButton).
- **Uso de espacio en disco** (las grabaciones BBB y los archivos de soporte de asistencia ocupan mucho espacio).
- **Carga de la base de datos** (consultas lentas, índices faltantes, etc.).

---

## Anexo A — Roles y capacidades (referencia técnica)

> El detalle exhaustivo de cada rol, su catálogo de capacidades, las páginas administrativas que desbloquea, los Web Services que puede invocar y los procedimientos de asignación y troubleshooting se encuentra en:
>
> - `docs/role-matrix.md` — documento de referencia completo (~600 líneas)
> - `docs/role-matrix-quickref.md` — tabla compacta de 1 página con el cheatsheet "quién puede hacer qué"
>
> Estos documentos son la **fuente de verdad** para cualquier decisión sobre permisos. El capítulo 2 de este informe los resume en lenguaje ejecutivo.

## Anexo B — Procedimiento formal de reválidas (referencia)

> El procedimiento formal completo de reválidas (PR-ACA-REV-001, versión 1.0) se encuentra en:
>
> - `docs/Procedimiento_Revalidas.md`
>
> Incluye: definiciones, política y criterios, roles y responsabilidades, las 6 fases del procedimiento con sus pasos numerados, diagrama de flujo, registros y evidencias, excepciones y consideraciones, y control de cambios.
>
> El capítulo 3.3 de este informe lo resume en lenguaje ejecutivo.

## Anexo C — Arquitectura técnica (referencia)

> El detalle técnico de la integración entre Moodle, Odoo, el LXP y la pasarela Express se encuentra en:
>
> - `docs/ARCHITECTURE.md`
>
> Incluye: topología de los 4 sistemas, mapeo de datos Odoo ↔ Moodle, esquema de webhooks, endpoints del LXP, endpoints de Express, decisiones de diseño críticas, gotchas operacionales.
>
> Este informe ejecutivo **omite deliberadamente** el detalle de la integración para mantener el foco en los procesos académicos y operativos.

## Anexo D — Personas y roles asignados (producción)

| Persona | Email | Rol en la plataforma | Cargo institucional |
|---|---|---|---|
| Administrador Usuario | tic@isi.edu.pa | `manager` | Soporte TI / Administración |
| Joyce Muñoz | direccionacademica@isi.edu.pa | `manager` | Dirección Académica (técnica) |
| Walber Castillo | gerenciageneral@isi.edu.pa | `manager` (inactivo) | Gerencia General |
| José Joel Rodriguez | j.rodriguez@isi.edu.pa | `gmk_director_academico` | Director Académico |
| Lizbeth Aizprua | laizprua@isi.edu.pa | `gmk_registros_academicos` | Registros Académicos |
| Jean Remice | j.remice@isi.edu.pa | `gmk_secretaria_academica` | Secretaría Académica |
| Veronica Rangel | v.rangel@isi.edu.pa | `gmk_secretaria_academica` | Secretaría Académica |
| Jorge Oviedo | j.oviedo@isi.edu.pa | `gmk_bienestar` | Coordinador de Bienestar Estudiantil |
| Dulce Jurado | d.jurado@isi.edu.pa | `gmk_psicologo` | Psicóloga |
| Esteban Montoya | e.montoya@isi.edu.pa | `gmk_soporte_ti` | Soporte TI |
| Fernanda Alonso | f.alonso@isi.edu.pa | (sin rol custom; se le removió `manager`) | (Inactiva) |

> **Nota**: el cuerpo docente no aparece en esta tabla porque su acceso depende de las clases específicas que tengan asignadas, no de un rol global.

## Anexo E — Glosario

| Término | Definición |
|---|---|
| **Actividad** | Unidad de evaluación o interacción dentro de una clase: tarea, cuestionario, foro, sesión BBB, sesión de asistencia, material. |
| **Asignatura** | Materia del plan de estudios (por ejemplo, "Cálculo I", "Anatomía", "Dibujo Técnico"). |
| **BigBlueButton** | Servidor de videoconferencia usado para las clases virtuales y mixtas, con grabación automática. |
| **Bimestre** | Cada uno de los dos bloques en que se divide un periodo lectivo. |
| **Bundle de diploma** | Agrupación de plantillas de diploma que comparten un prefijo externo y un contador consecutivo común. |
| **Calificador rápido** | Herramienta del docente para calificar tareas y cuestionarios en lote. |
| **Carrera** | Programa académico completo del instituto (por ejemplo, "Técnico Superior en Logística Integral"). |
| **Clase** | Instancia operativa de una asignatura en un periodo (lo que el docente ve y califica). |
| **Cohorte** | Grupo de estudiantes que ingresaron al mismo periodo lectivo. Comparten la misma línea de tiempo académica. |
| **Cuestionario** | Actividad evaluable con preguntas que el estudiante responde en la plataforma. |
| **Cuatrimestre** | Unidad de tiempo en que se divide un plan de estudios (3 a 6 por carrera). Equivale a un nivel. |
| **Diploma** | Documento que certifica la finalización completa de una carrera. |
| **Docente de apoyo** | Docente secundario asignado a una clase, con las mismas capacidades que el titular pero con responsabilidad secundaria. |
| **Docente titular** | Docente principal asignado a una clase, responsable formal del acta de cierre. |
| **Estudiante elegible** | Estudiante que cumple las condiciones para acceder a un beneficio (por ejemplo, una reválida). |
| **Falta justificada (FJ)** | Ausencia a clase con soporte válido (certificado médico, cita legal, etc.). |
| **Falta injustificada (FI)** | Ausencia a clase sin soporte válido. |
| **Fork de Moodle** | Versión modificada de Moodle con código propio del instituto. |
| **Homologación** | Reconocimiento de equivalencia entre asignaturas de distintos planes. |
| **Índice académico** | Promedio ponderado por créditos de los puntos obtenidos en cada asignatura. |
| **Jornada** | Modalidad horaria del estudiante: Diurna, Nocturna o Sabatina. |
| **LXP** | Learning Experience Platform. La interfaz personalizada que ven los estudiantes, separada del Moodle estándar. |
| **Malla curricular** | Conjunto de asignaturas que componen un plan de estudios, organizadas por niveles. |
| **Módulo independiente** | Curso corto (1-4 semanas) que se ofrece fuera del calendario regular, para recuperación de asignaturas reprobadas. Típicamente virtual asíncrono. |
| **Nivel** | Cuatrimestre del plan de estudios en que se encuentra el estudiante. |
| **Nota final integrada** | Calificación ponderada total del estudiante en una asignatura. |
| **Periodo lectivo** | Contenedor calendario al que se alinean todas las actividades académicas. El año tiene 5 periodos. |
| **Plan de estudios** | La traducción operativa de una carrera a un recorrido cursable. |
| **Prelación** | Requisito de una asignatura sobre otra: para cursar B, hay que haber cursado A. |
| **QR de asistencia** | Código QR rotativo que el docente proyecta en clase y que el estudiante escanea para marcar su presencia. |
| **Resolución** | Número de aprobación oficial de una carrera ante la DNCES/DNES. |
| **Retraso (R)** | Estado de asistencia que indica que el estudiante llegó después del inicio pero dentro de la ventana de tolerancia. |
| **Reválida** | Evaluación adicional que se ofrece a un estudiante que obtuvo una nota entre 60.0 y 70.9 en una asignatura teórica. Si la aprueba, la asignatura queda aprobada con nota 71. |
| **Sesión** | Encuentro programado de una clase (presencial, virtual o mixto). |
| **Suficiencia** | Tipo de homologación que reconoce un examen externo como equivalente a una asignatura. |
| **Tarea** | Actividad evaluable en la que el estudiante sube archivos o responde en línea. |
| **Web Service (WS)** | Interfaz técnica que el LXP y otros sistemas externos invocan para leer o escribir datos en Moodle. |

## Anexo F — Queries para obtener cifras en vivo

> Las siguientes consultas son para que el equipo de Registros o Soporte TI las ejecute directamente en la base de datos del LMS (`isidb` en `52.20.149.225`) y complete los placeholders del resumen ejecutivo (sección 0.2).

### Query 1 — Estudiantes activos

```sql
-- Estudiantes con matrícula activa en un plan de estudios y sin estado de retiro
SELECT COUNT(DISTINCT userid)
FROM mdl_local_learning_users
WHERE userrolename = 'student'
  AND status = 'activo';
```

### Query 2 — Docentes con clase activa

```sql
-- Docentes titulares o de apoyo con al menos una clase activa en el periodo vigente
SELECT COUNT(DISTINCT instructorid) + COUNT(DISTINCT supportinstructorid)
FROM mdl_gmk_class
WHERE closed = 0
  AND approved = 1;
```

### Query 3 — Periodos académicos vigentes

```sql
-- Periodos lectivos institucionales con status activo
SELECT COUNT(*)
FROM mdl_gmk_academic_periods
WHERE status = 1;
```

### Query 4 — Clases activas

```sql
-- Clases aprobadas y no cerradas en el periodo vigente
SELECT COUNT(*)
FROM mdl_gmk_class gc
JOIN mdl_gmk_academic_periods gap ON gap.id = gc.periodid
WHERE gc.closed = 0
  AND gc.approved = 1
  AND gap.status = 1;
```

### Query 5 — Estudiantes por carrera

```sql
-- Distribución de estudiantes activos por carrera
SELECT lp.name AS carrera, COUNT(DISTINCT llu.userid) AS estudiantes
FROM mdl_local_learning_users llu
JOIN mdl_local_learning_plans lp ON lp.id = llu.learningplanid
WHERE llu.userrolename = 'student'
  AND llu.status = 'activo'
GROUP BY lp.name
ORDER BY estudiantes DESC;
```

### Query 6 — Reválidas activas (pagadas pendientes de calificar)

```sql
-- Reválidas ya pagadas pero con la sesión pendiente
SELECT COUNT(*)
FROM mdl_gmk_revalidations
WHERE payment_state = 'paid'
  AND status = 'scheduled';
```

### Query 7 — Módulos independientes activos

```sql
-- Inscripciones activas a módulos independientes
SELECT COUNT(*)
FROM mdl_gmk_module_enrollment
WHERE status = 'active';
```

### Cómo ejecutar las queries

Desde un equipo con acceso a la base de datos:

```bash
mysql -h 52.20.149.225 -u isi -p isidb
# Ingresar la contraseña cuando se solicite
# Luego pegar la query
```

Alternativa con archivo:

```bash
mysql -h 52.20.149.225 -u isi -p isidb < queries.sql
```

> **Importante**: estas queries son de **solo lectura** (no modifican datos)## Anexo G — Pensum completo de las carreras (datos en vivo de `isidb`)


> Fuente: base de datos `isidb` del LMS, tablas `isi_local_learning_plans`, `isi_local_learning_periods`, `isi_local_learning_courses`, `isi_course` y `isi_customfield_data`. Extraído en vivo el 7 de octubre de 2026.


Cada carrera se lista con:

- El nombre oficial del plan de estudios
- Una tabla por nivel (cuatrimestre) con cada asignatura, sus horas de teoría (HT), horas de práctica (HP), créditos y, donde exista, la prelación
- Una tabla de totales al final de cada carrera

**Leyenda de campos**:


| Campo | Significado |
|---|---|
| **HT** | Horas teóricas de la asignatura |
| **HP** | Horas prácticas de la asignatura (taller, laboratorio, práctica supervisada) |
| **Créd** | Créditos académicos que aporta al índice acumulado |
| **TC** | Sí = pertenece al tronco común (compartida con otras carreras); No = específica de la carrera |
| **Prelación** | Códigos de asignaturas prerequisito separadas por coma (deben estar aprobadas o intentadas antes de cursar) |
| **Obl.** | S = obligatoria para graduarse; E = electiva |

---

### G.0. Resumen general de la oferta académica


| # | Carrera | Niveles | Asignaturas | Horas teoría | Horas práctica | Horas totales | Créditos totales |
|---|---|---:|---:|---:|---:|---:|---:|
| 2 | Técnico Superior en Azafata Profesional de Vuelo Comercial | 3 | 24 | 284 | 257 | 541 | 87 |
| 3 | Técnico Superior en Soldadura Subacuática y Estructuras Especiales | 4 | 24 | 352 | 320 | 672 | 90 |
| 4 | Técnico Superior en Logística Integral y Comercio Internacional | 4 | 24 | 368 | 320 | 688 | 92 |
| 5 | Técnico Superior en Asistente de Ingeniería Civil | 4 | 23 | 360 | 312 | 672 | 106 |
| 6 | Técnico Superior en Mecánica de Equipo Pesado | 4 | 24 | 368 | 288 | 656 | 91 |
| 7 | Técnico Superior en Seguridad, Mantenimiento y Operación de Equipo Pesado | 4 | 4 | 0 | 0 | 0 | 0 |
| 8 | Técnico Superior en Diseño y Obras Civiles | 4 | 26 | 328 | 296 | 624 | 92 |
| 9 | Técnico Superior en Topografía | 4 | 20 | 328 | 296 | 624 | 89 |
| 10 | Técnico Superior en Asistente de Odontología | 6 | 25 | 448 | 416 | 864 | 93 |
| 11 | Técnico Superior en Electricidad con Énfasis en Centrales Hidroeléctricas | 4 | 22 | 360 | 328 | 688 | 95 |
| 12 | Técnico Superior en Medio Ambiente y Manejo Integrado de Cuencas Hidrográficas | 4 | 21 | 288 | 224 | 512 | 92 |
| 13 | Técnico Superior en Acuicultura | 4 | 22 | 336 | 304 | 640 | 98 |
| 14 | Curso de Buceo Comercial | 4 | 32 | 401 | 329 | 730 | 62 |
| 15 | Grupo Makro Seguridad, Mantenimiento y Operación en Excavadora Hidráulica | 1 | 3 | 48 | 24 | 72 | 9 |
| | **TOTAL** | | **294** | **4269** | **3714** | **7983** | **1096** |

---

### G.0.1. Carga horaria por asignatura (módulo de planificación, `gmk_subject_loads`)


> Fuente canónica: tabla `isi_gmk_subject_loads` con `academicperiodid = 0` (alcance global desde 2026-IX-09, ver migración del PR 20260909000). Anteriormente esta información vivía duplicada por periodo; ahora la configuración vive una sola vez y se aplica a todos los periodos lectivos.

Esta tabla es la que el **planificador de horarios** consulta para distribuir las sesiones de cada clase en la semana. Cada fila describe la carga esperada de una asignatura en términos de:

- **Horas totales** (`total_hours`): la cantidad de horas de clase que la asignatura debe cubrir en el periodo (valor por defecto histórico: 64 h, aunque muchas asignaturas usan 16 h, 30 h, 60 h, etc., según el plan).
- **Intensidad semanal** (`intensity`): las horas de clase por semana. Una intensidad de 4.00 significa que la asignatura se dicta 4 horas cada semana (típicamente 2 sesiones de 2 horas, o 4 sesiones de 1 hora). Una intensidad de 0.00 indica que la asignatura está en el catálogo pero no se está dictando en el periodo actual (es una materia de otra carrera, o una materia electiva sin cohorte abierta).

**Resumen estadístico de la carga académica global**:


| Métrica | Valor |
|---|---|
| Asignaturas distintas en el catálogo | **155** |
| Asignaturas activas en el periodo actual (intensidad > 0) | **19** (12%) |
| Asignaturas en catálogo pero no dictadas (intensidad = 0) | **136** |
| Suma total de horas-teóricas-prácticas del catálogo | **2774 h** |
| Promedio de horas por asignatura | **17.9 h** |
| Intensidad semanal mínima | **0.0 h/sem** |
| Intensidad semanal máxima | **4.0 h/sem** |
| Intensidad semanal promedio | **0.43 h/sem** |

**Distribución por intensidad semanal**:


| Tramo | Cantidad de asignaturas |
|---|---:|
| 0 h/sem (inactiva) | 136 |
| 3.1-4 h/sem | 14 |
| 1.1-2 h/sem | 4 |
| 2.1-3 h/sem | 1 |

**Interpretación operativa**: la mayoría de las asignaturas se concentran en 0 h/sem (catálogo histórico, no se dictan actualmente) o en 4 h/sem (asignaturas troncales que el planificador reparte típicamente como 2 sesiones semanales de 2 horas cada una). Las intensidades de 2.5–3.75 h/sem corresponden a asignaturas con sesiones más cortas o fraccionadas.

---

#### G.0.1.a. Las 20 asignaturas con mayor carga semanal (intensidad = 4.00 h/sem)


Estas son las asignaturas que el planificador prioriza al momento de bloquear los espacios de la semana. Representan la columna vertebral del horario institucional.


| # | Asignatura | Horas totales en el periodo | Intensidad semanal |
|---:|---|---:|---:|
| 1 | TRABAJO SUBMARINO UTILIZANDO EQUIPO DE BUCEO LIVIANO | 65 | 4.00 |
| 2 | ACONDICIONAMIENTO FÍSICO EN PISCINA | 64 | 4.00 |
| 3 | ACONDICIONAMIENTO FÍSICO SCUBA | 64 | 4.00 |
| 4 | APLICACIÓN PRÁCTICA DE NÁUTICA Y MANEJO DE APAREJO | 60 | 4.00 |
| 5 | PARTICIPACIÓN DEL APRENDÍZ EN OPERACIONES DE CÁMARA HIPERBÁRICA | 44 | 4.00 |
| 6 | PROCEDIMIENTO Y TÉCNICAS PARA EL BUCEO LIVIANO | 40 | 4.00 |
| 7 | TABLAS DE DESCOMPRESIÓN DE AIRE Y PROCEDIMIENTOS DE DESCOMPRESIÓN | 30 | 4.00 |
| 8 | TRATAMIENTO DE ENFERMEDADES Y LESIONES DEL BUZO | 30 | 4.00 |
| 9 | SEMINARIO DE IMAGEN Y COMPORTAMIENTO | 4 | 4.00 |
| 10 | SEMINARIO DE PRIMEROS AUXILIOS | 4 | 4.00 |
| 11 | SEMINARIO DE SUPERVIVENCIA EN AGUA | 4 | 4.00 |
| 12 | SEMINARIO DE SUPERVIVENCIA EN TIERRA | 4 | 4.00 |
| 13 | SEMINARIO TÉCNICAS AEROPORTUARIAS | 4 | 4.00 |

---

#### G.0.1.b. Catálogo completo de cargas (155 asignaturas, ordenadas por intensidad)


| # | Asignatura | Horas totales | Intensidad semanal |
|---:|---|---:|---:|
| 1 | TRABAJO SUBMARINO UTILIZANDO EQUIPO DE BUCEO LIVIANO | 65 | 4.00 |
| 2 | ACONDICIONAMIENTO FÍSICO EN PISCINA | 64 | 4.00 |
| 3 | ACONDICIONAMIENTO FÍSICO SCUBA | 64 | 4.00 |
| 4 | APLICACIÓN PRÁCTICA DE NÁUTICA Y MANEJO DE APAREJO | 60 | 4.00 |
| 5 | PARTICIPACIÓN DEL APRENDÍZ EN OPERACIONES DE CÁMARA HIPERBÁRICA | 44 | 4.00 |
| 6 | PROCEDIMIENTO Y TÉCNICAS PARA EL BUCEO LIVIANO | 40 | 4.00 |
| 7 | TABLAS DE DESCOMPRESIÓN DE AIRE Y PROCEDIMIENTOS DE DESCOMPRESIÓN | 30 | 4.00 |
| 8 | TRATAMIENTO DE ENFERMEDADES Y LESIONES DEL BUZO | 30 | 4.00 |
| 9 | SEMINARIO DE IMAGEN Y COMPORTAMIENTO | 4 | 4.00 |
| 10 | SEMINARIO DE PRIMEROS AUXILIOS | 4 | 4.00 |
| 11 | SEMINARIO DE SUPERVIVENCIA EN AGUA | 4 | 4.00 |
| 12 | SEMINARIO DE SUPERVIVENCIA EN TIERRA | 4 | 4.00 |
| 13 | SEMINARIO TÉCNICAS AEROPORTUARIAS | 4 | 4.00 |
| 14 | BUCEO CON MEZCLA DE GASES | 30 | 3.75 |
| 15 | FUNCIÓN Y NOMENCLATURA DE EQUIPOS DE BUCEO LIVIANO | 24 | 3.00 |
| 16 | DIBUJO, LECTURA DE PLANOS Y REDACCIÓN DE INFORMES | 8 | 2.00 |
| 17 | SEGURIDAD INDUSTRIAL Y OFFSHORE | 6 | 2.00 |
| 18 | GASES NOCIVOS EN ESPACIOS CERRADOS | 2 | 2.00 |
| 19 | SISTEMA DE AGUAS CALIENTES | 2 | 2.00 |
| 20 | INGLÉS APLICADO A LA AERONÁUTICA | 32 | 0.00 |
| 21 | INGLÉS APLICADO A LA AERONÁUTICA | 32 | 0.00 |
| 22 | INGLÉS I | 32 | 0.00 |
| 23 | INGLÉS I | 32 | 0.00 |
| 24 | INGLÉS I | 32 | 0.00 |
| 25 | INGLÉS II | 32 | 0.00 |
| 26 | INGLÉS II | 32 | 0.00 |
| 27 | INTRODUCCIÓN A LA SOLDADURA EN CUBIERTA | 26 | 0.00 |
| 28 | FUNDAMENTOS DE NÁUTICA Y APAREJOS | 25 | 0.00 |
| 29 | HERRAMIENTAS SUBMARINAS | 24 | 0.00 |
| 30 | INTRODUCCIÓN AL CORTE Y SOLDADURA SUBMARINA | 24 | 0.00 |
| 31 | ANATOMÍA Y FISIOLOGÍA APLICADA AL BUCEO | 18 | 0.00 |
| 32 | ACUERDOS COMERCIALES | 16 | 0.00 |
| 33 | ADMINISTRACIÓN DE CONSTRUCCIONES | 16 | 0.00 |
| 34 | ASISTENCIA INTERNACIONAL | 16 | 0.00 |
| 35 | AUTOCAD I | 16 | 0.00 |
| 36 | AUTOCAD I | 16 | 0.00 |
| 37 | AUTOCAD II | 16 | 0.00 |
| 38 | CÁLCULO DIFERENCIAL | 16 | 0.00 |
| 39 | CÁMARA HIPERBÁRICA Y EQUIPOS ASOCIADOS | 16 | 0.00 |
| 40 | CATASTRO, LEGISLACIÓN Y TERRITORIO | 16 | 0.00 |
| 41 | CENTRALES HIDROELÉCTRICAS | 16 | 0.00 |
| 42 | CLÍNICA ODONTOLÓGICA DE PACIENTES ESPECIALES | 16 | 0.00 |
| 43 | COMERCIO INTERNACIONAL | 16 | 0.00 |
| 44 | CONSTRUCCIÓN DE ESTRUCTURAS PARA REDES ELÉCTRICAS | 16 | 0.00 |
| 45 | CONSTRUCCIÓN DE MUROS DE CONTENCIÓN | 16 | 0.00 |
| 46 | CONSTRUCCIÓN DE OBRAS DE ARTE COMPLEMENTARIAS | 16 | 0.00 |
| 47 | CONSTRUCCIÓN DE PUENTES | 16 | 0.00 |
| 48 | CONSTRUCCIÓN DE VÍAS Y ANDENES | 16 | 0.00 |
| 49 | CONSTRUCCIÓN I | 16 | 0.00 |
| 50 | CONSTRUCCIÓN II | 16 | 0.00 |
| 51 | CONSTRUCCIÓN III | 16 | 0.00 |
| 52 | COSTES DE LOGÍSTICA | 16 | 0.00 |
| 53 | DESARROLLO DE LA PERSONALIDAD | 16 | 0.00 |
| 54 | DIBUJO APLICADO | 16 | 0.00 |
| 55 | DIBUJO TÉCNICO | 16 | 0.00 |
| 56 | DISEÑO ARQUITECTÓNICO I | 16 | 0.00 |
| 57 | DISEÑO ARQUITECTÓNICO II | 16 | 0.00 |
| 58 | DISEÑO CARTOGRÁFICO | 16 | 0.00 |
| 59 | ECOSISTEMAS GEOGRÁFICOS | 16 | 0.00 |
| 60 | ELECTRICIDAD | 16 | 0.00 |
| 61 | ELECTROMECÁNICA | 16 | 0.00 |
| 62 | ELEMENTOS DE ARQUITECTURA | 16 | 0.00 |
| 63 | ENTRENAMIENTO EQUIPO SCUBA EN EL MAR | 16 | 0.00 |
| 64 | EPIDEMIOLOGÍA Y SALUD PÚBLICA | 16 | 0.00 |
| 65 | EPIDEMIOLOGÍA Y SALUD PÚBLICA | 16 | 0.00 |
| 66 | ESTRATEGIA PARA EL ESTUDIO Y FORMACIÓN PROF. | 16 | 0.00 |
| 67 | ÉTICA PARA LA AVIACIÓN | 16 | 0.00 |
| 68 | EXPRESIÓN ORAL Y ESCRITA I | 16 | 0.00 |
| 69 | EXPRESIÓN ORAL Y ESCRITA I | 16 | 0.00 |
| 70 | EXPRESIÓN ORAL Y ESCRITA II | 16 | 0.00 |
| 71 | FARMACOLOGÍA | 16 | 0.00 |
| 72 | FASES DEL PROCESO DE COMPRA | 16 | 0.00 |
| 73 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | 16 | 0.00 |
| 74 | FUNDAMENTOS DE PROCESOS DE SEGURIDAD, MANT Y OPE | 16 | 0.00 |
| 75 | GEOFÍSICA | 16 | 0.00 |
| 76 | GEOGRAFÍA DE PANAMÁ | 16 | 0.00 |
| 77 | GEOMETRÍA DESCRIPTIVA | 16 | 0.00 |
| 78 | GESTIÓN AMBIENTAL | 16 | 0.00 |
| 79 | GESTIÓN DE RECURSOS | 16 | 0.00 |
| 80 | GESTIÓN EMPRESARIAL | 16 | 0.00 |
| 81 | GESTIÓN Y DESARROLLO DE LA PRÁCTICA ODONTOLÓGICA | 16 | 0.00 |
| 82 | HISTORIA DE PANAMÁ | 16 | 0.00 |
| 83 | INFORMÁTICA APLICADA | 16 | 0.00 |
| 84 | INFORMÁTICA APLICADA | 16 | 0.00 |
| 85 | INGENIERÍA ECONÓMICA | 16 | 0.00 |
| 86 | INGENIERÍA GEOTÉCNICA | 16 | 0.00 |
| 87 | INTERPRETACIÓN DE PLANOS | 16 | 0.00 |
| 88 | INTERRELACIONES CON OTROS DEPARTAMENTOS | 16 | 0.00 |
| 89 | INTRODUCCIÓN AL DERECHO LABORAL | 16 | 0.00 |
| 90 | INVERSIÓN EXTRANJERA | 16 | 0.00 |
| 91 | LEGISLACIÓN AERONÁUTICA | 16 | 0.00 |
| 92 | LIBRE COMERCIO | 16 | 0.00 |
| 93 | LOGÍSTICA EMPRESARIAL | 16 | 0.00 |
| 94 | LOGÍSTICA INVERSA | 16 | 0.00 |
| 95 | MANTENIMIENTO MECÁNICO. | 16 | 0.00 |
| 96 | MAQUINAS ELÉCTRICAS | 16 | 0.00 |
| 97 | MATEMÁTICA I | 16 | 0.00 |
| 98 | MATEMÁTICA II | 16 | 0.00 |
| 99 | MATERIALES ODONTOLÓGICOS, EQUIPAMIENTO E INSTRUM. | 16 | 0.00 |
| 100 | MECÁNICA DE EQUIPO PESADO I | 16 | 0.00 |
| 101 | MECÁNICA DE EQUIPO PESADO II | 16 | 0.00 |
| 102 | MECÁNICA DE EQUIPO PESADO III | 16 | 0.00 |
| 103 | MEDICIONES ELÉCTRICAS | 16 | 0.00 |
| 104 | METEOROLOGÍA BÁSICA | 16 | 0.00 |
| 105 | METODOLOGÍA DE INVESTIGACIÓN EN CIENCIAS DE SALUD | 16 | 0.00 |
| 106 | METODOLOGÍA Y TÉCNICAS DE INVESTIGACIÓN | 16 | 0.00 |
| 107 | MICROBIOLOGÍA GENERAL Y BUCAL | 16 | 0.00 |
| 108 | MORFOLOGÍA, ESTRUCTURA Y FUNCIÓN DEL CUERPO HUMANO | 16 | 0.00 |
| 109 | MOTORES Y COMPRESORES MARINOS | 16 | 0.00 |
| 110 | NORMAS BÁSICAS DE SISMO Y RESISTENCIA | 16 | 0.00 |
| 111 | NORMATIVA INTERNACIONAL DE COMERCIO | 16 | 0.00 |
| 112 | OBRAS CIVILES PRELIMINARES | 16 | 0.00 |
| 113 | ODONTOLOGÍA ESTÉTICA | 16 | 0.00 |
| 114 | ODONTOLOGÍA PREVENTIVA Y COMUNITARIA | 16 | 0.00 |
| 115 | ODONTOPEDIATRÍA | 16 | 0.00 |
| 116 | OPERACIONES DE EQUIPO PESADO I | 16 | 0.00 |
| 117 | OPERACIONES DE EQUIPO PESADO II | 16 | 0.00 |
| 118 | ORIENTACIÓN TURÍSTICA Y COMERCIAL | 16 | 0.00 |
| 119 | ORTODONCIA | 16 | 0.00 |
| 120 | PATOLOGÍA Y TERAPÉUTICA DENTAL I | 16 | 0.00 |
| 121 | PATOLOGÍA Y TERAPÉUTICA DENTAL II | 16 | 0.00 |
| 122 | PLANEAMIENTO URBANO | 16 | 0.00 |
| 123 | PRESUPUESTO DE OBRAS | 16 | 0.00 |
| 124 | PRESUPUESTO Y ADMINISTRACIÓN | 16 | 0.00 |
| 125 | PRIMEROS AUXILIOS PARA BUZOS Y RPC | 16 | 0.00 |
| 126 | PRINCIPIOS DE FÍSICA | 16 | 0.00 |
| 127 | PRÓTESIS Y OCLUSIÓN I | 16 | 0.00 |
| 128 | PRÓTESIS Y OCLUSIÓN II | 16 | 0.00 |
| 129 | PROYECTO EMPRESARIAL | 16 | 0.00 |
| 130 | REDACCIÓN DE INFORMES TÉCNICOS | 16 | 0.00 |
| 131 | SEGURIDAD INDUSTRIAL Y SALUD OCUPACIONAL | 16 | 0.00 |
| 132 | SEGURIDAD Y PRIMEROS AUXILIOS | 16 | 0.00 |
| 133 | SERVICIO A BORDO | 16 | 0.00 |
| 134 | SISTEMA DE INFORMACIÓN GEOGRÁFICA | 16 | 0.00 |
| 135 | SISTEMA VISUAL BÁSICO | 16 | 0.00 |
| 136 | SISTEMAS DE ALMACENAMIENTO | 16 | 0.00 |
| 137 | SISTEMAS DE MANDOS Y CONTROLES I | 16 | 0.00 |
| 138 | SISTEMAS DE MANDOS Y CONTROLES II | 16 | 0.00 |
| 139 | TALLER DE ELECTRICIDAD | 16 | 0.00 |
| 140 | TÉCNICAS CARTOGRÁFICAS | 16 | 0.00 |
| 141 | TÉCNICAS E INSTRUMENTOS DE INVESTIGACIÓN | 16 | 0.00 |
| 142 | TEORÍA Y ANÁLISIS DE CIRCUITOS | 16 | 0.00 |
| 143 | TOPOGRAFÍA I | 16 | 0.00 |
| 144 | TOPOGRAFÍA II | 16 | 0.00 |
| 145 | TRANSPORTE Y LOGÍSTICA | 16 | 0.00 |
| 146 | APLICACIÓN DE FÓRMULAS | 13 | 0.00 |
| 147 | PRINCIPIOS DE LA FÍSICA EN EL BUCEO | 13 | 0.00 |
| 148 | APLICACIÓN PRACTICA DEL MÉTODO CORTE CON OXIGENO ACETILENO | 12 | 0.00 |
| 149 | ENFERMEDADES, LESIONES Y ASPECTOS PSICOLÓGICOS DEL BUCEO | 12 | 0.00 |
| 150 | EQUIPOS DE SOLDADURA EN SUPERFICIE | 12 | 0.00 |
| 151 | MANTENIMIENTO DEL UMBILICAL DEL BUZO | 12 | 0.00 |
| 152 | PELIGROS AMBIENTALES DEL BUCEO | 12 | 0.00 |
| 153 | PLANIFICACIÓN DE OPERACIONES | 12 | 0.00 |
| 154 | REGISTRO DE BUCEO Y NORMAS PARA OPERACIONES DE BUCEO | 12 | 0.00 |
| 155 | INTRODUCCIÓN AL CORTE CON OXIGENO-ACETILENO | 10 | 0.00 |

---

### G.2. Técnico Superior en Azafata Profesional de Vuelo Comercial

**Abreviatura**: `AZAFATA` · **Niveles declarados en el plan**: 3 · **Niveles con asignaturas en el catálogo actual**: 3 · **Asignaturas**: 24

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ÉTICA PARA LA AVIACIÓN | `EPA` | 8 | 8 | 4 | No | — | S |
| 2 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 5 | LEGISLACIÓN AERONÁUTICA | `LAE` | 8 | 8 | 3 | No | — | S |
| 6 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 7 | ORIENTACIÓN TURÍSTICA Y COMERCIAL | `OTC` | 8 | 8 | 6 | No | — | S |
| 8 | SEMINARIO TÉCNICAS AEROPORTUARIAS | `TAE` | 4 | 4 | 0 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **68** | **68** | **25** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | GESTIÓN DE RECURSOS | `GRE` | 8 | 8 | 6 | No | — | S |
| 4 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 5 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 6 | METEOROLOGÍA BÁSICA | `MBA` | 8 | 8 | 6 | No | — | S |
| 7 | SEGURIDAD Y PRIMEROS AUXILIOS | `SPA` | 8 | 8 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **64** | **48** | **29** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | DESARROLLO DE LA PERSONALIDAD | `DPE` | 8 | 8 | 6 | No | — | S |
| 2 | GESTIÓN EMPRESARIAL | `GEM` | 16 | 16 | 6 | No | — | S |
| 3 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 4 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 5 | REDACCIÓN DE INFORMES TÉCNICOS | `RIT` | 8 | 8 | 3 | No | — | S |
| 5 | INGLÉS APLICADO A LA AERONÁUTICA | `INGAA` | 8 | 8 | 0 | No | — | S |
| 6 | SEMINARIO DE SUPERVIVENCIA EN AGUA | `NAT` | 0 | 5 | 0 | No | — | S |
| 7 | SEMINARIO DE SUPERVIVENCIA EN TIERRA | `SUP` | 8 | 8 | 0 | No | — | S |
| 8 | SERVICIO A BORDO | `SBO` | 8 | 8 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **152** | **141** | **33** | | | |

**Totales de la carrera**: 284 h teoría + 257 h práctica = **541 h** totales, **87 créditos** distribuidos en **3 niveles**.

---

### G.3. Técnico Superior en Soldadura Subacuática y Estructuras Especiales

**Abreviatura**: `SOLDADURA` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 24

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CORTE BAJO EL AGUA | `CBA` | 16 | 16 | 3 | No | — | S |
| 2 | DIBUJO APLICADO | `DAP` | 16 | 16 | 3 | No | — | S |
| 3 | EQUIPOS Y CONEXIONES | `ECO` | 8 | 8 | 3 | No | — | S |
| 4 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 5 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 6 | SEGURIDAD EN OPERACIONES DE CORTE Y SOLDADURA | `SOCS` | 8 | 8 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **64** | **64** | **18** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CORTE ARC WATER | `CARC` | 8 | 8 | 4 | No | — | S |
| 2 | CORTE POR ELECTRODOS ULTRA TÉRMICOS | `CEU` | 8 | 8 | 4 | No | — | S |
| 3 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 4 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 5 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 6 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 7 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| | **TOTAL Cuatrimestre 2** | | **64** | **48** | **22** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CORTE POR ELECTRODOS REVESTIDOS | `CER` | 8 | 8 | 3 | No | — | S |
| 2 | SOLDADURA SUBACUÁTICA | `SSU` | 16 | 16 | 3 | No | — | S |
| 3 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 4 | SISTEMA DE CORTE OXIFUNDENTE O LANZA BERFIX | `SCO` | 8 | 8 | 4 | No | ISDLR | S |
| 5 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **56** | **40** | **15** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ESPECIFICACIONES PARA SOLDADURA SUBACUÁTICA | `ESS` | 8 | 8 | 6 | No | — | S |
| 2 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 5 | SOLDADURA HIPERBÁRICA | `SHI` | 32 | 32 | 4 | No | — | S |
| 6 | SOLDADURA HÚMEDA SUBACUÁTICA | `SHS` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **168** | **168** | **35** | | | |

**Totales de la carrera**: 352 h teoría + 320 h práctica = **672 h** totales, **90 créditos** distribuidos en **4 niveles**.

---

### G.4. Técnico Superior en Logística Integral y Comercio Internacional

**Abreviatura**: `LOGÍSTICA` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 24

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | COSTES DE LOGÍSTICA | `CL` | 16 | 16 | 4 | No | LE,MATI | S |
| 2 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 3 | FASES DEL PROCESO DE COMPRA | `FPC` | 8 | 8 | 3 | No | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 5 | INTERRELACIONES CON OTROS DEPARTAMENTOS | `ICD` | 16 | 16 | 4 | No | — | S |
| 6 | LOGÍSTICA EMPRESARIAL | `LE` | 16 | 16 | 4 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **72** | **72** | **21** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | LOGÍSTICA INVERSA | `LI` | 16 | 0 | 2 | No | SA,TL | S |
| 4 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 5 | SISTEMAS DE ALMACENAMIENTO | `SA` | 16 | 16 | 3 | No | LE | S |
| 6 | TRANSPORTE Y LOGÍSTICA | `TL` | 16 | 16 | 3 | No | CL,LE | S |
| | **TOTAL Cuatrimestre 2** | | **80** | **48** | **17** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ASISTENCIA INTERNACIONAL | `AI` | 16 | 16 | 3 | No | — | S |
| 2 | COMERCIO INTERNACIONAL | `CI` | 8 | 8 | 3 | No | MATII,LE | S |
| 3 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 4 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 5 | INVERSIÓN EXTRANJERA | `IE` | 16 | 16 | 10 | No | MATI,MATII | S |
| 6 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| | **TOTAL Cuatrimestre 3** | | **72** | **56** | **23** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ACUERDOS COMERCIALES | `AC` | 16 | 16 | 6 | No | ICD,INGI,INGII | S |
| 2 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | LIBRE COMERCIO | `LC` | 8 | 8 | 3 | No | CI | S |
| 5 | NORMATIVA INTERNACIONAL DE COMERCIO | `NIC` | 8 | 8 | 3 | No | AC | S |
| 6 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **144** | **144** | **31** | | | |

**Totales de la carrera**: 368 h teoría + 320 h práctica = **688 h** totales, **92 créditos** distribuidos en **4 niveles**.

---

### G.5. Técnico Superior en Asistente de Ingeniería Civil

**Abreviatura**: `ING. CIVIL` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 23

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | AUTOCAD I | `ACAI` | 16 | 16 | 6 | No | — | S |
| 2 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 3 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 4 | OBRAS CIVILES PRELIMINARES | `OCP` | 16 | 16 | 6 | No | — | S |
| 5 | SEGURIDAD INDUSTRIAL Y SALUD OCUPACIONAL | `SIS` | 8 | 8 | 2 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **56** | **56** | **20** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CONSTRUCCIÓN DE ESTRUCTURAS PARA REDES ELÉCTRICAS | `CERE` | 16 | 16 | 6 | No | — | S |
| 2 | CONSTRUCCIÓN DE VÍAS Y ANDENES | `CVA` | 16 | 16 | 6 | No | — | S |
| 3 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 4 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 5 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 6 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| | **TOTAL Cuatrimestre 2** | | **72** | **56** | **24** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CONSTRUCCIÓN DE MUROS DE CONTENCIÓN | `CMC` | 16 | 16 | 6 | No | — | S |
| 2 | CONSTRUCCIÓN DE PUENTES | `CPU` | 16 | 16 | 6 | No | — | S |
| 3 | GESTIÓN EMPRESARIAL | `GEM` | 16 | 16 | 6 | No | — | S |
| 4 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 5 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 6 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| | **TOTAL Cuatrimestre 3** | | **80** | **64** | **25** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CONSTRUCCIÓN DE OBRAS DE ARTE COMPLEMENTARIAS | `COA` | 16 | 16 | 10 | No | — | S |
| 2 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 3 | NORMAS BÁSICAS DE SISMO Y RESISTENCIA | `NBS` | 16 | 0 | 2 | No | — | S |
| 4 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 5 | PRESUPUESTO DE OBRAS | `POB` | 8 | 8 | 3 | No | — | S |
| 6 | TOPOGRAFÍA I | `TOPI` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **152** | **136** | **37** | | | |

**Totales de la carrera**: 360 h teoría + 312 h práctica = **672 h** totales, **106 créditos** distribuidos en **4 niveles**.

---

### G.6. Técnico Superior en Mecánica de Equipo Pesado

**Abreviatura**: `MECÁNICA EQ. PESADO` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 24

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 2 | FUNDAMENTOS DE PROCESOS DE SEGURIDAD, MANT Y OPE | `FPS` | 8 | 8 | 3 | No | — | S |
| 3 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 4 | INTRODUCCIÓN AL DERECHO LABORAL | `IDL` | 16 | 0 | 2 | No | — | S |
| 5 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 6 | MECÁNICA DE EQUIPO PESADO I | `MEI` | 32 | 0 | 6 | No | — | S |
| 7 | SEGURIDAD INDUSTRIAL Y SALUD OCUPACIONAL | `SIS` | 8 | 8 | 2 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **88** | **40** | **22** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 5 | MECÁNICA DE EQUIPO PESADO II | `MEII` | 16 | 16 | 6 | No | — | S |
| 6 | OPERACIONES DE EQUIPO PESADO I | `OEI` | 8 | 8 | 3 | No | — | S |
| 7 | SISTEMAS DE MANDOS Y CONTROLES I | `SMC` | 8 | 8 | 0 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **80** | **64** | **21** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 2 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 3 | MECÁNICA DE EQUIPO PESADO III | `MEIII` | 16 | 16 | 6 | No | MEII | S |
| 4 | OPERACIONES DE EQUIPO PESADO II | `OEII` | 16 | 16 | 6 | No | OEI | S |
| 5 | PRINCIPIOS DE FÍSICA | `PFI` | 8 | 8 | 3 | No | — | S |
| 6 | SISTEMAS DE MANDOS Y CONTROLES II | `SMCII` | 8 | 8 | 0 | No | SMC | S |
| | **TOTAL Cuatrimestre 3** | | **72** | **56** | **20** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ELECTROMECÁNICA | `ELM` | 16 | 16 | 6 | No | — | S |
| 2 | GESTIÓN EMPRESARIAL | `GEM` | 16 | 16 | 6 | No | — | S |
| 3 | MANTENIMIENTO MECÁNICO. | `MM` | 16 | 16 | 10 | No | — | S |
| 4 | PRÁCTICA EMPRESARIAL (PROFESIONAL) | `PEMP` | 80 | 80 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **128** | **128** | **28** | | | |

**Totales de la carrera**: 368 h teoría + 288 h práctica = **656 h** totales, **91 créditos** distribuidos en **4 niveles**.

---

### G.7. Técnico Superior en Seguridad, Mantenimiento y Operación de Equipo Pesado

**Abreviatura**: `SEGURIDAD` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 4

> ⚠️ **Las asignaturas están registradas pero los campos de horas (HT, HP) y créditos no han sido completados en el catálogo.**
> Se muestran las asignaturas pero sin datos de carga horaria hasta que Registros Académicos complete los custom fields de los cursos.

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 0 | NULL | `NULL` | 0 | 0 | 0 | No | NULL | E |
| | **TOTAL Cuatrimestre 1** | | **0** | **0** | **0** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 0 | NULL | `NULL` | 0 | 0 | 0 | No | NULL | E |
| | **TOTAL Cuatrimestre 2** | | **0** | **0** | **0** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 0 | NULL | `NULL` | 0 | 0 | 0 | No | NULL | E |
| | **TOTAL Cuatrimestre 3** | | **0** | **0** | **0** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 0 | NULL | `NULL` | 0 | 0 | 0 | No | NULL | E |
| | **TOTAL Cuatrimestre 4** | | **0** | **0** | **0** | | | |

**Totales de la carrera**: 0 h teoría + 0 h práctica = **0 h** totales, **0 créditos** distribuidos en **4 niveles**.

---

### G.8. Técnico Superior en Diseño y Obras Civiles

**Abreviatura**: `DISEÑO Y OBRAS` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 26

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CONSTRUCCIÓN I | `COI` | 8 | 8 | 3 | No | — | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | GEOMETRÍA DESCRIPTIVA | `GDE` | 8 | 8 | 3 | No | — | S |
| 4 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 5 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 6 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 7 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| | **TOTAL Cuatrimestre 1** | | **80** | **48** | **20** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CÁLCULO DIFERENCIAL | `CDI` | 8 | 8 | 4 | No | — | S |
| 2 | CONSTRUCCIÓN II | `COII` | 8 | 8 | 3 | No | COI | S |
| 3 | DIBUJO TÉCNICO | `DTC` | 8 | 8 | 3 | No | — | S |
| 4 | ELEMENTOS DE ARQUITECTURA | `EAR` | 8 | 8 | 3 | No | — | S |
| 5 | PLANEAMIENTO URBANO | `PUR` | 8 | 8 | 3 | No | — | S |
| 6 | SISTEMA VISUAL BÁSICO | `SVB` | 8 | 8 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **48** | **48** | **19** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | AUTOCAD I | `ACAI` | 16 | 16 | 6 | No | — | S |
| 2 | CONSTRUCCIÓN III | `COIII` | 8 | 8 | 3 | No | COII | S |
| 3 | DISEÑO ARQUITECTÓNICO I | `DAI` | 8 | 8 | 3 | No | — | S |
| 4 | INGENIERÍA ECONÓMICA | `IEC` | 8 | 8 | 3 | No | — | S |
| 5 | INGENIERÍA GEOTÉCNICA | `IGE` | 8 | 8 | 3 | No | — | S |
| 6 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| | **TOTAL Cuatrimestre 3** | | **56** | **56** | **20** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ADMINISTRACIÓN DE CONSTRUCCIONES | `ACO` | 8 | 8 | 3 | No | — | S |
| 2 | AUTOCAD II | `ACAII` | 8 | 8 | 3 | No | ACAI | S |
| 3 | DISEÑO ARQUITECTÓNICO II | `DAII` | 8 | 8 | 4 | No | DAI | S |
| 4 | INTERPRETACIÓN DE PLANOS | `IPL` | 8 | 8 | 4 | No | — | S |
| 5 | PROYECTO EMPRESARIAL | `PEM` | 16 | 16 | 3 | No | — | S |
| 6 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 7 | TOPOGRAFÍA I | `TOPI` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **144** | **144** | **33** | | | |

**Totales de la carrera**: 328 h teoría + 296 h práctica = **624 h** totales, **92 créditos** distribuidos en **4 niveles**.

---

### G.9. Técnico Superior en Topografía

**Abreviatura**: `TOPOGRAFÍA` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 20

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CATASTRO, LEGISLACIÓN Y TERRITORIO | `CLT` | 8 | 8 | 3 | No | — | S |
| 2 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 5 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| | **TOTAL Cuatrimestre 1** | | **48** | **48** | **15** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | TOPOGRAFÍA I | `TOPI` | 16 | 16 | 6 | No | — | S |
| 4 | TÉCNICAS CARTOGRÁFICAS | `TCA` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **56** | **40** | **18** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | DISEÑO CARTOGRÁFICO | `DCA` | 16 | 16 | 6 | No | TCA | S |
| 2 | TOPOGRAFÍA II | `TOPII` | 16 | 16 | 6 | No | TOPI | S |
| 3 | AUTOCAD I | `ACAI` | 16 | 16 | 6 | No | — | S |
| 4 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 5 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 6 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 7 | SISTEMA DE INFORMACIÓN GEOGRÁFICA | `SIG` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **96** | **80** | **32** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ECOSISTEMAS GEOGRÁFICOS | `ECOG` | 16 | 16 | 6 | No | SIG | S |
| 2 | GEOFÍSICA | `GFI` | 16 | 16 | 6 | No | — | S |
| 3 | GESTIÓN EMPRESARIAL | `GEM` | 16 | 16 | 6 | No | — | S |
| 4 | PRÁCTICA EMPRESARIAL (PROFESIONAL) | `PEMP` | 80 | 80 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **128** | **128** | **24** | | | |

**Totales de la carrera**: 328 h teoría + 296 h práctica = **624 h** totales, **89 créditos** distribuidos en **4 niveles**.

---

### G.10. Técnico Superior en Asistente de Odontología

**Abreviatura**: `ODONTOLOGÍA` · **Niveles declarados en el plan**: 6 · **Niveles con asignaturas en el catálogo actual**: 6 · **Asignaturas**: 25

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 2 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| | **TOTAL Cuatrimestre 1** | | **48** | **32** | **11** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ESTRATEGIA PARA EL ESTUDIO Y FORMACIÓN PROF. | `EFP` | 8 | 8 | 3 | No | — | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | METODOLOGÍA Y TÉCNICAS DE INVESTIGACIÓN | `MTI` | 8 | 8 | 3 | No | — | S |
| 4 | TÉCNICAS E INSTRUMENTOS DE INVESTIGACIÓN | `TII` | 8 | 8 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **40** | **24** | **12** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EPIDEMIOLOGÍA Y SALUD PÚBLICA | `ESP` | 8 | 8 | 4 | No | — | S |
| 2 | FARMACOLOGÍA | `FARM` | 8 | 8 | 4 | No | — | S |
| 3 | MATERIALES ODONTOLÓGICOS, EQUIPAMIENTO E INSTRUM. | `MOEI` | 8 | 8 | 4 | No | — | S |
| 4 | MORFOLOGÍA, ESTRUCTURA Y FUNCIÓN DEL CUERPO HUMANO | `MEFC` | 8 | 8 | 4 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **32** | **32** | **16** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ODONTOLOGÍA PREVENTIVA Y COMUNITARIA | `OPC` | 16 | 16 | 3 | No | — | S |
| 2 | ORTODONCIA | `ORT` | 16 | 16 | 4 | No | — | S |
| 3 | PATOLOGÍA Y TERAPÉUTICA DENTAL I | `PAT` | 16 | 16 | 3 | No | — | S |
| 4 | PRÓTESIS Y OCLUSIÓN I | `POI` | 16 | 16 | 4 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **64** | **64** | **14** | | | |

#### Cuatrimestre 5 (5° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CLÍNICA ODONTOLÓGICA DE PACIENTES ESPECIALES | `COPE` | 16 | 16 | 3 | No | — | S |
| 2 | METODOLOGÍA DE INVESTIGACIÓN EN CIENCIAS DE SALUD | `MICS` | 8 | 8 | 4 | No | MTI,TII | S |
| 3 | PATOLOGÍA Y TERAPÉUTICA DENTAL II | `PTD` | 16 | 16 | 3 | No | PAT | S |
| 4 | PRÓTESIS Y OCLUSIÓN II | `POII` | 16 | 16 | 4 | No | POI | S |
| | **TOTAL Cuatrimestre 5** | | **56** | **56** | **14** | | | |

#### Cuatrimestre 6 (6° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 2 | GESTIÓN Y DESARROLLO DE LA PRÁCTICA ODONTOLÓGICA | `GDPO` | 80 | 80 | 4 | No | — | S |
| 3 | MICROBIOLOGÍA GENERAL Y BUCAL | `MGB` | 16 | 16 | 4 | No | — | S |
| 4 | ODONTOLOGÍA ESTÉTICA | `OES` | 16 | 16 | 4 | No | — | S |
| 5 | ODONTOPEDIATRÍA | `OPE` | 16 | 16 | 4 | No | — | S |
| | **TOTAL Cuatrimestre 6** | | **208** | **208** | **26** | | | |

**Totales de la carrera**: 448 h teoría + 416 h práctica = **864 h** totales, **93 créditos** distribuidos en **6 niveles**.

---

### G.11. Técnico Superior en Electricidad con Énfasis en Centrales Hidroeléctricas

**Abreviatura**: `ELECTRICIDAD` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 22

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | DIBUJO APLICADO | `DAP` | 16 | 16 | 3 | No | — | S |
| 2 | ELECTRICIDAD | `ELE` | 16 | 16 | 4 | No | — | S |
| 3 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 5 | TEORÍA Y ANÁLISIS DE CIRCUITOS | `TAC` | 16 | 16 | 4 | No | ELE | S |
| | **TOTAL Cuatrimestre 1** | | **64** | **64** | **17** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 3 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 4 | MAQUINAS ELÉCTRICAS | `MAC` | 16 | 16 | 4 | No | — | S |
| 5 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 6 | MEDICIONES ELÉCTRICAS | `MED` | 16 | 16 | 4 | No | MAC | S |
| | **TOTAL Cuatrimestre 2** | | **72** | **56** | **20** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | GESTIÓN EMPRESARIAL | `GEM` | 16 | 16 | 6 | No | — | S |
| 2 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 3 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 4 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 5 | PRESUPUESTO Y ADMINISTRACIÓN | `PAD` | 16 | 16 | 3 | No | — | S |
| 6 | PROYECTO EMPRESARIAL | `PEM` | 16 | 16 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **80** | **64** | **19** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CENTRALES HIDROELÉCTRICAS | `CHI` | 16 | 16 | 10 | No | — | S |
| 2 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| 5 | TALLER DE ELECTRICIDAD | `TEL` | 16 | 16 | 10 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **144** | **144** | **39** | | | |

**Totales de la carrera**: 360 h teoría + 328 h práctica = **688 h** totales, **95 créditos** distribuidos en **4 niveles**.

---

### G.12. Técnico Superior en Medio Ambiente y Manejo Integrado de Cuencas Hidrográficas

**Abreviatura**: `MEDIO AMBIENTE` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 21

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EDUCACIÓN AMBIENTAL | `EA` | 8 | 8 | 6 | No | — | S |
| 2 | ÉTICA AMBIENTAL | `ETA` | 16 | 0 | 2 | No | — | S |
| 3 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 4 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 5 | RECURSOS HÍDRICOS | `RHI` | 8 | 8 | 2 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **48** | **32** | **16** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CUENCAS HIDROGRÁFICAS | `CH` | 8 | 8 | 6 | No | — | S |
| 2 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 3 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 4 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| 5 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 6 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| | **TOTAL Cuatrimestre 2** | | **64** | **48** | **21** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CAMBIO CLIMÁTICO | `CC` | 8 | 8 | 3 | No | — | S |
| 2 | DESARROLLO SOSTENIBLE | `DS` | 8 | 8 | 6 | No | — | S |
| 3 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 4 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 5 | LEGISLACIÓN AMBIENTAL | `LA` | 16 | 0 | 3 | No | — | S |
| 6 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| | **TOTAL Cuatrimestre 3** | | **64** | **32** | **19** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ECOLOGÍA DEMOGRÁFICA | `ED` | 8 | 8 | 10 | No | — | S |
| 2 | ECOSISTEMAS | `ECOS` | 8 | 8 | 10 | No | — | S |
| 3 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 4 | PRÁCTICA PROFESIONAL (PROYECTO DE GRADO) | `PPR` | 80 | 80 | 10 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **112** | **112** | **36** | | | |

**Totales de la carrera**: 288 h teoría + 224 h práctica = **512 h** totales, **92 créditos** distribuidos en **4 niveles**.

---

### G.13. Técnico Superior en Acuicultura

**Abreviatura**: `ACUICULTURA` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 22

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA I | `EOI` | 8 | 8 | 3 | Sí | — | S |
| 2 | INGLÉS I | `INGI` | 8 | 8 | 3 | Sí | — | S |
| 3 | ACUICULTURA | `ACU` | 16 | 16 | 6 | No | — | S |
| 4 | SISTEMAS DE PRODUCCIÓN ACUICOLA | `SISPA` | 16 | 16 | 6 | No | — | S |
| 5 | TIPOS DE CULTIVO ACUICOLA | `TIPCA` | 16 | 16 | 6 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **64** | **64** | **24** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | EXPRESIÓN ORAL Y ESCRITA II | `EOII` | 8 | 8 | 3 | Sí | EOI | S |
| 2 | MATEMÁTICA I | `MATI` | 8 | 8 | 3 | Sí | — | S |
| 3 | INFORMÁTICA APLICADA | `INFOA` | 16 | 16 | 3 | Sí | — | S |
| 4 | GEOGRAFÍA DE PANAMÁ | `GPA` | 16 | 0 | 3 | Sí | — | S |
| 5 | BIOLOGÍA INTEGRADA | `BA` | 16 | 16 | 6 | No | — | S |
| 6 | GESTIÓN AMBIENTAL | `GAM` | 8 | 8 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 2** | | **72** | **56** | **21** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | RECURSOS HÍDRICOS | `RHI` | 8 | 8 | 2 | No | — | S |
| 2 | MATEMÁTICA II | `MATII` | 8 | 8 | 3 | Sí | MATI | S |
| 3 | HISTORIA DE PANAMÁ | `HPA` | 16 | 0 | 2 | No | — | S |
| 4 | INGLÉS II | `INGII` | 8 | 8 | 2 | Sí | INGI | S |
| 5 | TÉCNICAS AUXILIARES DE ACUICULTURA | `TAA` | 16 | 16 | 6 | No | — | S |
| 6 | LEGISLACIÓN ECOLOGICA | `LEGE` | 16 | 16 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **72** | **56** | **18** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | PRODUCCIÓN Y MERCADO | `PM` | 16 | 16 | 6 | No | — | S |
| 2 | NUTRICIÓN Y SANIDAD | `NS` | 8 | 8 | 3 | No | — | S |
| 3 | ECOSISTEMAS | `ECOS` | 8 | 8 | 10 | No | — | S |
| 4 | FORMULACIÓN Y EVALUACIÓN DE PROYECTO | `FEP` | 16 | 16 | 6 | No | MATI,MATII | S |
| 5 | PRÁCTICA PROFESIONAL | `PP` | 80 | 80 | 10 | No | — | S |
| | **TOTAL Cuatrimestre 4** | | **128** | **128** | **35** | | | |

**Totales de la carrera**: 336 h teoría + 304 h práctica = **640 h** totales, **98 créditos** distribuidos en **4 niveles**.

---

### G.14. Curso de Buceo Comercial

**Abreviatura**: `BUCEO COMERCIAL` · **Niveles declarados en el plan**: 4 · **Niveles con asignaturas en el catálogo actual**: 4 · **Asignaturas**: 32

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | ACONDICIONAMIENTO FÍSICO EN PISCINA | `AFP` | 0 | 2 | 2 | No | — | S |
| 2 | ANATOMÍA Y FISIOLOGÍA APLICADA AL BUCEO | `AFAB` | 24 | 0 | 2 | No | — | S |
| 3 | DIBUJO, LECTURA DE PLANOS Y REDACCIÓN DE INFORMES | `DLPRI` | 4 | 4 | 1 | No | — | S |
| 4 | GASES NOCIVOS EN ESPACIOS CERRADOS | `GNEC` | 1 | 0 | 2 | No | — | S |
| 5 | MOTORES Y COMPRESORES MARINOS | `MC` | 16 | 0 | 1 | No | — | S |
| 6 | PELIGROS AMBIENTALES DEL BUCEO | `PAMB` | 1 | 0 | 2 | No | — | S |
| 7 | PRINCIPIOS DE LA FÍSICA EN EL BUCEO | `PFAF` | 32 | 0 | 2 | No | — | S |
| 8 | SEGURIDAD INDUSTRIAL Y OFFSHORE | `SIPA` | 24 | 0 | 2 | No | — | S |
| 9 | SISTEMA DE AGUAS CALIENTES | `SAC` | 2 | 0 | 2 | No | AFS | S |
| | **TOTAL Cuatrimestre 1** | | **104** | **6** | **16** | | | |

#### Cuatrimestre 2 (2° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | FUNDAMENTOS DE NÁUTICA Y APAREJOS | `FNA` | 25 | 0 | 2 | No | AFS | S |
| 2 | MANTENIMIENTO DEL UMBILICAL DEL BUZO | `MUB` | 8 | 8 | 2 | No | — | S |
| 3 | ACONDICIONAMIENTO FÍSICO SCUBA | `AFS` | 0 | 20 | 2 | No | AFP | S |
| 4 | APLICACIÓN DE FÓRMULAS | `AF` | 13 | 0 | 1 | No | PFAF | S |
| 5 | INTRODUCCIÓN A LA SOLDADURA EN CUBIERTA | `ISDLR` | 17 | 17 | 2 | No | EDSS | S |
| 6 | REGISTRO DE BUCEO Y NORMAS PARA OPERACIONES DE BUCEO | `RNOB` | 8 | 8 | 2 | No | TDD | S |
| 7 | TABLAS DE DESCOMPRESIÓN DE AIRE Y PROCEDIMIENTOS DE DESCOMPRESIÓN | `TDD` | 16 | 16 | 2 | No | PFAF | S |
| 8 | EQUIPOS DE SOLDADURA EN SUPERFICIE | `EDSS` | 8 | 8 | 2 | No | — | S |
| 9 | PLANIFICACIÓN DE OPERACIONES | `PO` | 8 | 8 | 2 | No | SIPA | S |
| | **TOTAL Cuatrimestre 2** | | **103** | **85** | **17** | | | |

#### Cuatrimestre 3 (3° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | APLICACIÓN PRÁCTICA DE NÁUTICA Y MANEJO DE APAREJO | `APNMA` | 0 | 60 | 2 | No | FNA | S |
| 2 | FUNCIÓN Y NOMENCLATURA DE EQUIPOS DE BUCEO LIVIANO | `FNEL` | 12 | 12 | 2 | No | MUB | S |
| 3 | BUCEO CON MEZCLA DE GASES | `BCG` | 15 | 15 | 2 | No | PFAF,TDD,AFAB | S |
| 4 | ENFERMEDADES, LESIONES Y ASPECTOS PSICOLÓGICOS DEL BUCEO | `ELAPB` | 16 | 0 | 1 | No | AFAB,PFAF | S |
| 5 | INTRODUCCIÓN AL CORTE CON OXIGENO-ACETILENO | `TCAP` | 24 | 0 | 2 | No | ISDLR | S |
| 6 | PRIMEROS AUXILIOS PARA BUZOS Y RPC | `PAB` | 8 | 8 | 2 | No | AFAB | S |
| 7 | PROCEDIMIENTO Y TÉCNICAS PARA EL BUCEO LIVIANO | `PTBL` | 20 | 20 | 2 | No | FNEL,EDSS,AFS | S |
| 8 | TRATAMIENTO DE ENFERMEDADES Y LESIONES DEL BUZO | `TFLB` | 32 | 0 | 2 | No | PFAF,ELAPB,AFAB | S |
| 9 | HERRAMIENTAS SUBMARINAS | `HSSAG` | 13 | 13 | 2 | No | — | S |
| | **TOTAL Cuatrimestre 3** | | **140** | **128** | **17** | | | |

#### Cuatrimestre 4 (4° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | CÁMARA HIPERBÁRICA Y EQUIPOS ASOCIADOS | `CHEO` | 30 | 30 | 2 | No | AFAB,PFAF,TDD,TFLB | S |
| 2 | APLICACIÓN PRACTICA DEL MÉTODO CORTE CON OXIGENO ACETILENO | `APOA` | 0 | 16 | 2 | No | TFLB,EDSS,TCAP | S |
| 3 | ENTRENAMIENTO EQUIPO SCUBA EN EL MAR | `EESM` | 0 | 20 | 2 | No | AFS,PTBL | S |
| 4 | INTRODUCCIÓN AL CORTE Y SOLDADURA SUBMARINA | `ICSS` | 24 | 0 | 2 | No | TCAP,ISDLR,EDSS | S |
| 5 | PARTICIPACIÓN DEL APRENDÍZ EN OPERACIONES DE CÁMARA HIPERBÁRICA | `PACH` | 0 | 44 | 4 | No | ELAPB,CHEO,PAB | S |
| | **TOTAL Cuatrimestre 4** | | **54** | **110** | **12** | | | |

**Totales de la carrera**: 401 h teoría + 329 h práctica = **730 h** totales, **62 créditos** distribuidos en **4 niveles**.

---

### G.15. Grupo Makro Seguridad, Mantenimiento y Operación en Excavadora Hidráulica

**Abreviatura**: `EXCAVADORA HIDRÁULICA` · **Niveles declarados en el plan**: 1 · **Niveles con asignaturas en el catálogo actual**: 1 · **Asignaturas**: 3

#### Cuatrimestre 1 (1° de la carrera)


| # | Asignatura | Código | HT | HP | Créd | TC | Prelación | Obl. |
|---:|---|---|---:|---:|---:|:-:|---|:-:|
| 1 | MANTENIMIENTO EN MAQUINARIA AMARILLA | `MMA` | 24 | 0 | 3 | No | — | S |
| 2 | OPERACION EN MAQUINARIA AMARILLA | `OPA` | 0 | 24 | 3 | No | — | S |
| 3 | SEGURIDAD EN MAQUINARIA AMARILLA | `SGA` | 24 | 0 | 3 | No | — | S |
| | **TOTAL Cuatrimestre 1** | | **48** | **24** | **9** | | | |

**Totales de la carrera**: 48 h teoría + 24 h práctica = **72 h** totales, **9 créditos** distribuidos en **1 niveles**.

---

. No requieren permisos especiales más allá del acceso a `isidb`.

---

## Control de cambios

| Versión | Fecha | Descripción | Autor |
|---|---|---|---|
| 1.0 | Octubre 2026 | Emisión inicial del informe ejecutivo del LMS. | Tecnología de la Información |
