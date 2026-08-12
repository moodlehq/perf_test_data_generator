<?php

namespace local_performancetool;

defined('MOODLE_INTERNAL') || die();

class generator {

    // Reuse the same sizes as the core tool generator backends expect.
    // These constants exist in the core backends; using them via the core class keeps parity.
    const MIN_SIZE = \tool_generator_backend::MIN_SIZE;
    const MAX_SIZE = \tool_generator_backend::MAX_SIZE;

    public static function configure_site(): bool
    {
        global $CFG, $DB;

        require_once($CFG->libdir . '/adminlib.php');

        $settings = array(
            'debugdisplay', 'enablenotes', 'enableblogs', 'enablebadges', 'enableoutcomes',
            'enableportfolios', 'enablerssfeeds', 'enablecompletion', 'enablecourserequests',
            'enableavailability', 'enableplagiarism', 'enablegroupmembersonly', 'enablegravatar',
            'enablesafebrowserintegration', 'usecomments', 'dndallowtextandlinks', 'gradepublishing',
            // Both default to 0 on fresh 5.x installs. The pages they guard redirect to the login
            // page rather than failing, so without these the site home and My courses scenarios
            // silently measure a redirect instead of the page under test.
            'enablemyhome', 'enablemycourses'
        );

        foreach ($settings as $setting) {
            set_config($setting, 1);
        }

        // Fresh 5.x installs default forcelogin to 1 (MDL-87523), which sends anonymous requests to
        // the login page from require_course_login() before any page-level check runs. That turns
        // the "Frontpage not logged" scenario into a second copy of "View login page", and makes it
        // incomparable with 4.x, where the default is 0.
        set_config('forcelogin', 0);

        // Update admin user info if exists.
        $admin = $DB->get_record('user', array('username' => 'admin'), '*', IGNORE_MISSING);
        if ($admin) {
            $admin->email = 'moodle@moodlemoodle.com';
            $admin->firstname = 'Admin';
            $admin->lastname = 'User';
            $admin->city = 'Perth';
            $admin->country = 'AU';
            $DB->update_record('user', $admin);
        }

        // Disable email message processor.
        $DB->set_field('message_processors', 'enabled', 0, array('name' => 'email'));

        // Configure frontpage lists for guests and logged users (use global class).
        if (class_exists('\admin_setting_courselist_frontpage')) {
            $frontpage = new \admin_setting_courselist_frontpage(false);
            $frontpage->write_setting(array(FRONTPAGEALLCOURSELIST));
            $frontpagelogged = new \admin_setting_courselist_frontpage(true);
            $frontpagelogged->write_setting(array(FRONTPAGEENROLLEDCOURSELIST));
        } else {
            mtrace("Skipping frontpage configuration: admin_setting_courselist_frontpage not available.");
        }

        mtrace("Moodle site configuration finished successfully.");
        return true;
    }

    /**
     * Create a JMX testplan stored as a file in the file storage (system context).
     *
     * @param int $courseid
     * @param int $size
     * @return stored_file
     */
    public static function create_testplan_file($courseid, $size)
    {
        $jmxcontents = self::generate_test_plan($courseid, $size);

        $fs = get_file_storage();
        $filerecord = self::get_file_record('testplan', 'jmx');
        return $fs->create_file_from_string($filerecord, $jmxcontents);
    }

    /**
     * Create a CSV users file stored in file storage.
     *
     * @param int $courseid
     * @param bool $updateuserspassword
     * @param int|null $size
     * @return stored_file
     */
    public static function create_users_file($courseid, $updateuserspassword, ?int $size = null)
    {
        $csvcontents = self::generate_users_file($courseid, $updateuserspassword, $size);

        $fs = get_file_storage();
        $filerecord = self::get_file_record('users', 'csv');
        return $fs->create_file_from_string($filerecord, $csvcontents);
    }

