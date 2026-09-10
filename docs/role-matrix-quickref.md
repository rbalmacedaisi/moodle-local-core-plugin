# Matriz de Roles — Quick Reference

> **Para el equipo de Registros, Soporte TI, Coordinación**.
> **Versión plugin**: 20261001007+.

## Los 7 roles

| Rol | # caps | Quién lo usa | Visión |
|---|---|---|---|
| `manager` | 587 | Super-admins (2-3 personas) | Acceso total Moodle |
| `gmk_director_academico` | 45 | Director Académico | Oversight + decisiones estructurales |
| `gmk_secretaria_academica` | 33 | Secretaría Académica | Operación diaria (clases, horarios, asistencia) |
| `gmk_registros_academicos` | 18 | Registros / Cartas / Diplomas | Documentos, contratos, exports |
| `gmk_soporte_ti` | 6 | Soporte TI | Debug, integraciones (Odoo, BBB) |
| `gmk_bienestar` | 4 | Coordinador Wellness | Módulo wellness (eventos, partners) |
| `gmk_psicologo` | 1 | Psicólogo/a | Solo agenda psicológica |

## Quién puede hacer qué (cheatsheet)

| Actividad | Dir | Sec | Reg | TI | Bien | Psi |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Crear/editar clases | ✓ | ✓ | | | | |
| Aprobar horarios | ✓ | ✓ | | | | |
| Cambiar estado de estudiante (aplazar/retirar) | ✓ | ✓ | | | | |
| **Anular** un movimiento académico ya registrado | ✓ | | | | | |
| Crear reválida **fuera de ventana** temporal | ✓ | | | | | |
| Marcar asistencia manualmente | ✓ | ✓ | | | | |
| Calificar (grades) | ✓ | ✓ | | | | |
| Generar diplomas / constancias | ✓ | | ✓ | | | |
| **Plantilla** de diplomas | ✓ | | ✓ | | | |
| Gestionar cartas (solicitudes) | | ✓ | ✓ | | | |
| Ver catálogo de tipos de carta | | | ✓ | | | |
| Crear/editar órdenes | ✓ | | ✓ | | | |
| Crear/editar instituciones | ✓ | | ✓ | | | |
| Crear/editar contratos institucionales | | | ✓ | | | |
| Crear/editar contratos individuales | ✓ | | ✓ | | | |
| Exportar datos de estudiantes | ✓ | ✓ | ✓ | | | |
| Eliminar usuarios masivamente | | | | | | | (solo `manager`)
| Importar notas desde Q10 | | | | | | | (solo `manager`)
| Acceder a debug pages | | | | ✓ | | |
| Gestionar eventos/partners wellness | | | | | ✓ | |
| Gestionar agenda psicológica | | | | | ✓ | ✓ |
| Publicar anuncios globales | | | | | ✓ | |
| Panel del Director (KPIs, cohortes) | ✓ | ✓ | | | | |
| Cerrar período académico | ✓ | | | | | |
| Subir calendario académico | ✓ | | | | | |
| Reset de sesiones BBB | ✓ | ✓ | | ✓ | | |

## Decisiones de diseño críticas

- **`bulk_delete_users` y `import_grades`**: SOLO `manager`. Nadie del equipo operativo.
- **`annul_movement`**: SOLO Director. Secretaría no puede borrar lo que ya hizo.
- **`create_extemporaneous_revalidation`**: SOLO Director. Secretaría ve revalidas pero no las crea fuera de ventana.
- **Teachers usan WS a nivel curso**: las caps `mod/assign:grade` y `mod/quiz:grade` se chequean dentro de la función, no a nivel servicio. Los teachers NO necesitan rol `gmk_*` para usar `teacher/save_grade`.

## Mapping de personas (producción)

| Persona | Email | Rol |
|---|---|---|
| Administrador Usuario | tic@isi.edu.pa | `manager` |
| Joyce Muñoz | direccionacademica@isi.edu.pa | `manager` |
| Walber Castillo | gerenciageneral@isi.edu.pa | `manager` (inactivo) |
| José Joel Rodriguez | j.rodriguez@isi.edu.pa | `gmk_director_academico` |
| Lizbeth Aizprua | laizprua@isi.edu.pa | `gmk_registros_academicos` |
| Jean Remice | j.remice@isi.edu.pa | `gmk_secretaria_academica` |
| Veronica Rangel | v.rangel@isi.edu.pa | `gmk_secretaria_academica` |
| Jorge Oviedo | j.oviedo@isi.edu.pa | `gmk_bienestar` |
| Dulce Jurado | d.jurado@isi.edu.pa | `gmk_psicologo` |
| Esteban Montoya | e.montoya@isi.edu.pa | `gmk_soporte_ti` |
| Fernanda Alonso | f.alonso@isi.edu.pa | (sin rol — solo se le removió `manager`) |

## Procedimiento: asignar un nuevo usuario

1. Como super-admin, ir a `/admin/roles/users.php`.
2. En "Assign roles in context: System", seleccionar el rol.
3. Buscar al usuario (por nombre, username o email).
4. Asignar.
5. **Purgar cachés** (Site administration → Development → Purge all caches).

## Procedimiento: "un usuario no ve X"

1. ¿Tiene el rol asignado? `/admin/roles/users.php?userid=<ID>`
2. ¿La cap está en el bundle del rol? Ver `docs/role-matrix.md` sección 3.
3. ¿La página/WS está registrada con esa cap? `db/services.php` y `settings.php`.
4. **Purgar cachés** después de cualquier cambio.

## Procedimiento: rollback

```bash
# Rollback del CLI de migración (restaura manager a todos)
APPLY=1 php local/grupomakro_core/cli/migrate_siteadmins_to_roles.php --reverse
```

---

**Para el documento completo**: ver `docs/role-matrix.md`.
