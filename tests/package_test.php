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
use mod_exelearning\local\package;

/**
 * Unit tests for the content.xml parser, focused on per-iDevice contenthash
 * (the change-detection that drives the "grades are now stale" warning, DEC-0021).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\package
 */
final class package_test extends advanced_testcase {
    /**
     * Builds a content.xml manifest from a list of [pageid, deviceid, type, body] rows.
     *
     * @param array $idevices List of [pageid, deviceid, type, body] arrays.
     * @return string
     */
    private function build_content_xml(array $idevices): string {
        $nav = "<odeNavStructure>\n";
        $lastpage = null;
        foreach ($idevices as [$pageid, $deviceid, $type, $body]) {
            if ($pageid !== $lastpage) {
                $nav .= "<odePageId>{$pageid}</odePageId>\n<pageName>Page {$pageid}</pageName>\n";
                $lastpage = $pageid;
            }
            $nav .= "<odePageId>{$pageid}</odePageId>\n";
            $nav .= "<odeIdeviceId>{$deviceid}</odeIdeviceId>\n";
            $nav .= "<odeIdeviceTypeName>{$type}</odeIdeviceTypeName>\n";
            $nav .= $body;
        }
        $nav .= "</odeNavStructure>\n";

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0">' . "\n"
            . $nav
            . "</ode>\n";
    }

    /**
     * Wraps a content.xml string in a stored ELPX zip in the system context.
     *
     * @param string $contentxml
     * @return \stored_file
     */
    private function make_package_file(string $contentxml): \stored_file {
        $tmp = make_request_directory();
        file_put_contents($tmp . '/content.xml', $contentxml);

        $packer = get_file_packer('application/zip');
        $zippath = make_request_directory() . '/pkg.elpx';
        $packer->archive_to_pathname(['content.xml' => $tmp . '/content.xml'], $zippath);

        $context = \context_system::instance();
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'pkg.elpx',
        ];
        // Allow several variants within one test.
        if ($old = $fs->get_file($context->id, 'mod_exelearning', 'package', 0, '/', 'pkg.elpx')) {
            $old->delete();
        }
        return $fs->create_file_from_pathname($filerecord, $zippath);
    }

    /**
     * Parse a manifest and return the detected gradable iDevices keyed by objectid.
     *
     * @param array $idevices
     * @return array<string,\stdClass>
     */
    private function detect(array $idevices): array {
        $file = $this->make_package_file($this->build_content_xml($idevices));
        $items = (new package($file))->detect_gradable_idevices();
        $byid = [];
        foreach ($items as $item) {
            $byid[$item->objectid] = $item;
        }
        return $byid;
    }

    /**
     * Each gradable iDevice gets a non-empty, stable contenthash; non-gradable
     * types (text) are ignored.
     */
    public function test_contenthash_present_and_stable(): void {
        $this->resetAfterTest();

        $manifest = [
            ['p1', 'idevice-text-a', 'text', "<p>intro</p>\n"],
            ['p1', 'idevice-tf-1', 'trueorfalse', "<question>Sky is blue?</question><answer>true</answer>\n"],
            ['p2', 'idevice-guess-1', 'guess', "<word>moodle</word>\n"],
        ];

        $first = $this->detect($manifest);
        $second = $this->detect($manifest);

        // Only the two gradable iDevices are detected.
        $this->assertCount(2, $first);
        $this->assertArrayHasKey('idevice-tf-1', $first);
        $this->assertArrayHasKey('idevice-guess-1', $first);
        $this->assertArrayNotHasKey('idevice-text-a', $first);

        // Hash is a 40-char sha1 and deterministic across identical parses.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $first['idevice-tf-1']->contenthash);
        $this->assertSame($first['idevice-tf-1']->contenthash, $second['idevice-tf-1']->contenthash);
        $this->assertSame($first['idevice-guess-1']->contenthash, $second['idevice-guess-1']->contenthash);

        // Distinct iDevices hash differently.
        $this->assertNotSame($first['idevice-tf-1']->contenthash, $first['idevice-guess-1']->contenthash);
    }

    /**
     * Editing one iDevice's options changes only that iDevice's hash; the
     * untouched one keeps its hash even though it shifts position in the file.
     */
    public function test_editing_options_changes_only_that_hash(): void {
        $this->resetAfterTest();

        $before = $this->detect([
            ['p1', 'idevice-tf-1', 'trueorfalse', "<answer>true</answer>\n"],
            ['p2', 'idevice-guess-1', 'guess', "<word>moodle</word>\n"],
        ]);

        // Flip the correct answer of the true/false; leave the guess untouched.
        $after = $this->detect([
            ['p1', 'idevice-tf-1', 'trueorfalse', "<answer>false</answer>\n"],
            ['p2', 'idevice-guess-1', 'guess', "<word>moodle</word>\n"],
        ]);

        $this->assertNotSame(
            $before['idevice-tf-1']->contenthash,
            $after['idevice-tf-1']->contenthash,
            'Editing the true/false answer must change its content hash.'
        );
        $this->assertSame(
            $before['idevice-guess-1']->contenthash,
            $after['idevice-guess-1']->contenthash,
            'An untouched iDevice must keep its content hash.'
        );
    }

    /**
     * Volatile per-iDevice metadata (timestamps) must not flip the hash.
     */
    public function test_volatile_metadata_ignored_in_hash(): void {
        $this->resetAfterTest();

        $v1 = $this->detect([
            ['p1', 'idevice-tf-1', 'trueorfalse', "<lastModified>2026-01-01</lastModified><answer>true</answer>\n"],
        ]);
        $v2 = $this->detect([
            ['p1', 'idevice-tf-1', 'trueorfalse', "<lastModified>2026-06-02</lastModified><answer>true</answer>\n"],
        ]);

        $this->assertSame(
            $v1['idevice-tf-1']->contenthash,
            $v2['idevice-tf-1']->contenthash,
            'Only the modification timestamp changed, so the hash must stay equal.'
        );
    }
}
