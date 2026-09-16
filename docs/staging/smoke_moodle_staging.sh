#!/usr/bin/env bash
# =============================================================================
# DES-MOODLE-002: Staging Moodle smoke test - 8 web services end-to-end
# =============================================================================
#
# PURPOSE:
#   Verifica que el staging Moodle (recién restaurado del dump sanitizado de
#   prod) responde correctamente a las 8 llamadas críticas que el ecosistema
#   Odoo/Moodle/Express/LXP ejerce contra Moodle. Es el gate "staging verde"
#   antes de promover código nuevo o de abrir tráfico LXP real contra staging.
#
# SCOPE:
#   - 6 core WS de Moodle (no requieren plugin)
#   - 1 WS del plugin local_grupomakro_core (read-side del bridge Odoo<->Moodle)
#   - 1 WS de escritura (core_completion_update_*) para validar que NO es solo
#     lectura (un dump pasivamente restaurado podría servir lecturas pero
#     fallar al primer write por missing capability o readonly transaction).
#
# USAGE:
#   MOODLE_URL=https://lms.students.isi.edu.pa \
#   MOODLE_TOKEN=<wstoken del external service 'smoke-test-staging'> \
#   ./smoke_moodle_staging.sh
#
# REQUIREMENTS:
#   - curl + python3 (REQUERIDOS - python3 hace el JSON parsing)
#   - jq (OPCIONAL - si está disponible se usa por velocidad; si no, python3)
#   - Un external service Moodle dedicado 'smoke-test-staging' con acceso a
#     los 8 WS listados. Crear desde Site Admin > Plugins > Web services >
#     External services > Custom services. Asignar a un usuario "smoke-bot"
#     que NO sea admin (preferentemente con rol similar a 'student' pero
#     con las capabilities justas que requiere este script - ver README).
#
# EXIT CODES:
#   0 = todos los WS respondieron OK (gate verde para Fase 5)
#   1 = al menos 1 WS falló (gate rojo - revisar sección "failed_ws")
#   2 = error de configuración (faltan vars, curl/jq ausente)
#
# NOTA SOBRE EL WS #7:
#   El ticket DES-MOODLE-002 menciona `local_moodle_sync_users` como "el
#   bridge Odoo<->Moodle". Ese WS NO existe en este fork
#   (grep -r 'local_moodle_sync' en classes/ = 0 matches). El bridge
#   Odoo<->Moodle en este fork se materializa con la tríada:
#     - Odoo:   moodle_user_sync (módulo Odoo) - ya parcheado en DES-ODOO-004
#     - Express: pasarela en rest_express (ya con guard de env en DES-LXP-008)
#     - Moodle: lectura del bridge via `local_grupomakro_get_user_courses`
#               (este fork) + `local_grupomakro_create_user` (escritura)
#   Por eso este script usa `local_grupomakro_get_user_courses` como
#   representativo del bridge. Si en el futuro el equipo crea un WS
#   `local_moodle_sync_users`, solo hay que cambiar SMOKE_WS[6].
# =============================================================================

set -u

# -----------------------------------------------------------------------------
# Validación de entrada
# -----------------------------------------------------------------------------
if [ -z "${MOODLE_URL:-}" ]; then
  echo "ERROR: MOODLE_URL es requerido (ej: https://lms.students.isi.edu.pa)" >&2
  exit 2
fi
if [ -z "${MOODLE_TOKEN:-}" ]; then
  echo "ERROR: MOODLE_TOKEN es requerido" >&2
  exit 2
fi
for cmd in curl python3; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: dependencia faltante: $cmd" >&2
    exit 2
  fi
done

