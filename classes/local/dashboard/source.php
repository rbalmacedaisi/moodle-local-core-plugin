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
 * Dashboard administrativo: una fuente de pendientes (§2.1 del plan).
 *
 * Cada bandeja aporta una subclase en classes/local/dashboard/sources/ y se
 * registra en registry::SOURCES. La fuente solo sabe LISTAR sus pendientes;
 * el semaforo, los conteos, la vista "lo mio" y las alertas los pone el
 * dashboard. Cada item:
 *   type          tipo de ANS (config::SLA_DEFAULTS), p. ej. 'wdr.receive'
 *   ref           identificador estable dentro de la fuente (p. ej. 'RET-2026-0012')
 *   title         texto corto; sin datos sensibles en fuentes privadas
 *   subtitle      detalle (estado, clase, fecha)
 *   since         desde cuando espera (ANS por antiguedad)
 *   deadline      fecha limite (ANS 'before')
 *   owner         userid responsable o 0 = la cola del rol
 *   url           enlace directo
 *   level, due    solo si la fuente trae su propio semaforo (external_sla)
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

use context_system;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

abstract class source {

    /** Upper bound of rows a source reads per call (oldest first). */
    const MAX_ROWS = 1000;

    abstract public function key(): string;

    abstract public function label(): string;

    /** Material Design icon name (mdi-*). */
    abstract public function icon(): string;

    /** Page that manages this queue (path under /local/grupomakro_core/pages/). */
    abstract public function page(): string;

    /** Capabilities (short names) that make the queue "mine"; any of them. */
    abstract public function capabilities(): array;

    /**
     * Pending items. $userid/$team let a source restrict visibility (tickets);
     * most sources return the whole queue and the dashboard filters by owner.
     *
     * @return stdClass[]
     */
    abstract public function fetch(int $userid, bool $team, int $now): array;

    /** The source sets level/due itself and sends its own alerts (tickets, cartas). */
    public function external_sla(): bool {
        return false;
    }

    /** Items never carry names of students (psicologia). */
    public function is_private(): bool {
        return false;
    }

    /** Whether the queue is the user's own (capability). */
    public function owns(int $userid): bool {
        $context = context_system::instance();
        foreach ($this->capabilities() as $cap) {
            if (has_capability('local/grupomakro_core:' . $cap, $context, $userid)) {
                return true;
            }
        }
        return false;
    }

    /** Users holding the queue: they get the level-1 alert of items without owner. */
    public function queue_users(): array {
        $context = context_system::instance();
        $ids = [];
        foreach ($this->capabilities() as $cap) {
            $users = get_users_by_capability($context, 'local/grupomakro_core:' . $cap, 'u.id', '', '', '', '', '', false);
            foreach ($users as $u) {
                $ids[(int)$u->id] = (int)$u->id;
            }
        }
        return array_values($ids);
    }

    public function url(array $params = []): string {
        return (new moodle_url('/local/grupomakro_core/pages/' . $this->page(), $params))->out(false);
    }

    /** Helper to build an item. */
    protected function item(string $type, string $ref, string $title, string $subtitle, int $since, int $deadline,
            int $owner, string $url): stdClass {
        return (object)[
            'type' => $type,
            'ref' => $ref,
            'title' => $title,
            'subtitle' => $subtitle,
            'since' => $since,
            'deadline' => $deadline,
            'owner' => $owner,
            'url' => $url,
        ];
    }

    /** Short local date for subtitles. */
    protected static function date(int $ts, bool $time = false): string {
        if ($ts <= 0) {
            return '';
        }
        return userdate($ts, $time ? '%d/%m/%Y %H:%M' : '%d/%m/%Y');
    }
}
