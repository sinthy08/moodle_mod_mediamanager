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
 * Private resource module utility functions
 *
 * @package    mod_mediamanager
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/filelib.php");
require_once("$CFG->libdir/resourcelib.php");
require_once("$CFG->dirroot/mod/mediamanager/lib.php");
require_once($CFG->dirroot . '/local/aws/sdk/aws-autoloader.php');

/**
 * Redirected to migrated resource if needed,
 * return if incorrect parameters specified
 * @param int $oldid
 * @param int $cmid
 * @return void
 */
function mediamanager_redirect_if_migrated($oldid, $cmid) {
    global $DB, $CFG;

    if ($oldid) {
        $old = $DB->get_record('mediamanager_old', array('oldid'=>$oldid));
    } else {
        $old = $DB->get_record('mediamanager_old', array('cmid'=>$cmid));
    }

    if (!$old) {
        return;
    }

    redirect("$CFG->wwwroot/mod/$old->newmodule/view.php?id=".$old->cmid);
}

/**
 * Display embedded resource file.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @param stored_file $file main file
 * @return does not return
 */
function mediamanager_display_embed($resource, $cm, $course, $file ='', $s3_file = '') {
    global $PAGE, $OUTPUT, $DB;

    $context = context_module::instance($cm->id);

    $mediamanager = core_media_manager::instance($PAGE);
    $embedoptions = array(
        core_media_manager::OPTION_TRUSTED => true,
        core_media_manager::OPTION_BLOCK => true,
    );

    $title = get_file_name($course, $cm);

    if ($s3_file != '' && $file == '') {

        // View the file
        $url = $s3_file;
        $activity_type = $DB->get_record('mediamanager_activity_type', ['cmid' => $cm->id]);
        $code = pdf_file_embed_view ($activity_type, $url, $title);

    } elseif ($s3_file != '' && $file != '') {

        // Update the file
        aws_s3_delete_file($course->id, $cm);
        $code = mediamanager_file_embed_save ($course, $cm, $title, $mediamanager, $embedoptions, $file, $context, $resource);
        $fs = get_file_storage();
        $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());    // Let the module handle the display.

    } else {
        // Save the file
        $code = mediamanager_file_embed_save ($course, $cm, $title, $mediamanager, $embedoptions, $file, $context, $resource);
        $fs = get_file_storage();
        $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());    // Let the module handle the display.

    }

    $PAGE->activityheader->set_description(mediamanager_get_intro($resource, $cm));

    mediamanager_print_header($resource, $cm, $course);

    echo format_text($code, FORMAT_HTML, ['noclean' => true]);

    echo $OUTPUT->footer();
    die;
}

/**
 * @param $mimetype
 * @param $url
 * @param $title
 * @param $mediamanager
 * @param $moodleurl
 * @param $embedoptions
 * @param $clicktoopen
 * @return void
 */
function pdf_file_embed_view ($data, $url, $title) {
    if ($data->mimetype == 'image') {  // It's an image
        $code = mediamanager_html_image_embed($url, $title);
    } else if ($data->mimetype == 'application' || $data == NULL) {
        // PDF document
        $code = resourcelib_embed_pdf($url, $title, 0);
        $code = mediamanager_html_pdf_embed ($url, $title);
    } else if ($data->mimetype == 'video_code') {
        // Media (audio/video) file.
        $code = mediamanager_html_videocode($url, $title);
    } else {
        // We need a way to discover if we are loading remote docs inside an iframe.
        // $moodleurl->param('embed', 1);
        // anything else - just try object tag enlarged as much as possible
        $code =  mediamanager_html_video_embedcode ($url, $title);
    }
    return $code;
}


