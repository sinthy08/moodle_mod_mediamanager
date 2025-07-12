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
 * Resource configuration form
 *
 * @package    mod_mediamanager
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

global $CFG;

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/mediamanager/locallib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot . '/local/aws/sdk/aws-autoloader.php');
class mod_mediamanager_mod_form extends moodleform_mod {
    function definition() {
        global $DB, $COURSE;

        // Activity update check
        $cmid = optional_param('update', 0,PARAM_RAW);
        $courseid = $COURSE->id;

        $config = get_config('mediamanager');
        $mform =& $this->_form;

        $config = get_config('mediamanager');

        if ($this->current->instance and $this->current->tobemigrated) {
            // resource not migrated yet
            $resource_old = $DB->get_record('mediamanager_old', array('oldid'=>$this->current->instance));
            $mform->addElement('static', 'warning', '', get_string('notmigrated', 'mediamanager', $resource_old->type));
            $mform->addElement('cancel');
            $this->standard_hidden_coursemodule_elements();
            return;
        }

        //-------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'48'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();
        $element = $mform->getElement('introeditor');
        $attributes = $element->getAttributes();
        $attributes['rows'] = 5;
        $element->setAttributes($attributes);
        $filemanager_options = array();
        $filemanager_options['accepted_types'] = '*';
        $filemanager_options['maxbytes'] = 0;
        $filemanager_options['maxfiles'] = -1;
        $filemanager_options['mainfile'] = true;

        if ($cmid) {
            $s3_link = aws_s3_get_file($COURSE, '', $cmid);
            $mform->addElement('static', 's3_link', get_string('uploaded_file', 'mediamanager'), '<a href="' . $s3_link . '" target="_blank">' . $s3_link . '</a>');
        }
        $mform->addElement('filemanager', 'files', get_string('selectfiles'), null, $filemanager_options);

        // add legacy files flag only if used
        if (isset($this->current->legacyfiles) and $this->current->legacyfiles != RESOURCELIB_LEGACYFILES_NO) {
            $options = array(RESOURCELIB_LEGACYFILES_DONE   => get_string('legacyfilesdone', 'mediamanager'),
                             RESOURCELIB_LEGACYFILES_ACTIVE => get_string('legacyfilesactive', 'mediamanager'));
            $mform->addElement('select', 'legacyfiles', get_string('legacyfiles', 'mediamanager'), $options);
        }

        //-------------------------------------------------------
        $mform->addElement('header', 'optionssection', get_string('appearance'));

        if ($this->current->instance) {
            $options = resourcelib_get_displayoptions(explode(',', $config->displayoptions), $this->current->display);
        } else {
            $options = resourcelib_get_displayoptions(explode(',', $config->displayoptions));
        }

        if (count($options) == 1) {
            $mform->addElement('hidden', 'display');
            $mform->setType('display', PARAM_INT);
            reset($options);
            $mform->setDefault('display', key($options));
        } else {
            $mform->addElement('select', 'display', get_string('displayselect', 'mediamanager'), $options);
            $mform->setDefault('display', $config->display);
            $mform->addHelpButton('display', 'displayselect', 'mediamanager');
        }
        $mform->addElement('checkbox', 'showsize', get_string('showsize', 'mediamanager'));
        $mform->setDefault('showsize', $config->showsize);
        $mform->addHelpButton('showsize', 'showsize', 'mediamanager');
        $mform->addElement('checkbox', 'showtype', get_string('showtype', 'mediamanager'));
        $mform->setDefault('showtype', $config->showtype);
        $mform->addHelpButton('showtype', 'showtype', 'mediamanager');
        $mform->addElement('checkbox', 'showdate', get_string('showdate', 'mediamanager'));
        $mform->setDefault('showdate', $config->showdate);
        $mform->addHelpButton('showdate', 'showdate', 'mediamanager');

        if (array_key_exists(RESOURCELIB_DISPLAY_POPUP, $options)) {
            $mform->addElement('text', 'popupwidth', get_string('popupwidth', 'mediamanager'), array('size'=>3));
            if (count($options) > 1) {
                $mform->hideIf('popupwidth', 'display', 'noteq', RESOURCELIB_DISPLAY_POPUP);
            }
            $mform->setType('popupwidth', PARAM_INT);
            $mform->setDefault('popupwidth', $config->popupwidth);
            $mform->setAdvanced('popupwidth', true);

            $mform->addElement('text', 'popupheight', get_string('popupheight', 'mediamanager'), array('size'=>3));
            if (count($options) > 1) {
                $mform->hideIf('popupheight', 'display', 'noteq', RESOURCELIB_DISPLAY_POPUP);
            }
            $mform->setType('popupheight', PARAM_INT);
            $mform->setDefault('popupheight', $config->popupheight);
            $mform->setAdvanced('popupheight', true);
        }

        if (array_key_exists(RESOURCELIB_DISPLAY_AUTO, $options) or
          array_key_exists(RESOURCELIB_DISPLAY_EMBED, $options) or
          array_key_exists(RESOURCELIB_DISPLAY_FRAME, $options)) {
            $mform->addElement('checkbox', 'printintro', get_string('printintro', 'mediamanager'));
            $mform->hideIf('printintro', 'display', 'eq', RESOURCELIB_DISPLAY_POPUP);
            $mform->hideIf('printintro', 'display', 'eq', RESOURCELIB_DISPLAY_DOWNLOAD);
            $mform->hideIf('printintro', 'display', 'eq', RESOURCELIB_DISPLAY_OPEN);
            $mform->hideIf('printintro', 'display', 'eq', RESOURCELIB_DISPLAY_NEW);
            $mform->setDefault('printintro', $config->printintro);
        }

        $options = array('0' => get_string('none'), '1' => get_string('allfiles'), '2' => get_string('htmlfilesonly'));
        $mform->addElement('select', 'filterfiles', get_string('filterfiles', 'mediamanager'), $options);
        $mform->setDefault('filterfiles', $config->filterfiles);
        $mform->setAdvanced('filterfiles', true);

        //-------------------------------------------------------
        $this->standard_coursemodule_elements();

        //-------------------------------------------------------
        $this->add_action_buttons();

        //-------------------------------------------------------
        $mform->addElement('hidden', 'revision');
        $mform->setType('revision', PARAM_INT);
        $mform->setDefault('revision', 1);
    }

