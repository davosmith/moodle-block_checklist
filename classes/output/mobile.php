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

namespace block_checklist\output;

/**
 * Functions to support mobile app
 *
 * @package   block_checklist
 * @copyright 2023 Dani Palou
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {

    /**
     * Returns the block checklist view for the mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS.
     * @return array HTML, javascript and otherdata.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function mobile_view($args) {
        $args = (object)$args;

        $instance = block_instance_by_id($args->blockid);
        if (!$instance) {
            return self::show_message('Error - block not found');
        }

        if ($args->contextlevel === 'course' && is_guest($instance->context)) {
            return []; // Course guests don't see the checklist block.
        }

        if (!\block_checklist::import_checklist_plugin()) {
            return self::show_message(get_string('nochecklistplugin', 'block_checklist'));
        }

        if (!empty($instance->config->checklistoverview)) {
            return self::show_checklist_overview($args, $instance);
        }

        if (!empty($instance->config->checklistid)) {
            return self::show_single_checklist($args, $instance);
        }

        // No checklist configured.
        return self::show_message(get_string('nochecklist', 'block_checklist'));
    }

    /**
     * Returns a view to display a single message in the block.
     *
     * @param string $message Message to display.
     * @return array HTML, javascript and otherdata.
     */
    public static function show_message($message) {
        global $OUTPUT;

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('block_checklist/mobile_view_message', [
                        'message' => $message,
                    ]),
                ],
            ],
        ];
    }

    /**
     * Returns a view to display a single checklist in the block.
     *
     * @param object $args Arguments from tool_mobile_get_content WS.
     * @param stdClass $instance Block instance.
     * @return array HTML, javascript and otherdata.
     * @throws \dml_exception
     */
    public static function show_single_checklist($args, $instance) {
        global $OUTPUT, $DB, $USER, $CFG;

        if (!$checklist = $DB->get_record('checklist', ['id' => $instance->config->checklistid])) {
            return self::show_message(get_string('nochecklist', 'block_checklist'));
        }
        if (!$cm = get_coursemodule_from_instance('checklist', $checklist->id, $checklist->course)) {
            return self::show_message('Error - course module not found');
        }

        $context = \context_module::instance($cm->id);

        $viewallreports = has_capability('mod/checklist:viewreports', $context);
        $viewmenteereports = has_capability('mod/checklist:viewmenteereports', $context);
        $updateownchecklist = has_capability('mod/checklist:updateown', $context);

        $data = [
            'title' => $instance->title,
            'showallusers' => $viewallreports || $viewmenteereports,
            'showsingleuser' => !($viewallreports || $viewmenteereports) && $updateownchecklist,
        ];
        $groupsusers = [];
        $groupid = $args->groupid ?? 0;

        // Show results for all users for a particular checklist.
        if ($data['showallusers']) {
            $groupinfo = self::get_groups_info($cm, $context);
            $groupid = self::validate_group_id($groupinfo, $groupid);
            $reporturl = new \moodle_url('/mod/checklist/report.php', ['id' => $cm->id]);

            // Get info for all groups, that way the user can change group without performing more network requests.
            // This also makes it easier to keep all the cached data in sync in the app.
            foreach ($groupinfo->groups as $group) {
                $ausers = \block_checklist::get_single_checklist_users($context, $group->id, $viewallreports);
                $groupsusers[$group->id] = [];

                foreach ($ausers as $auser) {
                    [$ticked, $total] = \checklist_class::get_user_progress($checklist->id, $auser->id);

                    $groupsusers[$group->id][] = (object)[
                        'fullname' => fullname($auser),
                        'viewurl' => $reporturl->out(false, ['studentid' => $auser->id]),
                        'progress' => $total ? $ticked * 100.0 / $total : null,
                    ];
                }
            }

        } else if ($data['showsingleuser']) {
            $data['viewurl'] = new \moodle_url('/mod/checklist/view.php', ['id' => $cm->id]);

            [$ticked, $total] = \checklist_class::get_user_progress($checklist->id, $USER->id);
            $data['progress'] = $total ? $ticked * 100.0 / $total : -1;
        }

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('block_checklist/mobile_view_single_checklist', $data),
                ],
            ],
            'otherdata' => [
                'groupinfo' => json_encode($groupinfo),
                'groupsusers' => json_encode($groupsusers),
                'groupid' => $groupid,
            ],
        ];
    }

    /**
     * Returns group info, using the format required by the app's core-group-selector.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $context Context.
     * @return object Group info.
     */
    protected static function get_groups_info($cm, $context) {
        global $USER;

        $groupinfo = (object)[
            'groups' => [],
            'defaultGroupId' => 0,
            'canAccessAllGroups' => false,
        ];

        if (!$groupmode = groups_get_activity_groupmode($cm)) {
            $groupinfo->groups[] = (object)[
                'id' => 0,
                'name' => get_string('allparticipants'),
            ];

            return $groupinfo;
        }

        $aag = has_capability('moodle/site:accessallgroups', $context);

        $groupinfo->separateGroups = $groupmode == SEPARATEGROUPS;
        $groupinfo->visibleGroups = $groupmode == VISIBLEGROUPS;
        $groupinfo->canAccessAllGroups = $aag;

        if ($groupmode == VISIBLEGROUPS || $aag) {
            $seeall = true;
            $allowedgroups = groups_get_all_groups($cm->course, 0, $cm->groupingid); // Any group in grouping.
        } else {
            $seeall = false;
            $allowedgroups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid); // Only assigned groups.
        }

        if (empty($allowedgroups) || $seeall) {
            $groupinfo->groups[] = (object)[
                'id' => 0,
                'name' => get_string('allparticipants'),
            ];
        } else {
            $groupinfo->defaultGroupId = reset($allowedgroups)->id;
        }

        if ($allowedgroups) {
            foreach ($allowedgroups as $group) {
                $groupinfo->groups[] = (object)[
                    'id' => (int)$group->id,
                    'name' => format_string($group->name),
                ];
            }
        }

        return $groupinfo;
    }

    /**
     * Given groupinfo and groupid, return the group ID to use.
     *
     * @param object $groupinfo Group info returned by get_groups_info.
     * @param int $groupid Group ID the user wants to use.
     * @return int Group ID.
     */
    protected static function validate_group_id($groupinfo, $groupid) {
        if ($groupid > 0 && $groupinfo && $groupinfo->groups) {
            // Check if the group is in the list of groups.
            if (in_array($groupid, array_column($groupinfo->groups, 'id'))) {
                return $groupid;
            }
        }

        return $groupinfo->defaultGroupId;
    }

    /**
     * Returns a view to display checklist overview in the block.
     *
     * @param object $args Arguments from tool_mobile_get_content WS.
     * @param stdClass $instance Block instance.
     * @return array HTML, javascript and otherdata.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function show_checklist_overview($args, $instance) {
        global $OUTPUT, $SITE;

        $courseid = $args->contextlevel === 'course' ? $args->instanceid : $SITE->id;

        $allcourses = $courseid === $SITE->id;
        if ($allcourses) {
            $mycourses = enrol_get_my_courses();
        } else {
            $mycourses = [$courseid => get_course($courseid)];
        }

        if (empty($mycourses)) {
            return self::show_message(get_string('notenrolled', 'block_checklist'));
        }

        $data = [
            'title' => $instance->title,
            'allcourses' => $allcourses,
            'checklists' => [],
        ];
        $checklists = \block_checklist::get_checklists_for_overview($mycourses);

        foreach ($checklists as $checklist) {
            $data['checklists'][] = (object)[
                'shortname' => $checklist->shortname,
                'name' => format_string($checklist->name),
                'viewurl' => new \moodle_url('/mod/checklist/view.php', ['id' => $checklist->cmid]),
                'progress' => $checklist->totalitems ? $checklist->checked * 100.0 / $checklist->totalitems : -1,
            ];
        }

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('block_checklist/mobile_view_overview', $data),
                ],
            ],
        ];
    }

}
