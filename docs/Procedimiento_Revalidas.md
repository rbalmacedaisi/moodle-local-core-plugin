# Procedimiento de Reválida de Asignaturas

| Campo | Detalle |
|---|---|
| **Código** | PR-ACA-REV-001 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 2026-06-09 |
| **Área responsable** | Dirección Académica |
| **Áreas implicadas** | Dirección Académica · Docencia · Administración Financiera · Tecnología de la Información |
| **Sistema(s)** | LMS (Moodle `grupomakro_core`) · Pasarela de integración · ERP de facturación (Odoo) |
| **Clasificación** | Uso interno |

---

## 1. Objetivo

Establecer el procedimiento estandarizado para la gestión de **reválidas de asignaturas**: el proceso
mediante el cual un estudiante que obtuvo una calificación final cercana a la aprobación en una
asignatura teórica puede presentar una evaluación adicional (reválida) que, de aprobarse, consolida la
asignatura como aprobada.

## 2. Alcance

Aplica a todas las asignaturas **teóricas** (sin horas prácticas) impartidas en la plataforma, desde la
identificación del estudiante elegible por parte del docente hasta la consolidación de la calificación
final y su reflejo en el panel de la Dirección Académica.

Quedan **fuera de alcance**:
- Asignaturas con horas prácticas (no aplican a reválida por norma institucional).
- El cierre definitivo del grupo, que es un proceso separado y posterior (ver sección 9).

## 3. Definiciones

| Término | Definición |
|---|---|
| **Reválida** | Evaluación adicional que se ofrece al estudiante elegible para recuperar la aprobación de una asignatura. |
| **Estudiante elegible** | Estudiante de una asignatura teórica cuya **nota final integrada** está en el rango **60.0 – 70.9** y la asignatura **no tiene horas prácticas**. |
| **Nota final integrada** | Calificación ponderada total del estudiante en la asignatura, calculada por el libro de calificaciones del docente (suma de cada actividad × su ponderación). |
| **Ponderaciones al 100 %** | Condición en la que la suma de los pesos de todas las actividades calificables del grupo es exactamente 100 %. Requisito para programar reválidas. |
| **Sesión de reválida** | Reunión virtual (BigBlueButton) agendada para que el estudiante presente la evaluación de reválida. |
| **Factura de reválida** | Documento de cobro generado en el ERP por el costo de la reválida; su número (consecutivo) queda registrado para verificar el pago. |
| **Consolidación** | Registro definitivo del resultado de la reválida sobre la calificación de la asignatura. |

## 4. Política y criterios

1. **Elegibilidad.** Solo es elegible el estudiante de una asignatura **teórica (0 horas prácticas)**
   con **nota final integrada entre 60.0 y 70.9**.
2. **Requisito de ponderaciones.** El docente solo puede programar reválidas cuando las **ponderaciones
   del grupo suman 100 %** y todas las actividades están calificadas.
3. **Pago obligatorio para calificar.** La nota de la reválida **solo puede registrarse si la factura
   de reválida está pagada**. El sistema verifica el pago antes de permitir guardar la nota.
4. **Resultado de la reválida:**
   - Nota de reválida **mayor a 70.9** → la asignatura se consolida como **Aprobada** con calificación
     final **71** (mínima de aprobación).
   - Nota de reválida **igual o menor a 70.9** → la asignatura queda **Reprobada**, conservando la
     **nota original** del estudiante. En ambos casos queda el **registro** de la reválida.
5. **Decisión del docente.** La reválida no es automática: el docente decide y marca explícitamente
   qué estudiantes elegibles la presentarán.
6. **Trazabilidad.** Todo el proceso queda registrado: sesión virtual, número de factura, estado de
   pago, nota de reválida y resultado.

## 5. Roles y responsabilidades

| Rol | Responsabilidades |
|---|---|
| **Docente** | Calificar todas las actividades y definir ponderaciones al 100 %. Identificar y **marcar** a los estudiantes elegibles. **Programar** la reválida (genera sesión virtual y factura). Aplicar la evaluación en la sesión y **registrar la nota** de reválida una vez confirmado el pago. |
| **Estudiante** | Realizar el **pago** de la factura de reválida. Asistir a la **sesión virtual** en la fecha/hora indicada y presentar la evaluación. |
| **Administración Financiera** | Emitir/gestionar la **factura** en el ERP y **confirmar el pago**. Atender dudas de cobro y conciliación. |
| **Dirección Académica** | **Supervisar** el proceso, validar resultados en su panel, dar seguimiento a casos especiales y autorizar excepciones. |
| **Tecnología de la Información** | Garantizar la **operación** de la plataforma y las integraciones (LMS, pasarela, ERP). Soporte ante incidencias técnicas. |

## 6. Procedimiento

### Fase 1 — Preparación (Docente)
1. El docente **califica todas las actividades** del grupo en el Libro de Calificaciones.
2. Verifica que las **ponderaciones sumen 100 %** (indicador visible en el panel de gestión de reválidas).

### Fase 2 — Identificación de elegibles (Sistema → Docente)
3. El sistema **resalta automáticamente** en el Libro de Calificaciones a los estudiantes elegibles
   (asignatura teórica + nota final 60.0–70.9), con una marca **"Reválida"** junto al nombre.
