<?php

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Ensure $CFG is available.
if (!isset($CFG)) {
    cli_error('Missing $CFG after loading config.php');
}

// Explicitly load the plugin class to avoid autoloader issues in CI containers.
require_once(__DIR__ . '/classes/generator.php');
use local_performancetool\generator;

list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'size' => false,
        'fixeddataset' => false,
        'filesizelimit' => false,
        'bypasscheck' => false,
        'quiet' => false,
        'updateuserspassword' => false,
        'planfilespath' => false,
    ),
    array(
        'h' => 'help',
        'p' => 'planfilespath',
    )
);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if (!empty($options['help']) || empty($options['size'])) {
    $sitesizes = array();
    for ($i = generator::MIN_SIZE; $i <= generator::MAX_SIZE; $i++) {
        $sitesizes[] = '* ' . get_string('sitesize_' . $i, 'tool_generator');
    }
    echo "Utility to generate a standard test site data set and a JMeter test plan.

        Usage:
        --size           Size of the generated site: XS, S, M, L, XL, XXL (required)
        --fixeddataset   Use a fixed data set
        --filesizelimit  Limits the size of generated files (bytes)
        --bypasscheck    Bypass developer-mode check
        --quiet          Suppress progress output
        --updateuserspassword  Update exported users' passwords to CFG->tool_generator_users_password

        Available sizes: " . implode(PHP_EOL, $sitesizes) . PHP_EOL;
    exit(empty($options['help']) ? 1 : 0);
}

// Check debugging is set to developer level unless bypassed.
if (empty($options['bypasscheck']) && empty($CFG->debugdeveloper)) {
    cli_error(get_string('error_notdebugging', 'tool_generator'));
}

// Resolve numeric size from name or numeric input.
$sizeparam = $options['size'];
if (is_numeric($sizeparam)) {
    $size = (int)$sizeparam;
} else {
    $size = generator::size_for_name($sizeparam);
}
if ($size === null || $size < generator::MIN_SIZE || $size > generator::MAX_SIZE) {
    cli_error("Invalid size ({$sizeparam}). Use --help for help.");
}

// Switch to admin user.
\core\session\manager::set_user(get_admin());

// Optionally configure site (uncomment to enable automatic configuration).
generator::configure_site();

// Prepare options.
$fixeddataset = !empty($options['fixeddataset']);
$filesizelimit = $options['filesizelimit'] ?: false;
$progress = empty($options['quiet']) ? true : false;
$bypass = !empty($options['bypasscheck']);

mtrace("Creating test site (size: {$sizeparam}) ...");

$gen = new generator();
try {
    $lastcourseid = $gen->make_site($size, $bypass, $fixeddataset, $filesizelimit, $progress);
    //$lastcourseid = 30;
} catch (\Exception $e) {
    cli_error('Error creating test site: ' . $e->getMessage());
}

mtrace("Test site structure created. Last course id: {$lastcourseid}");

// Generate the test plan and users file.
mtrace("Generating test plan and users file for course id: {$lastcourseid} ...");

try {

    $testplanfile = generator::create_testplan_file($lastcourseid, $size);
    //$numusers = $gen->get_total_users_created(); TODO: Do we need to fetch the list of users created or just assume a number?
    $numusers = 100;
    $usersfile = generator::create_users_file($lastcourseid, !empty($options['updateuserspassword']), $numusers);
} catch (\Exception $e) {
    cli_error('Error generating test plan or users file: ' . $e->getMessage());
}

// Usage after generating files and before writing:
$path = generator::choose_planfiles_path_from_option(!empty($options['planfilespath']) ? $options['planfilespath'] : false);
mtrace('Using planfiles directory: ' . $path);

try {
    $tpname = $testplanfile->get_filename();
    $tpfile = $path . $tpname;
    if (file_put_contents($tpfile, $testplanfile->get_content()) === false) {
        cli_error('Failed writing test plan to: ' . $tpfile);
    }
    mtrace('Test plan written to: ' . $tpfile);

    $uname = $usersfile->get_filename();
    $ufile = $path . $uname;
    if (file_put_contents($ufile, $usersfile->get_content()) === false) {
        cli_error('Failed writing users CSV to: ' . $ufile);
    }
    mtrace('Users CSV written to: ' . $ufile);
} catch (\Exception $e) {
    cli_error('Error saving generated files: ' . $e->getMessage());
}

mtrace("Test plan generation completed.");
exit(0);
