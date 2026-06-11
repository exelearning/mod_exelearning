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

namespace mod_exelearning;

use advanced_testcase;
use core_external\external_api;
use mod_exelearning\external\manage_embedded_editor;
use mod_exelearning\local\embedded_editor_source_resolver;

/**
 * Tests for the embedded editor management web service (capabilities + status).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\external\manage_embedded_editor
 */
final class manage_embedded_editor_test extends advanced_testcase {
    /**
     * get_status(false) returns local/config state and does not query GitHub.
     */
    public function test_get_status_reports_local_state_without_network(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $status = manage_embedded_editor::get_status(false);
        $status = external_api::clean_returnvalue(manage_embedded_editor::get_status_returns(), $status);

        $this->assertContains($status['active_source'], [
            embedded_editor_source_resolver::SOURCE_MOODLEDATA,
            embedded_editor_source_resolver::SOURCE_BUNDLED,
            embedded_editor_source_resolver::SOURCE_NONE,
        ]);
        // No admin-installed editor on a fresh site, and no GitHub check requested.
        $this->assertFalse($status['moodledata_available']);
        $this->assertSame('', $status['latest_version']);
        $this->assertSame('', $status['latest_error']);
        $this->assertFalse($status['update_available']);
        $this->assertFalse($status['installing']);
        // An admin can install when nothing is installed yet.
        $this->assertTrue($status['can_install']);
    }

    /**
     * A user without moodle/site:config cannot read the editor status.
     */
    public function test_get_status_requires_site_config_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        manage_embedded_editor::get_status(false);
    }

    /**
     * An unknown action is rejected before any installer or network work.
     */
    public function test_execute_action_rejects_unknown_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        manage_embedded_editor::execute_action('frobnicate');
    }

    /**
     * get_status() reflects an admin-installed editor: version, active source and
     * the repair/uninstall (but not install) action flags.
     */
    public function test_get_status_reflects_installed_editor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $src = make_temp_directory('mod_exelearning/ms-' . random_string(6));
        make_writable_directory($src . '/app');
        file_put_contents($src . '/index.html', 'x');
        $installer = new \mod_exelearning\local\embedded_editor_installer();
        $installer->safe_install($src);
        $installer->store_metadata('2.3.4');

        $status = manage_embedded_editor::get_status(false);

        $this->assertSame(embedded_editor_source_resolver::SOURCE_MOODLEDATA, $status['active_source']);
        $this->assertTrue($status['moodledata_available']);
        $this->assertSame('2.3.4', $status['moodledata_version']);
        $this->assertTrue($status['can_repair']);
        $this->assertTrue($status['can_uninstall']);
        $this->assertFalse($status['can_install']);

        remove_dir(embedded_editor_source_resolver::get_moodledata_dir());
    }

    /**
     * Each editor action maps to a non-empty success message.
     */
    public function test_action_success_strings(): void {
        foreach (['install', 'update', 'repair', 'uninstall'] as $action) {
            $this->assertNotEmpty(manage_embedded_editor::get_action_success_string($action));
        }
    }
}
