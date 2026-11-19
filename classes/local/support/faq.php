<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Mesa de ayuda: "Problemas comunes" (respuestas guiadas antes de abrir una
 * solicitud). Pedido de Ramiro del 18-nov-2026.
 *
 * gmk_tkt_faq: por area (y opcionalmente por tema) una lista de problemas con
 * su respuesta (texto con formato simple: listas numeradas, vinetas y
 * **negrita**), botones con enlace (web, App Store, Play Store), orden,
 * activo y publico (estudiantes, docentes o ambos). Cuenta vistas, "se
 * soluciono" y "sigo con el problema" para el tablero de la fase 3.
 *
 * La respuesta se guarda como texto y se convierte a HTML aqui (render), con
 * todo el texto escapado: los clientes la muestran con v-html sin riesgo.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use invalid_parameter_exception;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class faq {

    const AUDIENCES = ['all', 'student', 'teacher'];
    /** 'portal' (20261001130): ruta interna del portal ("/solicitudes-cartas"), abre en la misma pestaña. */
    const LINK_KINDS = ['web', 'appstore', 'playstore', 'portal'];
    const OUTCOMES = ['view' => 'views', 'solved' => 'solved', 'notsolved' => 'notsolved'];
    const MAX_LINKS = 6;

    /**
     * Active items of $areaid visible to $audience ('student' | 'teacher').
     */
    public static function for_area(int $areaid, string $audience): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_tkt_faq', 'areaid = :a AND active = 1 AND audience IN (:all, :aud)',
            ['a' => $areaid, 'all' => 'all', 'aud' => $audience === 'teacher' ? 'teacher' : 'student'],
            'sortorder ASC, id ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::export($r);
        }
        return $out;
    }

    public static function export(stdClass $r): array {
        return [
            'id' => (int)$r->id,
            'categoryid' => (int)$r->categoryid,
            'problem' => (string)$r->problem,
            'answerhtml' => self::render((string)$r->answer),
            'links' => self::links((string)$r->links),
        ];
    }

    /**
     * @return array [{label, url, kind}]
     */
    public static function links(?string $json): array {
        $out = [];
        foreach ((array)json_decode((string)$json, true) as $l) {
            if (!is_array($l) || empty($l['url']) || empty($l['label'])) {
                continue;
            }
            $out[] = ['label' => (string)$l['label'], 'url' => (string)$l['url'],
                'kind' => in_array($l['kind'] ?? 'web', self::LINK_KINDS, true) ? $l['kind'] : 'web'];
        }
        return $out;
    }

    /**
     * Light markup to safe HTML: every character is escaped first, then
     * **bold**, lines "1. ..." as a numbered list, "- ..." as bullets, the
     * rest as paragraphs (one line = one line break).
     */
    public static function render(string $text): string {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $html = '';
        $list = '';
        $para = [];
        $flushpara = function() use (&$para, &$html) {
            if ($para) {
                $html .= '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        $closelist = function() use (&$list, &$html) {
            if ($list !== '') {
                $html .= '</' . $list . '>';
                $list = '';
            }
        };
        foreach ($lines as $line) {
            $line = trim($line);
            $inline = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', s($line));
            if ($line === '') {
                $flushpara();
                $closelist();
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.*)$/u', $line, $m)) {
                $flushpara();
                if ($list !== 'ol') {
                    $closelist();
                    $html .= '<ol>';
                    $list = 'ol';
                }
                $html .= '<li>' . preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', s($m[1])) . '</li>';
                continue;
            }
            if (preg_match('/^[-•]\s+(.*)$/u', $line, $m)) {
                $flushpara();
                if ($list !== 'ul') {
                    $closelist();
                    $html .= '<ul>';
                    $list = 'ul';
                }
                $html .= '<li>' . preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', s($m[1])) . '</li>';
                continue;
            }
            $closelist();
            $para[] = $inline;
        }
        $flushpara();
        $closelist();
        return $html;
    }

    /**
     * Counts a view / solved / not solved for an item the user can see.
     */
    public static function record(int $faqid, string $outcome, int $userid, string $audience): void {
        global $DB;
        if (!isset(self::OUTCOMES[$outcome])) {
            throw new invalid_parameter_exception('outcome');
        }
        $item = $DB->get_record('gmk_tkt_faq', ['id' => $faqid, 'active' => 1]);
        if (!$item || ($item->audience !== 'all' && $item->audience !== ($audience === 'teacher' ? 'teacher' : 'student'))) {
            throw new moodle_exception('tkt_err_faq', 'local_grupomakro_core');
        }
        if ($audience === 'teacher' && !manager::is_teacher($userid)) {
            throw new moodle_exception('tkt_err_faq', 'local_grupomakro_core');
        }
        $col = self::OUTCOMES[$outcome];
        $DB->execute("UPDATE {gmk_tkt_faq} SET $col = $col + 1 WHERE id = ?", [$faqid]);
        if ($outcome !== 'view') {
            manager::log_event(0, 'faq_' . $outcome, '', (string)$faqid, $userid, ['audience' => $audience]);
        }
    }

    /**
     * Item usable in a new ticket of $categoryid's area, or null.
     */
    public static function for_ticket(int $faqid, int $areaid): ?stdClass {
        global $DB;
        if ($faqid <= 0) {
            return null;
        }
        $item = $DB->get_record('gmk_tkt_faq', ['id' => $faqid]);
        return ($item && (int)$item->areaid === $areaid) ? $item : null;
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    public static function export_all(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('gmk_tkt_faq', null, 'areaid, sortorder, id') as $r) {
            $out[] = [
                'id' => (int)$r->id, 'areaid' => (int)$r->areaid, 'categoryid' => (int)$r->categoryid,
                'problem' => (string)$r->problem, 'answer' => (string)$r->answer, 'answerhtml' => self::render((string)$r->answer),
                'links' => self::links((string)$r->links), 'audience' => (string)$r->audience,
                'sortorder' => (int)$r->sortorder, 'active' => (int)$r->active,
                'views' => (int)$r->views, 'solved' => (int)$r->solved, 'notsolved' => (int)$r->notsolved,
            ];
        }
        return $out;
    }

    /**
     * @return array [old, new] for the audit event
     */
    public static function save(array $d): array {
        global $DB;
        $areaid = (int)($d['areaid'] ?? 0);
        $area = $DB->get_record('gmk_tkt_area', ['id' => $areaid]);
        if (!$area) {
            throw new invalid_parameter_exception('Área inválida');
        }
        $categoryid = (int)($d['categoryid'] ?? 0);
        if ($categoryid > 0 && !$DB->record_exists('gmk_tkt_category', ['id' => $categoryid, 'areaid' => $areaid])) {
            throw new invalid_parameter_exception('El tema no pertenece al área');
        }
        $problem = \core_text::substr(trim(clean_param((string)($d['problem'] ?? ''), PARAM_TEXT)), 0, 255);
        $answer = trim(str_replace("\r\n", "\n", clean_param((string)($d['answer'] ?? ''), PARAM_TEXT)));
        if ($problem === '' || $answer === '') {
            throw new invalid_parameter_exception('El problema y la respuesta son obligatorios');
        }
        $audience = (string)($d['audience'] ?? 'all');
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new invalid_parameter_exception('Público inválido');
        }
        $links = [];
        foreach ((array)($d['links'] ?? []) as $l) {
            $label = \core_text::substr(trim(clean_param((string)($l['label'] ?? ''), PARAM_TEXT)), 0, 80);
            $url = trim((string)($l['url'] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            $kind = in_array($l['kind'] ?? 'web', self::LINK_KINDS, true) ? $l['kind'] : 'web';
            if ($kind === 'portal') {
                if ($label === '' || !preg_match('#^/[a-z0-9\-_/]*(\?[a-z0-9=&_\-]*)?$#i', $url)) {
                    throw new invalid_parameter_exception('Un botón del portal necesita texto y una ruta como /solicitudes-cartas');
                }
            } else if ($label === '' || !preg_match('#^https://#i', $url) || clean_param($url, PARAM_URL) !== $url) {
                throw new invalid_parameter_exception('Cada botón necesita texto y un enlace https válido');
            }
            $links[] = ['label' => $label, 'url' => \core_text::substr($url, 0, 500), 'kind' => $kind];
        }
        if (count($links) > self::MAX_LINKS) {
            throw new invalid_parameter_exception('Máximo ' . self::MAX_LINKS . ' botones por problema');
        }
        $now = time();
        $row = (object)[
            'areaid' => $areaid, 'categoryid' => $categoryid, 'problem' => $problem, 'answer' => $answer,
            'links' => json_encode($links), 'audience' => $audience, 'sortorder' => (int)($d['sortorder'] ?? 0),
            'active' => !empty($d['active']) ? 1 : 0, 'timemodified' => $now,
        ];
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) {
            $old = $DB->get_record('gmk_tkt_faq', ['id' => $id], '*', MUST_EXIST);
            $row->id = $id;
            $DB->update_record('gmk_tkt_faq', $row);
            return [$old, $row];
        }
        $row->views = 0;
        $row->solved = 0;
        $row->notsolved = 0;
        $row->timecreated = $now;
        $row->id = $DB->insert_record('gmk_tkt_faq', $row);
        return [null, $row];
    }

    public static function delete(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_faq', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        $DB->delete_records('gmk_tkt_faq', ['id' => $old->id]);
        return [$old, null];
    }

    /**
     * Initial items for Soporte Tecnologico (once, when the table is empty).
     */
    public static function seed(): int {
        global $DB;
        $area = $DB->get_record('gmk_tkt_area', ['code' => 'ti']);
        if (!$area || $DB->record_exists('gmk_tkt_faq', [])) {
            return 0;
        }
        $now = time();
        $items = [
            [
                'No me puedo conectar a mi clase virtual: no abre la videoconferencia cuando le doy «Unirme»',
                "Esto suele resolverse usando el navegador **Google Chrome**.\n"
                . "1. Descarga e instala Chrome en tu dispositivo con el botón que corresponda.\n"
                . "2. Abre Chrome y entra de nuevo a la plataforma (estudiantes: students.isi.edu.pa; docentes: lms.isi.edu.pa).\n"
                . "3. Intenta unirte otra vez a tu clase.",
                [
                    ['label' => 'Chrome para iPhone/iPad (App Store)', 'url' => 'https://apps.apple.com/app/google-chrome/id535886823',
                        'kind' => 'appstore'],
                    ['label' => 'Chrome para Android (Play Store)',
                        'url' => 'https://play.google.com/store/apps/details?id=com.android.chrome', 'kind' => 'playstore'],
                    ['label' => 'Chrome para Windows / computadora', 'url' => 'https://www.google.com/chrome/', 'kind' => 'web'],
                ],
                'all', 10,
            ],
            [
                'No sé cómo buscar mi clase virtual',
                "**Opción 1 – Desde tu horario:**\n"
                . "1. Inicia sesión en students.isi.edu.pa.\n"
                . "2. Toca tu foto o tus iniciales (arriba a la derecha) y elige «Mi Horario».\n"
                . "3. Toca la clase del día y luego el botón «Actividad».\n"
                . "4. En la asignatura, busca la sección «Sesiones Virtuales» y toca la sesión de hoy.\n"
                . "5. Pulsa «Unirse a Videoconferencia».\n"
                . "\n"
                . "**Opción 2 – Desde Inicio:**\n"
                . "1. En Inicio, en «Cursos Iniciados» (o en «Planes de Aprendizaje»), abre la asignatura.\n"
                . "2. Baja a «Sesiones Virtuales», toca la sesión de hoy y pulsa «Unirse a Videoconferencia».\n"
                . "\n"
                . "El botón aparece cuando la sesión está en curso. Si todavía no empieza verás «¡Casi llegamos!», y si el docente aún no la abre verás «Esperando al anfitrión».",
                [],
                'student', 20,
            ],
        ];
        $n = 0;
        foreach ($items as [$problem, $answer, $links, $audience, $sort]) {
            $DB->insert_record('gmk_tkt_faq', (object)[
                'areaid' => (int)$area->id, 'categoryid' => 0, 'problem' => $problem, 'answer' => $answer,
                'links' => json_encode($links), 'audience' => $audience, 'sortorder' => $sort, 'active' => 1,
                'views' => 0, 'solved' => 0, 'notsolved' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            $n++;
        }
        return $n;
    }
}