4. En la sección **"Gestión de Reválidas"** (debajo de la tabla de calificaciones) el docente ve la
   lista de elegibles con su nota final.

### Fase 3 — Programación (Docente → Sistema → Financiera)
5. El docente **marca con la casilla** a los estudiantes que presentarán la reválida.
6. Pulsa **"Programar reválidas"**. En ese momento el sistema, de forma automática:
   - **Crea una sesión virtual (BigBlueButton)** en el mismo día y hora de la clase, **la semana
     siguiente** a la finalización del grupo (visible en el calendario del estudiante y del docente).
   - **Genera la factura de reválida** en el ERP por el costo establecido y **almacena su número
     (consecutivo)** y el enlace de pago.
   - **Habilita la casilla** para registrar la nota de la reválida.

### Fase 4 — Pago (Estudiante → Financiera → Sistema)
7. El estudiante **paga** la factura por el medio dispuesto.
8. El sistema marca la factura como **Pagada**:
   - de forma **automática** cuando el ERP notifica el pago, o
   - de forma **manual** cuando el docente o la Dirección usa la opción **"Verificar pago"**.

### Fase 5 — Evaluación y calificación (Docente)
9. En la fecha programada se realiza la **sesión virtual** y el estudiante presenta la reválida.
10. El docente **registra la nota de reválida**. El sistema **solo permite guardar si la factura está
    pagada**.
11. El sistema **consolida** el resultado:
    - Nota **> 70.9** → **Aprobada**, calificación final **71**.
    - Nota **≤ 70.9** → **Reprobada**, se conserva la nota original.

### Fase 6 — Supervisión y cierre (Dirección Académica)
12. La Dirección Académica visualiza en su panel el **registro de reválida** de cada estudiante
    (nota original, nota de reválida, estado de pago y resultado), identificado con una etiqueta:
    *"Aprobó reválida (71)"*, *"Reprobó reválida"* o *"Reválida programada"*.
13. El **cierre definitivo** del grupo respeta el resultado consolidado de las reválidas (ver sección 9).

## 7. Diagrama de flujo (resumen)

```
DOCENTE                         SISTEMA / INTEGRACIONES            ESTUDIANTE        FINANCIERA
  │ Califica + pesos 100%
  │ ───────────────────────────► Resalta elegibles (60–70.9)
  │ Marca estudiantes
  │ "Programar reválidas" ──────► Crea sesión BBB (semana sgte.)
  │                               Genera factura (consecutivo) ───────────────────► Factura emitida
  │                               Habilita casilla de nota
  │                                                                 Paga factura ──► Confirma pago
  │                               Marca "Pagada" ◄─────────────────────────────────┘ (webhook/manual)
  │ Aplica evaluación en sesión ◄──────────────────────────────── Asiste a sesión
  │ Registra nota (si pagada) ──► Consolida:
  │                                 > 70.9 → Aprobada (71)
  │                                 ≤ 70.9 → Reprobada (nota original)
  │                               Refleja resultado en panel Dirección
```

## 8. Registros y evidencias

| Registro | Contenido | Ubicación |
|---|---|---|
| **Registro de reválida** | Estudiante, asignatura, nota original, nota de reválida, resultado, estado de pago, fecha de sesión. | Sistema LMS (tabla de reválidas). |
| **Sesión virtual** | Actividad BigBlueButton con fecha/hora y participantes. | Curso en el LMS / calendario. |
| **Factura de reválida** | Número (consecutivo), monto, estado de pago, enlace de pago. | ERP de facturación. |
| **Bitácora de cierre** | Resumen de aprobados/reprobados/reválidas al cerrar el grupo. | Registro de auditoría del LMS. |

## 9. Relación con el cierre definitivo del grupo

El **cierre del grupo** (realizado posteriormente desde la gestión de cursos) es **definitivo** y:
- **Respeta** el resultado consolidado de las reválidas ya gestionadas (71/Aprobada o nota
  original/Reprobada).
- **No** deja a ningún estudiante en estado "Pendiente Reválida": un estudiante elegible que el docente
  **no** marcó para reválida se cierra como **Reprobada** con su nota.
- Por lo tanto, **la gestión de reválidas debe realizarse antes del cierre del grupo.**

## 10. Excepciones y consideraciones

- **Pago no realizado:** sin factura pagada no es posible registrar la nota de reválida. La nota puede
  ingresarse posteriormente, una vez confirmado el pago, incluso si el grupo ya fue cerrado.
- **Asignaturas con horas prácticas:** no aplican a reválida; el sistema no las marca como elegibles.
- **Ponderaciones distintas de 100 %:** el sistema bloquea la programación de reválidas hasta corregir
  los pesos.
- **Casos especiales** (anulaciones, reembolsos, reprogramación de sesión) los autoriza la Dirección
  Académica en coordinación con Administración Financiera.

## 11. Control de cambios

| Versión | Fecha | Descripción | Autor |
|---|---|---|---|
| 1.0 | 2026-06-09 | Emisión inicial del procedimiento de reválida dirigido por el docente (rango de elegibilidad 60.0–70.9). | Tecnología de la Información |