function mediamanager_file_embed_save ($course, $cm, $title, $mediamanager, $embedoptions, $file, $context, $resource) {
    global $DB;

    $mimetype = $file->get_mimetype();
    $moodleurl = moodle_url::make_pluginfile_url($context->id, 'mod_mediamanager', 'content', $resource->revision,
                  $file->get_filepath(), $file->get_filename());

    aws_s3_upload_file($moodleurl->out(), $course, $cm, $file);
    $url = aws_s3_get_file($course, $cm);

    // Check if there is existing record
    $activity_data = $DB->get_record('mediamanager_activity_type', ['cmid' => $cm->id]);

    $activity_type = new stdClass();
    $activity_type->course = $course->id;
    $activity_type->cmid = $cm->id;

    if (file_mimetype_in_typegroup($mimetype, 'web_image')) {  // It's an image
        $code = mediamanager_html_image_embed($url, $title);
        $activity_type->mimetype = 'image';

    } else if ($mimetype === 'application/pdf') {
        $code = resourcelib_embed_pdf($url, $title, 0);

        $code = mediamanager_html_pdf_embed ($url, $title);
        $activity_type->mimetype = 'application';
    } else if ($mediamanager->can_embed_url($moodleurl, $embedoptions)) {  // Media (audio/video) file.
        $code = mediamanager_html_videocode($url, $title);
        $activity_type->mimetype = 'video_code';
    } else {
        // We need a way to discover if we are loading remote docs inside an iframe.
        // $moodleurl->param('embed', 1);
        // anything else - just try object tag enlarged as much as possible
        $code = mediamanager_html_video_embedcode ($url, $title);
        $activity_type->mimetype = 'video';
    }
    if (!$activity_data) {
        $DB->insert_record('mediamanager_activity_type', $activity_type);
    } else {
        $activity_type->id = $activity_data->id;
        $DB->update_record('mediamanager_activity_type', $activity_type);
    }

    $code = pdf_file_embed_view ($activity_type, $url, $title);
    return $code;
}
/**
 * Display resource frames.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @param stored_file $file main file
 * @return does not return
 */
function mediamanager_display_frame($resource, $cm, $course, $file='', $s3_file = '') {
    global $PAGE, $OUTPUT, $CFG;
    $config = get_config('mediamanager');
    $frame = optional_param('frameset', 'main', PARAM_ALPHA);
    $framesize = $config->framesize;
    $contentframetitle = s(format_string($resource->name));
    $modulename = s(get_string('modulename','mediamanager'));
    $dir = get_string('thisdirection', 'langconfig');
    $navurl = "$CFG->wwwroot/mod/mediamanager/view.php?id=$cm->id&amp;frameset=top";
    $title = strip_tags(format_string($course->shortname.': '.$resource->name));

    if ($frame === 'top') {
        $PAGE->set_pagelayout('frametop');
        $PAGE->activityheader->set_description(mediamanager_get_intro($resource, $cm, true));
        mediamanager_print_header($resource, $cm, $course);
        echo $OUTPUT->footer();
        die;

    } else {
        if ($file != '' && $s3_file == '') {

            $context = context_module::instance($cm->id);
            $path = '/'.$context->id.'/mod_mediamanager/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
            $fileurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);
            $navurl = "$CFG->wwwroot/mod/mediamanager/view.php?id=$cm->id&amp;frameset=top";
            $title = strip_tags(format_string($course->shortname.': '.$resource->name));
            $framesize = $config->framesize;
            $contentframetitle = s(format_string($resource->name));

            // Upload to S3
            aws_s3_upload_file($fileurl, $course, $cm, $file);

            $fs = get_file_storage();
            $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());

            $url = aws_s3_get_file ($course, $cm);
        } elseif ($file != '' && $s3_file != '') {
            $config = get_config('mediamanager');
            $context = context_module::instance($cm->id);
            $path = '/'.$context->id.'/mod_mediamanager/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
            $fileurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);
            $modulename = s(get_string('modulename','mediamanager'));
            $dir = get_string('thisdirection', 'langconfig');

            aws_s3_delete_file($course->id, $cm);
            // Upload to S3
            aws_s3_upload_file($fileurl, $course, $cm, $file);

            $fs = get_file_storage();
            $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());

            $url = aws_s3_get_file ($course, $cm);
        } elseif ($s3_file != '') {
            $url = aws_s3_get_file ($course, $cm);
        }

        $file = <<<EOF
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Frameset//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-frameset.dtd">
<html dir="$dir">
  <head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <title>$title</title>
  </head>
  <frameset rows="$framesize,*">
    <frame src="$navurl" title="$modulename" />
    <frame src="$url" title="$contentframetitle" />
  </frameset>
