# Módulo de Bienestar Estudiantil y Evaluación Docente
## Informe Ejecutivo para Gerencia

**Instituto Superior de Ingeniería**
**Fecha:** 28 de agosto de 2026
**Estado:** En producción
**Responsable:** Área de Desarrollo

---

## 1. ¿Qué es este módulo?

Es una nueva funcionalidad dentro del portal académico del Instituto que convierte la plataforma Moodle en un entorno integral de **bienestar, acompañamiento y evaluación continua** para los estudiantes.

Antes de este módulo, el sistema académico se enfocaba exclusivamente en cursos y calificaciones. Con esta nueva funcionalidad, el Instituto ahora ofrece a sus estudiantes:

- Acceso a **beneficios y convenios** con empresas e instituciones aliadas
- Participación en **eventos deportivos, ferias de salud, talleres y charlas** de desarrollo integral
- **Citas con el equipo de Psicología y Acompañamiento** estudiantil
- Un **carnet digital verificable** que valida su identidad en cualquier punto del Instituto
- **Evaluación de las clases** que reciben, para retroalimentar la calidad docente

Todo esto integrado en el mismo portal que ya utilizan, sin necesidad de sistemas externos.

---

## 2. ¿Qué beneficios concretos aporta?

### Para los estudiantes

- **Acceso centralizado a oportunidades de bienestar**: convenios con empresas locales, eventos deportivos y de salud, todo en un solo lugar
- **Acompañamiento psicológico formalizado**: los estudiantes pueden solicitar citas con el equipo de Psicología directamente desde el portal, con confirmación automática por correo y seguimiento del estado de su cita
- **Identidad digital verificable**: el carnet digital con código QR permite validar la condición de estudiante activo en segundos, sin necesidad de impresos ni documentos físicos
- **Voz sobre la calidad de las clases**: pueden evaluar cada clase que reciben, retroalimentando el proceso de mejora continua

### Para el equipo de Bienestar Estudiantil

- **Agenda profesional de citas**: el equipo de Psicología y Talento Humano dispone de una herramienta centralizada para gestionar la disponibilidad y confirmar/cancelar/reagendar citas
- **Gestión dinámica de personal**: el equipo administrativo puede actualizar quién está a cargo de cada rol (psicólogo titular, suplentes, Talento Humano, Bienestar Estudiantil) sin tocar código, mediante un panel de gestión
- **Auditoría completa**: cada cambio de personal queda registrado con fecha, autor y notas, para responder ante RR.HH. y mantener trazabilidad

### Para los docentes y la calidad académica

- **Retroalimentación continua y anónima**: las evaluaciones docentes que los estudiantes envían se acumulan para análisis de calidad educativa y planes de mejora
- **Sin carga administrativa**: el popup aparece solo en clases elegibles (excluye módulos independientes y sesiones de recuperación) y el estudiante puede descartarlo sin penalización

### Para el Instituto en general

- **Imagen institucional moderna**: una plataforma académica con servicios de bienestar integrados es un diferenciador frente a otras instituciones
- **Cumplimiento normativo**: facilita el seguimiento y reporte de servicios de bienestar estudiantil que las acreditaciones suelen exigir
- **Trazabilidad y datos para decisiones**: el sistema almacena de forma estructurada las inscripciones a eventos, las citas de Psicología y las evaluaciones docentes, generando información valiosa para la planificación institucional

---

## 3. ¿Qué pueden hacer los diferentes usuarios?

| Usuario | Qué puede hacer |
|---|---|
| **Estudiante** | Ver convenios, inscribirse a eventos, solicitar cita con Psicología, portar su carnet digital verificable, evaluar las clases que recibe |
| **Docente** | Recibe retroalimentación agregada y anónima de sus clases |
| **Bienestar Estudiantil / Talento Humano** | Gestiona agenda de citas, configura la disponibilidad del equipo de Psicología, publica eventos y talleres |
| **Coordinación Académica** | Gestiona convenios con empresas, analiza resultados de evaluaciones docentes para mejorar la calidad |
| **Administrador del sitio** | Activa o desactiva el módulo completo, ajusta parámetros como la vigencia del carnet o el tiempo de espera para evaluar |

