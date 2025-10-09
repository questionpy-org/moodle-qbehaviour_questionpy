<?php
// This file is part of the QuestionPy Moodle plugin - https://questionpy.org
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

use core\di;
use qtype_questionpy\constants;
use qtype_questionpy\local\files\response_file_service;

/**
 * Custom question behaviour for QuestionPy.
 *
 * This behaviour delegates almost all calls to the behaviour which the question would ordinarily have used (deferred,
 * adaptive, immediate, etc.), but it
 * - allows access to the entire {@see question_attempt} (questions are only provided the first step) and
 * - allows access to the {@see question_attempt_pending_step pending step} while an action is being processed.
 *
 * @package    qbehaviour_questionpy
 * @author     Maximilian Haye
 * @copyright  2024 TU Berlin, innoCampus {@link https://www.questionpy.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_questionpy extends question_behaviour {
    /** @var string */
    private const QB_VAR_BEHAVIOUR = '_behaviour';

    /** @var question_behaviour */
    private question_behaviour $delegate;

    /** @var question_attempt_pending_step|null */
    private ?question_attempt_pending_step $pendingstep = null;

    /** @var response_file_service */
    private readonly response_file_service $afs;

    /**
     * Initializes the behaviour for the given attempt.
     *
     * @param question_attempt $qa
     * @param string|qbehaviour_questionpy|null $preferredbehaviour Moodle sometimes passes the name of the archetypal
     *  behaviour here, and in some cases another instance of {@see qbehaviour_questionpy} to copy.
     * @param question_behaviour|null $delegate if an instance already exists, the "normal" behaviour to delegate to.
     *
     * @throws coding_exception
     */
    public function __construct(question_attempt $qa, string|qbehaviour_questionpy|null $preferredbehaviour,
                                ?question_behaviour $delegate = null) {
        parent::__construct($qa, $preferredbehaviour);

        if ($delegate) {
            $this->delegate = $delegate;
        } else if ($preferredbehaviour instanceof qbehaviour_questionpy) {
            // In some cases (such as regrading), Moodle passes us the original behaviour instance instead of its name.
            // We can't just reuse the delegate though, because it will contain the old attempt instance.
            // See question_attempt::start().
            $delegateclass = get_class($preferredbehaviour->delegate);
            $this->delegate = new $delegateclass($qa, $preferredbehaviour->delegate);
        } else {
            $delegatename = $qa->get_last_behaviour_var(self::QB_VAR_BEHAVIOUR, $preferredbehaviour);
            $this->delegate = question_engine::make_behaviour($delegatename, $qa, $preferredbehaviour);
        }

        if ($this->question instanceof qtype_questionpy_question) {
            $this->question->behaviour = $this;
        }

        $this->afs = di::get(response_file_service::class);
    }

    /**
     * If we are currently processing an action, return the pending step instance.
     *
     * This is useful because the pending step is not yet persisted and can still be mutated.
     *
     * @throws coding_exception if we are not currently processing an action
     */
    public function get_pending_step(): question_attempt_pending_step {
        if ($this->pendingstep === null) {
            throw new coding_exception('pendingstep is not set, we are probably not currently processing an action');
        }
        return $this->pendingstep;
    }

    /**
     * Return the complete question attempt.
     *
     * @return question_attempt
     */
    public function get_qa(): question_attempt {
        return $this->qa;
    }

    /**
     * Some behaviours can only work with certing types of question. This method
     * allows the behaviour to verify that a question is compatible.
     *
     * This implementation is only provided for backwards-compatibility. You should
     * override this method if you are implementing a behaviour.
     *
     * @param question_definition $question the question.
     */
    public function is_compatible_question(question_definition $question): bool {
        return $question->get_type_name() === 'questionpy';
    }

    /**
     * Returns the name of this behaviour, which must match the plugin name.
     *
     * @return string the name of this behaviour. For example the name of
     * qbehaviour_mymodle is 'mymodel'.
     */
    public function get_name(): string {
        return 'questionpy';
    }

    // The methods we actually care about:.

    /**
     * Sets the pending step for {@see get_pending_step}, handles files, then delegates processing.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     * @throws coding_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function process_action(question_attempt_pending_step $pendingstep): bool {
        $this->handle_files_in_pending_step($pendingstep);

        $this->pendingstep = $pendingstep;
        try {
            return $this->delegate->process_action($pendingstep);
        } finally {
            $this->pendingstep = null;
        }
    }


    /**
     * Sets the pending step for {@see get_pending_step}, handles files, then delegates processing.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     * @throws coding_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function process_autosave(question_attempt_pending_step $pendingstep): bool {
        $this->handle_files_in_pending_step($pendingstep);

        $this->pendingstep = $pendingstep;
        try {
            return $this->delegate->process_autosave($pendingstep);
        } finally {
            $this->pendingstep = null;
        }
    }

    /**
     * Handle files for a new or regraded pending step, making sure that draft areas are combined and will be saved.
     *
     * @param question_attempt_pending_step $pendingstep
     * @throws coding_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    private function handle_files_in_pending_step(question_attempt_pending_step $pendingstep): void {
        global $USER;

        $files = $pendingstep->get_qt_var(constants::QT_VAR_RESPONSE_FILES);
        $combineddraftarea = optional_param($this->qa->get_field_prefix() . constants::QT_VAR_RESPONSE_FILES, null, PARAM_INT);

        if ($files === null) {
            return;
        }

        if ($files instanceof question_file_loader) {
            if ($pendingstep->get_id() !== null) {
                // Looks like this step is being regraded, in which case it will keep its step ID, and we don't need to move files.
                return;
            }

            // This appears to be a bug in Moodle: Normal regraded steps keep the old ID, so files (which are stored under the step
            // ID) remain accessible. Autosaved steps, for whatever reason, though, do not keep the ID, so we need to move the
            // files.
            // We use a bound closure to access the private property, which is a hack, but necessary.
            $files = (fn() => $this->data[constants::QT_VAR_RESPONSE_FILES] = $files->get_question_file_saver())
                ->call($pendingstep);
        }

        if (!($files instanceof question_file_saver)) {
            throw new coding_exception("qt var '" . constants::QT_VAR_RESPONSE_FILES . "' is not a question_file_saver");
        }

        // TODO: Remove this sanity check once sure stuff works.
        $draftareafromsaver = (fn() => $this->draftitemid)->call($files);
        if ($draftareafromsaver != $combineddraftarea) {
            throw new coding_exception('question_file_saver and optional_param draft area do not match: '
                . "'$draftareafromsaver' != '$combineddraftarea'");
        }

        $draftareasjson = optional_param($this->qa->get_field_prefix() . constants::FORM_DRAFT_AREAS, null, PARAM_RAW_TRIMMED);
        $draftareas = $draftareasjson ? json_decode($draftareasjson, associative: true, depth: 2) : [];
        if (
            !is_array($draftareas)
            || array_filter($draftareas, fn($value, $key) => !is_string($key) || !is_int($value), ARRAY_FILTER_USE_BOTH)
        ) {
            debugging(constants::FORM_DRAFT_AREAS . ' is not valid.', DEBUG_DEVELOPER);
            $draftareas = null;
        }

        if ($combineddraftarea && $draftareas) {
            // New submission, combine the draft areas. (Which should be empty at this point.)
            $this->afs->combine_response_file_draft_areas($draftareas, $combineddraftarea, $USER->id);
            // Call the saver's constructor again to recalculate the hash. (Yeah, this is our best option.)
            $files->__construct($combineddraftarea, 'question', constants::FILEAREA_RESPONSE_FILES);
        }

        // TODO: Update qpy_response with info about the submitted files, or find another way to tell the package.
    }

    /**
     * Initialise the first step in a question attempt when a new
     * {@see question_attempt} is being started.
     *
     * This method must call $this->question->start_attempt($step, $variant), and may
     * perform additional processing if the behaviour requries it.
     *
     * @param question_attempt_step $step the first step of the
     *      question_attempt being started.
     * @param int $variant which variant of the question to use.
     * @throws coding_exception
     */
    public function init_first_step(question_attempt_step $step, $variant): void {
        $this->delegate->init_first_step($step, $variant);
        $step->set_behaviour_var(self::QB_VAR_BEHAVIOUR, $this->delegate->get_name());
    }

    // The rest we just delegate.

    /**
     * Cause the question to be renderered. This gets the appropriate behaviour
     * renderer using {@see get_renderer()}, and adjusts the display
     * options using {@see adjust_display_options()} and then calls
     * {@see core_question_renderer::question()} to do the work.
     * @param question_display_options $options controls what should and should not be displayed.
     * @param string|null $number the question number to display.
     * @param core_question_renderer $qoutput the question renderer that will coordinate everything.
     * @param qtype_renderer $qtoutput the question type renderer that will be helping.
     * @return string HTML fragment.
     */
    public function render(question_display_options $options, $number, core_question_renderer $qoutput,
                           qtype_renderer $qtoutput): string {
        return $this->delegate->render($options, $number, $qoutput, $qtoutput);
    }

    /**
     * Generate a brief textual description of the current state of the question,
     * normally displayed under the question number.
     *
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguised.
     * @return string a brief summary of the current state of the qestion attempt.
     */
    public function get_state_string($showcorrectness): string {
        return $this->delegate->get_state_string($showcorrectness);
    }

    /**
     * Whether the current attempt at this question could be completed just by the
     * student interacting with the question, before $qa->finish() is called.
     *
     * @return boolean whether the attempt can finish naturally.
     */
    public function can_finish_during_attempt(): bool {
        return $this->delegate->can_finish_during_attempt();
    }


    /**
     * Checks whether the users is allow to be served a particular file.
     * @param question_display_options $options the options that control display of the question.
     * @param string $component the name of the component we are serving files for.
     * @param string $filearea the name of the file area.
     * @param array $args the remaining bits of the file path.
     * @param bool $forcedownload whether the user must be forced to download the file.
     * @return bool true if the user can access this file.
     */
    public function check_file_access($options, $component, $filearea, $args, $forcedownload): bool {
        return $this->delegate->check_file_access($options, $component, $filearea, $args, $forcedownload);
    }

    /**
     * Just delegates.
     *
     * @param moodle_page $page the page to render for.
     * @return qbehaviour_renderer get the appropriate renderer to use for this model.
     */
    public function get_renderer(moodle_page $page): qbehaviour_renderer {
        return $this->delegate->get_renderer($page);
    }

    /**
     * Make any changes to the display options before a question is rendered, so
     * that it can be displayed in a way that is appropriate for the statue it is
     * currently in. For example, by default, if the question is finished, we
     * ensure that it is only ever displayed read-only.
     * @param question_display_options $options the options to adjust. Just change
     * the properties of this object - objects are passed by referece.
     */
    public function adjust_display_options(question_display_options $options): void {
        $this->delegate->adjust_display_options($options);
    }

    /**
     * Get the most applicable hint for the question in its current state.
     * @return question_hint|null the most applicable hint, or null, if none.
     */
    public function get_applicable_hint(): ?question_hint {
        return $this->delegate->get_applicable_hint();
    }

    /**
     * What is the minimum fraction that can be scored for this question.
     * Normally this will be based on $this->question->get_min_fraction(),
     * but may be modified in some way by the behaviour.
     *
     * @return number the minimum fraction when this question is attempted under
     * this behaviour.
     */
    public function get_min_fraction() {
        return $this->delegate->get_min_fraction();
    }

    /**
     * Return the maximum possible fraction that can be scored for this question.
     * Normally this will be based on $this->question->get_max_fraction(),
     * but may be modified in some way by the behaviour.
     *
     * @return number the maximum fraction when this question is attempted under
     * this behaviour.
     */
    public function get_max_fraction() {
        return $this->delegate->get_max_fraction();
    }

    /**
     * Return an array of the behaviour variables that could be submitted
     * as part of a question of this type, with their types, so they can be
     * properly cleaned.
     * @return array variable name => PARAM_... constant.
     */
    public function get_expected_data(): array {
        return $this->delegate->get_expected_data();
    }

    /**
     * Return an array of question type variables for the question in its current
     * state. Normally, if {@see adjust_display_options()} would set
     * {@see question_display_options::$readonly} to true, then this method
     * should return an empty array, otherwise it should return
     * $this->question->get_expected_data(). Thus, there should be little need to
     * override this method.
     * @return array|string variable name => PARAM_... constant, or, as a special case
     *      that should only be used in unavoidable, the constant question_attempt::USE_RAW_DATA
     *      meaning take all the raw submitted data belonging to this question.
     */
    public function get_expected_qt_data(): array|string {
        return $this->delegate->get_expected_qt_data();
    }

    /**
     * Return an array of any im variables, and the value required to get full
     * marks.
     * @return array variable name => value.
     */
    public function get_correct_response(): array {
        return $this->delegate->get_correct_response();
    }

    /**
     * Generate a brief, plain-text, summary of this question. This is used by
     * various reports. This should show the particular variant of the question
     * as presented to students. For example, the calculated quetsion type would
     * fill in the particular numbers that were presented to the student.
     * This method will return null if such a summary is not possible, or
     * inappropriate.
     *
     * Normally, this method delegates to {question_definition::get_question_summary()}.
     *
     * @return string|null a plain text summary of this question.
     */
    public function get_question_summary(): ?string {
        return $this->delegate->get_question_summary();
    }

    /**
     * Generate a brief, plain-text, summary of the correct answer to this question.
     * This is used by various reports, and can also be useful when testing.
     * This method will return null if such a summary is not possible, or
     * inappropriate.
     *
     * @return string|null a plain text summary of the right answer to this question.
     */
    public function get_right_answer_summary(): ?string {
        return $this->delegate->get_right_answer_summary();
    }

    /**
     * Used by {@see start_based_on()} to get the data needed to start a new
     * attempt from the point this attempt has go to.
     * @return array name => value pairs.
     */
    public function get_resume_data(): array {
        return $this->delegate->get_resume_data();
    }

    /**
     * Classify responses for this question into a number of sub parts and response classes as defined by
     * {@see \question_type::get_possible_responses} for this question type.
     *
     * @param string $whichtries which tries to analyse for response analysis. Will be one of
     *                                   question_attempt::FIRST_TRY, LAST_TRY or ALL_TRIES.
     *                                   Defaults to question_attempt::LAST_TRY.
     * @return (question_classified_response|array)[] If $whichtries is question_attempt::FIRST_TRY or LAST_TRY index is subpartid
     *                                   and values are question_classified_response instances.
     *                                   If $whichtries is question_attempt::ALL_TRIES then first key is submitted response no
     *                                   and the second key is subpartid.
     */
    public function classify_response($whichtries = question_attempt::LAST_TRY): array {
        return $this->delegate->classify_response($whichtries);
    }


    /**
     * Just delegates.
     *
     * @param question_attempt_step $step
     * @return string
     */
    public function summarise_action(question_attempt_step $step): string {
        return $this->delegate->summarise_action($step);
    }

    /**
     * When an attempt is started based on a previous attempt (see
     * {@see question_attempt::start_based_on}) this method is called to setup
     * the new attempt.
     *
     * This method must call $this->question->apply_attempt_state($step), and may
     * perform additional processing if the behaviour requries it.
     *
     * @param question_attempt_step $step The first step of the {@see question_attempt} being loaded.
     */
    public function apply_attempt_state(question_attempt_step $step): void {
        $this->delegate->apply_attempt_state($step);
    }

    /**
     * Implementation of processing a manual comment/grade action that should
     * be suitable for most subclasses.
     * @param question_attempt_pending_step $pendingstep a partially initialised step
     *      containing all the information about the action that is being peformed.
     * @return bool either {@see question_attempt::KEEP}
     */
    public function process_comment(question_attempt_pending_step $pendingstep): bool {
        return $this->delegate->process_comment($pendingstep);
    }

    /**
     * Just delegates.
     *
     * @param string|null $comment the comment text to format. If omitted,
     *      $this->qa->get_manual_comment() is used.
     * @param int|null $commentformat the format of the comment, one of the FORMAT_... constants.
     * @param \core\context|null $context the quiz context.
     * @return string the comment, ready to be output.
     */
    public function format_comment($comment = null, $commentformat = null, $context = null): string {
        return $this->delegate->format_comment($comment, $commentformat, $context);
    }

    /**
     * Just delegates.
     *
     * @param question_attempt_step $step
     * @return string
     */
    public function summarise_start($step): string {
        return $this->delegate->summarise_start($step);
    }

    /**
     * Just delegates.
     *
     * @param question_attempt_step $step
     * @return string
     */
    public function summarise_finish($step): string {
        return $this->delegate->summarise_finish($step);
    }

    /**
     * Does this step include a response submitted by a student?
     *
     * This method should return true for any attempt explicitly submitted by a student. The question engine itself will also
     * automatically recognise any last saved response before the attempt is finished, you don't need to return true here for these
     * steps with responses which are not explicitly submitted by the student.
     *
     * @param question_attempt_step $step
     * @return bool is this a step within a question attempt that includes a submitted response by a student.
     */
    public function step_has_a_submitted_response($step): bool {
        return $this->delegate->step_has_a_submitted_response($step);
    }

    /**
     * Catch-all to delegate any method not explicitly delegated above.
     *
     * Behaviour subclasses add additional methods which their renderers (among others) then call. Those are delegated
     * by this magic method. We can't use this for the {@see question_behaviour} methods, because most have
     * implementations in the superclass, which take precedence over `__call`.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return call_user_func_array([$this->delegate, $name], $arguments);
    }
}
