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
 * Resource module version information
 *
 * @package    mod_mediamanager
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use  Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

require('../../config.php');

global $CFG, $DB, $PAGE;
require_once($CFG->dirroot.'/mod/mediamanager/lib.php');
require_once($CFG->dirroot.'/mod/mediamanager/locallib.php');
require_once($CFG->libdir.'/completionlib.php');

$id       = optional_param('id', 0, PARAM_INT); // Course Module ID
$r        = optional_param('r', 0, PARAM_INT);  // Resource instance ID
$redirect = optional_param('redirect', 0, PARAM_BOOL);
$forceview = optional_param('forceview', 0, PARAM_BOOL);

if ($r) {
    if (!$resource = $DB->get_record('mediamanager', array('id'=>$r))) {
        mediamanager_redirect_if_migrated($r, 0);
        throw new \moodle_exception('invalidaccessparameter');
    }
    $cm = get_coursemodule_from_instance('mediamanager', $resource->id, $resource->course, false, MUST_EXIST);

} else {
    if (!$cm = get_coursemodule_from_id('mediamanager', $id)) {
        mediamanager_redirect_if_migrated(0, $id);
        throw new \moodle_exception('invalidcoursemodule');
    }
    $resource = $DB->get_record('mediamanager', array('id'=>$cm->instance), '*', MUST_EXIST);
}

$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mediamanager:view', $context);

// Completion and trigger events.
mediamanager_view($resource, $course, $cm, $context);

$PAGE->set_url('/mod/mediamanager/view.php', array('id' => $cm->id));

if ($resource->tobemigrated) {
    mediamanager_print_tobemigrated($resource, $cm, $course);
    die;
}

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_mediamanager', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!

$s3_file = aws_s3_get_file($course, $cm);

// Embed file view
if (count($files) < 1 && $s3_file == '') {
     mediamanager_print_filenotfound($resource, $cm, $course);
     die;
} else {
    $file = reset($files);
    unset($files);
}

$resource->mainfile = get_file_name ($course, $cm);

$displaytype = mediamanager_get_final_display_type($resource);

if ($displaytype == RESOURCELIB_DISPLAY_OPEN || $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD) {
    $redirect = true;
}

// Don't redirect teachers, otherwise they can not access course or module settings.
if ($redirect && !course_get_format($course)->has_view_page() &&
        (has_capability('moodle/course:manageactivities', $context) ||
        has_capability('moodle/course:update', context_course::instance($course->id)))) {
    $redirect = false;
}

if ($redirect && !$forceview) {
    $s3url = aws_s3_get_file ($course, $cm);
     if($s3url == '') {
         // Open mode of PDF
         // coming from course page or url index page
         // this redirect trick solves caching problems when tracking views ;-)
         $path = '/'.$context->id.'/mod_mediamanager/content/0'.$file->get_filepath().$file->get_filename();
         $fullurl = moodle_url::make_file_url('/pluginfile.php', $path, $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD);
         aws_s3_upload_file($fullurl, $course, $cm, $file);
         $s3url = aws_s3_get_file ($course, $cm);
         $fs = get_file_storage();
         $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());
     } elseif ($s3url != '' && $file != '') {
         aws_s3_delete_file($course->id, $cm);
         $path = '/'.$context->id.'/mod_mediamanager/content/0'.$file->get_filepath().$file->get_filename();
         $fullurl = moodle_url::make_file_url('/pluginfile.php', $path, $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD);
         aws_s3_upload_file($fullurl, $course, $cm, $file);
         $s3url = aws_s3_get_file ($course, $cm);
         $fs = get_file_storage();
         $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());
     }
    redirect($s3url);
}
switch ($displaytype) {
    case RESOURCELIB_DISPLAY_EMBED:
        mediamanager_display_embed($resource, $cm, $course, $file, $s3_file);
        break;
    case RESOURCELIB_DISPLAY_FRAME:
        mediamanager_display_frame($resource, $cm, $course, $file, $s3_file);
        break;
    default:
        if ($s3_file != '' && $file == '') {
            mediamanager_print_workaround($resource, $cm, $course, '', $s3_file);
        } elseif ($s3_file != '' && $file != '') {
            aws_s3_delete_file($course->id, $cm);
            mediamanager_print_workaround($resource, $cm, $course, '', $s3_file);
        }
        else {
            mediamanager_print_workaround($resource, $cm, $course, $file);
        }
        break;
}
