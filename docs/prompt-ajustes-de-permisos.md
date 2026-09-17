# Prompt: ajustes de permisos de los roles administrativos

Pega el bloque de abajo a un agente cuando haya que dar (o quitar) acceso a un rol.
Está escrito para alguien que **no conoce este proyecto**, así que es autocontenido.

Actualizado al plugin **20261001062**.

---

## PROMPT (copiar desde aquí)

Trabajas sobre el plugin Moodle `local_grupomakro_core`. Te van a pedir que un rol
administrativo pueda acceder a algo (una página, un botón, un informe). Tu trabajo es
concederlo **completo y verificado**, no a medias.

### Entorno

- Repo local: `C:\Users\Ramiro\Documents\TI\Proyectos\Plug Ins Moodle\grupomakro_core`
- Servidor: `ssh -i C:/cred/moodle.pem ubuntu@100.28.104.64`, Moodle en
  `/var/www/html/moodle`, plugin en `/var/www/html/moodle/local/grupomakro_core`
- BD: MySQL en `52.20.149.225`, base `isidb`, prefijo de tablas `isi_`, usuario `isi`
- Deploy: commit + push a `origin master` → en el servidor `git pull origin master`,
  `chown -R ubuntu:ubuntu`, `admin/cli/upgrade.php --non-interactive`,
  `admin/cli/purge_caches.php` y `systemctl reload php7.4-fpm`

### Roles activos

| id | shortname | nombre |
|----|-----------|--------|
| 14 | `gmk_director_academico` | Director Académico |
| 15 | `gmk_secretaria_academica` | Secretaría Académica |
| 16 | `gmk_registros_academicos` | Registros Académicos |
| 17 | `gmk_soporte_ti` | Soporte TI |
| 18 | `gmk_bienestar` | Coordinador de Bienestar |
| 19 | `gmk_psicologo` | Psicólogo/a |
| 12 | `administrative` | Administrativo (legacy) |

Los permisos se declaran en el array `$role_caps` de
`db/upgradelib.php`, dentro de `assign_capabilities_to_internal_roles()`.
`db/access.php` solo declara las capabilities del plugin.

### Regla de oro: audita ANTES de conceder

**Dar la capability de la página casi nunca basta.** Este plugin reparte el control de
acceso en **nueve capas** y cada una puede bloquear por su cuenta. Hacerlo de una vez evita
la cadena de "ya funciona / ahora falla otra cosa" que costó más de veinte despliegues.

Antes de tocar nada, inventaria **todas** las piezas de lo que te piden:

1. **La página** — `grep require_capability pages/<x>.php`
2. **Controles paralelos dentro de la misma página** — una página puede exigir su capability
   arriba y volver a decidir más abajo por su cuenta.
   `grep -nE "is_siteadmin|LIKE '%teacher%'|\$userRole" pages/<x>.php`
3. **El árbol de administración** (`settings.php`) — 27 páginas llaman
   `admin_externalpage_setup()`, que busca la página en el árbol y le hace `check_access()`.
   Si la página no está registrada con su capability, da *Acceso denegado* aunque el
   `require_capability` pase.
4. **El menú** (`lib.php`, `local_grupomakro_core_extend_navigation`) — mapa
   `[capability, página, etiqueta]`. Usa la capability que la página exige de verdad.
5. **Los `case` de `ajax.php`** — `grep -n "case 'local_grupomakro_<accion>'" ajax.php` y mira
   su `require_capability`.
6. **`db/services.php`** — el campo `capabilities` del web service.
7. **El `require_capability` dentro del `execute()` de la clase externa**
   (`classes/external/**`) — es el más escondido: no está ni en la página ni en `ajax.php`.
8. **Flags JS en la página** — `grep -n "is_siteadmin" pages/<x>.php` y mira qué variable
   emite (`window.GMK_IS_SITEADMIN`, `var isAdmin`, …). Si el endpoint responde bien por HTTP
   pero el usuario "no puede", **el bloqueo es visual**.
9. **Capabilities CORE de Moodle que las APIs usan por dentro** — ver más abajo.

### Capabilities core: la trampa que más ha costado

Los roles `gmk_*` nacieron **sin ninguna capability del core**. Varias APIs de Moodle
comprueban permisos core por dentro y fallan de formas engañosas:

