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
 * mediamanager module admin settings and defaults
 *
 * @package    mod_mediamanager
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_aws\admin_settings_aws_region;


defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once("$CFG->libdir/resourcelib.php");

    $displayoptions = resourcelib_get_displayoptions(array(RESOURCELIB_DISPLAY_AUTO,
                                                           RESOURCELIB_DISPLAY_EMBED,
                                                           RESOURCELIB_DISPLAY_FRAME,
                                                           RESOURCELIB_DISPLAY_DOWNLOAD,
                                                           RESOURCELIB_DISPLAY_OPEN,
                                                           RESOURCELIB_DISPLAY_NEW,
                                                           RESOURCELIB_DISPLAY_POPUP,
                                                          ));
    $defaultdisplayoptions = array(RESOURCELIB_DISPLAY_AUTO,
                                   RESOURCELIB_DISPLAY_EMBED,
                                   RESOURCELIB_DISPLAY_DOWNLOAD,
                                   RESOURCELIB_DISPLAY_OPEN,
                                   RESOURCELIB_DISPLAY_POPUP,
                                  );

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_configtext('mediamanager/framesize',
        get_string('framesize', 'mediamanager'), get_string('configframesize', 'mediamanager'), 130, PARAM_INT));
    $settings->add(new admin_setting_configmultiselect('mediamanager/displayoptions',
        get_string('displayoptions', 'mediamanager'), get_string('configdisplayoptions', 'mediamanager'),
        $defaultdisplayoptions, $displayoptions));

    //--- modedit defaults -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('mediamanagermodeditdefaults', get_string('modeditdefaults', 'admin'), get_string('condifmodeditdefaults', 'admin')));

    $settings->add(new admin_setting_configcheckbox('mediamanager/printintro',
        get_string('printintro', 'mediamanager'), get_string('printintroexplain', 'mediamanager'), 1));
    $settings->add(new admin_setting_configselect('mediamanager/display',
        get_string('displayselect', 'mediamanager'), get_string('displayselectexplain', 'mediamanager'), RESOURCELIB_DISPLAY_AUTO,
        $displayoptions));
    $settings->add(new admin_setting_configcheckbox('mediamanager/showsize',
        get_string('showsize', 'mediamanager'), get_string('showsize_desc', 'mediamanager'), 0));
    $settings->add(new admin_setting_configcheckbox('mediamanager/showtype',
        get_string('showtype', 'mediamanager'), get_string('showtype_desc', 'mediamanager'), 0));
    $settings->add(new admin_setting_configcheckbox('mediamanager/showdate',
        get_string('showdate', 'mediamanager'), get_string('showdate_desc', 'mediamanager'), 0));
    $settings->add(new admin_setting_configtext('mediamanager/popupwidth',
        get_string('popupwidth', 'mediamanager'), get_string('popupwidthexplain', 'mediamanager'), 620, PARAM_INT, 7));
    $settings->add(new admin_setting_configtext('mediamanager/popupheight',
        get_string('popupheight', 'mediamanager'), get_string('popupheightexplain', 'mediamanager'), 450, PARAM_INT, 7));
    $options = array('0' => get_string('none'), '1' => get_string('allfiles'), '2' => get_string('htmlfilesonly'));
    $settings->add(new admin_setting_configselect('mediamanager/filterfiles',
        get_string('filterfiles', 'mediamanager'), get_string('filterfilesexplain', 'mediamanager'), 0, $options));

    $settings->add(new admin_setting_heading('mediamanagermodeditdefaults', get_string('modeditdefaults', 'admin'), get_string('condifmodeditdefaults', 'admin')));

    $settings->add(new admin_settings_aws_region('mediamanager/awss3region',
        get_string('awss3region', 'mediamanager'), get_string('awss3regionexplain', 'mediamanager'), ''));

    $settings->add(new admin_setting_configtext('mediamanager/awss3key',
        get_string('awss3key', 'mediamanager'), get_string('awss3keyexplain', 'mediamanager'), ''));

    $settings->add(new admin_setting_configtext('mediamanager/awss3secret',
        get_string('awss3secret', 'mediamanager'), get_string('awss3secretexplain', 'mediamanager'), ''));

    $settings->add(new admin_setting_configtext('mediamanager/awss3bucketname',
        get_string('awss3bucketname', 'mediamanager'), get_string('awss3bucketnameexplain', 'mediamanager'), ''));
}