    function data_preprocessing(&$default_values) {
        if ($this->current->instance and !$this->current->tobemigrated) {
            $draftitemid = file_get_submitted_draft_itemid('files');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_mediamanager', 'content', 0, array('subdirs'=>true));
            $default_values['files'] = $draftitemid;
        }

        if (!empty($default_values['displayoptions'])) {
            $displayoptions = (array) unserialize_array($default_values['displayoptions']);
            if (isset($displayoptions['printintro'])) {
                $default_values['printintro'] = $displayoptions['printintro'];
            }
            if (!empty($displayoptions['popupwidth'])) {
                $default_values['popupwidth'] = $displayoptions['popupwidth'];
            }
            if (!empty($displayoptions['popupheight'])) {
                $default_values['popupheight'] = $displayoptions['popupheight'];
            }
            if (!empty($displayoptions['showsize'])) {
                $default_values['showsize'] = $displayoptions['showsize'];
            } else {
                // Must set explicitly to 0 here otherwise it will use system
                // default which may be 1.
                $default_values['showsize'] = 0;
            }
            if (!empty($displayoptions['showtype'])) {
                $default_values['showtype'] = $displayoptions['showtype'];
            } else {
                $default_values['showtype'] = 0;
            }
            if (!empty($displayoptions['showdate'])) {
                $default_values['showdate'] = $displayoptions['showdate'];
            } else {
                $default_values['showdate'] = 0;
            }
        }
    }

    function definition_after_data() {
        if ($this->current->instance and $this->current->tobemigrated) {
            // resource not migrated yet
            return;
        }
        parent::definition_after_data();
    }

    function validation($data, $files) {
        global $USER;
        $cmid = optional_param('update', 0,PARAM_RAW);

        $errors = parent::validation($data, $files);

        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();

        if(!$cmid) {
            if (!$files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data['files'], 'sortorder, id', false)) {
                $errors['files'] = get_string('required');
                return $errors;
            }
        }

        if (count($files) == 1) {
            // no need to select main file if only one picked
            return $errors;
        } else if(count($files) > 1) {
            $mainfile = false;
            foreach($files as $file) {
                if ($file->get_sortorder() == 1) {
                    $mainfile = true;
                    break;
                }
            }
            // set a default main file
            if (!$mainfile) {
                $file = reset($files);
                file_set_sortorder($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(),
                                   $file->get_filepath(), $file->get_filename(), 1);
            }
        }
        return $errors;
    }
}
