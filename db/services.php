<?php

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
    ]
];
