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
 * Shared ZIP extraction safety helpers for mod_exelearning.
 *
 * Both ZIP extraction sites in this plugin (the embedded-editor installer's
 * whole-tree `ZipArchive::extractTo()` and the styles service's filtered,
 * prefix-stripped per-entry copy) consume attacker-influenced archives. This
 * class centralises the two defences they share so the rules live in exactly
 * one place: the per-entry name validator that runs before extraction, and the
 * post-extraction sweep that verifies what actually landed on disk.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

/**
 * Class zip_utils.
 */
final class zip_utils {
    /**
     * Entries that must never be extracted (absolute paths, traversal, streams, empty).
     *
     * @param string $name
     * @return bool
     */
    public static function is_unsafe_zip_entry(string $name): bool {
        if ($name === '') {
            return true;
        }
        if (strpos($name, '\\') !== false) {
            return true;
        }
        if (strpos($name, '/') === 0) {
            return true;
        }
        if (preg_match('#^[a-zA-Z]+://#', $name)) {
            return true;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            return true;
        }
        return false;
    }

    /**
     * Verify an extraction landed entirely inside $dir: no symlinks anywhere
     * (a link could point outside and turn later writes/copies into
     * arbitrary-path operations) and every entry's real path contained in
     * $dir's real path. Defence-in-depth behind the per-entry name checks:
     * the pre-checks validate names, this validates what the filesystem
     * actually materialised.
     *
     * @param string $dir Extraction root (must exist).
     * @param string $exceptionstring Lang string key for the failure.
     * @throws \moodle_exception When a symlink or escaped path is found.
     */
    public static function assert_extraction_contained(string $dir, string $exceptionstring): void {
        // Normalise the root through realpath() so the containment comparison is
        // immune to symlinked temp roots (e.g. macOS /tmp -> /private/tmp).
        $root = realpath($dir);
        if ($root === false) {
            throw new \moodle_exception($exceptionstring, 'mod_exelearning', '', $dir);
        }

        // Do NOT follow symlinks: we want to inspect the link itself, not its target.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $relativepath = $iterator->getSubPathname();

            // A symlink could point outside $root and turn later writes/copies
            // through it into arbitrary-path operations; reject any link.
            if ($info->isLink()) {
                throw new \moodle_exception($exceptionstring, 'mod_exelearning', '', $relativepath);
            }

            // The materialised path must resolve inside (or be) the real root.
            $real = realpath($info->getPathname());
            $contained = ($real !== false)
                && ($real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) === 0);
            if (!$contained) {
                throw new \moodle_exception($exceptionstring, 'mod_exelearning', '', $relativepath);
            }
        }
    }
}
