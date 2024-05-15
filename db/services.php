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
 * Plakos Moodle Webservices - API Service configuration
 *
 * @package   local_ws_plakos
 * @copyright 2024 Plakos GmbH <info@plakos.de>
 * @license   TODO
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'plakos_get_questions' => [
        'classname' => 'ws_plakos_external',
        'methodname' => 'get_questions',
        'description' => 'Gets the questions from the question bank for the given course',
        'type' => 'read',
    ],
    'plakos_is_enrolled' => [
        'classname' => 'ws_plakos_external',
        'methodname' => 'is_enrolled',
        'description' => 'Gets a value indicating whether the given user id has an active enrolment in the given course.',
        'type' => 'read',
    ],
];
