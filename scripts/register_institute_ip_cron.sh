#!/usr/bin/env bash
# scripts/register_institute_ip_cron.sh
#
# Script on-site para registrar la IP publica actual del ISI como IP del
# instituto en el geofencing del QR de asistencia. Pensado para correr en un
# cron dentro de la red del ISI (Raspberry, servidor local, workstation
# siempre encendida).
#
# Como funciona:
#   1) Detecta la IP publica via api.ipify.org (o el endpoint que prefieras).
#   2) POST a Moodle con la IP + el token compartido.
#   3) Moodle persiste la IP en gmk_institute_ip_registry con TTL
#      (attendance_qr_dynamic_ip_ttl_hours).
#
# Cron sugerido (corra una vez al dia, p.ej. 6:30 AM hora Panama):
#   30 6 * * * /opt/isi/scripts/register_institute_ip_cron.sh >> /var/log/isi_ip.log 2>&1
#
# Variables de entorno requeridas:
#   GMK_MOODLE_URL   - ej: https://lms.isi.edu.pa
#   GMK_INSTITUTE_TOKEN - el valor del setting attendance_qr_institute_token
#
# Variables opcionales:
#   GMK_IPV4_PROVIDER - URL que devuelve la IP publica (default: https://api.ipify.org)
#   GMK_IP_LABEL      - etiqueta a guardar (default: on-site-cron)

set -euo pipefail

: "${GMK_MOODLE_URL:?Debes exportar GMK_MOODLE_URL}"
: "${GMK_INSTITUTE_TOKEN:?Debes exportar GMK_INSTITUTE_TOKEN}"

GMK_IPV4_PROVIDER="${GMK_IPV4_PROVIDER:-https://api.ipify.org}"
GMK_IP_LABEL="${GMK_IP_LABEL:-on-site-cron}"
TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

echo "[$TIMESTAMP] Detectando IP publica via $GMK_IPV4_PROVIDER ..."
PUBLIC_IP="$(curl -fsS --max-time 10 "$GMK_IPV4_PROVIDER" | tr -d '[:space:]')"
if [[ -z "$PUBLIC_IP" ]] || ! [[ "$PUBLIC_IP" =~ ^[0-9a-fA-F:.]+$ ]]; then
    echo "[$TIMESTAMP] ERROR: no se pudo obtener una IP valida (respuesta: '$PUBLIC_IP')"
    exit 1
fi
echo "[$TIMESTAMP] IP detectada: $PUBLIC_IP"

ENDPOINT="${GMK_MOODLE_URL%/}/local/grupomakro_core/pages/register_institute_ip.php"
echo "[$TIMESTAMP] Registrando IP en $ENDPOINT ..."
RESPONSE="$(curl -fsS --max-time 10 -X POST "$ENDPOINT" \
    -H "X-Institute-Token: $GMK_INSTITUTE_TOKEN" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode "ip=$PUBLIC_IP" \
    --data-urlencode "label=$GMK_IP_LABEL")"

echo "[$TIMESTAMP] Respuesta Moodle:"
echo "$RESPONSE"
echo "[$TIMESTAMP] OK"