- `add_moduleinfo()` (crear cualquier actividad) crea además su **evento de calendario** y
  exige `moodle/calendar:manageentries`. Sin ella la transacción muere a medias: la instancia
  del módulo queda **sin `course_module`** y el docente ve *"no hay sesión vinculada"* en BBB.
  Es también el error al copiar o reprogramar una sesión.
- Abrir **cualquier curso o el libro de calificaciones** exige `moodle/course:view`; sin ella
  no abre aunque tengas todas las de `grade`.
- El selector **"Añadir una actividad o recurso"** se llena con `mod/<modname>:addinstance`,
  una por módulo (27 en este sitio). Con `moodle/course:manageactivities` el modo de edición
  se enciende pero la lista sale **vacía**.
- Ver/editar el **perfil** de un usuario necesita `moodle/user:viewdetails`,
  `moodle/user:viewalldetails` y, para editar, `moodle/user:update`.

### Al escribir el cambio

- Añade las capabilities al bloque del rol en `db/upgradelib.php`.
- Añade un paso en `db/upgrade.php` que llame `assign_capabilities_to_internal_roles()`
  (es idempotente y reaplica toda la matriz).
- **Antes de numerar la versión**, comprueba las dos cosas, porque el equipo trabaja en
  paralelo y `version.php` es una pila donde **gana la última línea**:
  ```
  grep -oE 'plugin->version   = [0-9]+' version.php | tail -3
  SELECT value FROM isi_config_plugins WHERE plugin='local_grupomakro_core' AND name='version';
  ```
  Elige un número mayor que ambas. Si pones uno menor, el upgrade se niega con
  *"No se puede pasar … de X a Y"*, y un umbral `if ($oldversion < N)` por debajo del actual
  no se ejecuta nunca.
- **Comprueba en qué rama estás** (`git branch --show-current`). El working tree suele quedar
  en ramas del equipo; `git push origin master` responde *"Everything up-to-date"* sin subir
  nada y el deploy parece correcto. Si te pasa: `git checkout master` + `git cherry-pick <hash>`.
- `assign_capability()` corre con `$overwrite = false`: **solo añade, nunca revoca**. Para
  quitar un permiso hay que hacerlo por la UI o con `unassign_capability()`.

### Verificación obligatoria (no des nada por hecho)

No basta con que el upgrade diga Éxito. Comprueba el acceso **como el usuario real**:

```php
// script CLI en /var/www/html/moodle, borrar al terminar
\core\session\manager::set_user($DB->get_record('user', ['id' => <userid>], '*', MUST_EXIST));
has_capability('<capability>', context_system::instance());
```

Y mejor aún, por HTTP con una sesión real (no hace falta la contraseña): crea una sesión
escribiendo `$CFG->dataroot/sessions/sess_<sid>` con
`'USER|'.serialize($user).'SESSION|'.serialize(new stdClass)` más su fila en `{sessions}`,
usa `curl -b "MoodleSession=<sid>"`, y **borra después la fila y el fichero**.

Interpreta los códigos: `200` = entra; `303` a `/login/` = sin sesión; `303`/`404` en una
página concreta = le falta capability. Para una descarga, valida que el resultado sea real
(`content_type` de xlsx y los dos primeros bytes `PK`), no solo que devuelva 200.

### Cómo informar

Di con claridad **qué concediste, qué dejaste fuera y por qué**, y muestra la verificación.
Si el rol ya tenía el permiso y el fallo era otro, dilo — ha pasado varias veces.

Avisa siempre de dos cosas cuando apliquen:

- Las capabilities van en **contexto de sistema**, así que alcanzan a **todos** los cursos y
  usuarios del sitio, no solo al área del rol.
- `moodle/user:update` permite editar cualquier cuenta que no sea siteadmin y restablecer
  contraseñas; no se puede acotar a estudiantes.

### Contexto útil

El sitio corre con `debug=32767` y **`debugdisplay=1`**. Cualquier `debugging()` en la ruta de
un web service se imprime **dentro de la respuesta** y rompe el JSON que el front parsea:
el síntoma es "la página carga pero no trae datos". Si lo ves, busca el aviso, no el permiso.

## (fin del prompt)
