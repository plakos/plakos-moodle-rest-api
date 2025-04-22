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
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ws_plakos\webservice\get_questions\qtype_helper;

defined('MOODLE_INTERNAL') || die;

/** @var $CFG \stdClass */
require_once($CFG->dirroot . '/lib/externallib.php');
require_once($CFG->dirroot . '/enrol/externallib.php');
require_once($CFG->dirroot . '/enrol/self/externallib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/enrol/externallib.php');

/**
 * Plakos Moodle Webservices - External API
 *
 * @package   local_ws_plakos
 * @copyright 2024 Plakos GmbH <info@plakos.de>
 * @license   TODO
 */
class ws_plakos_external extends external_api {

    public static function onboarding_selfenrol_parameters(): external_function_parameters {
        $courseIds = new external_value(
            PARAM_TEXT,
            'The course ids to self enrol in',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        return new external_function_parameters([
            'courseids' => $courseIds
        ]);
    }

    public static function onboarding_selfenrol($courseids) {
        global $USER;

        $params = self::validate_parameters(self::onboarding_selfenrol_parameters(),
            array(
                'courseids' => $courseids
            ));

        $courseIdsSplitted = explode(',', $params['courseids']);
        $return = [
            'count' => 0,
            'courses' => [],
            'infos' => [],
        ];
        foreach($courseIdsSplitted as $courseId) {
            try {
                if (!$course = get_course($courseId)) {
                    $return['infos'][] = "Course not found: $courseId.";
                    continue;
                }

                // Alle Einschreibemethoden für diesen Kurs abrufen
                $enrolmentMethods = core_enrol_external::get_course_enrolment_methods($courseId);
                $selfEnrolMethod = null;

                foreach ($enrolmentMethods as $enrolmentMethod) {
                    if ($enrolmentMethod['type'] === 'self' && $enrolmentMethod['status'] === true) {
                        $selfEnrolMethod = $enrolmentMethod;
                        break; // Nur eine Methode notwendig
                    }
                }

                if (!$selfEnrolMethod) {
                    $return['infos'][] = "No active self enrolment for course $course->fullname ($courseId).";
                    continue;
                }

                if (self::is_enrolled($courseId, $USER->id)['is_enrolled']) {
                    $return['infos'][] = "User is already enrolled in course $course->fullname (ID $courseId).";
                    continue;
                }

                // Nutzer per Selbsteinschreibung hinzufügen
                enrol_self_external::enrol_user($courseId, $USER->id, $selfEnrolMethod['id']);

                // Erfolg speichern
                $return['count']++;
                $return['courses'][] = $course->fullname;
            }
            catch(\Exception $e) {
                echo $e->getMessage();
                // Do nothing
                // echo $e->getMessage();
            }
        }

        return $return;
    }

    public static function onboarding_selfenrol_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'The number of courses the user is self-enrolled in', VALUE_DEFAULT),
            'courses' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'The number of courses the user is self-enrolled in', VALUE_DEFAULT)
            ),
            'infos' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'A list of errors that occured', VALUE_DEFAULT)
            ),
        ]);
    }

    /**
     * Parameter description for onboarding_values().
     *
     * @return external_function_parameters.
     */
    public static function onboarding_values_parameters(): external_function_parameters {

        $result = new external_value(
            PARAM_TEXT,
            'The type identifier of the resulting filter fields',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        $country = new external_value(
            PARAM_TEXT,
            'The selected countries (comma separated)',
            VALUE_DEFAULT, null
        );

        $phase = new external_value(
            PARAM_TEXT,
            'The selected phases (comma separated)',
            VALUE_DEFAULT
        );

        $product = new external_value(
            PARAM_TEXT,
            'The selected products (comma separated)',
            VALUE_DEFAULT
        );

        $career = new external_value(
            PARAM_TEXT,
            'The selected careers (comma separated)',
            VALUE_DEFAULT
        );

        return new external_function_parameters(
            [
                'result' => $result,
                'countries' => $country,
                'phases' => $phase,
                'products' => $product,
                'careers' => $career,
            ]
        );
    }

    /**
     * This function returns values to implement an onboarding wizard.
     *
     * @param string|null $result
     * @param string|null $countries
     * @param string|null $phases
     * @param string|null $products
     * @param string|null $careers
     * @return array Array of arrays with questions.
     * @throws dml_exception
     * @throws invalid_parameter_exception
     */
    public static function onboarding_values(?string $result, ?string $countries = null, ?string $phases = null,
                                             ?string $products = null, ?string $careers = null): array {
        global $DB;

        $params = self::validate_parameters(self::onboarding_values_parameters(), [
            'result'    => $result,
            'countries' => $countries,
            'phases'    => $phases,
            'products'  => $products,
            'careers'   => $careers,
        ]);

        // The list of placeholders.
        $filters = [
            'country' => $params['countries'],
            'phase'   => $params['phases'],
            'product' => $params['products'],
            'career'  => $params['careers'],
        ];

        // A small helper function that creates sql placeholder strings for the queries. Without this, we would run.
        // Into a max 30 character limit for sql placeholders.
        $ph = function(string $filter, string $value, int $length = 3): string {
            return implode('_',
                array_map(
                    fn ($f) => substr($f, 0, $length),
                    explode('_', $filter . '_' . $value)
                )
            );
        };

        // Grouped field filters.
        $fieldfilter = [];
        foreach ($filters as $filterkey => $filtervalues) {
            if ($filtervalues === null) {
                continue;
            }

            $fieldfilter[$filterkey] = [];
            foreach (explode(',', $filtervalues) as $split) {
                $placeholder = $ph($filterkey, $split);
                $fieldfilter[$filterkey][$placeholder] = implode('_', ['onboarding', $filterkey, $split]);
            }
        }

        // Build the EXISTS queries for each field group and the groups itself.
        $wherequeries = [];
        foreach ($fieldfilter as $multifilters) {
            $localwherequeries = [];
            foreach (array_keys($multifilters) as $sqlplaceholder) {
                $localwherequeries[] = '(cf.shortname = :' . $sqlplaceholder . ' AND cd.value = 1)';
            }
            $wherequeries[] = 'EXISTS (
    SELECT cd.id
    FROM   mdl_customfield_data cd
    JOIN   mdl_customfield_field cf ON cf.id = cd.fieldid
    WHERE  cd.instanceid = c.id AND (' . implode(' OR ', $localwherequeries) . ')
)';
        }

        // Construct SQL query based on the selected filters.
        $sql = 'SELECT c.id, c.fullname FROM mdl_course c';
        if (count($wherequeries) > 0) {
            $sql .= "\nWHERE\n" . implode(' AND ', $wherequeries);
        }

        // Reduce collected filters to params for the query.
        $sqlparams = [];
        array_walk_recursive($fieldfilter, function($a, $b) use (&$sqlparams) { $sqlparams[$b] = $a;
        });
        $courses = $DB->get_records_sql($sql, $sqlparams);

        // Return format.
        $return = [
            'fields' => [],
            'current_courses' => [
                'count' => 0,
                'courses' => [],
            ],
            'potential_courses' => [
                'count' => 0,
                'courses' => [],
            ],
            'diff_courses' => [
                'count' => 0,
                'courses' => [],
                'reason' => 'Keine Zuordnung für ' . $params['result'],
            ],
            'filters' => [
                'countries' => [],
                'products' => [],
                'careers' => [],
                'phases' => [],
            ],
        ];

        $allcustomfields = [];

        // Loop all courses and collect the data.
        foreach ($courses as $course) {

            // Extract custom fields.
            $customfields = core_course\customfield\course_handler::create()->export_instance_data($course->id);
            if (!$customfields) {
                continue;
            }

            // Extract image of course.
            $courseimage = \core_course\external\course_summary_exporter::get_course_image($course);

            // Save current course.
            $return['current_courses']['courses'][] = [
                'id' => $course->id,
                'title' => $course->fullname,
                'image' => $courseimage,
            ];

            $via = [];
            // Loop fields.
            foreach ($customfields as $data) {
                if (str_starts_with($data->get_shortname(), 'onboarding_')) {
                    $allcustomfields[$data->get_shortname()] = $data->get_name();
                }
                if ($result !== 'finish' && str_starts_with($data->get_shortname(), 'onboarding_' . $result)) {
                    $tmp = explode('_', $data->get_shortname(), 3);
                    if (!isset($return['fields'][$data->get_shortname()])) {
                        $return['fields'][$data->get_shortname()] = [
                            'value' => $tmp[2],
                            'label' => $data->get_name(),
                            'active' => false,
                        ];
                    }

                    if ($data->get_data_controller()->get_value() === 1) {
                        $via[] = $tmp[2];
                        $return['fields'][$data->get_shortname()]['active'] = true;
                    }
                }
            }

            // Assign to cat depending on it's field values.
            if (count($via) > 0) {
                $return['potential_courses']['courses'][] = [
                    'id' => $course->id,
                    'title' => $course->fullname,
                    'image' => $courseimage,
                    'via' => implode(',', $via),
                ];
            } else {
                $return['diff_courses']['courses'][] = [
                    'id' => $course->id,
                    'image' => $courseimage,
                    'title' => $course->fullname,
                ];
            }
        }

        $map = [
            'countries' => 'country',
            'phases' => 'phase',
            'careers' => 'career',
            'products' => 'product',
        ];

        foreach (array_keys($map) as $grp) {
            foreach (array_filter(explode(',', $params[$grp])) as $value) {
                $return['filters'][$grp][] = [
                    'value' => $value,
                    'label' => $allcustomfields['onboarding_' . $map[$grp] . '_' . $value],
                ];
            }
        }

        $return['current_courses']['count'] = count($return['current_courses']['courses']);
        $return['potential_courses']['count'] = count($return['potential_courses']['courses']);
        $return['diff_courses']['count'] = count($return['diff_courses']['courses']);
        return $return;
    }

    /**
     * Parameter description for onboarding_values().
     *
     * @return external_single_structure
     */
    public static function onboarding_values_returns() {
        return new external_single_structure([
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_TEXT, 'The value of the field', VALUE_DEFAULT),
                    'label' => new external_value(PARAM_TEXT, 'The label of the field', VALUE_DEFAULT),
                    'active' => new external_value(PARAM_BOOL,
                        'A flag indicating whether the filter is active and usable', VALUE_DEFAULT),
                ])
            ),
            'current_courses' => new external_single_structure([
                'count' => new external_value(PARAM_INT, 'The number of courses', VALUE_DEFAULT),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'ID of the course', VALUE_DEFAULT),
                        'title' => new external_value(PARAM_TEXT, 'Title of the course', VALUE_DEFAULT),
                        'image' => new external_value(PARAM_TEXT, 'Image of the course', VALUE_DEFAULT),
                    ])
                ),
            ]),
            'potential_courses' => new external_single_structure([
                'count' => new external_value(PARAM_INT, 'The number of potential courses', VALUE_DEFAULT),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'ID of the course', VALUE_DEFAULT),
                        'title' => new external_value(PARAM_TEXT, 'Title of the course', VALUE_DEFAULT),
                        'image' => new external_value(PARAM_TEXT, 'Image of the course', VALUE_DEFAULT),
                        'via' => new external_value(PARAM_TEXT, 'The potential filter(s) set leading to the result', VALUE_DEFAULT),
                    ])
                ),
            ]),
            'diff_courses' => new external_single_structure([
                'reason' => new external_value(PARAM_TEXT, 'Reason for the diff', VALUE_DEFAULT),
                'count' => new external_value(PARAM_INT, 'The number of diff courses', VALUE_DEFAULT),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'ID of the course', VALUE_DEFAULT),
                        'title' => new external_value(PARAM_TEXT, 'Title of the course', VALUE_DEFAULT),
                        'image' => new external_value(PARAM_TEXT, 'Image of the course', VALUE_DEFAULT),
                    ])
                ),
            ]),
            'filters' => new external_single_structure([
                'products' => new external_multiple_structure(
                    new external_single_structure([
                        'value' => new external_value(PARAM_TEXT, 'The value of the product filter', VALUE_DEFAULT),
                        'label' => new external_value(PARAM_TEXT, 'The label of the product filter', VALUE_DEFAULT),
                    ])
                ),
                'phases' => new external_multiple_structure(
                    new external_single_structure([
                        'value' => new external_value(PARAM_TEXT, 'The value of the phase filter', VALUE_DEFAULT),
                        'label' => new external_value(PARAM_TEXT, 'The label of the phase filter', VALUE_DEFAULT),
                    ])
                ),
                'careers' => new external_multiple_structure(
                    new external_single_structure([
                        'value' => new external_value(PARAM_TEXT, 'The value of the career filter', VALUE_DEFAULT),
                        'label' => new external_value(PARAM_TEXT, 'The label of the career filter', VALUE_DEFAULT),
                    ])
                ),
                'countries' => new external_multiple_structure(
                    new external_single_structure([
                        'value' => new external_value(PARAM_TEXT, 'The value of the country filter', VALUE_DEFAULT),
                        'label' => new external_value(PARAM_TEXT, 'The label of the country filter', VALUE_DEFAULT),
                    ])
                ),
            ]),
        ]);
    }

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
                                    'Correctness Fraction of the answer where > 0 = correct',
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
     * @return external_function_parameters.
     */
    public static function finish_onboarding_parameters(): external_function_parameters {

        $useridparameter = new external_value(
            PARAM_INT,
            'The ID of the user',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        return new external_function_parameters(
            [
                'userid' => $useridparameter,
            ]
        );
    }

    /**
     * This function sets the user flag indicating whether the onboarding is finished.
     *
     * @param int $userid
     * @return array Array of nothing.
     * @throws dml_exception
     */
    public static function finish_onboarding(int $userid): array {
        global $DB, $CFG;

        $user = \core_user::get_user($userid);
        if ($user) {
            $user->profile_field_onboarding_finished = 1;
            profile_save_data($user);
        }

        // Dummy response.
        return [
            'onboarding_finished' => true,
        ];
    }


    /**
     * Return description for finish_onboarding().
     *
     * @return external_single_structure
     */
    public static function finish_onboarding_returns() {
        new external_single_structure(
            [
                'onboarding_success' => new external_value(PARAM_BOOL, 'Successful or not', VALUE_DEFAULT),
            ]
        );
    }

    /**
     * Parameter description for is_enrolled().
     *
     * @return external_function_parameters.
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
     * Gets a value indicating whether the given user is enrolled in the given course.
     *
     * @param int|null $courseid
     * @param int|null $userid
     * @return array
     */
    public static function is_enrolled(?int $courseid, ?int $userid) {
        $context = context_course::instance($courseid);
        return [
            'courseid' => $courseid,
            'userid' => $userid,
            'is_enrolled' => is_enrolled($context, $userid, '', true),
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

    /**
     * Parameter description for is_enrolled().
     *
     * @return external_function_parameters.
     */
    public static function subscription_activate_parameters() {
        $markerCourseId = new external_value(
            PARAM_INT,
            'The marker course',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );
        $userId = new external_value(
            PARAM_INT,
            'The user we want to activate',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        return new external_function_parameters(
            [
                'userid' => $userId,
                'markercourseid' => $markerCourseId,
            ]
        );
    }

    /**
     * Gets a value indicating whether the given user is enrolled in the given course.
     *
     * @param int|null $courseid
     * @param int|null $userid
     * @return array
     */
    public static function subscription_activate(int $userid, int $markercourseid) {
        global $DB;

        $return = [
            'count' => 0,
            'courses' => [],
            'infos' => [],
        ];

        $manualEnrolPlugin = new enrol_manual_plugin();

        // Manuelle Einschreibung im Marker-Kurs prüfen und durchführen
        $manualEnrol = $DB->get_record('enrol', ['courseid' => $markercourseid, 'enrol' => 'manual']);
        if (!$manualEnrol) {
            $return['infos'][] = "Manual enrollment for marker course $markercourseid not found.";
            return $return;
        }

        try {
            $manualEnrolPlugin->enrol_user($manualEnrol, $userid);
            $course = get_course($markercourseid);
            $return['courses'][] = $course->fullname;
            $return['count']++;
        } catch (\Exception $e) {
            $return['infos'][] = "Error while enroling to course id $markercourseid: " . $e->getMessage();
        }

        $sql = "SELECT ue.id AS userenrolid, e.id AS enrolid, e.courseid
            FROM {user_enrolments} ue
            JOIN {enrol} e ON ue.enrolid = e.id
            WHERE ue.userid = :userid AND e.enrol = 'self'";

        $enrolments = $DB->get_records_sql($sql, ['userid' => $userid]);

        if (!$enrolments) {
            $return['infos'][] = "No self-enrolment found for user id $userid.";
        }

        foreach ($enrolments as $enrolment) {
            // Manuelle Einschreibung für den Kurs finden
            $manualEnrol = $DB->get_record('enrol', ['courseid' => $enrolment->courseid, 'enrol' => 'manual']);

            if (!$manualEnrol) {
                $return['infos'][] = "Manual enrolment not enabled for course id {$enrolment->courseid}";
                continue;
            }

            // Alte Selbst-Einschreibung entfernen
            try {
                $DB->delete_records('user_enrolments', ['id' => $enrolment->userenrolid]);
            } catch (\Exception $e) {
                $return['infos'][] = "Error removing the self enrolment for course id {$enrolment->courseid}: " . $e->getMessage();
                continue;
            }

            // Neue manuelle Einschreibung durchführen
            try {
                $manualEnrolPlugin->enrol_user($manualEnrol, $userid);
                $course = get_course($enrolment->courseid);
                $return['courses'][] = $course->fullname;
                $return['count']++;
            } catch (\Exception $e) {
                $return['infos'][] = "Error while manually enroling in course id {$enrolment->courseid}: " . $e->getMessage();
            }
        }

        return $return;
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_single_structure
     */
    public static function subscription_activate_returns() {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of changed enrolments', VALUE_DEFAULT),
            'courses' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'The number of courses the user is self-enrolled in', VALUE_DEFAULT)
            ),
            'infos' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'The errors that occured.', VALUE_DEFAULT)
            )
        ]);
    }

    /**
     * Parameter description for is_enrolled().
     *
     * @return external_function_parameters.
     */
    public static function subscription_deactivate_parameters() {
        $markerCourseId = new external_value(
            PARAM_INT,
            'The marker course',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );
        $userId = new external_value(
            PARAM_INT,
            'The user we want to deactivate',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );
        $excludeCourseIds = new external_value(
            PARAM_TEXT,
            'The courses to ignore (comma separated)',
            VALUE_DEFAULT, null
        );

        return new external_function_parameters(
            [
                'userid' => $userId,
                'markercourseid' => $markerCourseId,
                'excludecourseids' => $excludeCourseIds,
            ]
        );
    }

    /**
     * Gets a value indicating whether the given user is enrolled in the given course.
     *
     * @param int|null $courseid
     * @param int|null $userid
     * @paran string $excludecourseids
     * @return array
     */
    public static function subscription_deactivate(int $userid, int $markercourseid, string $excludecourseids = '') {
        global $DB;

        $return = [
            'count' => 0,
        ];

        $excluded = array_filter(array_map('intval', explode(',', $excludecourseids)));

        $sql = "SELECT e.id AS enrolid, e.courseid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON ue.enrolid = e.id
             WHERE ue.userid = :userid
               AND e.enrol = 'manual'";

        $params = ['userid' => $userid];
        $enrolments = $DB->get_records_sql($sql, $params);

        foreach ($enrolments as $enrolment) {
            if (!in_array($enrolment->courseid, $excluded)) {
                $return['count']++;
                enrol_get_plugin('manual')->unenrol_user(
                    $DB->get_record('enrol', ['id' => $enrolment->enrolid], '*', MUST_EXIST),
                    $userid
                );
            }
        }

        return $return;
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_single_structure
     */
    public static function subscription_deactivate_returns() {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of changed enrolments', VALUE_DEFAULT),
            'infos' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'The errors that occured.', VALUE_DEFAULT)
            )
        ]);
    }
}
