#!/usr/bin/env bash
# =============================================================================
# DES-MOODLE-002: Staging smoke test - 8 web services end-to-end
# =============================================================================
#
# PURPOSE:
#   Verifica que el staging Moodle responde correctamente a 8 web services
#   criticos del plugin local_grupomakro_core. Es el gate de "staging verde"
#   antes de promover codigo nuevo o de abrir trafico LXP real contra staging.
#
# SCOPE:
#   - Cubre el bridge Odoo -> Moodle -> Express -> LXP en ambos sentidos
#     (lectura y escritura), sin tocar el codigo del fork.
#   - Selecciona 1 WS por ruta critica, evitando tests destructivos
#     (no se matricula, no se anulan movimientos, no se borran sesiones).
#   - Output: VERDICT ✅/❌ por WS + resumen ejecutivo.
#
# USAGE:
#   MOODLE_URL=https://staging-moodle.isi.example.test \
#   MOODLE_TOKEN=<wstoken del service smoke-test creado por el DBA> \
#   ./smoke_test_staging.sh
#
# REQUIREMENTS:
#   - curl, jq, python3
#   - Token de un Moodle external service que tenga acceso a los 8 WS siguientes
#     (preferentemente uno dedicado 'smoke-test' con capabilities de lectura +
#     un subconjunto muy limitado de escritura).
#
# EXIT CODES:
#   0 = todos los WS respondieron OK
#   1 = al menos 1 WS fallo
#
# NOTA DE DISENO:
#   El script NO hace asunciones sobre el payload exacto de cada WS (cambia
#   entre versiones del plugin). Solo verifica:
#     a) HTTP 200
#     b) respuesta JSON valida
#     c) ausencia de "exception" / "error" en el cuerpo
#     d) presencia del campo raiz esperado
#   Asi es estable frente a cambios del fork mientras los WS sigan existiendo.
# =============================================================================

set -u

MOODLE_URL="${MOODLE_URL:?MOODLE_URL es requerido (ej: https://staging-moodle.isi.example.test)}"
MOODLE_TOKEN="${MOODLE_TOKEN:?MOODLE_TOKEN es requerido}"
MOODLE_FORMAT="${MOODLE_FORMAT:-json}"
PARAMS_BASE="wstoken=${MOODLE_TOKEN}&moodlewsrestformat=${MOODLE_FORMAT}"

# 8 web services representativos del bridge. Seleccion 1 por ruta critica.
# Formato: nombre_interno|wsfunction|moodlewsrestformat_param_check
SMOKE_WS=(
    # 1. Identidad: el plugin expone un endpoint de identidad basica. Si esto
    #    responde, el bootstrap del plugin cargo bien (capabilities, db, caches).
    "core_webservice_get_site_info|core_webservice_get_site_info|sitename"

    # 2. Bridge Odoo -> Moodle (lectura usuarios): usado por el Express para
    #    resolver un partner_vat contra un moodle_user_id. Valida que el
    #    plugin responde con la estructura esperada.
    "user_get_users_by_field|core_user_get_users_by_field|users"

    # 3. Bridge Moodle -> Odoo (estado financiero por usuario): consumido por
    #    el webhook Express /api/odoo/cache/invalidate. Lectura pura.
    "local_grupomakro_get_financial_counts|local_grupomakro_get_financial_counts|counts"

    # 4. Listado paginado de clases (alto volumen, prueba paginacion + caches
    #    MUC del plugin). Lectura pura.
    "local_grupomakro_list_classes_paged|local_grupomakro_list_classes_paged|classes"

    # 5. Dashboard academico del estudiante (consulta muy usada por LXP,
    #    cubre varias tablas del plugin en una sola llamada).
    "get_student_info|local_grupomakro_get_student_info|userid"

    # 6. WS del panel director: revalidaciones. Lectura con paginacion.
    "list_revalidations_director|local_grupomakro_list_revalidations_director|revalidations"

    # 7. Failed subjects report (consulta compleja multi-tabla). Lectura pura.
    "get_failed_subjects_report|local_grupomakro_get_failed_subjects_report|report"

    # 8. Wellness: listado publico de eventos (uso LXP Bienestar).
    #    Lectura pura.
    "get_wellness_events|local_grupomakro_get_wellness_events|events"
)

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
pass=0
fail=0
failed_ws=()
results=()

