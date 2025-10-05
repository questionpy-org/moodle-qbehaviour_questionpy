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

namespace qbehaviour_questionpy;

use coding_exception;
use core\context;
use question_file_saver;

/**
 * Like {@see question_file_saver}, but supports subdirs in the draft area.
 *
 * @package    qbehaviour_questionpy
 * @author     Maximilian Haye
 * @copyright  2025 TU Berlin, innoCampus {@link https://www.questionpy.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subdir_question_file_saver extends question_file_saver {
    /**
     * Actually save the files.
     *
     * @param integer $itemid the item id for the file area to save into.
     * @param context $context the context where the files should be saved.
     * @throws coding_exception
     */
    public function save_files($itemid, $context): void {
        file_save_draft_area_files(
            $this->draftitemid,
            $context->id,
            $this->component,
            $this->filearea,
            $itemid,
            // This is the only difference from the parent class.
            options: [
                'subdirs' => true,
            ]
        );
    }
}