    /**
     * Generate the test plan contents from the template.
     *
     * @param int $targetcourseid
     * @param int $size
     * @return string
     */
    protected static function generate_test_plan($targetcourseid, $size) {
        global $CFG;

        $template = file_get_contents(__DIR__ . '/../testplan.template.jmx');
        $coursedata = self::get_course_test_data($targetcourseid);
        $urlcomponents = parse_url($CFG->wwwroot);
        if (empty($urlcomponents['path'])) {
            $urlcomponents['path'] = '';
        }

        // Use the same arrays as the core backend.
        $users = [1, 30, 100, 1000, 5000, 10000];
        $loops = [5, 5, 5, 6, 6, 7];
        $rampups = [1, 6, 40, 100, 500, 800];

        $replacements = array(
            $CFG->version,
            $users[$size],
            $loops[$size],
            $rampups[$size],
            $urlcomponents['host'],
            $urlcomponents['path'],
            get_string('shortsize_' . $size, 'tool_generator'),
            $targetcourseid,
            $coursedata->pageid,
            $coursedata->forumid,
            $coursedata->forumdiscussionid,
            $coursedata->forumreplyid
        );

        $placeholders = array(
            '{{MOODLEVERSION_PLACEHOLDER}}',
            '{{USERS_PLACEHOLDER}}',
            '{{LOOPS_PLACEHOLDER}}',
            '{{RAMPUP_PLACEHOLDER}}',
            '{{HOST_PLACEHOLDER}}',
            '{{SITEPATH_PLACEHOLDER}}',
            '{{SIZE_PLACEHOLDER}}',
            '{{COURSEID_PLACEHOLDER}}',
            '{{PAGEACTIVITYID_PLACEHOLDER}}',
            '{{FORUMACTIVITYID_PLACEHOLDER}}',
            '{{FORUMDISCUSSIONID_PLACEHOLDER}}',
            '{{FORUMREPLYID_PLACEHOLDER}}'
        );

        return str_replace($placeholders, $replacements, $template);
    }