# Campos sensibles a buscar en la respuesta para cada WS (substring match).
declare -A SMOKE_FIELD=(
    [core_webservice_get_site_info]="sitename"
    [core_user_get_users_by_field]="users"
    [local_grupomakro_get_financial_counts]="counts"
    [local_grupomakro_list_classes_paged]="classes"
    [local_grupomakro_get_student_info]="userid"
    [local_grupomakro_list_revalidations_director]="revalidations"
    [local_grupomakro_get_failed_subjects_report]="report"
    [local_grupomakro_get_wellness_events]="events"
)

# Argumentos minimos viables por WS (id del primer admin / student, segun
# disponibilidad). Si el WS requiere parametros mas estrictos, el script
# sigue siendo util porque detecta respuestas de "parametro faltante"
# (que son HTTP 200 con mensaje de validacion, no excepcion).
declare -A SMOKE_ARGS=(
    [core_webservice_get_site_info]=""
    [core_user_get_users_by_field]="&field=username&values[0]=admin"
    [local_grupomakro_get_financial_counts]="&userid=2"
    [local_grupomakro_list_classes_paged]="&page=0&perpage=1"
    [local_grupomakro_get_student_info]="&userid=2"
    [local_grupomakro_list_revalidations_director]="&page=0&perpage=1"
    [local_grupomakro_get_failed_subjects_report]="&periodid=0"
    [local_grupomakro_get_wellness_events]=""
)

check_one_ws() {
    local label="$1"
    local wsfunction="$2"
    local args="$3"
    local expect_field="$4"

    local url="${MOODLE_URL}/webservice/rest/server.php?${PARAMS_BASE}&wsfunction=${wsfunction}${args}"
    local resp http_code body

    resp=$(curl -sS -m 15 -o /tmp/smoke_body.$$ -w '%{http_code}' "$url" 2>&1) || resp="curl-error"
    http_code="$resp"
    body=$(cat /tmp/smoke_body.$$ 2>/dev/null || echo "")
    rm -f /tmp/smoke_body.$$

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

    # 3) Sin "exception" / "error" en el cuerpo (caso tipico de Moodle cuando
    #    el WS revienta por parametro invalido)
    if [ "$verdict" = "PASS" ]; then
        if echo "$body" | jq -e 'type == "object" and (.exception or .error)' >/dev/null 2>&1; then
            verdict="FAIL"; reason="moodle_exception"
        fi
    fi

    # 4) Campo raiz esperado presente
    if [ "$verdict" = "PASS" ] && [ -n "$expect_field" ]; then
        if ! echo "$body" | jq -e --arg k "$expect_field" 'has($k) or (type=="array")' >/dev/null 2>&1; then
            # Si es array, ok. Si es objeto y no tiene la clave, falla.
            verdict="FAIL"; reason="missing_field_${expect_field}"
        fi
    fi

    if [ "$verdict" = "PASS" ]; then
        pass=$((pass + 1))
        printf "  ✅ %-50s OK\n" "$label"
    else
        fail=$((fail + 1))
        failed_ws+=("$label ($reason)")
        printf "  ❌ %-50s FAIL (%s)\n" "$label" "$reason"
        printf "     url=%s\n" "$url"
        printf "     body=%s\n" "$(echo "$body" | head -c 300)"
    fi
    results+=("${label}|${verdict}|${reason}|${http_code}")
}

# -----------------------------------------------------------------------------
# Loop principal
# -----------------------------------------------------------------------------
echo "==================================================================="
echo " DES-MOODLE-002 - staging smoke test"
echo " target: $MOODLE_URL"
echo " started: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "==================================================================="

for entry in "${SMOKE_WS[@]}"; do
    IFS='|' read -r label wsfunction _ <<< "$entry"
    args="${SMOKE_ARGS[$wsfunction]:-}"
    field="${SMOKE_FIELD[$wsfunction]:-}"
    check_one_ws "$label" "$wsfunction" "$args" "$field"
done

echo "-------------------------------------------------------------------"
echo " Resumen:"
echo "   pass: $pass"
echo "   fail: $fail"
if [ "$fail" -gt 0 ]; then
    echo "   failed_ws:"
    for f in "${failed_ws[@]}"; do
        echo "     - $f"
    done
fi
echo "-------------------------------------------------------------------"
echo " Reporte CSV (pegable en ticket):"
echo "label,verdict,reason,http_code"
for r in "${results[@]}"; do
    echo "$r" | tr '|' ','
done
echo "==================================================================="

[ "$fail" -eq 0 ] || exit 1