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

require dirname(__DIR__) . '/Support/OAuthFixture.php';

[$server, $dir, , $cfg] = oauthApp();
$cfg['http']['base_url'] = $argv[1];
$cfg['mcp']['oauth']['issuer'] = $argv[1];
$cfg['mcp']['oauth']['redirect_uris'] = [$argv[2]];
file_put_contents($dir . '/config/citomni_http_cfg.php', '<?php return ' . var_export($cfg, true) . ';');
$entry = file_get_contents($dir . '/public/index.php');
$entry = str_replace('? 80 : 443;', '? 80 : ' . (int)parse_url($argv[1], PHP_URL_PORT) . ';', $entry);
file_put_contents($dir . '/public/index.php', $entry);
echo json_encode(['port' => $server->port, 'password' => PASSWORD, 'client' => CLIENT]), "\n";
fgets(STDIN);
$server->stop();