</html>
EOF;

        @header('Content-Type: text/html; charset=utf-8');
        echo $file;
        die;
    }
}

/**
 * Internal function - create click to open text with link.
 */
function mediamanager_get_clicktoopen($revision,  $cm, $course, $extra='', $file='', $url ='') {
    global $CFG;

    if ($file != '') {
        // File Upload to S3

        $path = '/' . $file->get_contextid() . '/mod_mediamanager/content/' . $revision . $file->get_filepath() . $file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot . '/pluginfile.php', $path, false);
        aws_s3_upload_file($fullurl, $course, $cm, $file);
        $url = aws_s3_get_file($course, $cm);
        $file_name = get_file_name($course, $cm);
        $fs = get_file_storage();
        $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());

    } elseif ($extra != '' || $url != '') {
        // File already uploaded to S3 only get
         $url = aws_s3_get_file ($course, $cm);
         $file_name =  get_file_name ($course, $cm) ;

    } else {
         echo "file not found!" ;
    }

    $string = get_string('clicktoopen2', 'mediamanager', "<a href=\"$url\" $extra>$file_name</a>");

    return $string;
}

/**
 * Internal function - create click to open text with link.
 */
function mediamanager_get_clicktodownload($revision, $cm, $course, $file='') {
    global $CFG, $DB;
    $file_name =  get_file_name ($course, $cm) ;
    if ($file != '') {
        $path = '/'.$file->get_contextid().'/mod_mediamanager/content/'.$revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, true);
        aws_s3_upload_file($fullurl, $course, $cm, $file);

        $fs = get_file_storage();
        $fs->delete_area_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid());
        $url = aws_s3_get_file ($course, $cm);
    } else {
        // S3 get file
        $url = aws_s3_get_file ($course, $cm);
    }

    $string = get_string('clicktodownload', 'mediamanager', "<a href=\"$url\">$file_name</a>");

    return $string;
}

/**
 * Print resource info and workaround link when JS not available.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @param stored_file $file main file
 * @return does not return
 */
function mediamanager_print_workaround($resource, $cm, $course, $file, $s3_file = '') {
    global $CFG, $OUTPUT, $PAGE;

    // Let the module handle the display.
    $PAGE->activityheader->set_description(mediamanager_get_intro($resource, $cm, true));

    mediamanager_print_header($resource, $cm, $course);

    $resource->mainfile = get_file_name($course, $cm);
    echo '<div class="resourceworkaround">';

    $url = $s3_file;
    switch (mediamanager_get_final_display_type($resource)) {
        case RESOURCELIB_DISPLAY_POPUP:
            $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
            $width  = empty($options['popupwidth'])  ? 620 : $options['popupwidth'];
            $height = empty($options['popupheight']) ? 450 : $options['popupheight'];
            $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
            $extra = "onclick=\"window.open('$url', '', '$wh'); return false;\"";
            echo mediamanager_get_clicktoopen($resource->revision,  $cm, $course, $extra, $file, $url);
            break;

        case RESOURCELIB_DISPLAY_NEW:
            $extra = 'onclick="this.target=\'_blank\'"';
            echo mediamanager_get_clicktoopen($resource->revision,  $cm, $course, $extra, $file, $url);
            break;

        case RESOURCELIB_DISPLAY_DOWNLOAD:
            echo mediamanager_get_clicktodownload($resource->revision, $cm, $course, $file);
            break;

        case RESOURCELIB_DISPLAY_OPEN:
        default:
            echo mediamanager_get_clicktoopen($resource->revision, $cm, $course, '', $file, $url);
            break;
    }


    echo '</div>';

    echo $OUTPUT->footer();
    die;
}

