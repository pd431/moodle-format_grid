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
 * Grid Format.
 *
 * @package   format_grid
 * @copyright 2025 G J Barnard in respect to modifications of standard topics format.
 * @author    G J Barnard - {@link https://moodle.org/user/profile.php?id=442195}
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */

namespace format_grid;

/**
 * PHPUnit tests for the grid format toolbox.
 *
 * @package   format_grid
 * @copyright 2025 G J Barnard in respect to modifications of standard topics format.
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 * @covers \format_grid\toolbox
 */
class toolbox_test extends \advanced_testcase {

    /**
     * Test that check_displayed_image processes only the first non-directory file
     * when the sectionimage file area contains more than one file.
     *
     * Background: a corrupt course import (e.g. via certain backup/restore tools) can
     * leave two files in the format_grid/sectionimage file area for a single section.
     * Without the break in check_displayed_image()'s foreach loop, both files are
     * processed in sequence.  setup_displayed_image() deletes the existing
     * displayedsectionimage before creating a new one, so after two iterations the
     * stored displayed file is named after the *second* file (e.g. image2.jpg) while
     * format_grid_image.image still records the *first* file's name (image1.jpg).
     * format_grid_pluginfile() looks up the file by that stored name and gets a 404,
     * so the section tile shows only alt text.
     *
     * This test will fail if the break statement in check_displayed_image() is removed.
     *
     * @requires extension gd
     */
    public function test_check_displayed_image_stops_after_first_file(): void {
        global $DB;

        $this->resetAfterTest();

        // Create a course using the grid format.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'grid', 'numsections' => 3]);
        $coursecontext = \context_course::instance($course->id);
        $fs = get_file_storage();

        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);

        // Simulate the file state left by a corrupt import: two files in the
        // sectionimage area, with only the first file registered in format_grid_image.
        $imagedata = $this->make_test_jpeg();
        foreach (['image1.jpg', 'image2.jpg'] as $filename) {
            $fs->create_file_from_string(
                [
                    'contextid' => $coursecontext->id,
                    'component' => 'format_grid',
                    'filearea'  => 'sectionimage',
                    'itemid'    => $section->id,
                    'filepath'  => '/',
                    'filename'  => $filename,
                ],
                $imagedata
            );
        }

        // Insert the format_grid_image record as a real import would have left it:
        // pointing to image1.jpg with displayedimagestate = 0 (not yet generated).
        $DB->insert_record('format_grid_image', (object) [
            'courseid'            => $course->id,
            'sectionid'           => $section->id,
            'image'               => 'image1.jpg',
            'contenthash'         => sha1($imagedata),
            'displayedimagestate' => 0,
        ]);

        $sectionimage = $DB->get_record('format_grid_image', ['sectionid' => $section->id], '*', MUST_EXIST);
        $format = \course_get_format($course->id);
        $toolbox = toolbox::get_instance();

        $updated = $toolbox->check_displayed_image(
            $sectionimage,
            $course->id,
            $coursecontext->id,
            $section->id,
            $format,
            $fs
        );

        // With the break: only image1.jpg is processed, so displayedimagestate == 1.
        // Without the break: image2.jpg is processed second, so displayedimagestate == 2,
        // and the stored displayed file is named image2.jpg — unreachable via the URL
        // that format_grid_pluginfile() builds from image1.jpg.
        $this->assertSame(
            1,
            (int) $updated->displayedimagestate,
            'displayedimagestate should be 1; a value of 2 means the break is missing ' .
            'and a second file was processed, leaving the section tile broken.'
        );

        $displayedfiles = array_values(
            $fs->get_area_files(
                $coursecontext->id,
                'format_grid',
                'displayedsectionimage',
                $section->id,
                'filename',
                false
            )
        );
        $this->assertCount(1, $displayedfiles, 'Exactly one displayed image file should exist.');
        $this->assertSame(
            'image1.jpg',
            $displayedfiles[0]->get_filename(),
            'The displayed file must match the registered image name so pluginfile can serve it.'
        );
    }

    /**
     * Generates a minimal valid JPEG using GD (50×50 px, solid colour).
     */
    private function make_test_jpeg(): string {
        $img = imagecreatetruecolor(50, 50);
        $colour = imagecolorallocate($img, 70, 144, 217);
        imagefilledrectangle($img, 0, 0, 49, 49, $colour);
        ob_start();
        imagejpeg($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }
}