    /**
     * Generate users CSV contents.
     *
     * @param int $targetcourseid
     * @param bool $updateuserspassword
     * @param int|null $size
     * @return string
     */
    protected static function generate_users_file($targetcourseid, $updateuserspassword, ?int $size = null)
    {
        global $CFG;

        $coursecontext = \context_course::instance($targetcourseid);

        $planusers = $size !== null ? ([1, 30, 100, 1000, 5000, 10000][$size] ?? 0) : 0;
        $users = get_enrolled_users($coursecontext, '', 0, 'u.id, u.username, u.auth', 'u.username ASC', 0, $planusers);
        if (!$users) {
            throw new \moodle_exception('coursewithoutusers', 'tool_generator');
        }

        $lines = array();
        foreach ($users as $user) {
            if ($updateuserspassword) {
                $userauth = get_auth_plugin($user->auth);
                if (!$userauth->user_update_password($user, $CFG->tool_generator_users_password)) {
                    throw new \moodle_exception('errorpasswordupdate', 'auth');
                }
            }
            $lines[] = $user->username . ',' . $CFG->tool_generator_users_password;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Create a file_record object for storing generated artifacts.
     *
     * @param string $filearea
     * @param string $filetype
     * @return stdClass
     */
    protected static function get_file_record($filearea, $filetype)
    {
        $systemcontext = \context_system::instance();

        $filerecord = new \stdClass();
        $filerecord->contextid = $systemcontext->id;
        $filerecord->component = 'local_perf_test_data_generator';
        $filerecord->filearea = $filearea;
        $filerecord->itemid = 0;
        $filerecord->filepath = '/';
        $filerecord->filename = $filearea . '_' . date('YmdHi', time()) . '_' . rand(1000, 9999) . '.' . $filetype;

        return $filerecord;
    }

    /**
     * Get course related IDs required by the test plan.
     *
     * @param int $targetcourseid
     * @return stdClass
     */
    protected static function get_course_test_data($targetcourseid)
    {
        global $DB, $USER;

        $data = new \stdClass();

        $course = new \stdClass();
        $course->id = $targetcourseid;
        $courseinfo = new \course_modinfo($course, $USER->id);

        if (!$pages = $courseinfo->get_instances_of('page')) {
            throw new \moodle_exception('error_nopageinstances', 'tool_generator');
        }
        $data->pageid = reset($pages)->id;

        if (!$forums = $courseinfo->get_instances_of('forum')) {
            throw new \moodle_exception('error_noforuminstances', 'tool_generator');
        }
        $forum = reset($forums);

        if (!$discussions = \forum_get_discussions($forum, 'd.timemodified ASC', false, -1, 1)) {
            throw new \moodle_exception('error_noforumdiscussions', 'tool_generator');
        }
        $discussion = reset($discussions);

        $data->forumid = $forum->id;
        $data->forumdiscussionid = $discussion->discussion;
        $data->forumreplyid = $discussion->id;

        return $data;
    }

    /**
     * Basic validation that the selected course is OK for generating artifacts.
     *
     * @param int|string $course
     * @param int $size
     * @return array|false
     */
    public static function has_selected_course_any_problem($course, $size)
    {
        global $DB;

        $errors = array();

        if (!is_numeric($course)) {
            if (!$course = $DB->get_field('course', 'id', array('shortname' => $course))) {
                $errors['courseid'] = get_string('error_nonexistingcourse', 'tool_generator');
                return $errors;
            }
        }

        $coursecontext = \context_course::instance($course, IGNORE_MISSING);
        if (!$coursecontext) {
            $errors['courseid'] = get_string('error_nonexistingcourse', 'tool_generator');
            return $errors;
        }

        if (!$users = get_enrolled_users($coursecontext, '', 0, 'u.id')) {
            $errors['courseid'] = get_string('coursewithoutusers', 'tool_generator');
        }

        $coursesizes = \tool_generator_course_backend::get_users_per_size();
        if (count($users) < ([1, 30, 100, 1000, 5000, 10000][$size] ?? 0)) {
            $errors['size'] = get_string('notenoughusers', 'tool_generator');
        }

        return empty($errors) ? false : $errors;
    }

    /**
     * Convert a human readable shortsize name back to numeric size index.
     *
     * @param string $name localised size name (e.g. get_string('shortsize_0', 'tool_generator'))
     * @return int|null returns numeric index or null if not found
     */
    public static function size_for_name($name)
    {
        for ($i = self::MIN_SIZE; $i <= self::MAX_SIZE; $i++) {
            if (get_string('shortsize_' . $i, 'tool_generator') === $name) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Make a full test site (multiple courses). This reproduces the loop from core
     * site backend but avoids shelling out by including the core CLI file in-process.
     *
     * @param int $size index
     * @param bool $bypasscheck
     * @param bool $fixeddataset
     * @param int|false $filesizelimit
     * @param bool $progress
     * @return int last created course id
     */
    public function make_site($size, $bypasscheck, $fixeddataset = false, $filesizelimit = false, $progress = true)
    {
        global $DB, $CFG;

        raise_memory_limit(MEMORY_EXTRA);

        $sitecourses = array(
            array(2, 8, 64, 256, 1024, 4096),
            array(1, 4, 8, 16, 32, 64),
            array(0, 0, 1, 4, 8, 16),
            array(0, 0, 0, 1, 0, 0),
            array(0, 0, 0, 0, 1, 0),
            array(0, 0, 0, 0, 0, 1)
        );

        $prevchdir = getcwd();
        chdir($CFG->dirroot);
        $ncourse = $this->get_last_testcourse_id();
        for ($coursesize = 0; $coursesize < count($sitecourses); $coursesize++) {
            $ncourses = $sitecourses[$coursesize][$size] ?? 0;
            for ($i = 1; $i <= $ncourses; $i++) {
                $ncourse++;
                $shortname = 'testcourse_' . $ncourse;
                $this->run_create_course($shortname, $coursesize, $fixeddataset, $bypasscheck, $filesizelimit, $progress);
            }
        }
        chdir($prevchdir);

        $lastcourseid = $DB->get_field('course', 'id', array('shortname' => 'testcourse_' . $ncourse));
        return $lastcourseid;
    }

    /**
     * Internal: call the core CLI script in-process to create a course.
     * This avoids executing `php` on the shell and bypasses executable permission problems.
     *
     * @param string $shortname
     * @param int $coursesize
     * @param bool $fixeddataset
     * @param bool $bypasscheck
     * @param int|false $filesizelimit
     * @param bool $progress
     * @return void
     */
    protected function run_create_course($shortname, $coursesize, $fixeddataset, $bypasscheck, $filesizelimit, $progress)
    {
        global $CFG;

        $scriptpath = $CFG->dirroot . '/admin/tool/generator/cli/maketestcourse.php';
        if (!file_exists($scriptpath)) {
            throw new \moodle_exception('errormissingmaketestcourse', 'tool_generator');
        }

        // Build command using the same PHP binary so the script doesn't need to be executable.
        $cmd = PHP_BINARY . ' ' . escapeshellarg($scriptpath);

        $options = array();
        $options[] = '--shortname=' . escapeshellarg($shortname);
        $options[] = '--size=' . escapeshellarg(get_string('shortsize_' . $coursesize, 'tool_generator'));

        if (!$progress) {
            $options[] = '--quiet';
        }
        if ($filesizelimit) {
            $options[] = '--filesizelimit=' . escapeshellarg((string)$filesizelimit);
        }
        if ($fixeddataset) {
            $options[] = '--fixeddataset';
        }
        if ($bypasscheck) {
            $options[] = '--bypasscheck';
        }

        $cmd .= ' ' . implode(' ', $options);

        // Run the command and capture output.
        $descriptorspec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );
        $cwd = getcwd();
        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd);

        if (!is_resource($process)) {
            throw new \Exception('Failed to start maketestcourse process');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitcode = proc_close($process);

        if ($exitcode !== 0) {
            throw new \Exception('maketestcourse failed with exit code ' . $exitcode . '. Output: ' . trim($stderr . PHP_EOL . $stdout));
        }
    }


    /**
     * Get the last numeric suffix used for generated testcourse_* shortnames.
     *
     * @return int
     */
    protected function get_last_testcourse_id()
    {
        global $DB;
        $prefix = 'testcourse_';
        $params = array();
        $params['shortnameprefix'] = $DB->sql_like_escape($prefix) . '%';
        $like = $DB->sql_like('shortname', ':shortnameprefix');

        if (!$testcourses = $DB->get_records_select('course', $like, $params, '', 'shortname')) {
            return 0;
        }

        $shortnames = array_keys($testcourses);
        \core_collator::asort($shortnames, \core_collator::SORT_NATURAL);
        $shortnames = array_reverse($shortnames);

        $prefixnchars = strlen($prefix);
        foreach ($shortnames as $shortname) {
            $sufix = substr($shortname, $prefixnchars);
            if (preg_match('/^[\d]+$/', $sufix)) {
                return $sufix;
            }
        }
        return 0;
    }

    public static function choose_planfiles_path_from_option($optionpath = false) {
        global $CFG;

        $candidates = array();

        // If CLI option provided, prefer that.
        if (!empty($optionpath)) {
            $candidates[] = rtrim($optionpath, "/\\");
        }

        // Plugin-local planfiles folder.
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'planfiles';

        // Moodle dataroot fallback if available.
        if (!empty($CFG->dataroot)) {
            $candidates[] = $CFG->dataroot . DIRECTORY_SEPARATOR . 'local_performancetool' . DIRECTORY_SEPARATOR . 'planfiles';
        }

        // System temp fallback.
        $candidates[] = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'performancetool_planfiles';

        foreach ($candidates as $candidate) {
            // Normalize.
            $candidate = rtrim($candidate, "/\\");
            if (!is_dir($candidate)) {
                // Try to create it; suppress warnings and continue on failure.
                if (!@mkdir($candidate, 0775, true)) {
                    continue;
                }
            }
            // Try to make it writable.
            @chmod($candidate, 0777);
            if (is_dir($candidate) && is_writable($candidate)) {
                return $candidate . DIRECTORY_SEPARATOR;
            }
        }

        cli_error('Failed to create or find a writable `planfiles` directory. Use --planfilespath to specify a writable path that is mounted into the container.');
    }
}
