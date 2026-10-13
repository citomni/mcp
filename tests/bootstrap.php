<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

/*
 * Shared bootstrap for the suites (tests/<suite>/run.php). Not a suite of its
 * own; tests/run.php only collects run.php and database.php.
 *
 * A suite installs its CLI guard and its error handler, then requires this file.
 *
 * Provides:
 * - Composer's autoloader from the package's vendor/, so "composer install"
 *   must have run in the package root. The suites replace no framework class
 *   with a class_alias double, and mcp/sdk is a Composer dependency, so they
 *   load the real packages.
 * - The case runner: test() runs one named case and reports PASS or FAIL;
 *   parallel() runs a multi-process case only with CITOMNI_TEST_PARALLEL=1 and
 *   reports SKIP otherwise; done() prints the totals and ends the suite with
 *   its exit code.
 * - check() and tempDir().
 *
 * Notes:
 * - Files a suite writes live below one run directory,
 *   citomni_mcp_<suite>_test_<random> under sys_get_temp_dir(), created on
 *   first use and removed at shutdown. Nothing else is ever removed.
 * - parallel() is the only way a case is skipped.
 */

/** A failed check: the case fails with the check's message. */
final class CheckFailed extends \RuntimeException {
}


/**
 * Report a fatal setup problem on STDERR and stop the suite with exit code 1.
 *
 * @param string $message Problem.
 * @return never
 */
function fail(string $message): never {
	\fwrite(\STDERR, $message . "\n");
	exit(1);
}


if (!\is_file(\dirname(__DIR__) . '/vendor/autoload.php')) {
	fail('vendor/autoload.php not found. Run "composer install" in the package root first.');
}

require \dirname(__DIR__) . '/vendor/autoload.php';

// The run directory of this suite; the suite is the directory of the script PHP started.
\define('MCP_TEST_RUN_DIR', \sys_get_temp_dir() . '/citomni_mcp_' . \basename(\dirname(\get_included_files()[0])) . '_test_' . \bin2hex(\random_bytes(6)));

$passed = 0;
$failed = 0;
$skipped = 0;


/**
 * Run one case and report it.
 *
 * Behavior:
 * - PASS <name> on stdout when the case returns.
 * - FAIL <name> - <reason> on stderr when a check fails or anything else is
 *   thrown. The suite goes on with the next case either way.
 *
 * @param string $name Case name, shown in the report.
 * @param \Closure $case The case.
 * @return void
 */
function test(string $name, \Closure $case): void {
	global $passed, $failed;

	try {
		$case();
	} catch (CheckFailed $e) {
		++$failed;
		\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e->getMessage()) . "\n");

		return;
	} catch (\Throwable $e) {
		++$failed;
		\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e::class . ': ' . $e->getMessage() . ' at ' . \basename($e->getFile()) . ':' . $e->getLine()) . "\n");

		return;
	}

	++$passed;
	\fwrite(\STDOUT, "PASS {$name}\n");
}


/**
 * Run one multi-process case when CITOMNI_TEST_PARALLEL is 1, and skip it otherwise.
 *
 * Behavior:
 * - With CITOMNI_TEST_PARALLEL=1, runs the case as test() does.
 * - Otherwise reports SKIP <name>: <how to enable it> on stdout and counts the
 *   case as skipped.
 *
 * @param string $name Case name, shown in the report.
 * @param \Closure $case The case.
 * @return void
 */
function parallel(string $name, \Closure $case): void {
	global $skipped;

	if (\getenv('CITOMNI_TEST_PARALLEL') === '1') {
		test($name, $case);

		return;
	}

	++$skipped;
	\fwrite(\STDOUT, "SKIP {$name}: set CITOMNI_TEST_PARALLEL=1 to run multi-process checks\n");
}


/**
 * Print the totals and end the suite: exit code 1 when a case failed.
 *
 * @return never
 */
function done(): never {
	global $passed, $failed, $skipped;

	\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
	exit($failed === 0 ? 0 : 1);
}


/**
 * Collapse a multi-line message to one line.
 *
 * @param string $text Message.
 * @return string One line.
 */
function oneLine(string $text): string {
	return \trim((string)\preg_replace('/\s*\R\s*/', ' | ', $text));
}


/**
 * Fail the current case unless the condition holds.
 *
 * @param bool $condition Condition that must hold.
 * @param string $message Reason reported when it does not.
 * @return void
 * @throws CheckFailed When the condition is false.
 */
function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new CheckFailed($message);
	}
}


/**
 * Create a fresh directory below the suite's run directory.
 *
 * @return string Absolute path.
 */
function tempDir(): string {
	static $count = 0;

	if ($count === 0) {
		if (!\mkdir(\MCP_TEST_RUN_DIR, 0777, true)) {
			fail('Unable to create the run directory ' . \MCP_TEST_RUN_DIR);
		}

		\register_shutdown_function(static function (): void {
			removeRunDir(\MCP_TEST_RUN_DIR);
		});
	}

	$dir = \MCP_TEST_RUN_DIR . '/' . ++$count;

	if (!\mkdir($dir)) {
		fail('Unable to create temp dir: ' . $dir);
	}

	return $dir;
}


/**
 * Remove the run directory and everything below it.
 *
 * @param string $dir Run directory.
 * @return void
 */
function removeRunDir(string $dir): void {
	if (!\is_dir($dir)) {
		return;
	}

	$entries = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($entries as $entry) {
		$entry->isDir() && !$entry->isLink() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
	}

	@\rmdir($dir);
}