/**
 * Print resource header.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @return void
 */
function mediamanager_print_header($resource, $cm, $course) {
    global $PAGE, $OUTPUT;

    $PAGE->set_title($course->shortname.': '.$resource->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_activity_record($resource);
    echo $OUTPUT->header();
}

/**
 * Gets details of the file to cache in course cache to be displayed using {@link mediamanager_get_optional_details()}
 *
 * @param object $resource Resource table row (only property 'displayoptions' is used here)
 * @param object $cm Course-module table row
 * @return string Size and type or empty string if show options are not enabled
 */
function mediamanager_get_file_details($resource, $cm) {
    $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
    $filedetails = array();
    if (!empty($options['showsize']) || !empty($options['showtype']) || !empty($options['showdate'])) {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_mediamanager', 'content', 0, 'sortorder DESC, id ASC', false);
        // For a typical file resource, the sortorder is 1 for the main file
        // and 0 for all other files. This sort approach is used just in case
        // there are situations where the file has a different sort order.
        $mainfile = $files ? reset($files) : null;
        if (!empty($options['showsize'])) {
            $filedetails['size'] = 0;
            foreach ($files as $file) {
                // This will also synchronize the file size for external files if needed.
                $filedetails['size'] += $file->get_filesize();
                if ($file->get_repository_id()) {
                    // If file is a reference the 'size' attribute can not be cached.
                    $filedetails['isref'] = true;
                }
            }
        }
        if (!empty($options['showtype'])) {
            if ($mainfile) {
                $filedetails['type'] = get_mimetype_description($mainfile);
                $filedetails['mimetype'] = $mainfile->get_mimetype();
                // Only show type if it is not unknown.
                if ($filedetails['type'] === get_mimetype_description('document/unknown')) {
                    $filedetails['type'] = '';
                }
            } else {
                $filedetails['type'] = '';
            }
        }
        if (!empty($options['showdate'])) {
            if ($mainfile) {
                // Modified date may be up to several minutes later than uploaded date just because
                // teacher did not submit the form promptly. Give teacher up to 5 minutes to do it.
                if ($mainfile->get_timemodified() > $mainfile->get_timecreated() + 5 * MINSECS) {
                    $filedetails['modifieddate'] = $mainfile->get_timemodified();
                } else {
                    $filedetails['uploadeddate'] = $mainfile->get_timecreated();
                }
                if ($mainfile->get_repository_id()) {
                    // If main file is a reference the 'date' attribute can not be cached.
                    $filedetails['isref'] = true;
                }
            } else {
                $filedetails['uploadeddate'] = '';
            }
        }
    }
    return $filedetails;
}

/**
 * Gets optional details for a resource, depending on resource settings.
 *
 * Result may include the file size and type if those settings are chosen,
 * or blank if none.
 *
 * @param object $resource Resource table row (only property 'displayoptions' is used here)
 * @param object $cm Course-module table row
 * @return string Size and type or empty string if show options are not enabled
 */
function mediamanager_get_optional_details($resource, $cm) {
    global $DB;

    $details = '';

    $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
    if (!empty($options['showsize']) || !empty($options['showtype']) || !empty($options['showdate'])) {
        if (!array_key_exists('filedetails', $options)) {
            $filedetails = mediamanager_get_file_details($resource, $cm);
        } else {
            $filedetails = $options['filedetails'];
        }
        $size = '';
        $type = '';
        $date = '';
        $langstring = '';
        $infodisplayed = 0;
        if (!empty($options['showsize'])) {
            if (!empty($filedetails['size'])) {
                $size = display_size($filedetails['size']);
                $langstring .= 'size';
                $infodisplayed += 1;
            }
        }
        if (!empty($options['showtype'])) {
            if (!empty($filedetails['type'])) {
                $type = $filedetails['type'];
                $langstring .= 'type';
                $infodisplayed += 1;
            }
        }
        if (!empty($options['showdate']) && (!empty($filedetails['modifieddate']) || !empty($filedetails['uploadeddate']))) {
            if (!empty($filedetails['modifieddate'])) {
                $date = get_string('modifieddate', 'mod_mediamanager', userdate($filedetails['modifieddate'],
                    get_string('strftimedatetimeshort', 'langconfig')));
            } else if (!empty($filedetails['uploadeddate'])) {
                $date = get_string('uploadeddate', 'mod_mediamanager', userdate($filedetails['uploadeddate'],
                    get_string('strftimedatetimeshort', 'langconfig')));
            }
            $langstring .= 'date';
            $infodisplayed += 1;
        }

        if ($infodisplayed > 1) {
            $details = get_string("resourcedetails_{$langstring}", 'mediamanager',
                    (object)array('size' => $size, 'type' => $type, 'date' => $date));
        } else {
            // Only one of size, type and date is set, so just append.
            $details = $size . $type . $date;
        }
    }

    return $details;
}

/**
 * Get resource introduction.
 *
 * @param object $resource
 * @param object $cm
 * @param bool $ignoresettings print even if not specified in modedit
 * @return string
 */
function mediamanager_get_intro(object $resource, object $cm, bool $ignoresettings = false): string {
    $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);

    $extraintro = mediamanager_get_optional_details($resource, $cm);
    if ($extraintro) {
        // Put a paragaph tag around the details
        $extraintro = html_writer::tag('p', $extraintro, array('class' => 'resourcedetails'));
    }

    $content = "";
    if ($ignoresettings || !empty($options['printintro']) || $extraintro) {
        $gotintro = !html_is_blank($resource->intro);
        if ($gotintro || $extraintro) {
            if ($gotintro) {
                $content = format_module_intro('mediamanager', $resource, $cm->id);
            }
            $content .= $extraintro;
        }
    }

    return $content;
}

