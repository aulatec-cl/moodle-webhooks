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
 * The event handler.
 *
 * @package   local_webhooks
 * @copyright 2017 "Valentin Popov" <info@valentineus.link>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_webhooks;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../locallib.php';

require_once $CFG->libdir . '/filelib.php';

use curl;
use local_webhooks_events;

/**
 * Defines how to work with events.
 *
 * @copyright 2017 "Valentin Popov" <info@valentineus.link>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handler
{
    /**
     * External handler.
     *
     * @param object $event
     *
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function events($event)
    {
        $data = $event->get_data();

        # Get expanded data from moodle event

        if (!empty($callbacks = local_webhooks_get_list_records())) {
            foreach ($callbacks as $callback) {
                self::handler_callback($event, $data, $callback);
            }
        }
    }

    /**
     * Processes each callback.
     *
     * @param array  $data
     * @param object $callback
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function handler_callback($event, $data, $callback)
    {
        global $CFG;
        global $DB;

        if ((bool) $callback->enable && !empty($callback->events[$data['eventname']])) {
            $urlparse = parse_url($CFG->wwwroot);

            $data['host'] = $urlparse['host'];
            $data['token'] = $callback->token;
            $data['extra'] = $callback->other;

            $extra_event_data = array();
            if ($data['eventname'] == '\\mod_quiz\\event\\attempt_submitted') {
                $quizid = $data['other']['quizid'];
                $quiz = $DB->get_record('quiz', array('id' => $quizid), 'id, name');
                $extra_event_data['quiz'] = $quiz;

                $courseid = $data['courseid'];
                $course = $DB->get_record('course', array('id' => $courseid), 'id, fullname');
                $extra_event_data['course'] = $course;

                $userid = $data['other']['submitterid'];
                $user = $DB->get_record('user', array('id' => $userid), 'id, firstname, lastname');
                $extra_event_data['user'] = $user;
            }

            $data['extra_event_data'] = $extra_event_data;

            self::send($data, $callback);
        }
    }

    /**
     * Sending data to the node.
     *
     * @param array  $data
     * @param object $callback
     *
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function send($data, $callback)
    {
        $curl = new curl();

        if (!empty($callback->auth_token)) {
            $curl->setHeader(array($callback->auth_header_name . ': ' . $callback->auth_token));
        }

        $curl->setHeader(array('Content-Type: application/' . $callback->type));
        $curl->post($callback->url, json_encode($data));

        $response = $curl->getResponse();
        local_webhooks_events::response_answer($callback->id, $response);

        return $response;
    }
}
