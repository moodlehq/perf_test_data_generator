# Performance Test Data Generator #

A Moodle local plugin that generates standardized test data and JMeter test plans for performance testing. This plugin is primarily used by the Moodle CI performance testing job to generate the necessary test datasets and corresponding JMeter configurations.

## Overview

This plugin provides a command-line utility to generate realistic Moodle site data at various scales (XS to XXL) and automatically creates:

1. **Test Site Data** - A complete Moodle site structure with courses, users, activities, and content scaled to the specified size
2. **JMeter Test Plan (JMX file)** - A ready-to-run JMeter test configuration file
3. **Users CSV File** - A list of generated test users with credentials for use in performance testing

## Purpose

This plugin was extracted from the core Moodle code to facilitate easier modification and maintenance of the performance test data generation process outside the main development cycle. It enables reproducible performance testing across different Moodle installations and CI pipelines.

## Features

- **Scalable Test Data Generation** - Create test sites in 6 different sizes:
  - XS (Extra Small)
  - S (Small)
  - M (Medium)
  - L (Large)
  - XL (Extra Large)
  - XXL (Extra Extra Large)

- **Automated Test Plan Creation** - Generates JMeter-compatible test plans from a configurable template
- **User Credential Export** - Creates CSV file with test user credentials
- **Optional Site Configuration** - Can automatically configure Moodle settings for testing
- **Flexible File Output** - Supports custom file output paths for CI integration
- **Progress Reporting** - Detailed console output (optional quiet mode for CI environments)

## Installing via uploaded ZIP file ##

1. Log in to your Moodle site as an admin and go to _Site administration > Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually ##

The plugin can be installed by placing the contents of this directory into:

    {your/moodle/dirroot}/local/performancetool

Then log in to your Moodle site as an admin and go to _Site administration > Notifications_ to complete the installation.

Alternatively, run the upgrade command from the Moodle root directory:

    $ php admin/cli/upgrade.php

## Usage ##

The plugin is designed to be run from the command line using the `generate_test_data.php` script located in the plugin directory.

### Basic Usage

```bash
php local/performancetool/generate_test_data.php --size=M
```

### Command-Line Options

- `--size` (required) - The size of the test site to generate. Must be one of: XS, S, M, L, XL, XXL
- `--fixeddataset` - Use a fixed dataset for consistent results across runs
- `--filesizelimit` - Limit the maximum size of generated files (in bytes)
- `--bypasscheck` - Bypass the developer-mode debug check (use with caution)
- `--quiet` - Suppress progress output during generation
- `--updateuserspassword` - Set all generated user passwords to the value in `$CFG->tool_generator_users_password`
- `--planfilespath` or `-p` - Specify a custom directory path for output files (JMX and CSV)
- `--help` or `-h` - Display help information

### Examples

Generate a medium-sized test site with progress output:
```bash
php local/performancetool/generate_test_data.php --size=M
```

Generate a large test site with fixed dataset and custom output path:
```bash
php local/performancetool/generate_test_data.php --size=L --fixeddataset --planfilespath=/tmp/testplans/
```

Generate a test site with quiet output (suitable for CI):
```bash
php local/performancetool/generate_test_data.php --size=M --quiet --bypasscheck
```

### Output Files

The script generates two files in the specified output directory:

1. **Test Plan File (JMX)** - A JMeter test plan in Apache JMeter format, ready to load and execute
2. **Users CSV File** - A CSV file containing usernames and passwords for the generated test users

These files are automatically timestamped and numbered to avoid conflicts.

## Requirements

- Moodle 3.9 or later
- PHP CLI access to your Moodle installation
- Moodle must be running in development debug mode (unless `--bypasscheck` is used)
- Sufficient disk space to accommodate the test site data

## Integration with Moodle CI

This plugin is used as part of the Moodle CI performance testing pipeline. The CI job:

1. Installs this plugin into a Moodle instance
2. Runs `generate_test_data.php` to create a test site and JMeter plan
3. Uses the generated JMX file to run performance tests against the test site
4. Collects and analyzes the performance metrics

## License ##

2025 Simey Lameze <simey@moodle.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program.  If not, see <https://www.gnu.org/licenses/>.
