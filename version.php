<?php

/**
 * Plakos Moodle Webservices - Version file
 *
 * @package   local_ws_plakos
 * @copyright 2024 Plakos GmbH <info@plakos.de>
 * @license   TODO
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @var \stdClass $plugin 
*/
$plugin->component = 'local_ws_plakos';
$plugin->version = 2023100900; //4.3
$plugin->requires = 2023100900;
$plugin->supported = [403, 403];
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = 'v0.1.1';