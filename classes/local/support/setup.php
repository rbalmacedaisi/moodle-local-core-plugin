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
 * Mesa de ayuda: carga inicial de la configuracion (decisiones del 17-nov-2026).
 *
 * Corre una sola vez: deja la marca tkt_seeded en config_plugins y ademas no
 * hace nada si ya hay areas. Despues todo se edita desde support_settings.php.
 * Las personas solo se cargan si el usuario existe y no esta borrado; las que
 * falten quedan en el resultado para revisarlas a mano.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

defined('MOODLE_INTERNAL') || die();

class setup {

    // Personas del §0 / §6.1 del plan.
    const JEAN_REMICE = 2732;
    const LIZBETH_AIZPRUA = 2912;
    const JORGE_OVIEDO = 2737;
    const DULCE_JURADO = 2765;
    const ESTEBAN_MONTOYA = 2756;
    const JOYCE_MUNOZ = 2886;

    const HOURS_LONG = '{"1":[["08:00","21:00"]],"2":[["08:00","21:00"]],"3":[["08:00","21:00"]],'
        . '"4":[["08:00","21:00"]],"5":[["08:00","21:00"]],"6":[["08:00","16:00"]]}';
    const HOURS_OFFICE = '{"1":[["08:00","17:00"]],"2":[["08:00","17:00"]],"3":[["08:00","17:00"]],'
        . '"4":[["08:00","17:00"]],"5":[["08:00","17:00"]]}';

    const TEMPLATES = [
        'created' => ['Al crear',
            'Recibimos tu solicitud {numero} sobre {categoria}. Te atenderá {agente}. Tiempo habitual de respuesta: 20 minutos en horario de atención.'],
        'assigned' => ['Al asignar o reasignar', '{agente} está atendiendo tu solicitud.'],
        'out_of_hours' => ['Fuera de horario',
            'Recibimos tu mensaje fuera del horario de atención ({horario}). Te responderemos al inicio de la siguiente jornada.'],
        'resolved' => ['Al resolver',
            'Marcamos tu solicitud como resuelta. ¿Se resolvió tu caso? Responde Sí o No en esta misma ventana.'],
        'transferred' => ['Al pasar a otra área o tema',
            'Pasamos tu solicitud {numero} a {area} ({categoria}). Te atenderá {agente}.'],
        'reopened' => ['Al reabrir', 'Reabrimos tu solicitud {numero}. {agente} la retomará con prioridad.'],
        'closed' => ['Al cerrar',
            'Tu solicitud {numero} fue cerrada. Si necesitas algo más, puedes abrir una nueva solicitud.'],
        // Fase 2 (los usa la tarea de escalamiento); se cargan ya para poder editarlos.
        'sla_warning' => ['20 min sin respuesta (fase 2)',
            'Tu solicitud sigue en cola; te responderemos en breve. Gracias por tu paciencia.'],
        'reminder' => ['Recordatorio al estudiante sin respuesta (horas hábiles)',
            '¿Sigues necesitando ayuda? Si no recibimos respuesta, cerraremos tu solicitud en {horas_cierre} horas hábiles.'],
    ];

