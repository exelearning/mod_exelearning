<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appearance']            = 'Appearance';
$string['areacontent']      = 'Extracted package files';
$string['areapackage']      = 'Original package file (.elpx)';
$string['attempt']           = 'Attempt';
$string['attemptdeleted']     = 'The attempt was deleted and the grade was recalculated.';
$string['attempts']          = 'Attempts';
$string['attemptsofmax']      = 'Attempts: {$a->used} of {$a->max}';
$string['attemptsreport']    = 'Attempts report';
$string['attemptsused']       = 'Attempts used: {$a}';
$string['checkingforupdates'] = 'Checking for updates...';
$string['confirmuninstall'] = 'Are you sure you want to uninstall the embedded editor? This will remove the admin-installed copy from moodledata.';
$string['confirmuninstalltitle'] = 'Confirm uninstall';
$string['deleteattempt']     = 'Delete attempt';
$string['detecteditems']    = 'Gradable iDevices detected:';
$string['editorbackuperror'] = 'Could not back up the existing editor installation.';
$string['editorbundleddesc'] = 'A version is included with the plugin. You can install the latest version published on GitHub.';
$string['editorbundledsource'] = 'Bundled with plugin';
$string['editorcopyfailed'] = 'Could not copy editor files to the target directory.';
$string['editordownloademptyfile'] = 'The downloaded file is empty.';
$string['editordownloaderror'] = 'Failed to download the editor package: {$a}';
$string['editordownloadingmessage'] = 'Downloading and installing the editor. This may take a minute...';
$string['editorextractfailed'] = 'Failed to extract the editor package: {$a}';
$string['editorextractwriteerror'] = 'Could not write extracted files to the temporary directory.';
$string['editorgithubapierror'] = 'GitHub returned HTTP status {$a}. Please try again later.';
$string['editorgithubconnecterror'] = 'Could not connect to GitHub: {$a}';
$string['editorgithubparseerror'] = 'Could not parse the latest release information from GitHub.';
$string['editorinstall'] = 'Install latest version';
$string['editorinstallconcurrent'] = 'An installation is already in progress. Please wait a few minutes and try again.';
$string['editorinstalledat'] = 'Installed at';
$string['editorinstalledsuccess'] = 'Editor installed successfully';
$string['editorinstallfailed'] = 'Failed to install the editor: {$a}';
$string['editorinstalling'] = 'Installing...';
$string['editorinvalidlayout'] = 'The package does not contain the expected editor files (index.html and asset directories).';
$string['editorinvalidzip'] = 'The downloaded file is not a valid ZIP archive.';
$string['editorlatestversionongithub'] = 'Latest version on GitHub:';
$string['editormanagementhelp'] = 'Download and install the latest eXeLearning editor from GitHub. The version installed by the administrator takes priority over the bundled one.';
$string['editormkdirerror'] = 'Could not create directory: {$a}';
$string['editormoodledatasource'] = 'Admin-installed (moodledata)';
$string['editornotinstalleddesc'] = 'Install the editor from GitHub to enable the embedded editing mode.';
$string['editorreaderror'] = 'Could not read the eXeLearning embedded editor files. Please check file permissions and contact your administrator.';
$string['editorrepairsuccess'] = 'Editor repaired successfully';
$string['editoruninstall'] = 'Remove';
$string['editoruninstalledsuccess'] = 'Editor uninstalled successfully';
$string['editoruninstalling'] = 'Removing...';
$string['editoruninstallingmessage'] = 'Removing the editor installation...';
$string['editorupdate'] = 'Update editor';
$string['editorupdateavailable'] = 'Update available: v{$a}';
$string['editorupdatedsuccess'] = 'Editor updated successfully';
$string['editoruploadfailed'] = 'Failed to upload the editor package: {$a}';
$string['editoruploadmissingfile'] = 'No editor ZIP file was uploaded.';
$string['editorzipextensionmissing'] = 'The PHP ZipArchive extension is not available. Please ask your server administrator to enable it.';
$string['editwitheditor'] = 'Edit with eXeLearning';
$string['embeddededitor']         = 'Embedded eXeLearning editor';
$string['embeddededitor_desc']    = 'Enable the in-browser eXeLearning editor for authors. If enabled but the editor is not installed yet, administrators can install it from the management page below (download the latest release from GitHub or upload a ZIP).';
$string['embeddededitorsettings'] = 'Editor type';
$string['embeddededitorstatus'] = 'Embedded editor';
$string['embeddednotinstalledadmin'] = 'The embedded editor files are not installed. You can install it from the plugin settings.';
$string['embeddednotinstalledcontactadmin'] = 'The embedded editor files are not installed. Please contact your site administrator to install it.';
$string['exelearning:addinstance']           = 'Add a new eXeLearning resource';
$string['exelearning:deleteattempt']         = 'Delete student attempts';
$string['exelearning:manageembeddededitor']  = 'Manage the embedded eXeLearning editor settings';
$string['exelearning:savetrack']             = 'Save tracking interactions';
$string['exelearning:view']                  = 'View an eXeLearning resource';
$string['exelearning:viewreport']            = 'View reports';
$string['grade_overall_name'] = 'Overall (aggregated)';
$string['gradedisplay']                  = 'Grade display';
$string['gradedisplay_default']          = 'Default (inherit from course)';
$string['gradedisplay_help']             = 'Choose how each gradebook column for this activity is displayed to students and teachers. Moodle always stores grades numerically; this setting only changes the format shown in the grader report and the student dashboard. "Default" inherits from the course-wide setting.';
$string['gradedisplay_letter']           = 'Letter (A, B, …)';
$string['gradedisplay_percentage']       = 'Percentage';
$string['gradedisplay_real']             = 'Real (0-100)';
$string['gradedisplay_real_percentage']  = 'Real and percentage';
$string['gradeitem_overall'] = 'Overall';
$string['grademax']       = 'Maximum grade per item';
$string['grademax_help']  = 'Each gradable iDevice in the package (trueorfalse, guess, drag-and-drop, quizzes…) is registered as a separate column in the gradebook with this maximum value.';
$string['grademethod']            = 'Attempts grading method';
$string['grademethod_average']    = 'Average of attempts';
$string['grademethod_first']      = 'First attempt';
$string['grademethod_help']       = 'When a student submits more than once, this controls which value reaches the gradebook: the highest, the average, the first, the most recent (last), or the lowest of all attempts. Mirrors mod_scorm / mod_quiz.';
$string['grademethod_highest']    = 'Highest attempt';
$string['grademethod_last']       = 'Last attempt';
$string['grademethod_lowest']     = 'Lowest attempt';
$string['grademin']       = 'Minimum grade per item';
$string['grademodel']         = 'Gradebook columns';
$string['grademodel_both']    = 'Both (overall excluded from course total)';
$string['grademodel_help']    = 'Choose how this activity reports to the gradebook. "Overall only": one aggregated column (like SCORM). "Per iDevice only": one column per gradable iDevice. "Both": an overall column plus one per iDevice, with the overall excluded from the course total so the student is not graded twice for the same work.';
$string['grademodel_overall'] = 'Overall only';
$string['grademodel_peritem'] = 'Per iDevice only';
$string['gradepass']       = 'Grade to pass';
$string['gradepass_help']  = 'The minimum overall grade required to pass. When the "Require passing grade" completion condition is enabled, the activity is marked complete (SCORM-style) once the student reaches this grade. Leave at 0 to disable pass-based completion.';
$string['gradingheading'] = 'Grading';
$string['installstale'] = 'Installation may have failed. Please try again.';
$string['intro']         = 'Description';
$string['invalidaction'] = 'Invalid action: {$a}';
$string['manage_editor_heading']  = 'Editor management';
$string['manage_editor_link']     = 'Manage embedded editor';
$string['maxattempt']         = 'Attempts allowed';
$string['maxattempt_help']    = 'Maximum number of attempts a student may submit. Set to 0 for unlimited attempts. One attempt corresponds to one page-load session of the activity.';
$string['maxattemptsreached'] = 'You have used all your allowed attempts for this activity.';
$string['modulename']        = 'eXeLearning resource';
$string['modulename_help']   = 'The eXeLearning resource module embeds a published eXeLearning v4 package in a Moodle course while preserving its native navigation sidebar and recording one or more gradable items in the Moodle gradebook.';
$string['modulenameplural']  = 'eXeLearning resources';
$string['noattempts']        = 'No attempts have been recorded yet.';
$string['noeditorinstalled'] = 'No editor installed';
$string['noexelearningactivities'] = 'There are no eXeLearning resources in this course yet.';
$string['nopermissionreport'] = 'You do not have permission to view the attempts report for this activity.';
$string['operationtakinglong'] = 'Operation is taking longer than expected. Checking status...';
$string['operationtimedout'] = 'Operation timed out. Please check the editor status and try again.';
$string['package']       = 'Package file (.elpx)';
$string['package_help']  = 'Upload an eXeLearning v4 package (.elpx). The package is extracted server-side and rendered with its native sidebar preserved.';
$string['packagenotfound']  = 'The eXeLearning package could not be found or has not finished extracting yet.';
$string['participation_summary']      = '{$a->attempted} of {$a->total} students have attempted this activity.';
$string['participation_summary_mean'] = '{$a->attempted} of {$a->total} students have attempted this activity · average {$a->mean}%.';
$string['pluginadministration'] = 'eXeLearning resource administration';
$string['pluginname']        = 'eXeLearning resource';
$string['pluginnameplural']  = 'eXeLearning resources';
$string['previewmode']        = 'Preview mode (test):';
$string['previewmode_desc']   = 'Nothing you do here will be saved to the gradebook.';
$string['previewmode_enter']  = 'Try as a student (preview)';
$string['previewmode_exit']   = 'Exit preview mode';
$string['privacy:metadata:exelearning_attempt'] = 'Attempt records for each submission a user makes on an eXeLearning gradable item.';
$string['privacy:metadata:exelearning_attempt:attempt'] = 'The sequential attempt number.';
$string['privacy:metadata:exelearning_attempt:itemnumber'] = 'The gradable item (0 = overall, >0 = a specific iDevice).';
$string['privacy:metadata:exelearning_attempt:rawscore'] = 'The raw score obtained in the attempt.';
$string['privacy:metadata:exelearning_attempt:scaledscore'] = 'The score scaled to 0..1.';
$string['privacy:metadata:exelearning_attempt:status'] = 'The status of the attempt (completed, passed, failed, incomplete).';
$string['privacy:metadata:exelearning_attempt:timecreated'] = 'When the attempt was first recorded.';
$string['privacy:metadata:exelearning_attempt:timemodified'] = 'When the attempt was last updated.';
$string['privacy:metadata:exelearning_attempt:userid'] = 'The user who made the attempt.';
$string['report_actions']    = 'Actions';
$string['report_attempt']    = 'Attempt';
$string['report_date']       = 'Submitted';
$string['report_item']       = 'Item';
$string['report_overall']    = 'Overall';
$string['report_score']      = 'Score';
$string['report_status']     = 'Status';
$string['report_user']       = 'User';
$string['reportedgrade']      = 'Reported grade';
$string['reports']           = 'Reports';
$string['reviewmode']         = 'Students may review attempts';
$string['reviewmode_aftercompletion'] = 'After the activity is complete';
$string['reviewmode_always']  = 'Always';
$string['reviewmode_help']    = 'Controls whether students can see a summary of their own previous attempts on the activity page.';
$string['reviewmode_none']    = 'Never';
$string['savedsuccess'] = 'Changes saved successfully';
$string['savetomoodle'] = 'Save to Moodle';
$string['saving'] = 'Saving...';
$string['savingwait'] = 'Please wait while the file is being saved.';
$string['stillworking'] = 'Still working...';
$string['stylesblockimport'] = 'Block user-imported styles';
$string['stylesblockimport_desc'] = 'When enabled, the embedded editor hides the "User styles" tab and refuses to install a style bundled inside an imported .elpx project. Users may only choose from the admin-approved list above. This mirrors the eXeLearning ONLINE_THEMES_INSTALL=false behavior.';
$string['stylesbuiltin'] = 'Built-in styles';
$string['stylesbuiltin_empty'] = 'Built-in styles are not available because the embedded editor is not installed.';
$string['stylesbuiltin_hint'] = 'Uncheck a style to hide it from the editor. Disabled built-ins are not deleted; the project can always fall back to the default style.';
$string['stylesdelete'] = 'Delete';
$string['stylesdelete_confirm'] = 'Delete this style? This cannot be undone.';
$string['stylesdelete_success'] = 'Style deleted.';
$string['stylesdisable'] = 'Disable';
$string['stylesenable'] = 'Enable';
$string['stylesinstallfailed'] = 'Style installation failed: {$a}';
$string['stylesmanager'] = 'Styles';
$string['stylesmanager_intro'] = 'Manage the eXeLearning styles available to the embedded editor. Built-in styles can be hidden individually. Uploaded styles can be enabled, disabled, or deleted at any time.';
$string['stylesnocss'] = 'The uploaded style does not contain any stylesheet.';
$string['stylesonlywhenembedded'] = 'The embedded editor is not enabled. Styles managed here only take effect when the editor mode is set to "embedded".';
$string['stylestable_actions'] = 'Actions';
$string['stylestable_enabled'] = 'Enabled';
$string['stylestable_id'] = 'Id';
$string['stylestable_installed'] = 'Installed';
$string['stylestable_title'] = 'Title';
$string['stylestable_version'] = 'Version';
$string['stylesupload_badentry'] = 'The ZIP archive contains unreadable entries.';
$string['stylesupload_badext'] = 'File type not allowed in style package: {$a}';
$string['stylesupload_badxml'] = 'config.xml is not valid XML.';
$string['stylesupload_badzip'] = 'The uploaded file is not a readable ZIP archive.';
$string['stylesupload_configread'] = 'config.xml could not be read from the archive.';
$string['stylesupload_empty'] = 'The uploaded file is empty.';
$string['stylesupload_failed'] = 'Style upload failed.';
$string['stylesupload_goto_settings'] = 'Upload styles from the plugin settings page';
$string['stylesupload_hint'] = 'Maximum file size: {$a}. Only .zip packages containing a valid config.xml are accepted.';
$string['stylesupload_label'] = 'Style ZIP package';
$string['stylesupload_missing'] = 'The uploaded file is missing or unreadable.';
$string['stylesupload_mixedroots'] = 'The archive must contain a single root folder or place all files at the root.';
$string['stylesupload_multiconfig'] = 'The archive contains more than one config.xml.';
$string['stylesupload_noconfig'] = 'The style package is missing config.xml.';
$string['stylesupload_noname'] = 'config.xml must declare a &lt;name&gt; element.';
$string['stylesupload_nozip'] = 'The ZipArchive PHP extension is not available.';
$string['stylesupload_readfailed'] = 'Failed to read a file from the archive during extraction.';
$string['stylesupload_success'] = 'Style "{$a}" installed.';
$string['stylesupload_toolarge'] = 'The uploaded style exceeds the maximum allowed size of {$a}.';
$string['stylesupload_traversal'] = 'Refused path traversal during extraction.';
$string['stylesupload_unsafe'] = 'Rejected unsafe archive entry: {$a}';
$string['stylesupload_writefailed'] = 'Failed to write an extracted file.';
$string['stylesuploaded'] = 'Uploaded styles';
$string['stylesuploaded_empty'] = 'No uploaded styles yet.';
$string['stylesuploaded_hint'] = 'Enable or disable uploaded styles. Uncheck to hide a style from the editor; delete to remove it permanently.';
$string['teachermodevisible']      = 'Show teacher preview toggle';
$string['teachermodevisible_help'] = 'If disabled, the "Try as a student (preview)" toggle is hidden from teachers in the activity view. Capability checks still prevent students from entering preview mode regardless of this setting.';
$string['unsavedchanges'] = 'You have unsaved changes. Are you sure you want to close?';
$string['updateavailable'] = 'Update available';
$string['viewattemptsreport'] = 'View attempts report';
$string['viewstub']      = 'This is a placeholder view. The full mod_exelearning render (iframe + sidebar + xAPI bridge) is under construction. See <code>research/</code> in the plugin source for the design history.';

// Generated strings for multi-grade-items (mod_exelearning\grades\gradeitems).
// Moodle 5 form_trait calls get_string("grade_<itemname>_name", $component) for
// each itemnumber in the mapping. This loop is code, not literal strings.
for ($exelearningstringi = 1; $exelearningstringi <= 100; $exelearningstringi++) {
    $string['grade_idevice' . $exelearningstringi . '_name'] = 'iDevice ' . $exelearningstringi;
}
unset($exelearningstringi);