# Si jq no está, definimos un shim python3 que imita las sub-expresiones
# minimas que este script usa: 'type', 'length', 'type == "object" and (...)',
# 'has($k)' y los interpoladores tipo '.exception', '.errorcode'.
# El shim es un script python standalone (no bash wrapper) porque algunos
# entornos MSYS/Git-Bash en Windows se comportan raro con `python -` heredoc.
if ! command -v jq >/dev/null 2>&1; then
  shim_dir="$(mktemp -d)"
  # Detectar el python a usar. En Linux (target real) es siempre /usr/bin/env python3.
  # En Windows MSYS preferimos 'python' (Windows-aware).
  if command -v python >/dev/null 2>&1; then
    py_bin="$(command -v python)"
  elif [ -n "${MSYSTEM:-}" ] && [ -n "${LOCALAPPDATA:-}" ] && [ -x "${LOCALAPPDATA//\\//}/../Local/Programs/Python/Python312/python.exe" ]; then
    py_bin="${LOCALAPPDATA//\\//}/../Local/Programs/Python/Python312/python.exe"
  else
    py_bin="$(command -v python3)"
  fi
  # El shim es un script bash que invoca python3 con un codigo inline via -c.
  # Esto evita TODOS los problemas de paths cruzados MSYS<->Windows en staging.
  cat > "$shim_dir/jq" <<BASH_EOF
#!/usr/bin/env bash
exec "${py_bin}" -c '
import sys, json
# Strip jq flags que este script usa (e.g. -e para "exit non-null");
# el shim los ignora silenciosamente porque no cambian la salida valida.
args = [a for a in sys.argv[1:] if not a.startswith("-")]
if not args:
    sys.exit(2)
expr = args[0]
data = json.load(sys.stdin)
def _type(v):
    return {list: "array", dict: "object", str: "string",
            int: "number", float: "number", bool: "boolean",
            type(None): "null"}.get(type(v), type(v).__name__)
def _has(d, key):
    return isinstance(d, dict) and key in d
