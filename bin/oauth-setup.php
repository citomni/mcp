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

/**
 * Generate secrets for the optional single-owner OAuth server.
 *
 * Usage: php vendor/citomni/mcp/bin/oauth-setup.php /absolute/path/mcp.oauth.secret.php
 * Run locally, copy the owner password to a password manager, and merge the
 * generated array into the host's environment-specific secrets store.
 * Existing files are never overwritten. No application bootstrap is required.
 */
if (PHP_SAPI !== 'cli') {
	\http_response_code(404);
	exit;
}

$output = $argv[1] ?? '';
if (\count($argv) !== 2 || !\str_ends_with($output, '.secret.php') || !\is_dir(\dirname($output))) {
	\fwrite(STDERR, "Usage: php oauth-setup.php <existing-private-directory>/mcp.oauth.secret.php\n");
	exit(1);
}

// Resolve Composer both in a package checkout and in a host's vendor directory.
$autoload = \dirname(__DIR__) . '/vendor/autoload.php';
if (!\is_file($autoload)) {
	$autoload = \dirname(__DIR__, 3) . '/autoload.php';
}
if (!\is_file($autoload)) {
	\fwrite(STDERR, "Composer autoload.php not found. Install dependencies first.\n");
	exit(1);
}
require $autoload;
if (!\class_exists(\Defuse\Crypto\Key::class)) {
	\fwrite(STDERR, "Install league/oauth2-server ^9.4.1 in the host first.\n");
	exit(1);
}

// PHP for Windows ships the openssl.cnf that key generation needs, but OpenSSL does not look
// for it there. Elsewhere OpenSSL finds its own.
$opensslCnf = \dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
$opensslOptions = PHP_OS_FAMILY === 'Windows' && \is_file($opensslCnf) ? ['config' => $opensslCnf] : [];
$key = \openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $opensslOptions);
if ($key === false || !\openssl_pkey_export($key, $private, null, $opensslOptions)) {
	$errors = [];
	while (($error = \openssl_error_string()) !== false) {
		$errors[] = $error;
	}
	throw new \RuntimeException('Cannot generate OAuth signing key: ' . \implode('; ', $errors)
		. ' (on Windows without extras\\ssl\\openssl.cnf next to php.exe, set OPENSSL_CONF to an openssl.cnf).');
}
$details = \openssl_pkey_get_details($key);
if ($details === false) {
	throw new \RuntimeException('Cannot extract OAuth public key.');
}
$password = \bin2hex(\random_bytes(24));
$clientSecret = \bin2hex(\random_bytes(32));
$secrets = [
	'mcp.oauth.owner_password_hash' => \password_hash($password, PASSWORD_DEFAULT),
	'mcp.oauth.client_secret' => $clientSecret,
	'mcp.oauth.private_key' => $private,
	'mcp.oauth.public_key' => $details['key'],
	'mcp.oauth.encryption_key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
];
$php = "<?php\ndeclare(strict_types=1);\n// Private OAuth secrets. Never commit or expose this file.\nreturn " . \var_export($secrets, true) . ";\n";
$handle = @\fopen($output, 'x');
if ($handle === false) {
	\fwrite(STDERR, "Cannot create output file (it may already exist). Nothing was overwritten.\n");
	exit(1);
}
\chmod($output, 0600);
if (\fwrite($handle, $php) !== \strlen($php)) {
	\fclose($handle);
	throw new \RuntimeException('Cannot write complete OAuth secrets file.');
}
\fclose($handle);
\fwrite(STDOUT, "Created: {$output}\nOwner approval password (save now): {$password}\nOAuth client secret (ChatGPT configuration): {$clientSecret}\n");
