<?php

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/lib/externallib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * Web service API definition.
 *
 * @package local_ws_plakos
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class ws_plakos_external extends external_api {

    /**
     * Parameter description for get_questions().
     *
     * @return external_function_parameters.
     */
    public static function get_questions_parameters() {

        $courseIdParameter = new external_value(PARAM_INT,
            'The course for which we are selecting questions',
            VALUE_REQUIRED, null, NULL_NOT_ALLOWED
        );

        $typesParameter = new external_value(PARAM_TEXT,
            'The question types to be returned',
            VALUE_DEFAULT
        );

        $pageParameter = new external_value(PARAM_INT,
            'The question page offset',
            VALUE_DEFAULT, 1
        );

        $perpageParameter = new external_value(PARAM_INT,
            'The number of questions to be returned',
            VALUE_DEFAULT, 10
        );

        return new external_function_parameters([
            'courseid' => $courseIdParameter,
            'types' => $typesParameter,
            'page' => $pageParameter,
            'perpage' => $perpageParameter,
        ]);
    }

    /**
     * Return questions.
     *
     * This function returns all questions.
     *
     * @return array Array of arrays with questions.
     * @throws dml_exception
     */
    public static function get_questions(?int $courseid, ?string $types, ?int $page = null, ?int $perpage = null) {
        global $DB;

        // a mapping of allowed question classes to api parameters
        $allowedTypes = [
            qtype_multichoice_single_question::class => 'multichoice',
            qtype_multianswer_question::class => 'multiple_answer'
        ];
        $defaultTypes = array_values($allowedTypes);

        $params = self::validate_parameters(self::get_questions_parameters(), [
            'courseid' => $courseid,
            'types' => implode(', ', $defaultTypes),
            'page' => $page,
            'perpage' => $perpage,
        ]);

        $givenTypes = array_filter(
            array_map('trim', explode(',', $params['types']))
        );
        $givenCourseId = $params['courseid'];

        $invalidTypeMessage = sprintf(
            'Invalid question type submitted in parameter "types": %%s. Allowed: %s',
            implode(', ', $defaultTypes)
        );

        $filterTypes = [];
        foreach($givenTypes as $typeToCheck) {
            $questionClass = array_search($typeToCheck, $allowedTypes);
            if($questionClass === false) {
                throw new invalid_parameter_exception(sprintf($invalidTypeMessage, $typeToCheck));
            }

            $filterTypes[$questionClass] = $typeToCheck;
        }

        // fetch course identified by the get param
        if(!$DB->record_exists('course', ['id' => $givenCourseId])) {
            throw new invalid_parameter_exception(
                sprintf('Course with id %d not found', $givenCourseId)
            );
        }


        // fetch context and categories based on the found context
        $context = context_course::instance($givenCourseId);
        if($context === false) {
            throw new invalid_parameter_exception(
                sprintf('Course %d found but context for not found', $givenCourseId)
            );
        }

        // TODO: Research what the categories are (top, default, ..)
        $dbCategories = $DB->get_records('question_categories', [
            'contextid' => $context->id
        ], '', 'id');

        // reduce records to id only
        $categoryIds = array_map(fn($dbCategory) => $dbCategory->id, $dbCategories);

        // fetch all questions
        $quotedGivenTypes = array_map(fn($type) => "'" . $type . "'", $givenTypes);

        // this function sadly does not make use of the pagination,
        // but that is fine for now.
        $questionIds = array_values(question_bank::get_finder()->get_questions_from_categories(
            $categoryIds, 'qtype IN (' . implode(',', $quotedGivenTypes) . ')'
        ));

        $pagedQuestionIds = array_slice($questionIds,
            ($params['page'] - 1) * $params['perpage'], $params['perpage']);

        $questions = [];
        // now loop the questions and fetch them using the internal question
        // bank features
        foreach($pagedQuestionIds as $questionId) {

            $questionFromBank = question_bank::load_question($questionId);

            // check if the given question type is in the list of filtered
            if(!isset($filterTypes[get_class($questionFromBank)])) {
                continue;
            }

            $question = [
                'id' => $questionFromBank->id,
                'title' => $questionFromBank->name,
                'answers' => []
            ];

            foreach($questionFromBank->answers as $answer) {
                $question['answers'][] = [
                    'id' => $answer->id,
                    'text' => $answer->answer,
                    'fraction' => $answer->fraction,
                    'correct' => floatval($answer->fraction) === 1.0,
                    'feedback' => $answer->feedback,
                ];
            }

            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Parameter description for get_questions().
     *
     * @return external_description
     */
    public static function get_questions_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'ID of the question', VALUE_DEFAULT),
                    'title' => new external_value(PARAM_TEXT, 'Name of the question', VALUE_DEFAULT),
                    'answers' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'ID of the answer', VALUE_DEFAULT),
                            'text' => new external_value(PARAM_RAW, 'Text of the answer', VALUE_DEFAULT),
                            'fraction' => new external_value(PARAM_FLOAT, 'Correctness Fraction of the answer where 100 = correct, 0 = wrong, inbetween => ?', VALUE_DEFAULT),
                            'correct' => new external_value(PARAM_BOOL, 'Value indicating whether the answer is correct.', VALUE_DEFAULT),
                            'feedback' => new external_value(PARAM_TEXT, 'Feedback text', VALUE_DEFAULT),
                        ])
                    )
                ]
            )
        );
    }
}
