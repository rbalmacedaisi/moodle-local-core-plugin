<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Aviso a repitentes: los estudiantes con una asignatura reprobada NO se cargan automaticamente. Cuando hay una clase
 * aprobada (horario publicado y aprobado) de esa asignatura en el periodo, se les avisa que pueden cursarla, que deben
 * acercarse al area academica a solicitar el cargue y que deben pagarla.
 *
 * - Candidatos: repitentes del motor de demanda con una clase aprobada y abierta de esa asignatura en el periodo,
 *   que no esten ya en esa clase (grupo, cola o progreso en curso).
 * - Un aviso por estudiante, asignatura y periodo: queda registrado en gmk_academic_alerts (tipo oferta_repite).
 * - Canal: notificacion de la plataforma (proveedor repeat_offer).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class repeat_offers {

    const ALERT_TYPE = 'oferta_repite';

    /** Interruptor general del aviso automatico (apagado por defecto). */
    public static function enabled() {
        return (bool)get_config('local_grupomakro_core', 'planning_notify_repeaters');
    }

    /**
     * A quien corresponde avisar en un periodo.
     *
     * @return array lista de ['userid','planid','name','email','courseid','course','classes' => [['id','name','shift']],
     *               'same_shift' => bool, 'notified' => int timestamp|0]
     */
    public static function candidates($periodid) {
        global $DB;
        $res = (new demand_engine($periodid))->compute();
        if (!empty($res['error'])) {
            return [];
        }
        // Clases aprobadas y abiertas del periodo, por curso Moodle.
        $classes = [];
        foreach ($DB->get_records_select('gmk_class', 'periodid = ? AND approved = 1 AND closed = 0', [$periodid],
                'id', 'id, name, corecourseid, shift, groupid') as $c) {
            $classes[(int)$c->corecourseid][] = $c;
        }
        $sent = self::sent_index($periodid);
        $out = [];
        foreach ($res['students'] as $s) {
            foreach ($s['subjects'] as $sub) {
                if ($sub['category'] !== demand_engine::CAT_REPITE || empty($classes[$sub['courseid']])) {
                    continue;
                }
                $cls = $classes[$sub['courseid']];
                if (self::already_in($s['userid'], $sub['courseid'], $cls)) {
                    continue;
                }
                $same = array_filter($cls, function ($c) use ($s) {
                    return $s['jornada'] !== '' && strcasecmp((string)$c->shift, $s['jornada']) === 0;
                });
                $out[] = [
                    'userid' => (int)$s['userid'], 'planid' => (int)$s['planid'], 'name' => $s['name'], 'email' => $s['email'],
                    'phone' => $s['phone'], 'documento' => $s['documento'], 'jornada' => $s['jornada'],
                    'courseid' => (int)$sub['courseid'], 'course' => $sub['name'],
                    'classes' => array_values(array_map(function ($c) {
                        return ['id' => (int)$c->id, 'name' => $c->name, 'shift' => (string)$c->shift];
                    }, $cls)),
                    'same_shift' => (bool)$same,
                    'notified' => (int)($sent[$s['userid'] . '|' . $sub['courseid']] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** Ya esta en una clase de ese curso (grupo, cola) o la esta cursando. */
    protected static function already_in($userid, $courseid, array $classes) {
        global $DB;
        if ($DB->record_exists('gmk_course_progre', ['userid' => $userid, 'courseid' => $courseid, 'status' => 2])) {
            return true;
        }
        foreach ($classes as $c) {
            if (!empty($c->groupid) && $DB->record_exists('groups_members', ['groupid' => $c->groupid, 'userid' => $userid])) {
                return true;
            }
            if ($DB->record_exists('gmk_class_queue', ['classid' => $c->id, 'userid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /** 'userid|courseid' => fecha del aviso ya enviado en el periodo. */
    protected static function sent_index($periodid) {
        global $DB;
        $out = [];
        foreach ($DB->get_records('gmk_academic_alerts', ['type' => self::ALERT_TYPE], '', 'id, userid, detail_json, timecreated') as $a) {
            $d = json_decode((string)$a->detail_json, true);
            if ((int)($d['periodid'] ?? 0) === (int)$periodid) {
                $out[$a->userid . '|' . (int)($d['courseid'] ?? 0)] = (int)$a->timecreated;
            }
        }
        return $out;
    }

    /**
     * Envia los avisos pendientes de un periodo.
     *
     * @param int $periodid
     * @param bool $apply false = simulacion (no envia ni registra)
     * @return array ['pendientes' => n, 'enviados' => n, 'fallidos' => n, 'ya_avisados' => n, 'detalle' => [...]]
     */
    public static function send($periodid, $apply = false) {
        global $DB;
        $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid], 'id, name', MUST_EXIST);
        $sum = ['pendientes' => 0, 'enviados' => 0, 'fallidos' => 0, 'ya_avisados' => 0, 'detalle' => []];
        foreach (self::candidates($periodid) as $c) {
            if ($c['notified']) {
                $sum['ya_avisados']++;
                continue;
            }
            $sum['pendientes']++;
            $sum['detalle'][] = $c['name'] . ' — ' . $c['course'];
            if (!$apply) {
                continue;
            }
            $user = $DB->get_record('user', ['id' => $c['userid'], 'deleted' => 0]);
            if (!$user || !self::notify($user, $period, $c)) {
                $sum['fallidos']++;
                continue;
            }
            $now = time();
            $DB->insert_record('gmk_academic_alerts', (object)[
                'userid' => $c['userid'], 'learningplanid' => $c['planid'], 'type' => self::ALERT_TYPE,
                'detail_json' => json_encode(['periodid' => (int)$periodid, 'courseid' => $c['courseid'],
                    'classes' => array_column($c['classes'], 'id')], JSON_UNESCAPED_UNICODE),
                'messagetext' => 'Aviso enviado: clase disponible de ' . $c['course'] . ' (reprobada) en ' . $period->name
                    . '. Debe solicitar el cargue en el área académica y pagarla.',
                'status' => 'notified', 'timecreated' => $now, 'timeacknowledged' => 0, 'usermodified' => 2, 'timemodified' => $now,
            ]);
            $sum['enviados']++;
        }
        return $sum;
    }

    protected static function notify($user, $period, array $c) {
        $shifts = array_values(array_unique(array_filter(array_column($c['classes'], 'shift'))));
        $subject = 'Puedes cursar ' . $c['course'] . ' en ' . $period->name;
        $body = 'Hola ' . $user->firstname . ': para el periodo ' . $period->name . ' se abrió la asignatura ' . $c['course']
            . ($shifts ? ' (jornada ' . implode(', ', $shifts) . ')' : '') . ', que tienes pendiente porque no la aprobaste. '
            . 'Si deseas cursarla, acércate al área académica para solicitar el cargue. '
            . 'Recuerda que debes realizar el pago correspondiente de la asignatura.';
        $message = new \core\message\message();
        $message->component = 'local_grupomakro_core';
        $message->name = 'repeat_offer';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . s($body) . '</p>';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/'))->out(false);
        $message->contexturlname = 'Ir a la plataforma';
        return (bool)message_send($message);
    }
}