/**
 * Print warning that instance not migrated yet.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @return void, does not return
 */
function mediamanager_print_tobemigrated($resource, $cm, $course) {
    global $DB, $OUTPUT, $PAGE;
    $PAGE->activityheader->set_description(mediamanager_get_intro($resource, $cm));
    $resource_old = $DB->get_record('mediamanager_old', array('oldid'=>$resource->id));
    mediamanager_print_header($resource, $cm, $course);
    echo $OUTPUT->notification(get_string('notmigrated', 'mediamanager', $resource_old->type));
    echo $OUTPUT->footer();
    die;
}

/**
 * Print warning that file can not be found.
 * @param object $resource
 * @param object $cm
 * @param object $course
 * @return void, does not return
 */
function mediamanager_print_filenotfound($resource, $cm, $course) {
    global $DB, $OUTPUT, $PAGE;

    $resource_old = $DB->get_record('mediamanager_old', array('oldid'=>$resource->id));
    $PAGE->activityheader->set_description(mediamanager_get_intro($resource, $cm));
    mediamanager_print_header($resource, $cm, $course);
    if ($resource_old) {
        echo $OUTPUT->notification(get_string('notmigrated', 'mediamanager', $resource_old->type));
    } else {
        echo $OUTPUT->notification(get_string('filenotfound', 'mediamanager'));
    }
    echo $OUTPUT->footer();
    die;
}

/**
 * Decide the best display format.
 * @param object $resource
 * @return int display type constant
 */
function mediamanager_get_final_display_type($resource) {
    global $CFG, $PAGE;

    if ($resource->display != RESOURCELIB_DISPLAY_AUTO) {
        return $resource->display;
    }

    // Creating a new activity
    if (empty($resource->mainfile)) {
        return RESOURCELIB_DISPLAY_DOWNLOAD;
    } else {
        $mimetype = mimeinfo('type', $resource->mainfile);
    }

    if (file_mimetype_in_typegroup($mimetype, 'archive')) {
        return RESOURCELIB_DISPLAY_DOWNLOAD;
    }
    if (file_mimetype_in_typegroup($mimetype, array('web_image', '.htm', 'web_video', 'web_audio'))) {
        return RESOURCELIB_DISPLAY_EMBED;
    }

    // let the browser deal with it somehow
    return RESOURCELIB_DISPLAY_OPEN;
}