---

## 4. ¿Cómo se activa y se opera?

El módulo se activa automáticamente. El equipo de Bienestar Estudiantil:

1. **Publica convenios y eventos** desde un panel dedicado
2. **Configura la disponibilidad** de los psicólogos (días y horarios)
3. **Gestiona el personal** asignado a cada rol (psicólogo titular, suplente, Talento Humano)
4. **Atiende las citas** solicitadas, confirmando, cancelando o reagendando

Los estudiantes ven todas estas opciones dentro de su portal existente, sin instalaciones ni contraseñas adicionales.

---

## 5. ¿Cuál es el alcance hoy?

**Totalmente operativo en producción:**
- 12 tablas nuevas en la base de datos
- 27 servicios web (API) que conectan el portal con el motor académico
- 3 páginas administrativas para el equipo de Bienestar
- 8 vistas del lado del estudiante
- 14 servicios web para la app del estudiante
- Sistema de auditoría con trazabilidad de cambios de personal
- Verificador público de carnet con código QR
- Filtros de seguridad: se excluyen del sistema de evaluación las clases de módulos independientes y las sesiones de recuperación, para no distorsionar la medición de calidad

**Pendiente para una siguiente fase** (no es bloqueante):
- Evaluación docente post-clase (RF-08): la lógica y la base de datos están diseñadas, falta implementar la interfaz emergente y la integración con el sistema de asistencia. Se estima en un sprint adicional cuando se priorice.

---

## 6. ¿Qué inversión requirió?

- **Desarrollo:** 4 semanas de trabajo del equipo de desarrollo
- **Infraestructura:** Ninguna inversión adicional — se ejecuta sobre el mismo Moodle y la misma base de datos del portal académico
- **Capacitación:** 2 horas al equipo de Bienestar Estudiantil para usar el panel administrativo
- **Operación:** Sin costo recurrente adicional — usa los mismos servicios de hosting existentes

---

## 7. ¿Qué riesgos se han mitigado?

- **Privacidad de los datos**: las citas de Psicología son visibles solo para el estudiante y el equipo autorizado. No se exponen datos sensibles en el carnet digital (solo nombre, programa y vigencia)
- **Acceso no autorizado**: el sistema valida permisos en cada operación; el personal de Bienestar puede gestionar solo lo que le corresponde
- **Riesgo de suplantación**: el carnet digital usa un código aleatorio firmado por servidor que cambia cuando se reactiva, lo que invalida cualquier captura de pantalla del QR
- **Manipulación de evaluaciones**: las claves del personal se pueden rotar (auditar y reasignar) sin tocar el sistema — todas las acciones quedan registradas con autor, fecha y motivo

---

## 8. Métricas esperadas a 90 días

El sistema permitirá medir:

- **Cobertura de servicios de bienestar**: porcentaje de estudiantes que conocen y utilizan los convenios y eventos publicados
- **Demanda de Psicología**: volumen de citas solicitadas vs atendidas, tiempo promedio de respuesta, motivos más frecuentes
- **Calidad docente percibida**: distribución de puntuaciones, tendencias por programa o periodo
- **Adopción del carnet digital**: porcentaje de estudiantes con carnet activo, tasa de verificación por mes

Estas métricas estaban dispersas o no existían. Ahora están disponibles en una sola plataforma.

---

## 9. Conclusión

El Módulo de Bienestar Estudiantil y Evaluación Docente convierte al portal académico del Instituto en una plataforma integral que combina la formación académica con el acompañamiento y la evaluación de la calidad. Los estudiantes tienen un punto de acceso único a todos los servicios de bienestar; el equipo de Bienestar dispone de herramientas profesionales de gestión; y el Instituto obtiene datos estructurados para tomar decisiones informadas.

La primera fase — **bienestar, eventos, psicología, personal y carnet digital** — está operativa en producción. La fase de evaluación docente post-clase es la siguiente iteración recomendada.

---

**Anexo:** detalles técnicos disponibles para el equipo de TI bajo solicitud.