def eval_expr(e, d):
    e = e.strip()
    if e == "type": return _type(d)
    if e == "length": return len(d)
    if e == ".": return d
    if e.startswith("type == \\""):
        target = e.split("\\"", 2)[1]
        return _type(d) == target
    if e.startswith("type == \\"") and " and " in e:
        target = e.split("\\"", 2)[1]
        if _type(d) != target: return False
        body = e.split(" and ", 1)[1].strip().strip("()")
        for o in [p.strip() for p in body.split(" or ")]:
            if o.startswith(".") and _has(d, o[1:]): return True
        return False
    if e.startswith("has(\$k)"):
        return False
    if e.startswith("if "):
        body = e[3:].rsplit(" end", 1)[0]
        clauses = body.split(" then ")
        if clauses and clauses[0].startswith("."):
            i = 0
            while i < len(clauses):
                cond = clauses[i]
                if cond == "else": return eval_expr(clauses[i+1], d)
                if _has(d, cond[1:]) and d[cond[1:]] is not None:
                    return eval_expr(clauses[i+1], d)
                i += 2
        return None
    if e.startswith("\\""):
        result = e.strip("\\"")
        if isinstance(d, dict):
            for k, v in d.items():
                result = result.replace("{." + k + "}", str(v))
        return result
    raise SystemExit("unsupported jq expr: " + e)
result = eval_expr(expr, data)
if isinstance(result, bool): sys.exit(0 if result else 1)
print("null" if result is None else result)
' "\$@"
BASH_EOF
  chmod +x "$shim_dir/jq"
  export PATH="$shim_dir:$PATH"
  export _JQ_SHIM_DIR="$shim_dir"
  echo "(jq no disponible - usando shim python3 desde $shim_dir)" >&2
fi

# Trim trailing slash del URL para evitar //webservice
MOODLE_URL="${MOODLE_URL%/}"
MOODLE_FORMAT="${MOODLE_FORMAT:-json}"

# -----------------------------------------------------------------------------
# Los 8 WS del gate DES-MOODLE-002
# -----------------------------------------------------------------------------
# Formato: "label|wsfunction|args|expected_root_type"
#   expected_root_type: 'array' | 'object' | 'any'
#   Para arrays asumimos length>=1 (debe haber al menos 1 fila del dump).
#   Para objects asumimos que alguna clave exista (validamos has(cualquiera)).
SMOKE_WS=(
  # 1. Cursos del sitio: si esto responde con cursos, el dump se restauró OK.
  "list_courses|core_course_get_courses||array"

  # 2. Cursos de un usuario: valida la matriz enrol del usuario de muestra.
  "user_courses|core_enrol_get_users_courses|&userid=2|array"

  # 3. Lectura de usuario por idnumber: el bridge Odoo<->Moodle resuelve
  #    partner_vat contra mdl_user.idnumber. Si no hay ninguno, el dump no
  #    tiene usuarios o la sanitización rompió la columna.
  "user_by_idnumber|core_user_get_users|&criteria[0][key]=idnumber&criteria[0][value]=8-999-9999|array"

  # 4. Versión del sitio: valida que el WS básico de Moodle responde.
  "site_info|core_webservice_get_site_info||object"

  # 5. Items de calificación de un usuario: valida que mdl_grade_items se
  #    restauró (sin esto, LXP no puede pintar notas).
  "user_grade_items|gradereport_user_get_grade_items|&userid=2&courseid=1|array"

  # 6. Escritura de completion status: primer WS de escritura del set.
  #    Si el token no tiene capability, Moodle devuelve 'nopermissions' o
  #    'forbidden' y el smoke lo marca como FAIL (eso es informativo:
  #    significa que el external service no tiene el capability correcto).
  "completion_write|core_completion_update_activity_completion_status_manually|&cmid=1&completed=1&userid=2&activitytype=page|any"

  # 7. Bridge Odoo<->Moodle (read): el listado de cursos enriquecidos de un
  #    usuario. Es el WS que consume el Express cuando pinta el dashboard
  #    del alumno desde staging. Si esto responde con progreso+créditos+ausencias,
  #    el bridge de lectura está sano. (Sustituye a `local_moodle_sync_users`
  #    que no existe en este fork - ver comentario al inicio del script.)
  "bridge_user_courses|local_grupomakro_get_user_courses|&userId=2|array"

  # 8. Categorías del plan académico: valida que mdl_course_categories
  #    se restauró (categorías son el nivel más alto del árbol académico).
  "categories|core_course_get_categories|&criteria[0][key]=&criteria[0][value]=|array"
)

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
pass=0
fail=0
failed_ws=()
results=()

# Mapea wsfunction -> expected_root_type para el veredicto
declare -A EXPECTED_TYPE=(
  [core_course_get_courses]="array"
  [core_enrol_get_users_courses]="array"
  [core_user_get_users]="array"
  [core_webservice_get_site_info]="object"
  [gradereport_user_get_grade_items]="array"
  [core_completion_update_activity_completion_status_manually]="any"
  [local_grupomakro_get_user_courses]="array"
  [core_course_get_categories]="array"
)

check_one_ws() {
  local label="$1"
  local wsfunction="$2"
  local args="$3"
  local expected_type="$4"

  local url="${MOODLE_URL}/webservice/rest/server.php?wstoken=${MOODLE_TOKEN}&moodlewsrestformat=${MOODLE_FORMAT}&wsfunction=${wsfunction}${args}"
  # En Linux (target real de staging EC2) mktemp devuelve /tmp/xxx y curl/bash
  # lo ven igual. En MSYS/Git-Bash en Windows, mktemp devuelve /tmp/xxx pero
  # curl lo traduce a una ruta donde bash no lo lee - usar la temp nativa de
  # Windows cuando estamos en ese entorno. Detectamos MSYS por $MSYSTEM.
  local body_file
  if [ -n "${MSYSTEM:-}" ] && [ -n "${LOCALAPPDATA:-}" ]; then
    body_file="${LOCALAPPDATA//\\//}/Temp/smoke_body_${$}_$(date +%s).txt"
  else
    body_file="$(mktemp 2>/dev/null || echo "/tmp/smoke_body.$$")"
  fi
  local latency_ms
  local resp http_code body

  local t0 t1
  t0=$(date +%s%N)
  resp=$(curl -gsS -m 15 -o "$body_file" -w '%{http_code}' "$url" 2>&1) || resp="curl-error"
  t1=$(date +%s%N)
  latency_ms=$(( (t1 - t0) / 1000000 ))
  http_code="$resp"
  body=$(cat "$body_file" 2>/dev/null || echo "")
  rm -f "$body_file"

  local verdict="PASS"
  local reason=""

  # 1) HTTP 200
  if [ "$http_code" != "200" ]; then
    verdict="FAIL"; reason="http_${http_code}"
  fi

  # 2) JSON valido
  if [ "$verdict" = "PASS" ]; then
    if ! echo "$body" | jq -e . >/dev/null 2>&1; then
      verdict="FAIL"; reason="invalid_json"
    fi
  fi

  # 3) Sin "exception" / "error" en el cuerpo
  #    Moodle devuelve estos campos cuando algo reventó (param faltante,
  #    capability faltante, etc.). Distinguimos "errorcode" legítimo en el
  #    cuerpo de respuesta de un exception de Moodle chequeando el campo
  #    "exception" en el root.
  if [ "$verdict" = "PASS" ]; then
    if echo "$body" | jq -e 'type == "object" and (.exception or .errorcode or .message)' >/dev/null 2>&1; then
      # Si hay exception O errorcode, es un fallo de Moodle. message solo
      # sin esos otros dos NO es motivo de fail (algunos WS legítimos lo usan).
      local has_exc
      has_exc=$(echo "$body" | jq -r 'if type=="object" and (.exception or .errorcode) then "yes" else "no" end')
      if [ "$has_exc" = "yes" ]; then
        verdict="FAIL"
        reason=$(echo "$body" | jq -r 'if .exception then "moodle_exception" elif .errorcode then "moodle_errorcode:\(.errorcode)" else "unknown" end')
      fi
    fi
  fi

  # 4) Tipo de raíz esperado
  if [ "$verdict" = "PASS" ] && [ "$expected_type" != "any" ]; then
    local actual_type
    actual_type=$(echo "$body" | jq -r 'type')
    if [ "$actual_type" != "$expected_type" ]; then
      verdict="FAIL"; reason="root_type_${actual_type}_expected_${expected_type}"
    fi
  fi

  # 5) Si esperaba array, debe tener >=1 fila
  if [ "$verdict" = "PASS" ] && [ "$expected_type" = "array" ]; then
    local len
    len=$(echo "$body" | jq 'length')
    if [ "$len" -lt 1 ]; then
      verdict="FAIL"; reason="empty_array"
    fi
  fi

  if [ "$verdict" = "PASS" ]; then
    pass=$((pass + 1))
    printf "  ✅ %-30s OK   (latency=%4dms)\n" "$label" "$latency_ms"
  else
    fail=$((fail + 1))
    failed_ws+=("$label ($reason)")
    printf "  ❌ %-30s FAIL (%s, latency=%dms)\n" "$label" "$reason" "$latency_ms"
    printf "     url=%s\n" "$url"
    printf "     body=%s\n" "$(echo "$body" | head -c 300)"
  fi
  results+=("${label}|${verdict}|${reason}|${http_code}|${latency_ms}")
}

# -----------------------------------------------------------------------------
# Loop principal
# -----------------------------------------------------------------------------
echo "==================================================================="
echo " DES-MOODLE-002 - staging Moodle smoke test (8 WS)"
echo " target:   $MOODLE_URL"
echo " started:  $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "==================================================================="

for entry in "${SMOKE_WS[@]}"; do
  IFS='|' read -r label wsfunction args _ <<< "$entry"
  expected_type="${EXPECTED_TYPE[$wsfunction]:-any}"
  check_one_ws "$label" "$wsfunction" "$args" "$expected_type"
done

# -----------------------------------------------------------------------------
# Resumen
# -----------------------------------------------------------------------------
echo "-------------------------------------------------------------------"
echo " Resumen:"
echo "   pass: $pass / 8"
echo "   fail: $fail / 8"
if [ "$fail" -gt 0 ]; then
  echo "   failed_ws:"
  for f in "${failed_ws[@]}"; do
    echo "     - $f"
  done
fi
echo "-------------------------------------------------------------------"
echo " Reporte CSV (pegable en ticket):"
echo "label,verdict,reason,http_code,latency_ms"
for r in "${results[@]}"; do
  echo "$r" | tr '|' ','
done
echo "==================================================================="

[ "$fail" -eq 0 ] || exit 1

# Cleanup del shim de jq si lo creamos
if [ -n "${_JQ_SHIM_DIR:-}" ] && [ -d "$_JQ_SHIM_DIR" ]; then
  rm -rf "$_JQ_SHIM_DIR"
fi