/**
 * File browsing support class
 */
class mediamanager_content_file_info extends file_info_stored {
    public function get_parent() {
        if ($this->lf->get_filepath() === '/' and $this->lf->get_filename() === '.') {
            return $this->browser->get_file_info($this->context);
        }
        return parent::get_parent();
    }
    public function get_visible_name() {
        if ($this->lf->get_filepath() === '/' and $this->lf->get_filename() === '.') {
            return $this->topvisiblename;
        }
        return parent::get_visible_name();
    }
}


function mediamanager_set_mainfile($data) {
    global $DB, $CFG;
    $fs = get_file_storage();
    $cmid = $data->coursemodule;
    $draftitemid = $data->files;

    $context = context_module::instance($cmid);
    if ($draftitemid) {
        $options = array('subdirs' => true, 'embed' => false);
        if ($data->display == RESOURCELIB_DISPLAY_EMBED) {
            $options['embed'] = true;
        }
        file_save_draft_area_files ($draftitemid, $context->id, 'mod_mediamanager', 'content', 0, $options);

    }
    $files = $fs->get_area_files($context->id, 'mod_mediamanager', 'content', 0, 'sortorder', false);
    if (count($files) == 1) {
        // only one file attached, set it as main file automatically
        $file = reset($files);

        file_set_sortorder($context->id, 'mod_mediamanager', 'content', 0, $file->get_filepath(), $file->get_filename(), 1);
    }
}


function aws_s3_upload_file ($fileurl, $course, $cm, $file) {
    global $DB;
    $context = context_system::instance();
    $courseid = $course->id;
    // Get the download URL
    $download_url = $fileurl;
    $record = $DB->get_record('course_modules', ['course' => $courseid, 'id' => $cm->id]);

    $course = $DB->get_record('course', ['id' => $courseid]);

    $config = get_config('mediamanager');

    // S3 Configuration
    $bucketName = $config->awss3bucketname;
    $IAM_KEY = $config->awss3key;
    $IAM_SECRET = $config->awss3secret;
    $region = $config->awss3region;

        try {
            $s3 = new S3Client([
                'credentials' => [
                    'key' => $IAM_KEY,
                    'secret' => $IAM_SECRET,
                ],
                'version' => 'latest',
                'region' => $region,
            ]);
        } catch (Exception $e) {
            die("Error: " . $e->getMessage());
        }


        $fileUrl = $download_url;
        $keyName = $course->shortname.'/activity_'.$cm->id;

        // Add it to S3
        try {
            if (!file_exists('/tmp/tmpfile')) {
                mkdir('/tmp/tmpfile');
            }

            $tempFilePath = '/tmp/tmpfile/' . basename($fileUrl);

            // Download the file
            file_put_contents($tempFilePath, file_get_contents($fileUrl));

            $s3->putObject([
                'Bucket' => $bucketName,
                'Key' => $keyName,
                'SourceFile' => $tempFilePath,
                'StorageClass' => 'REDUCED_REDUNDANCY',
                'ACL' => 'public-read',
            ]);

            // Optional: Remove the temporary file after upload
            unlink($tempFilePath);

        } catch (S3Exception $e) {
            die('Error: ' . $e->getMessage());
        }
}

