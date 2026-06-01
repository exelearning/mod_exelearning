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

// NOTE: behat step files live outside the mod_exelearning namespace by Moodle
// convention (the class name maps to the @mod_exelearning step container).

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps to drive the SCORM bridge of a mod_exelearning activity from the browser.
 *
 * These exercise the DEC-0017 fix end to end: the view.php shim reads the iframe
 * DOM to resolve each scored iDevice to its stable objectid, and track.php routes
 * the score to the right gradebook column. The steps talk to the parent
 * window.API exactly as eXeLearning's pipwerks SCORM client would.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_exelearning extends behat_base {
    /** @var string DOM id of the package iframe rendered by view.php. */
    const IFRAME_ID = 'exelearningobject';

    /**
     * Waits until the package iframe has loaded and exposes gradable iDevice nodes.
     *
     * @Given /^I wait until the eXeLearning package iframe has loaded$/
     */
    public function i_wait_until_the_exelearning_package_iframe_has_loaded(): void {
        $condition = "(function(){var f=document.getElementById('" . self::IFRAME_ID . "');"
            . "return !!(f && f.contentDocument && "
            . "f.contentDocument.querySelectorAll('.idevice_node').length);})()";
        if (!$this->getSession()->wait(10 * 1000, $condition)) {
            throw new ExpectationException(
                'The eXeLearning package iframe did not load any iDevice nodes.',
                $this->getSession()
            );
        }
    }

    /**
     * Reports a SCORM score for the gradable iDevice of the currently loaded page.
     *
     * Builds the producer's cmi.suspend_data line for that iDevice at its real
     * page-local index N and hands it to the shim through window.API, then commits.
     *
     * @When /^I report a SCORM score of "(?P<score_string>\d+)" for the gradable iDevice on the current eXeLearning page$/
     * @param string $score Score percentage to report.
     */
    public function i_report_a_scorm_score_for_the_gradable_idevice(string $score): void {
        $pct = (int) $score;
        $frameid = self::IFRAME_ID;
        $js = <<<JS
(function(){
  var f = document.getElementById('{$frameid}');
  var doc = f.contentDocument;
  var nodes = doc.querySelectorAll('.idevice_node');
  var n = 0, title = '';
  for (var i = 0; i < nodes.length; i++) {
    var t = nodes[i].getAttribute('data-idevice-type');
    if (t && t !== 'text') {
      n = i + 1;
      var h = nodes[i].querySelector('.box-title');
      title = h ? h.textContent : '';
      break;
    }
  }
  if (!n) { return; }
  var line = n + '. "' + title + '"; Score: {$pct}%; Weight: 100%';
  window.API.LMSSetValue('cmi.suspend_data', line);
  window.API.LMSSetValue('cmi.core.score.raw', '{$pct}');
  window.API.LMSSetValue('cmi.core.score.max', '100');
  window.API.LMSSetValue('cmi.core.lesson_status', 'completed');
  window.API.LMSCommit();
})();
JS;
        $this->execute_script($js);
    }

    /**
     * Navigates the package iframe to another page of the package (full navigation).
     *
     * @When /^I switch the eXeLearning iframe to the package page "(?P<page_string>[^"]*)"$/
     * @param string $page Package-relative path, e.g. "html/page-2.html".
     */
    public function i_switch_the_exelearning_iframe_to_the_package_page(string $page): void {
        $page = addslashes($page);
        $js = "(function(){var f=document.getElementById('" . self::IFRAME_ID . "');"
            . "f.src=f.src.replace(/(content\\/\\d+\\/).*$/, '\$1{$page}');})();";
        $this->execute_script($js);
        $this->i_wait_until_the_exelearning_package_iframe_has_loaded();
    }
}
