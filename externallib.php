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
 * Plakos Moodle Webservices - External API
 *
 * @package   local_ws_plakos
 * @copyright 2024 Plakos GmbH <info@plakos.de>
 * @license   TODO
 */

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ws_plakos\webservice\get_questions\qtype_helper;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * Plakos Moodle Webservices - External API
 *
 * @package   local_ws_plakos
 * @copyright 2024 Plakos GmbH <info@plakos.de>
 * @license   TODO
 */
class ws_plakos_external extends external_api {


    /**
     * Parameter description for get_questions().
     *
     * @return external_function_parameters.
     */
    public static function get_questions_parameters(): external_function_parameters {

        $courseidparameter = new external_value(
            PARAM_INT,
            'The course for which we are selecting questions',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        $typesparameter = new external_value(
            PARAM_TEXT,
            'The question types to be returned',
            VALUE_DEFAULT
        );

        $pageparameter = new external_value(
            PARAM_INT,
            'The question page offset',
            VALUE_DEFAULT, 1
        );

        $perpageparameter = new external_value(
            PARAM_INT,
            'The max number of questions to be returned',
            VALUE_DEFAULT, 10
        );

        return new external_function_parameters(
            [
            'courseid' => $courseidparameter,
            'types' => $typesparameter,
            'page' => $pageparameter,
            'perpage' => $perpageparameter,
            ]
        );
    }

    /**
     * This function returns all questions.
     *
     * @param int|null $courseid
     * @param string|null $types
     * @param int|null $page
     * @param int|null $perpage
     * @return array Array of arrays with questions.
     * @throws dml_exception
     * @throws invalid_parameter_exception
     */
    public static function get_questions(?int $courseid, ?string $types, ?int $page, ?int $perpage): array {
        global $DB;

        $qtypehelper = new qtype_helper();

        $params = self::validate_parameters(self::get_questions_parameters(), [
            'courseid' => $courseid,
            'types'    => $types,
            'page'     => $page,
            'perpage'  => $perpage,
        ]);

        // Extract and validate given types.
        $giventypes = $qtypehelper->default_types();
        if ($params['types'] !== null) {
            $giventypes = $qtypehelper->parse_from_comma_string($params['types']);

            // Validate given types in detail.
            if (!$qtypehelper->validate_given($giventypes)) {
                $invalidtypemessage = sprintf(
                    'Invalid question type submitted in parameter "types": %s. Allowed: %s',
                    $params['types'], implode(',', $qtypehelper->default_types())
                );
                throw new invalid_parameter_exception($invalidtypemessage);
            }
        }

        // Extract the given course id and paging parameters.
        $givencourseid = $params['courseid'];
        $givenpage = $params['page'];
        $givenperpage = $params['perpage'];

        // Fetch given course.
        if (!$DB->record_exists('course', ['id' => $givencourseid])) {
            throw new invalid_parameter_exception(
                sprintf('Course with id %d not found', $givencourseid)
            );
        }

        // Fetch context and categories based on the found context.
        $context = context_course::instance($givencourseid);
        if ($context === false) {
            throw new invalid_parameter_exception(
                sprintf('Course %d found but context for not found', $givencourseid)
            );
        }

        // TODO: Research what the categories are (top, default, ..).
        $dbcategories = $DB->get_records(
            'question_categories', ['contextid' => $context->id], '', 'id'
        );

        // Reduce records to id only.
        $categoryids = array_map(fn($dbcategory) => $dbcategory->id, $dbcategories);

        if (count($categoryids) === 0) {
            // TODO: Maybe return an empty result here.
            throw new invalid_parameter_exception(
                sprintf('No question categories for course %d found', $givencourseid)
            );
        }

        // The inner workings of this function sadly does not make use of the pagination.
        $questionids = array_values(
            question_bank::get_finder()->get_questions_from_categories(
                $categoryids, 'qtype IN (' . implode(',', $qtypehelper->quote($giventypes)) . ')'
            )
        );

        // Virtual pagination.
        $pagedquestionids = array_slice($questionids, ($givenpage - 1) * $givenperpage, $givenperpage);

        $questions = [];
        // Now loop the questions and fetch them using the internal question bank features.
        foreach ($pagedquestionids as $questionid) {
            $response = $qtypehelper->to_array(question_bank::load_question($questionid));
            if (count($response) > 0) {
                $questions[] = $response;
            }
        }

        return $questions;
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_multiple_structure
     */
    public static function get_questions_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'ID of the question', VALUE_DEFAULT),
                    'title' => new external_value(PARAM_TEXT, 'Name of the question', VALUE_DEFAULT),
                    'type' => new external_value(PARAM_TEXT, 'Type of the question', VALUE_DEFAULT),
                    'answers' => new external_multiple_structure(
                        new external_single_structure(
                            [
                            'id' => new external_value(PARAM_INT, 'ID of the answer', VALUE_DEFAULT),
                            'text' => new external_value(PARAM_RAW, 'Text of the answer', VALUE_DEFAULT),
                            'fraction' => new external_value(PARAM_FLOAT,
                                'Correctness Fraction of the answer where 100 = correct, 0 = wrong, inbetween => ?',
                                VALUE_DEFAULT
                            ),
                            'correct' => new external_value(PARAM_BOOL,
                                'Value indicating whether the answer is correct.',
                                VALUE_DEFAULT
                            ),
                            'feedback' => new external_value(PARAM_RAW,
                                'Feedback text',
                                VALUE_DEFAULT
                            ),
                            ]
                        )
                    ),
                ]
            )
        );
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_function_parameters
     */
    public static function is_enrolled_parameters() {
        $courseidparameter = new external_value(
            PARAM_INT,
            'The course for which we check the enrollment',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        $useridparameter = new external_value(
            PARAM_INT,
            'The user for which we check the enrollment',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        return new external_function_parameters(
            [
                'courseid' => $courseidparameter,
                'userid' => $useridparameter,
            ]
        );
    }

    /**
     * @return array
     */
    public static function is_enrolled(?int $courseid, ?int $userid) {
        $context = context_course::instance($courseid);
        return [
            'courseid' => $courseid,
            'userid' => $userid,
            'is_enrolled' => is_enrolled($context, $userid),
        ];
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_single_structure
     */
    public static function is_enrolled_returns() {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'ID of the user', VALUE_DEFAULT),
            'courseid' => new external_value(PARAM_INT, 'ID of the course', VALUE_DEFAULT),
            'is_enrolled' => new external_value(PARAM_BOOL, 'Flag indicating whether the user is enrolled', VALUE_DEFAULT),
        ]);
    }
}