function aws_s3_get_file ($course, $cm = '', $cmid = '') {
    global $DB;

    $courseid = $course->id;

    // Get the download URL
    $course = $DB->get_record('course', ['id' => $courseid]);
    $config = get_config('mediamanager');

    if ($cmid != ''){
        $course_module_id = $cmid;
    } else {
        $course_module_id = $cm->id;
    }

    // S3 Configuration
    $bucketName = $config->awss3bucketname;
    $IAM_KEY = $config->awss3key;
    $IAM_SECRET = $config->awss3secret;
    $region = $config->awss3region;

    // Connect to AWS
    try {
        $s3 = new S3Client(
            array(
                'credentials' => array(
                    'key' => $IAM_KEY,
                    'secret' => $IAM_SECRET
                ),
                'version' => 'latest',
                'region' => $region
            )
        );

    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }

    if (file_exists('/tmp/tmpfile')) {
        $file_name =  $course->shortname.'/activity_'.$course_module_id;

        try {
            $file = $s3->getObject([
                'Bucket' => $bucketName,
                'Key' => $file_name,
            ]);
            $body = $file->get('Body');
            $body->rewind();

            $effectiveUri = $file['@metadata']['effectiveUri'];

            // Output the effectiveUri
            return $effectiveUri;

        } catch (Exception $exception) {
            return '';
        }
    }
}

function aws_s3_delete_file ($courseid, $cm = '', $cmid = '') {
    global $DB;

    // Get the download URL
    $course = $DB->get_record('course', ['id' => $courseid]);
    $config = get_config('mediamanager');

    if ($cmid != ''){
        $course_module_id = $cmid;
    } else {
        $course_module_id = $cm->id;
    }

    // S3 Configuration
    $bucketName = $config->awss3bucketname;
    $IAM_KEY = $config->awss3key;
    $IAM_SECRET = $config->awss3secret;
    $region = $config->awss3region;

    // Connect to AWS
    try {
        $s3 = new S3Client(
            array(
                'credentials' => array(
                    'key' => $IAM_KEY,
                    'secret' => $IAM_SECRET
                ),
                'version' => 'latest',
                'region' => $region
            )
        );

    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }

    if (file_exists('/tmp/tmpfile')) {
        $file_name =  $course->shortname.'/activity_'.$course_module_id;

        try {
            $file = $s3->deleteObject([
                'Bucket' => $bucketName,
                'Key' => $file_name,
            ]);
        } catch (Exception $exception) {
            return '';
        }
    }
}


function get_file_name ($course, $cm) {
    global $DB;
    $url = aws_s3_get_file ($course, $cm);

    $courseid = $course->id;
    $record = $DB->get_record('course_modules', ['course' => $courseid, 'id' => $cm->id]);

    $moduleinfo = $DB->get_record('mediamanager', ['course' => $courseid, 'id' => $record->instance]);
    $modulename = $moduleinfo->name;
    $file_name =  $course->shortname.'/activity_'.$cm->id;
    return $file_name;
}

function mediamanager_html_videocode ($url, $title) {
    $code = '<div class="resourcecontent"><div class="mediaplugin mediaplugin_videojs d-block"><div style="max-width:640px;"><video data-setup-lazy="{&quot;language&quot;: &quot;en&quot;, &quot;fluid&quot;: true, &quot;playbackRates&quot;: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2], &quot;userActions&quot;: {&quot;hotkeys&quot;: true}}" id="id_videojs_65573b020a281_1" class="video-js" preload="auto" controls="true" title='.$title.'><source src="'.$url.'" type="video/mp4" /><a class="mediafallbacklink" href="'.$url.'">video Embed</a></video></div></div></div>';

    return $code;
}
function mediamanager_html_video_embedcode ($url, $title) {
    $code = '<div class="resourcecontent resourcegeneral"> <iframe id="resourceobject" src="'.$url.'" title="'.$title.'"> Click <a href="'.$url.'" >'.$title.'</a> link to view the file. </iframe> </div>';

    return $code;
}

function mediamanager_html_pdf_embed ($url, $title) {
    $code = '<div class="resourcecontent resourcepdf"> <object id="resourceobject" data="'.$url.'" type="application/pdf" width="800" height="600"> <param name="src" value="'.$url.'" /> Click <a href="'.$url.'" >'.$title.'</a> link to view the file. </object> </div>';
    return $code;
}

function mediamanager_html_image_embed ($url, $title) {
    $code = '<div class="resourcecontent resourceimg"><img title="'.$title.'" class="resourceimage" src="'.$url.'" alt="" /></div>';
    return $code;
}