    /**
     * @return array ['seeded' => bool, 'missingusers' => int[]]
     */
    public static function seed_defaults(): array {
        global $DB;
        $result = ['seeded' => false, 'missingusers' => []];
        if (get_config('local_grupomakro_core', 'tkt_seeded') || $DB->record_exists('gmk_tkt_area', [])) {
            return $result;
        }
        $now = time();

        $areas = [
            'academico' => ['Académico', 'Horarios, asignaturas y matrícula, notas, gestión con docentes y asistencia.',
                'mdi-school-outline', self::HOURS_LONG, 0, 10],
            'ti' => ['Soporte Tecnológico', 'Acceso y contraseña, plataforma y aula virtual, app y correo.',
                'mdi-laptop', self::HOURS_LONG, 1, 20],
            'bienestar' => ['Bienestar', 'Bienestar institucional: eventos, carné y apoyo.',
                'mdi-hand-heart-outline', self::HOURS_LONG, 0, 30],
            'psicologia' => ['Psicología', 'Quejas y temas personales. Es confidencial: solo lo ve Psicología.',
                'mdi-account-heart-outline', self::HOURS_OFFICE, 0, 40],
            'finanzas' => ['Finanzas', 'Pagos, saldos y facturas: te atendemos por WhatsApp.',
                'mdi-cash-multiple', '', 0, 50],
        ];
        $areaids = [];
        foreach ($areas as $code => [$name, $desc, $icon, $hours, $teacher, $sort]) {
            $row = (object)[
                'code' => $code, 'name' => $name, 'description' => $desc, 'icon' => $icon, 'hours' => $hours,
                'external_url' => '', 'external_label' => '', 'external_message' => null,
                'teacher_visible' => $teacher, 'active' => 1, 'sortorder' => $sort,
                'timecreated' => $now, 'timemodified' => $now,
            ];
            if ($code === 'finanzas') {
                $row->external_url = 'https://wa.me/50768920543';
                $row->external_label = 'Escribir a Finanzas por WhatsApp';
                $row->external_message = 'Hola, soy {estudiante} (cédula {cedula}). Necesito ayuda con un tema de Finanzas.';
            }
            $areaids[$code] = $DB->insert_record('gmk_tkt_area', $row);
        }

        // area code => [topic name, description, confidential, agents[]].
        $academic = [self::JEAN_REMICE, self::LIZBETH_AIZPRUA];
        $topics = [
            'academico' => [
                ['Horarios', 'Cambios o dudas de horario.', 0, $academic],
                ['Asignaturas y matrícula', 'Inscripción, retiro o cambio de asignaturas.', 0, $academic],
                ['Notas y calificaciones', 'Dudas o reclamos sobre notas.', 0, $academic],
                ['Gestión con docentes', 'Situaciones con un docente o una clase.', 0, $academic],
                ['Asistencia a clases', 'Faltas, código QR y justificaciones.', 0, [self::JORGE_OVIEDO]],
                ['Otro', 'Cualquier otro tema académico.', 0, $academic],
            ],
            'ti' => [
                ['Acceso y contraseña', 'No puedo entrar o olvidé mi contraseña.', 0, [self::ESTEBAN_MONTOYA]],
                ['Plataforma y aula virtual', 'Errores en el portal, Moodle o las clases virtuales.', 0, [self::ESTEBAN_MONTOYA]],
                ['App móvil', 'Problemas con la aplicación.', 0, [self::ESTEBAN_MONTOYA]],
                ['Correo institucional', 'Acceso o problemas con el correo.', 0, [self::ESTEBAN_MONTOYA]],
                ['Otro', 'Cualquier otro tema de tecnología.', 0, [self::ESTEBAN_MONTOYA]],
            ],
            'bienestar' => [
                ['Bienestar institucional', 'Eventos, carné, apoyo y actividades.', 0, [self::JORGE_OVIEDO]],
            ],
            'psicologia' => [
                ['Quejas y temas personales', 'Lo que escribas aquí solo lo ve Psicología.', 1, [self::DULCE_JURADO]],
            ],
        ];
        $missing = [];
        $exists = function(int $userid) use ($DB, &$missing): bool {
            $ok = $DB->record_exists('user', ['id' => $userid, 'deleted' => 0]);
            if (!$ok && !in_array($userid, $missing, true)) {
                $missing[] = $userid;
            }
            return $ok;
        };
        foreach ($topics as $code => $list) {
            $sort = 0;
            foreach ($list as [$name, $desc, $confidential, $agents]) {
                $sort += 10;
                $catid = $DB->insert_record('gmk_tkt_category', (object)[
                    'areaid' => $areaids[$code], 'name' => $name, 'description' => $desc,
                    'confidential' => $confidential, 'requires_attachment' => 0, 'autoassign' => 1,
                    'priority_default' => 1, 'sla_first_response_min' => 20, 'sla_resolution_min' => 0,
                    'active' => 1, 'sortorder' => $sort, 'timecreated' => $now, 'timemodified' => $now,
                ]);
                foreach ($agents as $uid) {
                    if ($exists($uid)) {
                        $DB->insert_record('gmk_tkt_agent', (object)['categoryid' => $catid, 'userid' => $uid,
                            'can_auto_assign' => 1, 'max_open' => 0, 'timecreated' => $now, 'timemodified' => $now]);
                    }
                }
            }
        }

        // Respaldos: Asistencia/Bienestar (Jorge) -> Jean y Lizbeth; Psicologia (Dulce) -> Joyce.
        // TI (Esteban) sin respaldo: queda en la cola visible para supervisores.
        $backups = [
            [self::JORGE_OVIEDO, self::JEAN_REMICE, 10],
            [self::JORGE_OVIEDO, self::LIZBETH_AIZPRUA, 20],
            [self::DULCE_JURADO, self::JOYCE_MUNOZ, 10],
        ];
        foreach ($backups as [$uid, $backup, $sort]) {
            if ($exists($uid) && $exists($backup)) {
                $DB->insert_record('gmk_tkt_backup', (object)['userid' => $uid, 'backupuserid' => $backup,
                    'categoryid' => 0, 'sortorder' => $sort, 'timecreated' => $now]);
            }
        }

        // Gerencia: Joyce Munoz, tambien con el rol gmk_gerencia.
        if ($exists(self::JOYCE_MUNOZ)) {
            $DB->insert_record('gmk_tkt_person', (object)['kind' => 'gerencia', 'userid' => self::JOYCE_MUNOZ,
                'timecreated' => $now]);
            $role = $DB->get_record('role', ['shortname' => 'gmk_gerencia']);
            if ($role) {
                role_assign($role->id, self::JOYCE_MUNOZ, \context_system::instance()->id);
            }
        }

        foreach (self::TEMPLATES as $code => [$name, $body]) {
            $DB->insert_record('gmk_tkt_template', (object)['code' => $code, 'name' => $name, 'body' => $body,
                'enabled' => 1, 'timemodified' => $now]);
        }

        foreach (config::SETTINGS as $name => [$default]) {
            if (get_config('local_grupomakro_core', $name) === false) {
                set_config($name, $default, 'local_grupomakro_core');
            }
        }

        set_config('tkt_seeded', $now, 'local_grupomakro_core');
        manager::log_event(0, 'config_seed', '', 'initial', 0, ['missingusers' => $missing]);
        $result['seeded'] = true;
        $result['missingusers'] = $missing;
        return $result;
    }
}
